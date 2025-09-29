<?php
declare(strict_types=1);

namespace CIS\Shared\Services;

use CIS\Shared\Contracts\PersistenceServiceInterface;
use CIS\Shared\Contracts\Results\TxResult;
use CIS\Shared\Contracts\ShipmentPlan;
use PDO;
use RuntimeException;

/**
 * Staff Internal Transfers — Persistence Adapter (STUB)
 *
 * Provides the same interface as PersistenceService but intentionally throws until
 * the staff-transfer tables are wired in. This prevents accidental production usage.
 */
final class PersistenceStaff implements PersistenceServiceInterface
{
    public function __construct(private PDO $pdo) {}

    public function commit(ShipmentPlan $plan): TxResult
    {
        throw new RuntimeException(
            'PERSISTENCE_STAFF_NOT_IMPLEMENTED: Wire Staff Internal Transfer persistence when ready.'
        );
    }
}
