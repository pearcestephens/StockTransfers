<?php
/**
 * Shipment Feed Dashboard
 * Simple, useful interface showing latest shipments with weights and recommendations
 * 
 * @author: CIS Development Team
 * @date: 2025-09-30
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/app.php';

// Get recent shipments with details
function getRecentShipments(PDO $pdo, int $limit = 20): array {
    $sql = "
        SELECT 
            ts.id as shipment_id,
            ts.transfer_id,
            ts.delivery_mode,
            ts.carrier_name,
            ts.status,
            ts.packed_at,
            ts.dispatched_at,
            ts.received_at,
            COUNT(tp.id) as parcel_count,
            COALESCE(SUM(tp.weight_kg), 0) as total_weight_kg,
            COALESCE(SUM(tp.weight_grams), 0) as total_weight_grams,
            vo_from.name as from_outlet,
            vo_to.name as to_outlet,
            t.status as transfer_status,
            CASE 
                WHEN ts.delivery_mode = 'courier' THEN 'Courier Service'
                WHEN ts.delivery_mode = 'pickup' THEN 'Customer Pickup'
                WHEN ts.delivery_mode = 'internal' THEN 'Internal Transport'
                ELSE UPPER(ts.delivery_mode)
            END as delivery_type
        FROM transfer_shipments ts
        LEFT JOIN transfer_parcels tp ON ts.id = tp.shipment_id AND tp.deleted_at IS NULL
        LEFT JOIN transfers t ON ts.transfer_id = t.id
        LEFT JOIN vend_outlets vo_from ON t.from_outlet_id = vo_from.id
        LEFT JOIN vend_outlets vo_to ON t.to_outlet_id = vo_to.id
        WHERE ts.deleted_at IS NULL
        GROUP BY ts.id
        ORDER BY ts.packed_at DESC, ts.id DESC
        LIMIT :limit
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get shipping recommendations based on weight and mode
function getShippingRecommendation(float $weightKg, string $deliveryMode, int $parcelCount): array {
    $recommendations = [];
    
    if ($deliveryMode === 'courier') {
        if ($weightKg <= 0.5) {
            $recommendations[] = ['type' => 'success', 'text' => 'Small Satchel (≤500g)'];
        } elseif ($weightKg <= 3.0) {
            $recommendations[] = ['type' => 'info', 'text' => 'Regular Satchel (≤3kg)'];
        } elseif ($weightKg <= 15.0) {
            $recommendations[] = ['type' => 'warning', 'text' => 'Large Satchel (≤15kg)'];
        } elseif ($weightKg <= 25.0) {
            $recommendations[] = ['type' => 'primary', 'text' => 'Box Required (≤25kg)'];
        } else {
            $recommendations[] = ['type' => 'danger', 'text' => 'Multiple Boxes Needed'];
        }
        
        // Service recommendations
        if ($weightKg <= 0.5) {
            $recommendations[] = ['type' => 'muted', 'text' => 'Suggest: Regular Post'];
        } elseif ($weightKg <= 3.0) {
            $recommendations[] = ['type' => 'muted', 'text' => 'Suggest: Courier Satchel'];
        } else {
            $recommendations[] = ['type' => 'muted', 'text' => 'Suggest: Express Service'];
        }
    } elseif ($deliveryMode === 'pickup') {
        $recommendations[] = ['type' => 'info', 'text' => 'Customer Collection'];
        if ($parcelCount > 1) {
            $recommendations[] = ['type' => 'muted', 'text' => 'Multiple packages ready'];
        }
    } elseif ($deliveryMode === 'internal') {
        $recommendations[] = ['type' => 'primary', 'text' => 'Internal Transfer'];
        if ($weightKg > 25.0) {
            $recommendations[] = ['type' => 'warning', 'text' => 'Heavy load - check capacity'];
        }
    }
    
    return $recommendations;
}

// Format weight for display
function formatWeight(float $weightKg): string {
    if ($weightKg < 1.0) {
        return number_format($weightKg * 1000, 0) . 'g';
    }
    return number_format($weightKg, 2) . 'kg';
}

// Get status badge class
function getStatusBadgeClass(string $status): string {
    switch ($status) {
        case 'packed': return 'badge-warning';
        case 'dispatched': 
        case 'in_transit': return 'badge-info';
        case 'delivered':
        case 'received': return 'badge-success';
        case 'cancelled': return 'badge-danger';
        default: return 'badge-secondary';
    }
}

try {
    $pdo = pdo();
    $shipments = getRecentShipments($pdo, 25);
    $currentTime = new DateTime();
} catch (Exception $e) {
    error_log("Shipment Feed Error: " . $e->getMessage());
    $shipments = [];
    $currentTime = new DateTime();
}

// Include header
include $_SERVER['DOCUMENT_ROOT'] . '/assets/template/html-header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/template/header.php';
?>

<div class="container-fluid mt-4">
    <!-- Enhanced Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                <div class="card-body text-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h1 class="h3 mb-1 font-weight-bold">
                                <i class="fa fa-shipping-fast mr-2"></i>
                                Shipment Feed Dashboard
                            </h1>
                            <p class="mb-0 opacity-75">
                                <i class="fa fa-clock mr-1"></i>
                                Latest shipments with weights and shipping recommendations
                            </p>
                        </div>
                        <div class="text-right">
                            <div class="badge badge-light text-dark px-3 py-2 font-weight-normal">
                                <i class="fa fa-sync-alt mr-1"></i>
                                Live Feed
                            </div>
                            <div class="text-light mt-1">
                                <small><?= $currentTime->format('M j, Y g:i A') ?></small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Stats -->
    <div class="row mb-4">
        <div class="col-sm-6 col-lg-3 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center">
                    <div class="text-primary mb-2">
                        <i class="fa fa-box fa-2x"></i>
                    </div>
                    <h4 class="font-weight-bold text-dark"><?= count($shipments) ?></h4>
                    <p class="text-muted mb-0">Recent Shipments</p>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center">
                    <div class="text-success mb-2">
                        <i class="fa fa-weight fa-2x"></i>
                    </div>
                    <h4 class="font-weight-bold text-dark">
                        <?= formatWeight(array_sum(array_column($shipments, 'total_weight_kg'))) ?>
                    </h4>
                    <p class="text-muted mb-0">Total Weight</p>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center">
                    <div class="text-info mb-2">
                        <i class="fa fa-shipping-fast fa-2x"></i>
                    </div>
                    <h4 class="font-weight-bold text-dark">
                        <?= count(array_filter($shipments, fn($s) => $s['delivery_mode'] === 'courier')) ?>
                    </h4>
                    <p class="text-muted mb-0">Courier Shipments</p>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center">
                    <div class="text-warning mb-2">
                        <i class="fa fa-clock fa-2x"></i>
                    </div>
                    <h4 class="font-weight-bold text-dark">
                        <?= count(array_filter($shipments, fn($s) => $s['status'] === 'packed')) ?>
                    </h4>
                    <p class="text-muted mb-0">Awaiting Dispatch</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Shipments Feed -->
    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-bottom-0 py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0 font-weight-bold text-dark">
                            <i class="fa fa-list mr-2 text-primary"></i>
                            Latest Shipments
                        </h5>
                        <button class="btn btn-outline-primary btn-sm" onclick="location.reload();">
                            <i class="fa fa-refresh mr-1"></i>Refresh
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($shipments)): ?>
                        <div class="text-center py-5">
                            <i class="fa fa-inbox fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No Recent Shipments</h5>
                            <p class="text-muted">No shipment data available at this time.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="thead-light">
                                    <tr>
                                        <th class="border-0 font-weight-bold">Transfer</th>
                                        <th class="border-0 font-weight-bold">Route</th>
                                        <th class="border-0 font-weight-bold">Type</th>
                                        <th class="border-0 font-weight-bold">Weight</th>
                                        <th class="border-0 font-weight-bold">Parcels</th>
                                        <th class="border-0 font-weight-bold">Status</th>
                                        <th class="border-0 font-weight-bold">Recommendations</th>
                                        <th class="border-0 font-weight-bold">Packed</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($shipments as $shipment): 
                                        $recommendations = getShippingRecommendation(
                                            $shipment['total_weight_kg'], 
                                            $shipment['delivery_mode'], 
                                            $shipment['parcel_count']
                                        );
                                        $packedTime = new DateTime($shipment['packed_at']);
                                        $timeAgo = $currentTime->diff($packedTime);
                                    ?>
                                        <tr>
                                            <td>
                                                <div class="font-weight-bold text-primary">
                                                    #<?= (int)$shipment['transfer_id'] ?>
                                                </div>
                                                <small class="text-muted">Ship <?= (int)$shipment['shipment_id'] ?></small>
                                            </td>
                                            <td>
                                                <div class="small">
                                                    <div class="font-weight-medium">
                                                        <?= htmlspecialchars($shipment['from_outlet'] ?: 'Unknown', ENT_QUOTES) ?>
                                                    </div>
                                                    <div class="text-muted">
                                                        <i class="fa fa-arrow-down mr-1"></i>
                                                        <?= htmlspecialchars($shipment['to_outlet'] ?: 'Unknown', ENT_QUOTES) ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge badge-outline-secondary">
                                                    <?= htmlspecialchars($shipment['delivery_type'], ENT_QUOTES) ?>
                                                </span>
                                                <?php if ($shipment['carrier_name']): ?>
                                                    <div class="small text-muted mt-1">
                                                        <?= htmlspecialchars($shipment['carrier_name'], ENT_QUOTES) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="font-weight-bold text-dark">
                                                    <?= formatWeight($shipment['total_weight_kg']) ?>
                                                </div>
                                                <?php if ($shipment['total_weight_grams'] != $shipment['total_weight_kg'] * 1000): ?>
                                                    <small class="text-muted">
                                                        (~<?= number_format($shipment['total_weight_grams']) ?>g)
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge badge-info">
                                                    <?= (int)$shipment['parcel_count'] ?> 
                                                    <?= $shipment['parcel_count'] == 1 ? 'parcel' : 'parcels' ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge <?= getStatusBadgeClass($shipment['status']) ?>">
                                                    <?= ucfirst($shipment['status']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php foreach ($recommendations as $rec): ?>
                                                    <div class="badge badge-<?= $rec['type'] ?> mr-1 mb-1">
                                                        <?= htmlspecialchars($rec['text'], ENT_QUOTES) ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </td>
                                            <td>
                                                <div class="small">
                                                    <?php if ($timeAgo->days > 0): ?>
                                                        <?= $timeAgo->days ?>d ago
                                                    <?php elseif ($timeAgo->h > 0): ?>
                                                        <?= $timeAgo->h ?>h ago
                                                    <?php else: ?>
                                                        <?= $timeAgo->i ?>m ago
                                                    <?php endif; ?>
                                                </div>
                                                <div class="text-muted" style="font-size: 0.75rem;">
                                                    <?= $packedTime->format('M j, g:i A') ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="font-weight-bold mb-3">
                        <i class="fa fa-bolt mr-2 text-warning"></i>
                        Quick Actions
                    </h6>
                    <div class="row">
                        <div class="col-md-3 mb-2">
                            <a href="pack.php" class="btn btn-outline-primary btn-block">
                                <i class="fa fa-plus mr-1"></i>New Transfer
                            </a>
                        </div>
                        <div class="col-md-3 mb-2">
                            <a href="receive.php" class="btn btn-outline-success btn-block">
                                <i class="fa fa-check mr-1"></i>Receive Items
                            </a>
                        </div>
                        <div class="col-md-3 mb-2">
                            <button class="btn btn-outline-info btn-block" onclick="window.print();">
                                <i class="fa fa-print mr-1"></i>Print Report
                            </button>
                        </div>
                        <div class="col-md-3 mb-2">
                            <button class="btn btn-outline-secondary btn-block" onclick="location.reload();">
                                <i class="fa fa-sync-alt mr-1"></i>Refresh Data
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .badge-outline-secondary {
        background: transparent;
        border: 1px solid #6c757d;
        color: #6c757d;
    }
    
    .card {
        transition: all 0.2s ease;
    }
    
    .card:hover {
        transform: translateY(-2px);
    }
    
    .table td {
        vertical-align: middle;
        border-top: 1px solid #f8f9fa;
    }
    
    .table tbody tr:hover {
        background-color: #f8f9ff;
    }
    
    @media print {
        .btn, .card:hover {
            transform: none;
            box-shadow: none !important;
        }
        
        .badge {
            color: #000 !important;
            background: #fff !important;
            border: 1px solid #000 !important;
        }
    }
</style>

<script>
    // Auto-refresh every 2 minutes
    setInterval(function() {
        const lastRefresh = localStorage.getItem('shipment_feed_last_refresh');
        const now = Date.now();
        
        if (!lastRefresh || (now - parseInt(lastRefresh)) > 120000) {
            localStorage.setItem('shipment_feed_last_refresh', now.toString());
            location.reload();
        }
    }, 120000);
    
    // Mark refresh time
    localStorage.setItem('shipment_feed_last_refresh', Date.now().toString());
</script>

<?php
// Include footer
include $_SERVER['DOCUMENT_ROOT'] . '/assets/template/personalisation-menu.php';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/template/html-footer.php';
include $_SERVER['DOCUMENT_ROOT'] . '/assets/template/footer.php';
?>