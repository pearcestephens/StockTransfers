<?php
declare(strict_types=1);
/**
 * module_analyse.php
 * Convenience endpoint/CLI for running PHPStan (and future Psalm) against ONLY this module
 * while leveraging the centralized root/vendor toolchain.
 *
 * Invocation (CLI):
 *   php modules/transfers/tools/module_analyse.php --level=7
 *   php modules/transfers/tools/module_analyse.php --tool=psalm
 *
 * Invocation (HTTP internal):
 *   /modules/transfers/tools/module_analyse.php?level=7
 *   /modules/transfers/tools/module_analyse.php?tool=psalm
 *
 * Security: Keep internal / authenticated. Output may include file paths but no secrets.
 */

header('Content-Type: application/json; charset=utf-8');

// Load shared autoload for helper functions + tool classes.
@require_once __DIR__ . '/../_shared/Autoload.php';

// --- Argument Parsing ---
$args = $_SERVER['argv'] ?? [];
$cliParams = [];
foreach ($args as $arg) {
    if (strpos($arg, '--') === 0) {
        $eq = strpos($arg, '=');
        if ($eq !== false) {
            $cliParams[substr($arg, 2, $eq - 2)] = substr($arg, $eq + 1);
        } else {
            $cliParams[substr($arg, 2)] = '1';
        }
    }
}

$tool = $cliParams['tool'] ?? ($_GET['tool'] ?? 'phpstan');
$level = $cliParams['level'] ?? ($_GET['level'] ?? '7');
$raw = isset($cliParams['raw']) || isset($_GET['raw']);

// Validate tool choice
$allowedTools = ['phpstan', 'psalm'];
if (!in_array($tool, $allowedTools, true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => [
            'code' => 'INVALID_TOOL',
            'message' => 'Tool must be one of: ' . implode(', ', $allowedTools),
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

// Determine autoload used
$autoloadUsed = function_exists('cis_shared_composer_autoload_used') ? cis_shared_composer_autoload_used() : null;
if ($autoloadUsed === null) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'code' => 'NO_SHARED_VENDOR',
            'message' => 'No shared Composer autoload located; ensure root or shared vendor installed.',
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

$vendorDir = dirname($autoloadUsed);
$projectRoot = dirname($vendorDir); // assumption root contains vendor/

// Resolve executable path
if ($tool === 'phpstan') {
    $execCandidates = [
        $vendorDir . '/bin/phpstan',
        $vendorDir . '/phpstan/phpstan/phpstan',
    ];
    $baseCmd = null;
    foreach ($execCandidates as $c) {
        if (is_file($c) && is_executable($c)) { $baseCmd = $c; break; }
    }
    if ($baseCmd === null) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => [
                'code' => 'PHPSTAN_NOT_FOUND',
                'message' => 'phpstan binary not found in shared vendor.',
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }
    $modulePath = realpath(__DIR__ . '/..');
    $cmd = escapeshellarg($baseCmd) . ' analyse ' . escapeshellarg((string)$modulePath) . ' --level=' . escapeshellarg($level) . ' --no-progress --memory-limit=512M';
} else { // psalm
    $execCandidates = [
        $vendorDir . '/bin/psalm',
        $vendorDir . '/vimeo/psalm/psalm',
    ];
    $baseCmd = null;
    foreach ($execCandidates as $c) {
        if (is_file($c) && is_executable($c)) { $baseCmd = $c; break; }
    }
    if ($baseCmd === null) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => [
                'code' => 'PSALM_NOT_FOUND',
                'message' => 'psalm binary not found in shared vendor.',
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }
    $cmd = escapeshellarg($baseCmd) . ' --no-progress --shepherd --stats';
}

// Execute command
$descriptorSpec = [
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$process = proc_open($cmd, $descriptorSpec, $pipes, $projectRoot);
if (!is_resource($process)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'code' => 'EXEC_FAILURE',
            'message' => 'Unable to start analysis process.',
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);

if ($raw) {
    // Emit raw combined output for direct console piping
    echo $stdout . \PHP_EOL . $stderr;
    exit($exitCode);
}

// Truncate to avoid massive payloads
$maxLen = 25000; // 25KB limit
$truncatedStdout = strlen($stdout) > $maxLen ? substr($stdout, 0, $maxLen) . "\n...[truncated]" : $stdout;
$truncatedStderr = strlen($stderr) > $maxLen ? substr($stderr, 0, $maxLen) . "\n...[truncated]" : $stderr;

echo json_encode([
    'success' => $exitCode === 0,
    'tool' => $tool,
    'level' => $tool === 'phpstan' ? $level : null,
    'exit_code' => $exitCode,
    'cmd' => $cmd,
    'autoload_used' => $autoloadUsed,
    'stdout' => $truncatedStdout,
    'stderr' => $truncatedStderr,
    'truncated' => [
        'stdout' => strlen($stdout) > $maxLen,
        'stderr' => strlen($stderr) > $maxLen,
    ],
    'timestamp' => gmdate('c'),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit($exitCode);
