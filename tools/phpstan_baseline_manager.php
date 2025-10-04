<?php
declare(strict_types=1);
/**
 * phpstan_baseline_manager.php
 * Automated baseline lifecycle helper for centralized PHPStan usage.
 *
 * Actions:
 *   generate  - Create baseline if missing (fails if exists unless --force)
 *   update    - Re-generate baseline (overwrites)
 *   verify    - Runs analyse with baseline and reports residual (new) errors count
 *
 * Examples (from application root):
 *   php modules/transfers/tools/phpstan_baseline_manager.php --action=generate --level=7
 *   php modules/transfers/tools/phpstan_baseline_manager.php --action=update --level=8
 *   php modules/transfers/tools/phpstan_baseline_manager.php --action=verify
 *
 * Query params also supported for internal HTTP (keep internal only):
 *   /modules/transfers/tools/phpstan_baseline_manager.php?action=verify
 *
 * Output: JSON envelope unless --raw passed (then raw tool output only).
 */

header('Content-Type: application/json; charset=utf-8');

@require_once __DIR__ . '/../_shared/Autoload.php';

// ---- Argument / Param parsing ----
$cliArgs = $_SERVER['argv'] ?? [];
$cli = [];
foreach ($cliArgs as $arg) {
    if (strpos($arg, '--') === 0) {
        $eq = strpos($arg, '=');
        if ($eq !== false) {
            $cli[substr($arg, 2, $eq - 2)] = substr($arg, $eq + 1);
        } else {
            $cli[substr($arg, 2)] = '1';
        }
    }
}

$action = $cli['action'] ?? ($_GET['action'] ?? 'verify');
$level  = $cli['level'] ?? ($_GET['level'] ?? '7');
$force  = isset($cli['force']) || isset($_GET['force']);
$raw    = isset($cli['raw']) || isset($_GET['raw']);

if (!in_array($action, ['generate', 'update', 'verify'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => ['code' => 'INVALID_ACTION', 'message' => 'Action must be generate, update, or verify']], JSON_PRETTY_PRINT);
    exit;
}

// ---- Resolve shared vendor / root ----
$autoloadUsed = function_exists('cis_shared_composer_autoload_used') ? cis_shared_composer_autoload_used() : null;
if ($autoloadUsed === null) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => ['code' => 'NO_SHARED_VENDOR', 'message' => 'Shared vendor/autoload not found']], JSON_PRETTY_PRINT);
    exit;
}
$vendorDir  = dirname($autoloadUsed);
$root       = dirname($vendorDir); // assume root has composer.json
$baseline   = $root . '/phpstan-baseline.neon';
$rootConfig = $root . '/phpstan.neon';
$distConfig = $root . '/phpstan.neon.dist';

// Locate phpstan binary
$phpstanBin = null;
foreach ([$vendorDir . '/bin/phpstan', $vendorDir . '/phpstan/phpstan/phpstan'] as $cand) {
    if (is_file($cand) && is_executable($cand)) { $phpstanBin = $cand; break; }
}
if ($phpstanBin === null) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => ['code' => 'PHPSTAN_NOT_FOUND', 'message' => 'phpstan binary not found in shared vendor']], JSON_PRETTY_PRINT);
    exit;
}

// Choose config file or build temporary one
$configToUse = null;
if (is_file($rootConfig)) { $configToUse = $rootConfig; }
elseif (is_file($distConfig)) { $configToUse = $distConfig; }
else {
    // Construct ephemeral config including all modules, excluding backups
    $tempConfig = tempnam(sys_get_temp_dir(), 'phpstan_cfg_');
    $cfg = "parameters:\n  level: $level\n  paths:\n    - modules\n  excludePaths:\n    - */backups/*\n  tmpDir: var/cache/phpstan\n  memoryLimit: 512M\n";
    file_put_contents($tempConfig, $cfg);
    $configToUse = $tempConfig;
}

// Build command depending on action
$baseCmd = escapeshellarg($phpstanBin) . ' analyse --no-progress --memory-limit=512M --level=' . escapeshellarg($level) . ' --configuration=' . escapeshellarg($configToUse) . ' modules';

if ($action === 'generate') {
    if (is_file($baseline) && !$force) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => ['code' => 'BASELINE_EXISTS', 'message' => 'Baseline already exists; use --force or action=update']], JSON_PRETTY_PRINT);
        exit;
    }
    $cmd = $baseCmd . ' --generate-baseline=' . escapeshellarg($baseline);
} elseif ($action === 'update') {
    $cmd = $baseCmd . ' --generate-baseline=' . escapeshellarg($baseline);
} else { // verify
    $cmd = $baseCmd;
    if (is_file($baseline)) {
        $cmd .= ' --baseline=' . escapeshellarg($baseline);
    }
}

// Execute
$spec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open($cmd, $spec, $pipes, $root);
if (!is_resource($proc)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => ['code' => 'EXEC_FAILURE', 'message' => 'Could not start phpstan process']], JSON_PRETTY_PRINT);
    exit;
}
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exit = proc_close($proc);

// Heuristic: count errors (lines starting with '  ✗' or containing 'ERROR')
$residualErrors = 0;
if ($action === 'verify') {
    foreach (explode("\n", $stdout) as $line) {
        if (preg_match('/(^\s*✗| ERROR )/u', $line)) { $residualErrors++; }
    }
}

if ($raw) {
    echo $stdout . "\n" . $stderr;
    exit($exit);
}

// Truncate big outputs
$truncateLimit = 30000;
$tStdout = strlen($stdout) > $truncateLimit ? substr($stdout, 0, $truncateLimit) . "\n...[truncated]" : $stdout;
$tStderr = strlen($stderr) > $truncateLimit ? substr($stderr, 0, $truncateLimit) . "\n...[truncated]" : $stderr;

echo json_encode([
    'success' => $exit === 0 || $action !== 'verify' ? true : false,
    'action' => $action,
    'exit_code' => $exit,
    'autoloader_used' => $autoloadUsed,
    'baseline_path' => $baseline,
    'baseline_exists' => is_file($baseline),
    'level' => $level,
    'cmd' => $cmd,
    'residual_errors_estimate' => $residualErrors,
    'stdout' => $tStdout,
    'stderr' => $tStderr,
    'timestamp' => gmdate('c'),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit($exit);
