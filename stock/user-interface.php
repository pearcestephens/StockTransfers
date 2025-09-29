<?php
declare(strict_types=1);

/**
 * Dispatch Console (View)
 * - Pure front-end; no carrier calls here.
 * - Passes meta + endpoints + tokens to JS via window.DISPATCH_BOOT.
 * Replace the dummy values with real ones from your session/DB.
 */

// ---- Replace these with your real values ----
$transferId   = (int)($_GET['transfer'] ?? 12345);
$fromOutletId = 1;
$toOutletId   = 7;
$fromOutlet   = 'Hamilton East';
$toOutlet     = 'Glenfield';
$fromLine     = 'Hamilton East | Hamilton | 3216 | New Zealand | 027 774 2792 | hamilton@vapeshed.co.nz';

// Tokens are managed server-side; we only expose if you choose to.
$apiKey       = getenv('CIS_API_KEY') ?: 'CHANGE_ME_OPTIONAL';
$nzPostToken  = getenv('NZPOST_TOKEN') ?: 'NZPOST_TOKEN_HERE';
$gssToken     = getenv('GSS_TOKEN') ?: 'GSS_TOKEN_HERE';

// Endpoints (adjust paths if different in your repo)
$ENDPOINTS = [
  'rates'         => '/modules/transfers/stock/api/rates.php',
  'create'        => '/modules/transfers/stock/api/create_label.php',
  'address_facts' => '/modules/transfers/stock/api/address_facts.php',
  'printers'      => '/modules/transfers/stock/api/printers_health.php'
];
?>

  <link rel="stylesheet" href="https://staff.vapeshed.co.nz/modules/transfers/stock/assets/css/dispatch.css?v=1.1">

  <div class="wrapper">
    <!-- HEADER -->
    <div class="hcard">
      <div class="hrow">
        <div class="brand">
          <div class="logo" aria-hidden="true"></div>
          <div>
            <h1>Dispatch Console</h1>
            <div class="sub" id="fromLine"><?= htmlspecialchars($fromLine, ENT_QUOTES) ?></div>
          </div>
        </div>
        <div class="rowline" aria-label="Printers">
          <span class="pstat"><span class="dot ok" id="p1dot"></span> <span id="p1name">Front Desk (80 mm)</span></span>
          <span class="pstat"><span class="dot ok" id="p2dot"></span> <span id="p2name">Warehouse Labeler</span></span>
          <button class="btn small" id="btnSettings" type="button">Settings</button>
        </div>
      </div>
      <div class="hrow" style="border-top:1px solid var(--line);background:linear-gradient(90deg,#eef2ff,#eef0ff)">
        <div class="rowline" aria-label="Route">
          <span class="k"><span class="small">FROM</span> <b id="fromOutlet"><?= htmlspecialchars($fromOutlet) ?></b></span>
          <span class="arrow">→</span>
          <span class="k"><span class="small">TO</span> <b id="toOutlet"><?= htmlspecialchars($toOutlet) ?></b></span>
          <span class="k"><span class="small">You are</span> <b>Warehouse</b></span>
        </div>
        <nav class="tnav" aria-label="Method">
          <a href="#" class="tab" data-method="courier" aria-current="page">Courier</a>
          <a href="#" class="tab" data-method="pickup">Pickup</a>
          <a href="#" class="tab" data-method="internal">Internal</a>
          <a href="#" class="tab" data-method="dropoff">Drop-off</a>
        </nav>
      </div>
    </div>

    <!-- GRID -->
    <div class="grid">
      <!-- LEFT -->
      <section class="card" aria-label="Parcels">
        <header>
          <div class="hdr">Mode</div>
          <div style="display:flex;gap:8px;align-items:center">
            <div class="switch" role="group" aria-label="Container type">
              <button class="tog" id="btnSatchel" aria-pressed="true" type="button">Satchel</button>
              <button class="tog" id="btnBox" aria-pressed="false" type="button">Box</button>
            </div>
            <select id="preset" class="btn small" aria-label="Package preset"></select>
            <button class="btn small js-add"  type="button">Add</button>
            <button class="btn small js-copy" type="button">Copy</button>
            <button class="btn small js-clear" type="button">Clear</button>
            <button class="btn small js-auto"  type="button" title="Auto-assign products">Auto</button>
          </div>
        </header>
        <div class="body">
          <table aria-label="Parcels table">
            <thead><tr>
              <th>#</th><th>Name</th><th>W×L×H (cm)</th><th>Weight</th><th>Items</th><th class="num"></th>
            </tr></thead>
            <tbody id="pkgBody"></tbody>
          </table>

          <div class="card" style="border:1px dashed var(--line);margin-top:12px">
            <div class="body">
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div>
                  <div class="sub" style="margin-bottom:6px">Capacity (25 kg boxes, 15 kg satchels)</div>
                  <div id="meters" style="display:grid;gap:8px"></div>
                </div>
                <div>
                  <div class="sub" style="margin-bottom:6px">Slip Preview</div>
                  <div class="slip">
                    <div class="rule"></div>
                    <div class="big mono">TRANSFER #<span id="slipT"><?= $transferId ?></span></div>
                    <div class="mono">FROM: <b id="slipFrom"><?= htmlspecialchars($fromOutlet) ?></b></div>
                    <div class="mono">TO:&nbsp;&nbsp; <b id="slipTo"><?= htmlspecialchars($toOutlet) ?></b></div>
                    <div class="mono" style="margin-top:6px">BOX <span id="slipBox">1</span> of
                      <input id="slipTotal" class="pn" type="number" min="1" value="1" style="width:70px" aria-label="Total boxes"></div>
                    <div class="rule"></div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <h3 style="margin:12px 0 6px">Activity & Comments</h3>
          <div class="feed" id="activityFeed" aria-live="polite"></div>
          <form id="commentForm" style="margin-top:8px">
            <div style="display:grid;grid-template-columns:1fr 160px auto;gap:10px;align-items:center">
              <input id="commentText" placeholder="Add a note… (saved to history)" />
              <select id="commentScope"><option value="shipment">Shipment</option></select>
              <button class="btn small" type="submit">Add</button>
            </div>
          </form>
        </div>
      </section>

      <!-- RIGHT -->
      <aside class="card" aria-label="Options & Rates" style="position:relative">
        <header>
          <div class="hdr">Options & Rates</div>
          <span class="badge">Incl GST</span>
        </header>

        <div class="blocker" id="uiBlock">
          <div class="msg">
            <div style="font-weight:700;margin-bottom:6px">All printers offline</div>
            <div class="sub" style="margin-bottom:10px">Switched to Manual Tracking mode.</div>
            <button class="btn small" id="dismissBlock" type="button">Ok</button>
          </div>
        </div>

        <div class="body">
          <div style="display:grid;gap:10px;margin-bottom:10px">
            <div style="display:flex;gap:12px;flex-wrap:wrap">
              <label><input type="checkbox" id="optSig" checked> Sig</label>
              <label><input type="checkbox" id="optATL"> ATL</label>
              <label title="R18 disabled for B2B"><input type="checkbox" id="optAge" disabled> R18</label>
              <label><input type="checkbox" id="optSat"> Saturday</label>
            </div>

            <!-- Reviewed By appears only in manual / pickup / internal / drop-off -->
            <div id="reviewedWrap" style="display:none">
              <label>Reviewed By <input id="reviewedBy" placeholder="Staff initials / name"></label>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;background:#fcfdff;border:1px solid var(--line);border-radius:12px;padding:8px">
              <div><b>Address facts</b>
                <div class="sub">Rural: <span id="factRural" class="mono">—</span></div>
                <div class="sub">Saturday serviceable: <span id="factSat" class="mono">—</span></div>
              </div>
              <div><b>Notes</b><div class="sub">Saturday auto-disables if address isn’t serviceable.</div></div>
            </div>
          </div>

          <!-- Courier rates -->
          <div id="blkCourier">
            <div class="rates" id="ratesList"></div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px">
              <div>
                <div class="sub">Selected</div>
                <div id="sumCarrier" style="font-weight:700">—</div>
                <div id="sumService" class="sub">—</div>
              </div>
              <div style="text-align:right">
                <div class="sub">Total (Incl GST)</div>
                <div id="sumTotal" style="font-weight:700;font-size:18px">$0.00</div>
              </div>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:10px">
              <button class="btn" id="btnPrintOnly" type="button">Print only</button>
              <button class="btn primary" id="btnPrintPacked" type="button">Print &amp; Mark Packed</button>
            </div>
          </div>

          <!-- Other methods -->
          <div id="blkPickup" hidden>
            <div style="display:grid;gap:8px">
              <label>Picked up by <input id="pickupBy" placeholder="Driver / Company"></label>
              <label>Contact phone <input id="pickupPhone" placeholder="+64…"></label>
              <label>Pickup time <input id="pickupTime" type="datetime-local"></label>
              <label>Parcels <input id="pickupPkgs" type="number" min="1" value="1"></label>
              <label>Notes <textarea id="pickupNotes" rows="2"></textarea></label>
            </div>
            <div style="display:flex;justify-content:flex-end;margin-top:10px">
              <button class="btn primary" id="btnSavePickup" type="button">Save Pickup</button>
            </div>
          </div>

          <div id="blkInternal" hidden>
            <div style="display:grid;gap:8px">
              <label>Driver/Van <input id="intCarrier" placeholder="Internal run name"></label>
              <label>Depart <input id="intDepart" type="datetime-local"></label>
              <label>Boxes <input id="intBoxes" type="number" min="1" value="1"></label>
              <label>Notes <textarea id="intNotes" rows="2"></textarea></label>
            </div>
            <div style="display:flex;justify-content:flex-end;margin-top:10px">
              <button class="btn primary" id="btnSaveInternal" type="button">Save Internal</button>
            </div>
          </div>

          <div id="blkDropoff" hidden>
            <div style="display:grid;gap:8px">
              <label>Drop-off location <input id="dropLocation" placeholder="NZ Post / NZC depot"></label>
              <label>When <input id="dropWhen" type="datetime-local"></label>
              <label>Boxes <input id="dropBoxes" type="number" min="1" value="1"></label>
              <label>Notes <textarea id="dropNotes" rows="2"></textarea></label>
            </div>
            <div style="display:flex;justify-content:flex-end;margin-top:10px">
              <button class="btn primary" id="btnSaveDrop" type="button">Save Drop-off</button>
            </div>
          </div>

          <div id="blkManual" hidden>
            <div class="hdr" style="margin:6px 0">Manual Tracking</div>
            <div style="display:grid;gap:8px">
              <label>Carrier<select id="mtCarrier"><option>NZ Post</option><option>NZ Couriers</option></select></label>
              <label>Tracking #<input id="mtTrack" placeholder="Ticket / tracking number"></label>
            </div>
            <div style="display:flex;justify-content:flex-end;margin-top:10px">
              <button class="btn primary" id="btnSaveManual" type="button">Save Number</button>
            </div>
          </div>
        </div>
      </aside>
    </div>

    <!-- FOOTER -->
    <div class="footer">
      <div class="wrap">
        <div class="row">
          <div class="sub mono" id="footStatus">Transfer #<?= $transferId ?> · ready</div>
          <div class="right" role="group" aria-label="Footer actions">
            <button class="btn" id="btnReset"  type="button">Reset</button>
            <button class="btn" id="btnCancel" type="button">Cancel</button>
            <button class="btn primary" id="btnReady" type="button">Confirm &amp; Print</button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Boot payload for JS -->
  <script>
  window.DISPATCH_BOOT = {
    transferId: <?= (int)$transferId ?>,
    fromOutletId: <?= (int)$fromOutletId ?>,
    toOutletId: <?= (int)$toOutletId ?>,
    fromOutlet: <?= json_encode($fromOutlet) ?>,
    toOutlet: <?= json_encode($toOutlet) ?>,
    fromLine: <?= json_encode($fromLine) ?>,
    endpoints: <?= json_encode($ENDPOINTS, JSON_UNESCAPED_SLASHES) ?>,
    tokens: { apiKey: <?= json_encode($apiKey) ?>, nzPost: <?= json_encode($nzPostToken) ?>, gss: <?= json_encode($gssToken) ?> }
  };
  </script>
  <script src="https://staff.vapeshed.co.nz/modules/transfers/stock/assets/js/dispatch.js?v=1.1"></script>

