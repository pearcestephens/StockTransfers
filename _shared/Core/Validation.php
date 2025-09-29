<?php
declare(strict_types=1);

namespace CIS\Shared\Core;

use CIS\Shared\Contracts\Errors as E;
use CIS\Shared\Contracts\PackMode;
use CIS\Shared\Contracts\PackSendRequest;
use CIS\Shared\Contracts\RequestValidatorInterface;
use CIS\Shared\Support\Uuid;
use InvalidArgumentException;

/**
 * Validation.php
 *
 * Implements request validation rules for Pack & Send flows.
 *
 * @package CIS\Shared\Core
 */
final class Validation implements RequestValidatorInterface
{
    /**
     * {@inheritDoc}
     */
    public function validate(PackSendRequest $req): void
    {
        if (!isset($req->transfer_id)) {
            throw new InvalidArgumentException(E::MISSING_PARAM . ': transfer.id');
        }

        if (!Uuid::isUuid($req->from_outlet_uuid)) {
            throw new InvalidArgumentException(E::VALIDATION . ': from_outlet_uuid');
        }

        if (!Uuid::isUuid($req->to_outlet_uuid)) {
            throw new InvalidArgumentException(E::VALIDATION . ': to_outlet_uuid');
        }

        switch ($req->mode) {
            case PackMode::PACKED_NOT_SENT:
                break;
            case PackMode::NZC_MANUAL:
            case PackMode::NZP_MANUAL:
                if (
                    !$req->parcels
                    && (!$req->totals || ($req->totals->box_count <= 0 && $req->totals->total_weight_g <= 0))
                ) {
                    throw new InvalidArgumentException(E::MISSING_PARAM . ': parcels or totals required for courier manual');
                }
                break;
            case PackMode::PICKUP:
                if (!$req->pickup || !$req->pickup->by || !$req->pickup->time) {
                    throw new InvalidArgumentException(E::MISSING_PARAM . ': pickup.by, pickup.time');
                }
                break;
            case PackMode::INTERNAL_DRIVE:
                if (
                    !$req->internal
                    || !$req->internal->depart
                    || (!$req->internal->driver_staff_id && !$req->internal->driver_name)
                ) {
                    throw new InvalidArgumentException(E::MISSING_PARAM . ': internal.driver (id or name) and internal.depart');
                }
                break;
            case PackMode::DEPOT_DROP:
                if (!$req->depot || !$req->depot->location || !$req->depot->when) {
                    throw new InvalidArgumentException(E::MISSING_PARAM . ': depot.location, depot.when');
                }
                break;
            case PackMode::RECEIVE_ONLY:
                break;
        }
    }
}
