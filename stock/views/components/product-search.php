<?php
/**
 * Product Search Panel Component
 * 
 * Reusable product search interface for transfers
 * 
 * Required variables:
 * - $search_config['table_id'] - ID for the search table
 * - $search_config['input_placeholder'] - Search input placeholder text
 * - $search_config['show_bulk_actions'] - Whether to show bulk action buttons
 * - $search_config['bulk_actions'] - Array of bulk action button configs
 * - $search_config['columns'] - Array of table column configurations
 * - $search_config['empty_message'] - Message shown when no results
 */

$default_config = [
  'table_id' => 'product-search-table',
  'input_id' => 'product-search-input',
  'input_placeholder' => 'Search products by name, SKU, handle, ID… (use * wildcard)',
  'show_bulk_actions' => true,
  'bulk_actions' => [
    ['id' => 'bulk-add-selected', 'label' => 'Add Selected', 'class' => 'btn-outline-primary'],
    ['id' => 'bulk-add-to-other', 'label' => 'Add to Other…', 'class' => 'btn-outline-secondary', 'title' => 'Add selected to other transfers (same origin outlet)']
  ],
  'columns' => [
    ['key' => 'select', 'label' => '', 'class' => 'col-checkbox'],
    ['key' => 'image', 'label' => 'Img', 'class' => 'col-image'],
    ['key' => 'product', 'label' => 'Product (Name + SKU)', 'class' => ''],
    ['key' => 'stock', 'label' => 'Stock', 'class' => ''],
    ['key' => 'price', 'label' => 'Price', 'class' => ''],
    ['key' => 'actions', 'label' => 'Add', 'class' => 'col-add']
  ],
  'empty_message' => 'Type to search…'
];

$search_config = array_merge($default_config, $search_config ?? []);
?>

<section class="card mb-3" id="product-search-card" aria-labelledby="product-search-title">
  <div class="card-header d-flex justify-content-between align-items-center gap-12px">
    <div class="d-flex align-items-center gap-8px flex-fill">
      <span class="sr-only" id="product-search-title">Product Search</span>
      <i class="fa fa-search text-muted" aria-hidden="true"></i>
      <input type="text" 
             id="<?= htmlspecialchars($search_config['input_id'], ENT_QUOTES, 'UTF-8') ?>" 
             class="form-control form-control-sm"
             placeholder="<?= htmlspecialchars($search_config['input_placeholder'], ENT_QUOTES, 'UTF-8') ?>" 
             autocomplete="off" 
             aria-label="Search products">
      <button class="btn btn-sm btn-outline-primary" id="product-search-run" type="button" title="Run search" aria-label="Run search">
        <i class="fa fa-search" aria-hidden="true"></i>
      </button>
      <button class="btn btn-sm btn-outline-secondary" id="product-search-clear" type="button" title="Clear search" aria-label="Clear search">
        <i class="fa fa-times" aria-hidden="true"></i>
      </button>
    </div>
    
    <?php if ($search_config['show_bulk_actions'] && !empty($search_config['bulk_actions'])): ?>
      <div class="btn-group btn-group-sm" role="group" aria-label="Bulk actions">
        <?php foreach ($search_config['bulk_actions'] as $action): ?>
          <button class="btn <?= htmlspecialchars($action['class'], ENT_QUOTES, 'UTF-8') ?>" 
                  id="<?= htmlspecialchars($action['id'], ENT_QUOTES, 'UTF-8') ?>" 
                  type="button" 
                  <?= !empty($action['title']) ? 'title="' . htmlspecialchars($action['title'], ENT_QUOTES, 'UTF-8') . '"' : '' ?>
                  disabled>
            <?= htmlspecialchars($action['label'], ENT_QUOTES, 'UTF-8') ?>
          </button>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  
  <div class="card-body p-0">
    <div id="product-search-results" class="table-responsive">
      <table class="table table-sm table-hover mb-0" 
             id="<?= htmlspecialchars($search_config['table_id'], ENT_QUOTES, 'UTF-8') ?>" 
             aria-live="polite" 
             aria-label="Product search results">
        <thead class="thead-light">
          <tr>
            <?php foreach ($search_config['columns'] as $column): ?>
              <th class="<?= htmlspecialchars($column['class'], ENT_QUOTES, 'UTF-8') ?>">
                <?php if ($column['key'] === 'select'): ?>
                  <input type="checkbox" id="ps-select-all" aria-label="Select all results">
                <?php else: ?>
                  <?= htmlspecialchars($column['label'], ENT_QUOTES, 'UTF-8') ?>
                <?php endif; ?>
              </th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody id="product-search-tbody">
          <tr>
            <td colspan="<?= count($search_config['columns']) ?>" class="text-muted small py-3 text-center">
              <?= htmlspecialchars($search_config['empty_message'], ENT_QUOTES, 'UTF-8') ?>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</section>