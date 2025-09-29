/* =============================================================================
 * File: assets/js/stock-transfers/pack-product-search.js
 * Purpose: Product search + add-to-transfer UI (migrated from inline script in pack.php)
 * Scope:   Transfer Pack page only (body[data-page="transfer-pack"]).
 * Depends: Minimal (fetch API). No jQuery required. Designed to co-exist with
 *          legacy jQuery code without conflicts.
 * Author:  Refactor Automation
 * Last Modified: 2025-09-26
 * ============================================================================= */
(function(){
  if(document.body.getAttribute('data-page') !== 'transfer-pack') return;
  var transferIdEl = document.getElementById('transferID');
  var transferId = transferIdEl ? parseInt(transferIdEl.value,10) : 0;
  var input = document.getElementById('product-search-input');
  if(!input) return; // card removed / not present
  var clearBtn = document.getElementById('product-search-clear');
  var runBtn   = document.getElementById('product-search-run');
  var runBtnIcon = runBtn ? runBtn.querySelector('i') : null;
  var leadIcon = document.getElementById('product-search-icon');
  var tbody = document.getElementById('product-search-tbody');
  var selectAll = document.getElementById('ps-select-all');
  var bulkAddBtn = document.getElementById('bulk-add-selected');
  var bulkAddOtherBtn = document.getElementById('bulk-add-to-other');
  var timer = null; var lastQuery=''; var currentRows=[]; var selected = new Set();

  function fmtPrice(p){ if(p===null||p===undefined||p==='') return ''; return '$'+Number(p).toFixed(2); }
  function esc(str){ return (str+'').replace(/[&<>"']/g, function(s){ return {"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[s]; }); }
  function debounce(){ if(timer) clearTimeout(timer); var q=input.value.trim(); if(q===''){ setSearching(false);} else { setSearching(true);} timer=setTimeout(function(){ run(false); },300); }
  function run(force){ var q=input.value.trim(); if(q===''){ reset(); setSearching(false); return;} if(!force && q===lastQuery){ setSearching(false); return;} lastQuery=q; search(q); }
  function reset(){ tbody.innerHTML='<tr><td colspan="6" class="text-muted small py-3 text-center">Type to search…</td></tr>'; currentRows=[]; selected.clear(); syncBulk(); }
  function loading(){ tbody.innerHTML='<tr><td colspan="6" class="text-muted small py-3 text-center">Searching…</td></tr>'; }
  function setSearching(on){ if(runBtn && runBtnIcon){ if(on){ if(!runBtnIcon.dataset.orig){ runBtnIcon.dataset.orig=runBtnIcon.className;} runBtn.classList.add('is-loading'); runBtn.setAttribute('aria-busy','true'); runBtnIcon.className='fa fa-spinner fa-spin'; } else { runBtn.classList.remove('is-loading'); runBtn.removeAttribute('aria-busy'); if(runBtnIcon.dataset.orig){ runBtnIcon.className=runBtnIcon.dataset.orig; } } } if(leadIcon){ if(on){ if(!leadIcon.dataset.orig){ leadIcon.dataset.orig=leadIcon.className;} leadIcon.className='fa fa-spinner fa-spin text-primary'; leadIcon.setAttribute('aria-busy','true'); } else { if(leadIcon.dataset.orig){ leadIcon.className=leadIcon.dataset.orig; } leadIcon.removeAttribute('aria-busy'); } } }
  function search(q){ loading(); setSearching(true); fetch('/modules/transfers/stock/api/product_search.php?transfer_id='+transferId+'&q='+encodeURIComponent(q))
    .then(function(r){ return r.json().catch(function(){ return {success:false,error:'bad_json'}; }); })
    .then(function(d){ if(!d || d.success===undefined){ tbody.innerHTML='<tr><td colspan="6" class="text-danger small py-3 text-center">Invalid response</td></tr>'; return; } if(!d.success){ tbody.innerHTML='<tr><td colspan="6" class="text-danger small py-3 text-center">'+(d.error||'Error')+'</td></tr>'; return;} currentRows=d.products||[]; render(); })
    .catch(function(){ tbody.innerHTML='<tr><td colspan="6" class="text-danger small py-3 text-center">Network error</td></tr>'; })
    .finally(function(){ setSearching(false); }); }
  function buildInlineIcons(pid,imageUrl){ var vendUrl='/vend/product.php?id='+pid; var vendIcon='<a href="'+vendUrl+'" target="_blank" class="ml-1" title="Open in Vend" aria-label="Open in Vend"><i class="fa fa-external-link-alt"></i></a>'; var imgIcon=imageUrl?'<a href="#" class="ml-1 ps-img-preview" data-img="'+esc(imageUrl)+'" title="Preview image" aria-label="Preview image"><i class="fa fa-image"></i></a>':''; return vendIcon+imgIcon; }
  function render(){ if(!currentRows.length){ tbody.innerHTML='<tr><td colspan="6" class="text-muted small py-3 text-center">No matches</td></tr>'; syncBulk(); return;} var out=[]; var seen=new Set(); for(var i=0;i<currentRows.length;i++){ var p=currentRows[i]; var pid=p.id; if(seen.has(pid)) continue; seen.add(pid); var chk=selected.has(pid)?'checked':''; var img=p.image_url?'<img src="'+esc(p.image_url)+'" alt="" style="width:42px;height:42px;object-fit:cover;border-radius:4px;">':'<div class="bg-light d-flex align-items-center justify-content-center" style="width:42px;height:42px;border:1px solid #e1e5ea;border-radius:4px;font-size:11px;color:#6c757d;">IMG</div>'; var name=esc(p.name||''); var sku=esc(p.sku||p.handle||String(p.id)); var nameBlock=name?'<strong>'+name+'</strong>':'<strong>'+sku+'</strong>'; var skuLine=sku?'<div class="text-muted small">'+sku+buildInlineIcons(pid,p.image_url)+'</div>':''; var isOOS=(p.stock_qty!==null && p.stock_qty<=0); var stockCell=(p.stock_qty==null?'':(isOOS?'<span class="ps-badge-out" title="Out of stock" aria-label="Out of stock">Out&nbsp;of&nbsp;stock</span>':p.stock_qty)); var addBtn=isOOS?'<button class="btn btn-sm btn-outline-secondary" type="button" disabled title="Out of stock" aria-disabled="true"><i class="fa fa-ban"></i></button>':'<button class="btn btn-sm btn-outline-primary ps-add-one" data-pid="'+pid+'" aria-label="Add product"><i class="fa fa-plus"></i></button>'; var checkbox=isOOS?'<input type="checkbox" disabled aria-label="Out of stock" />':'<input type="checkbox" class="ps-row-check" data-pid="'+pid+'" '+chk+' aria-label="Select product">'; out.push('<tr data-pid="'+pid+'"'+(isOOS?' data-oos="1"':'')+'>'+ '<td class="align-middle">'+checkbox+'</td>'+ '<td class="align-middle">'+img+'</td>'+ '<td class="align-middle">'+nameBlock+skuLine+'</td>'+ '<td class="align-middle text-center">'+stockCell+'</td>'+ '<td class="align-middle text-center">'+fmtPrice(p.price)+'</td>'+ '<td class="align-middle text-center">'+addBtn+'</td>'+ '</tr>'); } tbody.innerHTML=out.join(''); syncBulk(); }
  function syncBulk(){ bulkAddBtn.disabled=selected.size===0; bulkAddOtherBtn.disabled=selected.size===0; var total=currentRows.length; if(!total){ selectAll.checked=false; selectAll.indeterminate=false; return;} if(selected.size===0){ selectAll.checked=false; selectAll.indeterminate=false; } else if(selected.size===total){ selectAll.checked=true; selectAll.indeterminate=false; } else { selectAll.indeterminate=true; } }
  function toggle(pid,force){ if(force===true) selected.add(pid); else if(force===false) selected.delete(pid); else if(selected.has(pid)) selected.delete(pid); else selected.add(pid); }
  function addOne(pid){ var existing=document.querySelector('#transfer-table tbody tr[data-product-id="'+pid+'"]'); if(existing){ existing.classList.add('flash-existing'); existing.scrollIntoView({behavior:'smooth', block:'center'}); setTimeout(function(){ existing.classList.remove('flash-existing'); },1100); return; } var p=currentRows.find(function(r){ return r.id===pid; }); if(!p) return; if(p.stock_qty!==null && p.stock_qty<=0) return; var tb=document.querySelector('#transfer-table tbody'); if(!tb) return; var tr=document.createElement('tr'); tr.setAttribute('data-product-id',String(pid)); var name=esc(p.name||''); var sku=esc(p.sku||p.handle||('[ID '+p.id+']')); var content=name?('<strong class="tfx-product-name">'+name+'</strong><div class="tfx-product-sku text-muted small">SKU: '+sku+'</div>'):'<strong class="tfx-product-name">'+sku+'</strong>'; tr.innerHTML='<td class="text-center align-middle"><button class="tfx-remove-btn" type="button" data-action="remove-product" aria-label="Remove product"><i class="fa fa-times" aria-hidden="true"></i></button><input type="hidden" class="productID" value="'+pid+'"></td>'+ '<td><div class="tfx-product-cell">'+content+'</div></td>'+ '<td class="planned">0</td>'+ "<td class='counted-td'><input type='number' min='0' value='' class='form-control form-control-sm tfx-num'><span class='counted-print-value d-none'>0</span></td>"+ '<td></td><td><span class="id-counter">NEW</span></td>'; tb.appendChild(tr); tr.classList.add('flash-added'); tr.scrollIntoView({behavior:'smooth', block:'center'}); setTimeout(function(){ tr.classList.remove('flash-added'); },1100); }
  function addSelected(){ selected.forEach(function(pid){ addOne(pid); }); }
  function addSelectedOther(){ alert('Bulk add to other transfers (same outlet) coming soon. Selected: '+selected.size); }
  input.addEventListener('input', debounce);
  input.addEventListener('keydown', function(e){ if(e.key==='Enter'){ e.preventDefault(); run(true); }});
  if(runBtn) runBtn.addEventListener('click', function(){ run(true); });
  if(clearBtn) clearBtn.addEventListener('click', function(){ input.value=''; debounce(); input.focus(); });
  if(selectAll) selectAll.addEventListener('change', function(){ if(!currentRows.length) return; if(selectAll.checked){ currentRows.forEach(function(r){ selected.add(r.id); }); } else { selected.clear(); } render(); });
  if(tbody) tbody.addEventListener('change', function(e){ var cb=e.target.closest('.ps-row-check'); if(!cb) return; var pid=parseInt(cb.getAttribute('data-pid')||'0',10); if(!pid) return; toggle(pid, cb.checked); syncBulk(); });
  if(tbody) tbody.addEventListener('click', function(e){ var add=e.target.closest('.ps-add-one'); if(add){ var pid=parseInt(add.getAttribute('data-pid')||'0',10); if(pid) addOne(pid); }});
  if(bulkAddBtn) bulkAddBtn.addEventListener('click', addSelected);
  if(bulkAddOtherBtn) bulkAddOtherBtn.addEventListener('click', addSelectedOther);
})();
