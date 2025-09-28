<?php
declare(strict_types=1);

/**
 * CIS — Pack & Ship (Slim Power UI)
 * Purpose: minimal, colourful, powerful panel embedded in CIS template.
 * - Uses your existing template includes and services under /modules/transfers/stock/api/
 * - Right-rail big buttons; sticky bottom actions blended into the page.
 */

// --- BEGIN: Modules autoloader (same pattern as stock/pack.php) ---
spl_autoload_register(static function(string $class): void {
  $prefix = 'Modules\\';
  if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;

  $rel = substr($class, strlen($prefix));            // e.g. Transfers\Stock\Services\TransfersService
  $relSlashes = str_replace('\\', '/', $rel);

  // Base modules dir
  $base = $_SERVER['DOCUMENT_ROOT'] . '/modules/';

  // Try exact path first: /modules/Transfers/Stock/Services/TransfersService.php
  $p1 = $base . $relSlashes . '.php';
  if (is_file($p1)) { require_once $p1; return; }

  // Try lowercased subdirs: /modules/transfers/stock/services/TransfersService.php
  $parts = explode('/', $relSlashes);
  $file  = array_pop($parts);
  $dir   = strtolower(implode('/', $parts));
  $p2    = $base . $dir . '/' . $file . '.php';
  if (is_file($p2)) { require_once $p2; return; }

  // Last chance: full-lowercase path
  $p3 = $base . strtolower($relSlashes) . '.php';
  if (is_file($p3)) { require_once $p3; return; }
});
// --- END: Modules autoloader ---


require_once $_SERVER['DOCUMENT_ROOT'].'/app.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/assets/functions/config.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/modules/transfers/stock/lib/AccessPolicy.php';
use Modules\Transfers\Stock\Services\TransfersService;
use Modules\Transfers\Stock\Lib\AccessPolicy;

if (empty($_SESSION['userID'])) { header('Location:/login.php', true, 302); exit; }
$userId = (int)$_SESSION['userID'];

$transferId = (int)($_GET['transfer'] ?? $_GET['t'] ?? 0);
if ($transferId <= 0) { http_response_code(400); echo 'Missing ?transfer'; exit; }
if (!AccessPolicy::canAccessTransfer($userId, $transferId)) { http_response_code(403); echo 'Forbidden'; exit; }

$svc = new TransfersService();
$tx  = $svc->getTransfer($transferId);
if (!$tx) { http_response_code(404); echo 'Transfer not found'; exit; }

$fromName = htmlspecialchars((string)($tx['outlet_from_name'] ?? $tx['outlet_from'] ?? '—'), ENT_QUOTES, 'UTF-8');
$toName   = htmlspecialchars((string)($tx['outlet_to_name']   ?? $tx['outlet_to']   ?? '—'), ENT_QUOTES, 'UTF-8');
$items    = is_array($tx['items'] ?? null) ? $tx['items'] : [];

// --- Template header ----
include $_SERVER['DOCUMENT_ROOT'].'/assets/template/html-header.php';
include $_SERVER['DOCUMENT_ROOT'].'/assets/template/header.php';
?>
<body class="app header-fixed sidebar-fixed aside-menu-fixed sidebar-lg-show">
<div class="app-body">
<?php include $_SERVER['DOCUMENT_ROOT'].'/assets/template/sidemenu.php'; ?>
<main class="main">
  <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="/index.php">Home</a></li>
    <li class="breadcrumb-item"><a href="/modules/transfers">Transfers</a></li>
    <li class="breadcrumb-item active">Pack & Ship</li>
  </ol>

  <div class="container-fluid">

    <!-- Header strip: route + carrier health -->
    <div class="card shadow-sm mb-3" id="shipHead">
      <div class="card-body d-flex flex-wrap align-items-center justify-content-between" style="gap:10px">
        <div class="d-flex align-items-center" style="gap:10px">
          <div style="width:36px;height:36px;border-radius:12px;background:linear-gradient(180deg,#8fb3ff,#5c7cff)"></div>
          <div>
            <div class="h5 mb-0">Pack & Ship — Transfer #<?= (int)$transferId ?></div>
            <div class="text-muted small"><?= $fromName ?> → <?= $toName ?></div>
          </div>
        </div>
        <div class="d-flex align-items-center flex-wrap" style="gap:6px">
          <span class="badge badge-light border" id="nzpostBadge">NZ Post <span class="ml-1" id="nzpostStatus" style="font-weight:700">CHECK…</span></span>
          <span class="badge badge-light border" id="nzcBadge">NZ Couriers <span class="ml-1" id="nzcStatus" style="font-weight:700">CHECK…</span></span>
          <button class="btn btn-sm btn-outline-secondary" id="helpBtn">Help & Tips</button>
        </div>
      </div>
    </div>

    <div class="row">
      <!-- Left: Packages + capacity + slip preview -->
      <div class="col-lg-7">
        <div class="card mb-3">
          <div class="card-header d-flex justify-content-between align-items-center">
            <strong>Your Packages</strong>
            <div class="btn-group btn-group-sm" role="group">
              <button class="btn btn-outline-primary" id="addRow">Add Row</button>
              <button class="btn btn-outline-secondary" id="copyRow">Copy Row</button>
              <button class="btn btn-outline-danger" id="resetRows">Reset</button>
            </div>
          </div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-sm mb-0">
                <thead class="thead-light">
                  <tr><th style="width:42px">#</th><th>Name</th><th>W×L×H (CM)</th><th>Weight</th><th>Items</th><th></th></tr>
                </thead>
                <tbody id="pkgBody">
                  <!-- rows created by JS -->
                </tbody>
              </table>
            </div>
            <div class="p-2 text-muted small">Capacity meters (25kg cap)</div>
            <div id="capacityMeters" class="p-2 pt-0"></div>
          </div>
        </div>

        <!-- Slip preview -->
        <div class="card mb-3">
          <div class="card-header"><strong>Slip Preview</strong></div>
          <div class="card-body" style="min-height:140px">
            <div id="slipPreview" class="text-center text-muted" style="border:1px dashed #ddd;border-radius:8px;padding:24px">
              OLD-PRINTER STYLE PREVIEW WILL SHOW HERE (Boxes, From/To, Box N of M)
            </div>
          </div>
        </div>

        <!-- Tips (expandable, large) -->
        <div class="card">
          <div class="card-header d-flex justify-content-between align-items-center">
            <strong>Tips & Help</strong>
            <span class="text-muted small">look for the <span class="badge badge-info">?</span> badges</span>
          </div>
          <div class="card-body" id="tipsBody">
            <ul class="mb-0">
              <li>Measure external dimensions in cm; weigh accurately in kg. Round up.</li>
              <li>R18 is disabled for B2B (store→store or any business destination).</li>
              <li>Totals shown are **GST incl** with clear breakdown (base, fuel, rural, Saturday, signature).</li>
              <li>Use <kbd>G</kbd> to fetch rates, <kbd>C</kbd> to create label, <kbd>P</kbd> to print, <kbd>R</kbd> to reserve.</li>
              <li>Sticky bottom bar actions save a note + action with one click.</li>
            </ul>
          </div>
        </div>
      </div>

      <!-- Right: Options + Rates + Summary, BIG buttons -->
      <div class="col-lg-5">
        <div class="card mb-3">
          <div class="card-header d-flex justify-content-between align-items-center">
            <strong>Options</strong>
            <div class="text-muted small">Delivery options</div>
          </div>
          <div class="card-body">
            <div class="d-flex flex-wrap" style="gap:10px">
              <label class="btn btn-sm btn-outline-primary mb-0">
                <input type="checkbox" id="optSig" autocomplete="off" checked> Signature
              </label>
              <label class="btn btn-sm btn-outline-primary mb-0">
                <input type="checkbox" id="optATL" autocomplete="off"> ATL
              </label>
              <label class="btn btn-sm btn-outline-danger mb-0" title="R18 disabled for B2B">
                <input type="checkbox" id="optAge" autocomplete="off" disabled> Age-Restricted
              </label>
              <label class="btn btn-sm btn-outline-primary mb-0">
                <input type="checkbox" id="optSat" autocomplete="off"> Saturday
              </label>
            </div>
            <hr>
            <div class="d-flex justify-content-between align-items-center">
              <div class="text-muted">Rates & Services</div>
              <button class="btn btn-sm btn-outline-secondary" id="btnGetRates">Get rates</button>
            </div>
            <div id="ratesList" class="mt-2">
              <!-- quotes appear here -->
            </div>
            <hr>
            <div class="d-flex justify-content-between">
              <div>
                <div class="small text-muted">Selected</div>
                <div id="sumCarrier" style="font-weight:700">—</div>
                <div id="sumService" class="text-muted small">—</div>
              </div>
              <div class="text-right">
                <div class="small text-muted">Total (GST incl)</div>
                <div id="sumTotal" style="font-size:20px;font-weight:900">$0.00</div>
              </div>
            </div>
            <div class="mt-3 d-flex flex-column" style="gap:8px">
              <button class="btn btn-success btn-lg" id="btnPrintNow">Print Now</button>
              <button class="btn btn-primary btn-lg" id="btnCreateLabel">Create Label</button>
            </div>
          </div>
        </div>

        <!-- Sender/Receiver block -->
        <div class="card">
          <div class="card-header"><strong>Route</strong></div>
          <div class="card-body">
            <div><span class="badge badge-light border mr-1">From</span> <?= $fromName ?></div>
            <div class="mt-2"><span class="badge badge-light border mr-1">To</span> <?= $toName ?></div>
          </div>
        </div>

      </div>
    </div>

    <!-- Sticky bottom action bar -->
    <div class="card mt-3" style="position:sticky;bottom:0;z-index:10;background:linear-gradient(180deg,rgba(255,255,255,.9),#fff)">
      <div class="card-body d-flex align-items-center justify-content-between" style="gap:10px">
        <div class="flex-grow-1">
          <input id="noteText" class="form-control" placeholder="Add a note… (saved with the action)">
          <div class="small text-muted mt-1">Ctrl+Enter performs the primary action</div>
        </div>
        <div class="d-flex" style="gap:8px">
          <button class="btn btn-outline-secondary" id="btnReset">Reset</button>
          <button class="btn btn-outline-secondary" id="btnPrintSlips">Print Slips</button>
          <button class="btn btn-outline-danger" id="btnCancel">Cancel</button>
          <button class="btn btn-success" id="btnReady">Mark Transfer as Ready</button>
        </div>
      </div>
    </div>

  </div><!-- /container-fluid -->
</main>
</div>

<?php include $_SERVER['DOCUMENT_ROOT'].'/assets/template/personalisation-menu.php'; ?>
<?php include $_SERVER['DOCUMENT_ROOT'].'/assets/template/html-footer.php'; ?>
<?php include $_SERVER['DOCUMENT_ROOT'].'/assets/template/footer.php'; ?>

<script>
/* ========= Minimal JS wiring (no external deps required) ========= */
const API_RATES  = '/modules/transfers/stock/api/rates.php';
const API_LABEL  = '/modules/transfers/stock/api/create_label.php';
const API_TOWER  = '/modules/transfers/stock/api/pack_ship_api.php'; // health / carriers (optional)

const TID = <?= (int)$transferId ?>;
const route = { from_store_id: 1, to_store_id: 2 }; // store→store; keeps R18 off

const state = {
  packages: [
    {name:'Box M 400×300×200', w:30, l:40, h:20, kg:4.2, items:9}
  ],
  selection: null
};

function fmt$(n){ return '$' + (n||0).toFixed(2); }
function fmtKg(n){ return (n||0).toFixed(1) + ' kg'; }
function sumKg(){ return state.packages.reduce((s,p)=> s+(+p.kg||0), 0); }

function renderPackages(){
  const body = document.getElementById('pkgBody'); body.innerHTML='';
  state.packages.forEach((p,i)=>{
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${i+1}</td>
      <td>${p.name}</td>
      <td>${p.w}×${p.l}×${p.h}</td>
      <td>${fmtKg(p.kg)}</td>
      <td>${p.items||0}</td>
      <td class="text-right">
        <button class="btn btn-sm btn-outline-secondary" data-del="${i}">×</button>
      </td>`;
    body.appendChild(tr);
  });
  renderMeters();
}

function renderMeters(){
  const wrap = document.getElementById('capacityMeters');
  wrap.innerHTML='';
  const cap = 25;
  state.packages.forEach((p,i)=>{
    const pct = Math.min(100, Math.round((p.kg/cap)*100));
    const row = document.createElement('div');
    row.className='mb-1';
    row.innerHTML = `
      <div class="small text-muted">Box ${i+1} · ${fmtKg(p.kg)} / 25.0</div>
      <div style="height:10px;border-radius:6px;background:#eef2ff;overflow:hidden">
        <div style="height:100%;width:${pct}%;background:linear-gradient(90deg,#9ab1ff,#3b82f6)"></div>
      </div>`;
    wrap.appendChild(row);
  });
}

function applyR18B2BRule(){
  // R18 toggle is permanently disabled for B2B in this UI
  const age = document.getElementById('optAge'); if (age) { age.checked=false; age.disabled=true; }
}

async function getRates(){
  const packages = state.packages.map(p=>({length_cm:p.l, width_cm:p.w, height_cm:p.h, weight_kg:p.kg}));
  const body = { transfer_id: TID, carrier: 'nz_post', packages };
  const res  = await fetch(API_RATES, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)});
  const data = await res.json().catch(()=>({}));
  renderRates(Array.isArray(data.quotes)?data.quotes:[]);
}

function renderRates(quotes){
  const list = document.getElementById('ratesList');
  list.innerHTML='';
  if (!quotes.length){
    list.innerHTML = `<div class="text-muted small">No live rates; you can still print slips or create labels via API.</div>`;
    document.getElementById('sumCarrier').textContent='—';
    document.getElementById('sumService').textContent='—';
    document.getElementById('sumTotal').textContent='$0.00';
    return;
  }
  quotes.forEach((q,idx)=>{
    const card = document.createElement('div');
    card.className='border rounded p-2 mb-2';
    card.style.cursor='pointer';
    card.innerHTML = `
      <div class="d-flex justify-content-between">
        <div>
          <strong>${q.service_name || q.service_code}</strong>
          <div class="small text-muted">GST incl</div>
        </div>
        <div style="font-weight:900">${fmt$(q.total_price||0)}</div>
      </div>`;
    card.addEventListener('click', ()=>{
      state.selection = { carrier:'nz_post', service:(q.service_code||''), total: +q.total_price||0 };
      document.querySelectorAll('#ratesList .border').forEach(el=> el.style.outline='');
      card.style.outline='2px solid #3b82f6';
      document.getElementById('sumCarrier').textContent='NZ Post';
      document.getElementById('sumService').textContent=q.service_name||q.service_code||'—';
      document.getElementById('sumTotal').textContent=fmt$(+q.total_price||0);
    });
    if (idx===0) card.click();
    list.appendChild(card);
  });
}

async function createLabel(){
  if (!state.selection){ alert('Pick a service first'); return; }
  const payload = {
    transfer_id: TID,
    carrier: 'nz_post',
    service_code: state.selection.service,
    packages: state.packages.map(p=>({ l_cm:p.l, w_cm:p.w, h_cm:p.h, weight_kg:p.kg, ref:'' })),
    options: { signature: document.getElementById('optSig').checked,
               saturday:  document.getElementById('optSat').checked,
               atl:       document.getElementById('optATL').checked }
  };
  const res  = await fetch(API_LABEL+'?debug=1', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) });
  const data = await res.json().catch(()=>({}));
  if (data && data.success){
    alert('Label created.');
  } else {
    alert('Create failed.');
  }
}

function bindUI(){
  document.getElementById('pkgBody').addEventListener('click', (e)=>{
    const i = e.target.getAttribute('data-del'); if (i===null) return;
    state.packages.splice(+i,1); renderPackages();
  });
  document.getElementById('addRow').onclick = ()=>{ state.packages.push({name:'Box', w:30,l:40,h:20,kg:2.0,items:0}); renderPackages(); };
  document.getElementById('copyRow').onclick= ()=>{ if(state.packages.length){ state.packages.push({...state.packages[state.packages.length-1]}); renderPackages(); } };
  document.getElementById('resetRows').onclick= ()=>{ state.packages=[]; renderPackages(); };

  document.getElementById('btnGetRates').onclick = getRates;
  document.getElementById('btnPrintNow').onclick = ()=> window.print();
  document.getElementById('btnCreateLabel').onclick = createLabel;

  document.getElementById('btnPrintSlips').onclick = ()=> window.open('/modules/transfers/stock/print/box_slip.php?transfer='+TID+'&preview=1&n='+Math.max(1,state.packages.length),'_blank');

  // sticky bar main actions (note saved server-side via your existing endpoints if you wish)
  document.getElementById('btnReady').onclick = ()=> alert('Marked Ready (UI demo)');
  document.getElementById('btnCancel').onclick= ()=> alert('Cancelled (UI demo)');
  document.getElementById('btnReset').onclick = ()=> { state.packages=[]; state.selection=null; renderPackages(); renderRates([]); };

  // carriers health quick check
  fetch(API_TOWER+'?action=health', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({})})
    .then(r=>r.json()).then(d=>{
      const ok = (name, on) => {
        const el = document.getElementById(name);
        if (!el) return;
        el.textContent = on ? 'LIVE' : 'DOWN';
        el.style.color = on ? '#16a34a' : '#ef4444';
      };
      ok('nzpostStatus', d?.checks?.nz_post === 'ENABLED');
      ok('nzcStatus',    d?.checks?.nzc    === 'ENABLED');
    }).catch(()=>{});

  // Keyboard shortcuts
  document.addEventListener('keydown', e=>{
    if (e.key.toLowerCase()==='g') { e.preventDefault(); getRates(); }
    if (e.key.toLowerCase()==='c') { e.preventDefault(); createLabel(); }
    if (e.key.toLowerCase()==='p') { e.preventDefault(); window.print(); }
    if (e.key.toLowerCase()==='r') { e.preventDefault(); document.getElementById('btnReady').click(); }
    if (e.ctrlKey && e.key==='Enter') { e.preventDefault(); createLabel(); }
  });
}

(function boot(){
  applyR18B2BRule();
  renderPackages();
  bindUI();
  // slip preview
  document.getElementById('slipPreview').innerHTML =
    `<div style="font-family:ui-monospace,Menlo,Consolas,monospace">
      <div><strong>TRANSFER #<?= (int)$transferId ?></strong></div>
      <div>FROM: <?= $fromName ?></div>
      <div>TO:   <?= $toName ?></div>
      <div class="mt-2 small text-muted">BOX 1 of ${Math.max(1,state.packages.length)}</div>
     </div>`;
})();
</script>

<style>
/* Vibrant colours & slim chrome */
#ratesList .border:hover { background: #f8fbff }
.btn.btn-lg { border-radius: 10px; }
.badge-info { background: #3b82f6; }
</style>
</body>
</html>
