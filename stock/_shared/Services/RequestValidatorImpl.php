<?php
declare(strict_types=1);
/**
 * File: RequestValidatorImpl.php
 * Purpose: Validate inbound PackSendRequest payloads before orchestration
 * Author: GitHub Copilot
 * Last Modified: 2025-09-29
 * Dependencies: Payloads.php, Util\Uuid
 */

namespace Modules\Transfers\Stock\Shared\Services;

use Modules\Transfers\Stock\Shared\Util\Uuid;

final class RequestValidatorImpl
{
    /**
     * @return ValidationResult
     */
    public function validate(PackSendRequest $request): ValidationResult
    {
        $result = new ValidationResult();

        if ($request->transferId <= 0) {
            $result->addError('MISSING_PARAM', 'transfer.id', 'Transfer identifier is required.');
        }

        if (!Uuid::isValid($request->fromOutletUuid)) {
            $result->addError('VALIDATION', 'transfer.from_outlet_uuid', 'Source outlet UUID is required.');
        }

        if (!Uuid::isValid($request->toOutletUuid)) {
            $result->addError('VALIDATION', 'transfer.to_outlet_uuid', 'Destination outlet UUID is required.');
        }

        $allowedModes = [
            Modes::PACKED_NOT_SENT,
            Modes::NZC_MANUAL,
            Modes::NZP_MANUAL,
            Modes::PICKUP,
            Modes::INTERNAL_DRIVE,
            Modes::DEPOT_DROP,
            Modes::RECEIVE_ONLY,
        ];
        if (!in_array($request->mode, $allowedModes, true)) {
            $result->addError('VALIDATION', 'mode', 'Unsupported mode supplied.');
        }

        if ($request->mode === Modes::PACKED_NOT_SENT && $request->sendNow) {
            $result->addError('VALIDATION', 'send_now', 'PACKED_NOT_SENT cannot dispatch immediately.');
        }

        if ($request->mode === Modes::PICKUP) {
            $pickup = $request->modeData['pickup'] ?? [];
            $contact = trim((string)($pickup['by'] ?? ''));
            $phone = trim((string)($pickup['phone'] ?? ''));
            $timeIso = trim((string)($pickup['time'] ?? ''));
            $boxes = $pickup['parcels'] ?? null;

            if ($contact === '') {
                $result->addError('MISSING_PARAM', 'pickup.by', 'Pickup contact name is required.');
            }

            if ($phone === '') {
                $result->addError('MISSING_PARAM', 'pickup.phone', 'Pickup contact phone is required.');
            }

            if ($timeIso === '') {
                $result->addError('MISSING_PARAM', 'pickup.time', 'Pickup time window is required.');
            }

            if ($boxes !== null && (!is_int($boxes) || $boxes <= 0)) {
                $result->addError('VALIDATION', 'pickup.parcels', 'Pickup box count must be greater than zero.');
            }
        }

        if ($request->mode === Modes::INTERNAL_DRIVE) {
            $internal = $request->modeData['internal'] ?? [];
            $driver = trim((string)($internal['driver'] ?? ''));
            $departAt = trim((string)($internal['depart_at'] ?? ''));
            $boxes = $internal['boxes'] ?? null;

            if ($driver === '') {
                $result->addError('MISSING_PARAM', 'internal.driver', 'Driver name is required for internal runs.');
            }

            if ($departAt === '') {
                $result->addError('MISSING_PARAM', 'internal.depart_at', 'Planned departure time is required for internal runs.');
            }

            if ($boxes !== null && (!is_int($boxes) || $boxes <= 0)) {
                $result->addWarning('internal.boxes', 'Internal run box count should be a positive integer.');
            }
        }

        if ($request->mode === Modes::DEPOT_DROP) {
            $depot = $request->modeData['depot'] ?? [];
            $location = trim((string)($depot['location'] ?? ''));
            $dropAt = trim((string)($depot['drop_at'] ?? ''));
            $boxes = $depot['boxes'] ?? null;

            if ($location === '') {
                $result->addError('MISSING_PARAM', 'depot.location', 'Depot name or location is required.');
            }

            if ($dropAt === '') {
                $result->addError('MISSING_PARAM', 'depot.drop_at', 'Depot drop-off time is required.');
            }

            if ($boxes !== null && (!is_int($boxes) || $boxes <= 0)) {
                $result->addWarning('depot.boxes', 'Depot drop box count should be a positive integer.');
            }
        }

        foreach ($request->parcels as $parcel) {
            if ($parcel->weightKg !== null && $parcel->weightKg <= 0) {
                $result->addError('VALIDATION', 'parcels.weight_kg', 'Parcel weight must be greater than zero.');
                break;
            }
        }

        if ($request->totals->boxCount !== null && $request->totals->boxCount < 0) {
            $result->addError('VALIDATION', 'totals.box_count', 'Totals box_count cannot be negative.');
        }

        if ($request->totals->totalWeightKg !== null && $request->totals->totalWeightKg < 0) {
            $result->addError('VALIDATION', 'totals.total_weight_kg', 'Totals weight cannot be negative.');
        }

        return $result;
    }
}

final class ValidationResult
{
    /** @var list<array{code:string,field:string,message:string}> */
    public array $errors = [];
    /** @var list<array{field:string,message:string}> */
    public array $warnings = [];

    public function addError(string $code, string $field, string $message): void
    {
        $this->errors[] = [
            'code' => $code,
            'field' => $field,
            'message' => $message,
        ];
    }

    public function addWarning(string $field, string $message): void
    {
        $this->warnings[] = [
            'field' => $field,
            'message' => $message,
        ];
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}

final class Modes
{
    public const PACKED_NOT_SENT = 'PACKED_NOT_SENT';
    public const NZC_MANUAL = 'COURIER_MANUAL_NZC';
    public const NZP_MANUAL = 'COURIER_MANUAL_NZP';
    public const PICKUP = 'PICKUP';
    public const INTERNAL_DRIVE = 'INTERNAL_DRIVE';
    public const DEPOT_DROP = 'DEPOT_DROP';
    public const RECEIVE_ONLY = 'RECEIVE_ONLY';
}
