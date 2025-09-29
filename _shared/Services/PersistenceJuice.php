<?php
declare(strict_types=1);

namespace CIS\Shared\Services;

use CIS\Shared\Contracts\PersistenceServiceInterface;
use CIS\Shared\Contracts\Results\TxResult;
use CIS\Shared\Contracts\ShipmentPlan;
use PDO;
use RuntimeException;

/**
 * Juice Transfers — Persistence Adapter (STUB)
 *
 * Implements the PersistenceServiceInterface but remains a placeholder until the
 * Juice transfer tables are integrated. Throws a clear exception so the flow is
 * not accidentally enabled prematurely.
 */
final class PersistenceJuice implements PersistenceServiceInterface
{
    public function __construct(private PDO $pdo) {}

    public function commit(ShipmentPlan $plan): TxResult
    {
        throw new RuntimeException(
            'PERSISTENCE_JUICE_NOT_IMPLEMENTED: Wire Juice Transfer persistence when ready.'
        );
    }
}
