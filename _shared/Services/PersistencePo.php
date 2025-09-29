<?php
declare(strict_types=1);

namespace CIS\Shared\Services;

use CIS\Shared\Contracts\PersistenceServiceInterface;
use CIS\Shared\Contracts\Results\TxResult;
use CIS\Shared\Contracts\ShipmentPlan;
use PDO;
use RuntimeException;

/**
 * Purchase Orders (Inbound) — Persistence Adapter (STUB)
 *
 * Mirrors the PersistenceService contract but requires implementation against the
 * purchase-order equivalents. Until wired, this stub prevents accidental usage by
 * throwing a clear runtime exception.
 */
final class PersistencePo implements PersistenceServiceInterface
{
    public function __construct(private PDO $pdo) {}

    public function commit(ShipmentPlan $plan): TxResult
    {
        throw new RuntimeException(
            'PERSISTENCE_PO_NOT_IMPLEMENTED: Wire the PO persistence when ready. ' .
            'Plan received: mode=' . $plan->carrier_label .
            ', send_now=' . ($plan->send_now ? 'true' : 'false')
        );
    }
}
