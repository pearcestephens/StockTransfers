<?php
/**
 * Central asset versioning for the Transfers (Pack) module.
 *
 * Strategy:
 *  - Default static version string (bump manually on release).
 *  - If APP_ENV=dev (or ?devassets=1 in URL) we append a short rolling TTL hash
 *    derived from the current minute so developers always get fresh assets
 *    without manual bumps.
 *  - Supports override via environment variable TRANSFER_ASSET_VERSION.
 */

declare(strict_types=1);

if (!function_exists('transfer_asset_version')) {
    function transfer_asset_version(): string {
        static $ver = null;
        if ($ver !== null) return $ver;

        // Base (bump this for production releases)
        $base = '20250929b';

        // Override if env provided (e.g. in deployment pipeline)
        $envOverride = getenv('TRANSFER_ASSET_VERSION');
        if ($envOverride) {
            $base = preg_replace('~[^A-Za-z0-9_.-]~', '', $envOverride) ?: $base;
        }

        $isDev = (getenv('APP_ENV') === 'dev') || isset($_GET['devassets']);
        if ($isDev) {
            // Add a 1‑minute rolling suffix to defeat cache locally without thrashing.
            $base .= '-dev' . date('YmdHi');
        }

        return $ver = $base;
    }
}
