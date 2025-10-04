<?php
declare(strict_types=1);

/**
 * Autoload.php
 *
 * Registers the PSR-4 style autoloader for the shared CIS library.
 *
 * @package CIS\Shared
 */

spl_autoload_register(
    static function (string $class): void {
        // Handle CIS\Shared namespace
        $prefix = 'CIS\\Shared\\';
        $baseDir = __DIR__ . DIRECTORY_SEPARATOR;

        if (strncmp($prefix, $class, strlen($prefix)) === 0) {
            $relative = substr($class, strlen($prefix));
            $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
            if (is_file($file)) {
                require $file;
                return;
            }
        }

        // Handle Modules\Transfers\Shared namespace
        $prefix = 'Modules\\Transfers\\Shared\\';
        if (strncmp($prefix, $class, strlen($prefix)) === 0) {
            $relative = substr($class, strlen($prefix));
            $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
            if (is_file($file)) {
                require $file;
                return;
            }
        }

        // Handle Modules\Transfers\Stock namespace (services, lib, etc.)
        $prefix = 'Modules\\Transfers\\Stock\\';
        if (strncmp($prefix, $class, strlen($prefix)) === 0) {
            $relative = substr($class, strlen($prefix));
            // Base directory for stock module: go up one and into stock/
            $stockBase = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'stock' . DIRECTORY_SEPARATOR;
            $candidate = $stockBase . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
            if (is_file($candidate)) {
                require $candidate;
                return;
            }
            // Fallback: case-insensitive directories (lowercase path segments except filename)
            $parts = explode('\\', $relative);
            $fileName = array_pop($parts);
            $lowerDir = strtolower(implode(DIRECTORY_SEPARATOR, $parts));
            $candidate2 = $stockBase . $lowerDir . DIRECTORY_SEPARATOR . $fileName . '.php';
            if (is_file($candidate2)) {
                require $candidate2;
                return;
            }
        }
    }
);

// Load asset helper functions
require_once __DIR__ . '/Support/AssetHelpers.php';

// Attempt to include a shared Composer autoload (modules-wide or application root) only once.
// This allows every module to leverage a single vendor/ directory placed either at:
//  - public_html/vendor/autoload.php (application-wide)
//  - public_html/modules/_shared/vendor/autoload.php (modules shared)
//  - module-local vendor (fallback – discouraged for cross-module reuse)
// We deliberately perform a quick existence scan without expensive globbing.
static $composerAutoloadLoaded = false;
if (!$composerAutoloadLoaded) {
    $candidatePaths = [];

    // Application root guess (document root or three levels up from here)
    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    if ($docRoot !== '') {
        $candidatePaths[] = rtrim($docRoot, '/\\') . '/vendor/autoload.php';
        $candidatePaths[] = rtrim($docRoot, '/\\') . '/modules/_shared/vendor/autoload.php';
    }

    // Relative fallbacks from this file location (module/_shared/Autoload.php)
    $candidatePaths[] = dirname(__DIR__, 2) . '/_shared/vendor/autoload.php'; // modules/_shared
    $candidatePaths[] = dirname(__DIR__, 3) . '/vendor/autoload.php';          // application root
    $candidatePaths[] = dirname(__DIR__) . '/vendor/autoload.php';            // module-local (last resort)

    foreach ($candidatePaths as $path) {
        if (is_file($path)) {
            /** @psalm-suppress UnresolvableInclude */
            require_once $path;
            $composerAutoloadLoaded = true;
            // Record which path was actually used for diagnostics.
            $GLOBALS['cis_shared_composer_autoload_used'] = $path;
            if (!defined('CIS_SHARED_COMPOSER_AUTOLOAD_USED')) {
                define('CIS_SHARED_COMPOSER_AUTOLOAD_USED', $path);
            }
            break;
        }
    }
}

// Provide a tiny helper function (namespaced) only if not already defined.
// This avoids collision when multiple modules include their Autoload.
if (!function_exists('cis_shared_composer_autoload_paths')) {
    /**
     * Returns the probed Composer autoload candidate paths (for diagnostics / debugging UI).
     * @return array<int,string>
     */
    function cis_shared_composer_autoload_paths(): array
    {
        return [
            // Order mirrors scan order in the loader above
            (isset($_SERVER['DOCUMENT_ROOT']) ? rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . '/vendor/autoload.php' : ''),
            (isset($_SERVER['DOCUMENT_ROOT']) ? rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . '/modules/_shared/vendor/autoload.php' : ''),
            dirname(__DIR__, 2) . '/_shared/vendor/autoload.php',
            dirname(__DIR__, 3) . '/vendor/autoload.php',
            dirname(__DIR__) . '/vendor/autoload.php',
        ];
    }
}

if (!function_exists('cis_shared_composer_autoload_used')) {
    /**
     * Returns the autoload path that was successfully included, or null if none.
     * @return string|null
     */
    function cis_shared_composer_autoload_used(): ?string
    {
        return $GLOBALS['cis_shared_composer_autoload_used'] ?? (defined('CIS_SHARED_COMPOSER_AUTOLOAD_USED') ? CIS_SHARED_COMPOSER_AUTOLOAD_USED : null);
    }
}
