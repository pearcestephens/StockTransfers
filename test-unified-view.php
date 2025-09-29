<?php
/**
 * Pack Transfer Unified View - Test Page
 * 
 * Tests the new unified header + table component
 * Access via: /modules/transfers/test-unified-view.php
 */

// Include required dependencies
require_once __DIR__ . '/_shared/Autoload.php';
require_once __DIR__ . '/_shared/Support/AssetHelpers.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pack Transfer #13205 - Unified View Test</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <?= load_transfer_css(); ?>
</head>
<body class="bg-light" data-txid="13205">

<!-- Initial draft data for JavaScript -->
<script type="application/json" id="initialDraftData">
{"counted_qty":{},"added_products":[],"removed_items":[],"courier_settings":[],"notes":"","saved_at":""}
</script data-txid="13205">

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <!-- Unified Pack Transfer Component -->
            <?php
            $unified_config = [
              'transfer_id' => 13205,
              'title' => 'Pack Transfer',
              'subtitle' => 'Hamilton East → Huntly', 
              'description' => 'Count, label and finalize this consignment',
              'items' => $mockItems,
              'destination_label' => 'Huntly',
              'source_stock_map' => $mockSourceStockMap,
              'actions' => [
                [
                  'id' => 'savePack',
                  'label' => 'Save Pack',
                  'class' => 'btn-primary',
                  'icon' => 'fa-save'
                ],
                [
                  'id' => 'autofillFromPlanned', 
                  'label' => 'Autofill',
                  'class' => 'btn-outline-secondary',
                  'icon' => 'fa-magic',
                  'title' => 'Counted = Planned'
                ]
              ],
              'metrics' => [
                ['label' => 'Items', 'id' => 'itemsToTransfer', 'value' => 115],
                ['label' => 'Planned total', 'id' => 'plannedTotal', 'value' => 753],
                ['label' => 'Counted total', 'id' => 'countedTotal', 'value' => 746],
                ['label' => 'Diff', 'id' => 'diffTotal', 'value' => -7, 'class' => 'text-warning']
              ],
              'draft_status' => [
                'state' => 'idle',
                'text' => 'IDLE', 
                'last_saved' => 'Last saved: 8:48:43 PM'
              ]
            ];
            
            include __DIR__ . '/stock/views/components/pack-transfer-unified.php';
            ?>
        </div>
    </div>

    <!-- Additional Info Row -->
    <div class="row mt-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5>🎯 Unified View Features</h5>
                </div>
                <div class="card-body">
                    <ul class="list-unstyled mb-0">
                        <li>✅ Header and table combined into one cohesive component</li>
                        <li>✅ Real-time total calculations as you enter counts</li>
                        <li>✅ Visual status indicators for stock levels</li>
                        <li>✅ Draft status with live updates</li>
                        <li>✅ Responsive design for mobile/tablet</li>
                        <li>✅ Matches the original pack transfer design</li>
                    </ul>
                </div>
            </div>
        </div>
        
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5>🧪 Test Actions</h5>
                </div>
                <div class="card-body">
                    <button class="btn btn-sm btn-outline-primary mb-2" onclick="testAutofill()">
                        <i class="fa fa-magic"></i> Test Autofill
                    </button>
                    <button class="btn btn-sm btn-outline-info mb-2" onclick="testStatusUpdate()">
                        <i class="fa fa-sync"></i> Update Status to Saved
                    </button>
                    <button class="btn btn-sm btn-outline-warning mb-2" onclick="clearAllCounts()">
                        <i class="fa fa-eraser"></i> Clear All Counts
                    </button>
                    <div class="mt-2">
                        <small class="text-muted">Enter quantities in the table above to see real-time calculations.</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<?= load_transfer_js(); ?>

<script>
// Test functions
function testAutofill() {
  // Fill all empty counted quantities with planned quantities
  document.querySelectorAll('input[name^="counted_qty"]').forEach(function(input) {
    if (!input.value) {
      input.value = input.dataset.planned;
      input.dispatchEvent(new Event('input'));
    }
  });
  
  if (typeof TransfersUtils !== 'undefined') {
    TransfersUtils.showToast('Autofilled all empty quantities with planned amounts', 'success');
  }
}

function testStatusUpdate() {
  const statusPill = document.getElementById('draftStatusPill');
  const statusText = statusPill.querySelector('.pill-text');
  
  if (statusPill && statusText) {
    statusPill.className = 'draft-status-pill status-saved';
    statusText.textContent = 'SAVED';
    
    if (typeof TransfersUtils !== 'undefined') {
      TransfersUtils.showToast('Draft status updated to saved', 'info');
    }
  }
}

function clearAllCounts() {
  document.querySelectorAll('input[name^="counted_qty"]').forEach(function(input) {
    input.value = '';
    input.dispatchEvent(new Event('input'));
  });
  
  if (typeof TransfersUtils !== 'undefined') {
    TransfersUtils.showToast('All counted quantities cleared', 'warning');
  }
}

// Enhanced button functionality
document.addEventListener('DOMContentLoaded', function() {
  // Save Pack button
  document.getElementById('savePack')?.addEventListener('click', function() {
    const countedInputs = document.querySelectorAll('input[name^="counted_qty"]');
    let hasValues = false;
    
    countedInputs.forEach(function(input) {
      if (input.value) hasValues = true;
    });
    
    if (hasValues) {
      if (typeof TransfersUtils !== 'undefined') {
        TransfersUtils.showToast('Pack data saved successfully!', 'success', 3000);
      }
      testStatusUpdate(); // Update to saved status
    } else {
      if (typeof TransfersUtils !== 'undefined') {
        TransfersUtils.showToast('Please enter some counted quantities before saving', 'warning');
      }
    }
  });
  
  // Autofill button  
  document.getElementById('autofillFromPlanned')?.addEventListener('click', testAutofill);
  
  console.log('[Unified View Test] Page loaded successfully');
  
  // Debug auto-save system
  console.log('Checking auto-save system...');
  console.log('Transfer ID from body:', document.body.dataset.txid);
  console.log('PackAutoSave instance:', window.packAutoSave);
});
</script>

<!-- Load Transfer JavaScript Assets -->
<?= load_transfer_js(); ?>

</body>
</html>