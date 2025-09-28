<?php
declare(strict_types=1);

/**
 * Content-only partial. Requires #transferID or ?transfer in URL.
 * This partial is used by pack.view.php above.
 */

$tid = 0;
foreach (['transfer','transfer_id','id','t'] as $k) {
  if (isset($_GET[$k]) && (int)$_GET[$k] > 0) { $tid = (int)$_GET[$k]; break; }
}
?>
<section id="ship-wizard" class="sw card" data-transfer="<?= (int)$tid ?>">
  <header class="sw__head">
    <div class="sw__brand">
      <div class="sw__logo" aria-hidden="true"></div>
      <div class="sw__titles">
        <div class="sw__title">Shipping &amp; Print</div>
        <div class="sw__subtitle">Transfer #<span id="sw-tid"><?= $tid ?: '—' ?></span></div>
      </div>
    </div>

    <div class="sw__controls">
      <div class="sw__mode" role="tablist" aria-label="Fulfilment mode">
        <button class="sw__modebtn is-active" data-mode="pickup" type="button" role="tab" aria-selected="true">Pickup/Internal</button>
        <button class="sw__modebtn"          data-mode="courier" type="button" role="tab" aria-selected="false">Courier</button>
      </div>

      <div class="sw__carrier">
        <select id="sw-carrier" class="form-control form-control-sm" aria-label="Carrier" disabled>
          <option value="nz_post">NZ Post (Starshipit)</option>
          <option value="gss">NZ Couriers (GoSweetSpot)</option>
          <option value="manual">Manual</option>
        </select>
        <select id="sw-service" class="form-control form-control-sm" aria-label="Service" disabled>
          <option value="">Service (live/manual)</option>
        </select>

        <div class="sw__printer d-none" id="sw-printer-wrap">
          <input id="sw-printer" class="form-control form-control-sm" placeholder="GSS printer (optional)" list="sw-printer-datalist" />
          <datalist id="sw-printer-datalist"></datalist>
          <small class="text-muted d-block mt-1">
            Default: <em id="sw-printer-default">None</em>
            <span id="sw-printer-recent" class="sw__printerchips"></span>
          </small>
        </div>
      </div>
    </div>
  </header>

  <div id="sw-warn" class="alert alert-warning d-none" role="alert"></div>

  <section class="sw__status">
    <div class="sw__fromto">
      <div><span class="sw__tag">From</span><span id="sw-from">—</span></div>
      <div class="sw__arrow" aria-hidden="true">→</div>
      <div><span class="sw__tag">To</span><span id="sw-to">—</span></div>
    </div>
    <div class="sw__chips">
      <span class="sw__chip"              title="Items">Items: <b id="sw-items">0</b></span>
      <span class="sw__chip"              title="Total weight">Total: <b id="sw-total">0.000</b> kg</span>
      <span class="sw__chip"              title="Missing weights">Missing: <b id="sw-missing">0</b></span>
      <span class="sw__chip sw__chip--info"    title="Container cap">Cap: <b id="sw-cap">—</b> kg</span>
      <span class="sw__chip sw__chip--primary" title="Suggested boxes">Boxes: <b id="sw-boxes">0</b></span>
      <button class="btn btn-outline-primary btn-sm" id="sw-suggest">Suggest boxes</button>
    </div>
  </section>

  <section class="sw__body">
    <div class="sw__left">
      <header class="sw__sec">
        <div class="sw__sectitle">Packages</div>
        <div class="sw__actions">
          <button class="btn btn-sm btn-light"            id="sw-add">Add box</button>
          <button class="btn btn-sm btn-light"            id="sw-copy">Copy last</button>
          <button class="btn btn-sm btn-outline-danger"   id="sw-clear">Clear</button>
          <button class="btn btn-sm btn-outline-secondary sw__courieronly d-none" id="sw-quotes">Get live rates</button>
          <button class="btn btn-sm btn-light sw__courieronly d-none" id="sw-override">Override address</button>
        </div>
      </header>

      <div id="sw-override-block" class="sw__override d-none" role="group" aria-labelledby="sw-override-title">
        <div id="sw-override-title" class="sr-only">Recipient override</div>
        <div class="form-row">
          <div class="col-md-3"><input class="form-control form-control-sm" id="sw-name"     placeholder="Name"></div>
          <div class="col-md-3"><input class="form-control form-control-sm" id="sw-phone"    placeholder="Phone"></div>
          <div class="col-md-6"><input class="form-control form-control-sm" id="sw-street1"  placeholder="Street 1"></div>
          <div class="col-md-6"><input class="form-control form-control-sm" id="sw-street2"  placeholder="Street 2"></div>
          <div class="col-md-3"><input class="form-control form-control-sm" id="sw-suburb"   placeholder="Suburb"></div>
          <div class="col-md-3"><input class="form-control form-control-sm" id="sw-city"     placeholder="City"></div>
          <div class="col-md-2"><input class="form-control form-control-sm" id="sw-postcode" placeholder="Postcode"></div>
          <div class="col-md-2"><input class="form-control form-control-sm" id="sw-country"  value="NZ" placeholder="Country"></div>
          <div class="col-md-2"><button class="btn btn-sm btn-outline-secondary btn-block" id="sw-validate">Validate</button></div>
        </div>
      </div>

      <div class="table-responsive">
        <table class="table table-sm sw__table" id="sw-packages" aria-label="Packages">
          <thead>
            <tr><th>Name</th><th>L(cm)</th><th>W(cm)</th><th>H(cm)</th><th>Weight(kg)</th><th></th></tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>

      <div id="sw-rates" class="sw__rates d-none" aria-live="polite"></div>
    </div>

    <aside class="sw__right">
      <div class="sw__prefs sw__courieronly d-none">
        <label class="sw__check"><input type="checkbox" id="sw-signature" checked><span>Signature</span></label>
        <label class="sw__check"><input type="checkbox" id="sw-saturday"><span>Saturday</span></label>
        <label class="sw__check"><input type="checkbox" id="sw-atl"><span>Authority to leave</span></label>
      </div>

      <div class="sw__summary">
        <div class="sw__row"><span class="sw__label">Carrier</span><span class="sw__val" id="sw-summary-carrier">Pickup/Internal</span></div>
        <div class="sw__row"><span class="sw__label">Total weight</span><span class="sw__val" id="sw-summary-weight">0.000 kg</span></div>
        <div class="sw__row"><span class="sw__label">Packages</span><span class="sw__val" id="sw-summary-packages">0 pkgs</span></div>
        <div class="sw__row" id="sw-summary-quote" hidden>
          <span class="sw__label">Best service</span>
          <span class="sw__val"><span id="sw-best-name">—</span> <span id="sw-best-price"></span></span>
        </div>
      </div>

      <div id="sw-feedback" class="text-muted small mt-2">&nbsp;</div>

      <div class="sw__buttons">
        <button class="btn btn-outline-secondary"             id="sw-preview"><i class="fa fa-eye mr-1"></i>Preview Slips</button>
        <button class="btn btn-primary"                       id="sw-print"><i class="fa fa-print mr-1"></i>Print Slips</button>
        <button class="btn btn-success sw__courieronly d-none" id="sw-create"><i class="fa fa-truck mr-1"></i>Create Labels</button>
        <button class="btn btn-success"                       id="sw-ready"><i class="fa fa-check mr-1"></i>Mark Ready</button>
      </div>
    </aside>
  </section>

  <input type="hidden" id="transferID" value="<?= (int)$tid ?>">
</section>
