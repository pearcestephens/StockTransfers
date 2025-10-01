(function (window, $) {
  'use strict';
  if (!$) { console.error('[Transfers/Utils] jQuery is required.'); return; }

  // Namespace
  var TransfersUtils = window.TransfersUtils = (window.TransfersUtils || {});

  // --- Formatters ---
  TransfersUtils.formatNumber = function (num, decimals) {
    var n = Number(num) || 0;
    var d = Number.isInteger(decimals) ? decimals : 0;
    return n.toLocaleString('en-NZ', { minimumFractionDigits: d, maximumFractionDigits: d });
  };

  TransfersUtils.formatCurrency = function (amount, currency) {
    var n = Number(amount) || 0;
    var c = currency || 'NZD';
    return new Intl.NumberFormat('en-NZ', { style: 'currency', currency: c }).format(n);
  };

  TransfersUtils.formatDate = function (date, options) {
    var defaults = { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' };
    var fmt = Object.assign({}, defaults, options || {});
    var d = (date instanceof Date) ? date : new Date(date);
    return isNaN(d.getTime()) ? 'Invalid date' : d.toLocaleString('en-NZ', fmt);
  };

  // --- Helpers ---
  TransfersUtils.debounce = function (fn, wait) {
    var t; return function () { var ctx = this, args = arguments;
      clearTimeout(t); t = setTimeout(function () { fn.apply(ctx, args); }, wait);
    };
  };

  TransfersUtils.copyToClipboard = function (text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text);
    }
    return new Promise(function (resolve, reject) {
      try {
        var ta = document.createElement('textarea');
        ta.value = String(text || '');
        ta.style.position = 'fixed'; ta.style.left = '-9999px'; ta.style.top = '-9999px';
        document.body.appendChild(ta); ta.focus(); ta.select();
        var ok = document.execCommand('copy'); ta.remove(); ok ? resolve() : reject(new Error('Copy failed'));
      } catch (e) { reject(e); }
    });
  };

  TransfersUtils.getToastIcon = function (type) {
    return ({ success: 'check-circle', warning: 'exclamation-triangle', error: 'times-circle', info: 'info-circle' }[type] || 'info-circle');
  };

  // --- Toast (bridges to PackToast or CIS.ui.toast) ---
  TransfersUtils.showToast = function (message, type, duration) {
    type = type || 'info';
    if (window.PackToast && typeof window.PackToast.show === 'function') {
      return window.PackToast.show(String(message), type, { timeout: duration || 4000 });
    }
    if (window.CIS && window.CIS.ui && typeof window.CIS.ui.toast === 'function') {
      return window.CIS.ui.toast(message, type);
    }
    try { alert(String(message)); } catch (_) {}
  };

  // --- AJAX error -> toast ---
  TransfersUtils.handleAjaxError = function (xhr, context) {
    var msg = 'An unexpected error occurred';
    if (xhr && xhr.responseJSON && xhr.responseJSON.error) {
      msg = xhr.responseJSON.error.message || xhr.responseJSON.error || msg;
    } else if (xhr && xhr.responseText) {
      try { var p = JSON.parse(xhr.responseText); msg = (p && (p.error || p.message)) || msg; } catch (_) { msg = xhr.statusText || msg; }
    }
    if (context) msg = context + ': ' + msg;
    TransfersUtils.showToast(msg, 'error');
    console.error('[AJAX ERROR]', { xhr: xhr, context: context, message: msg });
  };

  // --- Simple form validator (required + email) ---
  TransfersUtils.validateForm = function ($form) {
    var errors = [];
    $form.find('[required]').each(function () {
      var $f = $(this), v = String(($f.val() || '')).trim();
      var label = $f.attr('aria-label') || $f.attr('name') || 'Field';
      if (!v) { errors.push(label + ' is required'); $f.addClass('is-invalid'); } else { $f.removeClass('is-invalid'); }
    });
    $form.find('[type="email"]').each(function () {
      var $f = $(this), v = String(($f.val() || '')).trim();
      if (v && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) { errors.push('Please enter a valid email address'); $f.addClass('is-invalid'); }
    });
    return { isValid: errors.length === 0, errors: errors };
  };

  // Global AJAX error -> toast
  $(document).ajaxError(function (_e, xhr, settings) {
    if (xhr && xhr.status !== 200) TransfersUtils.handleAjaxError(xhr, 'Request failed ' + (settings && settings.url ? '(' + settings.url + ')' : ''));
  });

  console.log('[Transfers/Utils] Ready');
})(window, window.jQuery);
