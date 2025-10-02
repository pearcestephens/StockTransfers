<?php
/**
 * CIS — Transfers » Stock » Pack (View)
 *
 * Expected variables (from pack.php):
 * - $DOCUMENT_ROOT
 * - $txId, $transfer, $items
 * - $fromLbl, $toLbl, $fromOutlet, $toOutlet
 * - $fromDisplay, $toDisplay
 * - $isPackaged
 * - $sourceStockMap
 * - $plannedSum, $countedSum, $diff, $diffLabel, $accuracy
 * - $bootPayload, $assetVer
 */

// Fallback helper for product tag generation (TRANSFERID-LINENUM)
if (!function_exists('tfx_product_tag')) {
  function tfx_product_tag(string $transferId, int $index): string {
    return preg_replace('#[^\d]#', '', $transferId) . '-' . max(1, $index);
  }
}
?>
<body class="app header-fixed sidebar-fixed aside-menu-fixed sidebar-lg-show">
  <main class="app-body ">
    <?php include $DOCUMENT_ROOT . '/assets/template/sidemenu.php'; ?>

    <main class="main" id="main"
      data-page="transfer-pack"
      data-txid="<?= (int)$txId ?>"
      data-txstring="<?= htmlspecialchars($txStringId, ENT_QUOTES) ?>">
      <?php
      // Breadcrumbs (kept as-is so your component works)
      $breadcrumb_config = [
        'active_page' => 'Pack',
        'show_transfer_id' => true,
        'transfer_id' => $txId
      ];
      include __DIR__ . '/views/components/breadcrumb.php';
      ?>

      <div class="container-fluid animated fadeIn" id="packPageContainer">
        <div class="pack-white-container">

          <!-- Enhanced Store Transfer Header -->
          <div class="card mb-4 overflow-hidden">
            <!-- Main Purple Header -->
            <div class="card-header text-white position-relative" style="background: linear-gradient(135deg, #8B5CF6 0%, #A855F7 25%, #C084FC 50%, #7C3AED 100%); border-bottom: none; min-height: 85px; box-shadow: 0 4px 15px rgba(139, 92, 246, 0.3);">
              <!-- Background Pattern -->
              <div class="position-absolute" style="top: 0; right: 0; opacity: 0.1; font-size: 8rem; line-height: 1; pointer-events: none;">
                <i class="fas fa-truck"></i>
              </div>
              
              <div class="d-flex justify-content-between align-items-center h-100">
                <div class="flex-grow-1">
                  <div class="d-flex align-items-center mb-2">
                    <i class="fas fa-exchange-alt mr-3" style="font-size: 1.5rem; color: rgba(255,255,255,0.9);"></i>
                    <div>
                      <h3 class="mb-0" style="font-weight: 700; color: white; text-shadow: 0 2px 4px rgba(0,0,0,0.3); font-size: 1.4rem;">
                        Store Transfer #<?= (int)$txId ?>
                        <span class="badge ml-2" id="lockStatusBadge" style="font-size:0.5em; background: rgba(220,53,69,0.85); color: #fff; border: 1px solid rgba(220,53,69,1); text-shadow: none; padding: 2px 6px;" title="<?= $lockStatus['has_lock'] ? 'Locked by you' : ($lockStatus['is_locked_by_other'] ? ('Locked by '.htmlspecialchars($lockStatus['holder_name']??'another user', ENT_QUOTES)) : 'No active lock') ?>">
                          <?php
                          // Dynamic badge label rules:
                          // - If you have the lock: LOCKED
                          // - If another user holds it: LOCKED (NAME)
                          // - If no active lock: UNLOCKED
                          if ($lockStatus['has_lock']) {
                              echo 'LOCKED';
                          } elseif ($lockStatus['is_locked_by_other']) {
                              $hn = htmlspecialchars($lockStatus['holder_name'] ?? 'USER', ENT_QUOTES);
                              echo 'LOCKED (' . $hn . ')';
                          } else {
                              echo 'UNLOCKED';
                          }
                          ?>
                        </span>
                        <span id="headerTimer" style="font-family: 'Segoe UI', sans-serif; font-weight: 600; font-size: 0.5em; color: rgba(255,255,255,0.8); margin-left: 8px;">
                          2h 45m
                        </span>
                      </h3>
                      <div class="d-flex align-items-center mt-1">
                        <span style="font-size: 0.9rem; color: rgba(255,255,255,0.85); font-weight: 500;">
                          <strong><?= htmlspecialchars($fromLbl, ENT_QUOTES) ?></strong>
                        </span>
                        <i class="fas fa-long-arrow-alt-right mx-2" style="color: rgba(255,255,255,0.7); font-size: 1.1rem;"></i>
                        <span style="font-size: 1.1rem; color: white; font-weight: 700; text-shadow: 0 1px 2px rgba(0,0,0,0.3);">
                          <i class="fas fa-map-marker-alt mr-1" style="color: #FFE066;"></i>
                          <?= htmlspecialchars($toLbl, ENT_QUOTES) ?>
                        </span>
                      </div>
                    </div>
                  </div>
                </div>
                
                <!-- Right Side Controls -->
                <div class="d-flex align-items-center">
                  <!-- Auto-Save Status (placeholder if needed) -->
                  <!-- Diagnostic Button -->
                  <button class="btn btn-sm mr-3" id="lockDiagnosticBtn" onclick="showLockDiagnostic()" 
                          style="background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.4); padding: 6px 10px; border-radius: 8px;" 
                          title="System Diagnostic">
                    <i class="fas fa-cog" style="font-size: 0.9rem;"></i>
                  </button>
                  
                  <!-- Test Lock Button -->
                  <button class="btn btn-sm mr-3" onclick="window.packSystem?.updateLockStatusDisplay?.()" 
                          style="background: rgba(255,255,255,0.15); color: white; border: 1px solid rgba(255,255,255,0.3); padding: 6px 10px; border-radius: 8px;" 
                          title="Update Lock Status">
                    <i class="fas fa-sync" style="font-size: 0.9rem;"></i>
                  </button>
                  
                  <!-- Add Product Button -->
                  <button type="button" id="headerAddProductBtn" class="btn btn-light btn-sm" 
                          style="font-weight: 600; padding: 8px 16px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.2);" 
                          data-toggle="modal" data-target="#addProdModal">
                    <i class="fas fa-plus mr-1"></i>Add Product
                  </button>
                </div>
              </div>
            </div>
            
            <!-- Secondary White Header Bar -->
            <div class="bg-white border-bottom" style="min-height: 50px; padding: 12px 24px;">
              <div class="d-flex justify-content-between align-items-center h-100">
                <!-- Enhanced Destination Info with Rich Outlet Data -->
                <?php if (!empty($toOutlet) && is_array($toOutlet)): ?>
                  <div class="d-flex align-items-center flex-grow-1">
                    <!-- Store Name & Address -->
                    <div class="mr-4">
                      <div class="d-flex align-items-center mb-1">
                        <i class="fas fa-store text-info mr-2" style="font-size: 1.1rem;"></i>
                        <span class="font-weight-bold text-dark" style="font-size: 1rem;">
                          <?= htmlspecialchars($toOutlet['name'] ?? $toLbl, ENT_QUOTES) ?>
                        </span>
                        <?php if (!empty($toOutlet['google_review_rating'])): ?>
                          <span class="badge badge-success ml-2" style="font-size: 0.7rem;">
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
                    </div>
                    
                    <!-- Contact Info -->
                    <?php if (!empty($toOutlet['physical_phone_number'])): ?>
                      <div class="mr-4">
                        <a href="tel:<?= htmlspecialchars($toOutlet['physical_phone_number'], ENT_QUOTES) ?>" 
                           class="btn btn-sm btn-outline-success" style="padding: 4px 12px; font-size: 0.85rem;">
                          <i class="fas fa-phone mr-1"></i>
                          <?= htmlspecialchars($toOutlet['physical_phone_number'], ENT_QUOTES) ?>
                        </a>
                      </div>
                    <?php endif; ?>
                    
                    <!-- Quick Actions -->
                    <div class="d-flex align-items-center">
                      <?php if (!empty($toOutlet['outlet_lat']) && !empty($toOutlet['outlet_long'])): ?>
                        <a href="https://www.google.com/maps/dir/?api=1&destination=<?= urlencode($toOutlet['outlet_lat']) ?>,<?= urlencode($toOutlet['outlet_long']) ?>"
                           target="_blank" class="btn btn-sm btn-outline-primary mr-2" style="padding: 4px 10px; font-size: 0.8rem;">
                          <i class="fas fa-directions mr-1"></i>Directions
                        </a>
                      <?php endif; ?>
                      <?php if (!empty($toOutlet['email'])): ?>
                        <a href="mailto:<?= htmlspecialchars($toOutlet['email'], ENT_QUOTES) ?>" 
                           class="btn btn-sm btn-outline-info" style="padding: 4px 10px; font-size: 0.8rem;">
                          <i class="fas fa-envelope mr-1"></i>Email
                        </a>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php else: ?>
                  <div class="text-muted" style="font-size: 0.9rem;">
                    <i class="fas fa-info-circle mr-2"></i>
                    Transfer to: <strong><?= htmlspecialchars($toLbl, ENT_QUOTES) ?></strong>
                  </div>
                <?php endif; ?>
                
                <!-- Session Info & Stats -->
                <div class="d-flex align-items-center">
                  <?php if (!empty($toOutlet['outlet_lat']) && !empty($toOutlet['outlet_long'])): ?>
                    <small class="text-muted mr-3" style="font-size: 0.75rem;">
                      <i class="fas fa-map-pin mr-1"></i>
                      GPS: <?= number_format((float)$toOutlet['outlet_lat'], 4) ?>, <?= number_format((float)$toOutlet['outlet_long'], 4) ?>
                    </small>
                  <?php endif; ?>
                  <small class="text-muted" style="font-size: 0.8rem;">
                    <i class="fas fa-clock mr-1"></i>
                    Session tracking enabled
                  </small>
                </div>
              </div>
            </div>
          </div><!-- /enhanced header -->

          <!-- (Duplicate Destination Store Details block removed to reduce redundancy) -->

          <!-- Items Table -->
          <section class="card mb-4">
            <!-- Lock Overlay (shown when locked by another user) -->
            <div id="lockOverlay" class="alert alert-warning" style="display: none; margin-bottom: 1rem; border-left: 4px solid #ffc107;">
              <i class="fa fa-lock mr-2"></i>Transfer is locked by another user.
            </div>
            
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
              <div class="d-flex align-items-center">
                <!-- Autosave Status Pill -->
                <div id="autosavePill" class="autosave-pill status-idle" style="display:inline-flex; align-items:center; padding:4px 10px; border-radius:15px; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.5px; background-color:#6c757d; color:white;">
                  <span class="pill-icon" style="width:6px; height:6px; border-radius:50%; margin-right:6px; background-color:currentColor;"></span>
                  <span id="autosavePillText">Idle</span>
                </div>
                <span id="autosaveStatus" class="ml-2 small text-muted" style="font-size:11px;">&nbsp;</span>
                <span id="autosaveLastSaved" class="ml-2 small text-muted" style="font-size:11px;"></span>
                <div id="lastSaveTime" style="font-size: 0.75rem; color: #6c757d; margin-left: 8px; min-height: 14px;">
                  
                </div>
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
                        $weightSource = (string)_first($item['weight_source'] ?? null, (isset($item['avg_weight_grams'])||isset($item['product_weight_grams'])||isset($item['weight_g'])||isset($item['unit_weight_g'])) ? 'product' : (isset($item['category_avg_weight_grams'])||isset($item['category_weight_grams'])||isset($item['cat_weight_g']) ? 'category' : 'default'));
                        $rowWeightG  = $unitWeightG > 0 ? $unitWeightG * max($countedQty, 0) : 0; // counted weight basis
                        $totalCountedWeightG += $rowWeightG;
                    ?>
                      <tr id="item-row-<?= $itemId ?>"
                          class="pack-item-row"
                          data-item-id="<?= $itemId ?>"
                          data-product-id="<?= htmlspecialchars($productId, ENT_QUOTES) ?>"
                          data-planned-qty="<?= $plannedQty ?>"
                          data-source-stock="<?= $stockQty ?>"
                          data-unit-weight-g="<?= $unitWeightG ?>">
                        <td class="text-center align-middle" style="width:50px; padding: 3px;">
                          <?php 
                            // Debug: Show what image fields are available
                            $availableFields = array_keys($item);
                            $imageFields = array_filter($availableFields, function($key) {
                              return strpos(strtolower($key), 'image') !== false;
                            });
                            
                            // Try multiple possible image field names from vend_products
                            $imageUrl = '';
                            $possibleImageFields = [
                              'image_url',
                              'image_thumbnail_url', 
                              'product_image_url',
                              'vend_image_url',
                              'thumbnail_url',
                              'image'
                            ];
                            
                            foreach ($possibleImageFields as $field) {
                              if (!empty($item[$field])) {
                                $imageUrl = $item[$field];
                                break;
                              }
                            }
                            
                            // If still no image, try to get from the main product data
                            if (empty($imageUrl) && !empty($productId)) {
                              // This might need to be populated in the main pack.php query
                              $imageUrl = $item['image_url'] ?? '';
                            }
                            
                            // Check if it's a valid image URL (not a placeholder)
                            $hasImage = !empty($imageUrl) && 
                                       $imageUrl !== 'https://secure.vendhq.com/images/placeholder/product/no-image-white-original.png' &&
                                       filter_var($imageUrl, FILTER_VALIDATE_URL);
                            
                            // Debug output (remove in production)
                            if (true) { // Set to true for debugging
                              echo "<!-- Debug for item {$itemId}: ";
                              echo "Image fields: " . implode(', ', $imageFields) . " | ";
                              echo "Image URL: " . htmlspecialchars($imageUrl) . " | ";
                              echo "Has Image: " . ($hasImage ? 'YES' : 'NO') . " -->";
                            }
                          ?>
                          <?php if ($hasImage): ?>
                            <img src="<?= htmlspecialchars($imageUrl, ENT_QUOTES) ?>" 
                                 class="product-thumb" 
                                 style="width: 45px; height: 45px; object-fit: cover; border-radius: 6px; border: 2px solid #dee2e6; transition: all 0.3s ease; cursor: pointer;"
                                 onclick="showProductImageModal(this.src, '<?= htmlspecialchars($item['product_name'] ?? 'Product Image', ENT_QUOTES) ?>')"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                                 title="Click to view larger image">
                          <?php else: ?>
                            <!-- Debug: Show why no image (remove in production) -->
                            <?php if (true): // Set to true for debugging ?>
                              <div style="font-size: 8px; color: red;">No img: <?= htmlspecialchars(substr($imageUrl, 0, 20)) ?></div>
                            <?php endif; ?>
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
                        <td class="text-center align-middle mono" title="Product Tag"><?= htmlspecialchars(tfx_product_tag((string)$txId, $rowNum), ENT_QUOTES) ?></td>
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
            <script>
            // Dynamic weight recalculation script
            (function(){
              function recalcWeights(){
                let totalG = 0;
                let srcCounts = {product:0, category:0, default:0};
                const rows = document.querySelectorAll('#transferItemsTable tbody tr');
                rows.forEach(tr => {
                  const unitG = parseInt(tr.getAttribute('data-unit-weight-g')||'0',10) || 0;
                  const input = tr.querySelector('.qty-input');
                  if(!input || unitG <= 0) return;
                  const qty = parseInt(input.value || input.getAttribute('value') || '0', 10) || 0;
                  const rowWeightG = unitG * qty;
                  const span = tr.querySelector('.row-weight');
                  if(span){
                    if(qty > 0){ span.textContent = (rowWeightG/1000).toFixed(rowWeightG >= 100000 ? 2 : 3) + 'kg'; }
                    else { span.textContent = '—'; }
                    span.setAttribute('data-row-weight-g', rowWeightG);
                    const src = span.getAttribute('data-weight-source');
                    if(src && srcCounts[src] !== undefined) srcCounts[src]++;
                  }
                  totalG += rowWeightG;
                });
                const totalSpan = document.getElementById('totalWeightFooterKgValue');
                if(totalSpan){ totalSpan.textContent = (totalG/1000).toFixed(2); }
                const breakdownEl = document.getElementById('weightSourceBreakdown');
                if(breakdownEl){
                  const totalLines = Object.values(srcCounts).reduce((a,b)=>a+b,0);
                  if(totalLines>0){
                    const pct = k=>((srcCounts[k]/totalLines)*100).toFixed(0)+'%';
                    breakdownEl.textContent = `P ${srcCounts.product} (${pct('product')}) • C ${srcCounts.category} (${pct('category')}) • D ${srcCounts.default} (${pct('default')})`;
                  } else breakdownEl.textContent='';
                }
              }
              document.addEventListener('input', (e)=>{ if(e.target && e.target.classList.contains('qty-input')) recalcWeights(); });
              if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', recalcWeights); else setTimeout(recalcWeights, 50);
              window.recalcTransferWeights = recalcWeights; // optional global hook

              // Keyboard navigation for qty inputs (Arrow keys + Enter)
              function focusNext(current, delta){
                const inputs = Array.from(document.querySelectorAll('#transferItemsTable .qty-input'));
                const idx = inputs.indexOf(current);
                if(idx === -1) return;
                const next = inputs[idx + delta];
                if(next){ next.focus(); next.select && next.select(); }
              }
              document.addEventListener('keydown', function(e){
                const t = e.target;
                if(!t || !t.classList || !t.classList.contains('qty-input')) return;
                if(e.key === 'ArrowDown'){ e.preventDefault(); focusNext(t, +1); }
                else if(e.key === 'ArrowUp'){ e.preventDefault(); focusNext(t, -1); }
                else if(e.key === 'Enter'){ e.preventDefault(); focusNext(t, +1); }
              });

              // Diff announcement (accessibility)
              const announceEl = document.getElementById('lineAnnouncement');
              let lastDiffMap = new Map();
              function announceChanges(){
                const rows = document.querySelectorAll('#transferItemsTable tbody tr');
                let messages=[];
                rows.forEach(tr=>{
                  const input=tr.querySelector('.qty-input');
                  const planned=parseInt(tr.getAttribute('data-planned-qty')||'0',10)||0;
                  if(!input) return;
                  const counted=parseInt(input.value||'0',10)||0;
                  const rid=tr.getAttribute('data-item-id')||'';
                  const key=rid;
                  const prev=lastDiffMap.get(key);
                  const state = counted===planned ? 'match' : (counted>planned ? 'over' : 'under');
                  if(prev && prev!==state){ messages.push(`Line ${rid}: now ${state}`); }
                  lastDiffMap.set(key,state);
                });
                if(messages.length && announceEl){ announceEl.textContent = messages.join('. '); }
              }
              document.addEventListener('input', (e)=>{ if(e.target && e.target.classList.contains('qty-input')) announceChanges(); });
              setTimeout(announceChanges, 1200);
            })();
            </script>

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
        <!-- Lock Request Toolbar (hidden by default; shown when locked out) -->
        <div id="lockRequestToolbar" class="lock-request-toolbar" style="display:none;">
          <div class="toolbar-inner">
            <span class="lock-msg"><i class="fa fa-lock mr-2"></i><span id="lockToolbarStatusText">This transfer is locked by <span class="lock-holder-name" id="lockHolderName">another user</span>.</span></span>
            <button type="button" id="requestLockBtn" class="btn btn-sm btn-light ml-2">
              <i class="fa fa-key mr-1"></i> Request Lock
            </button>
            <button type="button" id="retryAcquireBtn" class="btn btn-sm btn-outline-warning ml-1">
              <i class="fa fa-sync mr-1"></i> Retry
            </button>
            <button type="button" id="forceRefreshBtn" class="btn btn-sm btn-outline-light ml-1">
              <i class="fa fa-redo mr-1"></i> Refresh
            </button>
          </div>
        </div>
      </div><!-- /.container-fluid -->
    </main>
  </div>

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
    
    // Product Image Modal Function
    function showProductImageModal(imageSrc, productName) {
      // Remove existing modal if present
      const existingModal = document.getElementById('productImageModal');
      if (existingModal) {
        existingModal.remove();
      }
      
      // Create modal backdrop
      const modal = document.createElement('div');
      modal.id = 'productImageModal';
      modal.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.85);
        z-index: 10000;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        animation: fadeIn 0.3s ease;
      `;
      
      // Create modal content
      const content = document.createElement('div');
      content.style.cssText = `
        max-width: 90vw;
        max-height: 90vh;
        display: flex;
        flex-direction: column;
        align-items: center;
        cursor: default;
      `;
      
      // Create image
      const img = document.createElement('img');
      img.src = imageSrc;
      img.style.cssText = `
        max-width: 100%;
        max-height: 80vh;
        object-fit: contain;
        border-radius: 8px;
        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.5);
        animation: zoomIn 0.3s ease;
      `;
      
      // Create title
      const title = document.createElement('div');
      title.textContent = productName;
      title.style.cssText = `
        color: white;
        margin-top: 15px;
        font-size: 18px;
        font-weight: 600;
        text-align: center;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.8);
      `;
      
      // Create close hint
      const closeHint = document.createElement('div');
      closeHint.textContent = 'Click anywhere to close';
      closeHint.style.cssText = `
        color: rgba(255, 255, 255, 0.7);
        margin-top: 8px;
        font-size: 14px;
        text-align: center;
      `;
      
      // Add CSS animations
      const style = document.createElement('style');
      style.textContent = `
        @keyframes fadeIn {
          from { opacity: 0; }
          to { opacity: 1; }
        }
        @keyframes zoomIn {
          from { transform: scale(0.8); opacity: 0; }
          to { transform: scale(1); opacity: 1; }
        }
      `;
      document.head.appendChild(style);
      
      // Assemble modal
      content.appendChild(img);
      content.appendChild(title);
      content.appendChild(closeHint);
      modal.appendChild(content);
      document.body.appendChild(modal);
      
      // Close on click
      modal.onclick = function(e) {
        if (e.target === modal) {
          modal.style.animation = 'fadeOut 0.3s ease';
          setTimeout(() => modal.remove(), 300);
        }
      };
      
      // Close on escape key
      const handleEscape = function(e) {
        if (e.key === 'Escape') {
          modal.style.animation = 'fadeOut 0.3s ease';
          setTimeout(() => modal.remove(), 300);
          document.removeEventListener('keydown', handleEscape);
        }
      };
      document.addEventListener('keydown', handleEscape);
      
      // Prevent content click from closing
      content.onclick = function(e) {
        e.stopPropagation();
      };
      
      // Add fadeOut animation
      style.textContent += `
        @keyframes fadeOut {
          from { opacity: 1; }
          to { opacity: 0; }
        }
      `;
    }
  </script>

  <!-- Unified CSS & JS (clean architecture) -->
  <link rel="stylesheet" href="/modules/transfers/stock/assets/css/pack-unified.css?v=<?= (int)$assetVer ?>">
  <!-- Ensure Universal Lock base loads BEFORE pack-lock.js so PackLockSystem defines immediately -->
  <script src="/modules/transfers/stock/assets/js/00-universal-lock-system.js?v=<?= (int)$assetVer ?>"></script>
  <script src="/modules/transfers/stock/assets/js/pack-lock.js?v=<?= (int)$assetVer ?>"></script>
  <script src="/modules/transfers/stock/assets/js/pack-unified.js?v=<?= (int)$assetVer ?>" defer></script>
  <script src="/modules/transfers/stock/assets/js/pack-autosave.js?v=<?= (int)$assetVer ?>" defer></script>
  <!-- Modular ES build (provides mixin system); safe to include alongside shim -->
  <script type="module" src="/modules/transfers/stock/assets/js/pack-bootstrap.module.js?v=<?= (int)$assetVer ?>"></script>
  <script>
    // Inline bridge for autosave status spans (lightweight, idempotent)
    (function(){
      function set(txt){ var el=document.getElementById('autosaveStatus'); if(el) el.textContent=txt||''; }
      function setLast(ts){ var el=document.getElementById('autosaveLastSaved'); if(!el) return; var d = ts? new Date(ts): new Date(); if(!isNaN(d.getTime())) el.textContent='Last saved: '+d.toLocaleTimeString(); }
      document.addEventListener('packautosave:state', function(ev){
        var st=ev.detail && ev.detail.state; var p=ev.detail && ev.detail.payload; if(st==='saving') set('Saving…'); else if(st==='saved'){ set('Saved'); setLast(p && (p.saved_at||p.savedAt)); } else if(st==='error') set('Retry'); else if(st==='noop'){ /* keep prior */ } });
    })();
  </script>

  <!-- Pack System Initialization -->
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      /* =============================================================
       * LOCK REQUEST COUNTDOWN + DECISION MODAL INTEGRATION
       * ============================================================= */
  const TRANSFER_ID = window.DISPATCH_BOOT?.transfer_id;
  const SESSION_ID = window.DISPATCH_BOOT?.session_id || (window.__PACK_SESSION = window.__PACK_SESSION || (Math.random().toString(36).slice(2)));
      const USER_ID = window.DISPATCH_BOOT?.user_id;
      const POLL_INTERVAL_MS = 3000;
      let pollTimer = null;
      let countdownTimer = null;
      let latestRequest = null;
      const toolbar = document.getElementById('lockRequestToolbar');
      const countdownEl = document.getElementById('lockCountdown');
      const decisionModalEl = document.getElementById('lockRequestDecisionModal');
      const diagnosticsPanel = document.getElementById('lockDiagnosticsPanel');
      const diagPre = diagnosticsPanel ? diagnosticsPanel.querySelector('pre') : null;

      // ------------------------------------------------------------------
      // Tab Leadership & BroadcastChannel (single SSE connection pattern)
      // ------------------------------------------------------------------
      const LEADER_KEY = 'pack_lock_leader_'+TRANSFER_ID+'_'+USER_ID;
      const HEARTBEAT_MS = 3000;
      const LEADER_TIMEOUT_MS = 8000; // if heartbeat stale for >8s others can take over
      let isLeader = false; let heartbeatTimer = null; let takeoverCheckTimer = null;
      const bc = ('BroadcastChannel' in window) ? new BroadcastChannel('pack-lock-'+TRANSFER_ID+'-'+USER_ID) : null;

      function nowTs(){ return Date.now(); }
      function readLeader(){ try { return JSON.parse(localStorage.getItem(LEADER_KEY)||'{}'); } catch(e){ return {}; } }
      function writeLeader(payload){ localStorage.setItem(LEADER_KEY, JSON.stringify(payload)); }
      function claimLeadership(){ isLeader = true; writeLeader({ sid: SESSION_ID, ts: nowTs() }); startHeartbeat(); logDiag('Became leader (SSE active)'); initOrRestartSSE(); }
      function startHeartbeat(){ if(heartbeatTimer) clearInterval(heartbeatTimer); heartbeatTimer = setInterval(()=>{ if(!isLeader) return; writeLeader({ sid: SESSION_ID, ts: nowTs() }); }, HEARTBEAT_MS); }
      function ensureLeadership(){ const cur = readLeader(); if(!cur.sid || (nowTs()- (cur.ts||0)) > LEADER_TIMEOUT_MS){ claimLeadership(); } else if(cur.sid === SESSION_ID){ isLeader=true; startHeartbeat(); } else { isLeader=false; }
        if(!takeoverCheckTimer){ takeoverCheckTimer = setInterval(()=>{ if(isLeader) return; const c=readLeader(); if(!c.sid || (nowTs()- (c.ts||0)) > LEADER_TIMEOUT_MS){ claimLeadership(); } }, 2000); }
      }

      bc && bc.addEventListener('message', (ev)=>{ if(isLeader) return; const msg=ev.data||{}; if(msg.type==='lock-update'){ handleUpdate(msg.data, {source:'bc'}); } });

  function logDiag(msg,obj){ try { console.log('[LockFlow]',msg,obj||''); if(diagPre){ const line = '['+new Date().toLocaleTimeString()+'] '+msg+(obj? (' '+JSON.stringify(obj).slice(0,400)):''); diagPre.textContent = line + '\n' + diagPre.textContent; } } catch(e){} }

      function setToolbarState(state){ if(!toolbar) return; toolbar.dataset.state = state; }
  function secondsRemainingPairs(req){ if(!req) return {holder:null, requester:null}; const now=Date.now(); const holder=req.holder_deadline? Date.parse(req.holder_deadline): (req.grant_at? Date.parse(req.grant_at):null); const requester=req.requester_deadline? Date.parse(req.requester_deadline):null; return { holder: holder? Math.max(0, Math.floor((holder-now)/1000)) : null, requester: requester? Math.max(0, Math.floor((requester-now)/1000)) : null }; }
  function paintCountdown(pair){ if(!countdownEl) return; if(!pair || (pair.holder==null && pair.requester==null)){ countdownEl.textContent=''; return; } let txt=''; if(pair.requester!=null && pair.requester>0) txt += pair.requester+'s (you) '; if(pair.holder!=null) txt += '| '+pair.holder+'s holder'; countdownEl.textContent=txt.trim(); countdownEl.classList.toggle('urgent', pair.holder!==null && pair.holder<=3); }
      function clearTimers(){ if(pollTimer){ clearInterval(pollTimer); pollTimer=null;} if(countdownTimer){ clearInterval(countdownTimer); countdownTimer=null;} }
      function beginCountdown(){ clearInterval(countdownTimer); countdownTimer=setInterval(()=>{ if(!latestRequest){ paintCountdown(null); return; } const pair=secondsRemainingPairs(latestRequest); paintCountdown(pair); if(pair.holder===0){ logDiag('Holder deadline zero – awaiting auto-grant'); } },1000); }

      function showDecisionModal(req){ if(!decisionModalEl) return; const holderField = decisionModalEl.querySelector('[data-lock-holder]'); if(holderField){ holderField.textContent = (req.holder_name||req.holderName||'User'); } decisionModalEl.style.display='block'; document.body.classList.add('modal-open'); focusTrap(decisionModalEl); }
      function hideDecisionModal(){ if(!decisionModalEl) return; decisionModalEl.style.display='none'; document.body.classList.remove('modal-open'); releaseFocusTrap(); }

      // Simple focus trap
      let lastFocused = null; function focusTrap(container){ lastFocused=document.activeElement; const focusables = container.querySelectorAll('button,[href],input,select,textarea,[tabindex]:not([tabindex="-1"])'); const first=focusables[0]; const last=focusables[focusables.length-1]; function loop(e){ if(e.key==='Tab'){ if(e.shiftKey && document.activeElement===first){ e.preventDefault(); last.focus(); } else if(!e.shiftKey && document.activeElement===last){ e.preventDefault(); first.focus(); } } else if(e.key==='Escape'){ e.preventDefault(); /* block esc */ } } container.addEventListener('keydown', loop); container.__trapHandler = loop; if(first) setTimeout(()=>first.focus(),50); }
      function releaseFocusTrap(){ if(decisionModalEl && decisionModalEl.__trapHandler){ decisionModalEl.removeEventListener('keydown', decisionModalEl.__trapHandler); delete decisionModalEl.__trapHandler; } if(lastFocused) try{ lastFocused.focus(); }catch(e){} }

      let visibilityPaused=false;
      async function poll(){ if(!isLeader){ return; } if(document.hidden){ visibilityPaused=true; return; } visibilityPaused=false; try { const res = await fetch('/modules/transfers/stock/api/lock_request_poll.php?transfer_id='+encodeURIComponent(TRANSFER_ID), { credentials:'same-origin'}); const json = await res.json().catch(()=>({})); if(!json.success){ logDiag('Poll fail', json); return; } handleUpdate(json, {source:'poll'}); } catch(err){ logDiag('Poll error', err); }
      }

      function updateLockUI(lock, req){ try {
        const holderName = lock.holder_name||lock.holderName||req?.holder_name||'';
        const holderSpan = document.getElementById('lockHolderName'); if(holderSpan) holderSpan.textContent = holderName||'';
        const hasLock = !!lock.has_lock;
        document.body.classList.toggle('pack-locked-out', !hasLock);
        if(toolbar){ toolbar.style.display='block'; toolbar.classList.toggle('locked-by-other', !hasLock && holderName && holderName!==window.DISPATCH_BOOT?.user_name); toolbar.classList.toggle('lock-owned-by-me', hasLock); }
        // Disable editing inputs when not holding lock (read-only view) but keep visual clarity
        document.querySelectorAll('#transferItemsTableWrapper input.qty-input').forEach(inp=>{ inp.readOnly = !hasLock; inp.classList.toggle('readonly-no-lock', !hasLock); });
        // Provide accessibility notice once when locked-out
        if(!hasLock && !document.getElementById('lockAriaNote')){
          const note = document.createElement('div');
          note.id='lockAriaNote';
            note.className='sr-only';
          note.textContent='Read only view - another user holds the lock.';
          document.body.appendChild(note);
        }
      } catch(e){ console.warn('updateLockUI failed', e); }
      }

      // Decision actions
      document.addEventListener('click', function(ev){ const t=ev.target; if(t.matches('[data-lock-decision]')){ ev.preventDefault(); const decision=t.getAttribute('data-lock-decision'); if(!latestRequest){ return; } decideRequest(latestRequest.request_id, decision==='approve'); } if(t.matches('#lockDiagToggle')){ ev.preventDefault(); if(!diagnosticsPanel) return; diagnosticsPanel.style.display = diagnosticsPanel.style.display==='none'?'block':'none'; } });

      async function decideRequest(id, approve){ try { const res = await fetch('/modules/transfers/stock/api/lock_request_decide.php',{ method:'POST', headers:{'Content-Type':'application/json'}, credentials:'same-origin', body: JSON.stringify({ request_id: id, decision: approve? 'grant':'decline' }) }); const json = await res.json().catch(()=>({})); logDiag('Decision response', json); if(json.success){ hideDecisionModal(); await poll(); } else { window.PackToast?.error('Decision failed'); }
      } catch(err){ logDiag('Decision error', err); }
      }

      // Start polling immediately if there's a lock holder different from us or we do not own lock
      let esRef=null;
      function initOrRestartSSE(){ if(!isLeader) return false; if(esRef){ try{ esRef.close(); }catch(e){} }
        if(!window.EventSource || !TRANSFER_ID) return false;
        try {
          esRef = new EventSource('/modules/transfers/stock/api/lock_request_events.php?transfer_id='+encodeURIComponent(TRANSFER_ID));
          esRef.addEventListener('lock', ev => { if(document.hidden) return; const data = JSON.parse(ev.data||'{}'); handleUpdate(data,{source:'sse'}); bc && bc.postMessage({type:'lock-update', data}); });
          esRef.addEventListener('error', () => { logDiag('SSE error – will retry leadership'); setTimeout(()=>{ if(isLeader) initOrRestartSSE(); }, 2500); });
          esRef.onopen = () => { logDiag('SSE connected (leader)'); };
          window.__LOCK_SSE = esRef; return true;
        } catch(e){ logDiag('SSE init failed', e); return false; }
      }
      function handleUpdate(data, ctx){ latestRequest = data; const lock = data.lock_status||{}; if(lock && Object.keys(lock).length){ window.lockStatus = lock; } updateLockUI(window.lockStatus||{}, latestRequest); if(data.action_required){ showDecisionModal(latestRequest); } else { hideDecisionModal(); } if(data.state==='pending'){ beginCountdown(); } else { paintCountdown(null); } }

      // Leadership election & start
      ensureLeadership();
      if(isLeader){ // start poll fallback timer even with SSE (if SSE not supported)
        const sseOk = initOrRestartSSE();
        if(!sseOk && TRANSFER_ID){ poll(); pollTimer = setInterval(poll, POLL_INTERVAL_MS); }
      }
      document.addEventListener('visibilitychange', ()=>{ if(document.hidden){ return; } ensureLeadership(); if(isLeader){ if(!esRef){ initOrRestartSSE(); } else { poll(); } } });

      // Expose for manual debugging
      window.__LOCK_REQUEST_DEBUG = { poll, decideRequest };

      // Initial lock UI enforcement before system init
      (function initialLockPaint(){
        try {
          const ls = (window.lockStatus||{});
          if(ls && !ls.has_lock){ window.document.body.classList.add('pack-locked-out'); }
          if(ls && (ls.is_locked_by_other||ls.isLockedByOther)){
            const holder = ls.holder_name||ls.holderName||ls.lockedByName||'another user';
            const tb = document.getElementById('lockRequestToolbar');
            if(tb){ tb.style.display='block'; }
            const hn = document.getElementById('lockHolderName'); if(hn) hn.textContent=holder;
          }
        } catch(e){ console.warn('Initial lock paint failed', e); }
      })();
      // Prevent duplicate initialization if script included twice
      if (window.packSystem && window.packSystem.__alive) {
        console.warn('[PackSystem] Init skipped (already active)');
        return;
      }
      if (typeof TransfersPackSystem !== 'undefined' && window.DISPATCH_BOOT) {
        const config = {
          transferId: window.DISPATCH_BOOT.transfer_id,
            userId: window.DISPATCH_BOOT.user_id,
            debug: true
        };
        console.log('🚀 Initializing TransfersPackSystem with config:', config);
        window.packSystem = new TransfersPackSystem(config);
        window.packSystem.__alive = true;

        // Unified toast wrapper: funnel PackToast.* into packSystem.showToast for dedupe
        if (window.PackToast && !window.PackToast.__wrappedForPackSystem && window.packSystem?.showToast) {
          ['success','error','warning','info'].forEach(type => {
            const orig = window.PackToast[type] || function(){};
            window.PackToast[type] = function(msg, opts){
              try { window.packSystem.showToast(msg, type, opts || {}); } catch(e){ orig(msg, opts); }
            };
          });
          window.PackToast.__wrappedForPackSystem = true;
        }
      } else {
        console.error('❌ Failed to initialize pack system - missing dependencies', {
          hasClass: typeof TransfersPackSystem !== 'undefined',
          hasBoot: !!window.DISPATCH_BOOT
        });
      }

      // Fallback bindings (in case modular system failed to attach UI listeners)
      (function fallbackButtons(){
        if (window.packSystem && typeof window.packSystem.autofillQuantities === 'function') return; // primary system active
        const log = (...a)=>console.warn('[PackSystem:FALLBACK]',...a);
        function plannedFor(el){ return parseInt(el.getAttribute('data-planned')||el.dataset.planned||'0',10)||0; }
        function updateTotals(){
          let counted=0, planned=0; const inputs=document.querySelectorAll('.qty-input, input[name^="counted_qty"]');
          inputs.forEach(i=>{ const q=parseInt(i.value)||0; counted+=q; planned+=plannedFor(i); });
          const c=document.getElementById('countedTotalFooter'); if(c) c.textContent=counted;
          const p=document.getElementById('plannedTotalFooter'); if(p && !p.textContent.trim()) p.textContent=planned;
          const d=document.getElementById('diffTotalFooter'); if(d) { const diff=counted-planned; d.textContent= diff===0? 'OK' : (diff>0? '+'+diff : diff); }
          if (window.recalcTransferWeights) { try { window.recalcTransferWeights(); } catch(e){} }
        }
        function gatherDraft(){ const draft={ transfer_id: parseInt(document.querySelector('[data-txid]')?.getAttribute('data-txid')||'0',10)||0, counted_qty:{}, notes:'', timestamp:new Date().toISOString() }; document.querySelectorAll('.qty-input, input[name^="counted_qty"]').forEach(inp=>{ let id=null; const m=(inp.name||'').match(/counted_qty\[([^\]]+)\]/); if(m) id=m[1]; if(!id) id=inp.dataset.productId; const v=parseInt(inp.value)||0; if(id && v>0) draft.counted_qty[id]=v; }); const notes=document.querySelector('#notesForTransfer,[name="notes"]'); if(notes && notes.value) draft.notes=notes.value; return draft; }
  async function saveDraft(){ const pill=document.getElementById('autosavePillText'); const ls=window.lockStatus||{}; if(!ls.has_lock){ if(pill) pill.textContent='LOCK'; log('Blocked draft save - no lock held'); return; } if(pill) pill.textContent='Saving…'; const payload=gatherDraft(); if(Object.keys(payload.counted_qty).length===0){ if(pill) pill.textContent='Idle'; return; } try { const res=await fetch('/modules/transfers/stock/api/draft_save_api.php',{ method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload), credentials:'same-origin' }); const json=await res.json().catch(()=>({})); if(res.status===423){ if(pill) pill.textContent='Lock'; log('Lock not held for draft save (423)'); return; } if(res.ok && json.success){ if(pill) pill.textContent='Saved'; const last=document.getElementById('autosaveLastSaved'); if(last) last.textContent='Last saved: '+ new Date().toLocaleTimeString(); } else { if(pill) pill.textContent='Retry'; log('Draft save failed', json); } } catch(err){ if(pill) pill.textContent='Error'; log('Draft save error',err); } }
        function setTrackingMode(mode){ const mBtn=document.getElementById('trackModeManual'); const iBtn=document.getElementById('trackModeInternal'); if(mBtn) mBtn.classList.toggle('active', mode==='manual'); if(iBtn) iBtn.classList.toggle('active', mode==='internal'); const form=document.getElementById('manualTrackingForm'); if(form) form.setAttribute('data-mode', mode); }
        function addTrackingEntry(){ const tBody=document.getElementById('manualTrackingTbody'); if(!tBody) return; const input=document.getElementById('trackingInput'); const carrier=document.getElementById('carrierSelect'); const notes=document.getElementById('trackingNotes'); const num=(input?.value||'').trim(); if(!num) return; const carrierTxt=carrier && carrier.options[carrier.selectedIndex] ? carrier.options[carrier.selectedIndex].text : 'Carrier'; const mode=document.getElementById('manualTrackingForm')?.getAttribute('data-mode')||'manual'; const row=document.createElement('tr'); row.innerHTML=`<td class='text-center'></td><td class='mono'>${num.replace(/</g,'&lt;')}</td><td>${carrierTxt.replace(/</g,'&lt;')}</td><td>${(notes?.value||'').replace(/</g,'&lt;')}</td><td>${mode}</td><td class='text-center'><button type='button' class='btn btn-sm btn-outline-danger' data-action='del-track' title='Delete' aria-label='Delete'>&times;</button></td>`; const empty=tBody.querySelector('.tracking-empty'); if(empty) empty.remove(); tBody.appendChild(row); if(input) input.value=''; if(notes) notes.value=''; const addBtn=document.getElementById('addTrackingBtn'); if(addBtn) addBtn.disabled=true; saveDraft(); }
        document.addEventListener('click', function(e){
          const t=e.target;
            if (t.closest && t.closest('#autofillBtn')) { e.preventDefault(); log('Autofill (fallback)'); let filled=0; document.querySelectorAll('.qty-input, input[name^="counted_qty"]').forEach(inp=>{ const p=plannedFor(inp); if(p>0){ inp.value=p; inp.dispatchEvent(new Event('input',{bubbles:true})); filled++; }}); updateTotals(); }
            else if (t.closest && t.closest('#resetBtn')) { e.preventDefault(); log('Reset (fallback)'); document.querySelectorAll('.qty-input, input[name^="counted_qty"]').forEach(inp=>{ if(inp.value){ inp.value=''; inp.dispatchEvent(new Event('input',{bubbles:true})); }}); updateTotals(); }
            else if (t.closest && t.closest('#savePackBtn')) { e.preventDefault(); log('Manual save (fallback)'); saveDraft(); }
            else if (t.closest && t.closest('#trackModeManual')) { e.preventDefault(); setTrackingMode('manual'); }
            else if (t.closest && t.closest('#trackModeInternal')) { e.preventDefault(); setTrackingMode('internal'); }
            else if (t.closest && t.closest('#addTrackingBtn')) { e.preventDefault(); addTrackingEntry(); }
            else if (t.matches && t.matches('[data-action="del-track"]')) { e.preventDefault(); const r=t.closest('tr'); if(r) r.remove(); }
        });
        document.addEventListener('input', function(e){ if(e.target && (e.target.matches('.qty-input')||e.target.matches('input[name^="counted_qty"]'))) { updateTotals(); saveDraft(); } if(e.target && e.target.id==='trackingInput'){ const addBtn=document.getElementById('addTrackingBtn'); if(addBtn) addBtn.disabled = e.target.value.trim()===''; } });
        updateTotals();
        log('Fallback button handlers active (extended)');
      })();

      // Toolbar button handlers (works for both modular + fallback)
      document.addEventListener('click', function(ev){
        const t=ev.target;
        if(t.closest && t.closest('#requestLockBtn')){ ev.preventDefault(); if(window.packSystem?.requestLockAccess){ window.packSystem.requestLockAccess(); } else { window.PackToast?.info('Attempting lock request…'); fetch('/modules/transfers/stock/api/lock_request.php?tx='+encodeURIComponent(window.DISPATCH_BOOT?.transfer_id||'')); } }
        else if(t.closest && t.closest('#retryAcquireBtn')){ ev.preventDefault(); if(window.packSystem?.modules?.lockSystem?.acquireLock){ window.packSystem.modules.lockSystem.acquireLock().then(r=>{ if(r?.success){ window.PackToast?.success('Lock acquired'); } else { window.PackToast?.warning('Still locked'); } }); } }
        else if(t.closest && t.closest('#forceRefreshBtn')){ ev.preventDefault(); location.reload(); }
      });
    });
  </script>

  <!-- Lock Request Decision Modal (cannot click out) -->
  <div id="lockRequestDecisionModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); z-index:10001;">
    <div style="max-width:480px; margin:10vh auto; background:#fff; border-radius:8px; padding:24px; box-shadow:0 4px 18px rgba(0,0,0,.4); position:relative;">
      <h4 style="margin-top:0; font-weight:600;">Lock Request</h4>
      <p>Another user is requesting control of this transfer. You currently hold the lock.</p>
      <p><strong>Requestor:</strong> <span data-lock-holder></span></p>
      <p>If you approve, you will immediately lose edit access.</p>
      <div class="d-flex justify-content-end gap-2" style="gap:10px;">
        <button type="button" class="btn btn-outline-secondary" data-lock-decision="decline">Decline</button>
        <button type="button" class="btn btn-primary" data-lock-decision="approve">Approve Transfer</button>
      </div>
    </div>
  </div>

  <!-- Diagnostics Panel -->
  <div id="lockDiagnosticsPanel">
    <h6>Lock Diagnostics</h6>
    <pre></pre>
    <button id="lockDiagToggle" class="btn btn-sm btn-outline-light">Close</button>
  </div>

  <!-- Lock Origin Banner (new) -->
  <div id="lockOriginBanner" style="display:none; position:relative; z-index:5;">
    <div id="lockOriginInner" style="background:linear-gradient(90deg,#b31217,#e52d27);color:#fff;font-size:12px;font-weight:600;letter-spacing:.5px;padding:4px 10px;border-radius:0 0 6px 6px;box-shadow:0 2px 4px rgba(0,0,0,.25);display:flex;align-items:center;gap:8px;">
      <i class="fa fa-lock"></i>
      <span id="lockOriginText">Locked</span>
    </div>
  </div>
  <script>
  (function(){
    function detectLockOrigin(lock){
      if(!lock) return { reason:'unknown', text:'Lock status unknown' };
      const currentUserId = (window.DISPATCH_BOOT && window.DISPATCH_BOOT.user_id) || (window.bootPayload && window.bootPayload.user_id) || null;
      const holderId = lock.holder_id || lock.holderId || null;
      if(lock.has_lock){
        return { reason:'owned', text:'You hold the lock' };
      }
      if(lock.is_locked_by_other || lock.isLockedByOther){
        if(holderId && currentUserId && String(holderId) === String(currentUserId)){
          // Another browser / tab (different session) of SAME user
          return { reason:'same-user-other-session', text:'Locked in another browser/session of yours' };
        }
        const holderName = lock.holder_name || lock.holderName || 'Another user';
        return { reason:'other-user', text:'Locked by ' + holderName };
      }
      return { reason:'unlocked', text:'Not locked' };
    }
    function renderLockOrigin(lock){
      const banner = document.getElementById('lockOriginBanner');
      const textEl = document.getElementById('lockOriginText');
      if(!banner || !textEl) return;
      const info = detectLockOrigin(lock);
      if(info.reason === 'unlocked') { banner.style.display='none'; return; }
      textEl.textContent = info.text;
      // Different styling variants
      if(info.reason === 'other-user'){
        banner.style.display='block';
        textEl.parentElement.style.background='linear-gradient(90deg,#8b1111,#5d0c0c)';
      } else if(info.reason === 'same-user-other-session'){
        banner.style.display='block';
        textEl.parentElement.style.background='linear-gradient(90deg,#c07d00,#d69e00)';
      } else if(info.reason === 'owned') {
        banner.style.display='block';
        textEl.parentElement.style.background='linear-gradient(90deg,#146c2e,#0f4d21)';
      } else {
        banner.style.display='block';
        textEl.parentElement.style.background='linear-gradient(90deg,#555,#333)';
      }
    }
    window.renderLockOrigin = renderLockOrigin;
    // Hook into existing status update
    const origUpdate = window.packSystem?.updateLockStatusDisplay;
    if(window.packSystem){
      window.packSystem.updateLockStatusDisplay = function(){
        if(typeof origUpdate === 'function'){ origUpdate.apply(window.packSystem, arguments); }
        try { renderLockOrigin(window.lockStatus||{}); } catch(e) {}
      };
    }
    document.addEventListener('DOMContentLoaded', function(){ renderLockOrigin(window.lockStatus||{}); });
  })();
  </script>
</body>
</html>