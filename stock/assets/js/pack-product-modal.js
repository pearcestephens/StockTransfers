// pack-product-modal.js: Add Product modal controller
(function(){
  'use strict';
  const modalEl = document.getElementById('addProdModal');
  if(!modalEl){ return; }
  const bodyEl  = document.getElementById('addProdModalBody');
  if(!bodyEl){ return; }

  const API = {
    SEARCH: '/modules/transfers/stock/api/search_products.php',
    ADD: '/modules/transfers/stock/api/add_products_to_transfers.php'
  };

  const skeleton = '\n<div class="form-group mb-2">\n  <input id="ap-search" type="search" class="form-control form-control-sm" placeholder="Search products (name, SKU)…" autocomplete="off">\n</div>\n<div id="ap-results" class="row no-gutters" aria-live="polite"></div>\n<div class="text-center mt-2">\n  <button id="ap-load-more" type="button" class="btn btn-light btn-sm">Load more</button>\n</div>';

  function escapeHtml(str){ return (str||'').replace(/[&<>"']/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[c])); }
  function ensure(){ if(!bodyEl.dataset.initialized){ bodyEl.innerHTML=skeleton; bodyEl.dataset.initialized='1'; } return bodyEl; }
  function vendUrl(prod){ try { const base=(window.VEND_CFG?.base_url||'').replace(/\/+$/,''); const key=(window.VEND_CFG?.product_key||'id'); const val=prod[key]||prod.id; return base? base+'/product/'+encodeURIComponent(val): '#'; } catch(e){ return '#'; } }
  function isPlaceholder(url){ if(!url) return true; const pats=(window.VEND_CFG?.placeholder_patterns||['no-image','placeholder','/assets/images/noimage','data:image/']).map(p=>String(p).toLowerCase()); const u=String(url).toLowerCase(); return pats.some(p=>u.includes(p)); }
  function cardTpl(p){ const realImg=!isPlaceholder(p.image_url); return `\n<div class="col-6 col-md-4 col-lg-3 p-1">\n <div class="card h-100 border">\n  <div class="card-img-top d-flex align-items-center justify-content-center" style="height:130px;background:#f7f7f9;">${realImg?`<img src="${escapeHtml(p.image_url)}" alt="" style="max-width:100%;max-height:100%;">`:'<span class="text-muted small">No image</span>'}</div>\n  <div class="card-body p-2">\n    <div class="d-flex align-items-center mb-1" style="gap:4px;">\n      <span class="text-monospace small" style="font-size:11px;">${escapeHtml(p.sku||'')}</span>\n      <a class="text-muted" href="${vendUrl(p)}" target="_blank" rel="noopener" title="Open in Vend" style="font-size:11px;">↗</a>\n      ${realImg?`<button class="btn btn-link p-0 ml-auto" data-preview="${encodeURIComponent(p.image_url)}" title="Preview image" style="font-size:11px;">Img</button>`:''}\n    </div>\n    <div class="small text-truncate" title="${escapeHtml(p.name||'')}">${escapeHtml(p.name||'')}</div>\n    <div class="input-group input-group-sm mt-1">\n      <div class="input-group-prepend"><button class="btn btn-light" data-dec type="button">-</button></div>\n      <input class="form-control text-center" type="number" min="1" value="1" aria-label="Quantity">\n      <div class="input-group-append"><button class="btn btn-light" data-inc type="button">+</button></div>\n    </div>\n    <button class="btn btn-primary btn-block btn-sm mt-2" data-add data-id="${escapeHtml(p.id)}" type="button">Add</button>\n  </div>\n </div>\n</div>`; }

  async function fetchJSON(url, timeout=9000){ const ctrl=new AbortController(); const t=setTimeout(()=>ctrl.abort(), timeout); try{ const r=await fetch(url,{signal:ctrl.signal,credentials:'same-origin'}); const ct=r.headers.get('content-type')||''; if(!ct.includes('application/json')) throw new Error('Non-JSON'); return await r.json(); } finally { clearTimeout(t); } }
  async function searchProducts(q,page,outlet){
    const params = new URLSearchParams();
    params.set('q', q||'');
    params.set('page', page);
    if(outlet) params.set('outlet', outlet);
    return fetchJSON(API.SEARCH+'?'+params.toString());
  }
  async function addSingle(transferId, productId, qty){ const idem='MODAL-'+Date.now()+'-'+Math.random().toString(16).slice(2); const payload={ idempotency_key: idem, transfer_ids:[transferId], items:[{product_id: productId, qty: qty}] }; const r=await fetch(API.ADD,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)}); const j=await r.json().catch(()=>({})); if(!(r.ok&&j.ok)) throw new Error('Add failed'); }

  window.openAddProductModal = function(){
    const boot = window.DISPATCH_BOOT || {};
    const transferId = boot.transferId || boot.transfer_id || boot.transfer_id || 0;
    const body=ensure();
    const res=document.getElementById('ap-results');
    const input=document.getElementById('ap-search');
    const more=document.getElementById('ap-load-more');
  let page=1, hasMore=true, currentQ='';
  const outlet = boot.outlet_uuid || boot.outlet || '';
  let inflight=null;
  const pendingSpinner = '<div class="w-100 text-center py-4 text-muted small">Searching…</div>';

    async function run(q, reset){
      try {
        if(reset){
          if(inflight && inflight.abort) inflight.abort();
          res.innerHTML=pendingSpinner; page=1; hasMore=true; more.disabled=true;
        }
        if(q.length && q.length < 2){ res.innerHTML='<div class="text-muted text-center w-100 py-4">Type at least 2 characters.</div>'; more.disabled=true; return; }
        if(!hasMore) return;
        res.setAttribute('aria-busy','true');
        const json=await searchProducts(q,page,outlet);
        if(!json.ok){ throw new Error(json.error?.message||'Search failed'); }
        const rows=json.data?.rows||[];
        if(rows.length===0 && page===1){
          res.innerHTML='<div class="text-muted text-center w-100 py-4">No products found.</div>';
          hasMore=false;
          window.PackToast && PackToast.info('No products found');
        } else {
          res.insertAdjacentHTML('beforeend', rows.map(cardTpl).join(''));
          page++; hasMore=!!json.data?.has_more;
          more.disabled = !hasMore;
          if(reset) window.PackToast && PackToast.success('Loaded '+rows.length+' products');
        }
      } catch(e){
        res.innerHTML='<div class="alert alert-warning mb-0">Search error. Try again.</div>';
        hasMore=false;
        window.PackToast && PackToast.error('Search failed');
      } finally { res.removeAttribute('aria-busy'); }
    }

    let searchDebounce=null;
    input.addEventListener('input', ()=>{
      clearTimeout(searchDebounce);
      searchDebounce = setTimeout(()=>{ currentQ=input.value.trim(); run(currentQ,true); }, 320);
    });
    input.onkeydown = (e)=>{ if(e.key==='Enter'){ e.preventDefault(); currentQ=input.value.trim(); run(currentQ,true);} };
    more.onclick = ()=> run(currentQ,false);
    res.addEventListener('click', async (e)=>{
      const card=e.target.closest('.card'); if(!card) return;
      if(e.target.closest('[data-inc]')){ const inp=card.querySelector('input[type="number"]'); inp.value=(+inp.value||1)+1; }
      else if(e.target.closest('[data-dec]')){ const inp=card.querySelector('input[type="number"]'); inp.value=Math.max(1,(+inp.value||1)-1); }
      else if(e.target.closest('[data-add]')){
        if(!transferId){ alert('Transfer ID missing'); return; }
        const btn=e.target.closest('[data-add]'); if(btn.dataset.loading) return; btn.dataset.loading='1';
        const pid=btn.getAttribute('data-id'); const qty=parseInt(card.querySelector('input[type="number"]').value,10)||1; const old=btn.textContent; btn.textContent='…';
        try { await addSingle(transferId,pid,qty); btn.textContent='Added'; window._reloadAfterModal=true; window.PackToast && PackToast.success('Product added'); if(window.PackBus) PackBus.emit('product:added',{product_id:pid, qty}); setTimeout(()=>{ btn.textContent=old; btn.disabled=true; },1400);} catch(err){ btn.textContent='Err'; window.PackToast && PackToast.error('Add failed'); setTimeout(()=>{ btn.textContent=old; btn.dataset.loading=''; },1500);} }
      else if(e.target.closest('[data-preview]')){ const url=decodeURIComponent(e.target.closest('[data-preview]').dataset.preview); window.ImgPreview && window.ImgPreview.show(url); }
    });

    run('', true);
    if(window.jQuery && jQuery('#addProdModal').modal){ jQuery('#addProdModal').modal('show'); } else { modalEl.style.display='block'; }
  };

  // Lightweight image preview (global)
  window.ImgPreview = window.ImgPreview || { show(url){ const m=document.getElementById('imgModal'); if(!m) return; document.getElementById('imgModalPic').src=url; m.style.display='flex'; m.setAttribute('aria-hidden','false'); }, hide(){ const m=document.getElementById('imgModal'); if(!m) return; m.style.display='none'; m.setAttribute('aria-hidden','true'); document.getElementById('imgModalPic').src=''; } };

  // Bind trigger
  document.addEventListener('DOMContentLoaded', ()=>{
    const addMenuBtn=document.getElementById('addProductOpen');
    if(addMenuBtn){ addMenuBtn.addEventListener('click', ()=>{ window.openAddProductModal && window.openAddProductModal(); }); }
  });
})();
