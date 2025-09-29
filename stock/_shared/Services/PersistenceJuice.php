<?php
declare(strict_types=1);
/**
 * File: PersistenceJuice.php
 * Purpose: Stub for Juice persistence stack
 * Author: GitHub Copilot
 * Last Modified: 2025-09-29
 * Dependencies: none
 */

namespace Modules\Transfers\Stock\Shared\Services;

use RuntimeException;

final class PersistenceJuice
{
    public function commit(): never
    {
        throw new RuntimeException('PERSISTENCE_JUICE_NOT_IMPLEMENTED');
    }
}
