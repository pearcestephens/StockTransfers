<?php
/**
 * ============================================================================
 * TRANSFERS PACK SYSTEM - CLEAN VIEW (v2.0)
 * ============================================================================
 * 
 * Clean, lean pack.view.php with all JavaScript moved to external files.
 * Organized, maintainable, and easy to read.
 * 
 * Expected variables (from pack.php):
 * - $DOCUMENT_ROOT, $txId, $transfer, $items
 * - $fromLbl, $toLbl, $fromOutlet, $toOutlet
 * - $fromDisplay, $toDisplay, $isPackaged
 * - $sourceStockMap, $plannedSum, $countedSum, $diff, $diffLabel, $accuracy
 * - $bootPayload, $assetVer, $lockStatus
 */

// Fallback helper for product tag generation (TRANSFERID-LINENUM)
if (!function_exists('tfx_product_tag')) {
  function tfx_product_tag(string $transferId, int $index): string {
    return preg_replace('#[^\d]#', '', $transferId) . '-' . max(1, $index);
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Store Transfer #<?= (int)$txId ?> - Pack System</title>
  
  <!-- External CSS (consolidated) -->
  <link rel="stylesheet" href="/modules/transfers/stock/assets/css/pack-unified.css?v=<?= (int)$assetVer ?>">
  
  <!-- Bootstrap and FontAwesome (if not already included) -->
  <?php if (!isset($skipBootstrap)): ?>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
  <?php endif; ?>
</head>

<body class="app header-fixed sidebar-fixed aside-menu-fixed sidebar-lg-show">
  <main class="app-body">
    <?php include $DOCUMENT_ROOT . '/assets/template/sidemenu.php'; ?>

    <main class="main" id="main"
      data-page="transfer-pack"
      data-txid="<?= (int)$txId ?>"
      data-txstring="<?= htmlspecialchars($txStringId ?? '', ENT_QUOTES) ?>">
      
      <!-- Breadcrumbs -->
      <?php
      $breadcrumb_config = [
        'active_page' => 'Pack',
        'show_transfer_id' => true,
        'transfer_id' => $txId
      ];
      include __DIR__ . '/views/components/breadcrumb.php';
      ?>

      <div class="container-fluid animated fadeIn">
        
        <!-- ================================================================
             STORE TRANSFER HEADER (Clean, Professional)
             ================================================================ -->
        <div class="store-transfer-header">
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <h1>
                <i class="fas fa-exchange-alt"></i>
                Store Transfer #<?= (int)$txId ?>
                <span class="badge ml-2" id="lockStatusBadge">ACTIVE</span>
              </h1>
              <div class="store-transfer-meta">
                <div class="row">
                  <div class="col-md-4">
                    <i class="fas fa-store"></i>
                    <strong>From:</strong> <?= htmlspecialchars($fromLbl, ENT_QUOTES) ?>
                  </div>
                  <div class="col-md-4">
                    <i class="fas fa-map-marker-alt"></i>
                    <strong>To:</strong> <?= htmlspecialchars($toLbl, ENT_QUOTES) ?>
                  </div>
                  <div class="col-md-4">
                    <i class="fas fa-clock"></i>
                    <span id="headerTimer">
                      <?= $lockStatus['has_lock'] ? 'Active Session' : '2h 45m' ?>
                    </span>
                  </div>
                </div>
              </div>
            </div>
            
            <div class="d-flex align-items-center">
              <!-- Diagnostic Button -->
              <button class="btn btn-light btn-sm mr-3" id="lockDiagnosticBtn" title="System Diagnostic">
                <i class="fas fa-cog"></i>
              </button>
              
              <!-- Add Product Button -->
              <button type="button" class="btn btn-light btn-sm" id="headerAddProductBtn" 
                      data-toggle="modal" data-target="#addProdModal">
                <i class="fas fa-plus mr-1"></i>Add Product
              </button>
            </div>
          </div>
        </div>

        <!-- ================================================================
             PACK INTERFACE CONTENT
             ================================================================ -->
        <div class="pack-section">
          <div class="pack-section-header">
            <div class="d-flex justify-content-between align-items-center">
              <h5 class="mb-0">
                <i class="fas fa-boxes mr-2"></i>Transfer Contents
              </h5>
              <div class="text-muted">
                <?= count($items ?? []) ?> items • 
                <?= $plannedSum ?? 0 ?> units planned • 
                <?= $countedSum ?? 0 ?> units counted
              </div>
            </div>
          </div>
          
          <div class="pack-section-body">
            <?php if (!empty($items)): ?>
              <!-- Transfer Items Table -->
              <div class="table-responsive">
                <table class="table table-sm">
                  <thead>
                    <tr>
                      <th width="50">#</th>
                      <th>Product</th>
                      <th width="100">Planned</th>
                      <th width="100">Counted</th>
                      <th width="100">Status</th>
                      <th width="120">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($items as $index => $item): ?>
                    <tr data-product-id="<?= htmlspecialchars($item['product_id'] ?? '', ENT_QUOTES) ?>">
                      <td><?= tfx_product_tag($txId, $index + 1) ?></td>
                      <td>
                        <strong><?= htmlspecialchars($item['product_name'] ?? 'Unknown Product', ENT_QUOTES) ?></strong>
                        <?php if (!empty($item['product_sku'])): ?>
                          <br><small class="text-muted">SKU: <?= htmlspecialchars($item['product_sku'], ENT_QUOTES) ?></small>
                        <?php endif; ?>
                      </td>
                      <td>
                        <span class="badge badge-info"><?= (int)($item['quantity_planned'] ?? 0) ?></span>
                      </td>
                      <td>
                        <input type="number" 
                               class="form-control form-control-sm pack-quantity-input"
                               value="<?= (int)($item['quantity_counted'] ?? 0) ?>"
                               data-product-id="<?= htmlspecialchars($item['product_id'] ?? '', ENT_QUOTES) ?>"
                               min="0">
                      </td>
                      <td>
                        <?php
                        $planned = (int)($item['quantity_planned'] ?? 0);
                        $counted = (int)($item['quantity_counted'] ?? 0);
                        if ($counted === $planned): ?>
                          <span class="badge badge-success">Complete</span>
                        <?php elseif ($counted > $planned): ?>
                          <span class="badge badge-warning">Over</span>
                        <?php elseif ($counted > 0): ?>
                          <span class="badge badge-info">Partial</span>
                        <?php else: ?>
                          <span class="badge badge-secondary">Pending</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <div class="btn-group btn-group-sm">
                          <button class="btn btn-outline-primary pack-item-edit" 
                                  data-product-id="<?= htmlspecialchars($item['product_id'] ?? '', ENT_QUOTES) ?>"
                                  title="Edit">
                            <i class="fas fa-edit"></i>
                          </button>
                          <button class="btn btn-outline-danger pack-item-remove" 
                                  data-product-id="<?= htmlspecialchars($item['product_id'] ?? '', ENT_QUOTES) ?>"
                                  title="Remove">
                            <i class="fas fa-trash"></i>
                          </button>
                        </div>
                      </td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php else: ?>
              <!-- Empty State -->
              <div class="text-center py-5">
                <i class="fas fa-box-open fa-3x text-muted mb-3"></i>
                <h5 class="text-muted">No items in this transfer</h5>
                <p class="text-muted">Add products to get started</p>
                <button class="btn btn-primary" data-toggle="modal" data-target="#addProdModal">
                  <i class="fas fa-plus mr-2"></i>Add First Product
                </button>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- ================================================================
             PACK ACTIONS
             ================================================================ -->
        <div class="pack-section">
          <div class="pack-section-body">
            <div class="row">
              <div class="col-md-6">
                <h6>Pack Actions</h6>
                <div class="btn-group-vertical w-100">
                  <button class="btn btn-success pack-btn mb-2" id="completePackBtn">
                    <i class="fas fa-check mr-2"></i>Complete Pack
                  </button>
                  <button class="btn btn-info pack-btn mb-2" id="generateLabelsBtn">
                    <i class="fas fa-print mr-2"></i>Generate Labels
                  </button>
                  <button class="btn btn-warning pack-btn mb-2" id="saveProgressBtn">
                    <i class="fas fa-save mr-2"></i>Save Progress
                  </button>
                </div>
              </div>
              
              <div class="col-md-6">
                <h6>Transfer Summary</h6>
                <div class="card">
                  <div class="card-body">
                    <div class="row text-center">
                      <div class="col-4">
                        <div class="h4 mb-0 text-info"><?= $plannedSum ?? 0 ?></div>
                        <small class="text-muted">Planned</small>
                      </div>
                      <div class="col-4">
                        <div class="h4 mb-0 text-success"><?= $countedSum ?? 0 ?></div>
                        <small class="text-muted">Counted</small>
                      </div>
                      <div class="col-4">
                        <div class="h4 mb-0 <?= ($accuracy ?? 0) >= 100 ? 'text-success' : 'text-warning' ?>">
                          <?= number_format($accuracy ?? 0, 1) ?>%
                        </div>
                        <small class="text-muted">Accuracy</small>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

      </div>
    </main>
  </main>

  <!-- ====================================================================
       MODALS
       ==================================================================== -->
       
  <!-- Add Product Modal -->
  <div id="addProdModal" class="modal fade" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">
            <i class="fa fa-search mr-2"></i>Add Product to Transfer
          </h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
          <div class="row mb-3">
            <div class="col-md-8">
              <input type="text" id="productSearchInput" class="form-control form-control-lg" 
                     placeholder="Search by product name, SKU, or barcode..." autocomplete="off">
            </div>
            <div class="col-md-4">
              <button type="button" id="clearSearchBtn" class="btn btn-outline-secondary">
                <i class="fa fa-times mr-1"></i>Clear
              </button>
            </div>
          </div>
          <div id="productSearchResults" class="border rounded" style="max-height:400px; overflow-y:auto;">
            <div class="text-center text-muted py-4">
              <i class="fa fa-search fa-2x mb-2"></i>
              <p>Start typing to search for products...</p>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" data-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>

  <!-- ====================================================================
       JAVASCRIPT (All External)
       ==================================================================== -->
       
  <!-- Boot Data -->
  <script>
    window.DISPATCH_BOOT = <?= json_encode($bootPayload ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    window.lockStatus = <?= json_encode($lockStatus ?? [], JSON_UNESCAPED_SLASHES) ?>;
  </script>

  <!-- External JavaScript Modules (Optimized Loading) -->
  <script src="/modules/transfers/stock/assets/js/pack-lock.js?v=<?= (int)$assetVer ?>"></script>
  <script src="/modules/transfers/stock/assets/js/pack-unified.js?v=<?= (int)$assetVer ?>" defer></script>
  
  <?php if (!isset($skipBootstrap)): ?>
  <!-- Bootstrap JS (if not already included) -->
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/js/bootstrap.bundle.min.js"></script>
  <?php endif; ?>

  <!-- Footer includes -->
  <?php include $DOCUMENT_ROOT . '/assets/template/personalisation-menu.php'; ?>
  <?php
    $htmlFooterPath = rtrim($DOCUMENT_ROOT,'/') . '/assets/template/html-footer.php';
    if (is_file($htmlFooterPath)) { include $htmlFooterPath; }
    include $DOCUMENT_ROOT . '/assets/template/footer.php';
  ?>

</body>
</html>