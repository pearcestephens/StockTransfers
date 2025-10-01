(function(){
  'use strict';
  var D = window.Dispatch, U = D.util, S = D.state, core = D.core;

  var rateTimer = null, requestId = 0;

  function rateKey(r){ if(!r) return ''; return String((r.carrier_code||r.carrier||'')).toLowerCase()+'::'+String(r.service_code||r.service||'').toLowerCase()+'::'+String(r.package_code||r.package||'').toLowerCase(); }

  function normalizeRate(row){
    if(!row || typeof row!=='object') return null;
    var carrier=(row.carrier_code||row.carrier||'').toString().trim();
    var service=(row.service_code||row.service||'').toString().trim();
    if(!carrier || !service) return null;
    function parseMoney(v){ if (typeof v==='string'){ var c=v.replace(/[^0-9.\-]/g,''); if(!c||c==='-'||c==='.') return NaN; return Number(c);} return Number(v); }
    var rawIncl = row.total_incl_gst || row.total || 0;
    var rawTot  = row.total || rawIncl || 0;
    var totalIncl = U.toNumber(parseMoney(rawIncl),0);
    var total = U.toNumber(parseMoney(rawTot), totalIncl||0);
    if (!Number.isFinite(total) || total<=0) return null;

    return {
      carrier_code: carrier.toLowerCase(),
      carrier_name: (row.carrier_name||row.carrier||carrier).toString(),
      service_code: service,
      service_name: (row.service_name||row.service||service).toString(),
      package_code: (row.package_code||row.package||'').toString().trim()||null,
      package_name: (row.package_name||row.package||'').toString().trim()||null,
      eta: (row.eta||'').toString(),
      total: total,
      total_incl_gst: totalIncl>0? totalIncl : total,
      incl_gst: row.incl_gst !== false
    };
  }

  function setSelection(rate, source){
    if(!rate){ S.selection=null; S.selectionKey=null; renderSummary(); return; }
    S.selection = {
      carrier: (rate.carrier_code||rate.carrier||'').toString(),
      carrierCode: (rate.carrier_code||rate.carrier||'').toString(),
      carrierName: rate.carrier_name || rate.carrier || '',
      service: (rate.service_code||rate.service||'').toString(),
      serviceCode: (rate.service_code||rate.service||'').toString(),
      serviceName: rate.service_name || rate.service || '',
      package_code: rate.package_code||null,
      packageName: rate.package_name||null,
      eta: rate.eta||'',
      total: rate.total,
      total_incl_gst: rate.total_incl_gst>0 ? rate.total_incl_gst : rate.total,
      incl_gst: rate.incl_gst !== false,
      recommended: source === 'auto',
      source: source || 'user'
    };
    S.selectionKey = rateKey(rate);
    renderSummary();
  }

  function buildRatesRequest(reason){
    var satOpt = U.$('#optSat'), satServiceable = !!(S.facts && S.facts.saturday_serviceable);
    var satWanted = !!(satOpt && satOpt.checked && satServiceable);
    return {
      meta: {
        transfer_id: D.BOOT.transferId,
        from_outlet_id: (D.BOOT.legacy && D.BOOT.legacy.fromOutletId) || 0,
        to_outlet_id: (D.BOOT.legacy && D.BOOT.legacy.toOutletId) || 0,
        from_outlet_uuid: D.BOOT.fromOutletUuid || null,
        to_outlet_uuid: D.BOOT.toOutletUuid || null
      },
      packages: (S.packages||[]).map(function(p,i){ return {
        sequence:i+1, name:String(p.name||('Parcel '+(i+1))),
        w: U.toNumber(p.w,0), l: U.toNumber(p.l,0), h: U.toNumber(p.h,0), kg: U.toNumber(p.kg,0),
        items: Math.max(0, Math.trunc(U.toNumber(p.items,0)))
      }; }),
      options: { sig: !!(U.$('#optSig')&&U.$('#optSig').checked), atl: !!(U.$('#optATL')&&U.$('#optATL').checked),
                 age: !!(U.$('#optAge')&&U.$('#optAge').checked), sat: satWanted },
      address_facts: { rural: !!(S.facts && S.facts.rural), saturday_serviceable: satServiceable },
      carriers_enabled: core.useEffectiveCarriers(),
      reason: reason || 'change'
    };
  }

  function scheduleRatesRefresh(reason, opt){
    opt = opt || {};
    if (D.flags.PACK_ONLY) { S.rates.loading=false; S.rates.items=[]; S.rates.error=null; S.rates.lastHash=null; S.rates.lastRecommendedKey=null; setSelection(null); renderRates(); return; }
    if (S.method!=='courier') return;
    if (!D.BOOT.endpoints || !D.BOOT.endpoints.rates){ S.rates.loading=false; S.rates.items=[]; S.rates.error='Rates endpoint unavailable'; S.rates.lastHash=null; S.rates.lastRecommendedKey=null; setSelection(null); renderRates(); return; }
    var carriers = core.useEffectiveCarriers();
    if (!Object.values(carriers).some(Boolean)){ S.rates.loading=false; S.rates.items=[]; S.rates.error=null; S.rates.lastRecommendedKey=null; setSelection(null); renderRates(); return; }
    if (!S.packages.length){ S.rates.loading=false; S.rates.items=[]; S.rates.error=null; S.rates.lastRecommendedKey=null; setSelection(null); renderRates(); return; }

    if (rateTimer) { clearTimeout(rateTimer); rateTimer=null; }
    S.rates.loading = true; S.rates.error=null; renderRates();
    rateTimer = setTimeout(function(){ loadRates(reason, opt); }, 220);
  }

  async function loadRates(reason, opt){
    opt = opt || {};
    if (rateTimer){ clearTimeout(rateTimer); rateTimer=null; }
    if (D.flags.PACK_ONLY || S.method!=='courier' || !D.BOOT.endpoints || !D.BOOT.endpoints.rates || !S.packages.length) return;

    var payload = buildRatesRequest(reason);
    var hash = U.hash53(JSON.stringify(payload));
    if (!opt.force && S.rates.lastHash===hash && !S.rates.error){ S.rates.loading=false; renderRates(); return; }

    S.rates.loading=true; S.rates.error=null; renderRates(); requestId += 1; var rid = requestId;
    try{
      var res = await U.ajax('POST', D.BOOT.endpoints.rates, payload);
      if (rid !== requestId) return; // stale
      var rows = Array.isArray(res) ? res : [];
      var normalized = rows.map(normalizeRate).filter(Boolean).sort(function(a,b){ return (a.total||0)-(b.total||0); });
      S.rates = { loading:false, items: normalized, error:null, lastHash:hash, lastError:null, lastRecommendedKey: S.rates.lastRecommendedKey };

      if (!normalized.length){ S.rates.lastRecommendedKey=null; setSelection(null); renderRates(); return; }
      var prevKey=S.selectionKey, recommendedKey=rateKey(normalized[0]);
      var existing=normalized.find(function(r){return rateKey(r)===prevKey;});
      if (existing){ setSelection(existing, (S.selection && S.selection.source==='user') ? 'user':'auto'); }
      else { var best=normalized[0]; setSelection(best,'auto'); if (S.rates.lastRecommendedKey!==recommendedKey && window.PackToast) window.PackToast.info('Best rate: '+best.carrier_name+' · '+best.service_name+' '+U.fmt$(best.total)); }
      S.rates.lastRecommendedKey = recommendedKey; renderRates();
    }catch(err){
      if (rid!==requestId) return;
      var msg = (err && err.message) ? String(err.message) : 'Unable to load rates';
      var prev = S.rates.lastError;
      S.rates = { loading:false, items:[], error:msg, lastHash:hash, lastError:msg, lastRecommendedKey:null };
      setSelection(null);
      if (prev !== msg) console.warn('[rates] failed:', msg, '['+reason+']');
      renderRates();
    }
  }

  function renderRates(){
    var wrap = U.$('#ratesList'); if (!wrap) return;
    if (D.flags.PACK_ONLY || S.method!=='courier'){ wrap.innerHTML=''; return; }
    var carriers = core.useEffectiveCarriers();
    var carriersEnabled = Object.values(carriers).some(Boolean);
    var satOK = !!(S.facts && S.facts.saturday_serviceable);
    var satInput = U.$('#optSat'); if (satInput){ if (!satOK && satInput.checked) satInput.checked=false; satInput.disabled=!satOK; }

    if (!carriersEnabled){ wrap.innerHTML = '<div class="rate rate-empty">No carriers enabled for this outlet.</div>'; setSelection(null); return; }
    if (!S.packages.length){ wrap.innerHTML = '<div class="rate rate-empty">Add at least one parcel to view live rates.</div>'; setSelection(null); return; }
    if (S.rates.loading){ wrap.innerHTML = '<div class="rate rate-empty">Loading live rates…</div>'; return; }
    if (S.rates.error){ wrap.innerHTML = '<div class="rate rate-error">'+U.escapeHtml(S.rates.error)+'</div>'; setSelection(null); return; }

    var rates = S.rates.items||[]; if (!rates.length){ wrap.innerHTML='<div class="rate rate-empty">No live rates available.</div>'; setSelection(null); return; }
    var recommendedKey = rateKey(rates[0]); wrap.innerHTML='';
    rates.forEach(function(rate){
      var key = rateKey(rate), isActive = (S.selectionKey===key), isRec = (key===recommendedKey);
      var carrierCode=(rate.carrier_code||'').toLowerCase(); var logo = carrierCode==='nzpost'?'nzpost':(carrierCode==='nzc'?'nzc':'generic');
      var priceText = U.fmt$(U.toNumber(rate.total||rate.total_incl_gst||0,0));
      var badges=[]; if (isRec) badges.push('<span class="badge" title="Auto-selected best price">Recommended</span>');
      if (!satOK && /sat/i.test(rate.service_name||'')) badges.push('<span class="badge" title="Saturday not serviceable">No Saturday</span>');
      if (rate.package_name) badges.push('<span class="badge">'+U.escapeHtml(rate.package_name)+'</span>');
      var meta=[]; if ((rate.eta||'').trim()!=='') meta.push('<span>ETA '+U.escapeHtml(rate.eta)+'</span>'); meta.push('<span>'+(rate.incl_gst===false?'Excl GST':'Incl GST')+'</span>');
      var div=document.createElement('div'); div.className='rate'+(isActive?' is-active':'');
      div.innerHTML = '<div class="rhead"><div class="rleft"><div class="rlogo '+logo+'" aria-hidden="true">'+(logo==='nzpost'?'NZP':(logo==='nzc'?'NZC':'CR'))+'</div>'+
                      '<div class="rtitle">'+U.escapeHtml(rate.carrier_name)+' · '+U.escapeHtml(rate.service_name)+' '+badges.join(' ')+'</div></div>'+
                      '<div class="rprice">'+priceText+'</div></div><div class="rmeta">'+meta.join(' ')+'</div>';
      div.addEventListener('click', function(){ setSelection(rate,'user'); renderRates(); });
      wrap.appendChild(div);
    });
    renderSummary();
  }

  function renderSummary(){
    var s=S.selection, carrierText=s?(s.carrierName||s.carrier||'—'):'—', serviceText=s?(s.serviceName||s.service||'—'):'—';
    var totalVal=U.toNumber(s && (s.total || s.total_incl_gst) || 0,0);
    var sc=U.$('#sumCarrier'), ss=U.$('#sumService'), st=U.$('#sumTotal');
    if (sc) sc.textContent=carrierText||'—'; if (ss) ss.textContent=serviceText||'—'; if (st) st.textContent=s?U.fmt$(totalVal):'$0.00';
    // summary totals from packages/metrics
    var weight = S.metrics && Number.isFinite(S.metrics.weight) ? S.metrics.weight :
                (S.packages||[]).reduce(function(a,p){ return a + (Number(p.kg||0)||0); },0);
    var sw = U.$('#sw-summary-weight'); if (sw) sw.textContent = (Number.isFinite(weight)?weight:0).toFixed(3)+'kg';
    var count = (S.metrics && S.metrics.count) || (S.packages||[]).length;
    var sp = U.$('#sw-summary-packages'); if (sp) sp.textContent = count? (count+'pkg'+(count===1?'':'s')) : '0 pkgs';
  }

  // Expose
  window.Dispatch.rates = {
    scheduleRatesRefresh: scheduleRatesRefresh,
    renderRates: renderRates,
    renderSummary: renderSummary
  };
})();
