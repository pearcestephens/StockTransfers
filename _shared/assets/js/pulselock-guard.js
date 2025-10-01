(function () {
  'use strict';
  if (window.CISGuard) return;

  var overlay;
  function ensureOverlay() {
    if (overlay) return overlay;
    overlay = document.createElement('div');
    overlay.id = 'pl-global-lock';
    overlay.setAttribute('aria-live', 'assertive');
    overlay.style.display = 'none';
    overlay.innerHTML = [
      '<div class="pl-lock-panel">',
      '<div class="pl-chip" id="pl-lock-chip">PULSELOCK:INCIDENT</div>',
      '<h2 class="h5 mb-2">Automations paused</h2>',
      '<p id="pl-lock-msg" class="mb-0 small">We\'ve put the system in safe mode while we recover. You can browse, but changes are disabled.</p>',
      '</div>'
    ].join('');
    document.addEventListener('DOMContentLoaded', function () {
      document.body.appendChild(overlay);
    });
    return overlay;
  }

  var active = false, level = null, mo = null;
  function disableInteractive(root) {
    if (!root || root.nodeType !== 1) return;
    var sel = 'button,[type=button],[type=submit],[type=reset],a[href],input,select,textarea';
    root.querySelectorAll(sel).forEach(function (el) {
      if (el.closest('#pl-global-lock')) return;
      el.setAttribute('data-lock-prev-disabled', el.disabled ? '1' : '0');
      el.disabled = true;
      el.setAttribute('aria-disabled', 'true');
      el.classList.add('pl-locked');
    });
  }
  function intercept(e) {
    if (e.target && e.target.closest && e.target.closest('#pl-global-lock')) return;
    e.preventDefault(); e.stopPropagation();
  }
  function activate(newLevel, msg) {
    var ov = ensureOverlay();
    if (active && level === newLevel) return;
    active = true; level = newLevel;
    document.documentElement.classList.remove('cis-lock-red', 'cis-lock-amber');
    document.documentElement.classList.add('cis-lock-' + newLevel);
    ov.style.display = 'grid';
    if (msg) {
      var m = document.getElementById('pl-lock-msg'); if (m) m.textContent = msg;
    }
    disableInteractive(document);
    if (mo) mo.disconnect();
    mo = new MutationObserver(function (rs) {
      rs.forEach(function (r) { r.addedNodes.forEach(disableInteractive); });
    });
    mo.observe(document.documentElement, { childList: true, subtree: true });
    window.addEventListener('submit', intercept, true);
    window.addEventListener('click', intercept, true);
  }
  function downgradeAmber() {
    document.documentElement.classList.add('cis-lock-amber');
  }
  function deactivate() {
    active = false; level = null;
    ensureOverlay().style.display = 'none';
    document.documentElement.classList.remove('cis-lock-red', 'cis-lock-amber');
    if (mo) { mo.disconnect(); mo = null; }
    window.removeEventListener('submit', intercept, true);
    window.removeEventListener('click', intercept, true);
  }

  var Guard = {
    red: function (msg) { activate('red', msg || 'PulseLock incident – writes disabled'); },
    amber: function () { downgradeAmber(); },
    green: function () { deactivate(); },
    fatal: function (reason, meta) {
      try { console.error('[CIS][PIPELINE][FATAL]', reason, meta || {}); } catch (_) {}
      activate('red', reason || 'Pipeline failure');
    }
  };
  window.CISGuard = Guard;

  // Poll PulseLock (30s)
  async function refreshPulseLock() {
    try {
      var r = await fetch('/modules/transfers/platform/pulselock/api/status.json.php', { cache: 'no-store' });
      var j = await r.json();
      var status = String(j.status || 'green').toLowerCase();
      if (status === 'red') Guard.red('PulseLock incident – writes disabled');
      else if (status === 'amber') Guard.amber();
      else Guard.green();
    } catch (_e) { /* ignore */ }
  }
  document.addEventListener('DOMContentLoaded', function () {
    refreshPulseLock();
    setInterval(refreshPulseLock, 30000);
  });
})();
