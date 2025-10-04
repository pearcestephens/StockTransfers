<?php
declare(strict_types=1);
/**
 * tooling_health.php
 * Lightweight JSON status endpoint for shared tooling & autoload verification.
 * Safe to include in an authenticated internal environment; does not expose secrets.
 */

header('Content-Type: application/json; charset=utf-8');

// Attempt to load module shared autoload (non-fatal if missing) for helper functions.
@require_once __DIR__ . '/../_shared/Autoload.php';

$candidates = function_exists('cis_shared_composer_autoload_paths') ? cis_shared_composer_autoload_paths() : [];
$used       = function_exists('cis_shared_composer_autoload_used') ? cis_shared_composer_autoload_used() : null;

// Derive some version info if common tools are present.
$versions = [];
$composerLockPaths = [];
if ($used) {
    // Try to locate a composer.lock relative to autoload path.
    $vendorDir = dirname($used);
    $rootCandidate = dirname($vendorDir) . '/composer.lock';
    if (is_file($rootCandidate)) {
        $composerLockPaths[] = $rootCandidate;
        $lockJson = @json_decode((string)file_get_contents($rootCandidate), true, 512, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE);
        if (is_array($lockJson) && isset($lockJson['packages-dev'])) {
            foreach ($lockJson['packages-dev'] as $pkg) {
                if (!isset($pkg['name'], $pkg['version'])) { continue; }
                $name = (string)$pkg['name'];
                if (in_array($name, [
                    'squizlabs/php_codesniffer',
                    'phpstan/phpstan',
                    'friendsofphp/php-cs-fixer',
                    'vimeo/psalm'
                ], true)) {
                    $versions[$name] = $pkg['version'];
                }
            }
        }
    }
}

// Fallback quick probes (in case composer.lock unavailable) by class existence.
if (!isset($versions['squizlabs/php_codesniffer']) && class_exists('PHP_CodeSniffer\Config')) {
    $versions['squizlabs/php_codesniffer'] = 'present';
}
if (!isset($versions['phpstan/phpstan']) && class_exists('PHPStan\Command\CommandHelper')) {
    $versions['phpstan/phpstan'] = 'present';
}
if (!isset($versions['friendsofphp/php-cs-fixer']) && class_exists('PhpCsFixer\Runner\Runner')) {
    $versions['friendsofphp/php-cs-fixer'] = 'present';
}
if (!isset($versions['vimeo/psalm']) && class_exists('Psalm\Internal\Analyzer\ProjectAnalyzer')) {
    $versions['vimeo/psalm'] = 'present';
}

$response = [
    'success' => true,
    'data' => [
        'autoload' => [
            'candidates' => $candidates,
            'used' => $used,
            'used_exists' => $used ? is_file($used) : false,
        ],
        'tool_versions' => $versions,
        'composer_lock_paths_checked' => $composerLockPaths,
        'module' => 'transfers',
        'timestamp' => gmdate('c'),
    ],
    'meta' => [
        'note' => 'Tooling health snapshot; safe for internal diagnostic dashboards.'
    ]
];

echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;