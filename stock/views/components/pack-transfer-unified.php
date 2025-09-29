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
    'last_saved' => ''
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

// NOTE: Auto-save JS/CSS are now loaded centrally via load_transfer_js()/load_transfer_css().
// We deliberately DO NOT include them here to avoid double-including which caused
// "Identifier 'PackAutoSave' has already been declared" errors.
// If this component is rendered in isolation (test harness), ensure those helpers are called.
?>

<!-- Beautiful Blue Header - Outside Card Container -->
<div class="blue-integrated-header" data-transfer-id="<?= (int)$unified_config['transfer_id'] ?>">
  <!-- Top Blue Section -->
  <div class="blue-header-top">
    <div class="header-icon">
      <i class="fa fa-cube"></i>
    </div>
    <div class="header-content">
      <h1 class="header-title">STOCK TRANSFER #<?= (int)$unified_config['transfer_id'] ?> → <?= strtoupper(htmlspecialchars($unified_config['destination_label'] ?? 'HUNTLY', ENT_QUOTES, 'UTF-8')) ?></h1>
      <p class="header-subtitle">Shipping from <?= htmlspecialchars($unified_config['subtitle'] ?? 'Hamilton East', ENT_QUOTES, 'UTF-8') ?></p>
      <div class="header-status">
        <!-- Status pill removed per user request -->
      </div>
    </div>
    <div class="header-actions">
      <?php if (!empty($unified_config['draft_status']['last_saved'])): ?>
        <div class="last-saved-text"><?= htmlspecialchars($unified_config['draft_status']['last_saved'], ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
      <!-- Lightweight autosave status placeholder (no pill) -->
      <div id="autosaveStatus" class="small text-light" style="text-align:right; min-height:16px;"></div>
      <div class="header-action-buttons">
        <?php foreach ($unified_config['actions'] as $action): ?>
          <button type="button" 
                  id="<?= htmlspecialchars($action['id'], ENT_QUOTES, 'UTF-8') ?>"
                  class="header-action-btn <?= strpos($action['class'], 'primary') !== false ? 'primary' : 'secondary' ?>">
            <?php if (isset($action['icon'])): ?>
              <i class="fa <?= htmlspecialchars($action['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
            <?php endif; ?>
            <?= htmlspecialchars($action['label'], ENT_QUOTES, 'UTF-8') ?>
          </button>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  
  <!-- Dispatch Info Bar -->
  <div class="dispatch-bar">
    <div class="dispatch-content">
      <span class="dispatch-to"><strong>DELIVERING TO:</strong> <?= htmlspecialchars($unified_config['destination_label'] ?? 'Huntly', ENT_QUOTES, 'UTF-8') ?> | Huntly | NZ 3700 | 07 8286999 | huntly@vapeshed.co.nz</span>
    </div>
  </div>
  
  <!-- Role Information Section -->
  <div class="role-info-section">
    <div class="role-columns">
      <div class="role-column">
        <div class="role-label">DESTINATION</div>
        <div class="role-value"><?= htmlspecialchars($unified_config['destination_label'] ?? 'Huntly', ENT_QUOTES, 'UTF-8') ?></div>
      </div>
      <div class="role-column">
        <div class="role-label">ORIGIN</div>
        <div class="role-value"><?= htmlspecialchars($unified_config['subtitle'] ?? 'Hamilton East', ENT_QUOTES, 'UTF-8') ?></div>
      </div>
      <div class="role-column">
        <div class="role-label">TRANSFER</div>
        <div class="role-value">#<?= (int)$unified_config['transfer_id'] ?></div>
      </div>
      <div class="role-column">
        <div class="role-label">YOUR ROLE</div>
        <div class="role-value">Warehouse</div>
      </div>
    </div>
    
    <div class="transport-tabs">
      <button class="transport-tab active">Courier</button>
      <button class="transport-tab">Pickup</button>
      <button class="transport-tab">Internal</button>
      <button class="transport-tab">Drop-off</button>
    </div>
  </div>
  

</div>

<!-- Table Card Container -->
<section class="table-card" aria-labelledby="pack-transfer-title">
  
  <!-- DEBUG INFO (Remove this in production) -->
  <?php if (isset($_GET['debug']) && $_GET['debug'] === '1'): ?>
  <div class="alert alert-info">
    <h6>Debug Information:</h6>
    <p><strong>Items count:</strong> <?= count($unified_config['items'] ?? []) ?></p>
    <p><strong>Source stock map:</strong> <?= !empty($unified_config['source_stock_map']) ? 'EXISTS (' . count($unified_config['source_stock_map']) . ' products)' : 'EMPTY OR MISSING' ?></p>
  </div>
  <?php endif; ?>

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
      
      <!-- Seamless Table Integration -->
      <div class="table-container">
        <table class="cohesive-table" id="transferItemsTable">
          <thead>
            <tr class="table-header">
              <th class="col-delete">DELETE</th>
              <th class="col-product">PRODUCT</th>
              <th class="col-stock">QTY IN STOCK</th>
              <th class="col-planned">PLANNED QTY</th>
              <th class="col-counted">COUNTED QTY</th>
            </tr>
          </thead>
          <tbody class="table-body">
            <?php 
            $rowNumber = 1;
            foreach ($unified_config['items'] as $item): 
              $itemId = $item['id'] ?? $rowNumber;
              $plannedQty = $item['qty_requested'] ?? $item['planned_qty'] ?? 0;
              $countedQty = $item['counted_qty'] ?? 0; // Default to 0 instead of empty string
              
              // Get product ID - try multiple possible keys
              $productId = (string)($item['product_id'] ?? $item['vend_product_id'] ?? '');
              
              // Get stock quantity from sourceStockMap
              $stockQty = 0;
              if ($productId !== '' && isset($unified_config['source_stock_map'][$productId])) {
                $stockQty = (int)$unified_config['source_stock_map'][$productId];
              }
              
              // Ensure numeric values
              $plannedQty = is_numeric($plannedQty) ? (int)$plannedQty : 0;
              $countedQty = is_numeric($countedQty) ? (int)$countedQty : 0;
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
                  <?php if ($countedQty > 0): ?>
                    <input type="number" 
                             class="form-control form-control-sm tfx-num qty-input" 
                           name="counted_qty[<?= (int)$itemId ?>]"
                           id="counted-<?= (int)$itemId ?>"
                           min="0" 
                           step="1" 
                           value="<?= $countedQty ?>"
                           data-item-id="<?= (int)$itemId ?>"
                           data-planned="<?= $plannedQty ?>">
                  <?php else: ?>
          <input type="number" 
            class="form-control form-control-sm tfx-num qty-input" 
                           name="counted_qty[<?= (int)$itemId ?>]"
                           id="counted-<?= (int)$itemId ?>"
                           min="0" 
                           step="1" 
                           placeholder="0"
                           data-item-id="<?= (int)$itemId ?>"
                           data-planned="<?= $plannedQty ?>">
                  <?php endif; ?>
                </td>
              </tr>
            <?php 
              $rowNumber++;
            endforeach; 
            ?>
          </tbody>
          
          <!-- Table Footer with Totals -->
          <tfoot>
            <tr class="totals-row">
              <td class="totals-label" colspan="2">Totals:</td>
              <td class="totals-value">—</td>
              <td class="totals-value" id="plannedTotalFooter">—</td>
              <td class="totals-value">
                <span id="countedTotalFooter">—</span>
                <small class="diff-text">Diff: <span id="diffTotalFooter">—</span></small>
              </td>
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
/* Trimmed component-specific styles (removed deprecated status pill styles) */
.tfx-transfer-header { background-color: var(--background-light, #f8f9fa); border-bottom: 1px solid var(--border-light, #dee2e6); }
.tfx-remove-btn { background:none; border:none; color:#dc3545; padding:2px 6px; border-radius:3px; cursor:pointer; transition:background-color .2s; }
.tfx-remove-btn:hover { background:rgba(220,53,69,.1); }
.tfx-num { max-width:80px; text-align:center; }
.counted-td { position:relative; }
/* Fallback row colouring if global stylesheet missing */
tr.qty-match { background: rgba(0, 200, 83, 0.10); }
tr.qty-mismatch { background: rgba(255, 82, 82, 0.12); }
tr.qty-match td, tr.qty-mismatch td { transition: background-color .25s ease; }
@media (max-width:768px){ .transfer-metrics { justify-content:flex-start; margin-bottom:1rem;} }
</style>