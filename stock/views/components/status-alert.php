<?php
/**
 * Transfer Status Alert Component
 * 
 * Displays contextual alerts based on transfer state
 * 
 * Required variables:
 * - $alert_config['type'] - Alert type (warning, info, success, danger)
 * - $alert_config['title'] - Alert title
 * - $alert_config['message'] - Main alert message
 * - $alert_config['details'] (optional) - Array of detail points
 * - $alert_config['footer_message'] (optional) - Footer message
 * - $alert_config['icon'] (optional) - FontAwesome icon class
 */

$default_config = [
  'type' => 'info',
  'icon' => 'fa-info-circle',
  'title' => 'Information',
  'message' => '',
  'details' => [],
  'footer_message' => '',
  'show_close' => false
];

$alert_config = array_merge($default_config, $alert_config ?? []);

// Map alert types to Bootstrap classes and default icons
$type_mapping = [
  'warning' => ['class' => 'alert-warning', 'icon' => 'fa-exclamation-triangle'],
  'info'    => ['class' => 'alert-info', 'icon' => 'fa-info-circle'],
  'success' => ['class' => 'alert-success', 'icon' => 'fa-check-circle'],
  'danger'  => ['class' => 'alert-danger', 'icon' => 'fa-exclamation-circle']
];

$alert_class = $type_mapping[$alert_config['type']]['class'] ?? 'alert-info';
$alert_icon = $alert_config['icon'] ?: $type_mapping[$alert_config['type']]['icon'];
?>

<section class="alert <?= $alert_class ?> border-<?= $alert_config['type'] ?> bg-white shadow-sm mb-4" 
         role="<?= $alert_config['type'] === 'danger' ? 'alert' : 'status' ?>" 
         aria-live="polite">
  <div class="d-flex align-items-start gap-12px">
    <i class="fa <?= $alert_icon ?> text-<?= $alert_config['type'] ?> pack-alert-icon" aria-hidden="true"></i>
    <div class="flex-grow-1">
      <h2 class="h5 mb-2 text-<?= $alert_config['type'] ?> pack-alert-title">
        <?= htmlspecialchars($alert_config['title'], ENT_QUOTES, 'UTF-8') ?>
      </h2>
      
      <?php if (!empty($alert_config['message'])): ?>
        <p class="mb-2 text-muted"><?= htmlspecialchars($alert_config['message'], ENT_QUOTES, 'UTF-8') ?></p>
      <?php endif; ?>
      
      <?php if (!empty($alert_config['details'])): ?>
        <ul class="mb-2 pl-3">
          <?php foreach ($alert_config['details'] as $detail): ?>
            <li><?= htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      
      <?php if (!empty($alert_config['footer_message'])): ?>
        <p class="mb-0"><strong><?= htmlspecialchars($alert_config['footer_message'], ENT_QUOTES, 'UTF-8') ?></strong></p>
      <?php endif; ?>
    </div>
    
    <?php if ($alert_config['show_close']): ?>
      <button type="button" class="close" data-dismiss="alert" aria-label="Close">
        <span aria-hidden="true">&times;</span>
      </button>
    <?php endif; ?>
  </div>
</section>