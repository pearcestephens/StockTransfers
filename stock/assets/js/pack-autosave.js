(function () {
  'use strict';
  if (window.PackAutoSaveLoaded) return;
  window.PackAutoSaveLoaded = true;

  var SAVE_ENDPOINT = '/modules/transfers/stock/api/draft_save_api.php';
  var FETCH_TIMEOUT_MS = 20000;
  var MAX_BACKOFF_MS = 15000;

  function now() { return Date.now(); }
  function toInt(v) { var n = parseInt(String(v || '').trim(), 10); return Number.isFinite(n) ? n : 0; }
  function hasText(el) { return !!(el && typeof el.value === 'string' && el.value.trim() !== ''); }
  function setInlineStatus(txt) { var el = document.getElementById('autosaveStatus'); if (el) el.textContent = txt || ''; }
  window.setInlineStatus = setInlineStatus;

  function markRowStatus(input) {
    if (!input) return;
    var counted = toInt(input.value);
    var planned = toInt(input.dataset.planned || input.getAttribute('data-planned'));
    var row = input.closest('tr'); if (!row) return;
    row.classList.remove('qty-match', 'qty-mismatch', 'qty-neutral');
    var hasVal = hasText(input), touched = hasVal || input.dataset.touched === '1';
    if (planned === 0 && counted === 0) { row.classList.add('qty-neutral'); return; }
    if (!touched && planned > 0 && counted === 0) { row.classList.add('qty-neutral'); return; }
    if (counted === planned) { row.classList.add(counted !== 0 ? 'qty-match' : 'qty-neutral'); return; }
    row.classList.add('qty-mismatch');
  }

  function collectDraft(transferId) {
    var data = { transfer_id: transferId, counted_qty: {}, notes: '', timestamp: new Date().toISOString() };
    document.querySelectorAll('.qty-input,input[name^="counted_qty"]').forEach(function (input) {
      var pid = input.dataset.productId;
      if (!pid && input.name && input.name.startsWith('counted_qty[')) pid = input.name.slice(12, -1);
      if (!pid) {
        var row = input.closest('tr'), probe = row && (row.querySelector('.productID') || row.querySelector('[data-product-id]'));
        if (probe) pid = probe.value || probe.dataset.productId || null;
      }
      if (pid && hasText(input)) {
        var val = toInt(input.value); if (val > 0) data.counted_qty[String(pid)] = val;
      }
    });
    var notes = document.querySelector('#notesForTransfer,[name="notes"]');
    if (notes && typeof notes.value === 'string') data.notes = notes.value;
    return data;
  }

  // Legacy constructor style (intentionally not using ES6 class for maximum compatibility)
  function PackAutoSave(transferId) {
    this.transferId = toInt(transferId);
    this.saveDelay = 800;
    this.isDirty = false;
    this.isSaving = false;
    this._hash = '';
    this._err = 0;
    this._timer = null;
    this._abort = null;
    this._idleAt = now();
    this._lastToastedIdle = 0;
    this.bind();
    // initial marking pass
    setTimeout(this.markAll.bind(this), 80);
  }

  PackAutoSave.prototype.bind = function () {
    var self = this;

    function touch() { self._idleAt = now(); }
    document.addEventListener('input', function (e) {
      var t = e.target;
      if (t && (t.matches('.qty-input') || t.matches('input[name^="counted_qty"]'))) {
        if (!t.dataset.touched && hasText(t)) t.dataset.touched = '1';
        markRowStatus(t);
        self.markDirty();
        self.schedule();
      }
      touch();
    }, { passive: true });

    document.addEventListener('focus', function (e) {
      var t = e.target;
      if (t && (t.matches('.qty-input') || t.matches('input[name^="counted_qty"]'))) markRowStatus(t);
      touch();
    }, true);

    document.addEventListener('click', function (e) {
      var t = e.target;
      if (t && (t.matches('#savePack') || t.matches('[data-action="save"]'))) {
        e.preventDefault();
        self.saveNow();
      }
      touch();
    });

    ['keydown', 'mousemove', 'mousedown', 'touchstart', 'scroll', 'visibilitychange'].forEach(function (evt) {
      document.addEventListener(evt, touch, { passive: true });
    });
  };

  PackAutoSave.prototype.markAll = function () {
    document.querySelectorAll('.qty-input,input[name^="counted_qty"]').forEach(markRowStatus);
  };
  PackAutoSave.prototype.markDirty = function () { this.isDirty = true; };
  PackAutoSave.prototype.schedule = function () {
    var self = this;
    clearTimeout(self._timer);
    self._timer = setTimeout(function () { self.saveNow(); }, self.saveDelay);
  };
  PackAutoSave.prototype._shouldToastIdle = function () {
    var idleFor = now() - this._idleAt;
    if (idleFor < 5 * 60 * 1000) return false; // 5 minutes
    if (this._lastToastedIdle === this._idleAt) return false;
    this._lastToastedIdle = this._idleAt;
    return true;
  };

  PackAutoSave.prototype.saveNow = async function () {
    if (this.isSaving) return;
    // Block when no lock (respect global lockStatus like modular system)
    try {
      var ls = window.lockStatus || {};
      if(!ls.has_lock){
        setInlineStatus('Lock');
        document.getElementById('autosavePillText') && (document.getElementById('autosavePillText').textContent='LOCK');
        return; // Do not attempt save without lock
      }
    } catch(_e) {}
    var draft = collectDraft(this.transferId);
    var newHash = JSON.stringify(draft.counted_qty) + '|' + (draft.notes || '');
    if (newHash === this._hash) { this.isDirty = false; try { document.dispatchEvent(new CustomEvent('packautosave:state', { detail: { state: 'noop' } })); } catch (_) {} return; }
    this._hash = newHash;

    this.isSaving = true;
    setInlineStatus('Saving…');
    try {
      if (this._abort) { try { this._abort.abort(); } catch (_) {} }
      this._abort = ('AbortController' in window) ? new AbortController() : null;
      var to = this._abort ? setTimeout(function () { try { this._abort.abort(); } catch (_) {} }.bind(this), FETCH_TIMEOUT_MS) : null;

      var res = await fetch(SAVE_ENDPOINT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(draft),
        signal: this._abort ? this._abort.signal : undefined,
        credentials: 'same-origin'
      });
      var text = await res.text();
      var json = {};
      try { json = text ? JSON.parse(text) : {}; } catch (e) {}

      if (res.status === 423) {
        setInlineStatus('Lock');
        if (window.PackToast) window.PackToast.warning('Locked by another user – cannot save');
        try { document.dispatchEvent(new CustomEvent('packautosave:state', { detail: { state: 'error', payload: { code: 423, message: 'Lock required' } } })); } catch (_) {}
        this.isDirty = true; // keep dirty so it attempts again when lock acquired
        this._err = 0;
        return;
      }
      if (res.ok && json && (json.success === true || json.ok === true)) {
        this.isDirty = false; this._err = 0;
        setInlineStatus('Saved');
        if (window.PackToast && this._shouldToastIdle()) window.PackToast.success('Draft saved', { timeout: 3000, force: false });
        try { document.dispatchEvent(new CustomEvent('packautosave:state', { detail: { state: 'saved', payload: json } })); } catch (_) {}
      } else {
        throw new Error((json && json.error && (json.error.message || json.error)) || 'Draft save failed');
      }
      if (to) clearTimeout(to);
    } catch (err) {
      console.error('[autosave] error:', err);
      this._err = Math.min(this._err + 1, 4);
      var backoff = Math.min(MAX_BACKOFF_MS, Math.pow(2, this._err) * 1000);
      setInlineStatus('Retry…');
      if (window.PackToast) window.PackToast.error('Draft save failed', { action: { label: 'Retry', onClick: this.saveNow.bind(this) }, force: this._err > 1 });
      try { document.dispatchEvent(new CustomEvent('packautosave:state', { detail: { state: 'error', payload: { message: err && err.message } } })); } catch (_) {}
      clearTimeout(this._timer);
      this._timer = setTimeout(this.saveNow.bind(this), backoff);
    } finally {
      this.isSaving = false;
    }
  };

  function boot() {
    var el = document.querySelector('[data-txid]') || document.querySelector('[data-transfer-id]') || document.body;
    var tx = 0;
    if (el) tx = toInt(el.getAttribute('data-txid') || el.getAttribute('data-transfer-id') || '0');
    if (!tx) {
      var p = new URLSearchParams(location.search);
      tx = toInt(p.get('transfer') || p.get('t') || '0') || 0;
    }
    if (tx > 0) {
      window.PackAutoSave = new PackAutoSave(tx);
    } else {
      console.warn('[PackAutoSave] No valid transfer ID; disabled');
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
