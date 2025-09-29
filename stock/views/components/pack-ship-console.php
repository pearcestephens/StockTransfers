<?php
/**
 * Pack & Ship Console Component
 * 
 * Main dispatch UI for courier/pickup/internal/dropoff methods
 * 
 * Required variables:
 * - $dispatch_config['transfer_id'] - Transfer ID
 * - $dispatch_config['from_outlet'] - Source outlet name
 * - $dispatch_config['to_outlet'] - Destination outlet name
 * - $dispatch_config['from_line'] - Source outlet details
 * - $dispatch_config['to_line'] - Destination outlet details
 * - $dispatch_config['show_courier_detail'] - Show full courier UI
 * - $dispatch_config['print_pool'] - Print pool status
 * - $dispatch_config['freight_metrics'] - Weight/item metrics
 * - $dispatch_config['manual_summary'] - Manual mode summary
 */

$default_config = [
  'transfer_id' => 0,
  'from_outlet' => '',
  'to_outlet' => '',
  'from_line' => '',
  'to_line' => '',
  'show_courier_detail' => false,
  'print_pool' => [
    'online' => false,
    'status_text' => 'Print pool offline',
    'meta_text' => 'Awaiting printer status'
  ],
  'freight_metrics' => [
    'total_weight_kg' => 0.0,
    'total_items' => 0,
    'package_count' => 1
  ],
  'manual_summary' => [
    'weight_label' => '—',
    'boxes_label' => '—'
  ],
  'methods' => [
    ['key' => 'courier', 'label' => 'Courier', 'current' => true],
    ['key' => 'pickup', 'label' => 'Pickup', 'current' => false],
    ['key' => 'internal', 'label' => 'Internal', 'current' => false],
    ['key' => 'dropoff', 'label' => 'Drop-off', 'current' => false]
  ]
];

$dispatch_config = array_merge($default_config, $dispatch_config ?? []);
$showCourierDetail = $dispatch_config['show_courier_detail'];
$printPoolOnline = $dispatch_config['print_pool']['online'];
?>

<section id="psx-app" class="psx<?= $showCourierDetail ? '' : ' psx-manual-mode' ?>" aria-label="Pack & Ship Panel">
  <div class="wrapper">
    <!-- HEADER & BODY -->
    <div class="hcard">
      <div class="hrow hrow-main">
        <div class="brand">
          <div class="logo" aria-hidden="true"></div>
          <div class="title-block">
            <h1>
              Transfer <span class="mono">#<?= (int)$dispatch_config['transfer_id'] ?></span>
              → <span class="dest-text" id="headDestination"><?= htmlspecialchars($dispatch_config['to_outlet'], ENT_QUOTES, 'UTF-8') ?></span>
            </h1>
            <div class="subtitle">
              Origin: <span id="headFrom"><?= htmlspecialchars($dispatch_config['from_outlet'], ENT_QUOTES, 'UTF-8') ?></span>
              · Your role: <span id="headRole">Warehouse</span>
            </div>
            <div class="contact-line">
              <span class="label">Dispatching from:</span>
              <span class="value" id="fromLine"><?= htmlspecialchars($dispatch_config['from_line'], ENT_QUOTES, 'UTF-8') ?></span><br>
              <span class="divider" aria-hidden="true">→</span>
              <span class="label">Recipient:</span>
              <span class="value" id="toLine"><?= htmlspecialchars($dispatch_config['to_line'], ENT_QUOTES, 'UTF-8') ?></span>
            </div>
          </div>
        </div>
        <div class="status-block" aria-label="Print pool status"<?= $showCourierDetail ? '' : ' hidden'; ?>>
          <span class="pstat" id="printPoolStatus">
            <span class="dot <?= $printPoolOnline ? 'ok' : 'err' ?>" id="printPoolDot"></span>
            <span id="printPoolText"><?= htmlspecialchars($dispatch_config['print_pool']['status_text'], ENT_QUOTES, 'UTF-8') ?></span>
          </span>
          <span class="status-meta" id="printPoolMeta"><?= htmlspecialchars($dispatch_config['print_pool']['meta_text'], ENT_QUOTES, 'UTF-8') ?></span>
          <button class="btn small" id="btnSettings" type="button">Settings</button>
        </div>
      </div>
      
      <div class="hrow hrow-meta">
        <div class="meta-chips">
          <div class="chip chip-primary" aria-label="Destination outlet">
            <span class="label">Destination</span>
            <span class="value" id="toOutlet"><?= htmlspecialchars($dispatch_config['to_outlet'], ENT_QUOTES, 'UTF-8') ?></span>
          </div>
          <div class="chip">
            <span class="label">Origin</span>
            <span class="value" id="fromOutlet"><?= htmlspecialchars($dispatch_config['from_outlet'], ENT_QUOTES, 'UTF-8') ?></span>
          </div>
          <div class="chip">
            <span class="label">Transfer</span>
            <span class="value mono">#<?= (int)$dispatch_config['transfer_id'] ?></span>
          </div>
          <div class="chip">
            <span class="label">Your role</span>
            <span class="value">Warehouse</span>
          </div>
        </div>
        <nav class="tnav" aria-label="Method">
          <?php foreach ($dispatch_config['methods'] as $method): ?>
            <a href="#" 
               class="tab" 
               data-method="<?= htmlspecialchars($method['key'], ENT_QUOTES, 'UTF-8') ?>"
               <?= $method['current'] ? 'aria-current="page"' : '' ?>>
              <?= htmlspecialchars($method['label'], ENT_QUOTES, 'UTF-8') ?>
            </a>
          <?php endforeach; ?>
        </nav>
      </div>
    </div>

    <div class="hcard-body">
      <!-- GRID -->
      <div class="grid">
        <!-- LEFT PANEL - Package Management -->
        <?php include __DIR__ . '/dispatch/parcel-panel.php'; ?>
        
        <!-- RIGHT PANEL - Rates & Options -->
        <?php include __DIR__ . '/dispatch/rates-panel.php'; ?>
      </div>
    </div>
  </div>
</section>