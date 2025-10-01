(function () {
  'use strict';

  // Tiny event bus
  var subs = {};
  function on(evt, fn) { (subs[evt] = subs[evt] || []).push(fn); }
  function off(evt, fn) { if (!subs[evt]) return; subs[evt] = subs[evt].filter(function (h) { return h !== fn; }); }
  function emit(evt, payload) { (subs[evt] || []).forEach(function (h) { try { h(payload); } catch (e) { console.error('[PackBus] handler error', e); } }); }
  window.PackBus = { on: on, off: off, emit: emit };

  // Debounce helper
  window.packDebounce = function (fn, wait) {
    var t; return function () { var ctx = this, args = arguments; clearTimeout(t); t = setTimeout(function () { fn.apply(ctx, args); }, wait); };
  };

  // Toast bootstrap (if pack-toast loads later)
  if (!window.PackToast) {
    window.PackToast = {
      __q: [],
      show: function (m, type, o) { this.__q.push(['show', m, type, o]); console.log('[toast/pending]', type || 'info', m); }
    };
  }
  on('toast:ready', function (api) {
    if (!window.PackToast.__q) return;
    var q = window.PackToast.__q.splice(0);
    q.forEach(function (it) { api[it[0]].call(api, it[1], it[2], it[3]); });
  });

  // Lazy load product-modal only when needed
  document.addEventListener('DOMContentLoaded', function () {
    var btn = document.getElementById('addProductOpen');
    if (!btn) return;
    function ensureModal(cb) {
      if (window.openAddProductModal) return cb && cb();
      if (document.querySelector('script[data-prod-modal-loaded]')) return cb && cb();
      var s = document.createElement('script');
      s.src = '/modules/transfers/stock/assets/js/pack-product-modal.js?v=' + encodeURIComponent(window.TRANSFER_ASSET_VER || '');
      s.defer = true; s.dataset.prodModalLoaded = '1'; s.onload = function () { cb && cb(); };
      document.head.appendChild(s);
    }
    btn.addEventListener('click', function () {
      ensureModal(function () { window.openAddProductModal && window.openAddProductModal(); });
    });
  });
})();
