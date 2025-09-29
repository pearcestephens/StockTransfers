<?php
/**
 * Pack Transfer Unified View Component
 * 
 * Combines the transfer header with the items table in a single cohesive view
 * Matches the design shown in the pack transfer interface screenshot
 * 
 * Required variables:
 * - $unified_config - Configuration array containing:
 *   - transfer_id, title, subtitle, description
 *   - metrics (items count, planned total, counted total, diff)
 *   - actions (Save Pack, Autofill buttons)
 *   - items array for the table
 *   - destination_label, source_stock_map
 */

$default_config = [
  'transfer_id' => 0,
  'title' => 'Pack Transfer',
  'subtitle' => '',
  'description' => 'Count, label and finalize this consignment',
  'items' => [],
  'destination_label' => '',
  'source_stock_map' => [],
  'actions' => [
    ['id' => 'savePack', 'label' => 'Save Pack', 'class' => 'btn-primary', 'icon' => 'fa-save'],
    ['id' => 'autofillFromPlanned', 'label' => 'Autofill', 'class' => 'btn-outline-secondary', 'icon' => 'fa-magic']
  ],
  'metrics' => [
    ['label' => 'Items', 'id' => 'itemsToTransfer', 'value' => 0],
    ['label' => 'Planned total', 'id' => 'plannedTotal', 'value' => 0],
    ['label' => 'Counted total', 'id' => 'countedTotal', 'value' => 0],
    ['label' => 'Diff', 'id' => 'diffTotal', 'value' => 0]
  ],
  'draft_status' => [
    'state' => 'idle',
    'text' => 'Idle',
    'last_saved' => 'Last saved: 8:48:43 PM'
  ]
];

$unified_config = array_merge($default_config, $unified_config ?? []);

// Helper function to render product cell
if (!function_exists('tfx_render_product_cell') && function_exists('tfx_render_product_cell')) {
  // Use existing function
} elseif (!function_exists('tfx_render_product_cell')) {
  function tfx_render_product_cell($item) {
    $name = htmlspecialchars($item['product_name'] ?? $item['name'] ?? 'Product', ENT_QUOTES, 'UTF-8');
    $sku = htmlspecialchars($item['product_sku'] ?? $item['sku'] ?? '', ENT_QUOTES, 'UTF-8');
    $skuLine = $sku ? '<div class="tfx-product-sku text-muted small">SKU: ' . $sku . '</div>' : '';
    return '<div class="tfx-product-cell"><strong class="tfx-product-name">' . $name . '</strong>' . $skuLine . '</div>';
  }
}
?>

<section class="card tfx-transfer-unified" aria-labelledby="pack-transfer-title">
  
  <!-- DEBUG INFO (Remove this in production) -->
  <?php if (isset($_GET['debug']) && $_GET['debug'] === '1'): ?>
  <div class="alert alert-info">
    <h6>Debug Information:</h6>
    <p><strong>Items count:</strong> <?= count($unified_config['items'] ?? []) ?></p>
    <p><strong>Source stock map:</strong> <?= !empty($unified_config['source_stock_map']) ? 'EXISTS (' . count($unified_config['source_stock_map']) . ' products)' : 'EMPTY OR MISSING' ?></p>
    <?php if (!empty($unified_config['source_stock_map'])): ?>
      <p><strong>Stock map keys:</strong> <?= implode(', ', array_keys($unified_config['source_stock_map'])) ?></p>
      <p><strong>Stock map values:</strong> <?= implode(', ', array_values($unified_config['source_stock_map'])) ?></p>
    <?php endif; ?>
    <?php if (!empty($unified_config['items'])): ?>
      <p><strong>First item keys:</strong> <?= implode(', ', array_keys($unified_config['items'][0] ?? [])) ?></p>
      <p><strong>First item product_id:</strong> <?= $unified_config['items'][0]['product_id'] ?? 'NOT SET' ?></p>
      <p><strong>First item planned qty field:</strong> qty_requested = <?= $unified_config['items'][0]['qty_requested'] ?? 'NOT SET' ?></p>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  
  <!-- Transfer Header Section -->
  <div class="card-header tfx-transfer-header">
    <div class="row align-items-center">
      <!-- Left: Title and Description -->
      <div class="col-md-6">
        <h1 class="card-title h4 mb-1" id="pack-transfer-title">
          <?= htmlspecialchars($unified_config['title'], ENT_QUOTES, 'UTF-8') ?>
          <?php if ($unified_config['transfer_id']): ?>
            #<?= (int)$unified_config['transfer_id'] ?>
          <?php endif; ?>
        </h1>
        
        <?php if (!empty($unified_config['subtitle'])): ?>
          <h2 class="h6 text-muted mb-1"><?= htmlspecialchars($unified_config['subtitle'], ENT_QUOTES, 'UTF-8') ?></h2>
        <?php endif; ?>
        
        <?php if (!empty($unified_config['description'])): ?>
          <p class="small text-muted mb-2"><?= htmlspecialchars($unified_config['description'], ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <!-- Draft Status -->
        <div class="draft-status-pill status-<?= htmlspecialchars($unified_config['draft_status']['state'], ENT_QUOTES, 'UTF-8') ?>" 
             id="draftStatusPill" data-state="<?= htmlspecialchars($unified_config['draft_status']['state'], ENT_QUOTES, 'UTF-8') ?>">
          <i class="fa fa-circle" aria-hidden="true"></i>
          <span class="pill-text"><?= htmlspecialchars($unified_config['draft_status']['text'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="small text-muted mt-1"><?= htmlspecialchars($unified_config['draft_status']['last_saved'], ENT_QUOTES, 'UTF-8') ?></div>
      </div>

      <!-- Right: Actions and Metrics -->
      <div class="col-md-6 text-md-right">
        <!-- Action Buttons -->
        <div class="btn-group mb-3" role="group" aria-label="Transfer actions">
          <?php foreach ($unified_config['actions'] as $action): ?>
            <button type="button" 
                    id="<?= htmlspecialchars($action['id'], ENT_QUOTES, 'UTF-8') ?>"
                    class="btn <?= htmlspecialchars($action['class'], ENT_QUOTES, 'UTF-8') ?>"
                    <?= isset($action['title']) ? 'title="' . htmlspecialchars($action['title'], ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
              <?php if (isset($action['icon'])): ?>
                <i class="fa <?= htmlspecialchars($action['icon'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
              <?php endif; ?>
              <?= htmlspecialchars($action['label'], ENT_QUOTES, 'UTF-8') ?>
            </button>
          <?php endforeach; ?>
        </div>

        <!-- Metrics Row -->
        <div class="transfer-metrics">
          <?php foreach ($unified_config['metrics'] as $index => $metric): ?>
            <span class="metric-item">
              <strong><?= htmlspecialchars($metric['label'], ENT_QUOTES, 'UTF-8') ?>:</strong>
              <span id="<?= htmlspecialchars($metric['id'] ?? '', ENT_QUOTES, 'UTF-8') ?>" 
                    class="metric-value <?= isset($metric['class']) ? htmlspecialchars($metric['class'], ENT_QUOTES, 'UTF-8') : '' ?>">
                <strong><?= htmlspecialchars($metric['value'] ?? '0', ENT_QUOTES, 'UTF-8') ?></strong>
              </span>
              <?php if ($index === 3): // Diff metric ?>
                <span class="metric-diff-indicator" id="diffIndicator"></span>
              <?php endif; ?>
            </span>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Items Table Section -->
  <div class="card-body p-0">
    <?php if (empty($unified_config['items'])): ?>
      <div class="text-center py-5">
        <div class="text-muted">
          <i class="fa fa-inbox fa-3x mb-3"></i>
          <p>No items on this transfer.</p>
        </div>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0" id="transferItemsTable">
          <thead class="thead-light">
            <tr>
              <th scope="col" class="text-center" style="width: 50px;">#</th>
              <th scope="col">Product</th>
              <th scope="col" class="text-center" style="width: 120px;">Qty in Stock</th>
              <th scope="col" class="text-center" style="width: 120px;">Planned Qty</th>
              <th scope="col" class="text-center" style="width: 120px;">Counted Qty</th>
              <th scope="col" class="text-center" style="width: 100px;">To</th>
              <th scope="col" class="text-center" style="width: 100px;">ID</th>
              <th scope="col" class="text-center" style="width: 50px;"></th>
            </tr>
          </thead>
          <tbody>
            <?php 
            $rowNumber = 1;
            foreach ($unified_config['items'] as $item): 
              $itemId = $item['id'] ?? $rowNumber;
              $plannedQty = $item['qty_requested'] ?? $item['planned_qty'] ?? 0;
              $countedQty = $item['counted_qty'] ?? '';
              
              // Get product ID - try multiple possible keys
              $productId = (string)($item['product_id'] ?? $item['vend_product_id'] ?? '');
              
              // Get stock quantity from sourceStockMap
              $stockQty = 0;
              if ($productId !== '' && isset($unified_config['source_stock_map'][$productId])) {
                $stockQty = (int)$unified_config['source_stock_map'][$productId];
              }
              
              // Ensure numeric values
              $plannedQty = is_numeric($plannedQty) ? (int)$plannedQty : 0;
            ?>
              <tr id="item-row-<?= (int)$itemId ?>" data-item-id="<?= (int)$itemId ?>" data-product-id="<?= htmlspecialchars($productId, ENT_QUOTES) ?>">
                <!-- Row Number -->
                <th scope="row" class="text-center align-middle">
                  <button class="tfx-remove-btn" title="Remove item" data-item-id="<?= (int)$itemId ?>">
                    <i class="fa fa-times" aria-hidden="true"></i>
                  </button>
                </th>
                
                <!-- Product -->
                <td class="align-middle">
                  <?= tfx_render_product_cell($item) ?>
                  <?php if ($productId > 0): ?>
                    <div class="small text-muted">ID: <?= $productId ?></div>
                  <?php endif; ?>
                </td>
                
                <!-- Stock Qty (now first) -->
                <td class="text-center align-middle">
                  <?php if ($stockQty <= 0): ?>
                    <span class="text-danger font-weight-bold">0</span>
                    <div class="small text-danger">
                      <i class="fa fa-exclamation-triangle"></i> Out of stock
                    </div>
                  <?php elseif ($stockQty < $plannedQty): ?>
                    <span class="text-warning font-weight-bold"><?= $stockQty ?></span>
                    <div class="small text-warning">
                      <i class="fa fa-exclamation-triangle"></i> Low stock
                    </div>
                  <?php else: ?>
                    <span class="text-success font-weight-bold"><?= $stockQty ?></span>
                  <?php endif; ?>
                </td>
                
                <!-- Planned Qty (now second) -->
                <td class="text-center align-middle">
                  <span class="font-weight-bold"><?= $plannedQty ?></span>
                </td>
                
                <!-- Counted Qty (Editable) -->
                <td class="text-center align-middle counted-td">
                  <?php if ($countedQty === '' || $countedQty === null): ?>
                    <input type="number" 
                           class="form-control form-control-sm tfx-num" 
                           name="counted_qty[<?= (int)$itemId ?>]"
                           id="counted-<?= (int)$itemId ?>"
                           min="0" 
                           step="1" 
                           placeholder="<?= $plannedQty ?>"
                           data-item-id="<?= (int)$itemId ?>"
                           data-planned="<?= $plannedQty ?>">
                  <?php else: ?>
                    <input type="number" 
                           class="form-control form-control-sm tfx-num" 
                           name="counted_qty[<?= (int)$itemId ?>]"
                           id="counted-<?= (int)$itemId ?>"
                           min="0" 
                           step="1" 
                           value="<?= (int)$countedQty ?>"
                           data-item-id="<?= (int)$itemId ?>"
                           data-planned="<?= $plannedQty ?>">
                    <div class="counted-print-value"><?= (int)$countedQty ?></div>
                  <?php endif; ?>
                </td>
                
                <!-- Destination -->
                <td class="text-center align-middle small text-muted">
                  <?= htmlspecialchars($unified_config['destination_label'], ENT_QUOTES, 'UTF-8') ?>
                </td>
                
                <!-- ID -->
                <td class="text-center align-middle small text-muted">
                  <?= htmlspecialchars($unified_config['transfer_id'], ENT_QUOTES, 'UTF-8') ?>-<?= $rowNumber ?>
                </td>
                
                <!-- Actions -->
                <td class="text-center align-middle">
                  <div class="btn-group-vertical btn-group-sm" role="group">
                    <button type="button" class="btn btn-outline-secondary btn-sm" 
                            title="Will remove at submit" 
                            data-item-id="<?= (int)$itemId ?>">
                      <i class="fa fa-info" aria-hidden="true"></i>
                    </button>
                  </div>
                </td>
              </tr>
            <?php 
              $rowNumber++;
            endforeach; 
            ?>
          </tbody>
          
          <!-- Table Footer with Totals -->
          <tfoot class="thead-light">
            <tr>
              <th colspan="2" class="text-right">Totals:</th>
              <th class="text-center">—</th>
              <th class="text-center" id="plannedTotalFooter">—</th>
              <th class="text-center" id="countedTotalFooter">—</th>
              <th colspan="3" class="text-center">
                <small class="text-muted">Diff: <span id="diffTotalFooter">—</span></small>
              </th>
            </tr>
          </tfoot>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>

<!-- Auto-calculation JavaScript for table totals -->
<script>
document.addEventListener('DOMContentLoaded', function() {
  // Calculate and update totals
  function updateTotals() {
    let plannedTotal = 0;
    let countedTotal = 0;
    
    // Sum up planned quantities
    document.querySelectorAll('[data-planned]').forEach(function(input) {
      plannedTotal += parseInt(input.dataset.planned) || 0;
    });
    
    // Sum up counted quantities
    document.querySelectorAll('input[name^="counted_qty"]').forEach(function(input) {
      countedTotal += parseInt(input.value) || 0;
    });
    
    const diff = countedTotal - plannedTotal;
    
    // Update header metrics
    const itemsTotal = document.getElementById('itemsToTransfer');
    const plannedTotalEl = document.getElementById('plannedTotal');
    const countedTotalEl = document.getElementById('countedTotal');
    const diffTotalEl = document.getElementById('diffTotal');
    
    if (itemsTotal) itemsTotal.textContent = <?= count($unified_config['items']) ?>;
    if (plannedTotalEl) plannedTotalEl.textContent = plannedTotal;
    if (countedTotalEl) countedTotalEl.textContent = countedTotal;
    if (diffTotalEl) {
      diffTotalEl.textContent = (diff >= 0 ? '+' : '') + diff;
      diffTotalEl.className = 'metric-value ' + (diff === 0 ? 'text-success' : diff > 0 ? 'text-info' : 'text-warning');
    }
    
    // Update footer totals
    const plannedFooter = document.getElementById('plannedTotalFooter');
    const countedFooter = document.getElementById('countedTotalFooter');
    const diffFooter = document.getElementById('diffTotalFooter');
    
    if (plannedFooter) plannedFooter.textContent = plannedTotal;
    if (countedFooter) countedFooter.textContent = countedTotal;
    if (diffFooter) {
      diffFooter.textContent = (diff >= 0 ? '+' : '') + diff;
      diffFooter.className = diff === 0 ? 'text-success' : diff > 0 ? 'text-info' : 'text-warning';
    }
  }
  
  // Attach event listeners to quantity inputs
  document.querySelectorAll('input[name^="counted_qty"]').forEach(function(input) {
    input.addEventListener('input', updateTotals);
    input.addEventListener('change', updateTotals);
  });
  
  // Initial calculation
  updateTotals();
});
</script>

<style>
/* Component-specific styles */
.tfx-transfer-header {
  background-color: var(--background-light, #f8f9fa);
  border-bottom: 1px solid var(--border-light, #dee2e6);
}

.transfer-metrics {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
  justify-content: flex-end;
}

.metric-item {
  white-space: nowrap;
  font-size: 0.9rem;
}

.metric-value {
  font-weight: 600;
}

.draft-status-pill {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.25rem 0.75rem;
  border-radius: 12px;
  font-size: 0.75rem;
  font-weight: 500;
  background-color: #f8f9fa;
  color: #6c757d;
  border: 1px solid #dee2e6;
}

.draft-status-pill.status-idle { background-color: #f8f9fa; color: #6c757d; }
.draft-status-pill.status-saving { background-color: #fff3cd; color: #856404; }
.draft-status-pill.status-saved { background-color: #d4edda; color: #155724; }
.draft-status-pill.status-error { background-color: #f8d7da; color: #721c24; }

.tfx-remove-btn {
  background: none;
  border: none;
  color: #dc3545;
  padding: 2px 6px;
  border-radius: 3px;
  cursor: pointer;
  transition: background-color 0.2s;
}

.tfx-remove-btn:hover {
  background: rgba(220, 53, 69, 0.1);
}

.tfx-num {
  max-width: 80px;
  text-align: center;
}

.counted-td {
  position: relative;
}

.counted-print-value {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  font-weight: bold;
  color: #28a745;
  pointer-events: none;
}

@media (max-width: 768px) {
  .transfer-metrics {
    justify-content: flex-start;
    margin-bottom: 1rem;
  }
  
  .btn-group {
    flex-direction: column;
    width: 100%;
  }
}
</style>