<?php
declare(strict_types=1);
/**
 * Bulk Add Products to Draft Transfers UI
 * Emits window.ADD_PRODUCTS_BOOT and serves a self-contained HTML/JS application.
 * Security: requires authenticated user + outlet context.
 */
require_once $_SERVER['DOCUMENT_ROOT'].'/app.php';
if (empty($_SESSION['userID'])) { http_response_code(401); echo 'Auth required'; exit; }
$staffId = (int)$_SESSION['userID'];
// Expect outlet UUID in query (or derive from session/profile if available)
$outletUuid = isset($_GET['outlet']) ? trim((string)$_GET['outlet']) : '';
if ($outletUuid === '' && !empty($_SESSION['active_outlet_uuid'])) {
    $outletUuid = (string)$_SESSION['active_outlet_uuid'];
}
$csrf = isset($_SESSION['csrf']) ? (string)$_SESSION['csrf'] : '';
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Add Products to Transfers — Bulk</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root { --gap:12px; --tile:168px; --radius:12px; --brand:#4f7cff; --ok:#19a974; --warn:#ffb700; --err:#d33; }
  html,body{margin:0;height:100%;font:14px/1.35 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue",Arial}
  .app{display:grid;grid-template-columns:320px 1fr;gap:0;height:100%}
  .pane{overflow:auto}
  .left{border-right:1px solid #e6e6e6;background:#fafafa}
  .right{background:#fff}
  header{padding:14px 16px;border-bottom:1px solid #e6e6e6;background:#fff;position:sticky;top:0;z-index:2}
  h1{font-size:16px;margin:0 0 6px 0}
  .hint{color:#666;font-size:12px}
  .section{padding:14px 16px}
  .searchbar{display:flex;gap:8px}
  .searchbar input[type="search"]{flex:1;padding:10px 12px;border:1px solid #cfcfcf;border-radius:10px}
  .btn{border:0;border-radius:10px;padding:10px 12px;cursor:pointer;background:#efefef}
  .btn.primary{background:linear-gradient(180deg,#6fa0ff,#4f7cff);color:#fff}
  .btn.ok{background:linear-gradient(180deg,#22c37f,#159a61);color:#fff}
  .btn.warn{background:#ffdd88}
  .btn.err{background:#ffd3d3}
  .btn:disabled{opacity:.5;cursor:not-allowed}
  .toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
  .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(var(--tile),1fr));gap:var(--gap)}
  .tile{border:1px solid #e6e6e6;border-radius:var(--radius);overflow:hidden;background:#fff;display:flex;flex-direction:column;position:relative}
  .tile .imgwrap{aspect-ratio:1/1;background:#f4f4f4;display:flex;align-items:center;justify-content:center}
  .tile img{max-width:100%;max-height:100%;object-fit:contain;display:block}
  .tile .meta{padding:10px}
  .sku{font-size:12px;color:#666}
  .name{font-size:13px;margin:4px 0 6px 0}
  .qtyrow{display:flex;align-items:center;gap:8px}
  .qtyrow input{width:56px;text-align:center;padding:6px;border:1px solid #cfcfcf;border-radius:8px}
  .chip{position:absolute;top:8px;left:8px;background:#fff;border:1px solid #ddd;border-radius:999px;padding:4px 8px;font-size:12px;display:flex;align-items:center;gap:6px}
  .chip input{width:16px;height:16px}
  .sticky-actions{position:sticky;bottom:0;background:#fff;border-top:1px solid #e6e6e6;padding:12px 16px;display:flex;gap:8px;align-items:center;justify-content:space-between}
  .badges{display:flex;gap:8px;align-items:center}
  .badge{background:#f0f3ff;color:#2b3d91;border-radius:999px;padding:4px 10px;font-size:12px;border:1px solid #dbe3ff}
  .list{display:flex;flex-direction:column;gap:10px}
  .transfer{border:1px solid #e6e6e6;border-radius:10px;background:#fff;padding:10px;display:grid;grid-template-columns:28px 1fr auto;gap:10px;align-items:center}
  .transfer input{width:16px;height:16px}
  .tname{font-weight:600}
  .tmeta{font-size:12px;color:#666}
  .loadmore{margin-top:12px}
  .divider{height:1px;background:#e6e6e6;margin:10px 0}
  .toast{position:fixed;right:16px;bottom:16px;background:#222;color:#fff;padding:12px 14px;border-radius:10px;opacity:.96;z-index:9999;max-width:380px}
  .kbd{font-family:ui-monospace,Menlo,Consolas,monospace;background:#f4f4f4;border:1px solid #ddd;border-bottom-width:2px;padding:1px 6px;border-radius:6px;font-size:12px}
  .muted{color:#666}
  .pill{padding:2px 8px;border-radius:999px;font-size:12px;border:1px solid #ddd}
  .status-draft{background:#f7fff3;border-color:#bfe7c7}
  .sr-only{position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden}
</style>
</head>
<body>
<script>
window.ADD_PRODUCTS_BOOT = {
  outlet_uuid: <?php echo json_encode($outletUuid, JSON_UNESCAPED_SLASHES); ?>,
  staff_id: <?php echo json_encode($staffId, JSON_UNESCAPED_SLASHES); ?>,
  csrf: <?php echo json_encode($csrf, JSON_UNESCAPED_SLASHES); ?>,
  preset_transfer_ids: []
};
</script>
<div class="app" role="application" aria-label="Add products to multiple transfers">

  <!-- LEFT: Draft transfers assigned to outlet -->
  <div class="pane left" id="pane-transfers">
    <header>
      <h1>Draft Transfers (assigned to your outlet)</h1>
      <div class="hint">Select one or many. Products will be added to all selected transfers.</div>
    </header>

    <div class="section">
      <div class="searchbar" aria-label="Search transfers">
        <input id="tx-search" type="search" placeholder="Search transfers (ID, destination, notes)…" autocomplete="off">
        <button class="btn" id="tx-refresh" title="Refresh list">Refresh</button>
      </div>

      <div class="toolbar" style="margin-top:10px">
        <button class="btn" id="tx-select-all">Select all</button>
        <button class="btn" id="tx-clear">Clear</button>
        <span class="muted" id="tx-count">0 selected</span>
      </div>

      <div class="divider"></div>

      <div id="tx-list" class="list" aria-live="polite" aria-busy="false"></div>
      <button class="btn loadmore" id="tx-load">Load more transfers</button>
    </div>
  </div>

  <!-- RIGHT: Product search with images -->
  <div class="pane right">
    <header>
      <h1>Add Products</h1>
      <div class="hint">Search products, set quantities, then add to all selected transfers.</div>
    </header>

    <div class="section">
      <div class="searchbar" aria-label="Search products">
        <input id="q" type="search" placeholder="Search products (name, SKU, barcode)…" autocomplete="off" aria-label="Product search input">
        <button class="btn primary" id="go">Search</button>
      </div>

      <div class="toolbar" style="margin-top:10px">
        <button class="btn" id="sel-toggle">Toggle select all</button>
        <button class="btn" id="bulk-qty">Set bulk qty <span class="kbd">Q</span></button>
        <span class="badges">
          <span class="badge" id="badge-selected">0 selected</span>
          <span class="badge" id="badge-items">0 items</span>
        </span>
      </div>

      <div id="results" class="grid" aria-live="polite" aria-busy="false" style="margin-top:12px"></div>
      <button class="btn loadmore" id="load-more">Load more products</button>

      <div class="sticky-actions">
        <div>
          <span class="pill status-draft"><strong>Targets:</strong> <span id="targets-pill">0 transfers</span></span>
          <span class="pill"><strong>Selected:</strong> <span id="sel-pill">0 products</span></span>
        </div>
        <div>
          <button class="btn ok" id="apply" disabled>Add to selected transfers</button>
        </div>
      </div>

      <div class="hint" style="margin-top:10px">
        Tips: Press <span class="kbd">A</span> to toggle select all results, <span class="kbd">Q</span> to bulk-set quantity, and <span class="kbd">Enter</span> to search.
      </div>
    </div>
  </div>
</div>

<div id="toast" class="toast" style="display:none"></div>

<script>
/* ===========================
   CONFIG — Wire these endpoints
   =========================== */
const API = {
  LIST_TRANSFERS: '/modules/transfers/stock/api/list_transfers.php',
  SEARCH_PRODUCTS: '/modules/transfers/stock/api/search_products.php',
  ADD_PRODUCTS: '/modules/transfers/stock/api/add_products_to_transfers.php'
};

// Boot payload from PHP (ensure outlet_uuid is set)
const BOOT = Object.assign({
  outlet_uuid: '',
  staff_id: '',
  csrf: '',
  preset_transfer_ids: []
}, window.ADD_PRODUCTS_BOOT || {});

function toast(msg, ms=3500){ const t=document.getElementById('toast'); t.textContent=msg; t.style.display='block'; clearTimeout(t._to); t._to=setTimeout(()=>t.style.display='none', ms); }
function idemKey(prefix='add'){ return `${prefix}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2,8)}`; }
async function jsonGet(url){ const r = await fetch(url, { credentials:'same-origin', headers: BOOT.csrf ? {'X-CSRF-Token': BOOT.csrf} : {} }); return r.json(); }
async function jsonPost(url, body){ const headers = {'Content-Type':'application/json'}; if (BOOT.csrf) headers['X-CSRF-Token']=BOOT.csrf; headers['Idempotency-Key']=body.idempotency_key||idemKey('add'); const r=await fetch(url,{method:'POST',credentials:'same-origin',headers,body:JSON.stringify(body)}); return r.json(); }

const state = { tx:[], txPage:1, txHasMore:true, txSelected:new Set(), q:'', items:[], page:1, hasMore:true, selected:new Set(), qty:new Map() };

const txList=document.getElementById('tx-list');
const txCount=document.getElementById('tx-count');
function renderTransfers(applyPreset=false){ txList.innerHTML=''; for(const t of state.tx){ const el=document.createElement('div'); el.className='transfer'; el.innerHTML=`\n      <input type="checkbox" aria-label="Select transfer ${t.public_id || t.id}">\n      <div>\n        <div class="tname">#${t.public_id || t.id} — ${t.to_outlet_name || 'Destination'}</div>\n        <div class="tmeta">Draft • Items: ${t.item_count ?? '-'} • Notes: ${t.notes || '—'}</div>\n      </div>\n      <div class="muted">${t.updated_at ? new Date(t.updated_at).toLocaleString() : ''}</div>\n    `; const cb=el.querySelector('input'); cb.checked=state.txSelected.has(t.id); cb.addEventListener('change',()=>{ if(cb.checked) state.txSelected.add(t.id); else state.txSelected.delete(t.id); updateTargetsPill(); updateApplyButton(); txCount.textContent=`${state.txSelected.size} selected`; }); el.addEventListener('click',(e)=>{ if(e.target.tagName!=='INPUT'){ cb.checked=!cb.checked; cb.dispatchEvent(new Event('change')); }}); txList.appendChild(el);} if(applyPreset && Array.isArray(BOOT.preset_transfer_ids)){ for(const id of BOOT.preset_transfer_ids){ state.txSelected.add(id);} txList.querySelectorAll('input').forEach((cb,i)=>{ const id=state.tx[i]?.id; cb.checked=state.txSelected.has(id); }); updateTargetsPill(); updateApplyButton(); txCount.textContent=`${state.txSelected.size} selected`; } }
async function loadTransfers(reset=false){ if(reset){ state.tx=[]; state.txPage=1; state.txHasMore=true;} if(!state.txHasMore) return; document.getElementById('pane-transfers').setAttribute('aria-busy','true'); const q=encodeURIComponent(document.getElementById('tx-search').value||''); const url=`${API.LIST_TRANSFERS}?status=draft&assigned_to=${encodeURIComponent(BOOT.outlet_uuid)}&q=${q}&page=${state.txPage}`; try{ const res=await jsonGet(url); if(res.ok){ const rows=res.data?.rows||[]; state.tx.push(...rows); state.txHasMore=rows.length>0 && (res.data?.has_more ?? rows.length>=20); state.txPage++; renderTransfers(reset);} else { toast(res.error?.message||'Failed to load transfers',4000); state.txHasMore=false;} }catch(e){ console.error(e); toast('Error loading transfers',4000); state.txHasMore=false;} document.getElementById('pane-transfers').setAttribute('aria-busy','false'); }

const results=document.getElementById('results');
const badgeSelected=document.getElementById('badge-selected');
const badgeItems=document.getElementById('badge-items');
function productTile(p){ const el=document.createElement('div'); el.className='tile'; el.innerHTML=`\n    <label class="chip"><input type="checkbox" aria-label="Select ${p.name}"><span>${p.stock_at_outlet ?? '—'} in stock</span></label>\n    <div class="imgwrap">\n      ${p.image_url ? `<img loading="lazy" src="${p.image_url}" alt="">` : `<span class="muted">No image</span>`}\n    </div>\n    <div class="meta">\n      <div class="sku">${p.sku || p.barcode || ''}</div>\n      <div class="name" title="${p.name || ''}">${p.name || ''}</div>\n      <div class="qtyrow">\n        <button class="btn" data-act="dec" title="Decrease">−</button>\n        <input type="number" min="1" step="1" value="${state.qty.get(p.id) ?? 1}" aria-label="Quantity for ${p.name}">\n        <button class="btn" data-act="inc" title="Increase">+</button>\n      </div>\n    </div>\n  `; const cb=el.querySelector('input[type="checkbox"]'); cb.checked=state.selected.has(p.id); cb.addEventListener('change',()=>{ if(cb.checked) state.selected.add(p.id); else state.selected.delete(p.id); updateSelectionBadges(); updateApplyButton(); }); el.addEventListener('click',(e)=>{ if(e.target.matches('[data-act]')) return; if(e.target.tagName==='INPUT') return; cb.checked=!cb.checked; cb.dispatchEvent(new Event('change')); }); const qtyInput=el.querySelector('input[type="number"]'); el.querySelector('[data-act="inc"]').addEventListener('click',()=>{ qtyInput.value=parseInt(qtyInput.value||'1',10)+1; state.qty.set(p.id, parseInt(qtyInput.value,10)); }); el.querySelector('[data-act="dec"]').addEventListener('click',()=>{ const v=Math.max(1,parseInt(qtyInput.value||'1',10)-1); qtyInput.value=v; state.qty.set(p.id,v); }); qtyInput.addEventListener('change',()=>{ const v=Math.max(1,parseInt(qtyInput.value||'1',10)); qtyInput.value=v; state.qty.set(p.id,v); }); return el; }
function renderProducts(append=false){ if(!append) results.innerHTML=''; for(const p of state.items){ results.appendChild(productTile(p)); } updateSelectionBadges(); }
let searchDebounce; function doSearch(reset=true){ if(reset){ state.page=1; state.items=[]; state.hasMore=true; state.selected.clear(); } const q=document.getElementById('q').value.trim(); state.q=q; loadProducts(reset); }
async function loadProducts(append=false){ if(!state.hasMore) return; results.setAttribute('aria-busy','true'); try{ const url=`${API.SEARCH_PRODUCTS}?q=${encodeURIComponent(state.q)}&page=${state.page}`; const res=await jsonGet(url); if(res.ok){ const rows=res.data?.rows||[]; if(rows.length===0 && state.page===1){ results.innerHTML=`<div class="muted">No products found for “${state.q}”.</div>`; state.hasMore=false; } else { state.items.push(...rows); renderProducts(true); state.hasMore=rows.length>0 && (res.data?.has_more ?? rows.length>=24); state.page++; } } else { toast(res.error?.message||'Search failed',4000); state.hasMore=false; } }catch(e){ console.error(e); toast('Error searching products',4000); state.hasMore=false; } results.setAttribute('aria-busy','false'); }
function updateSelectionBadges(){ badgeSelected.textContent=`${state.selected.size} selected`; document.getElementById('sel-pill').textContent=`${state.selected.size} products`; badgeItems.textContent=`${state.items.length} items`; }
function updateTargetsPill(){ document.getElementById('targets-pill').textContent=`${state.txSelected.size} transfers`; }
function updateApplyButton(){ const apply=document.getElementById('apply'); apply.disabled=!(state.txSelected.size>0 && state.selected.size>0); }
function toggleSelectAll(){ const shouldSelect=state.selected.size < state.items.length; state.selected=new Set(shouldSelect ? state.items.map(p=>p.id):[]); results.querySelectorAll('input[type="checkbox"]').forEach((cb)=>{ cb.checked=shouldSelect; }); updateSelectionBadges(); updateApplyButton(); }
function bulkQtyPrompt(){ const v=prompt('Set quantity for all selected products:','1'); if(!v) return; const qty=Math.max(1,parseInt(v,10)||1); for(const id of state.selected){ state.qty.set(id,qty); } const items=state.items.slice(); state.items=[]; renderProducts(false); state.items=items; renderProducts(false); }
async function applyToTransfers(){ const transfer_ids=Array.from(state.txSelected); const items=Array.from(state.selected).map(id=>({ product_id:id, qty:Math.max(1,state.qty.get(id)||1) })); if(items.length===0){ toast('Select products first'); return; } if(transfer_ids.length===0){ toast('Select one or more transfers'); return; } const body={ idempotency_key:idemKey('add'), transfer_ids, items, outlet_uuid:BOOT.outlet_uuid }; const applyBtn=document.getElementById('apply'); applyBtn.disabled=true; applyBtn.textContent='Applying…'; try{ const res=await jsonPost(API.ADD_PRODUCTS, body); if(res.ok){ const added=res.data?.added||{}; const summary=Object.keys(added).length ? Object.entries(added).map(([tid,c])=>`#${tid}: ${c} lines`).join(' • ') : 'No lines added'; toast(`Added to transfers → ${summary}`,5000); state.selected.clear(); updateSelectionBadges(); updateApplyButton(); } else { toast(res.error?.message||'Apply failed',5000); } }catch(e){ console.error(e); toast('Network error applying changes',5000); } finally { applyBtn.disabled=!(state.txSelected.size>0 && state.selected.size>0); applyBtn.textContent='Add to selected transfers'; } }

document.getElementById('go').addEventListener('click',()=>doSearch(true));
document.getElementById('q').addEventListener('keydown',e=>{ if(e.key==='Enter'){ doSearch(true); }});
document.getElementById('load-more').addEventListener('click',()=>loadProducts(true));
document.getElementById('sel-toggle').addEventListener('click',toggleSelectAll);
document.getElementById('bulk-qty').addEventListener('click',bulkQtyPrompt);
document.getElementById('apply').addEventListener('click',applyToTransfers);

document.getElementById('tx-refresh').addEventListener('click',()=>loadTransfers(true));
document.getElementById('tx-load').addEventListener('click',()=>loadTransfers(false));
document.getElementById('tx-select-all').addEventListener('click',()=>{ state.tx.forEach(t=>state.txSelected.add(t.id)); renderTransfers(); updateTargetsPill(); updateApplyButton(); txCount.textContent=`${state.txSelected.size} selected`; });
document.getElementById('tx-clear').addEventListener('click',()=>{ state.txSelected.clear(); renderTransfers(); updateTargetsPill(); updateApplyButton(); txCount.textContent='0 selected'; });
document.getElementById('tx-search').addEventListener('input',()=>{ clearTimeout(searchDebounce); searchDebounce=setTimeout(()=>loadTransfers(true),450); });

document.addEventListener('keydown',(e)=>{ if(e.target.tagName==='INPUT' && e.target.type==='search') return; if(e.key==='a' || e.key==='A'){ toggleSelectAll(); } if(e.key==='q' || e.key==='Q'){ bulkQtyPrompt(); } });
(function init(){ const _renderProducts=renderProducts; renderProducts=function(append=false){ if(!append) results.innerHTML=''; for(const p of state.items){ const el=productTile(p); el.dataset.pid=p.id; const qtyInput=el.querySelector('input[type="number"]'); if(state.qty.has(p.id)) qtyInput.value=state.qty.get(p.id); results.appendChild(el);} updateSelectionBadges(); }; loadTransfers(true); doSearch(true); })();
</script>
</body>
</html>
