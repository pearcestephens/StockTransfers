// pack-core.js: shared utilities + sheen + print guard + namespace exposure
(function(){
  'use strict';

  // Basic event bus (publish/subscribe) for cross-module communication
  const subscribers = {};
  function on(evt, handler){ (subscribers[evt] = subscribers[evt] || []).push(handler); }
  function off(evt, handler){ if(!subscribers[evt]) return; subscribers[evt] = subscribers[evt].filter(h=>h!==handler); }
  function emit(evt, payload){ (subscribers[evt]||[]).forEach(h=>{ try { h(payload); } catch(e){ console.error('[pack-bus] handler error', e); } }); }
  window.PackBus = { on, off, emit };
  // Dev debug: enable by setting window.PACK_BUS_DEBUG = true before modules load
  if (window.PACK_BUS_DEBUG) {
    on('*', function(payload){ /* reserved */ }); // placeholder if someone emits '*'
    const origEmit = emit;
    window.PackBus.emit = function(ev,p){ if(window.PACK_BUS_DEBUG && ev!=='counts:updated'){ console.debug('[PackBus]', ev, p); } origEmit(ev,p); };
  }

  // PRINT PLACEHOLDER DISABLED:
  // Previous implementation injected #print-header and #print-footer divs before print.
  // These IDs conflicted with existing layout/footer CSS and disrupted footer positioning.
  // Disabled per request. If reinstating later, use namespaced IDs (e.g., pack-print-header) to avoid clashes.
  // function ensurePrintChromeOnce(){ /* intentionally disabled */ }
  // window.addEventListener('beforeprint', ensurePrintChromeOnce);

  // Debounce utility (export globally for sibling modules)
  window.packDebounce = function(fn, delay){ let t; return function(...a){ clearTimeout(t); t=setTimeout(()=>fn.apply(this,a), delay); }; };

  // Expose convenience toast shim (actual implementation in pack-toast.js, but allow calls before it loads)
  if(!window.PackToast){
    window.PackToast = {
      queue: [],
      show: function(msg, type='info', opts={}){ this.queue.push({msg,type,opts}); console.log('[toast:pending]', type, msg); },
      _drain: function(real){ if(!real) return; this.queue.splice(0).forEach(t=> real.show(t.msg,t.type,t.opts)); }
    };
  }
  // When real toast module declares ready, drain pre-load queue
  on('toast:ready', real => { if(window.PackToast && window.PackToast._drain) window.PackToast._drain(real); });

  function initSheen(){
    const header = document.querySelector('.pack-header-join .card-header');
    if(!header) return; if(header.classList.contains('sheen-ready')) return;
    header.classList.add('sheen-ready');
    setTimeout(()=> header.classList.add('sheen-run'), 600);
  }
  document.addEventListener('DOMContentLoaded', initSheen);

  // Fallback for Options dropdown (Add Product) if Bootstrap's dropdown JS not present
  function initOptionsDropdownFallback(){
    const hasBootstrapDropdown = !!(window.jQuery && jQuery.fn && typeof jQuery.fn.dropdown === 'function');
    if(hasBootstrapDropdown) return; // native bootstrap will handle
    const trigger = document.getElementById('addProductMenuBtn');
    const wrapper = document.getElementById('addProductDropdown');
    if(!trigger || !wrapper) return;
    const menu = wrapper.querySelector('.dropdown-menu');
    if(!menu) return;
    function close(){ wrapper.classList.remove('show'); menu.classList.remove('show'); trigger.setAttribute('aria-expanded','false'); }
    function open(){ wrapper.classList.add('show'); menu.classList.add('show'); trigger.setAttribute('aria-expanded','true'); }
    function toggle(e){ e.preventDefault(); e.stopPropagation(); const openNow = menu.classList.contains('show'); openNow? close(): open(); }
    trigger.addEventListener('click', toggle);
    document.addEventListener('click', (e)=>{ if(!wrapper.contains(e.target)) close(); });
    document.addEventListener('keydown', (e)=>{ if(e.key==='Escape'){ close(); trigger.focus(); } });
  }
  document.addEventListener('DOMContentLoaded', initOptionsDropdownFallback);

  // Lazy loader for product modal script (pack-product-modal.js) to defer network until user intent
  document.addEventListener('DOMContentLoaded', function(){
    var triggerBtn = document.getElementById('addProductOpen');
    if(!triggerBtn) return;
    function ensureModalScript(cb){
      if(window.openAddProductModal){ cb&&cb(); return; }
      if(document.querySelector('script[data-prod-modal-loaded]')){ cb&&cb(); return; }
      var s=document.createElement('script');
      s.src='/modules/transfers/stock/assets/js/pack-product-modal.js?v=' + encodeURIComponent((window.TRANSFER_ASSET_VER||''));
      s.defer=true; s.dataset.prodModalLoaded='1';
      s.onload=function(){ cb&&cb(); };
      document.head.appendChild(s);
    }
    triggerBtn.addEventListener('click', function(){ ensureModalScript(function(){ window.openAddProductModal && window.openAddProductModal(); }); }, { once:false });
  });
})();
