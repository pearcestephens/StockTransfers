// weight-recalc.js
// Extracted dynamic weight recalculation & accessibility diff announcer from pack.view.php
(function(){
  'use strict';
  if(window.WeightRecalcLoaded) return; window.WeightRecalcLoaded=true;

  function recalcWeights(){
    let totalG = 0; let srcCounts = {product:0, category:0, default:0};
    const rows = document.querySelectorAll('#transferItemsTable tbody tr');
    rows.forEach(tr => {
      const unitG = parseInt(tr.getAttribute('data-unit-weight-g')||'0',10) || 0;
      const input = tr.querySelector('.qty-input');
      if(!input || unitG <= 0) return;
      const qty = parseInt(input.value || input.getAttribute('value') || '0', 10) || 0;
      const rowWeightG = unitG * qty;
      const span = tr.querySelector('.row-weight');
      if(span){
        if(qty > 0){ span.textContent = (rowWeightG/1000).toFixed(rowWeightG >= 100000 ? 2 : 3) + 'kg'; }
        else { span.textContent = '—'; }
        span.setAttribute('data-row-weight-g', rowWeightG);
        const src = span.getAttribute('data-weight-source');
        if(src && srcCounts[src] !== undefined) srcCounts[src]++;
      }
      totalG += rowWeightG;
    });
    const totalSpan = document.getElementById('totalWeightFooterKgValue');
    if(totalSpan){ totalSpan.textContent = (totalG/1000).toFixed(2); }
    const breakdownEl = document.getElementById('weightSourceBreakdown');
    if(breakdownEl){
      const totalLines = Object.values(srcCounts).reduce((a,b)=>a+b,0);
      if(totalLines>0){ const pct = k=>((srcCounts[k]/totalLines)*100).toFixed(0)+'%'; breakdownEl.textContent = `P ${srcCounts.product} (${pct('product')}) • C ${srcCounts.category} (${pct('category')}) • D ${srcCounts.default} (${pct('default')})`; } else breakdownEl.textContent='';
    }
  }
  document.addEventListener('input', (e)=>{ if(e.target && e.target.classList.contains('qty-input')) recalcWeights(); });
  if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', recalcWeights); else setTimeout(recalcWeights, 50);
  window.recalcTransferWeights = recalcWeights;

  // Keyboard navigation
  function focusNext(current, delta){ const inputs = Array.from(document.querySelectorAll('#transferItemsTable .qty-input')); const idx = inputs.indexOf(current); if(idx === -1) return; const next = inputs[idx + delta]; if(next){ next.focus(); next.select && next.select(); } }
  document.addEventListener('keydown', function(e){ const t=e.target; if(!t || !t.classList || !t.classList.contains('qty-input')) return; if(e.key==='ArrowDown'){ e.preventDefault(); focusNext(t,+1); } else if(e.key==='ArrowUp'){ e.preventDefault(); focusNext(t,-1); } else if(e.key==='Enter'){ e.preventDefault(); focusNext(t,+1); } });

  // Diff announcement for accessibility
  const announceEl = document.getElementById('lineAnnouncement');
  let lastDiffMap = new Map();
  function announceChanges(){ const rows=document.querySelectorAll('#transferItemsTable tbody tr'); let messages=[]; rows.forEach(tr=>{ const input=tr.querySelector('.qty-input'); const planned=parseInt(tr.getAttribute('data-planned-qty')||'0',10)||0; if(!input) return; const counted=parseInt(input.value||'0',10)||0; const rid=tr.getAttribute('data-item-id')||''; const key=rid; const prev=lastDiffMap.get(key); const state = counted===planned ? 'match' : (counted>planned ? 'over' : 'under'); if(prev && prev!==state){ messages.push(`Line ${rid}: now ${state}`); } lastDiffMap.set(key,state); }); if(messages.length && announceEl){ announceEl.textContent = messages.join('. '); } }
  document.addEventListener('input', (e)=>{ if(e.target && e.target.classList.contains('qty-input')) announceChanges(); });
  setTimeout(announceChanges, 1200);
})();
