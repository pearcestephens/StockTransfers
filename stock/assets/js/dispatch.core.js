 (function () {
  'use strict';

  // Root namespace
  var D = (window.Dispatch = window.Dispatch || {});
  var BOOT = (D.BOOT = window.DISPATCH_BOOT || {});
  var PACK_ONLY = !!(BOOT && BOOT.modes && BOOT.modes.pack_only);
  var SHOW_COURIER_DETAIL = (BOOT.ui && Object.prototype.hasOwnProperty.call(BOOT.ui, 'showCourierDetail'))
    ? !!BOOT.ui.showCourierDetail : true;

  // --- tiny helpers ---
  function $(sel, ctx) { return (ctx || document).querySelector(sel); }
  function $$(sel, ctx) { return Array.from((ctx || document).querySelectorAll(sel)); }
  function toNumber(v, fb) { var n = Number(v); return Number.isFinite(n) ? n : (fb || 0); }
  function fmt$(n) { return '$' + (Number(n) || 0).toFixed(2); }
  var ESC = {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}; 
  function escapeHtml(s){ return String(s==null?'':s).replace(/[&<>"']/g, function(c){return ESC[c];}); }
  function newUuid(){ if (crypto && crypto.randomUUID) return crypto.randomUUID();
    return 'uuid-' + Date.now().toString(16) + '-' + Math.random().toString(16).slice(2,10);
  }
  function hash53(s){var h1=0xdeadbeef^s.length,h2=0x41c6ce57^s.length;for(var i=0;i<s.length;i++){var ch=s.charCodeAt(i);
    h1=Math.imul(h1^ch,2654435761);h2=Math.imul(h2^ch,1597334677);}h1=(Math.imul(h1^(h1>>>16),2246822507)^Math.imul(h2^(h2>>>13),3266489909));
    h2=(Math.imul(h2^(h2>>>16),2246822507)^Math.imul(h1^(h1>>>13),3266489909));return (4294967296*(2097151&(h2>>>0))+(h1>>>0)).toString(36);}

  function ajax(method, url, data, timeout, extra){
    var ctrl = new AbortController();
    var id = setTimeout(function(){ try{ctrl.abort();}catch(_){ } }, timeout || 15000);
    var headers = Object.assign({
      'Content-Type':'application/json',
      'X-API-Key': BOOT.tokens && BOOT.tokens.apiKey || '',
      'X-NZPost-Token': BOOT.tokens && BOOT.tokens.nzPost || '',
      'X-GSS-Token': BOOT.tokens && BOOT.tokens.gss || '',
      'X-Transfer-ID': BOOT.transferId,
      'X-From-Outlet-ID': (BOOT.legacy && BOOT.legacy.fromOutletId) || '',
      'X-To-Outlet-ID': (BOOT.legacy && BOOT.legacy.toOutletId) || '',
      'X-From-Outlet-UUID': BOOT.fromOutletUuid || '',
      'X-To-Outlet-UUID': BOOT.toOutletUuid || ''
    }, (extra && extra.headers) || {});
    var isGET = method === 'GET';
    var u = isGET && data ? url + (url.includes('?') ? '&':'?') + new URLSearchParams(data) : url;
    var body = isGET ? null : (extra && typeof extra.rawBody === 'string' ? extra.rawBody : JSON.stringify(data || {}));
    return fetch(u, {method: method, headers: headers, body: body, signal: ctrl.signal}).then(function(r){
      clearTimeout(id);
      var ct = r.headers.get('content-type') || '';
      return (ct.includes('json') ? r.json() : r.text()).then(function(p){
        if (!r.ok) { var e=new Error((p && p.error) || ('HTTP '+r.status)); e.response=p; throw e; }
        return p;
      });
    });
  }

  // presets
  var PRESETS = {
    satchel: [
      {id:'nzp_s', name:'NZ Post Satchel Small 220×310', w:22, l:31, h:4,  kg:0.15, items:0},
      {id:'nzp_m', name:'NZ Post Satchel Medium 280×390', w:28, l:39, h:4,  kg:0.20, items:0},
      {id:'nzp_l', name:'NZ Post Satchel Large 335×420',  w:33, l:42, h:4,  kg:0.25, items:0}
    ],
    box: [
      {id:'vs_m',  name:'VS Box Medium 400×300×200', w:30, l:40, h:20, kg:2.0, items:0},
      {id:'vs_l',  name:'VS Box Large 450×350×250',  w:35, l:45, h:25, kg:2.5, items:0},
      {id:'vs_xl', name:'VS Box XL 500×400×300',     w:40, l:50, h:30, kg:3.1, items:0}
    ]
  };

  // initial state
  var state = (D.state = {
    method: 'courier',
    packMode: 'PACKED_NOT_SENT',
    container: 'satchel',
    packages: [{ id:1, ...PRESETS.satchel[1] }],
    selection: null,
    selectionKey: null,
    options: { sig:true, atl:false, age:false, sat:false, reviewedBy:'' },
    metrics: { weight:0, items:0, missing:0, count:1, capPer:15 },
    printPool: { online: !!(BOOT.capabilities && BOOT.capabilities.printPool && BOOT.capabilities.printPool.online),
                 onlineCount: Number((BOOT.capabilities && BOOT.capabilities.printPool && BOOT.capabilities.printPool.onlineCount) || 0),
                 totalCount: Number((BOOT.capabilities && BOOT.capabilities.printPool && BOOT.capabilities.printPool.totalCount) || 0),
                 updatedAt: null },
    rates: { loading:false, items:[], error:null, lastHash:null, lastError:null, lastRecommendedKey:null },
    comments: Array.isArray(BOOT.timeline) ? BOOT.timeline.map(function(e){
      function ts(v){ if (typeof v==='number' && Number.isFinite(v)) return v; var d=v?new Date(v):null; var t=d instanceof Date?d.getTime():NaN; return Number.isNaN(t)?Date.now():t; }
      return { scope: (e && e.scope) ? String(e.scope) : 'note', text: (e && e.text) ? String(e.text) : '', ts: ts(e && e.ts), user: e && e.user ? String(e.user) : null, note_id: e && e.id || null };
    }): [{ scope:'system', text:'Transfer #' + (BOOT.transferId || '') + ' created', ts: Date.now(), user:null }],
    trackingRefs: [],
    manualCourier: { preset:'', extra:'' },
    facts: { rural:null, saturday_serviceable:null },
    carriersFallbackUsed: false
  });

  // carriers fallback
  function useEffectiveCarriers(){
    if (PACK_ONLY) return {};
    var carriers = Object.assign({}, (BOOT.capabilities && BOOT.capabilities.carriers) || {});
    var hasDeclared = Object.values(carriers).some(Boolean);
    if (!hasDeclared) { carriers.nzpost = true; state.carriersFallbackUsed = true; }
    return carriers;
  }

  // read notes
  function readNotes(){
    var cands = ['#notesForTransfer','#noteText','#packNotes'];
    for (var i=0;i<cands.length;i++){ var el = $(cands[i]); if (el && typeof el.value === 'string'){ var t=el.value.trim(); if (t) return t; } }
    return '';
  }

  // mode payloads
  function parseDtLocal(v){ var raw=String(v||'').trim(); if(!raw) return null; var d=new Date(raw); return Number.isNaN(d.getTime())?null:d.toISOString(); }
  function posInt(v){ var n=parseInt(String(v||'').trim(),10); return Number.isInteger(n)&&n>0 ? n : null; }

  function buildPickup(){
    var by=($('#pickupBy')||{}).value||'', phone=($('#pickupPhone')||{}).value||'';
    var time=($('#pickupTime')||{}).value||'', boxes=($('#pickupPkgs')||{}).value||'';
    var notes=($('#pickupNotes')||{}).value||'';
    if(!by) throw new Error('Pickup contact is required.');
    if(!phone) throw new Error('Pickup contact phone is required.');
    if(!time) throw new Error('Pickup time is required.');
    var parcels=posInt(boxes); if(parcels===null) throw new Error('Pickup box count must be greater than zero.');
    var iso=parseDtLocal(time); if(!iso) throw new Error('Pickup time is invalid.');
    var p={by:by, phone:phone, time:iso, parcels:parcels}; if(notes) p.notes=notes; return p;
  }
  function buildInternal(){
    var driver=($('#intCarrier')||{}).value||'', depart=($('#intDepart')||{}).value||'', boxes=($('#intBoxes')||{}).value||'';
    var notes=($('#intNotes')||{}).value||'';
    if(!driver) throw new Error('Internal driver/van is required.');
    if(!depart) throw new Error('Internal departure time is required.');
    var iso=parseDtLocal(depart); if(!iso) throw new Error('Internal departure time is invalid.');
    var n=posInt(boxes); if(n===null) throw new Error('Internal box count must be greater than zero.');
    var p={driver:driver, depart_at:iso, boxes:n}; if(notes) p.notes=notes; return p;
  }
  function buildDepot(){
    var location=($('#dropLocation')||{}).value||'', when=($('#dropWhen')||{}).value||'', boxes=($('#dropBoxes')||{}).value||'';
    var notes=($('#dropNotes')||{}).value||'';
    if(!location) throw new Error('Depot location is required.');
    if(!when) throw new Error('Depot drop-off time is required.');
    var iso=parseDtLocal(when); if(!iso) throw new Error('Depot drop-off time is invalid.');
    var n=posInt(boxes); if(n===null) throw new Error('Depot drop-off box count must be greater than zero.');
    var p={location:location, drop_at:iso, boxes:n}; if(notes) p.notes=notes; return p;
  }

  function collectParcels(){
    var rows=[];
    (Array.isArray(state.packages)?state.packages:[]).forEach(function(pkg,i){
      if(!pkg) return;
      var l=toNumber(pkg.l||pkg.length||pkg.length_cm,0);
      var w=toNumber(pkg.w||pkg.width||pkg.width_cm,0);
      var h=toNumber(pkg.h||pkg.height||pkg.height_cm,0);
      var kg=toNumber(pkg.kg||pkg.weight_kg,0);
      rows.push({sequence:i+1, name:String(pkg.name||('Parcel '+(i+1))),
        length_mm: l>0?Math.round(l*10):null, width_mm: w>0?Math.round(w*10):null, height_mm: h>0?Math.round(h*10):null,
        weight_kg: kg>0?Number(kg.toFixed(3)):null, estimated: !!pkg.estimated, notes: (pkg.notes && String(pkg.notes).trim()) || null});
    });
    return rows;
  }

  function buildPackSendPayload(mode, sendNow, idempotencyKey){
    if(!BOOT.fromOutletUuid || !BOOT.toOutletUuid) throw new Error('Missing outlet UUIDs in boot payload.');

    // manual external mapping
    var CARRIER_MODE = { 1:'NZP_MANUAL', 2:'NZC_MANUAL' }; // 1=NZ Post, 2=NZ Couriers
    var carrierSel = $('#carrier_id'), carrierId=null;
    if (carrierSel && carrierSel.value){ var cid=parseInt(carrierSel.value,10); if (Number.isFinite(cid)) carrierId = cid; }

    var canonical = mode === 'COURIER_MANAGED_EXTERNALLY' ? (CARRIER_MODE[carrierId] || 'NZC_MANUAL') : mode;
    var parcels = collectParcels();

    var totals = {};
    if (Number.isFinite(state.metrics && state.metrics.weight) && state.metrics.weight>0) totals.total_weight_kg = Number(state.metrics.weight.toFixed(3));
    if (Number.isFinite(state.metrics && state.metrics.count)  && state.metrics.count>0)  totals.box_count       = state.metrics.count;

    var payload = {
      idempotency_key: idempotencyKey,
      mode: canonical,
      send_now: (mode==='PACKED_NOT_SENT') ? false : !!sendNow,
      transfer: { id: String(BOOT.transferId||''), from_outlet_uuid: BOOT.fromOutletUuid, to_outlet_uuid: BOOT.toOutletUuid },
      totals: totals
    };

    if (carrierId) payload.carrier_id = carrierId;
    if (parcels.length) payload.parcels = parcels.map(function(r){ return {
      sequence:r.sequence, name:r.name, weight_kg:r.weight_kg, length_mm:r.length_mm, width_mm:r.width_mm, height_mm:r.height_mm, estimated:r.estimated, notes:r.notes
    }; });

    if (!Object.keys(payload.totals).length) delete payload.totals;

    var notes = readNotes(); if (notes) { payload.transfer.notes = notes; payload.notes = notes; }

    // optional: manual tracking arrays if present
    try{
      if (typeof window.getManualTracking === 'function'){ var mt=window.getManualTracking(); if (Array.isArray(mt) && mt.length) payload.manual_tracking = mt; }
      if (typeof window.getManualTrackingNumbers === 'function'){ var mtn=window.getManualTrackingNumbers(); if (Array.isArray(mtn) && mtn.length) payload.trackingNumbers = mtn; }
    }catch(_){}

    // mode sections
    try{
      if (mode==='PICKUP') payload.pickup = buildPickup();
      else if (mode==='INTERNAL_DRIVE') payload.internal = buildInternal();
      else if (mode==='DEPOT_DROP') payload.depot = buildDepot();
    }catch(e){ throw e; }

    return payload;
  }

  function payloadForPrint(markPacked, options){
    options = options || {};
    var manual = (window.Dispatch.manual && window.Dispatch.manual.collectManualCourierContext) ? window.Dispatch.manual.collectManualCourierContext() : null;
    var slip   = (window.Dispatch.manual && window.Dispatch.manual.collectSlipPreviewContext) ? window.Dispatch.manual.collectSlipPreviewContext(manual) : null;
    var base = {
      meta: { transfer_id: BOOT.transferId, from_outlet_id: BOOT.fromOutletId, to_outlet_id: BOOT.toOutletId },
      reviewed_by: state.options.reviewedBy,
      method: state.method,
      container: state.container,
      options: { sig: $('#optSig') && $('#optSig').checked, atl: $('#optATL') && $('#optATL').checked, age: $('#optAge') && $('#optAge').checked, sat: $('#optSat') && $('#optSat').checked },
      address_facts: state.facts,
      selection: state.selection,
      packages: state.packages,
      tracking_refs: Array.isArray(state.trackingRefs) ? state.trackingRefs.slice() : [],
      manual_courier: manual,
      slip_preview: slip,
      mark_packed: !!markPacked,
      idem: hash53(JSON.stringify(state))
    };
    if (options.slipOnly) base.slip_only = true;
    return base;
  }

  // expose
  D.util = { $, $$, toNumber, fmt$, escapeHtml, newUuid, hash53, ajax };
  D.flags = { PACK_ONLY: PACK_ONLY, SHOW_COURIER_DETAIL: SHOW_COURIER_DETAIL };
  D.PRESETS = PRESETS;
  D.core = {
    useEffectiveCarriers: useEffectiveCarriers,
    readNotes: readNotes,
    buildPickup: buildPickup,
    buildInternal: buildInternal,
    buildDepot: buildDepot,
    collectParcels: collectParcels,
    buildPackSendPayload: buildPackSendPayload,
    payloadForPrint: payloadForPrint
  };
})();
