<?php
declare(strict_types=1);

/**
 * File: modules/platform/pulselock/views/status.php
 * Purpose: PulseLock status hub UI rendering summary, pipelines, check grid, and timeline.
 * Author: GitHub Copilot
 * Last Modified: 2025-09-25
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/app.php';
require_once __DIR__ . '/../lib/PulseLock.php';

use CIS\PulseLock\Guard;

$payload = loadSnapshot();
$timeline = loadTimeline();
$assetBase = 'https://staff.vapeshed.co.nz/modules/platform/pulselock/assets';

if (function_exists('page_title')) {
    page_title('PulseLock Status Hub');
}

includeStyles($assetBase);

?>
<div class="container-fluid pulselock layout">
    <div class="row align-items-center mb-4">
        <div class="col-md-8">
            <h1 class="pulselock__title">PulseLock Status Hub</h1>
            <p class="pulselock__subtitle text-muted mb-0">
                Real-time operational overview across CIS, retail, wholesale, and integrations.
            </p>
        </div>
        <div class="col-md-4 text-md-right mt-3 mt-md-0">
            <span class="pulselock__badge pulselock__badge--<?php echo htmlspecialchars($payload['status']); ?>">
                <?php echo strtoupper(htmlspecialchars($payload['status'])); ?>
            </span>
            <div class="pulselock__score">Health score: <strong><?php echo number_format((float)$payload['score'], 2); ?></strong></div>
            <div class="pulselock__timestamp">Updated <?php echo htmlspecialchars($payload['executed_at'] ?? 'n/a'); ?> (<?php echo htmlspecialchars($payload['took_ms'] ?? 0); ?> ms)</div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h2 class="card-title h5 mb-0">Service Pipelines</h2>
                    <span class="text-muted">Overall coverage</span>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php foreach ($payload['pipelines'] as $pipeline => $status): ?>
                            <div class="col-sm-6 col-lg-4 mb-3">
                                <div class="pulselock__pipeline pulselock__pipeline--<?php echo htmlspecialchars($status); ?>">
                                    <div class="pulselock__pipeline-label"><?php echo htmlspecialchars($pipeline); ?></div>
                                    <div class="pulselock__pipeline-status text-uppercase"><?php echo htmlspecialchars($status); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($payload['pipelines'])): ?>
                            <div class="col-12 text-muted">No pipelines registered.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="card mb-4">
                <div class="card-header">
                    <h2 class="card-title h5 mb-0">Check Grid</h2>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped table-sm mb-0">
                            <thead>
                            <tr>
                                <th scope="col">Check</th>
                                <th scope="col" class="text-center">Status</th>
                                <th scope="col" class="text-center">Score</th>
                                <th scope="col" class="text-center">Duration</th>
                                <th scope="col">Pipelines</th>
                                <th scope="col">Meta</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($payload['checks'] as $check): ?>
                                <tr>
                                    <th scope="row"><?php echo htmlspecialchars($check['label']); ?></th>
                                    <td class="text-center">
                                        <span class="badge badge-pill badge-status badge-status--<?php echo htmlspecialchars($check['status']); ?>">
                                            <?php echo strtoupper(htmlspecialchars($check['status'])); ?>
                                        </span>
                                    </td>
                                    <td class="text-center"><?php echo number_format((float)$check['score'], 1); ?></td>
                                    <td class="text-center"><?php echo (int)$check['took_ms']; ?> ms</td>
                                    <td><?php echo htmlspecialchars(implode(', ', (array)($check['meta']['pipelines'] ?? []))); ?></td>
                                    <td class="text-muted small">
                                        <?php echo htmlspecialchars(formatMeta($check['meta'])); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($payload['checks'])): ?>
                                <tr><td colspan="6" class="text-center text-muted">No checks recorded.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card mb-4">
                <div class="card-header">
                    <h2 class="card-title h5 mb-0">Incident Timeline</h2>
                </div>
                <div class="card-body pulselock__timeline">
                    <?php foreach ($timeline as $entry): ?>
                        <div class="pulselock__timeline-item pulselock__timeline-item--<?php echo htmlspecialchars($entry['severity']); ?>">
                            <div class="pulselock__timeline-meta">
                                <strong><?php echo htmlspecialchars($entry['pipeline_key']); ?></strong>
                                <span class="pulselock__timeline-status text-uppercase"><?php echo htmlspecialchars($entry['severity']); ?></span>
                            </div>
                            <div class="pulselock__timeline-time">Opened <?php echo htmlspecialchars($entry['opened_at']); ?></div>
                            <?php if (!empty($entry['resolved_at'])): ?>
                                <div class="pulselock__timeline-time text-success">Resolved <?php echo htmlspecialchars($entry['resolved_at']); ?></div>
                            <?php else: ?>
                                <div class="pulselock__timeline-time text-warning">Active (last seen <?php echo htmlspecialchars($entry['last_seen_at']); ?>)</div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($timeline)): ?>
                        <div class="text-muted">No recent incidents recorded.</div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title h5 mb-0">Automation Controls</h2>
                </div>
                <div class="card-body">
                    <ul class="list-unstyled mb-0">
                        <li class="media mb-3">
                            <span class="fa-solid fa-robot fa-lg text-primary mr-3"></span>
                            <div class="media-body">
                                <h3 class="h6 mb-1">Form Locking</h3>
                                <p class="mb-0 text-muted">Forms respect PulseLock gating and will enter safe-mode when pipelines degrade.</p>
                            </div>
                        </li>
                        <li class="media">
                            <span class="fa-solid fa-sync fa-lg text-primary mr-3"></span>
                            <div class="media-body">
                                <h3 class="h6 mb-1">Cron Guard</h3>
                                <p class="mb-0 text-muted">Cron tasks monitor status.json freshness and trigger alerts when stale.</p>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<?php includeScripts($assetBase); ?>
<script>window.PulseLockBootstrap = <?php echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;</script>

<?php
/**
 * @return array{status:string,score:float,pipelines:array<string,string>,checks:array<int,array<string,mixed>>,executed_at?:string,took_ms?:int}
 */
function loadSnapshot(): array
{
    $cache = dirname($_SERVER['DOCUMENT_ROOT']) . '/private_html/pulselock/status.json';
    if (is_readable($cache)) {
        $decoded = json_decode((string)file_get_contents($cache), true);
        if (is_array($decoded)) {
            return normaliseSnapshot($decoded);
        }
    }

    $pdo = Guard::db();
    if ($pdo) {
    $stmt = $pdo->query('SELECT status, score, pipelines_json, checks_json, took_ms, created_at FROM system_status_snapshots ORDER BY id DESC LIMIT 1');
    $row = $stmt ? $stmt->fetch(\PDO::FETCH_ASSOC) : false;
        if ($row) {
            return normaliseSnapshot([
                'status' => $row['status'],
                'score' => (float)$row['score'],
                'pipelines' => json_decode((string)$row['pipelines_json'], true),
                'checks' => json_decode((string)$row['checks_json'], true),
                'took_ms' => (int)$row['took_ms'],
                'executed_at' => $row['created_at'],
            ]);
        }
    }

    return [
        'status' => 'unknown',
        'score' => 0.0,
        'pipelines' => [],
        'checks' => [],
        'took_ms' => 0,
    ];
}

/**
 * @return array<int,array<string,mixed>>
 */
function loadTimeline(): array
{
    $pdo = Guard::db();
    if (!$pdo) {
        return [];
    }
    $stmt = $pdo->query('SELECT pipeline_key, severity, opened_at, last_seen_at, resolved_at FROM system_incidents ORDER BY opened_at DESC LIMIT 12');
    return $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
}

/**
 * @param array<string,mixed> $snapshot
 * @return array{status:string,score:float,pipelines:array<string,string>,checks:array<int,array<string,mixed>>,executed_at?:string,took_ms?:int}
 */
function normaliseSnapshot(array $snapshot): array
{
    $snapshot['status'] = $snapshot['status'] ?? 'unknown';
    $snapshot['score'] = (float)($snapshot['score'] ?? 0.0);
    $snapshot['pipelines'] = is_array($snapshot['pipelines'] ?? null) ? $snapshot['pipelines'] : [];
    $snapshot['checks'] = is_array($snapshot['checks'] ?? null) ? $snapshot['checks'] : [];
    $snapshot['took_ms'] = (int)($snapshot['took_ms'] ?? 0);
    return $snapshot;
}

/**
 * @param array<string,mixed> $meta
 */
function formatMeta(array $meta): string
{
    unset($meta['pipelines']);
    if (empty($meta)) {
        return '';
    }
    return http_build_query($meta, '', ' • ');
}

function includeStyles(string $assetBase): void
{
    echo '<link rel="stylesheet" href="' . htmlspecialchars($assetBase . '/css/pulselock.css') . '">' . "\n";
}

function includeScripts(string $assetBase): void
{
    echo '<script src="' . htmlspecialchars($assetBase . '/js/pulselock.js') . '" defer></script>' . "\n";
}
