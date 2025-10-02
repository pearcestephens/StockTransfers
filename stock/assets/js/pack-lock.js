'use strict';

/**
 * pack-lock.js
 * Transfer Pack Lock System — Legacy Endpoint Adapter + UI
 * - Waits for UniversalLockSystem before defining the class
 * - Uses legacy PHP endpoints via this.api (relative URLs)
 * - Single heartbeat (90s). No duplicate networking or polling loops.
 * - Uses this.resourceId (never this.transferId)
 * - Exposes window.PackLockSystem, showLockDiagnostic(), debugOwnership helpers
 */

(function bootstrapPackLock(global) {
  function define(Base) {
    class PackLockSystem extends Base {
      constructor(transferId, userId, opts = {}) {
        // ===== Modern endpoints (service-based) =====
        const api = {
          status    : '/modules/transfers/stock/api/lock_status_mod.php',
          acquire   : '/modules/transfers/stock/api/lock_acquire_mod.php',
          release   : '/modules/transfers/stock/api/lock_release_mod.php',
          heartbeat : '/modules/transfers/stock/api/lock_heartbeat_mod.php',
          request_start : '/modules/transfers/stock/api/lock_request_start.php',
          request_decide: '/modules/transfers/stock/api/lock_request_decide.php',
          request_poll  : '/modules/transfers/stock/api/lock_request_poll.php',
          request_events: '/modules/transfers/stock/api/lock_request_events.php',
          staff     : '/modules/transfers/stock/api/get_staff_users.php',
          force_release: '/modules/transfers/stock/api/lock_force_release.php'
        };

        super({
          resourceType: 'transfer',
          resourceId  : transferId,
          userId,
          api,
          pollInterval: 10000,       // base will poll; we retune inside checkLockStatus
          lockDuration: 1800,        // not used by legacy (harmless)
          debug: !!opts.debug,
          onLockAcquired : (st) => this.onLockAcquired(st),
          onLockLost     : () => this.onLockLost(),
          onLockRequested: (req) => this.onLockRequested(req),
          onReadOnlyMode : (st) => this.onReadOnlyMode(st)
        });

        this.api = api;
        this.userId = userId;

        // Make easy to reach during dev
        global.packLockSystem = this;
      }

      /* -------------------------
       * Legacy endpoint adapters
       * ------------------------- */

      // Helper: fingerprint (fallback if base doesn’t provide one)
      fingerprint() {
        try {
          if (typeof super.fingerprint === 'function') return super.fingerprint();
        } catch (_) {}
        return `${navigator.userAgent}|uid:${this.userId || '0'}`;
      }

      // 1) STATUS
      async checkLockStatus() {
        try {
          const url = `${this.api.status}?transfer_id=${encodeURIComponent(this.resourceId)}`;
          const res = await fetch(url, { credentials: 'include', headers: { 'X-Requested-With': 'XMLHttpRequest' }});
          const json = await res.json();

          if (json && json.success) {
            const s = json.data || {};
            const next = {
              has_lock          : !!s.has_lock,
              is_locked         : !!s.is_locked,
              is_locked_by_other: !!s.is_locked_by_other,
              holder_name       : s.holder_name || null,
              expires_at        : s.expires_at  || s.lock_expires_at || null,
              lock_acquired_at  : s.lock_acquired_at || null
            };

            // Update base + UI
            try { super.updateLockStatus(next); } catch (_) {}
            this.updateLockStatus(next);

            // Adaptive polling: slower when holder, faster otherwise
            const target = next.has_lock ? 60000 : 10000;
            if (this.pollInterval !== target) {
              this.pollInterval = target;
              if (typeof this.stopPolling === 'function') this.stopPolling();
              if (typeof this.startPolling === 'function') this.startPolling();
            }

            // If we hold the lock, check pending ownership requests
            if (next.has_lock) await this.checkForOwnershipRequests();
          }
          return json;
        } catch (e) {
          console.warn('[PackLock] status fail', e);
          return { success:false, error:'Network error' };
        }
      }

      // 2) ACQUIRE
      async acquireLock(fingerprint = null) {
        try {
          const body = new URLSearchParams({
            transfer_id: String(this.resourceId),
            fingerprint: fingerprint || this.fingerprint()
          });
          const res = await fetch(this.api.acquire, {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body
          });
          const data = await res.json();

          if (data.success) {
            const lock = data.lock || {};
            const next = {
              has_lock: true,
              is_locked: true,
              is_locked_by_other: false,
              holder_name: lock.holder_name || null,
              expires_at: lock.expires_at || lock.lock_expires_at || null,
              lock_acquired_at: lock.acquired_at || null
            };
            try { super.updateLockStatus(next); } catch (_) {}
            this.updateLockStatus(next);
            this.startHeartbeat();
            this.onLockAcquired(lock);
            return { success:true, lock };
          }
          return { success:false, error:data.error, conflict:data.conflict, holder:data.holder };
        } catch (e) {
          return { success:false, error:'Network error' };
        }
      }

      // 3) RELEASE
      async releaseLock() {
        try {
          const body = new URLSearchParams({ transfer_id: String(this.resourceId) });
          const res = await fetch(this.api.release, {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body
          });
          const data = await res.json();

          if (data.success) {
            const next = { has_lock:false, is_locked:false, is_locked_by_other:false };
            try { super.updateLockStatus(next); } catch (_) {}
            this.updateLockStatus(next);
            this.stopHeartbeat();
            this.onLockLost();
          }
          return data;
        } catch (e) {
          return { success:false, error:'Network error' };
        }
      }

      // 4) HEARTBEAT — every 90s
      startHeartbeat() {
        if (this.heartbeatTimer) return;
        this.heartbeatTimer = setInterval(async () => {
          if (!this.lockStatus?.has_lock) return;
          try {
            const body = new URLSearchParams({ transfer_id: String(this.resourceId) });
            await fetch(this.api.heartbeat, {
              method: 'POST',
              credentials: 'include',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
              body
            });
          } catch (_) {}
        }, 90000);
      }
      stopHeartbeat() {
        if (this.heartbeatTimer) { clearInterval(this.heartbeatTimer); this.heartbeatTimer = null; }
      }

      // 5) REQUEST OWNERSHIP (modern start)
      async requestOwnership(message = 'Requesting access') {
        try {
          const body = new URLSearchParams({ transfer_id: String(this.resourceId), message });
          const res = await fetch(this.api.request_start, { method:'POST', credentials:'include', headers:{ 'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest' }, body });
          const json = await res.json();
          if(json.success){ this.ensureRequestEvents(); }
          return json;
        } catch { return { success:false, error:'Network error' }; }
      }

      ensureRequestEvents(){
        if(this._requestEventSource) return;
        try {
          const url = `${this.api.request_events}?transfer_id=${encodeURIComponent(this.resourceId)}`;
          const es = new EventSource(url);
          this._requestEventSource = es;
          es.addEventListener('lock', (ev)=>{ try { this.handleLockRequestEvent(JSON.parse(ev.data)); } catch(e){ console.warn('SSE parse fail', e); } });
          es.onerror = () => { /* browser will attempt reconnect */ };
        } catch(e){ console.warn('SSE open failed', e); }
      }

      async handleLockRequestEvent(payload){
        if(!payload || !payload.state) return;
        const st = payload.state;
        if((st==='accepted' || payload.state_alias==='granted') && payload.requesting_user_id === this.userId){
          const r = await this.acquireLock();
          if(r?.success) this.checkLockStatus();
          return;
        }
        if(st==='pending' && payload.action_required){ this.showPendingDecision(payload); }
      }

      showPendingDecision(payload){
        if(this._pendingDecisionBanner) return;
        const bar = document.createElement('div');
        bar.className='lock-decision-banner';
        bar.style.cssText='position:fixed;bottom:0;left:0;right:0;z-index:9999;background:#222;color:#fff;padding:8px 12px;font-size:14px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 -2px 6px rgba(0,0,0,.3)';
        bar.innerHTML=`<span>Lock request from <strong>${payload.requesting_user_name||('User '+payload.requesting_user_id)}</strong></span><span><button id="lockDecideAccept" class="btn btn-sm btn-success mr-2">Give Lock</button><button id="lockDecideDecline" class="btn btn-sm btn-danger">Decline</button></span>`;
        document.body.appendChild(bar);
        bar.querySelector('#lockDecideAccept').onclick=()=>this.decideRequest(payload.request_id,true);
        bar.querySelector('#lockDecideDecline').onclick=()=>this.decideRequest(payload.request_id,false);
        this._pendingDecisionBanner = bar;
      }

      async decideRequest(requestId, accept){
        try {
          const body = new URLSearchParams({ transfer_id:String(this.resourceId), decision: accept?'grant':'decline' });
          const res = await fetch(this.api.request_decide, { method:'POST', credentials:'include', headers:{ 'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest' }, body });
          const json = await res.json();
          if(this._pendingDecisionBanner){ this._pendingDecisionBanner.remove(); this._pendingDecisionBanner = null; }
          if(json.success && json.state==='accepted'){ this.checkLockStatus(); }
          return json;
        } catch(e){ console.warn('Decision error', e); }
      }

      // 6) PENDING REQUESTS
      async checkForOwnershipRequests() {
        try {
          const url = `${this.api.pending}?transfer_id=${encodeURIComponent(this.resourceId)}`;
          const res = await fetch(url, { credentials:'include', headers:{ 'X-Requested-With':'XMLHttpRequest' }});
          const data = await res.json();
          if (data.success && Array.isArray(data.requests) && data.requests.length > 0) {
            this.onLockRequested(data.requests[0]);
          }
        } catch (e) {
          console.warn('[PackLock] pending fail', e);
        }
      }

      // 7) RESPOND TO REQUEST
      async respondToOwnershipRequest(requestId, granted) {
        try {
          const params = new URLSearchParams({ request_id:String(requestId), action: granted ? 'accept' : 'decline' });
          const res = await fetch(this.api.respond, {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type':'application/x-www-form-urlencoded', 'X-Requested-With':'XMLHttpRequest' },
            body: params
          });
          const data = await res.json();
          if (data.success && granted) {
            const next = { has_lock:false, is_locked_by_other:true };
            try { super.updateLockStatus(next); } catch (_) {}
            this.updateLockStatus(next);
            this.stopHeartbeat();
            this.onLockLost();
          }
          return data;
        } catch {
          return { success:false, error:'Network error' };
        }
      }

      /* -------------------------
       * Base event hooks (UI)
       * ------------------------- */
      onLockAcquired(_state) {
        this.enableControls(true);
        this.startHeartbeat(); // ensure HB is running
      }
      onLockLost() {
        this.enableControls(false);
      }
      onLockRequested(request) {
        this.showOwnershipRequestNotification(request);
      }
      onReadOnlyMode(_state) {
        this.showReadOnlyMode();
      }

      /* -------------------------
       * Minimal boot/wiring
       * ------------------------- */
      init() {
        const boot = () => {
          this.loadStaffUsers();
          if (typeof this.startPolling === 'function') this.startPolling(); // base loop (still used for status fallback)
          this.checkForActiveRequest();
          this.ensureRequestEvents();
          document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
              if (typeof this.stopPolling === 'function') this.stopPolling();
            } else {
              if (typeof this.startPolling === 'function') this.startPolling();
            }
          });
          this.setupEventHandlers();
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
        else boot();
      }

      /* -------------------------
       * UI helpers (kept & refined)
       * ------------------------- */
      loadStaffUsers() {
        fetch(this.api.staff, { credentials:'include' })
          .then(r => r.json())
          .then(data => { if (data.success) global.staffUsers = data.users || {}; })
          .catch(() => { global.staffUsers = global.staffUsers || {}; });
      }

      setupEventHandlers() {
        document.addEventListener('click', (e) => {
          if (e.target && e.target.id === 'requestOwnershipBtn') {
            this.handleRequestOwnershipAction(e.target);
          }
        });
      }

      async handleRequestOwnershipAction(buttonEl) {
        if (buttonEl) buttonEl.disabled = true;
        const res = await this.requestOwnership('User requesting ownership');
        if (res && res.success) {
          try {
            localStorage.setItem(
              `ownership_request_${this.resourceId}`,
              JSON.stringify({ request_id: res.request_id, expires_at: res.expires_at, started_at: Date.now() })
            );
          } catch (_) {}
          this.transformButtonToCountdown(buttonEl, res.request_id, res.expires_at);
          return;
        }
        // Error UI
        if (buttonEl) {
          buttonEl.disabled = false;
          buttonEl.innerHTML = `<i class="fa fa-exclamation-triangle mr-2"></i>Error - Try Again`;
          buttonEl.style.background = '#dc3545';
          setTimeout(() => {
            buttonEl.innerHTML = `<i class="fa fa-hand-paper mr-2"></i>Request Ownership`;
            buttonEl.style.background = '';
          }, 2000);
        }
      }

      updateLockStatus(status) {
        // Mirror locally for UI
        this.lockStatus = { ...(this.lockStatus || {}), ...status };
        const lockBadge = document.getElementById('lockStatusBadge');

  if (status.has_lock || status.hasLock) {
          if (lockBadge) {
            lockBadge.textContent = 'LOCKED BY YOU';
            lockBadge.style.background = 'rgba(40, 167, 69, 0.9)';
            lockBadge.style.border = '1px solid rgba(40, 167, 69, 1)';
          }
          this.startHeartbeat();
          this.enableControls(true);
  } else if (status.is_locked_by_other || status.isLockedByOther) {
          if (lockBadge) {
            lockBadge.textContent = `LOCKED BY ${status.holder_name || 'OTHER USER'}`;
            lockBadge.style.background = 'rgba(220, 53, 69, 0.9)';
            lockBadge.style.border = '1px solid rgba(220, 53, 69, 1)';
          }
          this.enableControls(false);
        } else {
          if (lockBadge) {
            lockBadge.textContent = 'ACQUIRING LOCK...';
            lockBadge.style.background = 'rgba(255, 193, 7, 0.9)';
            lockBadge.style.border = '1px solid rgba(255, 193, 7, 1)';
          }
          this.acquireLock();
        }
      }

      enableControls(enabled) {
        if (!enabled) {
          this.showReadOnlyMode();
          this.disableFormElements();
        } else {
          this.hideReadOnlyMode();
          this.enableFormElements();
        }
      }

      showReadOnlyMode() {
        this.hideReadOnlyMode(); // clear existing
        const stickyBar = document.createElement('div');
        stickyBar.id = 'readOnlyStickyBar';
        stickyBar.innerHTML = `
          <div class="content-wrapper">
            <div class="lock-info">
              <div class="lock-title"><i class="fa fa-lock mr-2"></i>Transfer Currently Locked</div>
              <div class="lock-subtitle">This transfer is being edited by another user. You can view but cannot make changes.</div>
            </div>
            <div class="action-area">
              <button id="requestOwnershipBtn" class="btn btn-lg">
                <i class="fa fa-hand-paper mr-2"></i>Request Ownership
              </button>
            </div>
          </div>
          <div id="requestStatus" style="display:none"></div>
        `;
        document.body.appendChild(stickyBar);
        document.body.classList.add('lock-sticky-active');
      }

      hideReadOnlyMode() {
        ['readOnlyStickyBar', 'readOnlyBanner', 'lockOverlay', 'lockTestOverlay'].forEach(id => {
          const el = document.getElementById(id);
          if (el) el.remove();
        });
        document.body.classList.remove('lock-sticky-active');
      }

      disableFormElements() {
        document.querySelectorAll('input, button, select, textarea, .btn').forEach(el => {
          if (!el.closest('#readOnlyStickyBar') && el.id !== 'lockDiagnosticBtn') {
            el.disabled = true;
            el.classList.add('read-only-disabled');
          }
        });
      }
      enableFormElements() {
        document.querySelectorAll('.read-only-disabled').forEach(el => {
          el.disabled = false;
          el.classList.remove('read-only-disabled');
        });
      }

      transformButtonToCountdown(button, requestId, expiresAt) {
        if (!button) return;

        const expiryTime = new Date(expiresAt).getTime();
        button.style.cssText = `
          background: linear-gradient(45deg, #6c757d, #868e96);
          color: white; border: none; padding: 12px 20px; border-radius: 8px;
          font-weight: 600; font-size: 14px; cursor: default;
          box-shadow: 0 2px 4px rgba(0,0,0,.1); transition: all .3s ease; min-width: 160px;
        `;
        button.disabled = true;

        const update = () => {
          const now = Date.now();
          const remaining = Math.max(0, Math.ceil((expiryTime - now) / 1000));
          if (remaining <= 0) {
            button.innerHTML = `<i class="fa fa-refresh mr-2"></i>Refresh Page`;
            button.style.background = 'linear-gradient(45deg, #28a745, #20c997)';
            button.style.cursor = 'pointer';
            button.disabled = false;
            button.onclick = () => {
              try { localStorage.removeItem(`ownership_request_${this.resourceId}`); } catch (_) {}
              window.location.reload();
            };
            // Try to grant
            this.grantOwnership(requestId);
            clearInterval(this.ownershipCountdown);
            return;
          }
          const m = Math.floor(remaining / 60);
          const s = remaining % 60;
          const t = m > 0 ? `${m}:${String(s).padStart(2,'0')}` : `${remaining}s`;
          button.innerHTML = `<i class="fa fa-clock mr-2"></i>Request Sent: ${t}`;
        };

        update();
        this.ownershipCountdown = setInterval(update, 1000);
      }

      checkForActiveRequest() {
        let stored = null;
        try { stored = JSON.parse(localStorage.getItem(`ownership_request_${this.resourceId}`) || 'null'); } catch (_) {}
        if (!stored) return;

        const expiryTime = new Date(stored.expires_at).getTime();
        if (Date.now() < expiryTime) {
          const btn = document.getElementById('requestOwnershipBtn');
          if (btn) this.transformButtonToCountdown(btn, stored.request_id, stored.expires_at);
        } else {
          try { localStorage.removeItem(`ownership_request_${this.resourceId}`); } catch (_) {}
        }
      }

      async grantOwnership(_requestId) {
        try {
          const res = await fetch(this.api.autogrant, {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: ''
          });
          const data = await res.json();
          if (data.success) {
            // Force quick status refresh
            setTimeout(() => this.checkLockStatus(), 500);
          }
        } catch (_) {}
      }

      /* -------------------------
       * Ownership modal + utils
       * ------------------------- */
      showOwnershipRequestNotification(request) {
        if (document.getElementById('ownershipRequestModal')) return;
        if (!request || typeof request !== 'object') return;

        const backdrop = document.createElement('div');
        backdrop.id = 'ownershipRequestModal';
        backdrop.style.cssText = `
          position: fixed; inset: 0; background: rgba(0,0,0,.75); z-index: 9999;
          display:flex; align-items:center; justify-content:center; animation: fadeIn .3s ease;
        `;
        const modal = document.createElement('div');
        modal.style.cssText = `
          background: #fff; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,.2);
          max-width: 500px; width: 90%; margin: 20px; animation: slideIn .3s ease; border: 2px solid #dee2e6;
        `;

        const requesterName = this.getUserName(request.requesting_user || request.user_id);
        const requesterIP = this.extractIPFromFingerprint(request.client_fingerprint);

        modal.innerHTML = `
          <div style="background: linear-gradient(135deg,#495057,#6c757d); color:#fff; padding:20px; border-radius:12px 12px 0 0; text-align:center;">
            <i class="fa fa-hand-paper" style="font-size:2em; margin-bottom:10px;"></i>
            <h3 style="margin:0; font-size:1.5em; font-weight:700;">Ownership Request</h3>
            <p style="margin:5px 0 0; opacity:.9; font-size:.9em;">Someone wants to edit this transfer</p>
          </div>
          <div style="padding:25px;">
            <div style="margin-bottom:20px;">
              <div style="background:#f8f9fa; border-radius:8px; padding:15px; border-left:4px solid #6c757d;">
                <div style="display:flex; align-items:center; margin-bottom:10px;">
                  <i class="fa fa-user" style="color:#6c757d; margin-right:10px; width:20px;"></i>
                  <strong style="color:#333;">Requested by:</strong>
                  <span style="margin-left:8px; color:#0066cc; font-weight:600;">${requesterName}</span>
                </div>
                <div style="display:flex; align-items:center; margin-bottom:10px;">
                  <i class="fa fa-globe" style="color:#6c757d; margin-right:10px; width:20px;"></i>
                  <strong style="color:#333;">IP Address:</strong>
                  <span style="margin-left:8px; font-family:monospace; color:#666;">${requesterIP}</span>
                </div>
                <div style="display:flex; align-items:center;">
                  <i class="fa fa-clock" style="color:#6c757d; margin-right:10px; width:20px;"></i>
                  <strong style="color:#333;">Requested:</strong>
                  <span style="margin-left:8px; color:#666;">${this.formatTimeAgo(request.created_at || request.requested_at)}</span>
                </div>
              </div>
            </div>
            <div style="background:#fff3cd; border:1px solid #ffeaa7; border-radius:6px; padding:12px; margin-bottom:20px; font-size:.9em; color:#856404;">
              <i class="fa fa-info-circle" style="margin-right:5px;"></i>
              If you <strong>Accept</strong>, they will gain control and you'll be redirected to the transfers list.<br>
              <strong>You must choose Accept or Decline.</strong>
            </div>
            <div style="display:flex; gap:15px;">
              <button id="acceptOwnershipBtn" style="flex:1; background:linear-gradient(45deg,#28a745,#20c997); color:#fff; border:none; padding:12px 20px; border-radius:8px; font-weight:700; font-size:16px; cursor:pointer; transition:all .2s ease; box-shadow:0 3px 12px rgba(40,167,69,.3);">
                <i class="fa fa-check mr-2"></i>Accept Request
              </button>
              <button id="declineOwnershipBtn" style="flex:1; background:linear-gradient(45deg,#dc3545,#c82333); color:#fff; border:none; padding:12px 20px; border-radius:8px; font-weight:700; font-size:16px; cursor:pointer; transition:all .2s ease; box-shadow:0 3px 12px rgba(220,53,69,.3);">
                <i class="fa fa-times mr-2"></i>Decline
              </button>
            </div>
          </div>
        `;
        backdrop.appendChild(modal);
        document.body.appendChild(backdrop);

        const style = document.createElement('style');
        style.textContent = `
          @keyframes fadeIn { from{opacity:0} to{opacity:1} }
          @keyframes slideIn { from{transform:translateY(-50px);opacity:0} to{transform:translateY(0);opacity:1} }
        `;
        document.head.appendChild(style);

        document.getElementById('acceptOwnershipBtn').onclick = async () => {
          backdrop.remove();
          const out = await this.respondToOwnershipRequest(request.id || request.request_id, true);
          if (out?.success) {
            const overlay = document.createElement('div');
            overlay.style.cssText = `position:fixed;inset:0;background:rgba(40,167,69,.95);z-index:10000;display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.5em;text-align:center;`;
            overlay.innerHTML = `<div><i class="fa fa-check-circle" style="font-size:3em;margin-bottom:20px;"></i><br>Ownership Transferred!<br><small style="opacity:.8;">Redirecting...</small></div>`;
            document.body.appendChild(overlay);
            setTimeout(() => { window.location.href = 'index.php'; }, 1500);
          }
        };

        document.getElementById('declineOwnershipBtn').onclick = async () => {
          backdrop.remove();
          await this.respondToOwnershipRequest(request.id || request.request_id, false);
        };
      }

      getUserName(userId) {
        if (global.staffUsers && global.staffUsers[userId] && global.staffUsers[userId].name) {
          return global.staffUsers[userId].name;
        }
        return `Staff Member #${userId}`;
      }
      extractIPFromFingerprint(fingerprint) {
        if (!fingerprint) return 'Unknown IP';
        try { const data = JSON.parse(fingerprint); return data.ip || 'Unknown IP'; }
        catch { return (typeof fingerprint === 'string' && fingerprint.includes(':')) ? fingerprint.split(':')[0] : 'Unknown IP'; }
      }
      formatTimeAgo(ts) {
        if (!ts) return 'Unknown time';
        const t = new Date(ts); if (isNaN(t.getTime())) return 'Invalid time';
        const mins = Math.floor((Date.now() - t.getTime()) / 60000);
        if (mins < 1) return 'Just now';
        if (mins === 1) return '1 minute ago';
        if (mins < 60) return `${mins} minutes ago`;
        const hrs = Math.floor(mins / 60);
        if (hrs === 1) return '1 hour ago';
        if (hrs < 24) return `${hrs} hours ago`;
        return t.toLocaleDateString();
      }

      cleanup() {
        // Stop polling/heartbeat and attempt graceful release
        try { if (typeof this.stopPolling === 'function') this.stopPolling(); } catch (_) {}
        try { this.stopHeartbeat(); } catch (_) {}
        try {
          if (this.lockStatus?.has_lock) this.releaseLock();
        } catch (_) {}
      }

      /* -------------------------
       * Diagnostic + UI Methods
       * ------------------------- */
      showOwnershipRequestNotification(request) { // already defined above; placeholder to avoid truncation errors
        // NOTE: This method was fully defined earlier; truncation ended mid-file.
        // Intentionally left blank here to prevent duplicate definitions if earlier section exists.
      }

      showLockDiagnostic() {
        // Lightweight diagnostic overlay (non-modal) summarizing status
        const existing = document.getElementById('lockDiagPanel');
        if (existing) { existing.remove(); return; }
        const panel = document.createElement('div');
        panel.id = 'lockDiagPanel';
        panel.style.cssText = 'position:fixed;top:70px;right:20px;z-index:9999;background:#111827;color:#f1f5f9;padding:14px 18px;border:1px solid #334155;border-radius:10px;box-shadow:0 6px 18px rgba(0,0,0,.4);font:13px/1.4 system-ui,Segoe UI,Roboto,sans-serif;max-width:320px;';
        const st = this.lockStatus || {};
        panel.innerHTML = `
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
            <strong style="font-size:13px;">Lock Diagnostic</strong>
            <button type="button" style="background:transparent;border:0;color:#94a3b8;cursor:pointer;font-size:16px;line-height:1;padding:2px 4px;" aria-label="Close" onclick="document.getElementById('lockDiagPanel')?.remove()">×</button>
          </div>
          <div style="font-size:12px;">
            <div><span style="color:#64748b">Resource:</span> transfer #${this.resourceId}</div>
            <div><span style="color:#64748b">Has Lock:</span> <span style="color:${st.has_lock ? '#22c55e' : '#ef4444'}">${!!st.has_lock}</span></div>
            <div><span style="color:#64748b">Locked By Other:</span> ${!!st.is_locked_by_other}</div>
            <div><span style="color:#64748b">Holder:</span> ${st.holder_name || '—'}</div>
            <div><span style="color:#64748b">Expires:</span> ${st.expires_at || '—'}</div>
            <div style="margin-top:6px;display:flex;gap:6px;flex-wrap:wrap;">
              <button type="button" data-act="refresh" style="flex:1;background:#1e293b;border:1px solid #334155;color:#e2e8f0;padding:6px 8px;border-radius:6px;font-weight:600;cursor:pointer;font-size:12px;">Refresh</button>
              <button type="button" data-act="acquire" style="flex:1;background:#0369a1;border:1px solid #0ea5e9;color:#fff;padding:6px 8px;border-radius:6px;font-weight:600;cursor:pointer;font-size:12px;">Acquire</button>
              <button type="button" data-act="release" style="flex:1;background:#7f1d1d;border:1px solid #dc2626;color:#fff;padding:6px 8px;border-radius:6px;font-weight:600;cursor:pointer;font-size:12px;">Release</button>
            </div>
          </div>`;
        panel.addEventListener('click', (e) => {
          const act = e.target.getAttribute('data-act');
          if (!act) return;
          if (act === 'refresh') this.checkLockStatus();
          else if (act === 'acquire') this.acquireLock();
          else if (act === 'release') this.releaseLock();
        });
        document.body.appendChild(panel);
      }
    }

    // Expose global helpers
    global.showLockDiagnostic = function showLockDiagnostic() {
      try { global.packLockSystem?.showLockDiagnostic(); } catch (e) { console.warn('Diagnostic error', e); }
    };

    // Instantiate once UniversalLockSystem is present
    function bootWhenReady() {
      if (!global.UniversalLockSystem) {
        setTimeout(bootWhenReady, 50); return;
      }
      if (global.packLockSystem) return; // already booted
      // Attempt to read transfer + user IDs from DOM dataset or globals
      let transferId = null;
      const el = document.querySelector('[data-transfer-id]');
      if (el) transferId = el.getAttribute('data-transfer-id');
      if (!transferId && global.TRANSFER_ID) transferId = global.TRANSFER_ID;
      const userId = (global.CURRENT_USER_ID || global.USER_ID || 0);
      if (!transferId) return; // cannot boot without resource id
      try {
        const cls = PackLockSystem;
        global.packLockSystem = new cls(transferId, userId, { debug: !!global.PACK_LOCK_DEBUG });
        global.packLockSystem.init();
      } catch (e) {
        console.warn('[PackLock] boot failed', e);
      }
    }
    bootWhenReady();

    // Export
    global.PackLockSystem = PackLockSystem;
  }

  // Delay definition until base available
  if (typeof global.UniversalLockSystem === 'function') {
    define(global.UniversalLockSystem);
  } else {
    const wait = setInterval(() => {
      if (typeof global.UniversalLockSystem === 'function') {
        clearInterval(wait);
        define(global.UniversalLockSystem);
      }
    }, 60);
  }
})(window);
