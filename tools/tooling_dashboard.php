<?php
declare(strict_types=1);
/**
 * tooling_dashboard.php
 * Aggregates internal tooling status (autoload, PHPStan baseline verify, drift scan)
 * into a single JSON envelope for dashboards.
 *
 * Safe for internal authenticated use only. Does not expose secrets.
 */

header('Content-Type: application/json; charset=utf-8');

@require_once __DIR__ . '/../_shared/Autoload.php';

function td_run(string $cmd, int $timeoutSeconds = 30): array {
    $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptor, $pipes);
    if (!is_resource($proc)) {
        return ['exit' => -1, 'stdout' => '', 'stderr' => 'proc_open failed'];
    }
    $start = microtime(true);
    $stdout = '';
    $stderr = '';
    $read = [$pipes[1], $pipes[2]];
    $write = $except = [];
    while (!empty($read)) {
        $r = $read;
        if (stream_select($r, $write, $except, 1, 0) === false) { break; }
        foreach ($r as $stream) {
            $chunk = fread($stream, 8192);
            if ($chunk === '' || $chunk === false) {
                $idx = array_search($stream, $read, true);
                if ($idx !== false) { unset($read[$idx]); }
                continue;
            }
            if ($stream === $pipes[1]) { $stdout .= $chunk; } else { $stderr .= $chunk; }
        }
        if ((microtime(true) - $start) > $timeoutSeconds) {
            $stderr .= "\n[timeout] exceeded {$timeoutSeconds}s";
            break;
        }
    }
    foreach ($pipes as $p) { fclose($p); }
    $exit = proc_close($proc);
    return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr, 'duration_ms' => (int)((microtime(true)-$start)*1000)];
}

$autoload = [
    'candidates' => function_exists('cis_shared_composer_autoload_paths') ? cis_shared_composer_autoload_paths() : [],
    'used' => function_exists('cis_shared_composer_autoload_used') ? cis_shared_composer_autoload_used() : null,
];

// PHPStan baseline verify (non-fatal if missing)
$phpstan = null;
$baselineScript = __DIR__ . '/phpstan_baseline_manager.php';
if (is_file($baselineScript)) {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($baselineScript) . ' --action=verify';
    $res = td_run($cmd, 60);
    $decoded = json_decode($res['stdout'], true);
    if (is_array($decoded) && isset($decoded['action'])) {
        $phpstan = [
            'ok' => $decoded['success'] ?? false,
            'exit_code' => $decoded['exit_code'] ?? null,
            'residual_errors_estimate' => $decoded['residual_errors_estimate'] ?? null,
            'baseline_exists' => $decoded['baseline_exists'] ?? null,
            'level' => $decoded['level'] ?? null,
            'duration_ms' => $res['duration_ms'],
        ];
    } else {
        $phpstan = [
            'ok' => false,
            'exit_code' => $res['exit'],
            'parse_error' => true,
            'raw_stdout' => substr($res['stdout'], 0, 2000),
            'raw_stderr' => substr($res['stderr'], 0, 1000),
        ];
    }
} else {
    $phpstan = ['ok' => false, 'reason' => 'baseline_manager_missing'];
}

// Drift detection (non-fatal)
$driftScript = __DIR__ . '/find_lint_config_drift.php';
$drift = null;
if (is_file($driftScript)) {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($driftScript);
    $res = td_run($cmd, 20);
    $output = trim($res['stdout'] . '\n' . $res['stderr']);
    $hasDrift = $res['exit'] !== 0;
    $drift = [
        'ok' => !$hasDrift,
        'exit_code' => $res['exit'],
        'has_drift' => $hasDrift,
        'sample' => substr($output, 0, 2000),
        'duration_ms' => $res['duration_ms'],
    ];
} else {
    $drift = ['ok' => false, 'reason' => 'drift_script_missing'];
}

$response = [
    'success' => true,
    'module' => 'transfers',
    'timestamp' => gmdate('c'),
    'data' => [
        'autoload' => $autoload,
        'phpstan' => $phpstan,
        'lint_config_drift' => $drift,
    ],
    'meta' => [
        'note' => 'Aggregated tooling dashboard snapshot',
        'version' => '1.0.0'
    ]
];

echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;
