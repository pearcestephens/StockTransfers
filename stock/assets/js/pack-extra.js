// File intentionally removed – legacy shim retired.
// (Left as zero-byte placeholder to avoid 404 until caches expire.)
  const ProductModal = (function(){
    const modalEl = document.getElementById('addProdModal');
    if(!modalEl) return { open:()=>{}, init:()=>{} };
    const searchInput = modalEl.querySelector('#addProdSearch');
    const resultsBody = modalEl.querySelector('#addProdResultsBody');
    const addSelectedBtn = modalEl.querySelector('#addSelectedProductsBtn');
    const feedback = modalEl.querySelector('#addProdFeedback');
    const transferId = modalEl.getAttribute('data-transfer-id');
    let lastQuery='';
    let page=1; let isLoading=false; let currentRows=[];

    function rowHtml(p){
      return `<tr data-product-id="${p.product_id}" data-sku="${p.handle||''}">\n<td><input type="checkbox" class="prod-select" /></td>\n<td><span class="mono">${p.product_id}</span></td>\n<td><strong>${escapeHtml(p.name||p.title||'')}</strong><br><small class="text-muted">${p.handle||''}</small></td>\n<td>${p.sku||''}</td>\n<td>${p.barcode||''}</td>\n<td>${p.inventory||0}</td>\n<td>${p.outlet||'-'}</td>\n</tr>`;
    }

    function setLoading(state){ isLoading=state; modalEl.classList.toggle('loading', state); }

    function search(q){
      q=q.trim();
      lastQuery=q; page=1; currentRows=[]; resultsBody.innerHTML = `<tr><td colspan="7" class="text-center text-muted">Searching...</td></tr>`;
      fetch(`/modules/transfers/stock/api/search_products.php?q=${encodeURIComponent(q)}&transfer_id=${encodeURIComponent(transferId)}`)
        .then(r=>r.json())
        .then(resp=>{
          currentRows = resp.data||[];
          if(!currentRows.length){
            resultsBody.innerHTML = `<tr><td colspan="7" class="text-center text-muted">No products found</td></tr>`; return;
          }
          resultsBody.innerHTML = currentRows.map(rowHtml).join('');
        })
        .catch(e=>{ resultsBody.innerHTML = `<tr><td colspan="7" class="text-danger">Error: ${e.message}</td></tr>`; });
    }

    const debouncedSearch = debounce(search, 350);

    function gatherSelected(){
      const rows=[...resultsBody.querySelectorAll('tr')];
      const selected=[]; rows.forEach(tr=>{ const cb=tr.querySelector('.prod-select'); if(cb && cb.checked){ selected.push({ product_id: tr.getAttribute('data-product-id') }); } });
      return selected;
    }

    function addSelected(){
      const list=gatherSelected();
      if(!list.length){ feedback.textContent='Nothing selected'; feedback.className='text-muted'; return; }
      feedback.textContent='Adding...'; feedback.className='text-info';
      fetch('/modules/transfers/stock/api/add_products_to_transfers.php', {
        method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ transfer_id: transferId, items: list })
      }).then(r=>r.json()).then(resp=>{
        if(resp.success){ feedback.textContent=`Added ${resp.data.added||list.length} products`; feedback.className='text-success'; window.PackAutoSave && window.PackAutoSave.requestSync && window.PackAutoSave.requestSync(); }
        else { feedback.textContent = resp.error && resp.error.message ? resp.error.message : 'Error adding'; feedback.className='text-danger'; }
      }).catch(e=>{ feedback.textContent=e.message; feedback.className='text-danger'; });
    }

    function wire(){
      if(searchInput){ searchInput.addEventListener('input', e=>debouncedSearch(e.target.value)); }
      if(addSelectedBtn){ addSelectedBtn.addEventListener('click', addSelected); }
      // initial empty search to show prompt
      setTimeout(()=>{ if(searchInput && searchInput.value.trim().length){ search(searchInput.value.trim()); } }, 200);
    }

    function open(){ if(window.jQuery){ window.jQuery(modalEl).modal('show'); } }

    function escapeHtml(str){ return (str||'').replace(/[&<>"']/g, c=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[c])); }

    wire();
    return { open, search };
  })();
  window.PackProductModal = ProductModal;

  // Sheen animation trigger
  function initSheen(){
    const header = document.querySelector('.pack-header-join .card-header');
    if(!header) return; if(header.classList.contains('sheen-ready')) return; header.classList.add('sheen-ready');
    setTimeout(()=>{ header.classList.add('sheen-run'); }, 600);
  }
  document.addEventListener('DOMContentLoaded', initSheen);

  // Metrics & Row Diff Updater
  const Metrics = (function(){
    const table = document.getElementById('transferItemsTable');
    if(!table) return {};
    const totalSpan = document.getElementById('totalItemsActual');
    const selectedSpan = document.getElementById('totalItemsSelected');
    const matchedSpan = document.getElementById('totalItemsMatched');

    function recompute(){
      let actual=0, selected=0, matched=0;
      [...table.querySelectorAll('tbody tr')].forEach(tr=>{
        const actualEl = tr.querySelector('.tfx-actual');
        const selectedEl = tr.querySelector('.tfx-selected');
        if(!actualEl || !selectedEl) return;
        const a = parseInt(actualEl.value||actualEl.textContent||'0',10)||0;
        const s = parseInt(selectedEl.value||selectedEl.textContent||'0',10)||0;
        actual += a; selected += s; if(a===s) matched++;
        tr.classList.remove('qty-match','qty-mismatch','qty-neutral');
        if(a===0 && s===0){ tr.classList.add('qty-neutral'); }
        else if(a===s){ tr.classList.add('qty-match'); }
        else { tr.classList.add('qty-mismatch'); }
      });
      if(totalSpan) totalSpan.textContent=actual; if(selectedSpan) selectedSpan.textContent=selected; if(matchedSpan) matchedSpan.textContent=matched;
    }

    document.addEventListener('input', function(e){ if(e.target && e.target.classList.contains('tfx-actual')) recompute(); });
    document.addEventListener('change', function(e){ if(e.target && (e.target.classList.contains('tfx-actual')||e.target.classList.contains('tfx-selected'))) recompute(); });
    setTimeout(recompute, 400);
    return { recompute };
  })();
  window.PackMetrics = Metrics;

  // Manual Tracking Panel Logic
  const TrackingPanel = (function(){
    const panel = document.getElementById('manualTrackingPanel');
    if(!panel) return {};
    const form = panel.querySelector('#manualTrackingForm');
    const codeInput = panel.querySelector('#manualTrackingCode');
    const modeBtns = panel.querySelectorAll('[data-mode]');
    const tableBody = panel.querySelector('#manualTrackingTable tbody');
    const statusEl = panel.querySelector('#trackingStatus');
    const carrierSelect = panel.querySelector('#manualTrackingCarrier');
    const transferId = panel.getAttribute('data-transfer-id');
    let mode='internal';

    modeBtns.forEach(btn=>btn.addEventListener('click', function(){ modeBtns.forEach(b=>b.classList.remove('active')); this.classList.add('active'); mode = this.getAttribute('data-mode'); }));

    function setStatus(msg, cls){ if(!statusEl) return; statusEl.textContent=msg||''; statusEl.className='mt-1 '+(cls||''); }

    function addRowLocal(trackCode, carrierId){
      const tr=document.createElement('tr');
      tr.innerHTML = `<td><span class="track-code">${escapeHtml(trackCode)}</span></td><td><span class="mode-badge mode-${mode}">${mode.toUpperCase()}</span></td><td>${carrierId?('<span class="badge bg-secondary">#'+carrierId+'</span>'):'-'}</td><td class="text-end"><button class="tracking-remove-btn" title="Remove">&times;</button></td>`;
      const removeBtn = tr.querySelector('.tracking-remove-btn');
      removeBtn.addEventListener('click', ()=> tr.remove());
      tableBody.prepend(tr);
    }

    function saveTracking(trackCode){
      setStatus('Saving...','status-saving');
      const payload={ transfer_id: transferId, tracking_code: trackCode, mode: mode };
      const carrierId = carrierSelect && carrierSelect.value ? parseInt(carrierSelect.value,10) : null;
      if(carrierId) payload.carrier_id = carrierId;
      fetch('/modules/transfers/stock/api/save_manual_tracking.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) })
        .then(r=>r.json())
        .then(resp=>{
          if(resp.success){ setStatus('Saved','status-ok'); addRowLocal(trackCode, carrierId); window.PackAutoSave && window.PackAutoSave.requestSync && window.PackAutoSave.requestSync(); }
          else { setStatus(resp.error && resp.error.message? resp.error.message : 'Error','status-error'); }
        })
        .catch(e=> setStatus(e.message,'status-error'));
    }

    form && form.addEventListener('submit', function(e){ e.preventDefault(); const v=(codeInput.value||'').trim(); if(!v){ setStatus('Enter a code','status-error'); return;} saveTracking(v); codeInput.value=''; codeInput.focus(); });

    function escapeHtml(str){ return (str||'').replace(/[&<>"']/g, c=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[c])); }

    return { saveTracking };
  })();
  window.PackTracking = TrackingPanel;

})();
