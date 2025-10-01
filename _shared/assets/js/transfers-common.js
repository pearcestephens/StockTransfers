(function (window, $) {
  'use strict';
  if (!$) {
    console.error('[Transfers/Common] jQuery is required.');
    return;
  }

  // --- CSRF helpers (cookie -> meta -> empty) ---
  function getCookie(name) {
    var m = document.cookie.match('(?:^|; )' + name.replace(/[-[\]{}()*+?.,\\^$|#\s]/g, '\\$&') + '=([^;]*)');
    return m ? decodeURIComponent(m[1]) : '';
  }
  function getCsrfToken() {
    return (
      getCookie('XSRF-TOKEN') ||
      (document.querySelector('meta[name="csrf-token"]') || {}).content ||
      ''
    );
  }

  // --- Global AJAX defaults ---
  $.ajaxSetup({
    headers: {
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-Token': getCsrfToken()
    },
    cache: false,
    timeout: 30000
  });

  // --- Namespaces ---
  var CIS = (window.CIS = window.CIS || {});
  CIS.util = CIS.util || {};
  CIS.http = CIS.http || {};
  CIS.ui = CIS.ui || {};

  // --- Utils ---
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
  CIS.util.exists = function (selector) { return $(selector).length > 0; };

  // --- HTTP (JSON) ---
  CIS.http.postJSON = function (url, payload, extraHeaders) {
    return $.ajax({
      url: url || (window.location.pathname + window.location.search),
      type: 'POST',
      dataType: 'json',
      contentType: 'application/json; charset=utf-8',
      headers: extraHeaders || {},
      data: JSON.stringify(payload || {})
    });
  };

  // --- Toast (accessible) ---
  CIS.ui.toast = function (message, type) {
    try {
      var cls = 'bg-info';
      if (type === 'success') cls = 'bg-success';
      else if (type === 'warning') cls = 'bg-warning';
      else if (type === 'error') cls = 'bg-danger';

      if ($('#toast-container').length === 0) {
        $('body').append(
          '<div id="toast-container" style="position:fixed;top:20px;right:20px;z-index:9999;" aria-live="polite" aria-atomic="true"></div>'
        );
      }
      var id = 'toast-' + Date.now();
      var safe = $('<div>').text(String(message || '')).html();
      var html = [
        '<div id="', id, '" class="toast align-items-center text-white ', cls,
        ' border-0 p-2 px-3" role="alert" style="margin-bottom:10px;display:none;min-width:260px;">',
        '<div class="d-flex"><div class="toast-body">', safe, '</div></div></div>'
      ].join('');
      $('#toast-container').append(html);
      var $t = $('#' + id);
      $t.fadeIn(120);
      setTimeout(function () { $t.fadeOut(180, function () { $t.remove(); }); }, 3600);
    } catch (e) {
      console.warn('[Transfers/Common] toast fallback:', e);
      alert(message);
    }
  };

  CIS.__commonVersion = '1.1.0-clean';
})(window, window.jQuery);
