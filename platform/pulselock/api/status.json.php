<?php
declare(strict_types=1);

/**
 * File: modules/platform/pulselock/api/status.json.php
 * Purpose: Public PulseLock status API exposing latest snapshot and incident summary.
 * Author: GitHub Copilot
 * Last Modified: 2025-09-25
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/app.php';
require_once __DIR__ . '/../lib/PulseLock.php';

use CIS\PulseLock\Guard;

header('Content-Type: application/json');
header('Cache-Control: max-age=15, stale-while-revalidate=30');

$cachePath = $_SERVER['DOCUMENT_ROOT'] . '/_cache/pulselock/status.json';
$payload = null;

if (is_readable($cachePath)) {
    $json = file_get_contents($cachePath);
    if ($json !== false) {
        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }
}

if (!is_array($payload)) {
    $payload = fallbackFromDatabase();
}

if (!is_array($payload)) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'error' => [
            'code' => 'snapshot_unavailable',
            'message' => 'PulseLock status snapshot is not available at this time.',
        ],
        'request_id' => requestId(),
    ]);
    exit;
}

$payload['success'] = true;
$payload['request_id'] = requestId();
$payload['generated_at'] = gmdate('c');

echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

/**
 * @return array<string,mixed>|null
 */
function fallbackFromDatabase(): ?array
{
    $pdo = Guard::db();
    if ($pdo === null) {
        return null;
    }

    $stmt = $pdo->query('SELECT status, score, pipelines_json, checks_json, took_ms, version, created_at FROM system_status_snapshots ORDER BY id DESC LIMIT 1');
    $row = $stmt ? $stmt->fetch(\PDO::FETCH_ASSOC) : false;

    if (!$row) {
        return null;
    }

    try {
        return [
            'status' => $row['status'],
            'score' => (float)$row['score'],
            'pipelines' => json_decode((string)$row['pipelines_json'], true, 512, JSON_THROW_ON_ERROR),
            'checks' => json_decode((string)$row['checks_json'], true, 512, JSON_THROW_ON_ERROR),
            'took_ms' => (int)$row['took_ms'],
            'executed_at' => (string)$row['created_at'],
            'version' => $row['version'],
        ];
    } catch (\JsonException $e) {
        return null;
    }
}

function requestId(): string
{
    return defined('REQUEST_ID') ? (string)REQUEST_ID : bin2hex(random_bytes(8));
}
