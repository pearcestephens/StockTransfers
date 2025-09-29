/* ==========================================================================
   CIS Transfers — Common JS Utilities
   Requires: jQuery (>=3.x)
   ========================================================================== */
(function (window, $) {
  'use strict';

  if (!$) {
    console.error('[CIS/Common] jQuery is required.');
    return;
  }

  // --- Ajax defaults --------------------------------------------------------
  $.ajaxSetup({
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    cache: false,
    timeout: 30000
  });

  

  // --- Namespaces -----------------------------------------------------------
  var CIS = window.CIS || (window.CIS = {});
  CIS.util = CIS.util || {};
  CIS.http = CIS.http || {};
  CIS.ui   = CIS.ui   || {};

  // --- Utils ----------------------------------------------------------------
  CIS.util.debounce = function (fn, wait) {
    var t;
    return function () {
      var ctx = this, args = arguments;
      clearTimeout(t);
      t = setTimeout(function () { fn.apply(ctx, args); }, wait);
    };
  };

  CIS.util.safeParse = function (str, fallback) {
    try { return JSON.parse(str); } catch (e) { return fallback; }
  };

  CIS.util.safeStringify = function (obj) {
    try { return JSON.stringify(obj); } catch (e) { return ''; }
  };

  CIS.util.exists = function (selector) {
    return $(selector).length > 0;
  };

  // --- HTTP helpers ---------------------------------------------------------
  CIS.http.postJSON = function (url, payload) {
    return $.ajax({
      url: url || window.location.pathname + window.location.search,
      type: 'POST',
      dataType: 'json',
      contentType: 'application/json; charset=utf-8',
      data: JSON.stringify(payload || {})
    });
  };

  // Attach CSRF header for every AJAX call using the XSRF-TOKEN cookie
(function (){
  function getCookie(name) {
    var m = document.cookie.match('(^|;)\\s*' + name.replace(/[-[\]{}()*+?.,\\^$|#\s]/g, '\\$&') + '\\s*=\\s*([^;]+)');
    return m ? decodeURIComponent(m.pop()) : '';
  }
  var token = getCookie('XSRF-TOKEN');
  if (token) {
    var hdrs = $.ajaxSettings.headers || {};
    hdrs['X-CSRF-Token'] = token;
    $.ajaxSetup({ headers: hdrs });
  }
})();


  // --- UI helpers -----------------------------------------------------------
  CIS.ui.toast = function (message, type) {
    try {
      var cls = 'bg-info';
      if (type === 'success') cls = 'bg-success';
      else if (type === 'warning') cls = 'bg-warning';
      else if (type === 'error') cls = 'bg-danger';

      if ($('#toast-container').length === 0) {
        $('body').append('<div id="toast-container" style="position:fixed;top:20px;right:20px;z-index:9999;" aria-live="polite" aria-atomic="true"></div>');
      }
      var id = 'toast-' + Date.now();
      var html = [
        '<div id="', id, '" class="toast align-items-center text-white ', cls, ' border-0 p-2 px-3"',
        ' role="alert" style="margin-bottom:10px;display:none;min-width:260px;">',
        '<div class="d-flex"><div class="toast-body">', $('<div>').text(String(message || '')).html(), '</div></div>',
        '</div>'
      ].join('');
      $('#toast-container').append(html);
      var $t = $('#' + id);
      $t.fadeIn(120);
      setTimeout(function () { $t.fadeOut(180, function () { $t.remove(); }); }, 3600);
    } catch (e) {
      console.warn('[CIS/Common] toast fallback:', e);
      alert(message);
    }
  };

  // Expose version for quick debugging
  CIS.__commonVersion = '1.0.0';

})(window, window.jQuery);

(function(){
  const root = document.getElementById('pack-bottom'); if(!root) return;
  const TID = parseInt(root.getAttribute('data-transfer')||'0',10);

  const $ = (s,r=root)=>r.querySelector(s);
  const el = {
    count: $('#pb-label-count'),
    steps: root.querySelectorAll('.pb-step'),
    btnPreview: $('#pb-preview'),
    btnPrint: $('#pb-print'),
    btnReady: $('#pb-ready'),
    items: $('#pb-items'), kg: $('#pb-kg'), boxes: $('#pb-boxes'),
    bestSvc: $('#pb-best-service'), bestPrice: $('#pb-best-price'), rateSrc: $('#pb-rate-src'),
    traffic: $('#pb-traffic'), status: $('#pb-status-msg')
  };

  function setTraffic(state){
    el.traffic.classList.remove('is-green','is-amber','is-red');
    el.traffic.classList.add(state==='green'?'is-green':state==='amber'?'is-amber':'is-red');
  }
  function getCsrf(){
    if(window.CIS_CSRF) return window.CIS_CSRF;
    const m=document.cookie.match(/(?:^|;)\s*XSRF-TOKEN=([^;]+)/); return m?decodeURIComponent(m[1]):'';
  }
  async function getJSON(url){
    const r=await fetch(url,{credentials:'same-origin',headers:{'Accept':'application/json','X-CSRF':getCsrf()}});
    if(!r.ok) throw new Error(`${url} → ${r.status}`); return r.json();
  }
  async function postJSON(url,body){
    const r=await fetch(url,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF':getCsrf()},body:JSON.stringify(body)});
    const j=await r.json().catch(()=>({})); if(!r.ok||j.success===false) throw new Error(`${url} → ${r.status}: ${j.error||'error'}`); return j;
  }
  function estimateBoxes(){
    const rows=document.querySelectorAll('.box-row'); return rows.length||parseInt(el.count.value||'1',10)||1;
  }

  async function hydrate(){
    if(!TID){ setTraffic('amber'); el.status.textContent='Missing transfer id.'; return; }
    try{
      const d = await getJSON(`/modules/transfers/stock/api/_diag.php?transfer=${TID}`);
      el.items.textContent = d?.data?.items_count ?? 0;
      el.kg.textContent    = (d?.data?.computed_total_kg ?? 0).toFixed(3);
      el.boxes.textContent = estimateBoxes();
      const star=!!d?.creds?.starshipit?.present, gss=!!d?.creds?.gss?.present, itemsOk=(d?.data?.has_items===true);
      setTraffic(star||gss ? (itemsOk ? 'green':'amber') : 'red');
      if(!itemsOk) el.status.textContent = 'No items yet — add lines to this transfer.';
    }catch(e){
      setTraffic('red'); el.status.textContent='Status failed to load.';
    }

    try{
      const pkgs=[{l_cm:40,w_cm:30,h_cm:20,weight_kg:2.0,qty:estimateBoxes(),ref:`T${TID}`}];
      const rates = await postJSON('/modules/transfers/stock/api/rates.php', {transfer_id:TID, carrier:'gss', packages:pkgs});
      const qs = rates.quotes||rates.results||[];
      if(qs.length){
        let best = qs[0];
        for(const q of qs){ if((+q.total_price||+q.price||9e9) < (+best.total_price||+best.price||9e9)) best=q; }
        el.bestSvc.textContent = best.service_name||best.name||best.service_code||best.code||'Service';
        el.bestPrice.textContent = ' $'+(+best.total_price||+best.price||0).toFixed(2);
        el.rateSrc.textContent = rates.source?`(${rates.source})`:'(live)';
      }else{
        el.bestSvc.textContent='Rules only'; el.bestPrice.textContent=''; el.rateSrc.textContent='(fallback)';
      }
    }catch(e){ el.rateSrc.textContent='(no rates)'; }
  }

  // events
  el.steps.forEach(b=>b.addEventListener('click',()=>{
    const d = b.getAttribute('data-delta')==='-1' ? -1 : 1;
    const v = Math.max(1,(parseInt(el.count.value||'1',10)+d)); el.count.value=v; el.boxes.textContent=v;
  }));
  el.btnPreview.addEventListener('click', ()=>{
    const n = Math.max(1, parseInt(el.count.value||'1',10));
    const url = `/modules/transfers/stock/print/box_slip.php?transfer=${TID}&preview=1&n=${n}`;
    window.open(url,'_blank');
  });
  el.btnPrint.addEventListener('click', ()=>{
    // click your existing page button if present, else open preview
    const btn = Array.from(document.querySelectorAll('button,a')).find(b=>/Print labels/i.test(b.textContent||''));
    if(btn) btn.click(); else el.btnPreview.click();
  });
  el.btnReady.addEventListener('click', ()=>{
    const btn = document.querySelector('[data-action="ready-delivery"]')
            || Array.from(document.querySelectorAll('button,a')).find(b=>/Mark as Ready for Delivery/i.test(b.textContent||''));
    btn?.click();
  });

  hydrate();
})();


(function(){
  function text(sel){ var n=document.querySelector(sel); return n ? n.textContent.trim() : ""; }
  function value(sel){ var n=document.querySelector(sel); return n ? (n.value||n.textContent||"").trim() : ""; }

  // Transfer ID
  var tid = value("#transferID") || text("#sw-tid") || ((text(".card-title")||"").match(/\d+/)||[])[0] || "";

  // Per your spec: force the printed route label to say "Hamilton to Auckland"
  var ROUTE_LABEL = "Hamilton to Auckland";

  // NZ date
  var nzDate = new Date().toLocaleDateString("en-NZ", {year:"numeric", month:"short", day:"2-digit"});

  // Removed automatic injection of #print-header/#print-footer (was conflicting with real page footer).
  // If print chrome is required later, reintroduce with namespaced IDs (e.g., packPrintHeader) and isolated CSS.

  // Watermark helps reunite separated pages
  document.body.setAttribute("data-doc-watermark", (tid?("TFR-"+tid+" • "):"") + ROUTE_LABEL);

  // Ensure counted values print even if inputs not filled into span yet
  function syncCounted(){
    document.querySelectorAll("#transfer-table tbody tr").forEach(function(tr){
      var input = tr.querySelector(".counted-td input.tfx-num");
      var span  = tr.querySelector(".counted-td .counted-print-value");
      if (input && span) {
        var v = (input.value||"").trim();
        if (v!=="") span.textContent = v;
      }
    });
  }

  window.addEventListener("beforeprint", function(){
    syncCounted();
    document.body.classList.add("printing");
  });
  window.addEventListener("afterprint", function(){
    document.body.classList.remove("printing");
  });
})();

