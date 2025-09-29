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

// Mock data based on the screenshot
$mockItems = [
  [
    'id' => 1,
    'product_id' => 12345,
    'product_name' => 'Brutal - Raspberry Sour 120ml - 3mg',
    'product_sku' => '5056598132987',
    'planned_qty' => 7,
    'counted_qty' => null, // Empty for testing
  ],
  [
    'id' => 2, 
    'product_id' => 12346,
    'product_name' => 'Brutal - Sweet Licorice 120ml - 3mg',
    'product_sku' => '5056598132970',
    'planned_qty' => 4,
    'counted_qty' => 4,
  ],
  [
    'id' => 3,
    'product_id' => 12347, 
    'product_name' => 'Disposavape L-Pod 0.8ohm Cartridge - 2 Pack',
    'product_sku' => '697590702507',
    'planned_qty' => 10,
    'counted_qty' => 10,
  ],
  [
    'id' => 4,
    'product_id' => 12348,
    'product_name' => 'DISPOSAVAPE B-BOXX (Refill Cartridge) - Green - 25mg',
    'product_sku' => '1234567890123',
    'planned_qty' => 20,
    'counted_qty' => null,
  ]
];

$mockSourceStockMap = [
  12345 => 15,  // Raspberry Sour has 15 in stock
  12346 => 8,   // Sweet Licorice has 8 in stock 
  12347 => 0,   // L-Pod out of stock
  12348 => 25,  // B-BOXX has 25 in stock
];

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
<body class="bg-light">

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
});
</script>

</body>
</html>