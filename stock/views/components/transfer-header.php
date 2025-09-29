<?php
/**
 * Transfer Header Component
 * 
 * Displays transfer information and primary actions.
 * Now supports an optional 'wrapper_class' in $header_config to allow
 * caller to override outer <section> classes (e.g. to visually join with
 * adjacent cards while keeping files/components separate.
 * 
 * Config keys:
 * - transfer_id (int)
 * - title (string)
 * - title_id (string, optional - for aria-labelledby linkage)
 * - subtitle (string)
 * - description (string)
 * - actions (array)
 * - metrics (array)
 * - show_draft_status (bool)
 * - draft_status (array: state,text,last_saved)
 * - wrapper_class (string, optional) Custom classes for outer section. Defaults to 'card mb-3'.
 */

$default_config = [
  'transfer_id' => 0,
  'title' => 'Transfer',
  'subtitle' => '',
  'description' => '',
  'actions' => [],
  'metrics' => [],
  'show_draft_status' => true,
  'draft_status' => [
    'state' => 'idle',
    'text' => 'Idle',
    'last_saved' => 'Not saved'
  ]
];

$header_config = array_merge($default_config, $header_config ?? []);
$wrapper_class = trim($header_config['wrapper_class'] ?? 'card mb-3');
$wrapper_class = htmlspecialchars($wrapper_class, ENT_QUOTES, 'UTF-8');
?>

<section class="<?= $wrapper_class ?>" aria-labelledby="<?= htmlspecialchars($header_config['title_id'] ?? 'transfer-title', ENT_QUOTES, 'UTF-8') ?>">
  <div class="card-header d-flex justify-content-between align-items-center">
    <div>
      <h1 class="card-title h4 mb-0" id="<?= htmlspecialchars($header_config['title_id'] ?? 'transfer-title', ENT_QUOTES, 'UTF-8') ?>">
        <?= htmlspecialchars($header_config['title'], ENT_QUOTES, 'UTF-8') ?>
        <?php if ($header_config['transfer_id']): ?>
          #<?= (int)$header_config['transfer_id'] ?>
        <?php endif; ?>
        
        <?php if (!empty($header_config['subtitle'])): ?>
          <br><small class="text-muted"><?= htmlspecialchars($header_config['subtitle'], ENT_QUOTES, 'UTF-8') ?></small>
        <?php endif; ?>
      </h1>
      
      <?php if (!empty($header_config['description'])): ?>
        <p class="small text-muted mb-0"><?= htmlspecialchars($header_config['description'], ENT_QUOTES, 'UTF-8') ?></p>
      <?php endif; ?>
    </div>
    
    <?php if (!empty($header_config['actions'])): ?>
      <div class="btn-group" role="group" aria-label="Primary actions">
        <?php foreach ($header_config['actions'] as $action): ?>
          <button id="<?= htmlspecialchars($action['id'], ENT_QUOTES, 'UTF-8') ?>" 
                  class="btn <?= htmlspecialchars($action['class'] ?? 'btn-primary', ENT_QUOTES, 'UTF-8') ?>"
                  <?= !empty($action['type']) ? 'type="' . htmlspecialchars($action['type'], ENT_QUOTES, 'UTF-8') . '"' : 'type="button"' ?>
                  <?= !empty($action['title']) ? 'title="' . htmlspecialchars($action['title'], ENT_QUOTES, 'UTF-8') . '"' : '' ?>
                  <?= !empty($action['disabled']) ? 'disabled' : '' ?>>
            <?php if (!empty($action['icon'])): ?>
              <i class="fa <?= htmlspecialchars($action['icon'], ENT_QUOTES, 'UTF-8') ?> mr-1" aria-hidden="true"></i>
            <?php endif; ?>
            <?= htmlspecialchars($action['label'], ENT_QUOTES, 'UTF-8') ?>
          </button>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($header_config['show_draft_status'] || !empty($header_config['metrics'])): ?>
    <div class="card-body transfer-data">
      <div class="d-flex justify-content-between align-items-start w-100 mb-3 gap-8px" id="table-action-toolbar">
        <?php if ($header_config['show_draft_status']): ?>
          <div class="d-flex flex-column gap-6px">
            <div class="d-flex align-items-center gap-10px">
              <button type="button" 
                      id="draft-indicator" 
                      class="draft-status-pill status-<?= htmlspecialchars($header_config['draft_status']['state'], ENT_QUOTES, 'UTF-8') ?>"
                      data-state="<?= htmlspecialchars($header_config['draft_status']['state'], ENT_QUOTES, 'UTF-8') ?>" 
                      aria-live="polite"
                      aria-label="Draft status: <?= htmlspecialchars($header_config['draft_status']['state'], ENT_QUOTES, 'UTF-8') ?>. <?= htmlspecialchars($header_config['draft_status']['text'], ENT_QUOTES, 'UTF-8') ?>." 
                      title="Draft status" 
                      disabled>
                <span class="pill-icon" aria-hidden="true"></span>
                <span class="pill-text" id="draft-indicator-text"><?= htmlspecialchars($header_config['draft_status']['text'], ENT_QUOTES, 'UTF-8') ?></span>
              </button>
            </div>
            <div class="small text-muted"><span id="draft-last-saved"><?= htmlspecialchars($header_config['draft_status']['last_saved'], ENT_QUOTES, 'UTF-8') ?></span></div>
          </div>
        <?php endif; ?>

        <?php if (!empty($header_config['metrics'])): ?>
          <div class="d-flex align-items-center flex-wrap gap-10px">
            <?php foreach ($header_config['metrics'] as $metric): ?>
              <span>
                <?= htmlspecialchars($metric['label'], ENT_QUOTES, 'UTF-8') ?>: 
                <strong id="<?= htmlspecialchars($metric['id'] ?? '', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($metric['value'], ENT_QUOTES, 'UTF-8') ?></strong>
              </span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</section>