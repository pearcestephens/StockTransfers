<?php
/**
 * Parcel Management Panel Component
 * 
 * Left panel of dispatch console for package management
 * 
 * Required variables inherited from parent:
 * - $dispatch_config['transfer_id']
 * - $dispatch_config['from_outlet'] 
 * - $dispatch_config['to_outlet']
 */

$transferId = $dispatch_config['transfer_id'] ?? 0;
$fromOutlet = $dispatch_config['from_outlet'] ?? '';
$toOutlet = $dispatch_config['to_outlet'] ?? '';
?>

<section class="card psx-parcel-card" aria-label="Parcels">
  <header>
    <div class="hdr">Mode</div>
    <div class="psx-parcel-tools">
      <div class="switch psx-parcel-switch" role="group" aria-label="Container type">
        <button class="tog" id="btnSatchel" aria-pressed="true" type="button">Satchel</button>
        <button class="tog" id="btnBox" aria-pressed="false" type="button">Box</button>
      </div>
      <select id="preset" class="btn small" aria-label="Package preset"></select>
      <button class="btn small js-add" type="button">Add</button>
      <button class="btn small js-copy" type="button">Copy</button>
      <button class="btn small js-clear" type="button">Clear</button>
      <button class="btn small js-auto" type="button" title="Auto-assign products">Auto</button>
    </div>
  </header>
  
  <div class="body psx-parcel-body">
    <table class="psx-parcel-table" aria-label="Parcels table">
      <thead>
        <tr>
          <th>#</th>
          <th>Name</th>
          <th>W x L x H (cm)</th>
          <th>Weight</th>
          <th>Items</th>
          <th class="num"></th>
        </tr>
      </thead>
      <tbody id="pkgBody"></tbody>
    </table>

    <div class="card psx-capacity-card">
      <div class="body">
        <div class="psx-capacity-grid">
          <div class="psx-capacity-info">
            <div class="sub psx-capacity-subtitle">Capacity (25 kg boxes, 15 kg satchels)</div>
            <div id="meters"></div>
          </div>
          <div class="psx-slip-preview-wrap">
            <div class="slip-head">
              <div class="sub">Slip Preview</div>
              <div class="slip-actions">
                <button class="btn small" id="btnSlipPrint" type="button">Print slip</button>
              </div>
            </div>
            <div class="slip slip-grid" aria-live="polite">
              <div class="slip-col slip-col-preview">
                <div class="rule"></div>
                <div class="big mono">TRANSFER #<span id="slipT"><?= (int)$transferId ?></span></div>
                <div class="mono">FROM: <b id="slipFrom"><?= htmlspecialchars($fromOutlet, ENT_QUOTES, 'UTF-8') ?></b></div>
                <div class="mono">TO:&nbsp;&nbsp; <b id="slipTo"><?= htmlspecialchars($toOutlet, ENT_QUOTES, 'UTF-8') ?></b></div>
                <div class="mono pack-slip-counter">BOX <span id="slipBox">1</span> of
                  <input id="slipTotal" class="pn pack-slip-total-input" type="number" min="1" value="1" aria-label="Total boxes">
                </div>
                <div class="rule"></div>
              </div>
              <div class="slip-col slip-col-tracking" id="manualTrackingWrap" hidden>
                <div class="sub" style="margin-bottom:2px">Tracking codes / URLs</div>
                <div class="tracking-input-row">
                  <input id="trackingInput" class="form-control form-control-sm" 
                         placeholder="Paste tracking code or URL" autocomplete="off" 
                         aria-label="Tracking code or URL">
                  <button class="btn small" id="trackingAdd" type="button">Add</button>
                </div>
                <ul class="tracking-list" id="trackingList" aria-live="polite" aria-label="Tracking references"></ul>
                <div class="tracking-empty" id="trackingEmpty" aria-hidden="true">No tracking references yet.</div>
                <div class="sub" style="font-size:11px;">These references print on the manual slip when the courier label is handled externally.</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <h3 style="margin:12px 0 6px">Activity & Comments</h3>
    <div class="feed" id="activityFeed" aria-live="polite"></div>
    <form id="commentForm" class="psx-comment-form">
      <div class="psx-comment-grid">
        <input id="commentText" class="form-control form-control-sm psx-note-input" 
               placeholder="Add a note… (saved to history)" autocomplete="off">
        <select id="commentScope" class="form-control form-control-sm">
          <option value="shipment">Shipment</option>
        </select>
        <button class="btn btn-primary btn-sm psx-note-btn" type="submit">Add note</button>
      </div>
    </form>
  </div>
</section>