<?php
/**
 * Metrics Card Component
 * 
 * Summary statistics card
 * 
 * Required variables:
 * @var int   $plannedSum    Total planned
 * @var int   $countedSum    Total counted
 * @var int   $diff          Difference
 * @var int   $accuracy      Accuracy percentage
 * @var float $estimatedWeight  Weight in kg
 */

$plannedSum = $plannedSum ?? 0;
$countedSum = $countedSum ?? 0;
$diff = $diff ?? 0;
$accuracy = $accuracy ?? 0;
$estimatedWeight = $estimatedWeight ?? 0;

$diffClass = $diff === 0 ? 'success' : ($diff > 0 ? 'warning' : 'danger');
?>

<div class="card mb-3">
    <div class="card-header">
        <strong><i class="fas fa-chart-bar mr-2"></i>Summary</strong>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-3 text-center">
                <div class="metric">
                    <div class="metric-value"><?= (int)$plannedSum ?></div>
                    <div class="metric-label text-muted">Planned</div>
                </div>
            </div>
            <div class="col-md-3 text-center">
                <div class="metric">
                    <div class="metric-value"><?= (int)$countedSum ?></div>
                    <div class="metric-label text-muted">Counted</div>
                </div>
            </div>
            <div class="col-md-3 text-center">
                <div class="metric">
                    <div class="metric-value text-<?= $diffClass ?>">
                        <?= $diff > 0 ? '+' : '' ?><?= (int)$diff ?>
                    </div>
                    <div class="metric-label text-muted">Difference</div>
                </div>
            </div>
            <div class="col-md-3 text-center">
                <div class="metric">
                    <div class="metric-value"><?= (int)$accuracy ?>%</div>
                    <div class="metric-label text-muted">Accuracy</div>
                </div>
            </div>
        </div>
        <div class="row mt-3">
            <div class="col-12 text-center">
                <div class="metric">
                    <div class="metric-value">
                        <?= number_format((float)$estimatedWeight, 3) ?> kg
                    </div>
                    <div class="metric-label text-muted">Estimated Weight</div>
                </div>
            </div>
        </div>
    </div>
</div>
