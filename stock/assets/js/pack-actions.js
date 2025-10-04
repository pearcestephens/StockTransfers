// pack-actions.js
// Wires unhooked buttons on pack.view.php: autofill, reset, add product modal triggers, and save pack.
// Non-destructive: uses feature detection & logs to lock diagnostics if present.
(function(){
  'use strict';
  if(window.PackActionsBound) return; window.PackActionsBound = true;

  const log = (type, data)=>{ if(window.__logLockDiagEvent) window.__logLockDiagEvent(type, data); else if(window.DISPATCH_BOOT?.debug) console.log('[PackActions]', type, data); };
  const qs = sel => document.querySelector(sel);
  const qsa = sel => Array.from(document.querySelectorAll(sel));
  const liveAnnounce = (msg)=>{ let el = qs('#packLiveAnnounce'); if(!el){ el=document.createElement('div'); el.id='packLiveAnnounce'; el.className='sr-only'; el.setAttribute('aria-live','polite'); el.style.position='absolute'; el.style.left='-9999px'; el.style.width='1px'; el.style.height='1px'; document.body.appendChild(el); } el.textContent=msg; };
  const autosavePill = qs('#autosavePill');
  const autosavePillText = qs('#autosavePillText');
  const lastSaveEl = qs('#lastSaveTime');
  const countedTotalFooter = qs('#countedTotalFooter');
  const diffFooter = qs('#diffTotalFooter');
  const totalWeightFooterKg = qs('#totalWeightFooterKgValue');

  const dirtyState = { dirty:false, lastSaved:null, pending:false };
  function setDirty(on){ if(on && !dirtyState.dirty){ dirtyState.dirty=true; document.body.classList.add('pack-dirty'); } else if(!on && dirtyState.dirty){ dirtyState.dirty=false; document.body.classList.remove('pack-dirty'); } updateAutosavePill(); }
  function updateAutosavePill(){ if(!autosavePill) return; if(dirtyState.pending){ autosavePill.className='autosave-pill status-saving'; autosavePill.style.backgroundColor='#0d6efd'; autosavePillText.textContent='Saving'; return; } if(dirtyState.dirty){ autosavePill.className='autosave-pill status-dirty'; autosavePill.style.backgroundColor='#d97706'; autosavePillText.textContent='Unsaved'; } else { autosavePill.className='autosave-pill status-idle'; autosavePill.style.backgroundColor='#6c757d'; autosavePillText.textContent='Idle'; } if(lastSaveEl && dirtyState.lastSaved){ lastSaveEl.textContent='Last saved '+relativeTime(dirtyState.lastSaved); }
  }
  function relativeTime(ts){ const diff = Date.now()-ts; if(diff<60000) return 'just now'; const m=Math.floor(diff/60000); if(m<60) return m+'m ago'; const h=Math.floor(m/60); if(h<24) return h+'h ago'; const d=Math.floor(h/24); return d+'d ago'; }

  // Recalculate totals (planned, counted, diff, weight) – lean, only recount visible rows
  function recalcTotals(){ let counted=0; let weight=0; const rows=qsa('#transferItemsTable tbody tr.pack-item-row'); rows.forEach(r=>{ const input=r.querySelector('input.qty-input'); const wEl=r.querySelector('.row-weight'); const unitG = parseInt(wEl?.getAttribute('data-unit-weight-g')||'0',10); if(input && input.value!=='' && !isNaN(input.value)){ counted+=parseInt(input.value,10); if(unitG>0) weight += unitG*parseInt(input.value,10); }}); if(countedTotalFooter) countedTotalFooter.textContent = counted; if(diffFooter){ const plannedTotal=parseInt(qs('#plannedTotalFooter')?.textContent||'0',10); const diff = counted - plannedTotal; diffFooter.textContent = (diff===0?'OK': (diff>0? '+'+diff: diff)); diffFooter.className = diff===0?'text-success':'text-warning'; } if(totalWeightFooterKg){ totalWeightFooterKg.textContent = (weight/1000).toFixed(2); } }

  // Validation: highlight rows where counted > source stock
  function validateRow(r){ const input=r.querySelector('input.qty-input'); if(!input) return; const source = parseInt(input.getAttribute('data-source-stock')||'0',10); const planned=parseInt(input.getAttribute('data-planned')||'0',10); const val = input.value===''?0:parseInt(input.value,10); input.classList.remove('is-invalid','is-overstock','is-overplanned'); if(val>source && source>0){ input.classList.add('is-invalid','is-overstock'); input.title='Count exceeds source stock ('+source+')'; } else if(val>planned && planned>0){ input.classList.add('is-overplanned'); input.title='Count exceeds planned quantity ('+planned+')'; } else { input.title=''; }
  }
  function validateAll(){ qsa('#transferItemsTable tbody tr.pack-item-row').forEach(validateRow); }

  // Draft save integration (re-uses existing server endpoint if present)
  async function performDraftSave(){ if(dirtyState.pending) return; const transferId = (window.DISPATCH_BOOT||{}).transfer_id; if(!transferId){ log('draft_save_skipped',{reason:'missing_transfer'}); return; }
    const payload = { transfer_id: transferId, counted_qty: collectCounts(), timestamp: new Date().toISOString() };
    dirtyState.pending=true; updateAutosavePill();
    try {
      const res = await fetch('/modules/transfers/stock/api/draft_save_api.php',{method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)});
      const json = await res.json().catch(()=>({success:false,error:{code:'BAD_JSON'}}));
      log('draft_save_result',{status:res.status, json});
      if(json.success){ dirtyState.lastSaved=Date.now(); setDirty(false); } else { showTransientToast('Draft save failed'); }
    } catch(err){ log('draft_save_error',{error:String(err)}); showTransientToast('Draft save error'); }
    finally { dirtyState.pending=false; updateAutosavePill(); }
  }
  function collectCounts(){ const out={}; qsa('#transferItemsTable tbody tr.pack-item-row').forEach(r=>{ const input=r.querySelector('input.qty-input'); if(!input) return; const id=r.getAttribute('data-product-id')||r.getAttribute('data-item-id'); if(!id) return; if(input.value!==''){ const v=parseInt(input.value,10); if(!isNaN(v)) out[id]=v; } }); return out; }
  function scheduleAutoDraft(){ if(scheduleAutoDraft._t) clearTimeout(scheduleAutoDraft._t); scheduleAutoDraft._t=setTimeout(()=>{ if(dirtyState.dirty) performDraftSave(); }, 5000); }

  // Provide a status updater if referenced by legacy buttons (packSystem?.updateLockStatusDisplay)
  if(!window.packSystem) window.packSystem = {}; // ensure object
  if(typeof window.packSystem.updateLockStatusDisplay !== 'function'){
    window.packSystem.updateLockStatusDisplay = function(){
      log('lock_status_refresh_requested',{});
      // Attempt to pull latest state from lockInstance if present
      if(window.lockInstance && typeof window.lockInstance.refresh === 'function'){
        try { window.lockInstance.refresh(); liveAnnounce('Lock status refreshed'); } catch(e){ log('lock_status_refresh_error',{error:String(e)}); }
      } else {
        liveAnnounce('Lock status refresh queued');
      }
    };
  }

  function bindAutofill(){
    const btn = qs('#autofillBtn');
    if(!btn || btn.dataset.bound) return;
    btn.dataset.bound='1';
    btn.addEventListener('click', ()=>{
      let changed=0; const rows = qsa('#transferItemsTable tbody tr.pack-item-row');
      rows.forEach(r=>{
        const input = r.querySelector('input.qty-input');
        if(!input) return; const planned = parseInt(input.getAttribute('data-planned')||'0',10);
        if(planned>0 && (input.value===''||input.value==='0')){ input.value = planned; changed++; input.dispatchEvent(new Event('change',{bubbles:true})); }
      });
      log('autofill_clicked',{rows:rows.length, changed});
      liveAnnounce('Autofill applied to '+changed+' lines');
      recalcTotals(); validateAll(); setDirty(true); scheduleAutoDraft();
    });
  }

  function bindReset(){
    const btn = qs('#resetBtn');
    if(!btn || btn.dataset.bound) return;
    btn.dataset.bound='1';
    btn.addEventListener('click', ()=>{
      const rows = qsa('#transferItemsTable tbody tr.pack-item-row');
      let cleared=0; rows.forEach(r=>{ const input=r.querySelector('input.qty-input'); if(!input) return; if(input.value!==''){ input.value=''; cleared++; input.dispatchEvent(new Event('change',{bubbles:true})); } });
      log('reset_clicked',{rows:rows.length, cleared});
      liveAnnounce('Cleared '+cleared+' counted entries');
      recalcTotals(); validateAll(); setDirty(true); scheduleAutoDraft();
    });
  }

  function bindSave(){
    const btn = qs('#savePackBtn');
    if(!btn || btn.dataset.bound) return;
    btn.dataset.bound='1';
    btn.addEventListener('click', async ()=>{
      // Collect payload (minimal stub); integrate with existing autosave bridge if present.
      const rows = qsa('#transferItemsTable tbody tr.pack-item-row');
      const lineItems = rows.map(r=>{
        const id = r.getAttribute('data-item-id');
        const input = r.querySelector('input.qty-input');
        const counted = input ? (input.value===''?null:parseInt(input.value,10)) : null;
        return { id, counted };
      });
      log('save_clicked',{count:lineItems.length});
      // If an external packSystem save method exists, delegate.
      if(window.packSystem && typeof window.packSystem.savePack==='function'){
        try { await window.packSystem.savePack(lineItems); log('save_delegated',{status:'ok'}); return; } catch(err){ log('save_delegate_error',{error: String(err)}); }
      }
      // Fallback: POST to a placeholder endpoint (not yet implemented) or emit event.
      document.dispatchEvent(new CustomEvent('pack:save:requested',{detail:{lineItems}}));
      showTransientToast('Save triggered ('+lineItems.length+' lines).');
      performDraftSave();
    });
  }

  function bindAddProductButton(){
    const btn = qs('#headerAddProductBtn');
    if(!btn || btn.dataset.bound) return;
    btn.dataset.bound='1';
    btn.addEventListener('click',()=>{ log('add_product_header_clicked',{}); /* Modal already triggered via data attributes */ });
  }

  // Per-input change monitoring for dirty + validation + totals
  function bindInputMonitoring(){ qsa('#transferItemsTable tbody tr.pack-item-row input.qty-input').forEach(inp=>{ if(inp.dataset.bound) return; inp.dataset.bound='1'; inp.addEventListener('input',()=>{ validateRow(inp.closest('tr')); recalcTotals(); setDirty(true); scheduleAutoDraft(); }); }); }

  function boot(){ bindAutofill(); bindReset(); bindSave(); bindAddProductButton(); bindInputMonitoring(); recalcTotals(); validateAll(); updateAutosavePill(); }
  // Minimal transient toast for user feedback (non-modal, accessible)
  function showTransientToast(msg){
    let wrap = qs('#packActionToastWrap');
    if(!wrap){
      wrap = document.createElement('div');
      wrap.id='packActionToastWrap';
      wrap.style.position='fixed'; wrap.style.bottom='20px'; wrap.style.right='20px'; wrap.style.zIndex='10500'; wrap.style.display='flex'; wrap.style.flexDirection='column'; wrap.style.gap='8px';
      document.body.appendChild(wrap);
    }
    const node = document.createElement('div');
    node.setAttribute('role','status');
    node.style.background='rgba(31,41,55,0.95)';
    node.style.color='#fff'; node.style.padding='10px 14px'; node.style.borderRadius='6px'; node.style.fontSize='13px'; node.style.boxShadow='0 4px 12px rgba(0,0,0,0.4)';
    node.textContent=msg; wrap.appendChild(node);
    setTimeout(()=>{ node.style.transition='opacity .4s'; node.style.opacity='0'; setTimeout(()=> node.remove(), 450); }, 2500);
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
