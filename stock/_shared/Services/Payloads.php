<?php
declare(strict_types=1);
/**
 * File: Payloads.php
 * Purpose: DTO definitions for pack/send orchestrator
 * Author: GitHub Copilot
 * Last Modified: 2025-09-29
 * Dependencies: PHP 8.1+
 */

namespace Modules\Transfers\Stock\Shared\Services;

use DateTimeImmutable;
use Modules\Transfers\Stock\Shared\Util\Uuid;

final class PackSendRequest
{
    public readonly string $requestId;
    public readonly string $idempotencyKey;
    public readonly int $userId;
    public readonly int $transferId;
    public readonly string $fromOutletUuid;
    public readonly string $toOutletUuid;
    public readonly string $mode;
    public readonly bool $sendNow;
    /** @var list<ParcelInput> */
    public readonly array $parcels;
    public readonly TotalsInput $totals;
    /** @var array<string, mixed> */
    public readonly array $modeData;
    /** @var array<string, mixed> */
    public readonly array $rawPayload;
    private readonly string $rawBody;
    private readonly string $bodyHash;

    private function __construct(
        string $requestId,
        string $idempotencyKey,
        int $userId,
        int $transferId,
        string $fromOutletUuid,
        string $toOutletUuid,
        string $mode,
        bool $sendNow,
        array $parcels,
        TotalsInput $totals,
        array $modeData,
        array $rawPayload,
        string $rawBody
    ) {
        $this->requestId = $requestId;
        $this->idempotencyKey = $idempotencyKey;
        $this->userId = $userId;
        $this->transferId = $transferId;
        $this->fromOutletUuid = $fromOutletUuid;
        $this->toOutletUuid = $toOutletUuid;
        $this->mode = $mode;
        $this->sendNow = $sendNow;
        $this->parcels = $parcels;
        $this->totals = $totals;
        $this->modeData = $modeData;
        $this->rawPayload = $rawPayload;
        $this->rawBody = $rawBody;
        $this->bodyHash = hash('sha256', $rawBody ?: json_encode($rawPayload, JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromHttp(string $requestId, string $idempotencyKey, int $userId, array $payload, string $rawBody): self
    {
        $transfer = $payload['transfer'] ?? [];
        $transferId = (int)($transfer['id'] ?? $payload['transfer_id'] ?? 0);
        $fromUuid = Uuid::normalize($transfer['from_outlet_uuid'] ?? $payload['from_outlet_uuid'] ?? '');
        $toUuid = Uuid::normalize($transfer['to_outlet_uuid'] ?? $payload['to_outlet_uuid'] ?? '');
        $mode = strtoupper(trim((string)($payload['mode'] ?? '')));
        $sendNow = (bool)($payload['send_now'] ?? false);

        $parcels = [];
        $parcelPayload = $payload['parcels'] ?? [];
        if (is_iterable($parcelPayload)) {
            foreach ($parcelPayload as $index => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $parcels[] = ParcelInput::fromArray($row, (int)$index + 1);
            }
        }

        $totalsPayload = is_array($payload['totals'] ?? null) ? $payload['totals'] : [];
        $totals = TotalsInput::fromArray($totalsPayload);

        $pickupData = is_array($payload['pickup'] ?? null) ? self::sanitizePickup($payload['pickup']) : [];
        $internalData = is_array($payload['internal'] ?? null) ? self::sanitizeInternal($payload['internal']) : [];
        $depotData = is_array($payload['depot'] ?? null) ? self::sanitizeDepot($payload['depot']) : [];
        $courierData = is_array($payload['courier'] ?? null) ? $payload['courier'] : null;

        if ($mode !== Modes::PICKUP) {
            $pickupData = [];
        }

        if ($mode !== Modes::INTERNAL_DRIVE) {
            $internalData = [];
        }

        if ($mode !== Modes::DEPOT_DROP) {
            $depotData = [];
        }

        $modeData = [
            'courier' => $courierData,
            'pickup' => $pickupData,
            'internal' => $internalData,
            'depot' => $depotData,
            'notes' => self::sanitizeString($payload['notes'] ?? null),
        ];

        return new self(
            $requestId,
            $idempotencyKey,
            $userId,
            $transferId,
            $fromUuid,
            $toUuid,
            $mode,
            $sendNow,
            $parcels,
            $totals,
            $modeData,
            $payload,
            $rawBody
        );
    }

    public function bodyHash(): string
    {
        return $this->bodyHash;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function sanitizePickup(array $row): array
    {
        $result = [];

        $by = self::sanitizeString($row['by'] ?? $row['contact'] ?? null);
        if ($by !== null) {
            $result['by'] = $by;
        }

        $phone = self::sanitizeString($row['phone'] ?? $row['contact_phone'] ?? null);
        if ($phone !== null) {
            $result['phone'] = $phone;
        }

        $time = self::normalizeDateTime($row['time'] ?? $row['pickup_at'] ?? null);
        if ($time !== null) {
            $result['time'] = $time;
        }

        $parcels = self::sanitizeInt($row['parcels'] ?? $row['boxes'] ?? null);
        if ($parcels !== null) {
            $result['parcels'] = $parcels;
        }

        $notes = self::sanitizeString($row['notes'] ?? null);
        if ($notes !== null) {
            $result['notes'] = $notes;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function sanitizeInternal(array $row): array
    {
        $result = [];

        $driver = self::sanitizeString($row['driver'] ?? null);
        if ($driver !== null) {
            $result['driver'] = $driver;
        }

        $departAt = self::normalizeDateTime($row['depart_at'] ?? $row['departure'] ?? null);
        if ($departAt !== null) {
            $result['depart_at'] = $departAt;
        }

        $boxes = self::sanitizeInt($row['boxes'] ?? $row['parcels'] ?? null);
        if ($boxes !== null) {
            $result['boxes'] = $boxes;
        }

        $vehicle = self::sanitizeString($row['vehicle'] ?? null);
        if ($vehicle !== null) {
            $result['vehicle'] = $vehicle;
        }

        $notes = self::sanitizeString($row['notes'] ?? null);
        if ($notes !== null) {
            $result['notes'] = $notes;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function sanitizeDepot(array $row): array
    {
        $result = [];

        $location = self::sanitizeString($row['location'] ?? $row['depot'] ?? null);
        if ($location !== null) {
            $result['location'] = $location;
        }

        $dropAt = self::normalizeDateTime($row['drop_at'] ?? $row['arrival'] ?? null);
        if ($dropAt !== null) {
            $result['drop_at'] = $dropAt;
        }

        $boxes = self::sanitizeInt($row['boxes'] ?? $row['parcels'] ?? null);
        if ($boxes !== null) {
            $result['boxes'] = $boxes;
        }

        $notes = self::sanitizeString($row['notes'] ?? null);
        if ($notes !== null) {
            $result['notes'] = $notes;
        }

        return $result;
    }

    private static function sanitizeString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = is_string($value) ? trim($value) : trim((string)$value);
        return $string === '' ? null : $string;
    }

    private static function sanitizeInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && trim($value) === '') {
            return null;
        }

        $int = filter_var($value, FILTER_VALIDATE_INT);
        if ($int === false) {
            return null;
        }

        return $int < 0 ? null : (int)$int;
    }

    private static function normalizeDateTime(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = is_string($value) ? trim($value) : trim((string)$value);
        if ($string === '') {
            return null;
        }

        try {
            $date = new DateTimeImmutable($string);
        } catch (\Exception) {
            return null;
        }

        return $date->format(DateTimeImmutable::ATOM);
    }
}

final class ParcelInput
{
    public readonly int $sequence;
    public readonly ?string $name;
    public readonly ?float $weightKg;
    public readonly ?int $lengthMm;
    public readonly ?int $widthMm;
    public readonly ?int $heightMm;
    public readonly bool $estimated;
    public readonly ?string $notes;

    private function __construct(
        int $sequence,
        ?string $name,
        ?float $weightKg,
        ?int $lengthMm,
        ?int $widthMm,
        ?int $heightMm,
        bool $estimated,
        ?string $notes
    ) {
        $this->sequence = $sequence;
        $this->name = $name;
        $this->weightKg = $weightKg;
        $this->lengthMm = $lengthMm;
        $this->widthMm = $widthMm;
        $this->heightMm = $heightMm;
        $this->notes = $notes;
        $this->estimated = $estimated;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row, int $sequence): self
    {
        $name = isset($row['name']) ? trim((string)$row['name']) : null;
        $weightKg = isset($row['weight_kg']) ? self::toFloat($row['weight_kg']) : (isset($row['kg']) ? self::toFloat($row['kg']) : null);
        $lengthMm = isset($row['length_mm']) ? self::toIntNullable($row['length_mm']) : (isset($row['l']) ? self::toIntNullable((float)$row['l'] * 10) : null);
        $widthMm = isset($row['width_mm']) ? self::toIntNullable($row['width_mm']) : (isset($row['w']) ? self::toIntNullable((float)$row['w'] * 10) : null);
        $heightMm = isset($row['height_mm']) ? self::toIntNullable($row['height_mm']) : (isset($row['h']) ? self::toIntNullable((float)$row['h'] * 10) : null);
        $estimated = (bool)($row['estimated'] ?? false);
        $notes = isset($row['notes']) ? trim((string)$row['notes']) : null;

        return new self($sequence, $name ?: null, $weightKg, $lengthMm, $widthMm, $heightMm, $estimated, $notes ?: null);
    }

    private static function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $f = filter_var($value, FILTER_VALIDATE_FLOAT);
        return $f === false ? null : (float)$f;
    }

    private static function toIntNullable(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = filter_var($value, FILTER_VALIDATE_INT);
        return $int === false ? null : (int)$int;
    }
}

final class TotalsInput
{
    public readonly ?int $boxCount;
    public readonly ?float $totalWeightKg;

    private function __construct(?int $boxCount, ?float $totalWeightKg)
    {
        $this->boxCount = $boxCount;
        $this->totalWeightKg = $totalWeightKg;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $boxCount = isset($row['box_count']) ? filter_var($row['box_count'], FILTER_VALIDATE_INT) : null;
        $weight = null;
        if (isset($row['total_weight_kg'])) {
            $weight = filter_var($row['total_weight_kg'], FILTER_VALIDATE_FLOAT);
        } elseif (isset($row['total_kg'])) {
            $weight = filter_var($row['total_kg'], FILTER_VALIDATE_FLOAT);
        }

        return new self(
            $boxCount === false ? null : ($boxCount !== null ? max(0, (int)$boxCount) : null),
            $weight === false ? null : ($weight !== null ? max(0.0, (float)$weight) : null)
        );
    }
}

final class ParcelSpec
{
    public int $boxNumber;
    public ?float $weightKg = null;
    public ?int $lengthMm = null;
    public ?int $widthMm = null;
    public ?int $heightMm = null;
    public bool $estimated = false;
    public ?string $notes = null;

    public function toArray(): array
    {
        return [
            'box_number' => $this->boxNumber,
            'weight_kg' => $this->weightKg,
            'length_mm' => $this->lengthMm,
            'width_mm' => $this->widthMm,
            'height_mm' => $this->heightMm,
            'estimated' => $this->estimated,
            'notes' => $this->notes,
        ];
    }
}

final class PlanTotals
{
    public int $boxCount = 0;
    public int $totalWeightG = 0;
}

final class HandlerPlan
{
    public string $mode;
    public string $deliveryMode;
    public string $carrierLabel;
    public string $carrierLane;
    public bool $sendNow;
    public bool $shouldDispatch;
    /** @var list<ParcelSpec> */
    public array $parcels = [];
    public PlanTotals $totals;
    /** @var list<string> */
    public array $warnings = [];
    /** @var array<string, mixed> */
    public array $meta = [];

    public function __construct()
    {
        $this->totals = new PlanTotals();
    }
}

final class TxResult
{
    public int $transferId;
    public int $shipmentId;
    public int $boxCount;
    public float $totalWeightKg;
}

final class GuardianResult
{
    public string $tier = 'green';
    /** @var list<string> */
    public array $messages = [];
}

final class IdempotencyRecord
{
    public string $cacheKey;
    public string $bodyHash;
    public string $responseJson;
    public string $storedAt;
}
