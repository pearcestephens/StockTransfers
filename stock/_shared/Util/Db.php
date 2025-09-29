<?php
declare(strict_types=1);
/**
 * File: Db.php
 * Purpose: Central PDO resolver for pack/send stack
 * Author: GitHub Copilot
 * Last Modified: 2025-09-29
 * Dependencies: PDO, app.php bootstrap
 */

namespace Modules\Transfers\Stock\Shared\Util;

use PDO;
use RuntimeException;

final class Db
{
    private function __construct()
    {
    }

    public static function pdo(): PDO
    {
        if (function_exists('cis_pdo')) {
            $pdo = cis_pdo();
            if ($pdo instanceof PDO) {
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                return $pdo;
            }
        }

        if (class_exists('Core\\DB') && method_exists('Core\\DB', 'instance')) {
            /** @var mixed $candidate */
            $candidate = \Core\DB::instance();
            if ($candidate instanceof PDO) {
                $candidate->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                return $candidate;
            }
        }

        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            $candidate = $GLOBALS['pdo'];
            $candidate->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return $candidate;
        }

        throw new RuntimeException('Database connection not available for pack/send stack. Ensure app.php is loaded.');
    }
}
