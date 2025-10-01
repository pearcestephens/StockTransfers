<?php
declare(strict_types=1);

/**
 * File: modules/platform/pulselock/cron/pulselock_guard.php
 * Purpose: Scheduled guardian task that executes PulseLock health checks and updates snapshots/incidents.
 * Author: GitHub Copilot
 * Last Modified: 2025-09-25
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/app.php';
require_once __DIR__ . '/../lib/PulseLock.php';

use CIS\PulseLock\CronFreshnessChecker;
use CIS\PulseLock\DbChecker;
use CIS\PulseLock\Guard;
use CIS\PulseLock\HttpEndpointChecker;
use CIS\PulseLock\QueueChecker;
use CIS\PulseLock\Registry;
use CIS\PulseLock\Result;
use CIS\PulseLock\SystemChecker;
use CIS\PulseLock\TlsCertChecker;
use CIS\PulseLock\VendApiChecker;

$started = microtime(true);
$registry = new Registry();

$registry
    ->add(new HttpEndpointChecker(
        'website_main',
        'Retail Website',
        'https://www.vapeshed.co.nz/',
        ['retail', 'website'],
        8,
        '<title>The Vape Shed'
    ))
    ->add(new HttpEndpointChecker(
        'website_wholesale',
        'Wholesale Portal',
        'https://wholesale.vapeshed.co.nz/',
        ['wholesale', 'website'],
        8,
        '<title>Wholesale Portal'
    ))
    ->add(new HttpEndpointChecker(
        'website_staff_portal',
        'Staff Portal',
        'https://www.staff.vapeshed.co.nz/',
        ['cis', 'staff'],
        6,
        '<title>CIS Portal'
    ))
    ->add(new DbChecker('db_primary', 'Primary Database', ['cis']))
    ->add(new QueueChecker('queue_backlog', 'Queue Backlog', ['queues', 'cis']))
    ->add(new CronFreshnessChecker('cron_recency', 'Cron Recency', ['cron', 'cis']))
    ->add(new TlsCertChecker('tls_staff_portal', 'TLS Staff Portal', 'www.staff.vapeshed.co.nz', 443, ['security', 'staff']))
    ->add(new VendApiChecker())
    ->add(new SystemChecker('system_load', 'Server Load', ['infra']));

$results = [];
foreach ($registry->all() as $checker) {
    $results[] = $checker->run();
}

[$overall, $score, $pipelineWorst] = Guard::aggregate($results);
$took = (int)round((microtime(true) - $started) * 1000);

$snapshot = [
    'status' => $overall,
    'score' => $score,
    'pipelines' => $pipelineWorst,
    'checks' => array_map(static function (Result $result): array {
        return [
            'key' => $result->checkKey,
            'label' => $result->label,
            'status' => $result->status,
            'score' => $result->score,
            'took_ms' => $result->tookMs,
            'meta' => $result->meta,
        ];
    }, $results),
    'executed_at' => gmdate('c'),
    'took_ms' => $took,
    'version' => 'v1',
];

Guard::persist($snapshot);
Guard::setGlobalLock($pipelineWorst, $overall);

$pdo = Guard::db();
if ($pdo instanceof PDO) {
    updateCronRuns($pdo, $took, $overall, $score);
    reconcileIncidents($pdo, $overall, $pipelineWorst, $snapshot);
}

fwrite(STDOUT, sprintf("[PulseLock] %s status=%s score=%.2f took=%dms\n", gmdate('c'), $overall, $score, $took));
exit(0);

/**
 * @param array<string,string> $pipelineWorst
 * @param array<string,mixed> $snapshot
 */
function reconcileIncidents(PDO $pdo, string $overall, array $pipelineWorst, array $snapshot): void
{
    $now = gmdate('Y-m-d H:i:s');
    $openStmt = $pdo->prepare('SELECT id, pipeline_key, severity FROM system_incidents WHERE resolved_at IS NULL');
    $openStmt->execute();
    $open = $openStmt->fetchAll(PDO::FETCH_ASSOC);

    $active = [];
    foreach ($pipelineWorst as $pipeline => $status) {
        if ($status === 'red') {
            $active[$pipeline] = 'critical';
        } elseif ($status === 'amber') {
            $active[$pipeline] = 'degraded';
        }
    }

    // Resolve incidents no longer present.
    foreach ($open as $row) {
        $pipeline = (string)$row['pipeline_key'];
        $severity = (string)$row['severity'];
        $current = $active[$pipeline] ?? null;
        if ($current === null || $current !== $severity) {
            $close = $pdo->prepare('UPDATE system_incidents SET resolved_at = :resolved_at, resolution_meta = :meta WHERE id = :id');
            $close->execute([
                ':resolved_at' => $now,
                ':meta' => json_encode(['snapshot' => $snapshot], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':id' => (int)$row['id'],
            ]);
        }
    }

    // Open or update incidents for active degradations.
    foreach ($active as $pipeline => $severity) {
        $exists = null;
        foreach ($open as $row) {
            if ((string)$row['pipeline_key'] === $pipeline && (string)$row['severity'] === $severity) {
                $exists = $row;
                break;
            }
        }
        if ($exists) {
            $touch = $pdo->prepare('UPDATE system_incidents SET last_seen_at = :seen WHERE id = :id');
            $touch->execute([
                ':seen' => $now,
                ':id' => (int)$exists['id'],
            ]);
        } else {
            $create = $pdo->prepare('INSERT INTO system_incidents (pipeline_key, severity, opened_at, last_seen_at, snapshot_json)
                VALUES (:pipeline, :severity, :opened_at, :last_seen_at, :snapshot)');
            $create->execute([
                ':pipeline' => $pipeline,
                ':severity' => $severity,
                ':opened_at' => $now,
                ':last_seen_at' => $now,
                ':snapshot' => json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    $audit = $pdo->prepare('INSERT INTO system_status_audit (overall_status, overall_score, details_json, created_at)
        VALUES (:status, :score, :details, :created_at)');
    $audit->execute([
        ':status' => $overall,
        ':score' => $snapshot['score'] ?? 0.0,
        ':details' => json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':created_at' => $now,
    ]);
}

function updateCronRuns(PDO $pdo, int $took, string $overall, float $score): void
{
    $now = gmdate('Y-m-d H:i:s');
    $pdo->prepare('REPLACE INTO cron_runs (job_key, last_ok, last_duration_ms, meta_json)
        VALUES (:job_key, :last_ok, :duration, :meta)')->execute([
        ':job_key' => 'pulselock_guard',
        ':last_ok' => $now,
        ':duration' => $took,
        ':meta' => json_encode(['status' => $overall, 'score' => $score], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
}
