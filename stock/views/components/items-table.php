<?php
/**
 * Transfer Items Table Component
 * 
 * Displays transfer items in a table format with editable quantities
 * 
 * Required variables:
 * - $table_config['items'] - Array of transfer items
 * - $table_config['columns'] - Array of column configurations
 * - $table_config['table_id'] - HTML ID for the table
 * - $table_config['show_actions'] - Whether to show action buttons per row
 * - $table_config['empty_message'] - Message when no items
 * - $table_config['transfer_id'] - Transfer ID for row IDs
 * - $table_config['destination_label'] - Destination outlet label
 * - $table_config['source_stock_map'] - Stock levels for products
 * - $table_config['render_functions'] - Custom rendering functions
 */

$default_config = [
  'items' => [],
  'table_id' => 'transfer-table',
  'show_actions' => true,
  'empty_message' => 'No items on this transfer.',
  'transfer_id' => 0,
  'destination_label' => '',
  'source_stock_map' => [],
  'columns' => [
    ['key' => 'number', 'label' => '#', 'class' => 'col-number'],
    ['key' => 'product', 'label' => 'Product', 'class' => ''],
    ['key' => 'planned_qty', 'label' => 'Planned Qty', 'class' => ''],
    ['key' => 'stock_qty', 'label' => 'Qty in stock', 'class' => ''],
    ['key' => 'counted_qty', 'label' => 'Counted Qty', 'class' => ''],
    ['key' => 'destination', 'label' => 'To', 'class' => ''],
    ['key' => 'id', 'label' => 'ID', 'class' => '']
  ],
  'render_functions' => []
];

$table_config = array_merge($default_config, $table_config ?? []);

// Helper function to render product cell (must be available in parent scope)
if (!function_exists('tfx_render_product_cell') && isset($table_config['render_functions']['product_cell'])) {
  $tfx_render_product_cell = $table_config['render_functions']['product_cell'];
} elseif (!function_exists('tfx_render_product_cell')) {
  function tfx_render_product_cell($item) {
    $name = htmlspecialchars($item['product_name'] ?? $item['name'] ?? 'Product', ENT_QUOTES, 'UTF-8');
    $sku = htmlspecialchars($item['product_sku'] ?? $item['sku'] ?? '', ENT_QUOTES, 'UTF-8');
    $skuLine = $sku ? '<div class="tfx-product-sku text-muted small">SKU: ' . $sku . '</div>' : '';
    return '<div class="tfx-product-cell"><strong class="tfx-product-name">' . $name . '</strong>' . $skuLine . '</div>';
  }
}
?>

<div class="card tfx-card-tight mb-3" id="table-card" aria-labelledby="items-title">
  <div class="card-body py-2">
    <h2 class="sr-only" id="items-title">Items in this transfer</h2>
    <div class="tfx-pack-scope">
      <table class="table table-responsive-sm table-bordered table-striped table-sm" 
             id="<?= htmlspecialchars($table_config['table_id'], ENT_QUOTES, 'UTF-8') ?>" 
             aria-describedby="items-title">
        <thead>
          <tr>
            <?php foreach ($table_config['columns'] as $column): ?>
              <th class="<?= htmlspecialchars($column['class'], ENT_QUOTES, 'UTF-8') ?>" scope="col">
                <?= htmlspecialchars($column['label'], ENT_QUOTES, 'UTF-8') ?>
              </th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody id="productSearchBody">
          <?php if (!empty($table_config['items'])): ?>
            <?php
            $row = 0;
            foreach ($table_config['items'] as $item):
              $row++;
              $itemId = (int)($item['id'] ?? 0);
              $productId = (string)($item['product_id'] ?? '');
              $planned = max(0, (int)($item['qty_requested'] ?? 0));
              $sentSoFar = max(0, (int)($item['qty_sent_total'] ?? 0));
              $stockOnHand = !empty($productId) ? max(0, (int)($table_config['source_stock_map'][$productId] ?? 0)) : null;
              $inventory = max($planned, $sentSoFar, $stockOnHand ?? 0);
              
              if ($planned <= 0) continue;
            ?>
            <tr data-product-id="<?= $productId ?>" 
                data-inventory="<?= $inventory ?>" 
                data-planned="<?= $planned ?>"
                <?= $stockOnHand !== null ? 'data-stock="' . $stockOnHand . '"' : '' ?>>
              
              <?php foreach ($table_config['columns'] as $column): ?>
                <td class="<?= htmlspecialchars($column['class'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                  <?php
                  switch ($column['key']):
                    case 'number':
                      if ($table_config['show_actions']):
                  ?>
                        <div class="text-center align-middle">
                          <button class="tfx-remove-btn" type="button" data-action="remove-product" 
                                  aria-label="Remove product" title="Remove product">
                            <i class="fa fa-times" aria-hidden="true"></i>
                          </button>
                          <input type="hidden" class="productID" value="<?= $itemId ?>">
                        </div>
                      <?php else: ?>
                        <?= $row ?>
                      <?php endif;
                      break;
                      
                    case 'product':
                      echo tfx_render_product_cell($item);
                      break;
                      
                    case 'planned_qty':
                      echo '<span class="planned">' . $planned . '</span>';
                      break;
                      
                    case 'stock_qty':
                      $stockLabel = $stockOnHand !== null ? number_format($stockOnHand) : '&mdash;';
                      echo '<span class="stock">' . $stockLabel . '</span>';
                      break;
                      
                    case 'counted_qty':
                  ?>
                        <div class="counted-td">
                          <input type="number" 
                                 min="0" 
                                 max="<?= $inventory ?>" 
                                 value="<?= $sentSoFar ?: '' ?>" 
                                 class="form-control form-control-sm tfx-num" 
                                 inputmode="numeric" 
                                 aria-label="Counted quantity">
                          <span class="counted-print-value d-none"><?= $sentSoFar ?: 0 ?></span>
                        </div>
                      <?php
                      break;
                      
                    case 'destination':
                      echo htmlspecialchars($table_config['destination_label'], ENT_QUOTES, 'UTF-8');
                      break;
                      
                    case 'id':
                      echo '<span class="id-counter">' . $table_config['transfer_id'] . '-' . $row . '</span>';
                      break;
                      
                    default:
                      // Custom column - check if there's a custom renderer
                      if (isset($table_config['render_functions'][$column['key']])) {
                        echo call_user_func($table_config['render_functions'][$column['key']], $item, $row);
                      } else {
                        echo htmlspecialchars($item[$column['key']] ?? '', ENT_QUOTES, 'UTF-8');
                      }
                  endswitch;
                  ?>
                </td>
              <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="<?= count($table_config['columns']) ?>" class="text-center text-muted py-4">
                <?= htmlspecialchars($table_config['empty_message'], ENT_QUOTES, 'UTF-8') ?>
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>