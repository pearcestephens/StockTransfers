(function () {
  'use strict';
  var MAX = 5, AUTO_MS = 4200, containerId = 'packToastContainer';

  function ensure() {
    var c = document.getElementById(containerId);
    if (!c) {
      c = document.createElement('div');
      c.id = containerId;
      c.setAttribute('role', 'region');
      c.setAttribute('aria-live', 'polite');
      c.style.position = 'fixed';
      c.style.bottom = '12px';
      c.style.right = '12px';
      c.style.zIndex = '2500';
      c.style.display = 'flex';
      c.style.flexDirection = 'column';
      c.style.gap = '8px';
      document.body.appendChild(c);
    }
    return c;
  }
  function cls(t) {
    return t === 'success' ? 'pack-toast-success' :
           t === 'error'   ? 'pack-toast-error'   :
           t === 'warn'    ? 'pack-toast-warn'    : 'pack-toast-info';
  }
  function dismiss(el) { if (!el) return; el.classList.add('pack-toast-hide'); setTimeout(function () { el.remove(); }, 160); }

  var recent = [];
  function show(message, type, opts) {
    type = type || 'info'; opts = opts || {};
    // dedupe bursts
    var now = Date.now();
    for (var i = recent.length - 1; i >= 0; i--) if (now - recent[i].t > 3000) recent.splice(i, 1);
    if (!opts.force && recent.some(function (r) { return r.msg === message && r.type === type; })) return null;
    recent.push({ msg: message, type: type, t: now });

    var c = ensure(); while (c.children.length >= MAX) c.firstChild.remove();
    var div = document.createElement('div');
    div.className = 'pack-toast ' + cls(type);
    var action = (opts.action && opts.action.label) ? '<button class="pack-toast-act" type="button">' + opts.action.label + '</button>' : '';
    div.innerHTML = '<span class="pack-toast-msg"></span>' + action + '<button class="pack-toast-close" aria-label="Dismiss">&times;</button>';
    div.querySelector('.pack-toast-msg').textContent = String(message || '');
    if (action) {
      div.querySelector('.pack-toast-act').addEventListener('click', function () {
        try { opts.action.onClick && opts.action.onClick(); } catch (e) {}
        dismiss(div);
      });
    }
    div.querySelector('.pack-toast-close').addEventListener('click', function () { dismiss(div); });
    c.appendChild(div);
    if (opts.sticky) return div;

    var ms = typeof opts.timeout === 'number' ? opts.timeout : AUTO_MS;
    var to = setTimeout(function () { dismiss(div); }, ms);
    div.addEventListener('mouseenter', function () { clearTimeout(to); }, { once: true });
    return div;
  }

  function injectCss() {
    if (document.getElementById('packToastCSS')) return;
    var s = document.createElement('style');
    s.id = 'packToastCSS';
    s.textContent = [
      '.pack-toast{font:13px/1.4 system-ui,Segoe UI,Roboto,Arial;background:#1e2630;color:#fff;padding:10px 14px;border-radius:8px;',
      'box-shadow:0 4px 14px -2px rgba(0,0,0,.4);display:flex;align-items:center;gap:10px;min-width:240px;max-width:340px;}',
      '.pack-toast-info{background:linear-gradient(135deg,#2d3748,#1a202c);} .pack-toast-success{background:linear-gradient(135deg,#1b7f4d,#15965d);} ',
      '.pack-toast-error{background:linear-gradient(135deg,#b02733,#7d1627);} .pack-toast-warn{background:linear-gradient(135deg,#b07207,#865603);} ',
      '.pack-toast-hide{opacity:0;transform:translateY(-6px);transition:all .16s ease;}',
      '.pack-toast-close{background:none;border:none;color:#fff;opacity:.65;font-size:16px;line-height:1;margin-left:auto;cursor:pointer;}',
      '.pack-toast-close:hover{opacity:1;}.pack-toast-act{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);',
      'color:#fff;font-size:11px;padding:3px 6px;border-radius:4px;cursor:pointer;}.pack-toast-act:hover{background:rgba(255,255,255,.25);}'
    ].join('');
    document.head.appendChild(s);
  }
  function wireEsc() { document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { var c = document.getElementById(containerId); if (c && c.lastChild) dismiss(c.lastChild); } }); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', injectCss);
  else injectCss();
  wireEsc();

  window.PackToast = {
    info:    function (m, o) { return show(m, 'info',    o); },
    success: function (m, o) { return show(m, 'success', o); },
    error:   function (m, o) { return show(m, 'error',   o); },
    warn:    function (m, o) { return show(m, 'warn',    o); },
    show: show,
    dismissAll: function () {
      var c = document.getElementById(containerId); if (!c) return;
      Array.prototype.slice.call(c.children).forEach(dismiss);
    }
  };

  // tell pack-core we're live
  try { window.PackBus && window.PackBus.emit('toast:ready', window.PackToast); } catch (_) {}
})();
