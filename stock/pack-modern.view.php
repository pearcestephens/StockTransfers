<?php
/**
 * CIS — Transfers » Stock » Pack (Modern View)
 * =========================================================================
 * 
 * Clean, feature-rich pack interface with:
 * - Product search & multi-select
 * - Draft autosave
 * - Shipping integration
 * - Real-time validation
 * - Box labels
 * 
 * Expected variables from controller:
 * - $DOCUMENT_ROOT, $txId, $txStringId
 * - $transfer, $items, $fromOutlet, $toOutlet
 * - $fromLbl, $toLbl, $fromDisplay, $toDisplay
 * - $isPackaged, $PACKONLY
 * - $sourceStockMap, $metrics, $mergeTransfers
 * - $bootPayload, $assetVer, $lockStatus
 * - $currentUserId, $currentUserName
 */

// Fallback helper for product tags
if (!function_exists('tfx_product_tag')) {
    function tfx_product_tag($txId, $lineNum) {
        return htmlspecialchars("{$txId}-{$lineNum}", ENT_QUOTES, 'UTF-8');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pack Transfer #<?= htmlspecialchars($txId ?? '', ENT_QUOTES) ?> - Modern Interface</title>
    
    <!-- External CSS -->
    <link rel="stylesheet" href="/modules/transfers/stock/assets/css/pack-modern.css?v=<?= (int)$assetVer ?>">
    
    <!-- Bootstrap & FontAwesome -->
    <?php if (!isset($skipBootstrap)): ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <?php endif; ?>
</head>

<body class="app header-fixed sidebar-fixed aside-menu-fixed sidebar-lg-show">
  <main class="app-body">
    <?php include $DOCUMENT_ROOT . '/assets/template/sidemenu.php'; ?>

    <main class="main" id="main" data-page="transfer-pack-modern" data-txid="<?= (int)$txId ?>" data-txstring="<?= htmlspecialchars($txStringId ?? '', ENT_QUOTES) ?>">
      
      <!-- Breadcrumbs -->
      <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="/index.php">Home</a></li>
        <li class="breadcrumb-item"><a href="/modules/transfers/stock/list.php">Transfers</a></li>
        <li class="breadcrumb-item active">Pack #<?= (int)$txId ?></li>
      </ol>

      <div class="container-fluid animated fadeIn">
        
        <?php if ($PACKONLY): ?>
        <!-- Pack-Only Warning Banner -->
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
          <h5 class="alert-heading"><i class="fas fa-exclamation-triangle mr-2"></i>Pack-Only Mode</h5>
          <p class="mb-0">This transfer has already been sent. You can view details but cannot make changes.</p>
          <button type="button" class="close" data-dismiss="alert" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <?php endif; ?>

        <!-- Store Transfer Header -->
        <div class="card mb-3 shadow-sm">
          <div class="card-header bg-gradient-primary text-white">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <h4 class="mb-0">
                  <i class="fas fa-exchange-alt mr-2"></i>
                  Store Transfer #<?= (int)$txId ?>
                  <span class="badge badge-light ml-2"><?= $isPackaged ? 'SENT' : 'ACTIVE' ?></span>
                </h4>
                <small class="text-light">
                  Created by <?= htmlspecialchars($createdByName ?? 'Unknown', ENT_QUOTES) ?>
                  <?php if (!empty($transfer['created_at'])): ?>
                    on <?= date('M j, Y g:i A', strtotime($transfer['created_at'])) ?>
                  <?php endif; ?>
                </small>
              </div>
              <div class="d-flex align-items-center">
                <button class="btn btn-light btn-sm mr-2" id="btn-print-labels" title="Print Box Labels">
                  <i class="fas fa-print"></i> Labels
                </button>
                <button class="btn btn-light btn-sm" id="btn-add-product" title="Add Product" <?= $PACKONLY ? 'disabled' : '' ?>>
                  <i class="fas fa-plus mr-1"></i>Add Product
                </button>
              </div>
            </div>
          </div>
          
          <div class="card-body">
            <div class="row">
              <!-- From Outlet -->
              <div class="col-md-6">
                <div class="media">
                  <div class="media-icon mr-3">
                    <i class="fas fa-store fa-2x text-primary"></i>
                  </div>
                  <div class="media-body">
                    <h6 class="mb-1">From</h6>
                    <p class="h5 mb-0"><?= htmlspecialchars($fromDisplay, ENT_QUOTES) ?></p>
                    <?php if (!empty($fromOutlet['physical_address_1'])): ?>
                      <small class="text-muted">
                        <i class="fas fa-map-marker-alt mr-1"></i>
                        <?= htmlspecialchars($fromOutlet['physical_address_1'], ENT_QUOTES) ?>
                      </small>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
              
              <!-- To Outlet -->
              <div class="col-md-6">
                <div class="media">
                  <div class="media-icon mr-3">
                    <i class="fas fa-map-marker-alt fa-2x text-success"></i>
                  </div>
                  <div class="media-body">
                    <h6 class="mb-1">To</h6>
                    <p class="h5 mb-0"><?= htmlspecialchars($toDisplay, ENT_QUOTES) ?></p>
                    <?php if (!empty($toOutlet['physical_address_1'])): ?>
                      <small class="text-muted">
                        <i class="fas fa-map-marker-alt mr-1"></i>
                        <?= htmlspecialchars($toOutlet['physical_address_1'], ENT_QUOTES) ?>
                      </small>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Draft Status Bar -->
        <div class="card mb-3">
          <div class="card-body py-2">
            <div class="d-flex justify-content-between align-items-center">
              <div class="d-flex align-items-center">
                <span id="draft-status" class="badge badge-secondary mr-2">Draft: Off</span>
                <small id="draft-last-saved" class="text-muted">Not saved</small>
                <div class="custom-control custom-switch ml-3">
                  <input type="checkbox" class="custom-control-input" id="toggle-autosave">
                  <label class="custom-control-label" for="toggle-autosave">Auto-save (30s)</label>
                </div>
              </div>
              <div>
                <button type="button" id="btn-save-draft" class="btn btn-sm btn-outline-primary">
                  <i class="fa fa-save mr-1"></i>Save Draft
                </button>
                <button type="button" id="btn-restore-draft" class="btn btn-sm btn-outline-secondary" disabled>
                  <i class="fa fa-undo mr-1"></i>Restore
                </button>
                <button type="button" id="btn-discard-draft" class="btn btn-sm btn-outline-danger" disabled>
                  <i class="fa fa-trash mr-1"></i>Discard
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Transfer Items Table -->
        <div class="card mb-4">
          <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
              <i class="fas fa-boxes mr-2"></i>Transfer Contents
            </h5>
            <div>
              <button type="button" id="btn-autofill" class="btn btn-outline-primary btn-sm mr-2" <?= $PACKONLY ? 'disabled' : '' ?>>
                <i class="fa fa-magic mr-1"></i>Auto-fill
              </button>
              <button type="button" id="btn-prune-zeros" class="btn btn-outline-secondary btn-sm" <?= $PACKONLY ? 'disabled' : '' ?>>
                <i class="fa fa-filter mr-1"></i>Remove Zeros
              </button>
            </div>
          </div>
          
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-sm table-hover mb-0" id="transfer-table">
                <thead class="thead-light">
                  <tr>
                    <th width="40"></th>
                    <th>Product</th>
                    <th width="80" class="text-center">Stock</th>
                    <th width="80" class="text-center">Planned</th>
                    <th width="100" class="text-center">Counted</th>
                    <th width="100" class="text-center">Tag</th>
                    <th width="60" class="text-center">Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($items)): ?>
                    <tr>
                      <td colspan="7" class="text-center text-muted py-5">
                        <i class="fas fa-box-open fa-3x mb-3"></i>
                        <p>No items in this transfer yet.</p>
                        <button class="btn btn-primary" id="btn-add-first-product">
                          <i class="fas fa-plus mr-2"></i>Add First Product
                        </button>
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($items as $idx => $item): 
                      $productId = $item['product_id'] ?? 0;
                      $productName = $item['product_name'] ?? 'Unknown Product';
                      $sku = $item['sku'] ?? '';
                      $sourceStock = (int)($item['source_stock'] ?? 0);
                      $planned = (int)(_first($item['qty_requested'] ?? null, $item['planned_qty'] ?? 0) ?: 0);
                      $counted = (int)($item['counted_qty'] ?? 0);
                      $lineNum = $idx + 1;
                    ?>
                    <tr data-product-id="<?= (int)$productId ?>" 
                        data-inventory="<?= $sourceStock ?>" 
                        data-planned="<?= $planned ?>">
                      <td class="text-center align-middle">
                        <input type="hidden" class="productID" value="<?= (int)$productId ?>">
                        <i class="fas fa-grip-vertical text-muted"></i>
                      </td>
                      <td>
                        <strong><?= htmlspecialchars($productName, ENT_QUOTES) ?></strong>
                        <?php if ($sku): ?>
                          <br><small class="text-muted">SKU: <?= htmlspecialchars($sku, ENT_QUOTES) ?></small>
                        <?php endif; ?>
                      </td>
                      <td class="text-center inv"><?= $sourceStock ?></td>
                      <td class="text-center planned"><?= $planned ?></td>
                      <td class="counted-td text-center">
                        <input type="number" class="form-control form-control-sm text-center pack-qty-input" 
                               value="<?= $counted ?>" min="0" max="<?= $sourceStock ?>"
                               oninput="syncPrintValue(this); checkInvalidQty(this); recomputeTotals();"
                               <?= $PACKONLY ? 'disabled' : '' ?>>
                        <span class="counted-print-value d-none"><?= $counted ?></span>
                      </td>
                      <td class="text-center">
                        <span class="badge badge-secondary id-counter"><?= tfx_product_tag($txId, $lineNum) ?></span>
                      </td>
                      <td class="text-center">
                        <button type="button" class="btn btn-sm btn-outline-danger" 
                                onclick="removeProduct(this)" 
                                title="Remove"
                                <?= $PACKONLY ? 'disabled' : '' ?>>
                          <i class="fas fa-trash"></i>
                        </button>
                      </td>
                    </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
                <tfoot class="bg-light">
                  <tr>
                    <th colspan="3" class="text-right">Totals:</th>
                    <th class="text-center">
                      <span id="plannedTotal"><?= $metrics['planned'] ?></span>
                    </th>
                    <th class="text-center">
                      <span id="countedTotal"><?= $metrics['counted'] ?></span>
                    </th>
                    <th colspan="2" class="text-center">
                      Diff: <span id="diffTotal" style="font-weight:bold;"><?= $metrics['diff_label'] ?></span>
                    </th>
                  </tr>
                </tfoot>
              </table>
            </div>
          </div>
        </div>

        <!-- Shipping & Tracking Section -->
        <div class="card mb-4">
          <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-shipping-fast mr-2"></i>Shipping & Tracking</h5>
          </div>
          <div class="card-body">
            <ul class="nav nav-tabs mb-3" id="shipping-tabs" role="tablist">
              <li class="nav-item">
                <a class="nav-link active" id="manual-tab" data-toggle="tab" href="#manual-tracking" role="tab">
                  <i class="fas fa-edit mr-1"></i>Manual Tracking
                </a>
              </li>
              <li class="nav-item">
                <a class="nav-link" id="labels-tab" data-toggle="tab" href="#box-labels" role="tab">
                  <i class="fas fa-tag mr-1"></i>Box Labels
                </a>
              </li>
            </ul>
            
            <div class="tab-content">
              <!-- Manual Tracking -->
              <div class="tab-pane fade show active" id="manual-tracking" role="tabpanel">
                <div class="form-group">
                  <label>Tracking Numbers <span id="tracking-count" class="text-muted">(0 numbers)</span></label>
                  <div id="tracking-items"></div>
                  <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="btn-add-tracking" <?= $PACKONLY ? 'disabled' : '' ?>>
                    <i class="fa fa-plus mr-1"></i>Add Tracking Number
                  </button>
                </div>
                <div class="form-group">
                  <label for="notesForTransfer">Notes</label>
                  <textarea class="form-control" id="notesForTransfer" rows="3" 
                            placeholder="Add any notes about this shipment..." 
                            <?= $PACKONLY ? 'disabled' : '' ?>><?= htmlspecialchars($transfer['notes'] ?? '', ENT_QUOTES) ?></textarea>
                </div>
              </div>
              
              <!-- Box Labels -->
              <div class="tab-pane fade" id="box-labels" role="tabpanel">
                <div class="form-group">
                  <label for="box-count-input">Number of Boxes</label>
                  <input type="number" class="form-control" id="box-count-input" value="1" min="1" max="20">
                </div>
                <button type="button" class="btn btn-primary" onclick="openLabelPrintDialog()">
                  <i class="fa fa-print mr-2"></i>Generate & Print Labels
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Action Buttons -->
        <div class="card">
          <div class="card-body">
            <div class="row">
              <div class="col-md-8">
                <h5>Transfer Summary</h5>
                <div class="row text-center">
                  <div class="col-4">
                    <div class="h3 mb-0 text-info"><span id="itemsToTransfer"><?= count($items) ?></span></div>
                    <small class="text-muted">Items</small>
                  </div>
                  <div class="col-4">
                    <div class="h3 mb-0 text-success"><span id="summary-counted"><?= $metrics['counted'] ?></span></div>
                    <small class="text-muted">Units Counted</small>
                  </div>
                  <div class="col-4">
                    <div class="h3 mb-0 <?= $metrics['accuracy'] >= 100 ? 'text-success' : 'text-warning' ?>">
                      <span id="summary-accuracy"><?= $metrics['accuracy'] ?></span>%
                    </div>
                    <small class="text-muted">Accuracy</small>
                  </div>
                </div>
              </div>
              <div class="col-md-4 text-right">
                <?php if (!$PACKONLY): ?>
                  <button type="button" class="btn btn-lg btn-success" id="createTransferButton" onclick="markReadyForDelivery()">
                    <i class="fas fa-check mr-2"></i>Mark Ready for Delivery
                  </button>
                <?php else: ?>
                  <button type="button" class="btn btn-lg btn-secondary" disabled>
                    <i class="fas fa-check mr-2"></i>Already Sent
                  </button>
                <?php endif; ?>
                
                <button type="button" class="btn btn-lg btn-outline-danger mt-2" onclick="deleteTransfer(<?= (int)$txId ?>)">
                  <i class="fas fa-trash mr-2"></i>Delete Transfer
                </button>
              </div>
            </div>
          </div>
        </div>

      </div><!-- /container-fluid -->
    </main>
  </main>

  <!-- Add Product Modal -->
  <div class="modal fade" id="addProductsModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-xl" role="document">
      <div class="modal-content">
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title"><i class="fa fa-search mr-2"></i>Add Products to Transfer</h5>
          <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="modal-body">
          <div class="row mb-3">
            <div class="col-md-10">
              <input type="text" id="search-input" class="form-control form-control-lg" 
                     placeholder="Search by product name, SKU, or barcode..." autocomplete="off">
            </div>
            <div class="col-md-2">
              <button type="button" id="btn-clear-search" class="btn btn-outline-secondary btn-block">
                <i class="fa fa-times mr-1"></i>Clear
              </button>
            </div>
          </div>
          
          <div id="search-results-container" class="border rounded" style="max-height:500px; overflow-y:auto;">
            <div id="search-status" class="text-center py-5 text-muted">
              <i class="fa fa-search fa-3x mb-3"></i>
              <p>Type at least 2 characters to search...</p>
            </div>
            <table class="table table-sm mb-0 d-none" id="productAddSearch">
              <thead class="thead-light">
                <tr>
                  <th width="30">
                    <input type="checkbox" id="selectAllProducts" title="Select All">
                  </th>
                  <th>Product</th>
                  <th width="100" class="text-center">Stock</th>
                  <th width="120" class="text-center">Action</th>
                </tr>
              </thead>
              <tbody id="productAddSearchBody"></tbody>
            </table>
          </div>
          
          <!-- Bulk Actions -->
          <div id="bulk-controls" class="mt-3 d-none">
            <span class="mr-2">Selected: <strong id="selected-count">0</strong></span>
            <button type="button" class="btn btn-primary btn-sm" id="add-to-current-btn">
              <i class="fa fa-plus mr-1"></i>Add to Current Transfer
            </button>
          </div>
        </div>
        <div class="modal-footer">
          <span id="results-count" class="mr-auto text-muted">0 results</span>
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Hidden form fields -->
  <input type="hidden" id="transferID" value="<?= (int)$txId ?>">
  <input type="hidden" id="staffID" value="<?= (int)$currentUserId ?>">
  <input type="hidden" id="sourceID" value="<?= (int)$fromOutletId ?>">
  <input type="hidden" id="destinationID" value="<?= (int)$toOutletId ?>">

  <!-- Boot Data -->
  <script>
    window.DISPATCH_BOOT = <?= json_encode($bootPayload ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    window.PACK_ONLY = <?= json_encode($PACKONLY) ?>;
  </script>

  <!-- External JavaScript -->
  <?php if (!isset($skipBootstrap)): ?>
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/js/bootstrap.bundle.min.js"></script>
  <?php endif; ?>
  
  <script src="/modules/transfers/stock/assets/js/pack-modern.js?v=<?= (int)$assetVer ?>" defer></script>

  <!-- Footer includes -->
  <?php include $DOCUMENT_ROOT . '/assets/template/personalisation-menu.php'; ?>
  <?php
    $htmlFooterPath = rtrim($DOCUMENT_ROOT,'/') . '/assets/template/html-footer.php';
    if (is_file($htmlFooterPath)) { include $htmlFooterPath; }
    include $DOCUMENT_ROOT . '/assets/template/footer.php';
  ?>

</body>
</html>
