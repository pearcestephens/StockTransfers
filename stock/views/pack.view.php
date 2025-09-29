<?php
/**
 * Pack Transfer View
 * 
 * Main view template for the pack transfer page using modular components
 * 
 * This replaces the monolithic pack.php with reusable view components
 * All business logic should be handled before including this view
 * 
 * Expected variables:
 * - $transfer - Transfer data array
 * - $items - Transfer items array  
 * - $txId - Transfer ID
 * - $fromLbl, $toLbl - Outlet labels
 * - $isPackaged - Package status
 * - $sourceStockMap - Stock levels
 * - $bootPayload - JS boot configuration
 * - All helper functions (tfx_render_product_cell, etc.)
 */

// Ensure required variables are set
$txId = $txId ?? ($transfer['id'] ?? 0);
$items = $items ?? [];
$fromLbl = $fromLbl ?? 'Unknown';
$toLbl = $toLbl ?? 'Unknown';
$isPackaged = $isPackaged ?? false;
$sourceStockMap = $sourceStockMap ?? [];
?>

<body class="app header-fixed sidebar-fixed aside-menu-fixed sidebar-lg-show" data-page="transfer-pack" data-txid="<?= (int)$txId ?>">
  <div class="app-body">
    <?php include $DOCUMENT_ROOT . '/assets/template/sidemenu.php'; ?>
    <main class="main" id="main">
      
      <?php
      // Breadcrumb configuration
      $breadcrumb_config = [
        'active_page' => 'Pack',
        'show_transfer_id' => true,
        'transfer_id' => $txId
      ];
      include __DIR__ . '/components/breadcrumb.php';
      ?>

      <div class="container-fluid animated fadeIn">
        <div class="pack-white-container">
  <!-- Externalised assets -->
  <?php
    require_once __DIR__ . '/../_shared/asset_version.php';
    $assetVer = transfer_asset_version();
  ?>
  <link rel="preload" href="/modules/transfers/stock/assets/css/pack-extra.css?v=<?= htmlspecialchars($assetVer,ENT_QUOTES) ?>" as="style" />
  <link rel="stylesheet" href="/modules/transfers/stock/assets/css/pack-extra.css?v=<?= htmlspecialchars($assetVer,ENT_QUOTES) ?>" />
        <?php if ($isPackaged): ?>
          <?php
          // Status alert for packaged transfers
          $alert_config = [
            'type' => 'warning',
            'icon' => 'fa-exclamation-triangle',
            'title' => 'Heads up: this transfer is in PACKAGED mode',
            'message' => '"Mark as Packed" already ran. You can still make last-minute edits, but dispatch isn\'t locked until you send it.',
            'details' => [
              'Adjusting counts, parcels, or notes will update the existing packed shipment record.',
              'No data has been pushed to Lightspeed/Vend yet; that only happens when you mark it as sent.',
              'Accidental sends can\'t be undone here—grab Ops if you need a rollback before dispatch.'
            ],
            'footer_message' => 'Ready to hand over? Use "Mark as Packed & Send" from the Pack console when the consignment is actually leaving.'
          ];
          include __DIR__ . '/components/status-alert.php';
          ?>
        <?php endif; ?>

        <!-- Legacy #productSearchPanel removed (superseded by on-demand modal). -->

        <?php
        // Transfer header and actions (reverting to standard header component; hide draft status pill)
        $header_config = [
          'transfer_id' => $txId,
          'title' => 'Pack Transfer',
          'title_id' => 'pack-title',
          'subtitle' => $fromLbl . ' → ' . $toLbl,
          'description' => 'Count and verify each item against the planned quantities, allocate parcels & shipping labels, record discrepancies, and finalise this stock transfer for dispatch.',
          'show_draft_status' => false,
          'actions' => [], // moved Add Product control below into metrics bar
          'metrics' => [],
          'wrapper_class' => 'card mb-0 pack-header-join'
        ];
  // Minimal critical gradient CSS to avoid FOUC on ultra-slow devices (full rules in pack-extra.css)
  echo '<style id="packHeaderCritical">.pack-header-join .card-header{background:#423ffb;background:linear-gradient(35deg,rgba(66,63,251,1) 15%,rgba(213,75,181,1) 100%,rgba(252,70,107,1) 84%);color:#fff;}</style>';
  include __DIR__ . '/components/transfer-header.php';

        // Standalone items table (restored from unified component, simplified)
        ?>
        <?php
        // Precompute totals for initial render so metrics show real values immediately
        $plannedSum = 0;
        $countedSum = 0;
        foreach ($items as $it) {
          $p = $it['qty_requested'] ?? $it['planned_qty'] ?? 0;
            $c = $it['counted_qty'] ?? 0;
            if (!is_numeric($p)) $p = 0; if (!is_numeric($c)) $c = 0;
            $plannedSum += (int)$p; $countedSum += (int)$c;
        }
        $diffSum = $countedSum - $plannedSum;
        $diffLabel = ($diffSum >= 0 ? '+' : '') . $diffSum;
        ?>
  <section class="card pack-items-joined" id="transfer-items-card" aria-labelledby="pack-title">
          <div class="table-metrics-bar d-flex justify-content-between align-items-center flex-wrap px-3 pt-2 pb-2 border-bottom" style="background:#fafafa;">
            <div class="d-flex align-items-center mb-sm-0 flex-column flex-sm-row" style="gap:4px;">
              <div id="autosavePill" class="autosave-pill status-idle" aria-live="polite" title="Draft status: idle">
                <span class="pill-dot" aria-hidden="true"></span>
                <span class="pill-text" id="autosavePillText">Idle</span>
              </div>
              <div id="autosaveLastSaved" class="autosave-last-saved small text-muted" style="font-size:11px; line-height:1.2; min-height:14px;"></div>
            </div>
            <div class="d-flex align-items-center flex-wrap gap-3 metrics-cluster text-right" style="justify-content:flex-end;">
              <span class="small text-uppercase text-muted mr-3">Summary</span>
              <div class="metric-item small mr-3">Items: <strong id="itemsToTransfer"><?= count($items) ?></strong></div>
              <div class="metric-item small mr-3">Planned: <strong id="plannedTotal"><?= $plannedSum ?></strong></div>
              <div class="metric-item small mr-3">Counted: <strong id="countedTotal"><?= $countedSum ?></strong></div>
              <div class="metric-item small">Diff: <strong id="diffTotal"><?= htmlspecialchars($diffLabel, ENT_QUOTES) ?></strong></div>
              <div class="dropdown ml-3" id="addProductDropdown">
                <button class="btn btn-sm btn-primary dropdown-toggle options-btn" type="button" id="addProductMenuBtn" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" aria-label="Options menu">
                  Options
                </button>
                <div class="dropdown-menu dropdown-menu-right" aria-labelledby="addProductMenuBtn">
                  <button class="dropdown-item" type="button" id="addProductOpen">ADD A PRODUCT</button>
                  <!-- Future: <div class="dropdown-divider"></div><button class="dropdown-item" type="button">Bulk Import…</button> -->
                </div>
              </div>
            </div>
          </div>
          <div class="card-body p-0">
            <?php if (empty($items)): ?>
              <div class="text-center py-5">
                <div class="text-muted">
                  <i class="fa fa-inbox fa-3x mb-3"></i>
                  <p>No items on this transfer.</p>
                </div>
              </div>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table table-sm mb-0" id="transferItemsTable">
                  <thead class="thead-light">
                    <tr>
                      <th style="width:50px;" class="text-center">DEL</th>
                      <th>Product</th>
                      <th class="text-center" style="width:110px;">In Stock</th>
                      <th class="text-center" style="width:110px;">Planned</th>
                      <th class="text-center" style="width:140px;">Counted</th>
                      <th class="text-center" style="width:140px;">SENT TO</th>
                      <th class="text-center" style="width:150px;">PRODUCT ID</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php 
                  $rowNum = 1;
                  // helper: printable product tag (transfer number + sequence)
                  if (!function_exists('tfx_product_tag')) {
                    function tfx_product_tag(string $publicOrId, int $index): string {
                      if (preg_match('#(\d+)$#', $publicOrId, $m)) { $num = $m[1]; } else { $num = $publicOrId; }
                      return $num . '-' . max(1,$index);
                    }
                  }
                  // Product tag base should use internal transfer ID, not public display/public_id.
                  // This ensures format: <transferId>-<lineIndex> e.g. 13205-1, 13205-2
                  $transferPublic = (string)$txId;
                  foreach ($items as $item):
                    $itemId = $item['id'] ?? $rowNum;
                    $plannedQty = $item['qty_requested'] ?? $item['planned_qty'] ?? 0;
                    $countedQty = $item['counted_qty'] ?? 0;
                    $productId = (string)($item['product_id'] ?? $item['vend_product_id'] ?? '');
                    $stockQty = 0;
                    if ($productId !== '' && isset($sourceStockMap[$productId])) {
                      $stockQty = (int)$sourceStockMap[$productId];
                    }
                    $plannedQty = is_numeric($plannedQty) ? (int)$plannedQty : 0;
                    $countedQty = is_numeric($countedQty) ? (int)$countedQty : 0;
                  ?>
                    <tr id="item-row-<?= (int)$itemId ?>" data-item-id="<?= (int)$itemId ?>" data-product-id="<?= htmlspecialchars($productId, ENT_QUOTES) ?>">
                      <td class="text-center align-middle">
                        <button class="tfx-remove-btn" title="Remove item" data-item-id="<?= (int)$itemId ?>"><i class="fa fa-times" aria-hidden="true"></i></button>
                      </td>
                      <td class="align-middle">
                        <?php if (function_exists('tfx_render_product_cell')) { echo tfx_render_product_cell($item); } else { echo htmlspecialchars($item['product_name'] ?? $item['name'] ?? 'Product', ENT_QUOTES); } ?>
                      </td>
                      <td class="text-center align-middle">
                        <?php if ($stockQty <= 0): ?>
                          <span class="text-danger font-weight-bold">0</span>
                          <div class="small text-danger"><i class="fa fa-exclamation-triangle"></i> Out</div>
                        <?php elseif ($stockQty < $plannedQty): ?>
                          <span class="text-warning font-weight-bold"><?= $stockQty ?></span>
                          <div class="small text-warning"><i class="fa fa-exclamation-triangle"></i> Low</div>
                        <?php else: ?>
                          <span class="text-success font-weight-bold"><?= $stockQty ?></span>
                        <?php endif; ?>
                      </td>
                      <td class="text-center align-middle"><span class="font-weight-bold" data-planned="<?= $plannedQty ?>"><?= $plannedQty ?></span></td>
                      <td class="text-center align-middle counted-td">
                        <input type="number"
                               class="form-control form-control-sm tfx-num qty-input"
                               name="counted_qty[<?= (int)$itemId ?>]"
                               id="counted-<?= (int)$itemId ?>"
                               min="0" step="1"
                               <?= $countedQty > 0 ? 'value="' . $countedQty . '"' : 'placeholder="0"' ?>
                               data-item-id="<?= (int)$itemId ?>"
                               data-planned="<?= $plannedQty ?>">
                      </td>
                      <td class="text-center align-middle"><?= htmlspecialchars($toLbl, ENT_QUOTES) ?></td>
                      <td class="text-center align-middle mono" title="Product Tag"><?= htmlspecialchars(tfx_product_tag($transferPublic, $rowNum), ENT_QUOTES) ?></td>
                    </tr>
                  <?php $rowNum++; endforeach; ?>
                  </tbody>
                  <tfoot>
                    <tr>
                      <td colspan="3" class="text-right font-weight-bold">Totals:</td>
                      <td class="text-center" id="plannedTotalFooter"><?= $plannedSum ?></td>
                      <td class="text-center">
                        <span id="countedTotalFooter"><?= $countedSum ?></span>
                        <small class="d-block text-muted">Diff: <span id="diffTotalFooter"><?= htmlspecialchars($diffLabel, ENT_QUOTES) ?></span></small>
                      </td>
                      <td></td>
                      <td></td>
                    </tr>
                  </tfoot>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </section>
        <?php
        ?>

        <?php
        /*
         * Pack & Ship Console temporarily hidden per request (REMOVE FROM THE PAGE BUT DO NOT DELETE).
         * To restore, change if(false) to if(true) or remove the conditional wrapper below.
         */
        if (false) {
          $dispatch_config = [
            'transfer_id' => $txId,
            'from_outlet' => $fromLbl,
            'to_outlet' => $toLbl,
            'from_line' => $fromLine ?? '',
            'to_line' => $toLine ?? '',
            'show_courier_detail' => $showCourierDetail ?? false,
            'print_pool' => [
              'online' => $printPoolOnline ?? false,
              'status_text' => $printPoolOnline ? 'Print pool online' : 'Print pool offline',
              'meta_text' => $printPoolMetaText ?? 'Awaiting printer status'
            ],
            'freight_metrics' => $freightMetrics ?? ['total_weight_kg' => 0.0, 'total_items' => 0],
            'manual_summary' => [
              'weight_label' => $manualSummaryWeightLabel ?? '—',
              'boxes_label' => $manualSummaryBoxesLabel ?? '—'
            ]
          ];
          // Load CSS assets (kept with console to avoid unused includes while hidden)
          echo load_transfer_css();
          include __DIR__ . '/components/pack-ship-console.php';
        }
        ?>

          <!-- Delivery Tracking (moved inside main container) -->
          <div id="deliveryTrackingContainer" class="mt-4">
            <section id="manualTrackingPanel" class="card tracking-panel" aria-labelledby="manualTrackingHeading">
              <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                  <i class="fa fa-barcode mr-2 text-muted" aria-hidden="true"></i>
                  <h6 class="mb-0 font-weight-bold" id="manualTrackingHeading">Delivery Tracking</h6>
                </div>
                <div class="btn-group btn-group-sm" role="group" aria-label="Tracking mode">
                  <button type="button" class="btn btn-outline-secondary active" data-track-mode="manual" id="trackModeManual">Manual</button>
                  <button type="button" class="btn btn-outline-secondary" data-track-mode="internal" id="trackModeInternal">Delivered Internal</button>
                </div>
              </div>
              <div class="card-body pt-3 pb-2 px-3">
                <form id="manualTrackingForm" class="tracking-form" autocomplete="off">
                  <input type="hidden" name="transfer_id" value="<?= (int)$txId ?>" />
                  <!-- Promoted Order Comments textarea (replaces smaller inline notes input) -->
                  <div class="mb-3" style="width:100%;">
                    <label for="trackingNotes" class="small mb-1 font-weight-semibold">Order Comments (Optional)</label>
                    <textarea class="form-control form-control-sm" id="trackingNotes" placeholder="Add any internal handover / dispatch comments…" maxlength="300" rows="3" style="resize:vertical;"></textarea>
                    <small class="text-muted d-block mt-1">These comments will be saved alongside each tracking entry you add (not customer-facing).</small>
                  </div>
                  <div class="form-row align-items-end">
                    <div class="col-sm-4 mb-2">
                      <label class="small mb-1 font-weight-semibold">Tracking Number</label>
                      <input type="text" class="form-control form-control-sm" id="trackingInput" placeholder="e.g. MAN123456789" maxlength="64" />
                    </div>
                    <div class="col-sm-3 mb-2">
                      <label class="small mb-1 font-weight-semibold">Carrier</label>
                      <select class="form-control form-control-sm" id="carrierSelect" aria-label="Carrier" required>
                        <option value="" selected disabled>Please Select…</option>
                        <option value="2" data-code="NZC_MANUAL">NZ Couriers (GSS)</option>
                        <option value="1" data-code="NZP_MANUAL">NZ Post</option>
                        <option value="manual" data-code="MANUAL_OTHER">Manual / Other</option>
                      </select>
                   
                    </div>
                    <!-- Notes field moved & upgraded to textarea above; column slot intentionally left blank for balanced layout on wider screens -->
                    <div class="col-sm-1 mb-2 text-right">
                      <button type="submit" class="btn btn-sm btn-primary w-100" id="addTrackingBtn" disabled>Add</button>
                    </div>
                  </div>
                </form>
                <div class="tracking-status-row d-flex align-items-center small text-muted" id="trackingStatus" aria-live="polite"></div>
                <div class="table-responsive mt-3">
                  <table class="table table-sm table-striped mb-0" id="manualTrackingTable" aria-label="Manual tracking numbers">
                    <thead class="thead-light">
                      <tr>
                        <th style="width:36px;" class="text-center">#</th>
                        <th>Tracking</th>
                        <th style="width:140px;">Carrier</th>
                        <th>Notes</th>
                        <th style="width:120px;">Mode</th>
                        <th style="width:56px;" class="text-center">Del</th>
                      </tr>
                    </thead>
                    <tbody id="manualTrackingTbody">
                      <tr class="tracking-empty"><td colspan="6" class="text-center text-muted small py-3">No manual tracking added yet.</td></tr>
                    </tbody>
                  </table>
                </div>
              </div>
            </section>
          </div>

        </div><!-- /.pack-white-container -->
      </div>
    </main>
  </div>

  <!-- Boot payload for JS -->
  <script>
  window.DISPATCH_BOOT = <?= json_encode($bootPayload ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  </script>

  <!-- Immediate Render Add Product Modal (new implementation) -->
  <div id="addProdModal" class="modal fade" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
      <div class="modal-content">
        <div class="modal-header py-2">
          <h5 class="modal-title">Add Product to Transfer</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body" id="addProdModalBody">Loading search…</div>
        <div class="modal-footer py-2">
          <button class="btn btn-secondary btn-sm" data-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>
  <!-- Image Preview Lightweight Modal -->
  <div id="imgModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.72); align-items:center; justify-content:center; z-index:1060;" aria-hidden="true" role="dialog">
    <div style="background:#fff; padding:12px 12px 8px; border-radius:6px; max-width:640px; width:92%; position:relative;">
      <button type="button" onclick="ImgPreview.hide()" aria-label="Close" style="position:absolute; top:4px; right:6px; background:none; border:none; font-size:20px; line-height:1;">&times;</button>
      <img id="imgModalPic" src="" alt="Preview" style="max-width:100%; max-height:70vh; display:block; margin:0 auto;">
    </div>
  </div>

  <!-- Modular JS: core (utilities/print guard), product modal, metrics/autosave, tracking -->
  
  <?= load_transfer_js(); ?>

  <script src="/modules/transfers/stock/assets/js/pack-core.js?v=<?= htmlspecialchars($assetVer,ENT_QUOTES) ?>" defer></script>
  <script src="/modules/transfers/stock/assets/js/pack-toast.js?v=<?= htmlspecialchars($assetVer,ENT_QUOTES) ?>" defer></script>
  <!-- pack-product-modal.js now lazy-loaded on demand for efficiency -->
  <script src="/modules/transfers/stock/assets/js/pack-metrics.js?v=<?= htmlspecialchars($assetVer,ENT_QUOTES) ?>" defer></script>
  <script src="/modules/transfers/stock/assets/js/pack-tracking.js?v=<?= htmlspecialchars($assetVer,ENT_QUOTES) ?>" defer></script>
  <script>
    // Toast on draft data present (initial load feedback)
    (function(){
      try {
        var draftEl = document.getElementById('initialDraftData');
        if(draftEl && draftEl.textContent.trim().length){
          var d = JSON.parse(draftEl.textContent);
          if(d && (d.counted_qty && Object.keys(d.counted_qty).length)){ 
            window.addEventListener('DOMContentLoaded', function(){
              if(window.PackToast){ PackToast.info('Loaded Existing Transfer Data'); }
            });
          }
        }
      } catch(e){}
    })();
  </script>
  <?php include $DOCUMENT_ROOT . '/assets/template/personalisation-menu.php'; ?>
  <?php // Enforce active html-footer include (not deprecated)
    $root = isset($DOCUMENT_ROOT) ? $DOCUMENT_ROOT : ($_SERVER['DOCUMENT_ROOT'] ?? '');
    $htmlFooterPath = rtrim($root,'/') . '/assets/template/html-footer.php';
    if (is_file($htmlFooterPath)) {
      include $htmlFooterPath;
    } else {
      // Explicit warning if missing (single clear path) – surfaces misconfiguration without ambiguous fallback
      trigger_error('Expected html-footer.php not found at ' . $htmlFooterPath, E_USER_WARNING);
    }
  ?>
  <?php include $DOCUMENT_ROOT . '/assets/template/footer.php'; ?>
  <script>
    // Guard: remove any legacy injected print header/footer remnants that may persist from cached scripts
    document.addEventListener('DOMContentLoaded', function(){['print-header','print-footer'].forEach(function(id){var n=document.getElementById(id); if(n && !n.classList.contains('preserve-print-node')){ n.parentNode.removeChild(n); }});});
  </script>