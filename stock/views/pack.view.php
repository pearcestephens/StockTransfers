<?php
declare(strict_types=1);

$tx    = $transfer ?: ['id'=>0,'outlet_from'=>'','outlet_to'=>'','items'=>[]];
$txId  = (int)($tx['id'] ?? 0);
$items = is_array($tx['items'] ?? null) ? $tx['items'] : [];

$fromRaw = (string)($tx['outlet_from_name'] ?? $tx['outlet_from'] ?? '');
$toRaw   = (string)($tx['outlet_to_name']   ?? $tx['outlet_to']   ?? '');

$fromLbl = htmlspecialchars($fromRaw !== '' ? $fromRaw : (string)($tx['outlet_from'] ?? ''), ENT_QUOTES, 'UTF-8');
$toLbl   = htmlspecialchars($toRaw   !== '' ? $toRaw   : (string)($tx['outlet_to']   ?? ''), ENT_QUOTES, 'UTF-8');

if (!function_exists('tfx_pack_clean_text')) {
  function tfx_pack_clean_text(mixed $value): string {
    $text = trim((string)$value);
    if ($text === '') return '';
    $first = $text[0] ?? '';
    if ($first === '{' || $first === '[') {
      $decoded = json_decode($text, true);
      if (is_array($decoded)) {
        $flat = [];
        array_walk_recursive($decoded, static function($node) use (&$flat): void {
          if ($node === null) return;
          $node = trim((string)$node);
          if ($node !== '') $flat[] = $node;
        });
        if ($flat !== []) $text = implode(',', $flat);
      }
    }
    return trim($text);
  }
}
if (!function_exists('tfx_pack_first_clean')) {
  function tfx_pack_first_clean(array $candidates): string {
    foreach ($candidates as $candidate) {
      $clean = tfx_pack_clean_text($candidate);
      if ($clean !== '') return $clean;
    }
    return '';
  }
}
if (!function_exists('tfx_pack_render_product_cell')) {
  function tfx_pack_render_product_cell(array $item): string {
    $productName  = tfx_pack_first_clean([$item['product_name']??null,$item['name']??null,$item['title']??null,$item['product_label']??null]);
    $variantName  = tfx_pack_first_clean([$item['product_variant']??null,$item['variant_name']??null,$item['variant']??null,$item['option_value']??null]);
    $brandName    = tfx_pack_first_clean([$item['product_brand']??null,$item['brand']??null,$item['manufacturer']??null]);
    $sku          = tfx_pack_clean_text($item['product_sku']??$item['sku']??$item['variant_sku']??'');
    $idLabel      = tfx_pack_clean_text($item['product_id']??$item['vend_product_id']??$item['variant_id']??'');

    $primary = $productName !== '' ? $productName
             : ($variantName !== '' ? $variantName
             : ($sku !== '' ? $sku
             : ($idLabel !== '' ? $idLabel : 'Product')));

    $chips = [];
    if ($variantName !== '' && strcasecmp($variantName, $primary) !== 0) $chips[] = $variantName;
    if ($brandName   !== '' && strcasecmp($brandName,   $primary) !== 0) $chips[] = $brandName;
    if ($sku         !== '' && strcasecmp($sku,         $primary) !== 0) $chips[] = 'SKU '.$sku;

    $chipsHtml = $chips ? ('<div class="tfx-product-meta">'.implode('', array_map(
      fn($c)=>'<span class="tfx-product-chip">'.htmlspecialchars($c,ENT_QUOTES,'UTF-8').'</span>', $chips)).'</div>') : '';

    return '<div class="tfx-product-cell"><strong class="tfx-product-name">'
      . htmlspecialchars($primary, ENT_QUOTES, 'UTF-8') . '</strong>' . $chipsHtml . '</div>';
  }
}
?>
<div class="animated fadeIn">
  <!-- Header / actions -->
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <div>
        <h4 class="card-title mb-0">
          Pack Transfer #<?= $txId ?>
          <br><small class="text-muted"><?= $fromLbl ?> → <?= $toLbl ?></small>
        </h4>
        <div class="small text-muted">Count, label and finalize this consignment</div>
      </div>
      <div class="btn-group">
        <button id="savePack" class="btn btn-primary">
          <i class="fa fa-save mr-1" aria-hidden="true"></i>Save Pack
        </button>
        <button class="btn btn-outline-secondary" id="autofillFromPlanned" type="button" title="Counted = Planned">
          <i class="fa fa-magic mr-1" aria-hidden="true"></i>Autofill
        </button>
      </div>
    </div>

    <div class="card-body transfer-data">
      <!-- Draft / metrics -->
      <div class="d-flex justify-content-between align-items-start w-100 mb-3" id="table-action-toolbar" style="gap:8px;">
        <div class="d-flex flex-column" style="gap:6px;">
          <div class="d-flex align-items-center" style="gap:10px;">
            <div id="draft-indicator" class="draft-indicator is-idle" data-state="idle" aria-live="polite">
              <span class="draft-indicator__dot" aria-hidden="true"></span>
              <span class="draft-indicator__label" id="draft-indicator-text">IDLE</span>
            </div>
            <span class="text-muted small" id="draft-last-saved">Not saved</span>
          </div>
          <div class="small text-muted">Drafts auto-save to this browser.</div>
        </div>
        <div class="d-flex align-items-center flex-wrap" style="gap:10px;">
          <span>Items: <strong id="itemsToTransfer"><?= count($items) ?></strong></span>
          <span>Planned total: <strong id="plannedTotal">0</strong></span>
          <span>Counted total: <strong id="countedTotal">0</strong></span>
          <span>Diff: <strong id="diffTotal">0</strong></span>
        </div>
      </div>

      <!-- Items table -->
      <div class="card tfx-card-tight mb-3" id="table-card">
        <div class="card-body py-2">
          <table class="table table-responsive-sm table-bordered table-striped table-sm" id="transfer-table">
            <thead>
              <tr>
                <th style="width:38px;"></th>
                <th>Product</th>
                <th>Planned Qty</th>
                <th>Counted Qty</th>
                <th>To</th>
                <th>ID</th>
              </tr>
            </thead>
            <tbody id="productSearchBody">
              <?php
              $row = 0;
              if ($items) {
                foreach ($items as $i) {
                  $row++;
                  $iid       = (int)($i['id'] ?? 0);
                  $planned   = (int)($i['qty_requested'] ?? 0);
                  $sentSoFar = (int)($i['qty_sent_total'] ?? 0);
                  $inventory = max($planned, $sentSoFar);
                  if ($planned <= 0) continue;
                  echo '<tr data-inventory="'.$inventory.'" data-planned="'.$planned.'">';
                  echo "<td class='text-center align-middle'>
                          <button class='btn btn-outline-secondary btn-sm' type='button' data-action='remove-product' title='Remove'>
                            <i class='fa fa-times' aria-hidden='true'></i>
                          </button>
                          <input type='hidden' class='productID' value='{$iid}'>
                        </td>";
                  echo '<td>'. tfx_pack_render_product_cell($i) .'</td>';
                  echo '<td class="planned">'.$planned.'</td>';
                  echo "<td class='counted-td'>
                          <input type='number' min='0' max='{$inventory}' value='".($sentSoFar?:'')."' class='form-control form-control-sm tfx-num'>
                          <span class='counted-print-value d-none'>".($sentSoFar?:0)."</span>
                        </td>";
                  echo '<td>'.$toLbl.'</td>';
                  echo '<td><span class="id-counter">'.$txId.'-'.$row.'</span></td>';
                  echo '</tr>';
                }
              } else {
                echo '<tr><td colspan="6" class="text-center text-muted py-4">No items on this transfer.</td></tr>';
              }
              ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Unified Shipping & Print -->
      <?php include __DIR__ . '/ship_wizard.php'; ?>

      <!-- Notes & Manual tracking -->
      <div class="row mt-3">
        <div class="col-md-6 mb-3">
          <label class="mb-2"><strong>Notes &amp; Discrepancies</strong></label>
          <textarea class="form-control" id="notesForTransfer" rows="4" placeholder="Enter any notes..."></textarea>
        </div>
        <div class="col-md-6 mb-3">
          <label class="mb-2"><strong>Manual Tracking Numbers</strong></label>
          <div id="tracking-items" class="mb-2"></div>
          <button type="button" class="btn btn-sm btn-outline-primary" id="btn-add-tracking">
            <i class="fa fa-plus"></i> Add tracking number
          </button>
          <div class="mt-2 small text-muted"><span id="tracking-count">0 numbers</span></div>
        </div>
      </div>

      <!-- Hidden for JS -->
      <input type="hidden" id="transferID" value="<?= $txId ?>">
      <input type="hidden" id="sourceID"   value="<?= $fromLbl ?>">
      <input type="hidden" id="destinationID" value="<?= $toLbl ?>">
      <input type="hidden" id="staffID"    value="<?= (int)($_SESSION['userID'] ?? 0) ?>">
    </div>
  </div>
</div>
