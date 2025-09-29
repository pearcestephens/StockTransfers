<?php
declare(strict_types=1);
/**
 * File: breadcrumb.php
 * Component: Transfer Breadcrumb
 * Purpose: Render a consistent, safe breadcrumb trail for transfer-related pages.
 *
 * Expected incoming variable (by include scope):
 *   $breadcrumb_config = [
 *     'active_page'      => string,           // Label for current page (required)
 *     'show_transfer_id' => bool,             // Whether to append transfer id to final crumb
 *     'transfer_id'      => int|null,         // Transfer ID if applicable
 *     'custom_items'     => array<int,array{label:string,url?:string,aria?:string}>
 *   ]
 *
 * Security / Hardening Notes:
 * - All dynamic text is escaped with htmlspecialchars (ENT_QUOTES, UTF-8).
 * - Only whitelisted keys from custom_items are used.
 * - Prevents notice-level errors if structure is malformed.
 */

// -------- Default Config --------------------------------------------------
$__breadcrumb_defaults = [
  'active_page'      => 'Transfer',
  'show_transfer_id' => false,
  'transfer_id'      => null,
  'custom_items'     => []
];

$breadcrumb_config = isset($breadcrumb_config) && is_array($breadcrumb_config)
  ? array_merge($__breadcrumb_defaults, $breadcrumb_config)
  : $__breadcrumb_defaults;

// Normalise & validate custom items
$customItems = [];
if (!empty($breadcrumb_config['custom_items']) && is_array($breadcrumb_config['custom_items'])) {
  foreach ($breadcrumb_config['custom_items'] as $ci) {
    if (!is_array($ci)) continue;
    $label = trim((string)($ci['label'] ?? ''));
    if ($label === '') continue; // skip empties
    $url   = isset($ci['url']) && is_string($ci['url']) && $ci['url'] !== '' ? $ci['url'] : null;
    $aria  = isset($ci['aria']) && is_string($ci['aria']) ? $ci['aria'] : null;
    $customItems[] = [
      'label' => $label,
      'url'   => $url,
      'aria'  => $aria,
    ];
  }
}

$activePageLabel = htmlspecialchars((string)$breadcrumb_config['active_page'], ENT_QUOTES, 'UTF-8');
$transferId      = $breadcrumb_config['transfer_id'];
$showTransferId  = $breadcrumb_config['show_transfer_id'] && $transferId;
?>

<nav aria-label="breadcrumb">
  <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="/index.php">Home</a></li>
    <li class="breadcrumb-item"><a href="/modules/transfers">Transfers</a></li>
    
    <?php if (!empty($customItems)): ?>
      <?php foreach ($customItems as $item): ?>
        <li class="breadcrumb-item">
          <?php if (!empty($item['url'])): ?>
            <a href="<?= htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8') ?>"<?= $item['aria'] ? ' aria-label="' . htmlspecialchars($item['aria'], ENT_QUOTES, 'UTF-8') . '"' : '' ?>><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></a>
          <?php else: ?>
            <?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    <?php endif; ?>

    <li class="breadcrumb-item active" aria-current="page">
      <?= $activePageLabel ?><?= $showTransferId ? ' #' . (int)$transferId : '' ?>
    </li>
  </ol>
</nav>