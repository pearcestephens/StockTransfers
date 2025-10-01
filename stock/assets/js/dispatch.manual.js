 (function(){
  'use strict';
  var D = window.Dispatch, U = D.util, S = D.state;

  function updateManualCourierUI(){
    var select = U.$('#manualCourierPreset'), status = U.$('#manualCourierStatus');
    var extraWrap = U.$('#manualCourierExtraWrap'), extraInput = U.$('#manualCourierExtraDetail');
    var value = (select && select.value || '').trim(); S.manualCourier.preset = value;

    var msg='Select a manual courier method to confirm the handover.', cls='', needsDetail=false;
    if (value==='nzpost_manifest'){ msg='Manifested NZ Post bag confirmed for dispatch.'; cls='is-ready'; }
    else if (value==='nzpost_counter'){ msg='Counter drop-off chosen. Ensure docket accompanies the parcels.'; cls='is-ready'; }
    else if (value==='nzc_pickup'){ msg='NZ Couriers pick-up logged. Await driver collection.'; cls='is-ready'; }
    else if (value==='third_party'){ msg='Third-party courier selected. Record details below.'; cls='is-ready'; needsDetail=true; }

    if (extraWrap){ if (needsDetail){ extraWrap.removeAttribute('hidden'); } else { extraWrap.setAttribute('hidden',''); if (extraInput){ extraInput.value=''; S.manualCourier.extra=''; } } }
    if (status){ status.classList.remove('is-ready','is-error'); if (cls) status.classList.add(cls); status.innerHTML='<span class="status-dot"></span><span>'+U.escapeHtml(msg)+'</span>'; }

    var btn=U.$('#btnPrintPacked');
    if (btn) btn.textContent = value ? 'Mark as Packed & Sent' : (D.flags.SHOW_COURIER_DETAIL ? 'Print & Mark Packed' : 'Mark as Packed');
  }

  function collectManualCourierContext(){
    var select = U.$('#manualCourierPreset'), extraInput = U.$('#manualCourierExtraDetail');
    var value=(select&&select.value||'').trim();
    var label=select&&select.selectedIndex>=0 ? (select.options[select.selectedIndex].text||'').trim() : '';
    var extra=(extraInput && extraInput.value || S.manualCourier.extra || '').trim();
    S.manualCourier.extra = extra;
    return { preset: value||null, label: label||null, extra: extra||null, tracking_refs: Array.isArray(S.trackingRefs) ? S.trackingRefs.slice() : [] };
  }

  function collectSlipPreviewContext(manual){
    var totalBoxRaw=(U.$('#slipTotal')||{}).value||'', total=U.toNumber(totalBoxRaw,1);
    var seq=U.toNumber((U.$('#slipBox')||{}).textContent||'',1)||1;
    return {
      transfer_id: D.BOOT.transferId,
      from_label: ((U.$('#slipFrom')||{}).textContent||'').trim() || (D.BOOT.fromOutlet||''),
      to_label: ((U.$('#slipTo')||{}).textContent||'').trim() || (D.BOOT.toOutlet||''),
      box_sequence: seq, box_total: total>0?Math.round(total):1,
      tracking_refs: Array.isArray(S.trackingRefs)?S.trackingRefs.slice():[],
      manual_courier: manual
    };
  }

  // simple local tracking list (UI)
  function renderTrackingRefs(){
    var list=U.$('#trackingList'), empty=U.$('#trackingEmpty'); if(!list) return;
    list.innerHTML=''; if(!Array.isArray(S.trackingRefs) || !S.trackingRefs.length){ if(empty){ empty.removeAttribute('hidden'); empty.setAttribute('aria-hidden','false'); } return; }
    if (empty){ empty.setAttribute('hidden','true'); empty.setAttribute('aria-hidden','true'); }
    S.trackingRefs.forEach(function(ref,idx){
      var li=document.createElement('li'); li.className='tracking-item';
      li.innerHTML='<span class="value">'+U.escapeHtml(ref)+'</span><button type="button" class="tracking-remove" aria-label="Remove tracking reference" data-remove-index="'+idx+'">&times;</button>';
      list.appendChild(li);
    });
  }

  function wireManual(){
    var select=U.$('#manualCourierPreset'), extraInput=U.$('#manualCourierExtraDetail');
    if (select){ select.addEventListener('change', function(){
      updateManualCourierUI();
      var label=select.selectedIndex>=0 ? (select.options[select.selectedIndex].text||'').trim() : (select.value||'');
      if (window.PackToast) window.PackToast.info('Manual courier: '+label);
    }); }
    if (extraInput){
      extraInput.addEventListener('input', function(e){ S.manualCourier.extra = e.target.value.trim(); });
      extraInput.addEventListener('blur',  function(e){
        S.manualCourier.extra = e.target.value.trim();
        if (S.manualCourier.preset==='third_party' && S.manualCourier.extra && window.PackToast) window.PackToast.info('Third-party details saved');
      });
    }
    // tracking add/remove
    var addBtn=U.$('#trackingAdd'), input=U.$('#trackingInput'), list=U.$('#trackingList');
    function tryAdd(){ if(!input) return;
      var raw=input.value.trim(); if(!raw){ if(window.PackToast) window.PackToast.warn('Enter a tracking code or URL first.'); input.focus(); return; }
      if (S.trackingRefs.length>=12){ if(window.PackToast) window.PackToast.warn('Tracking limit reached'); return; }
      if (S.trackingRefs.some(function(x){return x.toLowerCase()===raw.toLowerCase();})){ if(window.PackToast) window.PackToast.info('Tracking already listed'); input.select(); return; }
      S.trackingRefs.push(raw); input.value=''; input.focus(); renderTrackingRefs();
      if(window.PackToast) window.PackToast.success('Tracking added'); 
    }
    if (addBtn && input){ addBtn.addEventListener('click', tryAdd); input.addEventListener('keydown', function(e){ if(e.key==='Enter'){ e.preventDefault(); tryAdd(); } }); }
    if (list){ list.addEventListener('click', function(e){ var btn=e.target.closest('[data-remove-index]'); if(!btn) return; var idx=Number(btn.dataset.removeIndex);
      if(Number.isInteger(idx) && idx>=0 && idx<S.trackingRefs.length){ var r=S.trackingRefs.splice(idx,1)[0]; renderTrackingRefs(); if(window.PackToast) window.PackToast.info('Tracking removed: '+r); }
    }); }
  }

  // expose
  window.Dispatch.manual = {
    updateManualCourierUI: updateManualCourierUI,
    collectManualCourierContext: collectManualCourierContext,
    collectSlipPreviewContext: collectSlipPreviewContext,
    renderTrackingRefs: renderTrackingRefs,
    wireManual: wireManual
  };
})();
