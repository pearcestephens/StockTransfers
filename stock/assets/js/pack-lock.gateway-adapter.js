/* Lightweight adapter to route PackLockSystem calls through lock_gateway.php */
(function () {
  'use strict';
  const GW = '/modules/transfers/stock/api/lock_gateway.php';

  function callGW(action, params = {}, method = 'POST') {
    const isGet = method.toUpperCase() === 'GET';
    const qs = new URLSearchParams(Object.assign({}, isGet ? params : { action })).toString();
    const url = isGet ? `${GW}?action=${encodeURIComponent(action)}&${qs}` : `${GW}?action=${encodeURIComponent(action)}`;
    const init = {
      method: method.toUpperCase(),
      credentials: 'include',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    };
    if (!isGet) {
      const body = new URLSearchParams(params);
      init.headers['Content-Type'] = 'application/x-www-form-urlencoded';
      init.body = body.toString();
    }
    return fetch(url, init).then(r => r.json());
  }

  function installOn(instance) {
    if (!instance) return;
    instance.api = { gateway: GW }; // mark

    instance.checkLockStatus = async function () {
      const r = await callGW('status', { transfer_id: this.resourceId }, 'GET');
      if (r && r.success && r.data) {
        try { this.updateLockStatus(r.data); } catch (_) {}
        return { success: true, data: r.data };
      }
      return { success: false, error: r && r.error };
    };

    instance.acquireLock = async function (fingerprint = null) {
      const r = await callGW('acquire', { transfer_id: this.resourceId, fingerprint }, 'POST');
      return r;
    };

    instance.releaseLock = async function () {
      const r = await callGW('release', { transfer_id: this.resourceId }, 'POST');
      if (r.success) { try { this.updateLockStatus({ has_lock: false, is_locked: false, is_locked_by_other: false }); } catch (_) {} }
      return r;
    };

    instance.startHeartbeat = function () {
      if (this.heartbeatTimer) clearInterval(this.heartbeatTimer);
      this.heartbeatTimer = setInterval(() => {
        if (!this.lockStatus?.has_lock) return;
        callGW('heartbeat', { transfer_id: this.resourceId });
      }, 90000);
    };

    instance.requestOwnership = async function (message = 'Requesting access') {
      return callGW('request_start', { transfer_id: this.resourceId, message }, 'POST');
    };

    instance.decideRequest = async function (requestId, accept) {
      const decision = accept ? 'grant' : 'decline';
      return callGW('request_decide', { transfer_id: this.resourceId, decision }, 'POST');
    };

    // Optional: polling snapshot for UIs that don't use SSE
    instance.pollRequestState = async function () {
      return callGW('request_state', { transfer_id: this.resourceId }, 'GET');
    };
  }

  // attach after pack-lock.js instantiates
  const boot = () => {
    if (window.packLockSystem) installOn(window.packLockSystem);
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
