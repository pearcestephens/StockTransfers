<script>
/**
 * Pack Transfer Auto-Save System (No Status Pills)
 * - Hardened version (same inputs/outputs & DOM contracts)
 * - JSON POST => /modules/transfers/stock/api/draft_save.php
 * - Payload: { transfer_id, counted_qty:{[productId]:int}, notes, timestamp }
 */

/* global PackToast */

(function () {
  'use strict';

  // Prevent duplicate loading
  if (window.PackAutoSaveLoaded) {
    console.warn('PackAutoSave already loaded, skipping...');
    return;
  }
  window.PackAutoSaveLoaded = true;

  // ---------- Utilities ----------
  const now = () => Date.now();
  const toInt = (v) => {
    const n = parseInt(String(v).trim(), 10);
    return Number.isFinite(n) ? n : 0;
  };
  const hasText = (el) => !!(el && typeof el.value === 'string' && el.value.trim() !== '');

  // Lightweight status helper (kept for compatibility)
  function setInlineStatus(txt) {
    const el = document.getElementById('autosaveStatus');
    if (el) el.textContent = txt;
  }

  class PackAutoSave {
    constructor(transferId, initialDraftData = null) {
      // ---- Config ----
      this.transferId = toInt(transferId);
      this.saveDelay = 1000; // 1s debounce
      this.IDLE_TOAST_THRESHOLD = 5 * 60 * 1000; // 5 minutes
      this.MAX_BACKOFF_MS = 15000; // cap backoff
      this.FETCH_TIMEOUT_MS = 20000; // 20s network timeout

      // ---- State ----
      this.saveTimeout = null;
      this.isDirty = false;
      this._consecErrors = 0;
      this.isSaving = false;
      this._lastHash = '';
      this.lastActivityTs = now();
      this._lastIdleToastActivity = 0;
      this._abortController = null;

      console.log('PackAutoSave initialized for transfer:', this.transferId);
      this.init();
    }

    init() {
      this.bindEvents();
      // Multiple passes to catch late-rendered rows / async injections
      [40, 140, 400, 900].forEach((delay) =>
        setTimeout(() => {
          try { this.updateAllQuantityStatuses(); } catch (e) { /* no-op */ }
        }, delay)
      );
    }

    // ---------- UI helpers ----------
    updateAllQuantityStatuses() {
      const inputs = document.querySelectorAll('.qty-input, input[name^="counted_qty"]');
      inputs.forEach((input) => this.updateQuantityStatus(input));
    }

    updateQuantityStatus(input) {
      if (!input) return;

      // Parse values defensively
      const countedValue = toInt(input.value);
      const plannedValueRaw = input.dataset.planned ?? input.getAttribute('data-planned');
      const plannedValue = toInt(plannedValueRaw);

      const row = input.closest('tr');
      if (!row) { console.warn('No row found for input:', input); return; }

      row.classList.remove('qty-match', 'qty-mismatch', 'qty-empty', 'qty-neutral');

      const hasValue = hasText(input);
      const touched = hasValue || input.dataset.touched === '1';

      // Neutral: both zero
      if (plannedValue === 0 && countedValue === 0) { row.classList.add('qty-neutral'); return; }
      // Suppress initial red until user touches the field
      if (!touched && plannedValue > 0 && countedValue === 0) { row.classList.add('qty-neutral'); return; }
      // Exact match (non-zero)
      if (countedValue === plannedValue) {
        if (countedValue !== 0) { row.classList.add('qty-match'); }
        else { row.classList.add('qty-neutral'); }
        return;
      }
      // Anything else mismatch
      row.classList.add('qty-mismatch');

      // Odd/suspicious value hints
      this.applyOddValueWarnings(input, countedValue, plannedValue);
    }

    applyOddValueWarnings(input, countedValue, plannedValue) {
      const raw = (input.value || '').trim();
      const warns = [];
      if (/^0\d+/.test(raw)) warns.push('Leading zero');
      if (/^(\d)\1{1,}$/.test(raw) && raw.length >= 2) warns.push('Repeated digits'); // 11, 222, 9999
      if (plannedValue > 0 && countedValue >= plannedValue * 5 && countedValue - plannedValue >= 10) warns.push('Way above planned');
      if (['99', '999', '9999'].includes(raw) && plannedValue < 50) warns.push('Suspicious value ' + raw);
      if (countedValue > 100000) warns.push('Unusually large');

      let warnEl = input.parentElement ? input.parentElement.querySelector('.qty-warn') : null;
      if (!warnEl && warns.length) {
        warnEl = document.createElement('small');
        warnEl.className = 'qty-warn text-warning d-block mt-1';
        if (input.parentElement) input.parentElement.appendChild(warnEl);
      }
      if (warns.length && warnEl) {
        warnEl.textContent = 'Check: ' + warns.slice(0, 3).join(', ');
      } else if (warnEl) {
        warnEl.remove();
      }
    }

    // ---------- Event binding ----------
    bindEvents() {
      // Quantity changes → update & debounce save
      document.addEventListener('input', (e) => {
        const t = e.target;
        if (t && t.matches('.qty-input, input[name^="counted_qty"]')) {
          if (!t.dataset.touched) t.dataset.touched = '1';
          this.updateQuantityStatus(t);
          this.markDirty();
          this.scheduleAutoSave();
        }
        this.recordActivity();
      }, { passive: true });

      // Focus (may be loaded lazily)
      document.addEventListener('focus', (e) => {
        const t = e.target;
        if (t && t.matches('.qty-input, input[name^="counted_qty"]')) {
          if (!t.dataset.touched && hasText(t)) t.dataset.touched = '1';
          this.updateQuantityStatus(t);
        }
        this.recordActivity();
      }, true);

      // Manual save button
      document.addEventListener('click', (e) => {
        const t = e.target;
        if (t && t.matches('#savePack, [data-action="save"]')) {
          e.preventDefault();
          this.saveNow();
        }
        this.recordActivity();
      });

      // General activity listeners (lightweight)
      ['keydown', 'mousemove', 'mousedown', 'touchstart', 'scroll', 'visibilitychange'].forEach((evt) => {
        document.addEventListener(evt, () => this.recordActivity(), { passive: true });
      });

      // Try to persist before unload if dirty (best-effort; no contract change)
      window.addEventListener('beforeunload', () => {
        if (this.isDirty && !this.isSaving) {
          // Fire & forget; navigator.sendBeacon would change transport, so just hint
          // Keep behavior minimal to avoid altering outputs.
        }
      });
    }

    recordActivity() { this.lastActivityTs = now(); }

    shouldShowSavedToast() {
      const idleFor = now() - this.lastActivityTs;
      if (idleFor < this.IDLE_TOAST_THRESHOLD) return false;
      if (this._lastIdleToastActivity === this.lastActivityTs) return false; // already toasted for this idle stretch
      this._lastIdleToastActivity = this.lastActivityTs;
      return true;
    }

    // ---------- Dirty state & scheduling ----------
    markDirty() { this.isDirty = true; }

    scheduleAutoSave() {
      if (this.saveTimeout) clearTimeout(this.saveTimeout);
      this.saveTimeout = setTimeout(() => this.saveNow(), this.saveDelay);
    }

    // ---------- Data collection ----------
    collectDraftData() {
      const data = {
        transfer_id: this.transferId,
        counted_qty: {},
        notes: '',
        timestamp: new Date().toISOString(),
      };

      // Collect counted quantities (positive ints only, matching original behavior)
      const quantityInputs = document.querySelectorAll('.qty-input, input[name^="counted_qty"]');
      quantityInputs.forEach((input) => {
        let productId = null;

        if (input.dataset.productId) {
          productId = String(input.dataset.productId);
        } else if (input.name && input.name.includes('[') && input.name.includes(']')) {
          productId = input.name.replace(/^counted_qty\[/, '').replace(/\]$/, '');
        } else if (input.dataset.item) {
          productId = String(input.dataset.item);
        } else {
          const row = input.closest('tr');
          if (row) {
            const productInput = row.querySelector('input[name*="product_id"], .productID, [data-product-id]');
            if (productInput) {
              productId = productInput.value || productInput.dataset.productId || null;
            }
          }
        }

        if (productId && hasText(input)) {
          const value = toInt(input.value);
          if (value > 0) {
            data.counted_qty[productId] = value;
          }
        }
      });

      // Collect notes (retain original selector set)
      const notesField = document.querySelector('#notesForTransfer, [name="notes"]');
      if (notesField && typeof notesField.value === 'string') {
        data.notes = notesField.value;
      }

      return data;
    }

    // ---------- Saving ----------
    async saveNow() {
      if (this.isSaving) return;

      const draftData = this.collectDraftData();
      const payloadHash = JSON.stringify(draftData.counted_qty || {}) + '|' + (draftData.notes || '');

      // No material change → emit noop
      if (this._lastHash === payloadHash) {
        this.isDirty = false;
        try { document.dispatchEvent(new CustomEvent('packautosave:state', { detail: { state: 'noop' } })); } catch (_) {}
        return;
      }

      this._lastHash = payloadHash;
      this.isSaving = true;

      // Notify "saving"
      try { document.dispatchEvent(new CustomEvent('packautosave:state', { detail: { state: 'saving' } })); } catch (_) {}

      // Abort any in-flight request (defensive)
      if (this._abortController) {
        try { this._abortController.abort(); } catch (_) {}
      }
      this._abortController = ('AbortController' in window) ? new AbortController() : null;

      const timeoutId = this._abortController ? setTimeout(() => {
        try { this._abortController.abort(); } catch (_) {}
      }, this.FETCH_TIMEOUT_MS) : null;

      try {
        const response = await fetch('/modules/transfers/stock/api/draft_save.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(draftData),
          signal: this._abortController ? this._abortController.signal : undefined,
          credentials: 'same-origin'
        });

        // Try to parse JSON safely (some servers may return 204/empty on success)
        let result = {};
        const text = await response.text();
        if (text) {
          try { result = JSON.parse(text); } catch (e) { /* malformed JSON → treat as failure below */ }
        }

        if (response.ok && result && (result.success === true)) {
          // Success path
          this.isDirty = false;
          this._consecErrors = 0; // reset backoff on success
          try { document.dispatchEvent(new CustomEvent('packautosave:state', { detail: { state: 'saved', payload: result } })); } catch (_) {}
          if (window.PackToast && this.shouldShowSavedToast()) {
            PackToast.success('Draft saved', { timeout: 3000, force: false });
          }
        } else {
          // Failure path (preserve same external behavior)
          console.error('Auto-save: Failed', result);
          this._consecErrors = (this._consecErrors || 0) + 1;
          try { document.dispatchEvent(new CustomEvent('packautosave:state', { detail: { state: 'error', payload: result } })); } catch (_) {}
          if (window.PackToast) {
            PackToast.error('Draft save failed', { action: { label: 'Retry', onClick: () => this.saveNow() }, force: this._consecErrors > 1 });
          }
          // Exponential backoff (1s, 2s, 4s, 8s → cap)
          const backoff = Math.min(this.MAX_BACKOFF_MS, Math.pow(2, Math.min(4, this._consecErrors)) * 1000);
          if (this.saveTimeout) clearTimeout(this.saveTimeout);
          this.saveTimeout = setTimeout(() => this.saveNow(), backoff);
        }
      } catch (error) {
        // Network or abort path
        console.error('Auto-save: Error', error);
        this._consecErrors = (this._consecErrors || 0) + 1;
        try { document.dispatchEvent(new CustomEvent('packautosave:state', { detail: { state: 'error', payload: { message: error && error.message ? error.message : 'Network error' } } })); } catch (_) {}
        if (window.PackToast) {
          PackToast.error('Draft save network error', { action: { label: 'Retry', onClick: () => this.saveNow() }, force: this._consecErrors > 1 });
        }
        const backoff = Math.min(this.MAX_BACKOFF_MS, Math.pow(2, Math.min(4, this._consecErrors)) * 1000);
        if (this.saveTimeout) clearTimeout(this.saveTimeout);
        this.saveTimeout = setTimeout(() => this.saveNow(), backoff);
      } finally {
        if (timeoutId) clearTimeout(timeoutId);
        this.isSaving = false;
      }
    }
  }

  // ---- Bootstrap on DOM ready ----
  function initAutoSave() {
    const transferIdEl = document.querySelector('[data-txid]') || document.querySelector('[data-transfer-id]') || document.body;
    let transferId = 0;
    if (transferIdEl) {
      transferId = toInt(transferIdEl.getAttribute('data-txid') || transferIdEl.getAttribute('data-transfer-id') || '0');
    }
    if (!transferId) {
      const p = new URLSearchParams(location.search);
      transferId = toInt(p.get('transfer') || p.get('t') || '0') || 0;
    }
    console.log('[PackAutoSave] Found transfer ID:', transferId);
    if (transferId > 0) {
      window.packAutoSave = new PackAutoSave(transferId);
      console.log('[PackAutoSave] Initialized');
      // Monkey patch saveNow to show inline status (kept exactly as your original flow)
      const _origProtoSave = PackAutoSave.prototype.saveNow.bind(window.packAutoSave);
      PackAutoSave.prototype.saveNow = async function () {
        if (this.isSaving) return;
        setInlineStatus('Saving...');
        await _origProtoSave();
        if (!this.isDirty) setInlineStatus('Saved'); else setInlineStatus('Pending changes');
      };
    } else {
      console.warn('[PackAutoSave] No valid transfer ID; disabled');
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAutoSave);
  } else {
    initAutoSave();
  }

  // Expose for compatibility with monkey patch
  window.setInlineStatus = setInlineStatus;
})();
</script>
