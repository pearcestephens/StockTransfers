(function () {
  'use strict';
  var overlay = document.getElementById('consignmentOverlay');
  if (!overlay) return;

  var statusWrap = overlay.querySelector('.consign-status');
  var stateText  = document.getElementById('consignStateText');
  var metaEl     = document.getElementById('consignMeta');
  var closeBtn   = document.getElementById('consignCloseBtn');
  var retryBtn   = document.getElementById('consignRetryBtn');
  var cancelBtn  = document.getElementById('consignCancelBtn');

  var VEND_ENDPOINT = '/modules/transfers/stock/api/consignment.create.php';
  var STORE_ENDPOINT = '/modules/transfers/stock/api/consignment.store.php';
  var AUDIT_ENDPOINT = '/modules/transfers/stock/api/consignment.audit.php';
  var lastPayload = null, lastRef = null, aborted = false, ctrl = null;

  function show()  { overlay.classList.remove('hidden'); }
  function hide()  { overlay.classList.add('hidden'); }
  function mode(m) { statusWrap.classList.remove('working','success','error'); statusWrap.classList.add(m); }
  function setState(m, txt, meta) { mode(m); if (txt) stateText.textContent = txt; if (meta !== undefined) metaEl.textContent = meta || ''; }
  function ref() { return 'PL-' + Date.now().toString(36).toUpperCase() + '-' + Math.random().toString(36).slice(2,8).toUpperCase(); }
  function idemKey(obj) { try { return 'consign-' + btoa(unescape(encodeURIComponent(JSON.stringify(obj)))).slice(0, 22); } catch (_) { return 'consign-' + Date.now().toString(36); } }
  function errMsg(j, http) {
    if (!j) return 'HTTP ' + http;
    if (typeof j.error === 'string') return j.error;
    if (j.error && j.error.message) return j.error.message;
    if (Array.isArray(j.errors) && j.errors.length) return j.errors[0].message || j.errors[0].code || 'Error';
    return j.message || ('HTTP ' + http);
  }

  async function createConsignment(input) {
    lastPayload = input;
    aborted = false;
    lastRef = ref();

    show(); setState('working', 'Creating in Vend…', 'PipelineRef ' + lastRef);
    retryBtn.style.display = 'none';
    closeBtn.style.display = 'none';
    if (cancelBtn) cancelBtn.style.display = 'inline-block';

    window.PulseRail && window.PulseRail.setStatus && window.PulseRail.setStatus('amber', 'Creating consignment…');

    ctrl && ctrl.abort(); ctrl = ('AbortController' in window) ? new AbortController() : null;
    var headers = { 'Content-Type': 'application/json', 'X-Idempotency-Key': idemKey(input), 'X-Pipeline-Ref': lastRef };
    var started = performance.now(), http = 0, payload = null, ok = false, text = '';

    try {
      var r = await fetch(VEND_ENDPOINT, { method: 'POST', headers: headers, body: JSON.stringify(input), signal: ctrl ? ctrl.signal : undefined, cache: 'no-store' });
      http = r.status; text = await r.text();
      try { payload = text ? JSON.parse(text) : {}; } catch (_) { payload = {}; }
      ok = r.ok && payload && (payload.success === true || payload.ok === true);
    } catch (e) {
      if (e && e.name === 'AbortError') return fail('Cancelled by user', 'cancelled');
      return fail('Network error');
    }

    var ms = Math.round(performance.now() - started);
    try { fetch(AUDIT_ENDPOINT, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'consignment.create', ok: !!ok, ms: ms, idem: headers['X-Idempotency-Key'], pipeline_ref: lastRef, http: http, vend_error: ok ? undefined : errMsg(payload, http).slice(0, 256) }) }).catch(function(){}); } catch (_){}

    if (!ok) return fail(errMsg(payload, http));

    var vendId = payload.id || payload.uuid || payload.consignment_id || null;
    var vendNo = payload.number || payload.reference || vendId || '';
    setState('success', 'Consignment Created', 'Vend #' + vendNo + ' · ' + ms + 'ms');
    window.PulseRail && window.PulseRail.setStatus && window.PulseRail.setStatus('green', 'Consignment created');
    if (cancelBtn) cancelBtn.style.display = 'none';
    retryBtn.style.display = 'none'; closeBtn.style.display = 'inline-block';

    // store linkage for dashboard/history
    var txId = (window.DISPATCH_BOOT && (window.DISPATCH_BOOT.transferId || window.DISPATCH_BOOT.transfer_id)) || null;
    if (txId) {
      try { fetch(STORE_ENDPOINT, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ transfer_id: txId, vend_id: vendId, vend_number: vendNo, pipeline_ref: lastRef, idem: headers['X-Idempotency-Key'] }) }); } catch (_){}
    }
    try { window.onConsignmentCreated && window.onConsignmentCreated({ vendId: vendId, vendNumber: vendNo, pipelineRef: lastRef }); } catch (_){}
  }

  function fail(msg, code) {
    window.CISGuard && window.CISGuard.fatal && window.CISGuard.fatal(msg || 'Vend create failed');
    setState('error', code === 'cancelled' ? 'Cancelled' : 'Creation Failed', msg || 'Error');
    if (cancelBtn) cancelBtn.style.display = 'none';
    retryBtn.style.display = 'inline-block';
    closeBtn.style.display = 'inline-block';
  }

  closeBtn && closeBtn.addEventListener('click', hide);
  retryBtn && retryBtn.addEventListener('click', function () { lastPayload && createConsignment(lastPayload); });
  cancelBtn && cancelBtn.addEventListener('click', function () {
    if (!ctrl) return; aborted = true; try { ctrl.abort(); } catch(_) {}
    fail('Cancelled by user', 'cancelled');
  });

  // Global launcher
  window.launchConsignmentCreate = function (formData) {
    // expects: { reference, sourceOutletId, destOutletId, lines: [{sku, qty}], note }
    var tx = (window.DISPATCH_BOOT && (window.DISPATCH_BOOT.transferId || window.DISPATCH_BOOT.transfer_id)) || null;
    var manualRows = (window.getManualTracking && window.getManualTracking()) || [];
    var manualCodes = (window.getManualTrackingNumbers && window.getManualTrackingNumbers()) || [];
    var payload = {
      external_ref: formData.reference || ('REF-' + Date.now().toString(36)),
      source_outlet_id: String(formData.sourceOutletId || ''),
      destination_outlet_id: String(formData.destOutletId || ''),
      products: (formData.lines || []).map(function (l) { return { sku: l.sku, quantity: +l.qty }; }),
      note: formData.note || '',
      manual_tracking: manualRows,
      tracking_numbers: manualCodes,
      transfer_id: tx
    };
    createConsignment(payload);
  };
})();
