 (function(){
  'use strict';
  var D = window.Dispatch, U = D.util, S = D.state, core = D.core;

  // FEED
  function renderFeed(){
    var feed=U.$('#activityFeed'); if(!feed) return;
    feed.innerHTML='';
    var items=S.comments.slice().reverse();
    if(!items.length){ feed.innerHTML='<div class="sub">No activity yet.</div>'; return; }
    items.forEach(function(evt){
      var div=document.createElement('div');
      var scope=(evt.scope||'system').toString();
      div.className='feed-item '+scope.split(':')[0];
      var when=new Date(evt.ts).toLocaleString();
      var scopeSafe=U.escapeHtml(evt.scope||''), userSafe=U.escapeHtml(evt.user||''), meta=[];
      if(scopeSafe) meta.push(scopeSafe); if(userSafe) meta.push(userSafe);
      var textSafe=U.escapeHtml(evt.text||'');
      div.innerHTML='<div class="feed-dot"></div><div><div class="feed-head"><div>'+textSafe+'</div><div class="feed-meta mono">'+when+'</div></div><div class="feed-meta">'+meta.join(' · ')+'</div></div>';
      feed.appendChild(div);
    });
  }

  // PRINT POOL
  function syncPrintPoolUI(){
    var info=S.printPool||{}, online=!!info.online, offline=!online;
    var trackingWrap=U.$('#manualTrackingWrap'), allowTracking=(S.method==='courier') && (offline || !D.flags.SHOW_COURIER_DETAIL);
    if (trackingWrap){ if(allowTracking){ trackingWrap.removeAttribute('hidden'); trackingWrap.classList.add('is-active'); D.manual.renderTrackingRefs(); } else { trackingWrap.setAttribute('hidden',''); trackingWrap.classList.remove('is-active'); } }

    if (D.flags.PACK_ONLY || !D.flags.SHOW_COURIER_DETAIL){
      ['#printPoolDot','#printPoolText','#printPoolMeta'].forEach(function(sel){ U.$$(sel).forEach(function(n){ if('hidden'in n) n.hidden=true; if(n.classList) n.classList.add('d-none'); }); });
      var manual=U.$('#blkManual'); if (manual){ manual.hidden=true; manual.classList.add('d-none'); }
      return;
    }
    var dot=U.$('#printPoolDot'); if (dot){ dot.className='dot '+(online?'ok':'err'); }
    var text=U.$('#printPoolText'); if (text){ text.textContent = online ? 'Print pool online' : 'Print pool offline'; }
    var meta=U.$('#printPoolMeta'); if (meta){ var total=Number(info.totalCount||0), ok=Number(info.onlineCount||0); meta.textContent = total > 0 ? (Math.max(0,ok)+' of '+Math.max(0,total)+' printers ready') : 'Awaiting printer status'; }
    var block=U.$('#uiBlock'); if (block){ block.classList.toggle('show', offline && S.method==='courier'); }
    var reviewed=U.$('#reviewedWrap'); if (reviewed){ reviewed.style.display = (offline || S.method!=='courier') ? 'block' : 'none'; }
    var manual=U.$('#blkManual'); if (manual){ var show = D.flags.SHOW_COURIER_DETAIL && offline && S.method==='courier'; manual.hidden = !show; manual.classList.toggle('d-none', !show); }
  }

  async function refreshPrintPool(){
    if (D.flags.PACK_ONLY) return;
    var url=D.BOOT.endpoints && D.BOOT.endpoints.print_pool; if (!url) return;
    try{
      var payload = D.BOOT.fromOutletUuid ? {from_outlet_uuid:D.BOOT.fromOutletUuid} : {from_outlet_id: (D.BOOT.legacy&&D.BOOT.legacy.fromOutletId)||0 };
      var r=await U.ajax('GET', url, payload);
      S.printPool = { online: !!(r.online||r.print_pool_online||r.ok||S.printPool.online),
                      onlineCount: Number(r.online_count||r.printers_online||S.printPool.onlineCount||0),
                      totalCount: Number(r.total_count||r.printers_total||S.printPool.totalCount||0),
                      updatedAt: Date.now() };
    }catch(_){ S.printPool = Object.assign({}, S.printPool, { online:false, updatedAt:Date.now() }); }
    syncPrintPoolUI();
  }

  // ADDRESS FACTS
  async function refreshAddressFacts(){
    var toUuid=(D.BOOT.toOutletUuid||'').trim(), toId=Number((D.BOOT.legacy && D.BOOT.legacy.toOutletId)||0);
    if ((toUuid==='' && (!Number.isFinite(toId)||toId<=0))){ S.facts={rural:false,saturday_serviceable:true}; var f1=U.$('#factRural'), f2=U.$('#factSat');
      if(f1) f1.textContent='No'; if(f2) f2.textContent='Yes'; D.rates && D.rates.scheduleRatesRefresh('address-facts',{force:true}); return; }
    try{
      var params = toUuid!=='' ? {to_outlet_uuid:toUuid} : {to_outlet_id:toId};
      var data = await U.ajax('GET', D.BOOT.endpoints.address_facts, params);
      S.facts = { rural: !!data.rural, saturday_serviceable: !!data.saturday_serviceable };
    }catch(_){ S.facts = { rural:false, saturday_serviceable:true }; }
    var r=U.$('#factRural'), s=U.$('#factSat'); if(r) r.textContent = S.facts.rural?'Yes':'No'; if(s) s.textContent = S.facts.saturday_serviceable?'Yes':'No';
    D.rates && D.rates.scheduleRatesRefresh('address-facts',{force:true});
  }

  // PRINT
  function doPrint(markPacked, options){
    options=options||{}; if (!options.slipOnly && S.method==='courier' && D.flags.SHOW_COURIER_DETAIL && !S.selection) return;
    if (!options.slipOnly && S.method==='courier' && D.flags.SHOW_COURIER_DETAIL && !(S.printPool&&S.printPool.online)){ syncPrintPoolUI(); if (window.PackToast) window.PackToast.warn('Print pool offline'); return; }
    var slip=document.querySelector('.slip'); if (slip){ slip.style.width='80mm'; slip.style.maxWidth='80mm'; slip.style.margin='0 auto'; }
    window.print();
    if (slip){ setTimeout(function(){ slip.style.width=''; slip.style.maxWidth=''; slip.style.margin=''; }, 1000); }
    var context = core.payloadForPrint(markPacked, options);
    var label = options.slipOnly ? 'Slip print' : (markPacked ? (context.manual_courier && context.manual_courier.preset ? 'Marked as packed & sent' : 'Marked as packed') : 'Print only');
    S.comments.push({ scope:'system', text: label, ts: Date.now() }); renderFeed();
  }

  // SETTINGS PANEL
  function openSettings(){
    var body=U.$('#settingsBody'); if (!body) return;
    var rows = [
      ['Transfer', D.BOOT.transferId],
      ['From Outlet ID', D.BOOT.fromOutletId],
      ['To Outlet ID', D.BOOT.toOutletId],
      ['Carrier · NZ Post', (D.BOOT.capabilities && D.BOOT.capabilities.carriers && D.BOOT.capabilities.carriers.nzpost) ? 'Enabled':'Disabled'],
      ['Carrier · NZ Couriers', (D.BOOT.capabilities && D.BOOT.capabilities.carriers && D.BOOT.capabilities.carriers.nzc) ? 'Enabled':'Disabled'],
      ['Carrier Fallback Active', S.carriersFallbackUsed?'Yes':'No'],
      ['Print Pool Online', S.printPool && S.printPool.online ? 'Yes':'No'],
      ['Print Pool Count', (S.printPool&&S.printPool.onlineCount||0)+'/'+(S.printPool&&S.printPool.totalCount||0)],
      ['X-API-Key', (D.BOOT.tokens&&D.BOOT.tokens.apiKey)||''],
      ['X-NZPost-Token', (D.BOOT.tokens&&D.BOOT.tokens.nzPost)||''],
      ['X-GSS-Token', (D.BOOT.tokens&&D.BOOT.tokens.gss)||''],
      ['Rates URL', (D.BOOT.endpoints&&D.BOOT.endpoints.rates)||'—'],
      ['Create URL', (D.BOOT.endpoints&&D.BOOT.endpoints.create)||'—'],
      ['Address Facts URL', (D.BOOT.endpoints&&D.BOOT.endpoints.address_facts)||'—'],
      ['Print Pool URL', (D.BOOT.endpoints&&D.BOOT.endpoints.print_pool)||'—']
    ];
    body.innerHTML = rows.map(function(r){ return '<div style="margin:4px 0"><b>'+r[0]+':</b> <code>'+String(r[1])+'</code></div>'; }).join('');
    var d=U.$('#drawer'); if (d) d.style.display='grid';
  }

  // PACK-ONLY UI
  function wirePackOnly(){
    S.method='pack_only';
    // hide big wizard if present
    var wiz=U.$('#ship-wizard'); if (wiz){ wiz.classList.add('d-none'); wiz.setAttribute('aria-hidden','true'); }
    var panel=U.$('#packOnlyPanel'); if (panel){ panel.classList.remove('d-none'); panel.setAttribute('aria-hidden','false'); }
    var trackingNode=U.$('#tracking-items'); if (trackingNode){ var col=trackingNode.closest('.col-md-6'); if (col){ col.classList.add('d-none'); col.setAttribute('aria-hidden','true'); } }
    var printPoolCard=U.$('#printPoolCard'); if (printPoolCard){ printPoolCard.classList.add('d-none'); }

    var modeSel=U.$('#packModeSelect');
    var order=['PACKED_NOT_SENT','PICKUP','INTERNAL_DRIVE','DEPOT_DROP'], avail = Array.isArray(D.BOOT.capabilities && D.BOOT.capabilities.modes) ? D.BOOT.capabilities.modes.slice() : [];
    var allowedSet=new Set(order), availModes=avail.filter(function(m){return allowedSet.has(m);}); if(!availModes.length) availModes=order;
    else { availModes=order.filter(function(m){return availModes.includes(m);}); if(!availModes.length) availModes=['PACKED_NOT_SENT']; }
    if (modeSel){ modeSel.innerHTML = availModes.map(function(m){ var label=(m==='PACKED_NOT_SENT'?'Packed (no dispatch)':
      m==='PICKUP'?'Pickup/Third-party': m==='INTERNAL_DRIVE'?'Internal Drive': m==='DEPOT_DROP'?'Depot Drop-off': m.replace(/_/g,' '));
      return '<option value="'+m+'">'+label+'</option>'; }).join(''); }
    var def = S.packMode && availModes.includes(S.packMode) ? S.packMode : (availModes.includes('COURIER_MANUAL_NZC')?'COURIER_MANUAL_NZC':availModes[0]);
    S.packMode=def; if (modeSel) modeSel.value=def;

    function updateBtnLabel(btn,t){ if(!btn) return; (btn.querySelector('.btn-label')||btn).textContent = t ? 'Mark as Packed & Send' : 'Mark as Packed'; }

    var toggle=U.$('#packSendNowToggle'), button=U.$('#packOnlyBtn');
    if (toggle){
      function handle(){ updateBtnLabel(button, !!toggle.checked); }
      toggle.disabled = (S.packMode==='PACKED_NOT_SENT'); if (!toggle.disabled && !toggle.checked && S.packMode==='PICKUP') toggle.checked=true;
      toggle.addEventListener('change', handle); handle();
    } else { updateBtnLabel(button, true); }

    if (modeSel){ modeSel.addEventListener('change', function(){
      S.packMode = modeSel.value;
      var needsCarrier = (S.packMode==='COURIER_MANAGED_EXTERNALLY'||S.packMode==='NZC_MANUAL'||S.packMode==='NZP_MANUAL');
      var row=U.$('#carrier-row'); if (row){ row.style.display = needsCarrier ? '' : 'none'; }
      if (toggle){ toggle.disabled = (S.packMode==='PACKED_NOT_SENT'); if (!toggle.disabled && !toggle.checked && S.packMode==='PICKUP') toggle.checked=true; }
    }); }

    // inject carrier row (if missing)
    (function injectCarrierRow(){
      if (U.$('#carrier-row')) return;
      var wrapper=(modeSel && (modeSel.closest('.form-group')||modeSel.parentElement)); if(!wrapper||!wrapper.parentElement) return;
      var div=document.createElement('div'); div.className='form-group'; div.id='carrier-row'; div.style.display='none';
      div.innerHTML = '<label for="carrier_id" class="mb-1">Carrier</label>'+
        '<select id="carrier_id" class="form-control form-control-sm"><option value="2">NZ Couriers (GSS)</option><option value="1">NZ Post</option></select>'+
        '<small class="text-muted">Choose the carrier when using “Courier Managed Externally”.</small>';
      wrapper.parentElement.insertBefore(div, wrapper.nextSibling);
    })();

    if (button){
      button.addEventListener('click', async function(){
        var status=U.$('#packOnlyStatus'); if (status){ status.textContent='Submitting…'; status.classList.remove('text-danger','text-success','text-warning'); status.classList.add('text-muted'); }
        var idem='pack-'+D.BOOT.transferId+'-'+Date.now().toString(16)+'-'+Math.random().toString(16).slice(2,10);
        var payload;
        try{
          payload = core.buildPackSendPayload(S.packMode, toggle ? !!toggle.checked : true, idem);
          button.disabled = true;
        }catch(e){
          if (status){ status.textContent=e.message||'Unable to build payload.'; status.classList.remove('text-muted'); status.classList.add('text-danger'); }
          if (window.PackToast) window.PackToast.error(e.message||'Unable to build payload');
          return;
        }
        try{
          var res = await U.ajax('POST', D.BOOT.endpoints.pack_send, payload, 20000, { headers:{'Idempotency-Key':idem,'X-Request-ID':U.newUuid()} });
          if (!res || typeof res!=='object') throw new Error('Empty response');
          if (!res.ok){ var msg=res.error&&res.error.message || 'Pack/send rejected.'; if (status){ status.textContent=msg; status.classList.remove('text-muted'); status.classList.add('text-danger'); } if (window.PackToast) window.PackToast.error(msg); button.disabled=false; return; }
          var warnings=Array.isArray(res.warnings)?res.warnings.filter(Boolean):[]; var txt=(toggle&&toggle.checked)?'Packed & marked in transit':'Packed (not dispatched)';
          if (status){ status.textContent=warnings.length?warnings.join(' • '):txt; status.classList.remove('text-muted'); status.classList.add(warnings.length?'text-warning':'text-success'); }
          if (window.PackToast) (warnings.length?window.PackToast.warn:window.PackToast.success)(txt);
          var go=res.data&&res.data.redirect || (D.BOOT.urls&&D.BOOT.urls.after_pack) || '/transfers'; setTimeout(function(){ window.location.assign(go); }, 1200);
        }catch(err){
          var m=err&&err.message || 'Unable to submit pack/send'; if (status){ status.textContent=m; status.classList.remove('text-muted'); status.classList.add('text-danger'); }
          if (window.PackToast) window.PackToast.error(m); button.disabled=false;
        }
      });
    }

    // hide unrelated controls
    ['#ratesList','#sw-rates','#printPoolMeta','#printPoolText','#printPoolDot','#btnSatchel','#btnBox','.js-add','.js-copy','.js-clear','.js-auto','#uiBlock','#blkManual','#blkCourier','.tnav']
      .forEach(function(sel){ U.$$(sel).forEach(function(n){ if(!n) return; if('hidden' in n) n.hidden=true; if(n.classList) n.classList.add('d-none'); }); });

    // render initial rates summary (empty)
    if (D.rates) D.rates.renderRates();
  }

  // WIRING
  function wire(){
    // manual section
    D.manual.updateManualCourierUI();
    D.manual.wireManual();

    // tabs
    U.$$('.tnav .tab').forEach(function(a){
      a.addEventListener('click', function(e){
        e.preventDefault(); U.$$('.tnav .tab').forEach(function(x){ x.removeAttribute('aria-current'); });
        a.setAttribute('aria-current','page');
        S.method = a.dataset.method;
        U.$('#blkCourier').hidden = S.method!=='courier';
        U.$('#blkPickup').hidden  = S.method!=='pickup';
        U.$('#blkInternal').hidden= S.method!=='internal';
        U.$('#blkDropoff').hidden = S.method!=='dropoff';
        U.$('#blkManual').hidden  = true;
        syncPrintPoolUI();
        if (S.method==='courier') refreshPrintPool();
        if (S.method==='courier') D.rates && D.rates.scheduleRatesRefresh('mode-change',{force:true});
        else { D.rates && D.rates.renderRates(); }
        D.manual.updateManualCourierUI();
        S.comments.push({ scope:'system', text:'Mode changed: '+S.method, ts: Date.now() }); renderFeed();
      });
    });

    // options -> re-rate
    ['optSig','optATL','optAge','optSat'].forEach(function(id){
      var el=document.getElementById(id); if (!el) return;
      el.addEventListener('change', function(){ D.rates && D.rates.scheduleRatesRefresh('option-change', {force:true}); });
    });

    var reviewed=U.$('#reviewedBy'); if (reviewed){ reviewed.addEventListener('input', function(e){ S.options.reviewedBy = e.target.value; }); }

    // save-only manual blocks (simulated in UI)
    var simPairs = [
      ['#btnSaveManual', function(){
        var c=(U.$('#mtCarrier')||{}).value, t=((U.$('#mtTrack')||{}).value||'').trim(); if(!t) return alert('Enter tracking number');
        S.comments.push({ scope:'shipment', text:'Manual tracking saved: '+c+' '+t, ts: Date.now() }); renderFeed(); alert('Saved manual tracking (sim)');
      }],
      ['#btnSavePickup', function(){
        var by=((U.$('#pickupBy')||{}).value||'').trim(), time=((U.$('#pickupTime')||{}).value||'').trim(), pkgs=+( (U.$('#pickupPkgs')||{}).value || 0);
        if(!by || !time) return alert('Enter pickup details'); S.comments.push({ scope:'shipment', text:'Pickup saved: '+by+' • '+time+' • '+pkgs+' boxes', ts: Date.now() }); renderFeed();
      }],
      ['#btnSaveInternal', function(){
        var d=((U.$('#intCarrier')||{}).value||'').trim(), depart=((U.$('#intDepart')||{}).value||'').trim(), boxes=+((U.$('#intBoxes')||{}).value||0);
        if(!d || !depart) return alert('Enter internal run details'); S.comments.push({ scope:'shipment', text:'Internal run saved: '+d+' • '+depart+' • '+boxes+' boxes', ts: Date.now() }); renderFeed();
      }],
      ['#btnSaveDrop', function(){
        var where=((U.$('#dropLocation')||{}).value||'').trim(), when=((U.$('#dropWhen')||{}).value||'').trim(), boxes=+((U.$('#dropBoxes')||{}).value||0);
        if(!where || !when) return alert('Enter drop-off details'); S.comments.push({ scope:'shipment', text:'Drop-off saved: '+where+' • '+when+' • '+boxes+' boxes', ts: Date.now() }); renderFeed();
      }]
    ];
    simPairs.forEach(function(p){ var el=U.$(p[0]); if (el) el.addEventListener('click', p[1]); });

    // print buttons
    var bPO=U.$('#btnPrintOnly'), bPP=U.$('#btnPrintPacked'), bR=U.$('#btnReady'), bSP=U.$('#btnSlipPrint'), bDim=U.$('#dismissBlock');
    if (bPO) bPO.addEventListener('click', function(){ doPrint(false); });
    if (bPP) bPP.addEventListener('click', function(){ doPrint(true); });
    if (bR)  bR .addEventListener('click', function(){ doPrint(true); });
    if (bSP) bSP.addEventListener('click', function(){ doPrint(false, { slipOnly:true }); if (window.PackToast) window.PackToast.info('Slip preview queued for printing.'); });
    if (bDim) bDim.addEventListener('click', function(){ var b=U.$('#uiBlock'); if (b) b.classList.remove('show'); });

    // settings
    var btnSet=U.$('#btnSettings'), btnClose=U.$('#closeDrawer');
    if (btnSet) btnSet.addEventListener('click', openSettings);
    if (btnClose){ btnClose.addEventListener('click', function(){ var d=U.$('#drawer'); if (d) d.style.display='none'; }); }

    syncPrintPoolUI(); refreshPrintPool();
  }

  // BOOT
  function boot(){
    // presence guard
    if (!document.getElementById('psx-app')) return;

    // packages
    var hydrated = D.packages.applyBootAutoplan();
    D.packages.loadPresetOptions(); if (!hydrated) D.packages.primeMetricsFromBoot();
    D.packages.renderPackages(); D.packages.wirePackageInputs();

    // manual + feed
    D.manual.renderTrackingRefs(); renderFeed();

    // wire
    wire();

    // initial rates + address facts + pool
    if (!D.flags.PACK_ONLY){ D.rates.scheduleRatesRefresh('boot', {force:true}); refreshAddressFacts(); refreshPrintPool(); }
    else { wirePackOnly(); }
    
    // container switches + package controls
    var bSat=U.$('#btnSatchel'), bBox=U.$('#btnBox'); if (bSat) bSat.addEventListener('click', function(){ D.packages.setContainer('satchel'); }); if (bBox) bBox.addEventListener('click', function(){ D.packages.setContainer('box'); });
    var a=U.$('.js-add'), c=U.$('.js-copy'), clr=U.$('.js-clear'), au=U.$('.js-auto');
    if (a)   a.addEventListener('click', D.packages.addParcelFromPreset);
    if (c)   c.addEventListener('click', D.packages.copyLastParcel);
    if (clr) clr.addEventListener('click', D.packages.clearParcels);
    if (au)  au.addEventListener('click', D.packages.autoAssignParcels);
  }

  if (document.readyState==='loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();

  // expose (if needed by other code)
  window.Dispatch.ui = { doPrint: doPrint, openSettings: openSettings, renderFeed: renderFeed, refreshPrintPool: refreshPrintPool, refreshAddressFacts: refreshAddressFacts };
})();
