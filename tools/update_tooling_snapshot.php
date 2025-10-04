<?php
declare(strict_types=1);
/**
 * update_tooling_snapshot.php
 * Runs the tooling dashboard and persists a lightweight snapshot JSON under var/tooling/.
 * Intended for cron (e.g., every 15 minutes) to enable historical trend collection.
 */

@require_once __DIR__ . '/../_shared/Autoload.php';

$dashboardScript = __DIR__ . '/tooling_dashboard.php';
if (!is_file($dashboardScript)) {
    fwrite(STDERR, "dashboard script missing\n");
    exit(1);
}

$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($dashboardScript);
$proc = proc_open($cmd, [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
if (!is_resource($proc)) {
    fwrite(STDERR, "proc_open failed\n");
    exit(1);
}
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exit = proc_close($proc);

if ($exit !== 0) {
    fwrite(STDERR, "dashboard exit code $exit\n$stderr\n");
    exit($exit);
}

$decoded = json_decode($stdout, true);
if (!is_array($decoded) || !isset($decoded['data'])) {
    fwrite(STDERR, "invalid dashboard JSON\n");
    exit(1);
}

$varDir = realpath(__DIR__ . '/../var') ?: (__DIR__ . '/../var');
if (!is_dir($varDir)) { mkdir($varDir, 0775, true); }
$toolingDir = $varDir . '/tooling';
if (!is_dir($toolingDir)) { mkdir($toolingDir, 0775, true); }

$snapshotPath = $toolingDir . '/snapshot.json';
$historyPath  = $toolingDir . '/history.log';

$payload = [
    'taken_at' => gmdate('c'),
    'autoload_used' => $decoded['data']['autoload']['used'] ?? null,
    'phpstan' => $decoded['data']['phpstan'],
    'drift' => $decoded['data']['lint_config_drift'],
];

$json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// Write snapshot atomically
$tmpFile = $snapshotPath . '.tmp';
file_put_contents($tmpFile, $json);
rename($tmpFile, $snapshotPath);

// Append compact line to history (single-line JSON)
file_put_contents($historyPath, $json . "\n", FILE_APPEND);

echo "Snapshot updated: $snapshotPath\n";
exit(0);
