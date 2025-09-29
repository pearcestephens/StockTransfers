// pack-tracking.js: Manual tracking panel logic
(function(){
  'use strict';
  const panel=document.getElementById('manualTrackingPanel');
  if(!panel) return;
  const form=panel.querySelector('#manualTrackingForm');
  const trackingInput=document.getElementById('trackingInput');
  const carrierSelect=document.getElementById('carrierSelect');
  const notesInput=document.getElementById('trackingNotes');
  const addBtn=document.getElementById('addTrackingBtn');
  const tbody=document.getElementById('manualTrackingTbody');
  const statusEl=document.getElementById('trackingStatus');
  const modeButtons=panel.querySelectorAll('[data-track-mode]');
  const transferId=parseInt(form.querySelector('[name="transfer_id"]').value,10) || 0;
  let currentMode='manual';
  let rowSeq=0;

  function escapeHtml(str){ return (str||'').replace(/[&<>"']/g,s=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[s]||s)); }
  function setStatus(txt,cls){ statusEl.textContent=txt||''; statusEl.className='tracking-status-row small text-muted'; if(cls) statusEl.classList.add(cls); }
  function refreshEmpty(){ if(!tbody.querySelector('tr[data-track-row]')){ if(!tbody.querySelector('.tracking-empty')){ const tr=document.createElement('tr'); tr.className='tracking-empty'; tr.innerHTML='<td colspan="6" class="text-center text-muted small py-3">No manual tracking added yet.</td>'; tbody.appendChild(tr);} } else { const e=tbody.querySelector('.tracking-empty'); if(e) e.remove(); } }
  function addRowLocal(data){ const tr=document.createElement('tr'); tr.dataset.trackRow='1'; tr.innerHTML=`<td class="text-center align-middle">${++rowSeq}</td><td class="align-middle"><span class="track-code" title="Tracking">${escapeHtml(data.tracking)}</span></td><td class="align-middle">${escapeHtml(data.carrier||'-')}</td><td class="align-middle">${escapeHtml(data.notes||'')}</td><td class="align-middle"><span class="mode-badge mode-${currentMode==='internal'?'internal':'manual'}">${currentMode==='internal'?'INTERNAL':'MANUAL'}</span></td><td class="text-center align-middle"><button type="button" class="tracking-remove-btn" title="Remove" aria-label="Remove tracking"><i class="fa fa-times"></i></button></td>`; tbody.appendChild(tr); refreshEmpty(); }
  function validate(){
    const val=trackingInput.value.trim();
    const carrierValid = carrierSelect && carrierSelect.value !== '';
    addBtn.disabled = (val.length < 4) || !carrierValid;
  }

  modeButtons.forEach(btn=> btn.addEventListener('click', ()=>{ modeButtons.forEach(b=>b.classList.remove('active')); btn.classList.add('active'); currentMode=btn.getAttribute('data-track-mode')||'manual'; }));
  trackingInput.addEventListener('input', validate);
  if(carrierSelect){ carrierSelect.addEventListener('change', validate); }
  notesInput.addEventListener('input', ()=>{});

  form.addEventListener('submit', async e=>{
    e.preventDefault(); validate(); if(addBtn.disabled) return;
    const tracking=trackingInput.value.trim(); let carrierVal='manual'; let carrierId=null;
    if(carrierSelect){ carrierVal=carrierSelect.options[carrierSelect.selectedIndex]?.text||'manual'; const raw=carrierSelect.value; if(/^\d+$/.test(raw)) carrierId=parseInt(raw,10); }
    const carrier=carrierVal || (currentMode==='internal'?'internal':'manual'); const notes=notesInput.value.trim();
    setStatus('Saving tracking…','status-saving'); addBtn.disabled=true;
    try {
      const payload={ transfer_id: transferId, carrier: carrier, tracking: tracking, notes: notes };
      if(carrierId!==null) payload.carrier_id = carrierId;
      const resp = await fetch('/modules/transfers/stock/api/save_manual_tracking.php',{ method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) });
      const json = await resp.json().catch(()=>({}));
      if(resp.ok && json.ok){ addRowLocal({ tracking, carrier, notes }); setStatus('Saved','status-ok'); window.PackToast && PackToast.success('Tracking saved'); if(window.PackBus) PackBus.emit('tracking:added',{tracking, carrier, notes, carrier_id:carrierId}); trackingInput.value=''; notesInput.value=''; validate(); setTimeout(()=> setStatus('',null),1800); }
      else { const code = json.error?.code || 'ERR'; const msg = json.error?.message||json.error||'save failed'; setStatus('Error: '+msg,'status-error'); window.PackToast && PackToast.error(code+': '+msg,{force:true}); }
    } catch(err){ setStatus('Error: '+(err.message||'network'),'status-error'); window.PackToast && PackToast.error('Tracking network error'); }
    finally { addBtn.disabled=false; }
  });

  tbody.addEventListener('click', e=>{ const btn=e.target.closest('.tracking-remove-btn'); if(!btn) return; const row=btn.closest('tr'); if(row) row.remove(); const rows=tbody.querySelectorAll('tr[data-track-row]'); rowSeq=0; rows.forEach(r=>{ const cell=r.querySelector('td'); if(cell) cell.textContent=(++rowSeq).toString(); }); refreshEmpty(); });

  refreshEmpty(); validate();
})();
