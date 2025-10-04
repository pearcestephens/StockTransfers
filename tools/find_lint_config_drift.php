<?php
declare(strict_types=1);
/**
 * find_lint_config_drift.php
 * Scans the modules directory (relative to execution) for per-module lint/style config duplicates
 * that should be centralized (phpcs.xml, .eslintrc*, .editorconfig).
 *
 * Usage (from application root):
 *   php modules/transfers/tools/find_lint_config_drift.php
 *
 * Exit codes:
 *  0 = No drift detected
 *  1 = Drift detected (duplicates found)
 */

$root = realpath(__DIR__ . '/../../../'); // points to modules directory parent (should be public_html/)
if ($root === false) {
    fwrite(STDERR, "[error] Unable to resolve project root.\n");
    exit(1);
}

$modulesDir = $root . '/modules';
if (!is_dir($modulesDir)) {
    fwrite(STDERR, "[error] modules directory not found at $modulesDir\n");
    exit(1);
}

$forbiddenFiles = [
    'phpcs.xml', '.phpcs.xml', '.eslintrc', '.eslintrc.json', '.eslintrc.js', '.editorconfig'
];

$drift = [];
$dirIter = new RecursiveDirectoryIterator($modulesDir, FilesystemIterator::SKIP_DOTS);
$iter = new RecursiveIteratorIterator($dirIter, RecursiveIteratorIterator::SELF_FIRST);
foreach ($iter as $file) {
    if (!$file->isFile()) { continue; }
    $base = $file->getFilename();
    if (in_array($base, $forbiddenFiles, true)) {
        // Allow only at root (project) not inside individual module subfolders.
        // If file path differs from root-level expectation, mark drift.
        $path = $file->getPathname();
        // We allow these only if they are inside central shared location (explicitly not under individual module after centralization).
        if (preg_match('~/modules/[^/]+/' . preg_quote($base, '~') . '$~', $path)) {
            $drift[] = $path;
        }
    }
}

if ($drift === []) {
    echo "No lint/style config drift detected. All clean." . PHP_EOL;
    exit(0);
}

echo "Drift detected in the following paths (remove or migrate to root):" . PHP_EOL;
foreach ($drift as $p) {
    echo " - $p" . PHP_EOL;
}
exit(1);