<?php
/**
 * Transfer Breadcrumb Component
 * 
 * Generic breadcrumb component for transfer pages
 * Can be customized via $breadcrumb_config array
 * 
 * Required variables:
 * - $breadcrumb_config['active_page'] - Current page name
 * - $breadcrumb_config['transfer_id'] (optional) - For transfer-specific breadcrumbs
 */

$default_config = [
  'active_page' => 'Transfer',
  'show_transfer_id' => false,
  'transfer_id' => null,
  'custom_items' => [] // Additional breadcrumb items
];

$breadcrumb_config = array_merge($default_config, $breadcrumb_config ?? []);
?>

<nav aria-label="breadcrumb">
  <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="/index.php">Home</a></li>
    <li class="breadcrumb-item"><a href="/modules/transfers">Transfers</a></li>
    
    <?php if (!empty($breadcrumb_config['custom_items'])): ?>
      <?php foreach ($breadcrumb_config['custom_items'] as $item): ?>
        <li class="breadcrumb-item">
          <?php if (!empty($item['url'])): ?>
            <a href="<?= htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></a>
          <?php else: ?>
            <?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    <?php endif; ?>
    
    <li class="breadcrumb-item active" aria-current="page">
      <?= htmlspecialchars($breadcrumb_config['active_page'], ENT_QUOTES, 'UTF-8') ?>
      <?php if ($breadcrumb_config['show_transfer_id'] && $breadcrumb_config['transfer_id']): ?>
        #<?= (int)$breadcrumb_config['transfer_id'] ?>
      <?php endif; ?>
    </li>
  </ol>
</nav>