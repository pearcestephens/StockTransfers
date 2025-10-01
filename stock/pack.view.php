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

      <div class="container-fluid animated fadeIn">
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
                        <span class="badge ml-2" id="lockStatusBadge" style="font-size:0.5em; background: rgba(255,255,255,0.25); color: white; border: 1px solid rgba(255,255,255,0.4); text-shadow: none; padding: 2px 6px;">
                          <?= $lockStatus['has_lock'] ? 'LOCKED' : 'UNLOCKED' ?>
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
                <div class="table-responsive">
                  <table class="table table-sm table-striped mb-0" id="transferItemsTable">
                    <thead class="thead-light">
                      <tr>
                        <th style="width:50px;" class="text-center"></th>
                        <th style="padding-left:8px; width: 45%;">Product</th>
                        <th style="width:80px;" class="text-center">Source Stock</th>
                        <th style="width:70px;" class="text-center">Planned</th>
                        <th style="width:90px;" class="text-center">Counted</th>
                        <th style="width:70px;" class="text-center">To</th>
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
                      foreach ($items as $item):
                        $itemId     = (int)_first($item['id'] ?? null, $rowNum);
                        $productId  = (string)_first($item['product_id'] ?? null, $item['vend_product_id'] ?? null, '');
                        $plannedQty = (int)_first($item['qty_requested'] ?? null, $item['planned_qty'] ?? 0);
                        $countedQty = (int)($item['counted_qty'] ?? 0);
                        $stockQty   = ($productId !== '' && isset($sourceStockMap[$productId])) ? (int)$sourceStockMap[$productId] : 0;
                    ?>
                      <tr id="item-row-<?= $itemId ?>"
                          class="pack-item-row"
                          data-item-id="<?= $itemId ?>"
                          data-product-id="<?= htmlspecialchars($productId, ENT_QUOTES) ?>"
                          data-planned-qty="<?= $plannedQty ?>"
                          data-source-stock="<?= $stockQty ?>">
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
                        <td class="text-center align-middle mono" title="Product Tag"><?= htmlspecialchars(tfx_product_tag((string)$txId, $rowNum), ENT_QUOTES) ?></td>
                      </tr>
                    <?php $rowNum++; endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                    <tfoot>
                      <tr>
                        <td colspan="3" class="text-right font-weight-bold">Totals:</td>
                        <td class="text-center" id="plannedTotalFooter"><?= (int)$plannedSum ?></td>
                        <td class="text-center">
                          <span id="countedTotalFooter"><?= (int)$countedSum ?></span>
                          <small class="d-block text-muted">Diff: <span id="diffTotalFooter"><?= htmlspecialchars($diffLabel, ENT_QUOTES) ?></span></small>
                        </td>
                        <td></td>
                        <td class="text-center">
                          <span class="font-weight-bold text-info"><?= number_format($estimatedWeight, 1) ?>kg</span>
                          <small class="d-block text-muted">Total Weight</small>
                        </td>
                      </tr>
                    </tfoot>
                  </table>
                </div>
              </div>
            </section>

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

  <!-- Pack System Initialization -->
  <script>
    document.addEventListener('DOMContentLoaded', function() {
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
    });
  </script>

    </main>
  </div>

    <!-- Footer templates -->
  <?php include $DOCUMENT_ROOT . '/assets/template/personalisation-menu.php'; ?>
  <?php
    $htmlFooterPath = rtrim($DOCUMENT_ROOT,'/') . '/assets/template/html-footer.php';
    if (is_file($htmlFooterPath)) { include $htmlFooterPath; }
    include $DOCUMENT_ROOT . '/assets/template/footer.php';
  ?>

</body>
</html>