<?php
declare(strict_types=1);

namespace CIS\Shared\Core;

use CIS\Shared\Contracts\HandlerFactoryInterface;
use CIS\Shared\Contracts\HandlerInterface;
use CIS\Shared\Contracts\PackMode;
use CIS\Shared\Handlers\CourierManualNzcHandler;
use CIS\Shared\Handlers\CourierManualNzpHandler;
use CIS\Shared\Handlers\DepotDropHandler;
use CIS\Shared\Handlers\InternalDriveHandler;
use CIS\Shared\Handlers\PackedNotSentHandler;
use CIS\Shared\Handlers\PickupHandler;
use CIS\Shared\Handlers\ReceiveOnlyHandler;

/**
 * HandlerFactory.php
 *
 * Resolves handlers for Pack & Send modes.
 *
 * @package CIS\Shared\Core
 */
final class HandlerFactory implements HandlerFactoryInterface
{
    /**
     * {@inheritDoc}
     */
    public function forMode(PackMode $mode): HandlerInterface
    {
        return match ($mode) {
            PackMode::PACKED_NOT_SENT => new PackedNotSentHandler(),
            PackMode::NZC_MANUAL => new CourierManualNzcHandler(),
            PackMode::NZP_MANUAL => new CourierManualNzpHandler(),
            PackMode::PICKUP => new PickupHandler(),
            PackMode::INTERNAL_DRIVE => new InternalDriveHandler(),
            PackMode::DEPOT_DROP => new DepotDropHandler(),
            PackMode::RECEIVE_ONLY => new ReceiveOnlyHandler(),
        };
    }
}
