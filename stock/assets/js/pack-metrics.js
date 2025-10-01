(function () {
  'use strict';

  function qs(sel, ctx) { return (ctx || document).querySelector(sel); }
  function qsa(sel, ctx) { return Array.from((ctx || document).querySelectorAll(sel)); }
  function int(v) { var n = parseInt(v || '0', 10); return Number.isFinite(n) ? n : 0; }

  function applyRowDiffs() {
    qsa('#transferItemsTable tr[data-item-id]').forEach(function (row) {
      var inp = row.querySelector('input.qty-input'); if (!inp) return;
      var planned = int(inp.getAttribute('data-planned'));
      var counted = int(inp.value);
      row.classList.remove('qty-match', 'qty-mismatch', 'qty-neutral');
      var hasValue = String(inp.value || '').trim() !== '';
      var touched = hasValue || inp.dataset.touched === '1' || inp.hasAttribute('data-touched');
      if (planned === 0 && counted === 0) { row.classList.add('qty-neutral'); return; }
      if (!touched && planned > 0 && counted === 0) { row.classList.add('qty-neutral'); return; }
      if (counted === planned) { row.classList.add(counted !== 0 ? 'qty-match' : 'qty-neutral'); return; }
      row.classList.add('qty-mismatch');
    });
  }

  function updateTotals() {
    var plannedTotal = 0, countedTotal = 0;

    qsa('#transferItemsTable input.qty-input').forEach(function (inp) {
      plannedTotal += int(inp.getAttribute('data-planned'));
      countedTotal += int(inp.value);
    });
    var diff = countedTotal - plannedTotal;

    function set(id, val) { var el = qs('#' + id); if (el) el.textContent = val; }
    set('plannedTotal', plannedTotal);
    set('countedTotal', countedTotal);
    set('diffTotal', (diff >= 0 ? '+' : '') + diff);
    set('plannedTotalFooter', plannedTotal);
    set('countedTotalFooter', countedTotal);
    set('diffTotalFooter', (diff >= 0 ? '+' : '') + diff);

    applyRowDiffs();

    try { window.PackBus && window.PackBus.emit('counts:updated', { planned: plannedTotal, counted: countedTotal, diff: diff }); } catch (_) {}
  }

  // Autosave pill bridge (uses custom events from pack-autosave.js)
  function initAutosavePill() {
    var pill = qs('#autosavePill'); if (!pill) return;
    var pillText = qs('#autosavePillText');
    var lastSavedEl = qs('#autosaveLastSaved');

    function setState(st, label) {
      pill.classList.remove('status-idle', 'status-dirty', 'status-saving', 'status-saved', 'status-error');
      pill.classList.add('status-' + st);
      if (pillText) pillText.textContent = label;
    }
    function last(ts) {
      if (!lastSavedEl) return;
      try {
        var d = ts ? new Date(ts) : new Date();
        lastSavedEl.textContent = 'Last saved: ' + d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit', second: '2-digit' });
      } catch (_) {}
    }

    qsa('#transferItemsTable input.qty-input').forEach(function (inp) {
      ['input', 'change'].forEach(function (ev) {
        inp.addEventListener(ev, function () {
          // show “dirty/pending” when user types
          if (!pill.classList.contains('status-saving')) setState('dirty', 'Pending');
        });
      });
    });

    document.addEventListener('packautosave:state', function (ev) {
      var st = ev.detail && ev.detail.state;
      var p = ev.detail && ev.detail.payload;
      if (st === 'saving') setState('saving', 'Saving…');
      else if (st === 'saved') { setState('saved', 'Saved'); last(p && p.saved_at); }
      else if (st === 'error') setState('dirty', 'Retry');
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    // Headers: e.g., “DEL” -> empty for icon space
    qsa('#transferItemsTable thead th').forEach(function (th) {
      if (th.textContent && th.textContent.trim().toUpperCase() === 'DEL') {
        th.textContent = ''; th.classList.add('col-del'); th.setAttribute('aria-label', 'Delete');
      }
    });

    updateTotals();
    [120, 400, 900].forEach(function (t) { setTimeout(function () { updateTotals(); }, t); });

    // Observe table body for dynamic row insertions
    var tbody = qs('#transferItemsTable tbody');
    if (tbody && !window.__packTableObserved) {
      window.__packTableObserved = true;
      try {
        var mo = new MutationObserver(function (muts) {
          for (var i = 0; i < muts.length; i++) {
            if (muts[i].addedNodes && muts[i].addedNodes.length) { updateTotals(); break; }
          }
        });
        mo.observe(tbody, { childList: true });
      } catch (_) {}
    }

    initAutosavePill();
  });

  // public hook
  window.refreshPackMetrics = updateTotals;
})();
