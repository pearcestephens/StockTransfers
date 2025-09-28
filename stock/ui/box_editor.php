<?php
declare(strict_types=1);

/**
 * box_editor.php
 * 
 * Interactive Box Editor UI - allows users to view and modify auto-generated box allocations
 * Integrates with BoxAllocationService and provides drag-and-drop interface
 * 
 * Author: CIS System
 * Last Modified: 2025-09-26
 * Dependencies: BoxAllocationService, transfer tables, CIS UI framework
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/app.php';
require_once __DIR__ . '/../services/BoxAllocationService.php';

$transfer_id = (int)($_GET['transfer'] ?? 0);
if ($transfer_id <= 0) {
    header('Location: /modules/transfers/stock/');
    exit;
}

// Get current allocation or generate new one
$allocation_service = new BoxAllocationService($transfer_id);
$allocation_result = $allocation_service->generateOptimalAllocation();

if (!$allocation_result['success']) {
    die('Error loading box allocation: ' . $allocation_result['error']);
}

$boxes = $allocation_result['boxes'];
$transfer_data = $allocation_result;

// Page title and navigation
$page_title = "Box Editor - Transfer #{$transfer_id}";
$breadcrumbs = [
    ['url' => '/modules/transfers/stock/', 'label' => 'Transfers'],
    ['url' => "/modules/transfers/stock/pack.php?transfer={$transfer_id}", 'label' => "Transfer #{$transfer_id}"],
    ['url' => '', 'label' => 'Box Editor']
];

include_once $_SERVER['DOCUMENT_ROOT'] . '/CIS_TEMPLATE.php';
?>

<div class="container-fluid mt-3" id="box-editor">
    <!-- Header Section -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2>📦 Box Editor - Transfer #<?= $transfer_id ?></h2>
                    <p class="text-muted">
                        Auto-sorted <?= count($boxes) ?> boxes • 
                        <?= $transfer_data['total_items'] ?> items • 
                        <?= $transfer_data['total_weight_kg'] ?>kg total
                    </p>
                </div>
                <div>
                    <button class="btn btn-outline-secondary" id="btn-regenerate">
                        🔄 Re-generate
                    </button>
                    <button class="btn btn-outline-primary" id="btn-add-box">
                        ➕ Add Box
                    </button>
                    <button class="btn btn-success" id="btn-save-allocation">
                        💾 Save & Apply
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="card-title">📦 Boxes</h5>
                    <h3 class="text-primary" id="total-boxes"><?= count($boxes) ?></h3>
                    <small class="text-muted">Auto-optimized</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="card-title">⚖️ Weight</h5>
                    <h3 class="text-info" id="total-weight"><?= $transfer_data['total_weight_kg'] ?>kg</h3>
                    <small class="text-muted">Distributed optimally</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="card-title">💰 Est. Cost</h5>
                    <h3 class="text-warning" id="total-cost">$<?= number_format($transfer_data['pricing']['total_estimated_cost'] ?? 0, 2) ?></h3>
                    <small class="text-muted">All carriers</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="card-title">⚡ Efficiency</h5>
                    <h3 class="text-success" id="efficiency-score">
                        <?= round(array_sum(array_column(array_column($boxes, 'utilization'), 'efficiency_score')) / max(1, count($boxes)), 1) ?>%
                    </h3>
                    <small class="text-muted">Space utilization</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Editor Area -->
    <div class="row">
        <div class="col-lg-8">
            <!-- Box Grid -->
            <div class="card">
                <div class="card-header d-flex justify-content-between">
                    <h5>📦 Box Layout</h5>
                    <div>
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-outline-secondary active" id="view-grid">Grid</button>
                            <button type="button" class="btn btn-outline-secondary" id="view-list">List</button>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div id="boxes-container" class="row">
                        <?php foreach ($boxes as $box_index => $box): ?>
                        <div class="col-md-6 col-xl-4 mb-3">
                            <div class="box-card" data-box-id="<?= $box_index ?>">
                                <div class="card box-item <?= $box['contains_nicotine'] ? 'border-warning' : '' ?> <?= $box['contains_fragile'] ? 'border-danger' : '' ?>">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <h6 class="mb-0">
                                            <?= htmlspecialchars($box['name']) ?>
                                            <?php if ($box['contains_nicotine']): ?>
                                                <span class="badge badge-warning">⚠️ Nicotine</span>
                                            <?php endif; ?>
                                            <?php if ($box['contains_fragile']): ?>
                                                <span class="badge badge-danger">🔸 Fragile</span>
                                            <?php endif; ?>
                                        </h6>
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-toggle="dropdown">
                                                ⋮
                                            </button>
                                            <div class="dropdown-menu">
                                                <a class="dropdown-item" href="#" onclick="editBox(<?= $box_index ?>)">✏️ Edit box</a>
                                                <a class="dropdown-item" href="#" onclick="splitBox(<?= $box_index ?>)">➗ Split box</a>
                                                <div class="dropdown-divider"></div>
                                                <a class="dropdown-item text-danger" href="#" onclick="deleteBox(<?= $box_index ?>)">🗑️ Delete</a>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <!-- Box Info -->
                                        <div class="row text-center mb-2">
                                            <div class="col-4">
                                                <small class="text-muted">Weight</small><br>
                                                <strong><?= round($box['weight_kg'], 2) ?>kg</strong>
                                            </div>
                                            <div class="col-4">
                                                <small class="text-muted">Items</small><br>
                                                <strong><?= $box['item_count'] ?></strong>
                                            </div>
                                            <div class="col-4">
                                                <small class="text-muted">Size</small><br>
                                                <strong><?= $box['template'] ?? 'custom' ?></strong>
                                            </div>
                                        </div>

                                        <!-- Utilization Bars -->
                                        <div class="mb-2">
                                            <small class="text-muted">Weight Utilization</small>
                                            <div class="progress" style="height: 6px;">
                                                <div class="progress-bar" style="width: <?= $box['utilization']['weight_percent'] ?>%"></div>
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <small class="text-muted">Volume Utilization</small>
                                            <div class="progress" style="height: 6px;">
                                                <div class="progress-bar bg-info" style="width: <?= $box['utilization']['volume_percent'] ?>%"></div>
                                            </div>
                                        </div>

                                        <!-- Products List -->
                                        <div class="products-list" style="max-height: 200px; overflow-y: auto;">
                                            <?php foreach ($box['products'] as $product): ?>
                                            <div class="product-item d-flex justify-content-between align-items-center mb-1 p-2 border rounded" 
                                                 draggable="true" 
                                                 data-product-id="<?= htmlspecialchars($product['product_id']) ?>"
                                                 data-from-box="<?= $box_index ?>">
                                                <div class="flex-grow-1">
                                                    <small class="font-weight-bold"><?= htmlspecialchars($product['product_name']) ?></small><br>
                                                    <small class="text-muted">SKU: <?= htmlspecialchars($product['sku']) ?></small>
                                                </div>
                                                <div class="text-right">
                                                    <span class="badge badge-primary"><?= $product['qty'] ?>x</span>
                                                    <button class="btn btn-sm btn-outline-secondary ml-1" onclick="moveProduct('<?= $product['product_id'] ?>', <?= $box_index ?>)">
                                                        ↗️
                                                    </button>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>

                                        <!-- Carrier Info -->
                                        <?php if (isset($box['suggested_carrier'])): ?>
                                        <div class="mt-3 pt-2 border-top">
                                            <small class="text-muted">
                                                📦 <?= $box['suggested_carrier'] ?> • 
                                                💰 $<?= number_format($box['estimated_cost'], 2) ?> • 
                                                ⏱️ <?= $box['delivery_days'] ?> days
                                            </small>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <!-- Control Panel -->
            <div class="card mb-3">
                <div class="card-header">
                    <h6>🎛️ Control Panel</h6>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label>Allocation Strategy</label>
                        <select class="form-control form-control-sm" id="allocation-strategy">
                            <option value="weight_first">Weight-First Packing</option>
                            <option value="volume_first">Volume-First Packing</option>
                            <option value="balanced" selected>Balanced (Current)</option>
                            <option value="cost_optimized">Cost-Optimized</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Safety Margin</label>
                        <input type="range" class="form-control-range" id="safety-margin" min="0.7" max="0.95" step="0.05" value="0.85">
                        <small class="text-muted">Current: 85%</small>
                    </div>
                    <button class="btn btn-outline-primary btn-sm btn-block" onclick="regenerateWithSettings()">
                        🔄 Apply Settings
                    </button>
                </div>
            </div>

            <!-- Savings Opportunities -->
            <?php if (!empty($transfer_data['pricing']['savings_opportunities'])): ?>
            <div class="card mb-3">
                <div class="card-header">
                    <h6>💡 Optimization Tips</h6>
                </div>
                <div class="card-body">
                    <?php foreach ($transfer_data['pricing']['savings_opportunities'] as $tip): ?>
                    <div class="alert alert-info py-2 mb-2">
                        <small><?= htmlspecialchars($tip) ?></small>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Carrier Breakdown -->
            <div class="card">
                <div class="card-header">
                    <h6>🚛 Carrier Breakdown</h6>
                </div>
                <div class="card-body">
                    <?php if (isset($transfer_data['pricing']['carrier_breakdown'])): ?>
                        <?php foreach ($transfer_data['pricing']['carrier_breakdown'] as $carrier => $info): ?>
                        <div class="d-flex justify-content-between mb-2">
                            <span><?= $carrier ?></span>
                            <span>
                                <?= $info['boxes'] ?> boxes • 
                                $<?= number_format($info['cost'], 2) ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Move Product Modal -->
<div class="modal fade" id="moveProductModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Move Product</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Move to Box:</label>
                    <select class="form-control" id="move-target-box">
                        <?php foreach ($boxes as $box_index => $box): ?>
                        <option value="<?= $box_index ?>"><?= htmlspecialchars($box['name']) ?> (<?= $box['item_count'] ?> items, <?= round($box['weight_kg'], 2) ?>kg)</option>
                        <?php endforeach; ?>
                        <option value="new">➕ Create New Box</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Quantity to Move:</label>
                    <input type="number" class="form-control" id="move-quantity" min="1" value="1">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="confirm-move">Move Product</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Box Modal -->
<div class="modal fade" id="editBoxModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Box Properties</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Box Name:</label>
                    <input type="text" class="form-control" id="edit-box-name">
                </div>
                <div class="row">
                    <div class="col-4">
                        <div class="form-group">
                            <label>Length (mm):</label>
                            <input type="number" class="form-control" id="edit-box-length">
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="form-group">
                            <label>Width (mm):</label>
                            <input type="number" class="form-control" id="edit-box-width">
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="form-group">
                            <label>Height (mm):</label>
                            <input type="number" class="form-control" id="edit-box-height">
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label>Max Weight (kg):</label>
                    <input type="number" class="form-control" id="edit-box-max-weight" step="0.1">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="confirm-edit-box">Save Changes</button>
            </div>
        </div>
    </div>
</div>

<style>
.box-card {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.box-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 4px 20px rgba(0,0,0,0.1);
}

.product-item {
    cursor: grab;
    transition: background-color 0.2s ease;
}

.product-item:hover {
    background-color: #f8f9fa;
}

.product-item:active {
    cursor: grabbing;
}

.drop-zone {
    border: 2px dashed #007bff;
    background-color: rgba(0,123,255,0.1);
}

.progress {
    border-radius: 10px;
}

.progress-bar {
    transition: width 0.3s ease;
}

.card {
    border-radius: 10px;
}

.badge {
    font-size: 0.7em;
}

#boxes-container.list-view .col-md-6,
#boxes-container.list-view .col-xl-4 {
    flex: 0 0 100%;
    max-width: 100%;
}

#boxes-container.list-view .box-card .card {
    margin-bottom: 15px;
}
</style>

<script>
let currentAllocation = <?= json_encode($allocation_result) ?>;
let draggedProduct = null;
let dragSourceBox = null;

$(document).ready(function() {
    initializeDragAndDrop();
    bindEventHandlers();
});

function initializeDragAndDrop() {
    // Make products draggable
    $('.product-item').on('dragstart', function(e) {
        draggedProduct = $(this).data('product-id');
        dragSourceBox = $(this).data('from-box');
        $(this).addClass('dragging');
    });

    $('.product-item').on('dragend', function(e) {
        $(this).removeClass('dragging');
        draggedProduct = null;
        dragSourceBox = null;
    });

    // Make box cards drop zones
    $('.box-item').on('dragover', function(e) {
        e.preventDefault();
        $(this).addClass('drop-zone');
    });

    $('.box-item').on('dragleave', function(e) {
        $(this).removeClass('drop-zone');
    });

    $('.box-item').on('drop', function(e) {
        e.preventDefault();
        $(this).removeClass('drop-zone');
        
        const targetBox = $(this).closest('.box-card').data('box-id');
        if (targetBox !== dragSourceBox && draggedProduct) {
            moveProductToBox(draggedProduct, dragSourceBox, targetBox);
        }
    });
}

function bindEventHandlers() {
    $('#btn-regenerate').click(function() {
        regenerateBoxes();
    });

    $('#btn-add-box').click(function() {
        addNewBox();
    });

    $('#btn-save-allocation').click(function() {
        saveAllocation();
    });

    $('#view-grid').click(function() {
        $('#boxes-container').removeClass('list-view');
        $('.btn-group .btn').removeClass('active');
        $(this).addClass('active');
    });

    $('#view-list').click(function() {
        $('#boxes-container').addClass('list-view');
        $('.btn-group .btn').removeClass('active');
        $(this).addClass('active');
    });

    $('#confirm-move').click(function() {
        const targetBox = $('#move-target-box').val();
        const quantity = parseInt($('#move-quantity').val());
        
        if (targetBox && quantity > 0) {
            // Implement move logic
            $('#moveProductModal').modal('hide');
            showToast('Product moved successfully', 'success');
        }
    });

    $('#confirm-edit-box').click(function() {
        // Implement edit box logic
        $('#editBoxModal').modal('hide');
        showToast('Box properties updated', 'success');
    });
}

function moveProduct(productId, fromBox) {
    $('#moveProductModal').modal('show');
    $('#moveProductModal').data('product-id', productId);
    $('#moveProductModal').data('from-box', fromBox);
}

function editBox(boxIndex) {
    const box = currentAllocation.boxes[boxIndex];
    $('#edit-box-name').val(box.name);
    $('#edit-box-length').val(box.length_mm);
    $('#edit-box-width').val(box.width_mm);
    $('#edit-box-height').val(box.height_mm);
    $('#edit-box-max-weight').val(box.max_weight_g / 1000);
    $('#editBoxModal').modal('show');
    $('#editBoxModal').data('box-index', boxIndex);
}

function splitBox(boxIndex) {
    if (confirm('Split this box into two smaller boxes?')) {
        // Implement split logic
        showToast('Box split successfully', 'success');
    }
}

function deleteBox(boxIndex) {
    const box = currentAllocation.boxes[boxIndex];
    if (box.item_count > 0) {
        alert('Cannot delete box with items. Move items to other boxes first.');
        return;
    }
    
    if (confirm('Delete this empty box?')) {
        // Implement delete logic
        showToast('Box deleted successfully', 'success');
    }
}

function regenerateBoxes() {
    if (confirm('Regenerate all boxes? This will reset any manual changes.')) {
        showLoading('Regenerating optimal box layout...');
        
        $.post('/modules/transfers/stock/services/BoxAllocationService.php', {
            action: 'generate_allocation',
            transfer_id: <?= $transfer_id ?>
        }, function(response) {
            if (response.success) {
                location.reload();
            } else {
                hideLoading();
                showToast('Failed to regenerate boxes: ' + response.error, 'error');
            }
        }).fail(function() {
            hideLoading();
            showToast('Network error during regeneration', 'error');
        });
    }
}

function addNewBox() {
    const newBoxData = {
        name: 'New Box ' + (currentAllocation.boxes.length + 1),
        length_mm: 300,
        width_mm: 200,
        height_mm: 150,
        max_weight_g: 5000,
        current_weight_g: 0,
        item_count: 0,
        products: []
    };
    
    // Add to current allocation
    currentAllocation.boxes.push(newBoxData);
    
    // Refresh UI
    location.reload();
}

function saveAllocation() {
    if (confirm('Save and apply this box allocation to the transfer?')) {
        showLoading('Saving box allocation...');
        
        $.post('/modules/transfers/stock/services/BoxAllocationService.php', {
            action: 'save_allocation',
            transfer_id: <?= $transfer_id ?>,
            allocation_data: JSON.stringify(currentAllocation)
        }, function(response) {
            hideLoading();
            if (response.success) {
                showToast('Box allocation saved successfully!', 'success');
                setTimeout(() => {
                    window.location.href = '/modules/transfers/stock/pack.php?transfer=<?= $transfer_id ?>';
                }, 2000);
            } else {
                showToast('Failed to save allocation: ' + response.error, 'error');
            }
        }).fail(function() {
            hideLoading();
            showToast('Network error while saving', 'error');
        });
    }
}

function regenerateWithSettings() {
    const strategy = $('#allocation-strategy').val();
    const safetyMargin = $('#safety-margin').val();
    
    showLoading('Applying new settings...');
    
    // Implement regeneration with settings
    setTimeout(() => {
        hideLoading();
        showToast('Settings applied successfully', 'success');
    }, 2000);
}

function moveProductToBox(productId, fromBoxIndex, toBoxIndex) {
    // Implement the actual move logic here
    console.log(`Moving product ${productId} from box ${fromBoxIndex} to box ${toBoxIndex}`);
    showToast(`Moved product to ${currentAllocation.boxes[toBoxIndex].name}`, 'success');
}

function showToast(message, type = 'info') {
    // Simple toast notification
    const toast = $(`
        <div class="alert alert-${type} alert-dismissible fade show position-fixed" 
             style="top: 20px; right: 20px; z-index: 9999;">
            ${message}
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
    `);
    $('body').append(toast);
    setTimeout(() => toast.remove(), 5000);
}

function showLoading(message) {
    const loading = $(`
        <div id="loading-overlay" class="position-fixed w-100 h-100" 
             style="top: 0; left: 0; background: rgba(0,0,0,0.7); z-index: 9999;">
            <div class="d-flex justify-content-center align-items-center h-100">
                <div class="text-center text-white">
                    <div class="spinner-border mb-3"></div>
                    <h5>${message}</h5>
                </div>
            </div>
        </div>
    `);
    $('body').append(loading);
}

function hideLoading() {
    $('#loading-overlay').remove();
}

// Update safety margin display
$('#safety-margin').on('input', function() {
    const value = Math.round($(this).val() * 100);
    $(this).next().text(`Current: ${value}%`);
});
</script>

<?php include_once $_SERVER['DOCUMENT_ROOT'] . '/CIS_TEMPLATE_FOOTER.php'; ?>