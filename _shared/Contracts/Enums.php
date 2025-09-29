<?php
declare(strict_types=1);

namespace CIS\Shared\Contracts;

/**
 * Enums.php
 *
 * Enumeration definitions used across shared pack flows.
 *
 * @package CIS\Shared\Contracts
 */
enum PackMode: string
{
    case PACKED_NOT_SENT = 'PACKED_NOT_SENT';
    case NZC_MANUAL = 'NZC_MANUAL';
    case NZP_MANUAL = 'NZP_MANUAL';
    case PICKUP = 'PICKUP';
    case INTERNAL_DRIVE = 'INTERNAL_DRIVE';
    case DEPOT_DROP = 'DEPOT_DROP';
    case RECEIVE_ONLY = 'RECEIVE_ONLY';
}

/**
 * Delivery mode classification.
 */
enum DeliveryMode: string
{
    case COURIER = 'courier';
    case PICKUP = 'pickup';
    case INTERNAL_DRIVE = 'internal_drive';
}
