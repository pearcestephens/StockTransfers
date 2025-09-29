<?php
declare(strict_types=1);

namespace CIS\Shared\Contracts;

/**
 * Dto.php
 *
 * Data-transfer object definitions for Pack & Send orchestration.
 *
 * @package CIS\Shared\Contracts
 */
final class DestSnapshot
{
    /** @var string|null */
    public ?string $name = null;
    /** @var string|null */
    public ?string $company = null;
    /** @var string|null */
    public ?string $addr1 = null;
    /** @var string|null */
    public ?string $addr2 = null;
    /** @var string|null */
    public ?string $suburb = null;
    /** @var string|null */
    public ?string $city = null;
    /** @var string|null */
    public ?string $postcode = null;
    /** @var string|null */
    public ?string $email = null;
    /** @var string|null */
    public ?string $phone = null;
    /** @var string|null */
    public ?string $instructions = null;
}

/**
 * Parcel specification structure.
 */
final class ParcelSpec
{
    /** @var int */
    public int $box_number;
    /** @var float|null */
    public ?float $weight_kg = null;
    /** @var int|null */
    public ?int $length_mm = null;
    /** @var int|null */
    public ?int $width_mm = null;
    /** @var int|null */
    public ?int $height_mm = null;
    /** @var bool */
    public bool $estimated = false;
    /** @var string|null */
    public ?string $notes = null;
}

/**
 * Shipment totals summary.
 */
final class Totals
{
    /** @var int */
    public int $box_count = 0;
    /** @var int */
    public int $total_weight_g = 0;
}

/**
 * Pickup meta-data for click-and-collect flows.
 */
final class PickupMeta
{
    /** @var string */
    public string $by;
    /** @var string */
    public string $time;
    /** @var string|null */
    public ?string $phone = null;
}

/**
 * Internal transfer meta-data.
 */
final class InternalMeta
{
    /** @var int|null */
    public ?int $driver_staff_id = null;
    /** @var string|null */
    public ?string $driver_name = null;
    /** @var string */
    public string $depart;
    /** @var string|null */
    public ?string $vehicle = null;
}

/**
 * Depot drop meta-data.
 */
final class DepotMeta
{
    /** @var string */
    public string $location;
    /** @var string */
    public string $when;
}

/**
 * Carrier options passed to handlers.
 */
final class CarrierOptions
{
    /** @var string|null */
    public ?string $carrier_label = null;
    /** @var array<int, array{code:string,count:int}> */
    public array $nzc_carton_hint = [];
    /** @var array<string, mixed>|null */
    public ?array $satchel = null;
}

/**
 * Pack & send request payload.
 */
final class PackSendRequest
{
    /** @var string */
    public string $idempotency_key;
    /** @var PackMode */
    public PackMode $mode;
    /** @var bool */
    public bool $send_now = true;

    /** @var string|int */
    public string|int $transfer_id;
    /** @var string|null */
    public ?string $transfer_public_id = null;
    /** @var string */
    public string $from_outlet_uuid;
    /** @var string */
    public string $to_outlet_uuid;
    /** @var string|null */
    public ?string $notes = null;

    /** @var DestSnapshot|null */
    public ?DestSnapshot $dest_snapshot = null;

    /** @var ParcelSpec[] */
    public array $parcels = [];
    /** @var Totals|null */
    public ?Totals $totals = null;

    /** @var PickupMeta|null */
    public ?PickupMeta $pickup = null;
    /** @var InternalMeta|null */
    public ?InternalMeta $internal = null;
    /** @var DepotMeta|null */
    public ?DepotMeta $depot = null;

    /** @var CarrierOptions|null */
    public ?CarrierOptions $carrier_options = null;
}

/**
 * Shipment planning result.
 */
final class ShipmentPlan
{
    /** @var DeliveryMode */
    public DeliveryMode $delivery_mode;
    /** @var string */
    public string $carrier_label = '';
    /** @var bool */
    public bool $send_now = false;

    /** @var string|int */
    public string|int $transfer_id;
    /** @var DestSnapshot|null */
    public ?DestSnapshot $dest_snapshot = null;

    /** @var ParcelSpec[] */
    public array $parcels = [];
    /** @var Totals */
    public Totals $totals;
    /** @var array<string, mixed> */
    public array $meta = [];
}

/**
 * Transaction persistence result.
 */
final class TxResult
{
    /** @var string|int */
    public string|int $transfer_id;
    /** @var int */
    public int $shipment_id;
    /** @var int */
    public int $box_count = 0;
    /** @var float */
    public float $total_weight_kg = 0.0;
}

/**
 * Vend interaction result.
 */
final class VendResult
{
    /** @var bool */
    public bool $ok = false;
    /** @var string|null */
    public ?string $vend_transfer_id = null;
    /** @var string|null */
    public ?string $vend_number = null;
    /** @var string|null */
    public ?string $vend_url = null;
    /** @var string|null */
    public ?string $message = null;
}
