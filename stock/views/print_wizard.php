<?php
declare(strict_types=1);
/**
 * Shipping & Print Wizard — Dual-mode (Courier + Pickup/Internal)
 * Default is Pickup/Internal; switch to Courier to reveal services/rates/labels.
 * Uses existing endpoints (no backend changes):
 *   /modules/transfers/stock/api/weight_suggest.php
 *   /modules/transfers/stock/api/services_live.php
 *   /modules/transfers/stock/api/rates.php
 *   /modules/transfers/stock/api/create_label.php
 */
$TID = (int)($transferId ?? $transfer ?? $_GET['transfer'] ?? $_GET['t'] ?? 0);
$fromName = htmlspecialchars((string)($fromLbl ?? ''), ENT_QUOTES, 'UTF-8');
$toName   = htmlspecialchars((string)($toLbl   ?? ''), ENT_QUOTES, 'UTF-8');
?>
<section id="print-wizard" class="pw card is-mode-pickup"><!-- DEFAULT: pickup -->
  <header class="pw-head">
    <div class="pw-head__left">
      <div class="pw-logo" aria-hidden="true"></div>
      <div>
        <div class="pw-title">Shipping &amp; Print Wizard</div>
        <div class="pw-sub">
          Transfer #<?= $TID ?: '—' ?>
          <?php if ($fromName || $toName): ?>
            · <?= $fromName ?: '—' ?> → <?= $toName ?: '—' ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Mode toggle (courier hidden until clicked) -->
    <div class="pw-mode" role="tablist" aria-label="Fulfilment mode">
      <button class="pw-mode__btn"          id="pw-mode-courier"  type="button" role="tab" aria-selected="false">Courier</button>
      <button class="pw-mode__btn is-active" id="pw-mode-pickup"   type="button" role="tab" aria-selected="true">Pickup / Internal</button>
    </div>
  </header>

  <!-- Address + status (common) -->
  <div class="pw-addr card-body" id="pw-addrbar">
    <div class="pw-fromto">
      <div><span class="pw-tag">From</span> <span id="pw-from"><?= $fromName ?: '—' ?></span></div>
      <div class="pw-arrow" aria-hidden="true">→</div>
      <div><span class="pw-tag">To</span> <span id="pw-to"><?= $toName ?: '—' ?></span></div>
    </div>
    <div class="pw-badges">
      <span class="pw-chip"                  title="Items in transfer">Items: <b id="pw-items">0</b></span>
      <span class="pw-chip"                  title="Total weight">Total: <b id="pw-total">0.000</b> kg</span>
      <span class="pw-chip"                  title="Lines missing weights">Missing: <b id="pw-missing">0</b></span>
      <span class="pw-chip pw-chip--info"    title="Container cap (kg)">Cap: <b id="pw-cap">—</b> kg</span>
      <span class="pw-chip pw-chip--primary" title="Suggested boxes">Boxes: <b id="pw-boxes">0</b></span>
      <button class="btn btn-sm btn-outline-primary" id="pw-seed">Suggest boxes</button>
    </div>
  </div>

  <!-- ======= COURIER MODE (hidden by default) ======= -->
  <div class="pw-courier d-none">
    <div class="pw-headline card-body">
      <div class="pw-head__right">
        <select id="pw-carrier" class="form-control form-control-sm" aria-label="Carrier">
          <option value="nz_post">NZ Post (Starshipit)</option>
          <option value="gss">NZ Couriers (GoSweetSpot)</option>
        </select>
        <select id="pw-service" class="form-control form-control-sm" aria-label="Service">
          <option value="">Service (live/manual)</option>
        </select>
        <div class="pw-printer-control">
          <div class="input-group input-group-sm">
            <input id="pw-printer" class="form-control" placeholder="Printer or queue" aria-label="Printer" autocomplete="off" list="pw-printer-datalist">
            <div class="input-group-append">
              <button class="btn btn-outline-secondary" type="button" id="pw-printer-set-default">Set Default</button>
            </div>
          </div>
          <div class="pw-printer-meta small text-muted mt-1">
            <span id="pw-printer-default" class="mr-2">Default: <em>None</em></span>
            <span>Recent:</span>
            <span id="pw-printer-recent" class="pw-printer-chips"></span>
          </div>
        </div>
        <datalist id="pw-printer-datalist"></datalist>
      </div>
      <div class="pw-options">
        <label class="pw-check"><input type="checkbox" id="pw-signature" checked> <span>Signature</span></label>
        <label class="pw-check"><input type="checkbox" id="pw-saturday"> <span>Saturday</span></label>
        <label class="pw-check"><input type="checkbox" id="pw-atl"> <span>Authority to leave</span></label>
        <button class="btn btn-sm btn-light" id="pw-override">Override address</button>
      </div>
    </div>

    <!-- Address override (courier only) -->
    <div class="pw-override card-body d-none" id="pw-override-block" role="group" aria-labelledby="pw-override-title">
      <div id="pw-override-title" class="sr-only">Recipient override</div>
      <div class="form-row">
        <div class="col-md-3"><input class="form-control form-control-sm" id="pw-name"     placeholder="Name"></div>
        <div class="col-md-3"><input class="form-control form-control-sm" id="pw-phone"    placeholder="Phone"></div>
        <div class="col-md-6"><input class="form-control form-control-sm" id="pw-street1"  placeholder="Street 1"></div>
        <div class="col-md-6"><input class="form-control form-control-sm" id="pw-street2"  placeholder="Street 2"></div>
        <div class="col-md-3"><input class="form-control form-control-sm" id="pw-suburb"   placeholder="Suburb"></div>
        <div class="col-md-3"><input class="form-control form-control-sm" id="pw-city"     placeholder="City"></div>
        <div class="col-md-2"><input class="form-control form-control-sm" id="pw-postcode" placeholder="Postcode"></div>
        <div class="col-md-2"><input class="form-control form-control-sm" id="pw-country"  value="NZ" placeholder="Country"></div>
        <div class="col-md-2"><button class="btn btn-sm btn-outline-secondary btn-block" id="pw-validate">Validate</button></div>
      </div>
    </div>

    <!-- Packages (courier only) -->
    <div class="pw-pack card-body">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="pw-section">Packages</div>
        <div class="pw-actions">
          <button class="btn btn-sm btn-light" id="pw-add">Add box</button>
          <button class="btn btn-sm btn-light" id="pw-copy">Copy last</button>
          <button class="btn btn-sm btn-outline-danger" id="pw-clear">Clear</button>
          <button class="btn btn-sm btn-outline-secondary" id="pw-quotes">Get live rates</button>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table table-sm pw-table" id="pw-packages" aria-label="Packages">
          <thead><tr>
            <th>Name</th><th>L (cm)</th><th>W (cm)</th><th>H (cm)</th><th>Weight (kg)</th><th></th>
          </tr></thead>
          <tbody></tbody>
        </table>
      </div>
      <div id="pw-rates" class="pw-rates d-none" aria-live="polite"></div>
    </div>
  </div>

  <!-- ======= PICKUP / INTERNAL MODE (default) ======= -->
  <div class="pw-pickup">
    <div class="card-body">
      <div class="pw-section mb-2">Pickup / Internal Delivery</div>
      <div class="form-row">
        <div class="col-md-3">
          <label class="small font-weight-bold">Method</label>
          <select class="form-control form-control-sm" id="pw-pickup-method">
            <option value="driver_pickup">Driver Pickup</option>
            <option value="driver_drop">Driver Drop-off</option>
            <option value="internal" selected>Internal Transfer</option>
          </select>
        </div>
        <div class="col-md-3">
          <label class="small font-weight-bold">Driver / Person</label>
          <input class="form-control form-control-sm" id="pw-pickup-person" placeholder="Driver / Staff name">
        </div>
        <div class="col-md-3">
          <label class="small font-weight-bold">ETA</label>
          <input class="form-control form-control-sm" id="pw-pickup-eta" placeholder="Today 3:30pm">
        </div>
        <div class="col-md-3">
          <label class="small font-weight-bold">Boxes to print</label>
          <input class="form-control form-control-sm" id="pw-pickup-boxes" type="number" min="1" step="1" value="1">
        </div>
        <div class="col-12 mt-2">
          <label class="small font-weight-bold">Notes</label>
          <textarea class="form-control" id="pw-pickup-notes" rows="2" placeholder="Anything the driver needs to know…"></textarea>
        </div>
      </div>
    </div>
  </div>

  <!-- Footer actions (mode aware) -->
  <footer class="pw-foot card-body">
    <div id="pw-feedback" class="text-muted small">&nbsp;</div>
    <div class="pw-btns">
      <!-- Courier -->
      <button class="btn btn-outline-secondary pw-btn-courier d-none" id="pw-preview"><i class="fa fa-eye mr-1"></i> Preview Slips</button>
      <button class="btn btn-primary pw-btn-courier d-none" id="pw-print"><i class="fa fa-print mr-1"></i> Print Slips</button>
      <button class="btn btn-success pw-btn-courier d-none" id="pw-create">Create Labels</button>

      <!-- Pickup -->
      <button class="btn btn-outline-secondary pw-btn-pickup" id="pw-pickup-preview"><i class="fa fa-eye mr-1"></i> Preview Slips</button>
      <button class="btn btn-primary pw-btn-pickup" id="pw-pickup-print"><i class="fa fa-print mr-1"></i> Print Slips</button>

      <!-- Common -->
      <button class="btn btn-success" id="pw-ready"><i class="fa fa-check mr-1"></i> Mark as Ready</button>
    </div>
  </footer>

  <input type="hidden" id="pw-transfer" value="<?= $TID ?>">
</section>
