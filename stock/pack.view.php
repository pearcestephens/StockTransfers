<?php
/**
 * CIS — Transfers » Stock » Pack (View) — CLEAN (Purple lock system only)
 *
 * Expected variables (from pack.php):
 * - $DOCUMENT_ROOT
 * - $txId, $txStringId, $transfer, $items
 * - $fromLbl, $toLbl, $fromOutlet, $toOutlet
 * - $fromDisplay, $toDisplay
 * - $isPackaged
 * - $sourceStockMap
 * - $plannedSum, $countedSum, $diff, $diffLabel, $accuracy
 * - $carrierComparison
 * - $bootPayload, $assetVer, $lockStatus
 */

// Fallback helper for product tag generation (TRANSFERID-LINENUM)
if (!function_exists('_tfx_product_tag')) {
    function _tfx_product_tag($txId, $lineNum) {
        return htmlspecialchars("{$txId}-{$lineNum}", ENT_QUOTES, 'UTF-8');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pack Transfer #<?= htmlspecialchars($txId ?? '', ENT_QUOTES) ?></title>
    
    <!-- External CSS -->
    <link rel="stylesheet" href="/modules/transfers/stock/assets/css/pack-unified.css?v=<?= (int)$assetVer ?>">
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
      if (file_exists(__DIR__ . '/views/components/breadcrumb.php')) {
        include __DIR__ . '/views/components/breadcrumb.php';
      }
      ?>

      <div class="container-fluid animated fadeIn">
        
        <!-- Store Transfer Header -->
        <div class="card mb-3">
          <div class="card-header bg-primary text-white">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <h4 class="mb-0">
                  <i class="fas fa-exchange-alt mr-2"></i>
                  Store Transfer #<?= (int)$txId ?>
                  <span class="badge badge-light ml-2" id="lockStatusBadge">ACTIVE</span>
                </h4>
              </div>
              <div class="d-flex align-items-center">
                <button class="btn btn-light btn-sm mr-2" id="lockDiagnosticBtn" title="System Diagnostic">
                  <i class="fas fa-cog"></i>
                </button>
                <button type="button" class="btn btn-light btn-sm" id="headerAddProductBtn">
                  <i class="fas fa-plus mr-1"></i>Add Product
                </button>
              </div>
            </div>
          </div>
          <div class="card-body">
            <div class="row">
              <div class="col-md-6">
                <div class="d-flex align-items-start">
                  <div class="mr-3">
                    <i class="fas fa-store fa-2x text-primary"></i>
                  </div>
                  <div class="flex-grow-1">
                    <div class="mb-2">
                      <strong>From:</strong> 
                      <span class="h5 mb-0"><?= htmlspecialchars($fromLbl, ENT_QUOTES) ?></span>
                    </div>
                    <?php if (!empty($fromOutlet) && is_array($fromOutlet)): ?>
                      <div>
                        <?php if (!empty($fromOutlet['google_review_rating'])): ?>
                          <span class="badge badge-warning mr-2">
                            <i class="fas fa-star"></i> <?= number_format((float)$fromOutlet['google_review_rating'], 1) ?>
                          </span>
                        <?php endif; ?>
                      </div>
                      <?php if (!empty($fromOutlet['physical_address_1'])): ?>
                        <div class="text-muted" style="font-size: 0.85rem;">
                          <i class="fas fa-map-marker-alt text-secondary mr-1"></i>
                          <?= htmlspecialchars($fromOutlet['physical_address_1'], ENT_QUOTES) ?>
                          <?php if (!empty($fromOutlet['physical_city'])): ?>
                            , <?= htmlspecialchars($fromOutlet['physical_city'], ENT_QUOTES) ?>
                          <?php endif; ?>
                        </div>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
              
              <div class="col-md-6">
                <div class="d-flex align-items-start">
                  <div class="mr-3">
                    <i class="fas fa-map-marker-alt fa-2x text-success"></i>
                  </div>
                  <div class="flex-grow-1">
                    <div class="mb-2">
                      <strong>To:</strong> 
                      <span class="h5 mb-0"><?= htmlspecialchars($toLbl, ENT_QUOTES) ?></span>
                    </div>
                    <?php if (!empty($toOutlet) && is_array($toOutlet)): ?>
                      <div>
                        <?php if (!empty($toOutlet['google_review_rating'])): ?>
                          <span class="badge badge-warning mr-2">
                            <i class="fas fa-star"></i> <?= number_format((float)$toOutlet['google_review_rating'], 1) ?>
                          </span>
                        <?php endif; ?>
                      </div>
                      <?php if (!empty($toOutlet['physical_address_1'])): ?>
                        <div class="text-muted" style="font-size: 0.85rem;">
                          <i class="fas fa-map-marker-alt text-secondary mr-1"></i>
                          <?= htmlspecialchars($toOutlet['physical_address_1'], ENT_QUOTES) ?>
                          <?php if (!empty($toOutlet['physical_city'])): ?>
                            , <?= htmlspecialchars($toOutlet['physical_city'], ENT_QUOTES) ?>
                          <?php endif; ?>
                          <?php if (!empty($toOutlet['physical_postcode'])): ?>
                            <?= htmlspecialchars($toOutlet['physical_postcode'], ENT_QUOTES) ?>
                          <?php endif; ?>
                        </div>
                      <?php endif; ?>
                      
                      <!-- Contact Info -->
                      <?php if (!empty($toOutlet['physical_phone_number'])): ?>
                        <div class="mt-2">
                          <a href="tel:<?= htmlspecialchars($toOutlet['physical_phone_number'], ENT_QUOTES) ?>"
                             class="btn btn-sm btn-outline-success" style="padding: 4px 12px; font-size: 0.85rem;">
                            <i class="fas fa-phone mr-1"></i>
                            <?= htmlspecialchars($toOutlet['physical_phone_number'], ENT_QUOTES) ?>
                          </a>
                        </div>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div><!-- /store transfer header -->

          <!-- Items Table -->
          <section class="card mb-4">
            <!-- Legacy #lockOverlay reference removed earlier: confirm no overlay markup ships. -->

            <div class="card-header py-2 d-flex justify-content-between align-items-center">
              <div class="d-flex align-items-center">
                <!-- Autosave Status Pill -->
                <div id="autosavePill" class="autosave-pill status-idle" style="display:inline-flex; align-items:center; padding:4px 10px; border-radius:15px; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.5px; background-color:#6c757d; color:white;">
                  <span class="pill-icon" style="width:6px; height:6px; border-radius:50%; margin-right:6px; background-color:currentColor;"></span>
                  <span id="autosavePillText">Idle</span>
                </div>
                <span id="autosaveStatus" class="ml-2 small text-muted" style="font-size:11px;">&nbsp;</span>
                <span id="autosaveLastSaved" class="ml-2 small text-muted" style="font-size:11px;"></span>
                <div id="lastSaveTime" style="font-size: 0.75rem; color: #6c757d; margin-left: 8px; min-height: 14px;"></div>
              </div>
              <div class="d-flex align-items-center">
                <button type="button" id="autofillBtn" class="btn btn-outline-primary btn-sm mr-2">
                  <i class="fa fa-magic mr-1"></i>Autofill
                </button>
                <button type="button" id="resetBtn" class="btn btn-outline-secondary btn-sm">
                  <i class="fa fa-undo mr-1"></i>Reset
                </button>
              </div>
            </div>

            <div class="card-body p-0">
              <div class="px-3 pt-2 pb-1 small text-muted d-flex justify-content-between align-items-center flex-wrap" aria-hidden="false">
                <div class="d-flex align-items-center flex-wrap" style="gap:12px;">
                  <span><strong>Weight Source Legend:</strong> <span class="badge badge-light" style="font-size:9px; border:1px solid #ccc;">P</span> Product <span class="badge badge-light" style="font-size:9px; border:1px solid #ccc;">C</span> Category <span class="badge badge-light" style="font-size:9px; border:1px solid #ccc;">D</span> Default</span>
                  <span id="weightSourceBreakdown" class="text-muted"></span>
                </div>
                <div class="mt-1 mt-sm-0" id="lineAnnouncement" class="sr-only" aria-live="polite" aria-atomic="true" style="position:absolute; left:-9999px; top:auto; width:1px; height:1px; overflow:hidden;">Line updates announced here.</div>
              </div>
              <div class="table-responsive" id="transferItemsTableWrapper">
                <table class="table table-sm table-striped mb-0" id="transferItemsTable">
                  <thead class="thead-light">
                    <tr>
                      <th style="width:50px;" class="text-center"></th>
                      <th style="padding-left:8px; width: 40%;">Product</th>
                      <th style="width:80px;" class="text-center">Source Stock</th>
                      <th style="width:70px;" class="text-center">Planned</th>
                      <th style="width:90px;" class="text-center">Counted</th>
                      <th style="width:70px;" class="text-center">To</th>
                      <th style="width:90px;" class="text-center">Weight</th>
                      <th style="width:60px;" class="text-center">Tag</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php if (empty($items)): ?>
                    <tr>
                      <td colspan="7" class="text-center text-muted py-4">
                        <i class="fa fa-exclamation-triangle mb-2"></i><br>
                        No items found in this transfer.
                        <small class="d-block">Items count: <?= count($items ?? []) ?></small>
                      </td>
                    </tr>
                  <?php else: ?>
                  <?php
                    $rowNum = 1;
                    $totalCountedWeightG = 0; // aggregate counted weight (grams)
                    foreach ($items as $item):
                      $itemId     = (int)_first($item['id'] ?? null, $rowNum);
                      $productId  = (string)_first($item['product_id'] ?? null, $item['vend_product_id'] ?? null, '');
                      $plannedQty = (int)_first($item['qty_requested'] ?? null, $item['planned_qty'] ?? 0);
                      $countedQty = (int)($item['counted_qty'] ?? 0);
                      $stockQty   = ($productId !== '' && isset($sourceStockMap[$productId])) ? (int)$sourceStockMap[$productId] : 0;
                      // Resolve unit weight (grams) precedence aligning with freight calculators:
                      // 1. Product avg weight (avg_weight_grams / product_weight_grams / weight_g variants)
                      // 2. Category avg weight (category_avg_weight_grams, category_weight_grams, cat_weight_g)
                      // 3. System default (100g) if still missing
                      $unitWeightG = (int)_first(
                        $item['derived_unit_weight_grams'] ?? null,
                        $item['avg_weight_grams'] ?? null,
                        $item['product_weight_grams'] ?? null,
                        $item['weight_g'] ?? null,
                        $item['unit_weight_g'] ?? null,
                        $item['category_avg_weight_grams'] ?? null,
                        $item['category_weight_grams'] ?? null,
                        $item['cat_weight_g'] ?? null,
                        100
                      );
                      $weightSource = (string)_first(
                        $item['weight_source'] ?? null,
                        (isset($item['avg_weight_grams'])||isset($item['product_weight_grams'])||isset($item['weight_g'])||isset($item['unit_weight_g']))
                          ? 'product'
                          : ((isset($item['category_avg_weight_grams'])||isset($item['category_weight_grams'])||isset($item['cat_weight_g'])) ? 'category' : 'default')
                      );
                      $rowWeightG  = $unitWeightG > 0 ? $unitWeightG * max($countedQty, 0) : 0; // counted weight basis
                      $totalCountedWeightG += $rowWeightG;

                      // Product image best-effort
                      $imageUrl = '';
                      foreach (['image_url','image_thumbnail_url','product_image_url','vend_image_url','thumbnail_url','image'] as $field) {
                        if (!empty($item[$field])) { $imageUrl = $item[$field]; break; }
                      }
                      if (empty($imageUrl) && !empty($productId)) {
                        $imageUrl = $item['image_url'] ?? '';
                      }
                      $hasImage = !empty($imageUrl)
                                  && $imageUrl !== 'https://secure.vendhq.com/images/placeholder/product/no-image-white-original.png'
                                  && filter_var($imageUrl, FILTER_VALIDATE_URL);
                  ?>
                    <tr id="item-row-<?= $itemId ?>"
                        class="pack-item-row"
                        data-item-id="<?= $itemId ?>"
                        data-product-id="<?= htmlspecialchars($productId, ENT_QUOTES) ?>"
                        data-planned-qty="<?= $plannedQty ?>"
                        data-source-stock="<?= $stockQty ?>"
                        data-unit-weight-g="<?= $unitWeightG ?>">
                      <td class="text-center align-middle" style="width:50px; padding: 3px;">
                        <?php if ($hasImage): ?>
                          <img src="<?= htmlspecialchars($imageUrl, ENT_QUOTES) ?>"
                               class="product-thumb"
                               style="width: 45px; height: 45px; object-fit: cover; border-radius: 6px; border: 2px solid #dee2e6; transition: all 0.3s ease; cursor: pointer;"
                               onclick="showProductImageModal(this.src, '<?= htmlspecialchars($item['product_name'] ?? 'Product Image', ENT_QUOTES) ?>')"
                               onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                               alt="Product image">
                        <?php endif; ?>
                        <div style="width: 45px; height: 45px; background: #f8f9fa; border: 2px solid #dee2e6; border-radius: 6px; display: <?= $hasImage ? 'none' : 'flex' ?>; align-items: center; justify-content: center;">
                          <i class="fa fa-image text-muted" style="font-size: 18px;"></i>
                        </div>
                      </td>
                      <td class="align-middle" style="padding-left:3px; padding-right: 8px;">
                        <?php
                          if (function_exists('tfx_render_product_cell')) {
                            echo tfx_render_product_cell($item);
                          } else {
                            echo htmlspecialchars((string)_first($item['product_name'] ?? null, $item['name'] ?? 'Product'), ENT_QUOTES);
                          }
                        ?>
                      </td>
                      <td class="text-center align-middle">
                        <?php
                          if ($stockQty <= 0) {
                            echo '<span class="text-danger font-weight-bold">0</span><div class="small text-danger"><i class="fa fa-exclamation-triangle" title="Out of Stock"></i></div>';
                          } elseif ($stockQty < $plannedQty) {
                            echo '<span class="text-warning font-weight-bold">' . $stockQty . '</span><div class="small text-warning"><i class="fa fa-exclamation-triangle"></i> Low</div>';
                          } else {
                            echo '<span class="text-success font-weight-bold">' . $stockQty . '</span>';
                          }
                        ?>
                      </td>
                      <td class="text-center align-middle">
                        <span class="font-weight-bold" data-planned="<?= $plannedQty ?>"><?= $plannedQty ?></span>
                      </td>
                      <td class="text-center align-middle counted-td">
                        <input type="number"
                               class="form-control form-control-sm tfx-num qty-input"
                               name="counted_qty[<?= $itemId ?>]"
                               id="counted-<?= $itemId ?>"
                               min="0" step="1"
                               <?= $countedQty > 0 ? 'value="' . $countedQty . '"' : 'placeholder="0"' ?>
                               data-item-id="<?= $itemId ?>"
                               data-planned="<?= $plannedQty ?>"
                               data-source-stock="<?= $stockQty ?>"
                               style="text-align: center;">
                      </td>
                      <td class="text-center align-middle"><?= htmlspecialchars($toLbl, ENT_QUOTES) ?></td>
                      <td class="text-center align-middle">
                        <?php if ($unitWeightG > 0): ?>
                          <?php
                            $srcAbbr = $weightSource === 'product' ? 'P' : ($weightSource === 'category' ? 'C' : 'D');
                            $srcTitle = $weightSource === 'product' ? 'Product specific weight' : ($weightSource === 'category' ? 'Category average weight' : 'Default fallback weight');
                          ?>
                          <span class="row-weight" data-unit-weight-g="<?= $unitWeightG ?>" data-weight-source="<?= htmlspecialchars($weightSource, ENT_QUOTES) ?>" data-row-weight-g="<?= $rowWeightG ?>">
                            <?= $rowWeightG > 0 ? number_format($rowWeightG/1000, 3) . 'kg' : '—' ?>
                          </span>
                          <small class="text-muted d-block" style="font-size:10px;">@<?= number_format($unitWeightG/1000,3) ?>kg ea <span class="badge badge-light" title="<?= htmlspecialchars($srcTitle, ENT_QUOTES) ?>" style="font-size:9px; font-weight:600; border:1px solid #ddd;"><?= $srcAbbr ?></span></small>
                        <?php else: ?>
                          <span class="row-weight text-muted" data-unit-weight-g="0">—</span>
                          <small class="text-warning d-block" style="font-size:10px;">no wt</small>
                        <?php endif; ?>
                      </td>
                      <td class="text-center align-middle mono" title="Product Tag"><?= htmlspecialchars(_tfx_product_tag((string)$txId, $rowNum), ENT_QUOTES) ?></td>
                    </tr>
                  <?php $rowNum++; endforeach; ?>
                  <?php endif; ?>
                  </tbody>
                  <tfoot>
                    <?php
                      if (!isset($estimatedWeight) || !is_numeric($estimatedWeight)) {
                        $estimatedWeight = $totalCountedWeightG / 1000.0; // fallback derive
                      }
                    ?>
                    <tr>
                      <td colspan="3" class="text-right font-weight-bold">Totals:</td>
                      <td class="text-center" id="plannedTotalFooter"><?= (int)$plannedSum ?></td>
                      <td class="text-center">
                        <span id="countedTotalFooter"><?= (int)$countedSum ?></span>
                        <small class="d-block text-muted">Diff: <span id="diffTotalFooter"><?= htmlspecialchars($diffLabel, ENT_QUOTES) ?></span></small>
                      </td>
                      <td></td>
                      <td class="text-center" id="totalWeightFooter">
                        <span class="font-weight-bold text-info"><span id="totalWeightFooterKgValue"><?= number_format($estimatedWeight, 2) ?></span>kg</span>
                        <small class="d-block text-muted">Total Weight</small>
                      </td>
                      <td></td>
                    </tr>
                  </tfoot>
                </table>
              </div>
            </div>
          </section>

          <!-- Weight recalculation module -->
          <script src="/modules/transfers/stock/assets/js/weight-recalc.js?v=<?= (int)$assetVer ?>" defer></script>

          <!-- Carrier Price Comparison (NZ Post vs NZ Couriers) -->
          <?php if (!empty($carrierComparison) && is_array($carrierComparison)): ?>
            <section class="card mb-4" id="carrierComparisonCard" aria-labelledby="carrierComparisonHeading">
              <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                  <i class="fa fa-balance-scale mr-2 text-muted" aria-hidden="true"></i>
                  <h6 class="mb-0 font-weight-bold" id="carrierComparisonHeading">Carrier Price Comparison</h6>
                </div>
                <small class="text-muted">Weight: <?= number_format((float)($carrierComparison['weight_kg'] ?? 0), 2) ?>kg • Distance: <?= number_format((float)($carrierComparison['distance_km'] ?? 0), 0) ?>km</small>
              </div>
              <div class="card-body p-0">
                <?php if (!empty($carrierComparison['quotes'])): ?>
                  <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0" aria-label="Carrier pricing comparison">
                      <thead class="thead-light">
                        <tr>
                          <th style="width:160px;">Carrier</th>
                          <th>Container</th>
                          <th style="width:110px;" class="text-right">Cap (kg)</th>
                          <th style="width:110px;" class="text-right">Price</th>
                          <th style="width:140px;" class="text-right">Cost / kg</th>
                          <th style="width:80px;" class="text-center">Best</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php
                          $bestCode = isset($carrierComparison['best']) ? strtolower($carrierComparison['best']['carrier'] ?? '') : '';
                          foreach ($carrierComparison['quotes'] as $q):
                            $c = strtolower($q['carrier'] ?? '');
                            $isBest = $bestCode === $c;
                            $capKg = !empty($q['cap_weight_g']) ? number_format(((float)$q['cap_weight_g'])/1000, 2) : '—';
                            $price = number_format((float)$q['price'], 2);
                            $weightBasis = max(0.0001, (float)($carrierComparison['weight_kg'] ?? 0));
                            $costPerKg = number_format($weightBasis>0 ? ((float)$q['price'] / $weightBasis) : 0, 2);
                            $label = $c === 'nz_post' ? 'NZ Post' : ($c === 'nz_couriers' ? 'NZ Couriers' : strtoupper($c));
                        ?>
                          <tr class="<?= $isBest ? 'table-success' : '' ?>">
                            <td class="align-middle font-weight-semibold"><?= htmlspecialchars($label, ENT_QUOTES) ?></td>
                            <td class="align-middle small">
                              <span class="d-block font-weight-500"><?= htmlspecialchars($q['container'] ?? ($q['container_name'] ?? '—'), ENT_QUOTES) ?></span>
                              <?php if (!empty($q['container_name']) && ($q['container_name'] !== $q['container'])): ?>
                                <small class="text-muted"><?= htmlspecialchars($q['container_name'], ENT_QUOTES) ?></small>
                              <?php endif; ?>
                            </td>
                            <td class="align-middle text-right mono"><?= $capKg ?></td>
                            <td class="align-middle text-right mono">$<?= $price ?></td>
                            <td class="align-middle text-right mono">$<?= $costPerKg ?></td>
                            <td class="align-middle text-center">
                              <?php if ($isBest): ?>
                                <span class="badge badge-success" style="font-size:10px;">Best</span>
                              <?php else: ?>
                                <span class="text-muted" style="font-size:10px;">—</span>
                              <?php endif; ?>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                  <?php if (!empty($carrierComparison['best'])): ?>
                    <div class="p-2 small border-top bg-light">
                      <strong>Recommendation:</strong> <?= htmlspecialchars(($carrierComparison['best']['carrier'] ?? 'Unknown'), ENT_QUOTES) ?> at $<?= number_format((float)($carrierComparison['best']['price'] ?? 0), 2) ?>.
                    </div>
                  <?php endif; ?>
                <?php else: ?>
                  <div class="p-3 small text-muted">
                    <i class="fa fa-info-circle mr-1"></i><?= htmlspecialchars($carrierComparison['note'] ?? 'No carrier pricing available', ENT_QUOTES) ?>
                  </div>
                <?php endif; ?>
              </div>
            </section>
          <?php endif; ?>

          <!-- Delivery Tracking Panel (manual / internal) -->
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
                  <div class="mb-3" style="width:100%;">
                    <label for="trackingNotes" class="small mb-1 font-weight-semibold">Order Comments (Optional)</label>
                    <textarea class="form-control form-control-sm" id="trackingNotes" placeholder="Add any internal handover / dispatch comments…" maxlength="300" rows="3" style="resize:vertical;"></textarea>
                    <small class="text-muted d-block mt-1">These comments save alongside each entry (not customer-facing).</small>
                  </div>

                  <div class="form-row align-items-end">
                    <div class="col-sm-4 mb-2">
                      <label class="small mb-1 font-weight-semibold" for="trackingInput">Tracking Number</label>
                      <input type="text" class="form-control form-control-sm" id="trackingInput" placeholder="e.g. MAN123456789" maxlength="64" />
                    </div>
                    <div class="col-sm-3 mb-2">
                      <label class="small mb-1 font-weight-semibold" for="carrierSelect">Carrier</label>
                      <select class="form-control form-control-sm" id="carrierSelect" aria-label="Carrier" required>
                        <option value="" selected disabled>Please Select…</option>
                        <option value="2" data-code="NZC_MANUAL">NZ Couriers (GSS)</option>
                        <option value="1" data-code="NZP_MANUAL">NZ Post</option>
                        <option value="manual" data-code="MANUAL_OTHER">Manual / Other</option>
                      </select>
                    </div>
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

          <!-- Bottom Primary Save -->
          <div class="card mt-4 mb-5 border-success" id="bottomSavePackCard">
            <div class="card-body text-center py-4">
              <button type="button" id="savePackBtn" class="btn btn-success btn-lg px-5 py-3" style="font-size:1.15rem; font-weight:600;">
                <i class="fa fa-save mr-2"></i><span class="save-pack-label"><?= $isPackaged ? 'Update Pack' : 'Packed & Ready For Pickup' ?></span>
              </button>
              <div class="small text-muted mt-2" id="savePackHint" style="display:none;">This saves current counted quantities & notes (does not dispatch).</div>
            </div>
          </div>

        </div><!-- /.pack-white-container -->
      </div><!-- /.container-fluid -->
    </main>
  </main>

  <!-- Add Product Modal with Enhanced Search -->
  <div id="addProdModal" class="modal fade" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="fa fa-search mr-2"></i>Add Product to Transfer</h5>
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

  <!-- Boot payload (clean) -->
  <script>
    window.DISPATCH_BOOT = <?= json_encode($bootPayload ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    window.lockStatus = <?= json_encode($lockStatus ?? [], JSON_UNESCAPED_SLASHES) ?>;
  </script>

  <!-- LATEST CSS -->
  <link rel="stylesheet" href="/modules/transfers/stock/assets/css/pack-unified.css?v=<?= (int)$assetVer ?>">

  <!-- LOCK SYSTEM DISABLED - JUST WORKS WITHOUT IT -->
  <!-- Simple Lock System -->
  <script src="/modules/transfers/stock/assets/js/simple-lock.js?v=<?= (int)$assetVer ?>"></script>

  <!-- App scripts (purple system) -->
  <script src="/modules/transfers/stock/assets/js/pack-unified.js?v=<?= (int)$assetVer ?>" defer></script>
  <script src="/modules/transfers/stock/assets/js/pack-autosave.js?v=<?= (int)$assetVer ?>" defer></script>

  <script src="/modules/transfers/stock/assets/js/autosave-bridge.js?v=<?= (int)$assetVer ?>" defer></script>
  <script src="/modules/transfers/stock/assets/js/pack-actions.js?v=<?= (int)$assetVer ?>" defer></script>

  <!-- Simple Lock Integration -->
  <script>
    (function(){
      const boot = window.DISPATCH_BOOT || {};
      const transferId = boot.transfer_id || document.getElementById('main')?.getAttribute('data-txid');
      const userId = boot.user_id || boot.staff_id || boot.userId || boot.staffId || window.CIS_USER_ID || null;
      const badgeEl = document.getElementById('lockStatusBadge');
      const announceEl = document.getElementById('lockStatusAnnounce');
      const actionableSelectors = [ '#headerAddProductBtn', 'button[data-save]', 'button.save-transfer', '#packSaveBtn' ];
      const SPECTATOR_CLASS='spectator-mode';
      const pageContainer=document.getElementById('packPageContainer');

      // Inject styles + bottom bar
      (function inject(){
        if(document.getElementById('lockRequestBar')) return;
        const style=document.createElement('style'); style.id='lockSpectatorStyles'; style.textContent=`
          .spectator-blur-wrap *:not(#transferItemsTable):not(#transferItemsTable *):not(#lockRequestBar):not(#lockRequestBar *):not(#lockHandoverModal):not(#lockHandoverModal *):not(#lockHandoverModalBackdrop) { pointer-events:none !important; opacity:0.6; }
          body.${SPECTATOR_CLASS} { position:relative; }
          #lockRequestBar { position:fixed; left:0; right:0; bottom:0; background:linear-gradient(90deg,#4c1d95,#6d28d9,#7e22ce); color:#fff; padding:10px 18px; z-index:9999; display:none; align-items:center; justify-content:space-between; font-size:14px; box-shadow:0 -2px 10px rgba(0,0,0,.35); font-weight:500; }
          #lockRequestBar.visible { display:flex; }
          #lockRequestBar button { font-weight:600; border-radius:6px; border:1px solid rgba(255,255,255,.35); background:rgba(255,255,255,.15); color:#fff; padding:6px 14px; backdrop-filter:blur(4px); }
          #lockRequestBar button:hover { background:rgba(255,255,255,.28); }
          #lockRequestCountdown { font-variant-numeric:tabular-nums; margin-left:8px; font-weight:600; }
          #lockHandoverModalBackdrop { position:fixed; inset:0; background:rgba(23,15,46,.78); display:flex; align-items:center; justify-content:center; z-index:10000; animation:fadeIn .25s ease; }
          #lockHandoverModal { background:#1f1139; border:1px solid #5b21b6; padding:28px 34px; max-width:480px; width:100%; border-radius:18px; box-shadow:0 10px 40px -5px rgba(80,0,160,.6),0 0 0 1px rgba(255,255,255,.08) inset; color:#f5f3ff; font-size:15px; line-height:1.5; animation:popIn .35s cubic-bezier(.16,.8,.26,1); }
          #lockHandoverModal h3 { font-size:20px; font-weight:700; margin:0 0 6px; letter-spacing:.5px; }
          #lockHandoverModal p { margin:0 0 14px; opacity:.9; }
          #lockHandoverModal .actions { display:flex; gap:12px; justify-content:flex-end; margin-top:10px; }
          #lockHandoverModal button { flex:0 0 auto; }
          @keyframes popIn {0%{transform:scale(.85) translateY(8px); opacity:0;}100%{transform:scale(1) translateY(0); opacity:1;}}
          @keyframes fadeIn {0%{opacity:0;}100%{opacity:1;}}
          #lockRequestBar.lock-owned { background:linear-gradient(90deg,#be123c,#dc2626,#b91c1c); }
          #lockRequestBar.lock-owned .status-label { font-weight:700; letter-spacing:.5px; }
          #lockRequestBar.lock-same-owner { background:linear-gradient(90deg,#dc2626,#ef4444,#f87171); }
          #lockRequestBar.lock-same-owner .status-label { font-weight:700; letter-spacing:.5px; }
          #lockRequestBar .spectator-note { opacity:.85; }
          #lockDiagModal { display:none; position:fixed; inset:0; background:rgba(0,0,0,.85); z-index:10001; align-items:center; justify-content:center; animation:fadeIn .2s ease; }
          #lockDiagModal.visible { display:flex; }
          #lockDiagContent { background:#1a1a2e; border:2px solid #6d28d9; border-radius:12px; width:90%; max-width:900px; max-height:90vh; overflow:auto; color:#e0e0e0; box-shadow:0 20px 60px rgba(0,0,0,.8); }
          #lockDiagContent h3 { background:linear-gradient(90deg,#6d28d9,#8b5cf6); color:#fff; padding:16px 20px; margin:0; font-size:18px; font-weight:700; border-radius:10px 10px 0 0; display:flex; justify-content:space-between; align-items:center; }
          #lockDiagContent h3 button { background:rgba(255,255,255,.2); border:none; color:#fff; padding:4px 12px; border-radius:6px; cursor:pointer; font-size:13px; }
          #lockDiagContent h3 button:hover { background:rgba(255,255,255,.35); }
          .diag-section { padding:16px 20px; border-bottom:1px solid #333; }
          .diag-section h4 { color:#a855f7; font-size:15px; font-weight:600; margin:0 0 10px; display:flex; align-items:center; gap:8px; }
          .diag-grid { display:grid; grid-template-columns:180px 1fr; gap:8px 16px; font-size:13px; }
          .diag-label { color:#9ca3af; font-weight:600; }
          .diag-value { color:#fff; font-family:monospace; word-break:break-all; }
          .diag-value.good { color:#10b981; }
          .diag-value.bad { color:#ef4444; }
          .diag-value.warn { color:#f59e0b; }
          .diag-events { max-height:200px; overflow-y:auto; background:#0f0f1e; border:1px solid #444; border-radius:6px; padding:10px; font-family:monospace; font-size:12px; }
          .diag-event { padding:4px 0; border-bottom:1px solid #2a2a3e; }
          .diag-event:last-child { border:none; }
          .diag-event-time { color:#6b7280; }
          .diag-event-type { color:#a855f7; font-weight:600; }
          .diag-actions { display:flex; gap:10px; padding:16px 20px; background:#0f0f1e; border-radius:0 0 10px 10px; }
          .diag-actions button { flex:1; padding:10px; border:none; border-radius:6px; font-weight:600; cursor:pointer; transition:all .2s; }
          .diag-actions .btn-copy { background:#6d28d9; color:#fff; }
          .diag-actions .btn-copy:hover { background:#7c3aed; }
          .diag-actions .btn-refresh { background:#8b5cf6; color:#fff; }
          .diag-actions .btn-refresh:hover { background:#a855f7; }
          .diag-actions .btn-close { background:#374151; color:#fff; }
          .diag-actions .btn-close:hover { background:#4b5563; }
        `; document.head.appendChild(style);
        const bar=document.createElement('div'); bar.id='lockRequestBar'; bar.innerHTML=`
          <div class="left d-flex align-items-center flex-wrap">
            <span class="status-label mr-3">Watching Only</span>
            <span class="spectator-note">View live updates; editing requires lock.</span>
            <span id="lockRequestCountdown" class="ml-3 d-none"></span>
          </div>
          <div class="right d-flex align-items-center" style="gap:10px;">
            <button id="lockRequestBtn" type="button">Request Lock</button>
            <button id="lockCancelRequestBtn" type="button" class="d-none">Cancel</button>
          </div>`; document.body.appendChild(bar);
        
        // Inject diagnostic modal
        const diagModal=document.createElement('div'); diagModal.id='lockDiagModal';
        diagModal.innerHTML=`<div id="lockDiagContent"><h3><span><i class="fas fa-stethoscope"></i> Lock System Diagnostics</span><button onclick="refreshDiagnostics()">Refresh</button></h3><div id="lockDiagBody">Loading...</div><div class="diag-actions"><button class="btn-copy" onclick="copyDiagnostics()"><i class="fas fa-copy"></i> Copy All</button><button class="btn-refresh" onclick="refreshDiagnostics()"><i class="fas fa-sync"></i> Refresh</button><button class="btn-close" onclick="hideLockDiagnostic()"><i class="fas fa-times"></i> Close</button></div></div>`;
        document.body.appendChild(diagModal);
        diagModal.addEventListener('click', (e)=>{ if(e.target===diagModal) hideLockDiagnostic(); });
      })();

      // Utility
      function updateBadge(state, info){
        if(!badgeEl) return; badgeEl.classList.remove('pulse','lock-badge--mine','lock-badge--other','lock-badge--unlocked','lock-badge--conflict');
        let text='UNLOCKED', cls='lock-badge--unlocked';
        switch(state){ case 'acquired': case 'alive': text='LOCKED'; cls='lock-badge--mine'; break; case 'blocked': text='LOCKED BY '+(info && info.holder_name ? info.holder_name.toUpperCase():'OTHER'); cls='lock-badge--other'; break; case 'lost': text='EXPIRED'; cls='lock-badge--conflict'; break; case 'released': text='UNLOCKED'; cls='lock-badge--unlocked'; }
        badgeEl.textContent=text; badgeEl.title=text; badgeEl.dataset.state=text; badgeEl.classList.add(cls,'pulse'); if(announceEl) announceEl.textContent='Lock status: '+text;
      }
      function setDisabled(disabled){ actionableSelectors.forEach(sel=>{ document.querySelectorAll(sel).forEach(btn=>{ btn.disabled=!!disabled; btn.classList.toggle('disabled', !!disabled); }); }); }

      // Spectator state handling (no persistence - fresh check every load)
      const state={ mode:'checking', countdownTimer:null, requestEndsAt:null, requestDurationSec:60, restored:false, sameOwner:false };
      function setSpectatorUi(on){ const body=document.body; if(on){ body.classList.add(SPECTATOR_CLASS); pageContainer&&pageContainer.classList.add('spectator-blur-wrap'); } else { body.classList.remove(SPECTATOR_CLASS); pageContainer&&pageContainer.classList.remove('spectator-blur-wrap'); } }
      function applyMode(){ const bar=document.getElementById('lockRequestBar'); if(!bar) return; const reqBtn=document.getElementById('lockRequestBtn'); const cancelBtn=document.getElementById('lockCancelRequestBtn'); const cd=document.getElementById('lockRequestCountdown');
        if(state.mode==='owning'){ bar.classList.remove('visible','lock-same-owner'); bar.classList.add('lock-owned'); bar.querySelector('.status-label').textContent='You Have Control'; reqBtn.textContent='Release'; reqBtn.classList.remove('d-none'); cancelBtn.classList.add('d-none'); cd.classList.add('d-none'); setSpectatorUi(false); setDisabled(false); }
        else if(state.mode==='checking'){ bar.classList.remove('visible','lock-owned','lock-same-owner'); bar.querySelector('.status-label').textContent='Loading...'; reqBtn.classList.add('d-none'); cancelBtn.classList.add('d-none'); cd.classList.add('d-none'); setSpectatorUi(false); setDisabled(false); }
        else if(state.mode==='acquiring'){ bar.classList.remove('visible','lock-owned','lock-same-owner'); bar.querySelector('.status-label').textContent='Acquiring Control...'; reqBtn.classList.add('d-none'); cancelBtn.classList.add('d-none'); cd.classList.add('d-none'); setSpectatorUi(false); setDisabled(false); }
        else if(state.mode==='requesting'){ bar.classList.add('visible'); bar.classList.remove('lock-owned'); bar.classList.toggle('lock-same-owner', state.sameOwner); bar.querySelector('.status-label').textContent= state.sameOwner ? 'Your Other Tab Has Control' : 'Requesting Access'; reqBtn.classList.add('d-none'); cancelBtn.classList.remove('d-none'); cd.classList.remove('d-none'); setSpectatorUi(true); setDisabled(true); }
        else { bar.classList.add('visible'); bar.classList.remove('lock-owned'); bar.classList.toggle('lock-same-owner', state.sameOwner); bar.querySelector('.status-label').textContent= state.sameOwner ? 'Your Other Tab Has Control' : 'Another User Has Control'; reqBtn.textContent= state.sameOwner ? 'Take Control' : 'Request Lock'; reqBtn.classList.remove('d-none'); cancelBtn.classList.add('d-none'); cd.classList.add('d-none'); setSpectatorUi(true); setDisabled(true); }
      }
      function startCountdown(seconds){ const cd=document.getElementById('lockRequestCountdown'); state.requestEndsAt=Date.now()+seconds*1000; cd.classList.remove('d-none'); if(state.countdownTimer) clearInterval(state.countdownTimer); state.countdownTimer=setInterval(()=>{ const r=Math.max(0,Math.ceil((state.requestEndsAt-Date.now())/1000)); cd.textContent=r+'s'; if(r<=0){ clearInterval(state.countdownTimer); state.countdownTimer=null; finalizeRequestTimeout(); } },1000); }
      function finalizeRequestTimeout(){ if(state.mode==='requesting'){ if(state.countdownTimer){ clearInterval(state.countdownTimer); state.countdownTimer=null; } state.requestEndsAt=null; state.mode='spectator'; applyMode(); } }

      // Modal (future extension - acceptance handshake placeholder)
      function openHandoverModal(onAccept,onDecline){ if(document.getElementById('lockHandoverModalBackdrop')) return; const bd=document.createElement('div'); bd.id='lockHandoverModalBackdrop'; bd.innerHTML=`<div id="lockHandoverModal" role="dialog" aria-modal="true"><h3>Lock Handover Request</h3><p>Another user is requesting control of this transfer. Accept?</p><div class="actions"><button type="button" id="lockDeclineBtn" style="background:#4c1d95; border:1px solid #6d28d9;">Decline</button><button type="button" id="lockAcceptBtn" style="background:#dc2626; border:1px solid #f87171;">Accept</button></div></div>`; document.body.appendChild(bd); bd.querySelector('#lockAcceptBtn').onclick=()=>{ onAccept&&onAccept(); bd.remove(); }; bd.querySelector('#lockDeclineBtn').onclick=()=>{ onDecline&&onDecline(); bd.remove(); }; }

  // NOTE: Inline legacy injection block trimmed (lock scripts now loaded via external tags below).
