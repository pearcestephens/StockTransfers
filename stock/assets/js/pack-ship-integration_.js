/*
 * File: assets/js/stock-transfers/pack-ship-integration.js
 * Purpose: Front-end integration for pack_ship_api.php
 * Features:
 *  - Fetch carriers & populate carrier select
 *  - Fetch rates for current package set
 *  - Reserve/create/void/track label actions
 *  - Basic audit helper (box weight capacity warnings)
 *  - Lightweight state + UI hooks (no framework)
 *
 * Assumptions:
 *  - Ship wizard container has IDs:
 *      #ship-carrier-select, #ship-rates-table tbody, #ship-rate-refresh,
 *      #ship-reserve-btn, #ship-create-btn, #ship-void-btn, #ship-track-btn,
 *      #ship-tracking-input, #ship-audit-btn, #ship-audit-output
 *  - Package list derived from existing pack table rows with data attributes
 *  - Draft/pack page already loaded
 */
(function(){
  const API_BASE = '/modules/transfers/stock/api/pack_ship_api.php';
  const idemCache = {}; // signature -> key
  const els = {};
  function qs(id){ return document.getElementById(id); }
  function initEls(){
    ['ship-carrier-select','ship-rates-table','ship-rate-refresh','ship-reserve-btn','ship-create-btn','ship-void-btn','ship-track-btn','ship-tracking-input','ship-audit-btn','ship-audit-output','ship-selected-rate'].forEach(id=>{ els[id]=qs(id); });
  }
  function toast(msg,type='info'){ console.log('[SHIP]',type,msg); }
  async function api(action, payload, extraHeaders={}){
    const body = payload? JSON.stringify({action,...payload}) : JSON.stringify({action});
    const headers = {'Content-Type':'application/json', ...extraHeaders};
    const res = await fetch(API_BASE+'?action='+encodeURIComponent(action), {method:'POST',headers,body});
    return res.json();
  }
  function gatherPackages(){
    // Example: rows in table with class .pack-row and data dims/weight
    const rows = document.querySelectorAll('.pack-row');
    const pkgs=[]; rows.forEach(r=>{
      const l = parseInt(r.getAttribute('data-l')||'30',10);
      const w = parseInt(r.getAttribute('data-w')||'30',10);
      const h = parseInt(r.getAttribute('data-h')||'20',10);
      const kg = parseFloat(r.getAttribute('data-kg')||'1');
      const items = parseInt(r.getAttribute('data-items')||'1',10);
      pkgs.push({l,w,h,kg,items});
    });
    return pkgs.length? pkgs : [{l:30,w:30,h:20,kg:1,items:1}]; // fallback demo package
  }
  async function loadCarriers(){
    const j = await api('carriers');
    if(!j.ok){ toast('Failed carriers','error'); return; }
    if(!els['ship-carrier-select']) return;
    els['ship-carrier-select'].innerHTML = '<option value="all">All</option>' + j.carriers.map(c=>`<option value="${c.code}">${c.name}${c.mode!=='live'?' ('+c.mode+')':''}</option>`).join('');
  }
  async function loadRates(){
    const carrier = els['ship-carrier-select']? els['ship-carrier-select'].value : 'all';
    const packages = gatherPackages();
    const payload = { carrier, packages, options:{sig:true}, context:{from:'warehouse',to:'customer',rural:false,saturday:false}};
    const j = await api('rates', payload);
    const tbody = els['ship-rates-table']? els['ship-rates-table'].querySelector('tbody'):null;
    if(!tbody) return;
    if(!j.ok){ tbody.innerHTML = `<tr><td colspan="6" class="text-danger">Error loading rates: ${j.error?.msg||''}</td></tr>`; return; }
    if(!j.results.length){ tbody.innerHTML = '<tr><td colspan="6">No rates</td></tr>'; return; }
    tbody.innerHTML = j.results.map(r=>`<tr class="ship-rate-row" data-carrier="${r.carrier}" data-service="${r.service}" data-total="${r.total}">
      <td><input type="radio" name="ship_rate_pick"></td>
      <td><span class="badge" style="background:${r.color}">${r.carrier_name}</span></td>
      <td>${r.service_name}</td>
      <td>${r.eta}</td>
      <td>$${r.total.toFixed(2)}</td>
      <td><small>${Object.entries(r.breakdown).map(([k,v])=>k+':'+(typeof v==='number'?v.toFixed(2):v)).join(', ')}</small></td>
    </tr>`).join('');
  }
  function selectedRate(){
    const row = document.querySelector('.ship-rate-row input[name="ship_rate_pick"]:checked');
    if(!row) return null;
    const tr = row.closest('tr');
    return { carrier: tr.getAttribute('data-carrier'), service: tr.getAttribute('data-service'), total: parseFloat(tr.getAttribute('data-total')||'0')};
  }
  async function doReserve(){
    const rate = selectedRate(); if(!rate){ toast('Select a rate first','warn'); return; }
    const sig = 'reserve:'+rate.carrier+':'+rate.service+':'+rate.total.toFixed(2);
    if(!idemCache[sig]) idemCache[sig] = 'idem-'+Math.random().toString(36).slice(2)+Date.now();
    const j = await api('reserve',{carrier:rate.carrier,payload:{service:rate.service,total:rate.total}},{'X-Idempotency-Key':idemCache[sig]});
    if(!j.ok){ toast('Reserve failed','error'); return; }
    toast('Reserved: '+j.reservation_id,'success');
    els['ship-selected-rate'] && (els['ship-selected-rate'].textContent = 'Reserved '+j.number+' ('+rate.carrier+')');
  }
  async function doCreate(){
    const rate = selectedRate(); if(!rate){ toast('Select a rate first','warn'); return; }
    const sig = 'create:'+rate.carrier+':'+rate.service+':'+rate.total.toFixed(2);
    if(!idemCache[sig]) idemCache[sig] = 'idem-'+Math.random().toString(36).slice(2)+Date.now();
    const j = await api('create',{carrier:rate.carrier,payload:{service:rate.service,total:rate.total}},{'X-Idempotency-Key':idemCache[sig]});
    if(!j.ok){ toast('Create failed','error'); return; }
    toast('Label created: '+j.tracking_number,'success');
    els['ship-selected-rate'] && (els['ship-selected-rate'].textContent = 'Label '+j.tracking_number+' '+(j.url||''));
  }
  async function doVoid(){
    const labelId = prompt('Label ID to void:'); if(!labelId) return;
    const carrier = els['ship-carrier-select']? els['ship-carrier-select'].value : 'nz_post';
    const j = await api('void',{carrier,label_id:labelId});
    if(!j.ok){ toast('Void failed','error'); return; }
    toast('Voided','success');
  }
  async function doTrack(){
    const tracking = els['ship-tracking-input']? els['ship-tracking-input'].value.trim():'';
    if(!tracking){ toast('Enter tracking','warn'); return; }
    const carrier = els['ship-carrier-select']? els['ship-carrier-select'].value : 'nz_post';
    const j = await api('track',{carrier,tracking});
    if(!j.ok){ toast('Track failed','error'); return; }
    alert('Events:\n'+j.events.map(e=>e.ts+' - '+e.desc).join('\n'));
  }
  async function doAudit(){
    const packages = gatherPackages();
    const j = await api('audit',{packages});
    if(!j.ok){ toast('Audit failed','error'); return; }
    if(els['ship-audit-output']){
      els['ship-audit-output'].innerHTML = '<ul>'+j.suggestions.map(s=>'<li>'+s+'</li>').join('')+'</ul>' + '<div class="d-flex gap-2 flex-wrap">'+j.meters.map(m=>`<div class="p-2 border rounded" style="min-width:140px"><strong>Box ${m.box}</strong><br><span>${m.kg}kg / ${m.cap}kg</span><br><div class="progress" style="height:6px"><div class="progress-bar bg-${m.pct>90?'danger':m.pct>70?'warning':'success'}" style="width:${m.pct}%"></div></div></div>`).join('')+'</div>';
    }
  }
  function bind(){
    els['ship-rate-refresh'] && els['ship-rate-refresh'].addEventListener('click', loadRates);
    els['ship-carrier-select'] && els['ship-carrier-select'].addEventListener('change', loadRates);
    els['ship-reserve-btn'] && els['ship-reserve-btn'].addEventListener('click', doReserve);
    els['ship-create-btn'] && els['ship-create-btn'].addEventListener('click', doCreate);
    els['ship-void-btn'] && els['ship-void-btn'].addEventListener('click', doVoid);
    els['ship-track-btn'] && els['ship-track-btn'].addEventListener('click', doTrack);
    els['ship-audit-btn'] && els['ship-audit-btn'].addEventListener('click', doAudit);
  }
  function ready(fn){ if(document.readyState!=='loading') fn(); else document.addEventListener('DOMContentLoaded',fn); }
  ready(async ()=>{ initEls(); bind(); await loadCarriers(); await loadRates(); });
})();
