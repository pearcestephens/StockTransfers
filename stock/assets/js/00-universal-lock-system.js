/* =======================================================================
   Universal Lock System (generic, cross-module)
   - Clean, no <script> wrapper
   - Safe defaults to PACK lock endpoints
   - 10s poll, 30s heartbeat, 30m hold
   - Gracefully handles missing callbacks
   ======================================================================= */
(function () {
  'use strict';

  // Helpers ---------------------------------------------------------------
  function safeParse(text) {
    try { return JSON.parse(text); } catch { return null; }
  }

  async function apiJSON(path, { method = 'GET', form = null, json = null, timeout = 12000 } = {}) {
    const headers = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    let body = null;
    if (form !== null) { headers['Content-Type'] = 'application/x-www-form-urlencoded'; body = String(form); }
    if (json !== null) { headers['Content-Type'] = 'application/json'; body = JSON.stringify(json); }

    const ctrl = new AbortController();
    const t = setTimeout(() => ctrl.abort(), timeout);
    try {
      const res = await fetch(path, { method, headers, body, signal: ctrl.signal, credentials: 'include' });
      const text = await res.text();
      const data = safeParse(text) ?? { success: false, error: 'Invalid JSON', status: res.status, raw: text };
      if (!res.ok) throw Object.assign(new Error(data.error || `HTTP ${res.status}`), { status: res.status, data });
      return data;
    } finally {
      clearTimeout(t);
    }
  }

  function normalizePackLockStatus(raw) {
    const s = raw || {};
    const hasLock = !!(s.hasLock ?? s.has_lock ?? s.mine ?? s.owning);
    const isLockedByOther = !!(s.isLockedByOther ?? s.is_locked_by_other ?? (s.holder_name || s.lockedBy || s.locked_by));
    return {
      hasLock,
      isLockedByOther,
      holderId:   s.holderId ?? s.holder_id ?? s.lockedBy ?? s.locked_by ?? null,
      holderName: s.holderName ?? s.holder_name ?? s.lockedByName ?? null,
      lockAcquiredAt: s.lockAcquiredAt ?? s.lock_acquired_at ?? s.acquired_at ?? null,
      lockExpiresAt:  s.lockExpiresAt  ?? s.lock_expires_at  ?? s.expires_at  ?? null,
      userId:        s.userId ?? s.user_id ?? null,
      resourceId:    s.resourceId ?? s.resource_id ?? s.transfer_id ?? null,
      resourceType:  s.resourceType ?? s.resource_type ?? 'resource',
      raw: s
    };
  }

  // Class -----------------------------------------------------------------
  class UniversalLockSystem {
    constructor(options = {}) {
      if (!options.resourceId) {
        console.error('UniversalLockSystem: resourceId is required');
        return;
      }

      // Callbacks(guarded)
      this.onLockAcquired  = options.onLockAcquired  || (() => {});
      this.onLockLost      = options.onLockLost      || (() => {});
      this.onLockRequested = options.onLockRequested || (() => {});
      this.onReadOnlyMode  = options.onReadOnlyMode  || (() => {});
      this.debugEnabled    = !!options.debug;

      // Identity / config
      this.resourceType  = options.resourceType || 'resource';
      this.resourceId    = options.resourceId;
      this.userId        = options.userId ?? null;

      // API map (default to PACK endpoints)
      this.api = options.api || {
        status:    '/modules/transfers/stock/api/lock_status.php',
        acquire:   '/modules/transfers/stock/api/lock_acquire.php',
        release:   '/modules/transfers/stock/api/lock_release.php',
        heartbeat: '/modules/transfers/stock/api/lock_heartbeat.php',
        request:   '/modules/transfers/stock/api/lock_request.php',
        respond:   '/modules/transfers/stock/api/lock_request_respond.php',
        pending:   '/modules/transfers/stock/api/lock_requests_pending.php',
        autogrant: '/modules/transfers/stock/api/auto_grant_service.php'
      };

      // Timers & durations
      this.pollInterval = options.pollInterval ?? 10000; // 10s poll
      this.lockDuration = options.lockDuration ?? 1800;  // 30 min
      this.isPageVisible = !document.hidden;

      // State
      this.lockStatus = { hasLock: false, isLockedByOther: false };
      this.pollTimer = null;
      this.heartbeatTimer = null;
      this._pollCycle = 0;

      // Bootstrap
      this._wireLifecycle();
      this.checkLockStatus();
      this.startPolling();
    }

    debug(...a) { if (this.debugEnabled) console.log('[ULS]', ...a); }

    // Lifecycle wiring
    _wireLifecycle() {
      document.addEventListener('visibilitychange', () => {
        this.isPageVisible = !document.hidden;
        if (this.isPageVisible) this.startPolling();
        else this.stopPolling();
      });

      window.addEventListener('beforeunload', () => {
        try {
          if (!this.lockStatus?.hasLock) return;
          const fd = new FormData();
          fd.append('resource_type', this.resourceType);
          fd.append('resource_id', String(this.resourceId));
          navigator.sendBeacon(this.api.release, fd);
        } catch (_) {}
      });
    }

    // Public actions -------------------------------------------------------
    async acquireLock(fingerprint = null) {
      try {
        const form =
          `resource_type=${encodeURIComponent(this.resourceType)}` +
          `&resource_id=${encodeURIComponent(this.resourceId)}` +
          `&fingerprint=${encodeURIComponent(fingerprint || this._fingerprint())}` +
          `&duration=${encodeURIComponent(this.lockDuration)}`;
        const res = await apiJSON(this.api.acquire, { method: 'POST', form });
        if (res.success) {
          const next = normalizePackLockStatus({
            hasLock: true,
            isLockedByOther: false,
            holder_name: res.lock?.holder_name,
            expires_at:  res.lock?.expires_at,
            resource_id: this.resourceId,
            resource_type: this.resourceType
          });
          this._updateLockStatus(next);
          return { success: true, lock: res.lock };
        }
        return { success: false, error: res.error || 'lock acquire failed', conflict: res.conflict, holder: res.holder };
      } catch (e) {
        return { success: false, error: e?.message || 'network error' };
      }
    }

    async releaseLock() {
      try {
        const form =
          `resource_type=${encodeURIComponent(this.resourceType)}` +
          `&resource_id=${encodeURIComponent(this.resourceId)}`;
        const res = await apiJSON(this.api.release, { method: 'POST', form });
        if (res.success) this._updateLockStatus({ hasLock: false, isLockedByOther: false });
        return res;
      } catch (e) {
        return { success: false, error: e?.message || 'network error' };
      }
    }

    async requestOwnership(message = 'Requesting access') {
      try {
        const form =
          `resource_type=${encodeURIComponent(this.resourceType)}` +
          `&resource_id=${encodeURIComponent(this.resourceId)}` +
          `&message=${encodeURIComponent(message)}`;
        return await apiJSON(this.api.request, { method: 'POST', form });
      } catch (e) {
        return { success: false, error: e?.message || 'network error' };
      }
    }

    async respondToOwnershipRequest(requestId, granted) {
      try {
        const form =
          `request_id=${encodeURIComponent(requestId)}` +
          `&granted=${granted ? 1 : 0}`;
        const res = await apiJSON(this.api.respond, { method: 'POST', form });
        if (res.success && granted) {
          // we gave it away → go read-only
          this._updateLockStatus({ hasLock: false, isLockedByOther: true });
        }
        return res;
      } catch (e) {
        return { success: false, error: e?.message || 'network error' };
      }
    }

    // Polling & heartbeat --------------------------------------------------
    startPolling() {
      if (this.pollTimer || !this.isPageVisible) return;
      this.pollTimer = setInterval(() => {
        if (document.hidden) return;
        this.checkLockStatus();
        // occasionally poke autogrant to sweep expired takeover requests
        this._pollCycle = (this._pollCycle + 1) >>> 0;
        if (this._pollCycle % 6 === 0) this.pokeAutoGrant();
      }, this.pollInterval);
      this.debug('polling started');
    }

    stopPolling() {
      if (this.pollTimer) {
        clearInterval(this.pollTimer);
        this.pollTimer = null;
        this.debug('polling stopped');
      }
    }

    startHeartbeat() {
      if (this.heartbeatTimer) clearInterval(this.heartbeatTimer);
      this.heartbeatTimer = setInterval(async () => {
        try {
          if (!this.lockStatus?.hasLock) return;
          const form =
            `resource_type=${encodeURIComponent(this.resourceType)}` +
            `&resource_id=${encodeURIComponent(this.resourceId)}`;
          await apiJSON(this.api.heartbeat, { method: 'POST', form });
        } catch (_) {}
      }, 30000);
    }

    stopHeartbeat() {
      if (this.heartbeatTimer) {
        clearInterval(this.heartbeatTimer);
        this.heartbeatTimer = null;
      }
    }

    async checkLockStatus() {
      try {
        const q = `?resource_type=${encodeURIComponent(this.resourceType)}&resource_id=${encodeURIComponent(this.resourceId)}`;
        const res = await apiJSON(this.api.status + q, { method: 'GET' });
        if (res.success) {
          this._updateLockStatus(res.data);
          if (res.data?.has_lock) this._checkPendingRequests();
        }
        return res;
      } catch (e) {
        this.debug('status error', e?.message || e);
        return { success: false, error: e?.message || 'network error' };
      }
    }

    async _checkPendingRequests() {
      try {
        const q = `?resource_type=${encodeURIComponent(this.resourceType)}&resource_id=${encodeURIComponent(this.resourceId)}`;
        const res = await apiJSON(this.api.pending + q, { method: 'GET' });
        if (res.success && Array.isArray(res.requests) && res.requests.length) {
          this.onLockRequested(res.requests[0]);
        }
      } catch (e) {
        this.debug('pending error', e?.message || e);
      }
    }

    async pokeAutoGrant() {
      if (!this.api.autogrant) return;
      try { await apiJSON(this.api.autogrant, { method: 'POST', form: '' }); } catch (_) {}
    }

    // Internals ------------------------------------------------------------
    _updateLockStatus(statusRaw) {
      const prev = normalizePackLockStatus(this.lockStatus);
      const next = normalizePackLockStatus(statusRaw);
      this.lockStatus = next;
      window.lockStatus = statusRaw;
      window.lockStatusNormalized = next;

      if (!prev.hasLock && next.hasLock) {
        this.onLockAcquired(next);
        this.startHeartbeat();
      } else if (prev.hasLock && !next.hasLock) {
        this.onLockLost();
        this.stopHeartbeat();
      }

      if (next.isLockedByOther) {
        this.onReadOnlyMode(next);
      }
    }

    _fingerprint() {
      return btoa(`${navigator.userAgent}|${screen.width}x${screen.height}|${Date.now()}`).slice(0, 32);
    }
  }

  // Expose
  window.UniversalLockSystem = UniversalLockSystem;
  window.normalizePackLockStatus = normalizePackLockStatus;
})();
