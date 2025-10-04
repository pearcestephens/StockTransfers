<?php
/**
 * Items Table Component
 * 
 * Product items table with inline editing
 * 
 * Required variables:
 * @var array  $items            Array of transfer items
 * @var string $toLbl            Destination outlet name
 * @var int    $txId             Transfer ID
 * @var array  $sourceStockMap   Product ID => stock quantity map
 * @var int    $plannedSum       Total planned quantity
 * @var int    $countedSum       Total counted quantity
 * @var string $diffLabel        Difference label
 * @var float  $estimatedWeight  Total weight in kg
 * @var int    $accuracy         Accuracy percentage
 */

if (!isset($items, $toLbl, $txId)) {
    throw new \RuntimeException('Items table component requires: $items, $toLbl, $txId');
}

$plannedSum = $plannedSum ?? 0;
$countedSum = $countedSum ?? 0;
$diffLabel = $diffLabel ?? '0';
$estimatedWeight = $estimatedWeight ?? 0;
$accuracy = $accuracy ?? 0;
$sourceStockMap = $sourceStockMap ?? [];

// Helper function availability check
if (!function_exists('_first')) {
    function _first(...$vals) {
        foreach ($vals as $v) {
            if (is_string($v) && $v !== '') return $v;
            if (is_numeric($v)) return $v;
            if (is_array($v) && !empty($v)) return $v;
        }
        return null;
    }
}

if (!function_exists('_tfx_product_tag')) {
    function _tfx_product_tag($txId, $lineNum) {
        return htmlspecialchars("{$txId}-{$lineNum}", ENT_QUOTES, 'UTF-8');
    }
}
?>

<section class="card mb-4">
    <div class="card-header py-2 d-flex justify-content-between align-items-center">
        <?php include __DIR__ . '/autosave-indicator.php'; ?>
        
        <div class="d-flex align-items-center">
            <button type="button" 
                    id="autofillBtn" 
                    class="btn btn-outline-primary btn-sm mr-2"
                    data-action="autofill">
                <i class="fa fa-magic mr-1"></i>Autofill
            </button>
            <button type="button" 
                    id="resetBtn" 
                    class="btn btn-outline-secondary btn-sm"
                    data-action="reset">
                <i class="fa fa-undo mr-1"></i>Reset
            </button>
        </div>
    </div>

    <div class="card-body p-0">
        <!-- Weight Source Legend -->
        <div class="px-3 pt-2 pb-1 small text-muted d-flex justify-content-between align-items-center flex-wrap">
            <div class="d-flex align-items-center flex-wrap" style="gap:12px;">
                <span>
                    <strong>Weight Source Legend:</strong> 
                    <span class="badge badge-light" style="font-size:9px; border:1px solid #ccc;">P</span> Product 
                    <span class="badge badge-light" style="font-size:9px; border:1px solid #ccc;">C</span> Category 
                    <span class="badge badge-light" style="font-size:9px; border:1px solid #ccc;">D</span> Default
                </span>
                <span id="weightSourceBreakdown" class="text-muted"></span>
            </div>
            <div id="lineAnnouncement" 
                 class="sr-only" 
                 aria-live="polite" 
                 aria-atomic="true" 
                 style="position:absolute; left:-9999px; top:auto; width:1px; height:1px; overflow:hidden;">
                Line updates announced here.
            </div>
        </div>
        
        <!-- Table -->
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
                        <td colspan="8" class="text-center text-muted py-4">
                            <i class="fa fa-exclamation-triangle mb-2"></i><br>
                            No items found in this transfer.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php
                    $rowNum = 1;
                    $totalCountedWeightG = 0;
                    
                    foreach ($items as $item):
                        $itemId     = (int)_first($item['id'] ?? null, $rowNum);
                        $productId  = (string)_first($item['product_id'] ?? null, $item['vend_product_id'] ?? null, '');
                        $plannedQty = (int)_first($item['qty_requested'] ?? null, $item['planned_qty'] ?? 0);
                        $countedQty = (int)($item['counted_qty'] ?? 0);
                        $stockQty   = ($productId !== '' && isset($sourceStockMap[$productId])) 
                                      ? (int)$sourceStockMap[$productId] 
                                      : 0;
                        
                        // Unit weight resolution
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
                            (isset($item['avg_weight_grams']) || isset($item['product_weight_grams']) || 
                             isset($item['weight_g']) || isset($item['unit_weight_g']))
                                ? 'product'
                                : ((isset($item['category_avg_weight_grams']) || 
                                    isset($item['category_weight_grams']) || 
                                    isset($item['cat_weight_g'])) ? 'category' : 'default')
                        );
                        
                        $rowWeightG = $unitWeightG > 0 ? $unitWeightG * max($countedQty, 0) : 0;
                        $totalCountedWeightG += $rowWeightG;
                        
                        // Product image
                        $imageUrl = '';
                        foreach (['image_url', 'image_thumbnail_url', 'product_image_url', 
                                  'vend_image_url', 'thumbnail_url', 'image'] as $field) {
                            if (!empty($item[$field])) {
                                $imageUrl = $item[$field];
                                break;
                            }
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
                        
                        <!-- Image -->
                        <td class="text-center align-middle" style="width:50px; padding: 3px;">
                            <?php if ($hasImage): ?>
                                <img src="<?= htmlspecialchars($imageUrl, ENT_QUOTES) ?>"
                                     class="product-thumb"
                                     data-action="show-image"
                                     data-src="<?= htmlspecialchars($imageUrl, ENT_QUOTES) ?>"
                                     data-name="<?= htmlspecialchars($item['product_name'] ?? 'Product', ENT_QUOTES) ?>"
                                     style="width: 45px; height: 45px; object-fit: cover; border-radius: 6px; border: 2px solid #dee2e6; cursor: pointer;"
                                     alt="Product image">
                            <?php endif; ?>
                            <div class="product-thumb-placeholder" 
                                 style="width: 45px; height: 45px; background: #f8f9fa; border: 2px solid #dee2e6; border-radius: 6px; display: <?= $hasImage ? 'none' : 'flex' ?>; align-items: center; justify-content: center;">
                                <i class="fa fa-image text-muted" style="font-size: 18px;"></i>
                            </div>
                        </td>
                        
                        <!-- Product Name -->
                        <td class="align-middle" style="padding-left:3px; padding-right: 8px;">
                            <?= htmlspecialchars((string)_first(
                                $item['product_name'] ?? null, 
                                $item['name'] ?? 'Product'
                            ), ENT_QUOTES) ?>
                        </td>
                        
                        <!-- Source Stock -->
                        <td class="text-center align-middle">
                            <?php if ($stockQty <= 0): ?>
                                <span class="text-danger font-weight-bold">0</span>
                                <div class="small text-danger">
                                    <i class="fa fa-exclamation-triangle" title="Out of Stock"></i>
                                </div>
                            <?php elseif ($stockQty < $plannedQty): ?>
                                <span class="text-warning font-weight-bold"><?= $stockQty ?></span>
                                <div class="small text-warning">
                                    <i class="fa fa-exclamation-triangle"></i> Low
                                </div>
                            <?php else: ?>
                                <span class="text-success font-weight-bold"><?= $stockQty ?></span>
                            <?php endif; ?>
                        </td>
                        
                        <!-- Planned -->
                        <td class="text-center align-middle">
                            <span class="font-weight-bold" data-planned="<?= $plannedQty ?>">
                                <?= $plannedQty ?>
                            </span>
                        </td>
                        
                        <!-- Counted (Input) -->
                        <td class="text-center align-middle counted-td">
                            <input type="number"
                                   class="form-control form-control-sm tfx-num qty-input"
                                   name="counted_qty[<?= $itemId ?>]"
                                   id="counted-<?= $itemId ?>"
                                   min="0" 
                                   step="1"
                                   <?= $countedQty > 0 ? 'value="' . $countedQty . '"' : 'placeholder="0"' ?>
                                   data-item-id="<?= $itemId ?>"
                                   data-planned="<?= $plannedQty ?>"
                                   data-source-stock="<?= $stockQty ?>"
                                   style="text-align: center;">
                        </td>
                        
                        <!-- Destination -->
                        <td class="text-center align-middle">
                            <?= htmlspecialchars($toLbl, ENT_QUOTES) ?>
                        </td>
                        
                        <!-- Weight -->
                        <td class="text-center align-middle">
                            <?php if ($unitWeightG > 0): ?>
                                <?php
                                    $srcAbbr = $weightSource === 'product' ? 'P' 
                                             : ($weightSource === 'category' ? 'C' : 'D');
                                    $srcTitle = $weightSource === 'product' ? 'Product specific weight' 
                                              : ($weightSource === 'category' ? 'Category average weight' 
                                              : 'Default fallback weight');
                                ?>
                                <span class="row-weight" 
                                      data-unit-weight-g="<?= $unitWeightG ?>" 
                                      data-weight-source="<?= htmlspecialchars($weightSource, ENT_QUOTES) ?>" 
                                      data-row-weight-g="<?= $rowWeightG ?>">
                                    <?= $rowWeightG > 0 ? number_format($rowWeightG/1000, 3) . 'kg' : '—' ?>
                                </span>
                                <small class="text-muted d-block" style="font-size:10px;">
                                    @<?= number_format($unitWeightG/1000, 3) ?>kg ea 
                                    <span class="badge badge-light" 
                                          title="<?= htmlspecialchars($srcTitle, ENT_QUOTES) ?>" 
                                          style="font-size:9px; font-weight:600; border:1px solid #ddd;">
                                        <?= $srcAbbr ?>
                                    </span>
                                </small>
                            <?php else: ?>
                                <span class="row-weight text-muted" data-unit-weight-g="0">—</span>
                                <small class="text-warning d-block" style="font-size:10px;">no wt</small>
                            <?php endif; ?>
                        </td>
                        
                        <!-- Tag -->
                        <td class="text-center align-middle mono" title="Product Tag">
                            <?= _tfx_product_tag((string)$txId, $rowNum) ?>
                        </td>
                    </tr>
                    <?php 
                        $rowNum++; 
                    endforeach; 
                    ?>
                <?php endif; ?>
                </tbody>
                
                <!-- Footer Totals -->
                <tfoot>
                    <tr>
                        <td colspan="3" class="text-right font-weight-bold">Totals:</td>
                        <td class="text-center" id="plannedTotalFooter">
                            <?= (int)$plannedSum ?>
                        </td>
                        <td class="text-center">
                            <span id="countedTotalFooter"><?= (int)$countedSum ?></span>
                            <small class="d-block text-muted">
                                Diff: <span id="diffTotalFooter"><?= htmlspecialchars($diffLabel, ENT_QUOTES) ?></span>
                            </small>
                        </td>
                        <td colspan="1"></td>
                        <td class="text-center">
                            <span id="totalWeightFooter">
                                <?= number_format((float)$estimatedWeight, 3) ?>kg
                            </span>
                        </td>
                        <td class="text-center">
                            <small class="text-muted">
                                <?= (int)$accuracy ?>%
                            </small>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</section>
