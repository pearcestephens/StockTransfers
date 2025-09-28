<?php
declare(strict_types=1);

/**
 * File: modules/transfers/stock/lib/AccessPolicy.php
 * Purpose: Centralized access-control helpers for transfer modules.
 * Author: GitHub Copilot
 * Last Modified: 2025-09-25
 * Dependencies: Core\DB (optional), cis_pdo(), userHasOutletAccess()
 */

namespace Modules\Transfers\Stock\Lib;

use PDO;

final class AccessPolicy
{
    /**
     * In-process cache of transfer outlet pairs to minimize repeat queries in a single request.
     * @var array<int,array{from:string,to:string}|null>
     */
    private static array $transferOutletCache = [];

    /**
     * Simple per-user outlet access decision cache: [userId][outletVendUuid] => bool
     * @var array<int,array<string,bool>>
     */
    private static array $userOutletAccessCache = [];

    /**
     * Resolve a PDO instance using Core\DB, cis_pdo(), or global fallback.
     *
     * @throws \RuntimeException when no PDO instance is available
     */
    private static function pdo(): PDO {
        if (class_exists('\Core\DB') && method_exists('\Core\DB','instance')) {
            $pdo = \Core\DB::instance(); if ($pdo instanceof PDO) return $pdo;
        }
        if (function_exists('cis_pdo')) {
            $pdo = cis_pdo(); if ($pdo instanceof PDO) return $pdo;
        }
        if (!empty($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];
        throw new \RuntimeException('DB not initialized — include /app.php first');
    }

    /**
     * Determine whether the given user is permitted to access the transfer.
     * When the platform exposes userHasOutletAccess(), both origin and destination
     * outlets must be accessible; otherwise the policy defaults to allow.
     */
    public static function canAccessTransfer(int $userId, int $transferId): bool {
        $row = self::fetchTransferOutlets($transferId);
        if ($row === null) return false;
        if (self::isAdmin($userId)) return true; // admin override
        if (!function_exists('userHasOutletAccess')) return true; // permissive fallback when ACL fn absent
        return self::userHasOutlet($userId, $row['from']) && self::userHasOutlet($userId, $row['to']);
    }

    /**
     * User can PACK (send) if they can access the origin outlet.
     */
    public static function canPackTransfer(int $userId, int $transferId): bool {
        $row = self::fetchTransferOutlets($transferId); if ($row === null) return false;
        if (self::isAdmin($userId)) return true;
        if (!function_exists('userHasOutletAccess')) return true;
        return self::userHasOutlet($userId, $row['from']);
    }

    /**
     * User can RECEIVE if they can access the destination outlet.
     */
    public static function canReceiveTransfer(int $userId, int $transferId): bool {
        $row = self::fetchTransferOutlets($transferId); if ($row === null) return false;
        if (self::isAdmin($userId)) return true;
        if (!function_exists('userHasOutletAccess')) return true;
        return self::userHasOutlet($userId, $row['to']);
    }

    /**
     * Bulk filter helper: returns only those transfer ids the user may perform the specified action on.
     * Action: view|pack|receive
     * Unknown action falls back to 'view'.
     * @param int   $userId
     * @param int[] $transferIds
     * @param string $action
     * @return int[] accessible transfer ids in original order
     */
    public static function filterAccessible(int $userId, array $transferIds, string $action = 'view'): array {
        $action = strtolower($action);
        $out = [];
        foreach ($transferIds as $tidRaw) {
            $tid = (int)$tidRaw; if ($tid <= 0) continue;
            $allow = match($action) {
                'pack' => self::canPackTransfer($userId, $tid),
                'receive' => self::canReceiveTransfer($userId, $tid),
                default => self::canAccessTransfer($userId, $tid),
            };
            if ($allow) $out[] = $tid;
        }
        return $out;
    }

    /**
     * Throwing variant for controller entrypoints.
     * @throws \RuntimeException
     */
    public static function requireAccess(int $userId, int $transferId, string $action = 'view'): void {
        $ok = match(strtolower($action)) {
            'pack' => self::canPackTransfer($userId, $transferId),
            'receive' => self::canReceiveTransfer($userId, $transferId),
            default => self::canAccessTransfer($userId, $transferId),
        };
        if (!$ok) {
            throw new \RuntimeException('forbidden_transfer_access');
        }
    }

    // ---- internals -------------------------------------------------------

    /**
     * Fetch cached outlet pair for a transfer.
     * @return array{from:string,to:string}|null
     */
    private static function fetchTransferOutlets(int $transferId): ?array {
        if ($transferId <= 0) return null;
        if (array_key_exists($transferId, self::$transferOutletCache)) {
            return self::$transferOutletCache[$transferId];
        }
        try {
            $db = self::pdo();
            $st = $db->prepare('SELECT outlet_from, outlet_to FROM transfers WHERE id=:id');
            $st->execute(['id' => $transferId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) { self::$transferOutletCache[$transferId] = null; return null; }
            $pair = ['from' => (string)$row['outlet_from'], 'to' => (string)$row['outlet_to']];
            self::$transferOutletCache[$transferId] = $pair;
            return $pair;
        } catch (\Throwable $e) {
            self::$transferOutletCache[$transferId] = null; return null;
        }
    }

    /** Determine if user is admin via optional helpers or session. */
    private static function isAdmin(int $userId): bool {
        try {
            if (function_exists('userIsAdmin')) return (bool)userIsAdmin($userId);
            if (!empty($_SESSION['is_admin'])) return (bool)$_SESSION['is_admin'];
        } catch (\Throwable $e) { /* ignore */ }
        return false;
    }

    /** Cached outlet access check */
    private static function userHasOutlet(int $userId, string $outletVendUuid): bool {
        if ($userId <= 0 || $outletVendUuid === '') return false;
        if (!isset(self::$userOutletAccessCache[$userId])) self::$userOutletAccessCache[$userId] = [];
        if (array_key_exists($outletVendUuid, self::$userOutletAccessCache[$userId])) {
            return self::$userOutletAccessCache[$userId][$outletVendUuid];
        }
        $ok = true; // permissive default when fn missing
        if (function_exists('userHasOutletAccess')) {
            try { $ok = (bool)userHasOutletAccess($userId, $outletVendUuid); } catch (\Throwable $e) { $ok = false; }
        }
        self::$userOutletAccessCache[$userId][$outletVendUuid] = $ok;
        return $ok;
    }
}
