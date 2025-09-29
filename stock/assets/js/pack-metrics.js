// pack-metrics.js: Totals + row diff updater + autosave pill integration
(function(){
  'use strict';
  function qs(sel,ctx){ return (ctx||document).querySelector(sel); }
  function qsa(sel,ctx){ return Array.from((ctx||document).querySelectorAll(sel)); }

  function applyRowDiffs(){
    qsa('#transferItemsTable tr[data-item-id]').forEach(row=>{
      const input=row.querySelector('input.qty-input'); if(!input) return;
      const planned=parseInt(input.getAttribute('data-planned'))||0;
      const counted=parseInt(input.value)||0;
      row.classList.remove('qty-match','qty-mismatch','qty-neutral');
      const hasValue=input.value.trim()!=='';
      const touched=hasValue || input.dataset.touched==='1' || input.hasAttribute('data-touched');
      if(planned===0 && counted===0){ row.classList.add('qty-neutral'); return; }
      if(!touched && planned>0 && counted===0){ row.classList.add('qty-neutral'); return; }
      if(counted===planned){ if(counted!==0){ row.classList.add('qty-match'); } else { row.classList.add('qty-neutral'); } return; }
      row.classList.add('qty-mismatch');
    });
  }
  function updateTotals(){
    let plannedTotal=0, countedTotal=0;
    qsa('#transferItemsTable [data-planned]').forEach(el=> plannedTotal += parseInt(el.getAttribute('data-planned'))||0);
    qsa('#transferItemsTable input[name^="counted_qty"]').forEach(inp=> countedTotal += parseInt(inp.value)||0);
    const diff=countedTotal - plannedTotal;
    const set=(id,val)=>{ const el=qs('#'+id); if(el) el.textContent=val; };
    set('plannedTotal', plannedTotal);
    set('countedTotal', countedTotal);
    set('diffTotal', (diff>=0?'+':'')+diff);
    set('plannedTotalFooter', plannedTotal);
    set('countedTotalFooter', countedTotal);
    set('diffTotalFooter', (diff>=0?'+':'')+diff);
    applyRowDiffs();
    try { if(window.PackBus){ PackBus.emit('counts:updated',{planned:plannedTotal, counted:countedTotal, diff:diff}); } } catch(e){}
  }
  function initAutosavePill(){
    const pill=qs('#autosavePill'); if(!pill) return;
    const pillText=qs('#autosavePillText'); const lastSavedEl=qs('#autosaveLastSaved'); let timer=null;
    function setState(st,label){ pill.classList.remove('status-idle','status-dirty','status-saving','status-saved'); pill.classList.add('status-'+st); if(pillText) pillText.textContent=label; }
    function formatTime(ts){ try{ const d=new Date(ts); return d.toLocaleTimeString(undefined,{hour:'numeric',minute:'2-digit',second:'2-digit'});}catch(e){return ts;} }
    function updateLast(ts){ if(!lastSavedEl) return; lastSavedEl.textContent = ts? ('Last saved: '+formatTime(ts)) : ''; }
    function markDirty(){ if(pill.classList.contains('status-saving')) return; setState('dirty','Pending'); }
    function markSaving(){ setState('saving','Saving…'); }
    function markSaved(ts){ setState('saved','Saved'); updateLast(ts||new Date().toISOString()); clearTimeout(timer); timer=setTimeout(()=>setState('idle','Idle'),1700); }
    qsa('#transferItemsTable input.qty-input').forEach(inp=>{ inp.addEventListener('input',markDirty); inp.addEventListener('change',markDirty); });
    if(window.PackAutoSave){ try{ if(!window.PackAutoSave.__wrapped){ const orig=window.PackAutoSave.saveNow?.bind(window.PackAutoSave); if(orig){ window.PackAutoSave.saveNow=async function(){ markSaving(); try{ const r=await orig(); markSaved(r?.saved_at); return r;}catch(e){ setState('dirty','Retry'); throw e; } }; } window.PackAutoSave.__wrapped=true; } }catch(e){}
    }
    document.addEventListener('packautosave:state', ev=>{ const st=ev.detail?.state; const p=ev.detail?.payload; if(st==='saving') markSaving(); else if(st==='saved') markSaved(p?.saved_at); else if(st==='error') setState('dirty','Retry'); });
    try{ const initEl=qs('#initialDraftData'); if(initEl && initEl.textContent.trim()){ const jd=JSON.parse(initEl.textContent); if(jd.saved_at) updateLast(jd.saved_at); } }catch(e){}
    setTimeout(()=>{ if(pill.classList.contains('status-dirty')) setState('idle','Idle'); },60000);
  }

  document.addEventListener('DOMContentLoaded', ()=>{
    // Sanitize DEL header label
    qsa('#transferItemsTable thead th').forEach(th=>{ if(th.textContent && th.textContent.trim().toUpperCase()==='DEL'){ th.textContent=''; th.classList.add('col-del'); th.setAttribute('aria-label','Delete'); } });
    updateTotals();
    applyRowDiffs();
    [120,400,900].forEach(t=> setTimeout(()=>{ updateTotals(); applyRowDiffs(); }, t));
    if(window.PackAutoSave && typeof window.PackAutoSave.updateAllQuantityStatuses==='function'){ try { window.PackAutoSave.updateAllQuantityStatuses(); setTimeout(applyRowDiffs,150); } catch(e){} }
    // Observe async row additions
    const tbody=qs('#transferItemsTable tbody');
    if(tbody && !window.__packTableObserved){
      window.__packTableObserved=true;
      const mo = new MutationObserver(muts=>{ for(const m of muts){ if(m.addedNodes && m.addedNodes.length){ updateTotals(); break; } } });
      try { mo.observe(tbody,{childList:true}); } catch(e){}
    }
    initAutosavePill();
  });

  // Expose manual refresh for external triggers
  window.refreshPackMetrics = updateTotals;
})();
