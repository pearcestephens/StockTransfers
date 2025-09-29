/* global window, document, fetch */
(() => {
  const BOOT = window.DISPATCH_BOOT || {};
  const PACK_ONLY = !!(BOOT && BOOT.modes && BOOT.modes.pack_only);
  const SHOW_COURIER_DETAIL = Object.prototype.hasOwnProperty.call(BOOT.ui || {}, 'showCourierDetail')
    ? !!BOOT.ui.showCourierDetail
    : true;
  const resolvedUserName = (BOOT.currentUser?.name ?? '').toString().trim();
  const CURRENT_USER = {
    id: Number(BOOT.currentUser?.id ?? 0) || 0,
    name: resolvedUserName || 'You',
  };
  const parseTimelineTs = value => {
    if (typeof value === 'number' && Number.isFinite(value)) return value;
    const date = value ? new Date(value) : null;
    const ts = date instanceof Date ? date.getTime() : Number.NaN;
    return Number.isNaN(ts) ? Date.now() : ts;
  };
  const timelineFromBoot = Array.isArray(BOOT.timeline)
    ? BOOT.timeline.map(entry => ({
        scope: (entry && entry.scope) ? String(entry.scope) : 'note',
        text: (entry && entry.text) ? String(entry.text) : '',
        ts: parseTimelineTs(entry?.ts),
        user: entry?.user ? String(entry.user) : null,
        note_id: entry?.id ?? null,
      }))
    : [];
  const MODE_LABELS = {
    PACKED_NOT_SENT: 'Packed (no dispatch)',
    COURIER_MANUAL_NZC: 'NZ Couriers (Manual)',
    COURIER_MANUAL_NZP: 'NZ Post (Manual)',
    PICKUP: 'Pickup / Third-party',
    INTERNAL_DRIVE: 'Internal Drive',
    DEPOT_DROP: 'Depot Drop-off',
    RECEIVE_ONLY: 'Receive Only',
  };
  const $ = (s, r=document) => r.querySelector(s);
  const $$ = (s, r=document) => [...r.querySelectorAll(s)];

  // ------- Transport
  function ajax(method, url, data, timeout=15000, extra){
    const opts = extra || {};
    const ctrl = new AbortController(); const id = setTimeout(()=>ctrl.abort(), timeout);
    const headers = {
      'Content-Type':'application/json',
      'X-API-Key': BOOT.tokens?.apiKey || '',
      'X-NZPost-Token': BOOT.tokens?.nzPost || '',
      'X-GSS-Token': BOOT.tokens?.gss || '',
      'X-Transfer-ID': BOOT.transferId,
      'X-From-Outlet-ID': BOOT.legacy?.fromOutletId ?? '',
      'X-To-Outlet-ID': BOOT.legacy?.toOutletId ?? '',
      'X-From-Outlet-UUID': BOOT.fromOutletUuid || '',
      'X-To-Outlet-UUID': BOOT.toOutletUuid || '',
    };
    if (opts.headers && typeof opts.headers === 'object') {
      Object.assign(headers, opts.headers);
    }
    const isGET = method === 'GET';
    const u = isGET && data ? url + (url.includes('?')?'&':'?') + new URLSearchParams(data) : url;
    const body = isGET ? null : (typeof opts.rawBody === 'string' ? opts.rawBody : JSON.stringify(data || {}));
    return fetch(u, {method, headers, body, signal: ctrl.signal})
      .then(async r => { const ct=r.headers.get('content-type')||''; const p = ct.includes('json') ? await r.json() : await r.text(); clearTimeout(id); if(!r.ok){ const e=new Error((p&&p.error)||('HTTP '+r.status)); e.response=p; throw e; } return p; });
  } 

  // ------- State
  const PRESETS = {
    satchel:[
      {id:'nzp_s',name:'NZ Post Satchel Small 220×310', w:22,l:31,h:4, kg:0.15, items:0},
      {id:'nzp_m',name:'NZ Post Satchel Medium 280×390', w:28,l:39,h:4, kg:0.20, items:0},
      {id:'nzp_l',name:'NZ Post Satchel Large 335×420', w:33,l:42,h:4, kg:0.25, items:0},
    ],
    box:[
      {id:'vs_m',name:'VS Box Medium 400×300×200', w:30,l:40,h:20, kg:2.0, items:0},
      {id:'vs_l',name:'VS Box Large 450×350×250', w:35,l:45,h:25, kg:2.5, items:0},
      {id:'vs_xl',name:'VS Box XL 500×400×300', w:40,l:50,h:30, kg:3.1, items:0},
    ]
  };
  const state = {
    method: 'courier',
    packMode: 'PACKED_NOT_SENT',
    container: 'satchel',
    packages: [{id:1, ...PRESETS.satchel[1]}],
    selection: null,
    selectionKey: null,
    options: {sig:true, atl:false, age:false, sat:false, reviewedBy:''},
    metrics: {weight:0, items:0, missing:0, count:1, capPer:15},
    printPool: {
      online: !!(BOOT.capabilities?.printPool?.online ?? true),
      onlineCount: Number(BOOT.capabilities?.printPool?.onlineCount ?? 0),
      totalCount: Number(BOOT.capabilities?.printPool?.totalCount ?? 0),
      updatedAt: null,
    },
    rates: {
      loading: false,
      items: [],
      error: null,
      lastHash: null,
      lastError: null,
      lastRecommendedKey: null,
    },
  comments: timelineFromBoot.length
    ? timelineFromBoot
    : [{scope:'system',text:`Transfer #${BOOT.transferId} created`,ts:Date.now(),user:null}],
  trackingRefs: [],
  manualCourier: {preset: '', extra: ''},
    facts: {rural:null, saturday_serviceable:null},
    carriersFallbackUsed: false,
  };

  // ------- Helpers
  const fmt$  = n => '$'+(n||0).toFixed(2);
  const toNumber = (value, fallback = 0) => {
    const num = Number(value);
    return Number.isFinite(num) ? num : fallback;
  };
  const ESCAPE_HTML = {'&': '&amp;', '<': '&lt;', '>': '&gt;'};
  ESCAPE_HTML['"'] = '&quot;';
  ESCAPE_HTML["'"] = '&#39;';
  const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, ch => ESCAPE_HTML[ch] ?? ch);
  const newUuid = () => {
    if (typeof window !== 'undefined' && window.crypto && typeof window.crypto.randomUUID === 'function') {
      return window.crypto.randomUUID();
    }
    const ts = Date.now().toString(16);
    const rand = Math.random().toString(16).slice(2, 10);
    return `uuid-${ts}-${rand}`;
  };
  const showToast = (message, tone='info') => {
    const payload = String(message ?? '');
    if (window.CIS && typeof window.CIS.toast === 'function') {
      window.CIS.toast(payload, tone);
      return;
    }
    if (window.toastr && typeof window.toastr[tone] === 'function') {
      window.toastr[tone](payload);
      return;
    }
    if (tone === 'error') {
      console.error(payload);
    }
    window.alert(payload);
  };
  const hash53 = s => {let h1=0xdeadbeef^s.length,h2=0x41c6ce57^s.length;for(let i=0,ch;i<s.length;i++){ch=s.charCodeAt(i);h1=Math.imul(h1^ch,2654435761);h2=Math.imul(h2^ch,1597334677);}h1=(Math.imul(h1^(h1>>>16),2246822507)^Math.imul(h2^(h2>>>13),3266489909));h2=(Math.imul(h2^(h2>>>16),2246822507)^Math.imul(h1^(h1>>>13),3266489909));return (4294967296*(2097151&(h2>>>0))+(h1>>>0)).toString(36)};
  function useEffectiveCarriers(){
    if (PACK_ONLY) return {};
    const carriers = {...(BOOT.capabilities?.carriers || {})};
    const hasDeclared = Object.values(carriers).some(Boolean);
    if (!hasDeclared) {
      carriers.nzpost = true;
      if (!state.carriersFallbackUsed) {
        state.carriersFallbackUsed = true;
        // Carrier fallback applied silently
      }
    }
    return carriers;
  }
  const readNotes = () => {
    const candidates = ['#notesForTransfer', '#noteText', '#packNotes'];
    for (const selector of candidates) {
      const el = $(selector);
      if (el && typeof el.value === 'string') {
        const text = el.value.trim();
        if (text) return text;
      }
    }
    return '';
  };

  const parseDateTimeLocal = value => {
    const raw = typeof value === 'string' ? value.trim() : '';
    if (!raw) return null;
    const date = new Date(raw);
    if (Number.isNaN(date.getTime())) return null;
    return date.toISOString();
  };

  const parsePositiveInt = value => {
    if (value === null || value === undefined) return null;
    const num = Number.parseInt(String(value), 10);
    if (!Number.isFinite(num) || num <= 0) return null;
    return num;
  };

  const collectPickupPayload = () => {
    const by = ($('#pickupBy')?.value || '').trim();
    const phone = ($('#pickupPhone')?.value || '').trim();
    const timeRaw = ($('#pickupTime')?.value || '').trim();
    const parcelsRaw = ($('#pickupPkgs')?.value || '').trim();
    const notes = ($('#pickupNotes')?.value || '').trim();

    if (!by) throw new Error('Pickup contact is required.');
    if (!phone) throw new Error('Pickup contact phone is required.');
    if (!timeRaw) throw new Error('Pickup time is required.');

    const parcels = parsePositiveInt(parcelsRaw);
    if (parcels === null) throw new Error('Pickup box count must be greater than zero.');

    const timeIso = parseDateTimeLocal(timeRaw);
    if (!timeIso) throw new Error('Pickup time is invalid.');

    const payload = { by, phone, time: timeIso, parcels };
    if (notes) payload.notes = notes;
    return payload;
  };

  const collectInternalPayload = () => {
    const driver = ($('#intCarrier')?.value || '').trim();
    const departRaw = ($('#intDepart')?.value || '').trim();
    const boxesRaw = ($('#intBoxes')?.value || '').trim();
    const notes = ($('#intNotes')?.value || '').trim();

    if (!driver) throw new Error('Internal driver / van is required.');
    if (!departRaw) throw new Error('Internal departure time is required.');

    const departIso = parseDateTimeLocal(departRaw);
    if (!departIso) throw new Error('Internal departure time is invalid.');

    const boxes = parsePositiveInt(boxesRaw);
    if (boxes === null) throw new Error('Internal box count must be greater than zero.');

    const payload = { driver, depart_at: departIso, boxes };
    if (notes) payload.notes = notes;
    return payload;
  };

  const collectDepotPayload = () => {
    const location = ($('#dropLocation')?.value || '').trim();
    const dropRaw = ($('#dropWhen')?.value || '').trim();
    const boxesRaw = ($('#dropBoxes')?.value || '').trim();
    const notes = ($('#dropNotes')?.value || '').trim();

    if (!location) throw new Error('Depot location is required.');
    if (!dropRaw) throw new Error('Depot drop-off time is required.');

    const dropIso = parseDateTimeLocal(dropRaw);
    if (!dropIso) throw new Error('Depot drop-off time is invalid.');

    const boxes = parsePositiveInt(boxesRaw);
    if (boxes === null) throw new Error('Depot drop-off box count must be greater than zero.');

    const payload = { location, drop_at: dropIso, boxes };
    if (notes) payload.notes = notes;
    return payload;
  };

  const collectModeSections = mode => {
    const result = { pickup: null, internal: null, depot: null };
    switch (mode) {
      case 'PICKUP':
        result.pickup = collectPickupPayload();
        break;
      case 'INTERNAL_DRIVE':
        result.internal = collectInternalPayload();
        break;
      case 'DEPOT_DROP':
        result.depot = collectDepotPayload();
        break;
      default:
        break;
    }
    return result;
  };
  function collectParcelsForPayload(){
    const rows = [];
    const parcels = Array.isArray(state.packages) ? state.packages : [];
    parcels.forEach((pkg, index) => {
      if (!pkg) return;
      const lengthCm = toNumber(pkg.l ?? pkg.length ?? pkg.length_cm, 0);
      const widthCm = toNumber(pkg.w ?? pkg.width ?? pkg.width_cm, 0);
      const heightCm = toNumber(pkg.h ?? pkg.height ?? pkg.height_cm, 0);
      const weightKg = toNumber(pkg.kg ?? pkg.weight_kg, 0);
      rows.push({
        sequence: index + 1,
        name: String(pkg.name ?? `Parcel ${index + 1}`),
        length_mm: lengthCm > 0 ? Math.round(lengthCm * 10) : null,
        width_mm: widthCm > 0 ? Math.round(widthCm * 10) : null,
        height_mm: heightCm > 0 ? Math.round(heightCm * 10) : null,
        weight_kg: weightKg > 0 ? Number(weightKg.toFixed(3)) : null,
        estimated: !!pkg.estimated,
        notes: typeof pkg.notes === 'string' && pkg.notes.trim() !== '' ? pkg.notes.trim() : null,
      });
    });
    return rows;
  }
  function buildPackSendPayload(mode, sendNow, idempotencyKey){
    if (!BOOT.fromOutletUuid || !BOOT.toOutletUuid) {
      throw new Error('Missing outlet UUIDs in boot payload.');
    }

    const parcels = collectParcelsForPayload();
    const totals = {};
    if (state.metrics && Number.isFinite(state.metrics.weight) && state.metrics.weight > 0) {
      totals.total_weight_kg = Number(state.metrics.weight.toFixed(3));
    }
    if (state.metrics && Number.isFinite(state.metrics.count) && state.metrics.count > 0) {
      totals.box_count = state.metrics.count;
    }

    const effectiveSendNow = mode === 'PACKED_NOT_SENT' ? false : !!sendNow;

    const payload = {
      idempotency_key: idempotencyKey,
      mode,
      send_now: effectiveSendNow,
      transfer: {
        id: String(BOOT.transferId ?? ''),
        from_outlet_uuid: BOOT.fromOutletUuid,
        to_outlet_uuid: BOOT.toOutletUuid,
      },
      totals: totals,
    };

    if (parcels.length) {
      payload.parcels = parcels.map(row => ({
        sequence: row.sequence,
        name: row.name,
        weight_kg: row.weight_kg,
        length_mm: row.length_mm,
        width_mm: row.width_mm,
        height_mm: row.height_mm,
        estimated: row.estimated,
        notes: row.notes,
      }));
    }

    if (!Object.keys(payload.totals).length) {
      delete payload.totals;
    }

    const notes = readNotes();
    if (notes) {
      payload.transfer.notes = notes;
      payload.notes = notes;
    }

    const modeSections = collectModeSections(mode);
    if (modeSections.pickup) {
      payload.pickup = modeSections.pickup;
    }
    if (modeSections.internal) {
      payload.internal = modeSections.internal;
    }
    if (modeSections.depot) {
      payload.depot = modeSections.depot;
    }

    return payload;
  }
  const rateKey = rate => {
    if (!rate) return '';
    const carrier = (rate.carrier_code ?? rate.carrier ?? '').toString().toLowerCase();
    const service = (rate.service_code ?? rate.service ?? '').toString().toLowerCase();
    const pack    = (rate.package_code ?? rate.package ?? '').toString().toLowerCase();
    return `${carrier}::${service}::${pack}`;
  };

  const FEED_SKIP_TITLES = new Set([
    'mode changed',
    'container set',
    'parcel added',
    'parcel copied',
    'parcel removed',
    'auto-assign complete',
    'rate recommended',
    'rate selected',
    'print blocked',
    'auto-planned',
    'carrier fallback',
    'rates failed',
    'address facts failed',
  ]);
  const FEED_ALLOWED_SCOPES = new Set(['shipment', 'system', 'system:error', 'note', 'error', 'fail']);
  function setSelection(rate, source = 'user'){
    if (!rate){
      state.selection = null;
      state.selectionKey = null;
      renderSummary();
      return;
    }

    const totalIncl = toNumber(rate.total_incl_gst ?? rate.total ?? 0, 0);
    const total = toNumber(rate.total ?? totalIncl ?? 0, totalIncl ?? 0);
    const carrierName = (rate.carrier_name ?? rate.carrier ?? '').toString() || (rate.carrier_code ?? '').toString().toUpperCase();
    const serviceName = (rate.service_name ?? rate.service ?? '').toString() || (rate.service_code ?? '').toString();
    const packageName = (rate.package_name ?? rate.package ?? '').toString() || '';

    state.selection = {
      carrier: (rate.carrier_code ?? rate.carrier ?? '').toString(),
      carrierCode: (rate.carrier_code ?? rate.carrier ?? '').toString(),
      carrierName,
      carrier_name: carrierName,
      service: (rate.service_code ?? rate.service ?? '').toString(),
      serviceCode: (rate.service_code ?? rate.service ?? '').toString(),
      serviceName,
      service_name: serviceName,
      package_code: rate.package_code ?? rate.package ?? null,
      packageCode: rate.package_code ?? rate.package ?? null,
      package_name: packageName || null,
      packageName: packageName || null,
      eta: (rate.eta ?? '').toString(),
      total,
      total_incl_gst: totalIncl > 0 ? totalIncl : total,
      incl_gst: rate.incl_gst !== false,
      recommended: source === 'auto',
      source,
    };
    state.selectionKey = rateKey(rate);
    renderSummary();
  }

  function log(scope, title, detail = '', meta){
    const normalizedTitle = String(title ?? '').trim().toLowerCase();
    if (FEED_SKIP_TITLES.has(normalizedTitle)) {
      return;
    }

    const baseScope = String(scope ?? '').split(':')[0];
    if (!FEED_ALLOWED_SCOPES.has(baseScope) && !(baseScope === 'system' && normalizedTitle.includes('error'))) {
      return;
    }

    const detailText = detail ? `: ${detail}` : '';
    const entry = {scope, text:`${title}${detailText}`, ts:Date.now()};
    if (meta && typeof meta === 'object') {
      Object.assign(entry, meta);
    }
    state.comments.push(entry);
    if (state.comments.length > 60) {
      state.comments.splice(0, state.comments.length - 60);
    }
    renderFeed();
  }

  async function submitPackOnly(){
    if (!BOOT.endpoints || !BOOT.endpoints.pack_send) {
      showToast('Pack/send endpoint unavailable', 'error');
      return;
    }

    const sendToggle = $('#packSendNowToggle');
    const sendNow = sendToggle ? !!sendToggle.checked : true;
    const modeSelect = $('#packModeSelect');
    const mode = modeSelect ? modeSelect.value : (state.packMode || 'PACKED_NOT_SENT');

    if (!mode) {
      showToast('Select a mode before submitting.', 'warning');
      return;
    }
    state.packMode = mode;

    const statusEl = $('#packOnlyStatus');
    if (statusEl) {
      statusEl.textContent = 'Submitting…';
      statusEl.classList.remove('text-danger', 'text-success', 'text-warning');
      statusEl.classList.add('text-muted');
    }

    const idempotencyKey = `pack-${BOOT.transferId}-${Date.now()}-${Math.random().toString(16).slice(2, 10)}`;
    const button = $('#packOnlyBtn');
    let payload;
    try {
      payload = buildPackSendPayload(mode, sendNow, idempotencyKey);
      if (button) button.disabled = true;
    } catch (err) {
      const msg = err?.message || 'Unable to build payload.';
      if (statusEl) {
        statusEl.textContent = msg;
        statusEl.classList.remove('text-muted');
        statusEl.classList.add('text-danger');
      }
      if (button) button.disabled = false;
      showToast(msg, 'error');
      return;
    }

    try {
      const response = await ajax('POST', BOOT.endpoints.pack_send, payload, 20000, {
        headers: {
          'Idempotency-Key': idempotencyKey,
          'X-Request-ID': newUuid(),
        },
      });

      if (!response || typeof response !== 'object') {
        throw new Error('Empty response from pack/send endpoint.');
      }

      if (!response.ok) {
        const message = response.error?.message || 'Pack/send rejected.';
        if (statusEl) {
          statusEl.textContent = message;
          statusEl.classList.remove('text-muted');
          statusEl.classList.add('text-danger');
        }
        showToast(message, 'error');
        log('system:error', 'Pack/send failed', message);
        if (button) button.disabled = false;
        return;
      }

      const warnings = Array.isArray(response.warnings) ? response.warnings.filter(Boolean) : [];
      const successMsg = sendNow ? 'Packed & marked in transit' : 'Packed (not dispatched)';
      if (statusEl) {
        statusEl.textContent = warnings.length ? warnings.join(' • ') : successMsg;
        statusEl.classList.remove('text-muted');
        statusEl.classList.add(warnings.length ? 'text-warning' : 'text-success');
      }

      showToast(successMsg, warnings.length ? 'warning' : 'success');
      log('shipment', successMsg, `Shipment ${response.data?.shipment_id ?? ''}`);

      const redirectTarget = response.data?.redirect || BOOT.urls?.after_pack || '/transfers';
      window.setTimeout(() => { window.location.assign(redirectTarget); }, 1200);
    } catch (err) {
      const message = err?.message || 'Unable to submit pack/send request.';
      if (statusEl) {
        statusEl.textContent = message;
        statusEl.classList.remove('text-muted');
        statusEl.classList.add('text-danger');
      }
      showToast(message, 'error');
      log('system:error', 'Pack/send failed', message);
      if (button) button.disabled = false;
    }
  }

  function updatePackOnlyButtonLabel(button, sendNow){
    if (!button) return;
    const labelNode = button.querySelector('.btn-label') || button;
    labelNode.textContent = sendNow ? 'Mark as Packed & Send' : 'Mark as Packed';
  }

  function syncPackOnlyPanels(mode){
    const showPanel = (selector, shouldShow) => {
      const node = $(selector);
      if (!node) return;
      if (shouldShow) {
        node.hidden = false;
        node.classList.remove('d-none');
      } else {
        node.hidden = true;
        node.classList.add('d-none');
      }
    };

    showPanel('#blkPickup', mode === 'PICKUP');
    showPanel('#blkInternal', mode === 'INTERNAL_DRIVE');
    showPanel('#blkDropoff', mode === 'DEPOT_DROP');
    showPanel('#blkManual', false);

    const toggle = $('#packSendNowToggle');
    const button = $('#packOnlyBtn');
    if (toggle) {
      if (mode === 'PACKED_NOT_SENT') {
        toggle.checked = false;
        toggle.disabled = true;
      } else {
        const previousDisabled = toggle.disabled;
        toggle.disabled = false;
        if (mode === 'PICKUP' && (previousDisabled || !toggle.checked)) {
          toggle.checked = true;
        }
      }
      updatePackOnlyButtonLabel(button, toggle.checked);
    } else {
      updatePackOnlyButtonLabel(button, true);
    }
  }

  function applyPackOnlyModeUI(){
    const wizard = $('#ship-wizard');
    if (wizard) {
      wizard.classList.add('d-none');
      wizard.setAttribute('aria-hidden', 'true');
    }

    const packPanel = $('#packOnlyPanel');
    if (packPanel) {
      packPanel.classList.remove('d-none');
      packPanel.setAttribute('aria-hidden', 'false');
    }

    const trackingNode = $('#tracking-items');
    if (trackingNode && typeof trackingNode.closest === 'function') {
      const manualTrackingCol = trackingNode.closest('.col-md-6');
      if (manualTrackingCol) {
        manualTrackingCol.classList.add('d-none');
        manualTrackingCol.setAttribute('aria-hidden', 'true');
      }
    }

    const printPoolCard = $('#printPoolCard');
    if (printPoolCard) {
      printPoolCard.classList.add('d-none');
    }

    const modeSelect = $('#packModeSelect');
    const availableModesRaw = Array.isArray(BOOT.capabilities?.modes) ? BOOT.capabilities.modes.slice() : [];
    const packOnlyOrder = ['PACKED_NOT_SENT', 'PICKUP', 'INTERNAL_DRIVE', 'DEPOT_DROP'];
    const allowedSet = new Set(packOnlyOrder);
    let availableModes = availableModesRaw.filter(mode => allowedSet.has(mode));
    if (!availableModes.length) {
      availableModes = packOnlyOrder;
    } else {
      availableModes = packOnlyOrder.filter(mode => availableModes.includes(mode));
      if (!availableModes.length) {
        availableModes = ['PACKED_NOT_SENT'];
      }
    }

    if (modeSelect) {
      modeSelect.innerHTML = availableModes.map(mode => {
        const label = MODE_LABELS[mode] || mode.replace(/_/g, ' ');
        return `<option value="${mode}">${label}</option>`;
      }).join('');
    }

    let defaultMode = availableModes.includes('COURIER_MANUAL_NZC') ? 'COURIER_MANUAL_NZC' : availableModes[0];
    if (state.packMode && availableModes.includes(state.packMode)) {
      defaultMode = state.packMode;
    }
    state.packMode = defaultMode;
    if (modeSelect) {
      modeSelect.value = defaultMode;
    }

    syncPackOnlyPanels(defaultMode);

    const statusEl = $('#packOnlyStatus');
    if (statusEl && !statusEl.textContent) {
      statusEl.textContent = 'Review transfer details, then mark this transfer as packed.';
    }
  }

  function wirePackOnly(){
    state.method = 'pack_only';
    applyPackOnlyModeUI();

    const button = $('#packOnlyBtn');
    const toggle = $('#packSendNowToggle');

    if (toggle) {
      const handleToggle = () => updatePackOnlyButtonLabel(button, toggle.checked);
      toggle.addEventListener('change', handleToggle);
      handleToggle();
    } else {
      updatePackOnlyButtonLabel(button, true);
    }

    const modeSelect = $('#packModeSelect');
    if (modeSelect) {
      modeSelect.addEventListener('change', () => {
        state.packMode = modeSelect.value;
        syncPackOnlyPanels(state.packMode);
      });
    }

    if (button) {
      button.addEventListener('click', submitPackOnly);
    }

    const hideSelectors = [
      '#ratesList',
      '#sw-rates',
      '#printPoolMeta',
      '#printPoolText',
      '#printPoolDot',
      '#btnSatchel',
      '#btnBox',
      '.js-add',
      '.js-copy',
      '.js-clear',
      '.js-auto',
      '#uiBlock',
      '#blkManual',
      '#blkCourier',
      '.tnav',
    ];

    hideSelectors.forEach(selector => {
      $$(selector).forEach(node => {
        if (!node) return;
        if ('hidden' in node) node.hidden = true;
        if (node.classList) node.classList.add('d-none');
      });
    });

    renderRates();
  }

  function primeMetricsFromBoot(){
    const metrics = BOOT?.metrics;
    if (!metrics) return;
    const weightKg = toNumber(metrics.total_weight_kg ?? metrics.total_weight ?? 0, 0);
    const totalItems = Math.max(0, Math.trunc(toNumber(metrics.total_items ?? 0, 0)));
    if (Number.isFinite(weightKg)) state.metrics.weight = weightKg;
    if (Number.isFinite(totalItems)) state.metrics.items = totalItems;
  }

  function applyBootAutoplan(){
    const plan = BOOT?.autoplan;
    if (!plan || plan.shouldHydrate === false || !Array.isArray(plan.packages) || plan.packages.length === 0) {
      primeMetricsFromBoot();
      return false;
    }

    const container = plan.container === 'box' ? 'box' : 'satchel';
    state.container = container;

    const presetList = PRESETS[container] || [];
    const fallbackPreset = presetList[0] || PRESETS.satchel[1];
    const packages = [];

    plan.packages.forEach((pkg, index) => {
      const preset = presetList.find(p => p.id === pkg.preset_id) || fallbackPreset;
      const dims = pkg.dimensions || {};
      const weightKg = Math.max(toNumber(pkg.weight_kg ?? pkg.goods_weight_kg, preset.kg), preset.kg);
      const items = Math.max(0, Math.trunc(toNumber(pkg.items ?? 0, 0)));

      packages.push({
        id: preset.id,
        name: preset.name,
        w: toNumber(dims.w ?? pkg.width_cm ?? pkg.w, preset.w),
        l: toNumber(dims.l ?? pkg.length_cm ?? pkg.l, preset.l),
        h: toNumber(dims.h ?? pkg.height_cm ?? pkg.h, preset.h),
        kg: weightKg,
        items,
      });
    });

    if (!packages.length) {
      primeMetricsFromBoot();
      return false;
    }

    state.packages = packages;
    state.selection = null;

    if (typeof plan.cap_kg === 'number' && Number.isFinite(plan.cap_kg) && plan.cap_kg > 0) {
      state.metrics.capPer = plan.cap_kg;
    } else {
      state.metrics.capPer = container === 'box' ? 25 : 15;
    }

    const totalWeight = toNumber(plan.total_weight_kg ?? plan.total_weight ?? state.metrics.weight ?? 0, 0);
    const totalItems = Math.max(0, Math.trunc(toNumber(plan.total_items ?? state.metrics.items ?? 0, 0)));

    if (Number.isFinite(totalWeight)) state.metrics.weight = totalWeight;
    if (Number.isFinite(totalItems)) state.metrics.items = totalItems;
    state.metrics.count = packages.length;

    const summaryWeight = Number.isFinite(totalWeight) ? totalWeight : 0;
    // Auto-plan completed silently - no timeline spam

    $('#btnSatchel')?.setAttribute('aria-pressed', container === 'satchel' ? 'true' : 'false');
    $('#btnBox')    ?.setAttribute('aria-pressed', container === 'box' ? 'true' : 'false');

    return true;
  }

  // ------- Renderers
  function loadPresetOptions(){
    const sel = $('#preset'); sel.innerHTML='';
    PRESETS[state.container].forEach(p => { const o=document.createElement('option'); o.value=p.id; o.textContent=p.name; sel.appendChild(o); });
  }
  function renderPackages(){
    const body = $('#pkgBody'); body.innerHTML='';
    state.packages.forEach((p,i) => {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${i+1}</td>
        <td>${p.name}</td>
        <td>
          <input class="pn" type="number" step="1" min="1" value="${p.w}" data-i="${i}" data-k="w"> ×
          <input class="pn" type="number" step="1" min="1" value="${p.l}" data-i="${i}" data-k="l"> ×
          <input class="pn" type="number" step="1" min="1" value="${p.h}" data-i="${i}" data-k="h">
        </td>
        <td><input class="pw" type="number" step="0.01" min="0" value="${p.kg}" data-i="${i}" data-k="kg"> kg</td>
        <td><input class="pi" type="number" step="1" min="0" value="${p.items}" data-i="${i}" data-k="items"></td>
        <td class="num"><button class="btn icon" data-del="${i}" title="Remove">×</button></td>`;
      body.appendChild(tr);
    });
    $('#slipBox').textContent = state.packages.length?1:0;
    $('#slipTotal').value     = state.packages.length || 1;
    renderMeters();
    updateShipmentStats();
    renderSummary();
    renderFeed();
    wirePkgInputs();
  }
  function renderMeters(){
    const cap = state.container==='satchel' ? 15 : 25;
    const wrap = $('#meters'); wrap.innerHTML='';
    state.packages.forEach((p,i)=>{
      const pct = Math.min(100, Math.round((p.kg/cap)*100));
      const row = document.createElement('div');
      row.innerHTML = `<div class="small">Parcel ${i+1} · ${p.kg.toFixed(1)} kg / ${cap.toFixed(1)} kg</div>
                       <div class="meter"><i style="width:${pct}%"></i></div>`;
      wrap.appendChild(row);
    });
  }
  function updateShipmentStats(){
    const parcels = state.packages || [];
    const capPer = state.container === 'satchel' ? 15 : 25;
    let totalWeight = 0;
    let totalItems = 0;
    let missing = 0;
    parcels.forEach(p => {
      const kg = Number(p?.kg ?? 0);
      const items = Number(p?.items ?? 0);
      if (!kg || kg <= 0) missing += 1;
      if (Number.isFinite(kg)) totalWeight += kg;
      if (Number.isFinite(items)) totalItems += items;
    });
    const count = parcels.length;

    state.metrics = {weight: totalWeight, items: totalItems, missing, count, capPer};

    const weightText = totalWeight.toFixed(3);
    const totalEl = $('#sw-total');
    if (totalEl) totalEl.textContent = weightText;
    const itemsEl = $('#sw-items');
    if (itemsEl) itemsEl.textContent = totalItems;
    const missingEl = $('#sw-missing');
    if (missingEl) missingEl.textContent = missing;
    const boxesEl = $('#sw-boxes');
    if (boxesEl) boxesEl.textContent = count;
    const capEl = $('#sw-cap');
    if (capEl) capEl.textContent = count ? `${capPer.toFixed(1)}` : '—';
    const summaryWeight = $('#sw-summary-weight');
    if (summaryWeight) summaryWeight.textContent = `${weightText} kg`;
    const summaryPackages = $('#sw-summary-packages');
    if (summaryPackages) {
      summaryPackages.textContent = count ? `${count} pkg${count === 1 ? '' : 's'}` : '0 pkgs';
    }
  }
  function renderRates(){
    const wrap = $('#ratesList');
    if (!wrap) return;

    if (PACK_ONLY) {
      wrap.innerHTML = '';
      return;
    }

    if (state.method !== 'courier') {
      wrap.innerHTML = '';
      return;
    }

  const carriers = useEffectiveCarriers();
  const carriersEnabled = Object.values(carriers).some(Boolean);

    const satOK = !!state.facts.saturday_serviceable;
    const satInput = $('#optSat');
    if (satInput) {
      if (!satOK && satInput.checked) satInput.checked = false;
      satInput.disabled = !satOK;
    }

    if (!carriersEnabled) {
      wrap.innerHTML = '<div class="rate rate-empty">No carriers enabled for this outlet.</div>';
      setSelection(null);
      return;
    }

    if (!state.packages.length) {
      wrap.innerHTML = '<div class="rate rate-empty">Add at least one parcel to view live rates.</div>';
      setSelection(null);
      return;
    }

    if (state.rates.loading) {
      wrap.innerHTML = '<div class="rate rate-empty">Loading live rates…</div>';
      return;
    }

    if (state.rates.error) {
      wrap.innerHTML = `<div class="rate rate-error">${escapeHtml(state.rates.error)}</div>`;
      setSelection(null);
      return;
    }

    const rates = state.rates.items || [];
    if (!rates.length) {
      wrap.innerHTML = '<div class="rate rate-empty">No live rates available.</div>';
      setSelection(null);
      return;
    }

    const recommendedKey = rateKey(rates[0]);
    wrap.innerHTML = '';

    rates.forEach(rate => {
      const key = rateKey(rate);
      const isActive = state.selectionKey === key;
      const isRecommended = key === recommendedKey;
      const carrierCode = (rate.carrier_code || '').toLowerCase();
      const logo = carrierCode === 'nzpost' ? 'nzpost' : (carrierCode === 'nzc' ? 'nzc' : 'generic');
      const logoText = logo === 'nzpost' ? 'NZP' : (logo === 'nzc' ? 'NZC' : 'CR');
      const priceText = fmt$(toNumber(rate.total ?? rate.total_incl_gst ?? 0, 0));
      const carrierLabel = escapeHtml(rate.carrier_name ?? '');
      const serviceLabel = escapeHtml(rate.service_name ?? '');
      const badges = [];
      if (isRecommended) badges.push('<span class="badge" title="Auto-selected best price">Recommended</span>');
      if (!satOK && /sat/i.test(rate.service_name || '')) badges.push('<span class="badge" title="Saturday delivery not serviceable">No Saturday</span>');
      if (rate.package_name) badges.push(`<span class="badge">${escapeHtml(rate.package_name)}</span>`);

      const metaBits = [];
      if ((rate.eta || '').trim() !== '') metaBits.push(`<span>ETA ${escapeHtml(rate.eta)}</span>`);
      metaBits.push(`<span>${rate.incl_gst === false ? 'Excl GST' : 'Incl GST'}</span>`);

      const div = document.createElement('div');
      div.className = 'rate' + (isActive ? ' is-active' : '');
      div.innerHTML = `
        <div class="rhead">
          <div class="rleft">
            <div class="rlogo ${logo}" aria-hidden="true">${logoText}</div>
            <div class="rtitle">${carrierLabel} · ${serviceLabel} ${badges.join(' ')}</div>
          </div>
          <div class="rprice">${priceText}</div>
        </div>
        <div class="rmeta">${metaBits.join(' ')}</div>`;
      div.addEventListener('click', () => {
        setSelection(rate, 'user');
        log('shipment', 'Rate selected', `${rate.carrier_name} · ${rate.service_name} ${priceText}`);
        renderRates();
      });
      wrap.appendChild(div);
    });

    renderSummary();
  }
  function renderSummary(){
    const s = state.selection;
    const carrierText = s ? (s.carrierName || s.carrier_name || s.carrierCode || s.carrier || '—') : '—';
    const serviceText = s ? (s.serviceName || s.service_name || s.serviceCode || s.service || '—') : '—';
    const totalValue = toNumber(s?.total ?? s?.total_incl_gst ?? 0, 0);
    $('#sumCarrier').textContent = carrierText || '—';
    $('#sumService').textContent = serviceText || '—';
    $('#sumTotal').textContent   = s ? fmt$(totalValue) : '$0.00';

    const weight = state.metrics?.weight ?? state.packages.reduce((acc,p)=>acc + (Number(p?.kg ?? 0) || 0),0);
    const weightText = (Number.isFinite(weight)? weight : 0).toFixed(3);
    const summaryWeight = $('#sw-summary-weight');
    if (summaryWeight) summaryWeight.textContent = `${weightText} kg`;
    const count = state.metrics?.count ?? state.packages.length;
    const summaryPackages = $('#sw-summary-packages');
    if (summaryPackages) summaryPackages.textContent = count ? `${count} pkg${count === 1 ? '' : 's'}` : '0 pkgs';
  }
  function renderTrackingRefs(){
    const list = $('#trackingList');
    const emptyState = $('#trackingEmpty');
    if (!list) return;

    list.innerHTML = '';
    if (!Array.isArray(state.trackingRefs) || state.trackingRefs.length === 0) {
      if (emptyState) {
        emptyState.removeAttribute('hidden');
        emptyState.setAttribute('aria-hidden', 'false');
      }
      return;
    }

    if (emptyState) {
      emptyState.setAttribute('hidden', 'true');
      emptyState.setAttribute('aria-hidden', 'true');
    }

    state.trackingRefs.forEach((ref, index) => {
      const item = document.createElement('li');
      item.className = 'tracking-item';
      item.innerHTML = `
        <span class="value">${escapeHtml(ref)}</span>
        <button type="button" class="tracking-remove" aria-label="Remove tracking reference" data-remove-index="${index}">&times;</button>`;
      list.appendChild(item);
    });
  }
  function updateManualCourierUI(){
    const select = $('#manualCourierPreset');
    const status = $('#manualCourierStatus');
    const extraWrap = $('#manualCourierExtraWrap');
    const extraInput = $('#manualCourierExtraDetail');

    const value = (select?.value || '').trim();
    state.manualCourier.preset = value;

    let message = 'Select a manual courier method to confirm the handover.';
    let statusClass = '';
    let requiresDetail = false;

    switch (value) {
      case 'nzpost_manifest':
        message = 'Manifested NZ Post bag confirmed for dispatch.';
        statusClass = 'is-ready';
        break;
      case 'nzpost_counter':
        message = 'Counter drop-off chosen. Ensure docket accompanies the parcels.';
        statusClass = 'is-ready';
        break;
      case 'nzc_pickup':
        message = 'NZ Couriers pick-up logged. Await driver collection.';
        statusClass = 'is-ready';
        break;
      case 'third_party':
        message = 'Third-party courier selected. Record details below.';
        statusClass = 'is-ready';
        requiresDetail = true;
        break;
      default:
        break;
    }

    if (extraWrap) {
      if (requiresDetail) {
        extraWrap.removeAttribute('hidden');
      } else {
        extraWrap.setAttribute('hidden', '');
        if (extraInput) {
          extraInput.value = '';
          state.manualCourier.extra = '';
        }
      }
    }

    if (status) {
      status.classList.remove('is-ready', 'is-error');
      if (statusClass) status.classList.add(statusClass);
      status.innerHTML = `<span class="status-dot"></span><span>${escapeHtml(message)}</span>`;
    }

    const actionBtn = $('#btnPrintPacked');
    if (actionBtn) {
      if (value) {
        actionBtn.textContent = 'Mark as Packed & Sent';
      } else {
        actionBtn.textContent = SHOW_COURIER_DETAIL ? 'Print & Mark Packed' : 'Mark as Packed';
      }
    }
  }
  function collectManualCourierContext(){
    const select = $('#manualCourierPreset');
    const extraInput = $('#manualCourierExtraDetail');
    const value = (select?.value || '').trim();
    const label = select && select.selectedIndex >= 0
      ? (select.options[select.selectedIndex].text || '').trim()
      : '';
    const extraRaw = (extraInput?.value || state.manualCourier.extra || '').trim();
    state.manualCourier.extra = extraRaw;

    return {
      preset: value || null,
      label: label || null,
      extra: extraRaw || null,
      tracking_refs: Array.isArray(state.trackingRefs) ? state.trackingRefs.slice() : [],
    };
  }
  function collectSlipPreviewContext(manualContext){
    const totalBoxesRaw = $('#slipTotal')?.value ?? '';
    const totalBoxes = toNumber(totalBoxesRaw, 1);
    const sequence = toNumber($('#slipBox')?.textContent ?? '', 1) || 1;

    return {
      transfer_id: BOOT.transferId,
      from_label: ($('#slipFrom')?.textContent || '').trim() || (BOOT.fromOutlet || ''),
      to_label: ($('#slipTo')?.textContent || '').trim() || (BOOT.toOutlet || ''),
      box_sequence: sequence,
      box_total: totalBoxes > 0 ? Math.round(totalBoxes) : 1,
      tracking_refs: Array.isArray(state.trackingRefs) ? state.trackingRefs.slice() : [],
      manual_courier: manualContext,
    };
  }
  function renderFeed(){
    const feed=$('#activityFeed'); feed.innerHTML='';
    const items = state.comments.slice().reverse();
    if(!items.length){ feed.innerHTML='<div class="sub">No activity yet.</div>'; return; }
    items.forEach(evt=>{
      const div=document.createElement('div');
      const scopeValue = (evt.scope || 'system').toString();
      div.className='feed-item '+scopeValue.split(':')[0];
      const when=new Date(evt.ts).toLocaleString();
      const scopeSafe = escapeHtml(evt.scope ?? '');
      const userSafe = escapeHtml(evt.user ?? '');
      const metaParts = [];
      if (scopeSafe) metaParts.push(scopeSafe);
      if (userSafe) metaParts.push(userSafe);
      const textSafe = escapeHtml(evt.text ?? '');
      div.innerHTML=`<div class="feed-dot"></div>
        <div><div class="feed-head"><div>${textSafe}</div><div class="feed-meta mono">${when}</div></div>
        <div class="feed-meta">${metaParts.join(' • ')}</div></div>`;
      feed.appendChild(div);
    });
  }

  let ratesTimer = null;
  let ratesRequestId = 0;

  function normalizeRate(row){
    if (!row || typeof row !== 'object') return null;

    const carrierCode = (row.carrier_code ?? row.carrier ?? '').toString().trim();
    const serviceCode = (row.service_code ?? row.service ?? '').toString().trim();
    if (!carrierCode || !serviceCode) return null;

    const parseMoney = value => {
      if (typeof value === 'string') {
        const cleaned = value.replace(/[^0-9.\-]/g, '');
        if (!cleaned || cleaned === '-' || cleaned === '.') return Number.NaN;
        return Number(cleaned);
      }
      return Number(value);
    };

    const rawIncl = row.total_incl_gst ?? row.total ?? 0;
    const rawTotal = row.total ?? rawIncl ?? 0;
    const totalIncl = toNumber(parseMoney(rawIncl), 0);
    const total = toNumber(parseMoney(rawTotal), totalIncl ?? 0);
    if (!Number.isFinite(total) || total <= 0) return null;

    const carrierName = (row.carrier_name ?? row.carrier ?? carrierCode).toString();
    const serviceName = (row.service_name ?? row.service ?? serviceCode).toString();
  const packageCode = (row.package_code ?? row.package ?? '').toString().trim() || null;
  const packageName = (row.package_name ?? row.package ?? '').toString().trim() || null;
    const eta = (row.eta ?? '').toString();

    return {
      carrier_code: carrierCode.toLowerCase(),
      carrier_name: carrierName,
      service_code: serviceCode,
      service_name: serviceName,
      package_code: packageCode,
      package_name: packageName,
      eta,
      total,
      total_incl_gst: totalIncl > 0 ? totalIncl : total,
      incl_gst: row.incl_gst !== false,
    };
  }

  function buildRatesRequest(reason = 'change'){
    const satOption = $('#optSat');
    const satServiceable = !!state.facts.saturday_serviceable;
    const satWanted = !!(satOption && satOption.checked && satServiceable);

    return {
      meta: {
        transfer_id: BOOT.transferId,
        from_outlet_id: BOOT.legacy?.fromOutletId ?? 0,
        to_outlet_id: BOOT.legacy?.toOutletId ?? 0,
        from_outlet_uuid: BOOT.fromOutletUuid || null,
        to_outlet_uuid: BOOT.toOutletUuid || null,
      },
      packages: state.packages.map((pkg, index) => ({
        sequence: index + 1,
        name: String(pkg.name ?? `Parcel ${index + 1}`),
        w: toNumber(pkg.w, 0),
        l: toNumber(pkg.l, 0),
        h: toNumber(pkg.h, 0),
        kg: toNumber(pkg.kg, 0),
        items: Math.max(0, Math.trunc(toNumber(pkg.items, 0))),
      })),
      options: {
        sig: !!$('#optSig')?.checked,
        atl: !!$('#optATL')?.checked,
        age: !!($('#optAge')?.checked),
        sat: satWanted,
      },
      address_facts: {
        rural: !!state.facts.rural,
        saturday_serviceable: satServiceable,
      },
      carriers_enabled: useEffectiveCarriers(),
      reason,
    };
  }

  function scheduleRatesRefresh(reason='change', {force=false} = {}){
    if (PACK_ONLY) {
      state.rates = {
        ...state.rates,
        loading: false,
        items: [],
        error: null,
        lastHash: null,
        lastRecommendedKey: null,
      };
      setSelection(null);
      renderRates();
      return;
    }
    if (state.method !== 'courier') return;
    if (!BOOT.endpoints?.rates) {
      state.rates = {...state.rates, loading:false, items:[], error:'Rates endpoint unavailable', lastHash:null, lastRecommendedKey:null};
      setSelection(null);
      renderRates();
      return;
    }

    const carriers = useEffectiveCarriers();
    if (!Object.values(carriers).some(Boolean)) {
      state.rates = {...state.rates, loading:false, items:[], error:null, lastRecommendedKey:null};
      setSelection(null);
      renderRates();
      return;
    }

    if (!state.packages.length) {
      state.rates = {...state.rates, loading:false, items:[], error:null, lastRecommendedKey:null};
      setSelection(null);
      renderRates();
      return;
    }

    if (ratesTimer) {
      clearTimeout(ratesTimer);
      ratesTimer = null;
    }

    state.rates = {...state.rates, loading:true, error:null};
    renderRates();
    ratesTimer = window.setTimeout(() => loadRates(reason, {force}), 220);
  }

  async function loadRates(reason='change', {force=false} = {}){
    if (ratesTimer) {
      clearTimeout(ratesTimer);
      ratesTimer = null;
    }
    if (PACK_ONLY) return;
    if (state.method !== 'courier') return;
    if (!BOOT.endpoints?.rates) return;
    if (!state.packages.length) return;

    const payload = buildRatesRequest(reason);
    const hash = hash53(JSON.stringify(payload));

    if (!force && state.rates.lastHash === hash && !state.rates.error) {
      state.rates = {...state.rates, loading:false};
      renderRates();
      return;
    }

    state.rates = {...state.rates, loading:true, error:null};
    renderRates();

    ratesRequestId += 1;
    const currentId = ratesRequestId;

    try {
      const response = await ajax('POST', BOOT.endpoints.rates, payload);
      if (currentId !== ratesRequestId) return;

      const rows = Array.isArray(response) ? response : [];
      const normalized = rows.map(normalizeRate).filter(Boolean).sort((a,b)=> (a.total ?? 0) - (b.total ?? 0));

      state.rates = {
        ...state.rates,
        loading: false,
        items: normalized,
        error: null,
        lastHash: hash,
        lastError: null,
      };

      if (!normalized.length) {
        state.rates.lastRecommendedKey = null;
        setSelection(null);
        renderRates();
        return;
      }

      const prevKey = state.selectionKey;
      const recommendedKey = rateKey(normalized[0]);
      const existing = normalized.find(r => rateKey(r) === prevKey);
      if (existing) {
        setSelection(existing, state.selection?.source === 'user' ? 'user' : 'auto');
      } else {
        const best = normalized[0];
        setSelection(best, 'auto');
        if (state.rates.lastRecommendedKey !== recommendedKey) {
          log('shipment','Rate recommended', `${best.carrier_name} · ${best.service_name} ${fmt$(toNumber(best.total,0))} [${reason}]`);
        }
      }
      state.rates.lastRecommendedKey = recommendedKey;

      renderRates();
    } catch (err) {
      if (currentId !== ratesRequestId) return;
      const msg = err?.message ? String(err.message) : 'Unable to load rates';
      const prevError = state.rates.lastError;
      state.rates = {
        ...state.rates,
        loading: false,
        items: [],
        error: msg,
        lastHash: hash,
        lastError: msg,
        lastRecommendedKey: null,
      };
      setSelection(null);
      if (prevError !== msg) {
        // Rate failures logged silently to prevent spam
        console.warn('Rates failed:', msg, `[${reason}]`);
      }
      renderRates();
    }
  }

  // ------- Address facts & Print pool
  async function refreshAddressFacts(){
    const targetUuid = (BOOT.toOutletUuid || '').trim();
    const targetId = Number(BOOT.legacy?.toOutletId ?? 0);
    if ((targetUuid === '' && (!Number.isFinite(targetId) || targetId <= 0))) {
      state.facts = {rural: false, saturday_serviceable: true};
      $('#factRural').textContent = 'No';
      $('#factSat').textContent   = 'Yes';
      scheduleRatesRefresh('address-facts', {force:true});
      return;
    }

    try{
      const params = targetUuid !== '' ? {to_outlet_uuid: targetUuid} : {to_outlet_id: targetId};
      const data = await ajax('GET', BOOT.endpoints.address_facts, params);
      state.facts = {rural: !!data.rural, saturday_serviceable: !!data.saturday_serviceable};
    }catch(_){
      state.facts = {rural:false, saturday_serviceable:true}; // safe fallback
    }
    $('#factRural').textContent = state.facts.rural ? 'Yes' : 'No';
    $('#factSat').textContent   = state.facts.saturday_serviceable ? 'Yes' : 'No';
    scheduleRatesRefresh('address-facts', {force:true});
  }
  function syncPrintPoolUI(){
    const info = state.printPool || {};
    const online = !!info.online;
    const offline = !online;
    const trackingWrap = $('#manualTrackingWrap');
    const allowTracking = state.method === 'courier' && (offline || !SHOW_COURIER_DETAIL);
    if (trackingWrap) {
      if (allowTracking) {
        trackingWrap.removeAttribute('hidden');
        trackingWrap.classList.add('is-active');
        renderTrackingRefs();
      } else {
        trackingWrap.setAttribute('hidden', '');
        trackingWrap.classList.remove('is-active');
      }
    }

    if (PACK_ONLY || !SHOW_COURIER_DETAIL) {
      ['#printPoolDot', '#printPoolText', '#printPoolMeta'].forEach(selector => {
        $$(selector).forEach(node => {
          if (!node) return;
          if ('hidden' in node) node.hidden = true;
          if (node.classList) node.classList.add('d-none');
        });
      });
      if (!SHOW_COURIER_DETAIL) {
        const manual = $('#blkManual');
        if (manual) {
          manual.hidden = true;
          manual.classList.add('d-none');
        }
      }
      return;
    }

    const dot = $('#printPoolDot');
    if(dot){ dot.className = 'dot '+(online?'ok':'err'); }
    const text = $('#printPoolText');
    if(text){ text.textContent = online ? 'Print pool online' : 'Print pool offline'; }
    const meta = $('#printPoolMeta');
    if(meta){
      const total = Number.isFinite(info.totalCount) ? info.totalCount : 0;
      const onlineCount = Number.isFinite(info.onlineCount) ? info.onlineCount : 0;
      meta.textContent = total > 0
        ? `${Math.max(0, onlineCount)} of ${Math.max(0, total)} printers ready`
        : 'Awaiting printer status';
    }
    $('#uiBlock').classList.toggle('show', offline && state.method==='courier');
    const reviewedWrap = $('#reviewedWrap');
    if(reviewedWrap){
      reviewedWrap.style.display = (offline || state.method!=='courier') ? 'block' : 'none';
    }
    const manual = $('#blkManual');
    if(manual){
      const shouldShowManual = SHOW_COURIER_DETAIL && offline && state.method==='courier';
      manual.hidden = !shouldShowManual;
      manual.classList.toggle('d-none', !shouldShowManual);
    }
  }

  async function refreshPrintPool(){
    if (PACK_ONLY) return;
    const url = BOOT.endpoints?.print_pool;
    if(!url){ return; }
    try{
      const payload = BOOT.fromOutletUuid ? {from_outlet_uuid: BOOT.fromOutletUuid} : {from_outlet_id: BOOT.legacy?.fromOutletId ?? 0};
      const r = await ajax('GET', url, payload);
      state.printPool = {
        ...state.printPool,
        online: !!(r.online ?? r.print_pool_online ?? r.ok ?? state.printPool.online),
        onlineCount: Number(r.online_count ?? r.printers_online ?? state.printPool.onlineCount ?? 0),
        totalCount: Number(r.total_count ?? r.printers_total ?? state.printPool.totalCount ?? 0),
        updatedAt: Date.now(),
      };
    }catch(_){
      state.printPool = {...state.printPool, online:false, updatedAt: Date.now()};
    }
    syncPrintPoolUI();
  }

  // ------- Printing
  function payload(markPacked, options = {}){
    const manualContext = collectManualCourierContext();
    const slipContext = collectSlipPreviewContext(manualContext);

    const base = {
      meta: {
        transfer_id: BOOT.transferId,
        from_outlet_id: BOOT.fromOutletId,
        to_outlet_id: BOOT.toOutletId
      },
      reviewed_by: state.options.reviewedBy,
      method: state.method,
      container: state.container,
      options:{ sig:$('#optSig').checked, atl:$('#optATL').checked, age:$('#optAge').checked, sat:$('#optSat').checked },
      address_facts: state.facts,
      selection: state.selection,
      packages: state.packages,
      tracking_refs: Array.isArray(state.trackingRefs) ? state.trackingRefs.slice() : [],
      manual_courier: manualContext,
      slip_preview: slipContext,
      mark_packed: !!markPacked,
      idem: hash53(JSON.stringify(state))
    };

    if (options.slipOnly) {
      base.slip_only = true;
    }

    return base;
  }
  async function doPrint(markPacked, options = {}){
    const slipOnly = !!options.slipOnly;
    if(!slipOnly && state.method==='courier' && SHOW_COURIER_DETAIL && !state.selection){
      // Optionally show a UI warning, but do not alert
      return;
    }
    if(!slipOnly && state.method==='courier' && SHOW_COURIER_DETAIL && !(state.printPool?.online)){
      syncPrintPoolUI();
      // Optionally show a UI warning, but do not alert
      log('system','Print blocked','Print pool offline');
      return;
    }
    // Set up for 80mm receipt printer
    const slip = document.querySelector('.slip');
    if (slip) {
      slip.style.width = '80mm';
      slip.style.maxWidth = '80mm';
      slip.style.margin = '0 auto';
    }
    window.print();
    if (slip) {
      setTimeout(() => {
        slip.style.width = '';
        slip.style.maxWidth = '';
        slip.style.margin = '';
      }, 1000);
    }
    const context = payload(markPacked, options);
    const label = slipOnly
      ? 'Slip print'
      : markPacked
        ? (context.manual_courier?.preset ? 'Marked as packed & sent' : 'Marked as packed')
        : 'Print only';
    log('system', label, '');
    // No alert
  }

  // ------- Wiring
  function wire(){
    if (PACK_ONLY) {
      wirePackOnly();
      return;
    }
    updateManualCourierUI();

    const manualCourierSelect = $('#manualCourierPreset');
    if (manualCourierSelect) {
      manualCourierSelect.addEventListener('change', () => {
        updateManualCourierUI();
        const option = manualCourierSelect.options[manualCourierSelect.selectedIndex];
        const label = option ? (option.text || '').trim() : (manualCourierSelect.value || '');
        log('shipment', 'Manual courier method set', label || 'None');
      });
    }
    const manualCourierExtra = $('#manualCourierExtraDetail');
    if (manualCourierExtra) {
      manualCourierExtra.addEventListener('input', e => {
        state.manualCourier.extra = e.target.value.trim();
      });
      manualCourierExtra.addEventListener('blur', e => {
        state.manualCourier.extra = e.target.value.trim();
        if (state.manualCourier.preset === 'third_party' && state.manualCourier.extra) {
          log('shipment', 'Third-party courier noted', state.manualCourier.extra);
        }
      });
    }
    // Tabs
    $$('.tnav .tab').forEach(a=>a.addEventListener('click',e=>{
      e.preventDefault();
      $$('.tnav .tab').forEach(x=>x.removeAttribute('aria-current'));
      a.setAttribute('aria-current','page');
      state.method = a.dataset.method;
      $('#blkCourier').hidden = state.method!=='courier';
      $('#blkPickup').hidden  = state.method!=='pickup';
      $('#blkInternal').hidden= state.method!=='internal';
      $('#blkDropoff').hidden = state.method!=='dropoff';
  $('#blkManual').hidden  = true; // manual appears only when print pool offline
      // Reviewed By only when not courier (manual) or if print pool offline
      syncPrintPoolUI();
      if(state.method==='courier') refreshPrintPool();
      if(state.method==='courier') {
        scheduleRatesRefresh('mode-change', {force:true});
      } else {
        setSelection(null);
        renderRates();
      }
      updateManualCourierUI();
      log('system','Mode changed',state.method);
    }));

    const commentForm = document.getElementById('commentForm');
    if (commentForm) {
      commentForm.addEventListener('submit', async event => {
        event.preventDefault();
        const textEl = document.getElementById('commentText');
        const scopeEl = document.getElementById('commentScope');
        const message = (textEl?.value || '').trim();
        if (!message) {
          showToast('Type a note before saving.', 'warning');
          textEl?.focus();
          return;
        }
        if (!BOOT.transferId) {
          showToast('Transfer context missing – cannot save note.', 'error');
          return;
        }

        const endpoint = BOOT.endpoints?.notes_add || '/modules/transfers/stock/api/notes_add.php';
        if (!endpoint) {
          showToast('Notes endpoint missing.', 'error');
          return;
        }

        const submitBtn = commentForm.querySelector('.psx-note-btn');
        const originalLabel = submitBtn?.textContent;
        if (submitBtn) {
          submitBtn.disabled = true;
          submitBtn.textContent = 'Saving…';
        }

        try {
          const scope = (scopeEl?.value || 'note').trim() || 'note';
          const idempotencyKey = `note-${BOOT.transferId}-${Date.now()}-${Math.random().toString(16).slice(2, 10)}`;
          const payload = { transfer_id: BOOT.transferId, note_text: message };
          const response = await ajax('POST', endpoint, payload, 12000, {
            headers: { 'Idempotency-Key': idempotencyKey },
          });
          if (textEl) {
            textEl.value = '';
            textEl.focus();
          }
          log(scope, message, '', {
            user: CURRENT_USER.name || null,
            note_id: response?.note_id ?? null,
          });
          showToast('Note saved to history.', 'success');
        } catch (error) {
          console.error('Failed to save note', error);
          showToast('Unable to save note right now. Please try again.', 'error');
        } finally {
          if (submitBtn) {
            submitBtn.disabled = false;
            if (originalLabel) {
              submitBtn.textContent = originalLabel;
            }
          }
        }
      });
    }

    const trackingInput = $('#trackingInput');
    const trackingAdd = $('#trackingAdd');
    const trackingList = $('#trackingList');
    const tryAddTracking = () => {
      if (!trackingInput) return;
      const raw = trackingInput.value.trim();
      if (!raw) {
        showToast('Enter a tracking code or URL first.', 'warning');
        trackingInput.focus();
        return;
      }
      if (state.trackingRefs.length >= 12) {
        showToast('Limit reached: remove an existing tracking reference before adding another.', 'warning');
        return;
      }
      if (state.trackingRefs.some(entry => entry.toLowerCase() === raw.toLowerCase())) {
        showToast('That tracking reference is already listed.', 'info');
        trackingInput.select();
        return;
      }
      state.trackingRefs.push(raw);
      trackingInput.value = '';
      trackingInput.focus();
      renderTrackingRefs();
      log('shipment', 'Tracking added', raw);
      showToast('Tracking reference added.', 'success');
    };

    if (trackingAdd && trackingInput) {
      trackingAdd.addEventListener('click', tryAddTracking);
      trackingInput.addEventListener('keydown', e => {
        if (e.key === 'Enter') {
          e.preventDefault();
          tryAddTracking();
        }
      });
    }

    if (trackingList) {
      trackingList.addEventListener('click', e => {
        const btn = e.target.closest('[data-remove-index]');
        if (!btn) return;
        const idx = Number(btn.dataset.removeIndex);
        if (!Number.isInteger(idx) || idx < 0 || idx >= state.trackingRefs.length) return;
        const [removed] = state.trackingRefs.splice(idx, 1);
        renderTrackingRefs();
        if (removed) {
          log('shipment', 'Tracking removed', removed);
          showToast('Tracking reference removed.', 'info');
        }
      });
    }

    // MODE
    $('#btnSatchel').addEventListener('click',()=> setContainer('satchel'));
    $('#btnBox').addEventListener('click',()=> setContainer('box'));

    // Package buttons
    $('.js-add').addEventListener('click',()=>{
      const id = $('#preset').value;
      const src = PRESETS[state.container].find(p=>p.id===id) || PRESETS[state.container][0];
      state.packages.push({...src, id: state.packages.length+1});
      renderPackages();
      scheduleRatesRefresh('pkg-add');
      log('parcel','Parcel added',src.name);
    });
    $('.js-copy').addEventListener('click',()=>{
      if(!state.packages.length) return;
      const last=state.packages[state.packages.length-1];
      state.packages.push({...last, id: state.packages.length+1});
      renderPackages();
      scheduleRatesRefresh('pkg-copy');
      log('parcel','Parcel copied',last.name);
    });
    $('.js-clear').addEventListener('click',()=>{ state.packages=[]; renderPackages(); scheduleRatesRefresh('pkg-clear'); log('shipment','Parcels cleared',''); });
    $('.js-auto').addEventListener('click',()=>{
      const per = state.packages.length? Math.ceil(120/state.packages.length):0;
      state.packages = state.packages.map((p,i)=> ({...p, items: per, kg: Math.max(p.kg, 1.2 + 0.4*i)}));
      renderPackages();
      scheduleRatesRefresh('pkg-auto');
      log('shipment','Auto-assign complete',`${state.packages.length} parcels`);
    });

    // Parcel edits / delete
    $('#pkgBody').addEventListener('input',e=>{
      const t=e.target; const i=+t.dataset.i; const k=t.dataset.k; if(Number.isNaN(i)||!k) return;
  const v = t.type==='number' ? parseFloat(t.value||'0') : t.value;
  state.packages[i][k] = (k==='name') ? String(v) : (Number.isFinite(v)?v:state.packages[i][k] ?? 0);
      renderMeters();
      updateShipmentStats();
      renderSummary();
      scheduleRatesRefresh('pkg-edit');
    });
    $('#pkgBody').addEventListener('click',e=>{
      const btn=e.target.closest('button[data-del]'); if(!btn) return;
      const idx=+btn.dataset.del; const removed=state.packages[idx]; state.packages.splice(idx,1);
      renderPackages();
      scheduleRatesRefresh('pkg-remove');
      log('parcel','Parcel removed',removed?.name||`#${idx+1}`);
    });

    // Options + Reviewed By
    ['optSig','optATL','optAge','optSat'].forEach(id=> {
      const el = document.getElementById(id);
      if (el) el.addEventListener('change', () => scheduleRatesRefresh('option-change', {force:true}));
    });
    $('#reviewedBy').addEventListener('input', e => state.options.reviewedBy = e.target.value);

    // Manual tracking & method saves
    $('#btnSaveManual').addEventListener('click',()=>{ const c=$('#mtCarrier').value, t=($('#mtTrack').value||'').trim(); if(!t) return alert('Enter tracking number'); log('shipment','Manual tracking saved',`${c}: ${t}`); alert('Saved manual tracking (sim)'); });
    $('#btnSavePickup').addEventListener('click', () => {
      const by    = ($('#pickupBy')?.value || '').trim();
      const phone = ($('#pickupPhone')?.value || '').trim();
      const time  = ($('#pickupTime')?.value || '').trim();
      const pkgs  = +($('#pickupPkgs')?.value || 0);
      const notes = ($('#pickupNotes')?.value || '').trim();
      if (!by)   return alert('Enter who picked it up');
      if (!time) return alert('Enter pickup time');
      log('shipment', 'Pickup saved', `${by} • ${time} • ${pkgs} parcels${phone?` • ${phone}`:''}${notes?` • ${notes}`:''}`);
      // TODO: ajax('POST', BOOT.endpoints.save_pickup, {by, phone, time, pkgs, notes}).catch(()=>{});
    });

    $('#btnSaveInternal').addEventListener('click', () => {
      const carrier = ($('#intCarrier')?.value || '').trim();
      const depart  = ($('#intDepart')?.value || '').trim();
      const boxes   = +($('#intBoxes')?.value || 0);
      const notes   = ($('#intNotes')?.value || '').trim();
      if (!carrier) return alert('Enter driver/van name');
      if (!depart)  return alert('Enter depart time');
      log('shipment', 'Internal run saved', `${carrier} • ${depart} • ${boxes} boxes${notes?` • ${notes}`:''}`);
      // TODO: ajax('POST', BOOT.endpoints.save_internal, {carrier, depart, boxes, notes}).catch(()=>{});
    });

    $('#btnSaveDrop').addEventListener('click', () => {
      const where = ($('#dropLocation')?.value || '').trim();
      const when  = ($('#dropWhen')?.value || '').trim();
      const boxes = +($('#dropBoxes')?.value || 0);
      const notes = ($('#dropNotes')?.value || '').trim();
      if (!where) return alert('Enter drop-off location');
      if (!when)  return alert('Enter drop-off time');
      log('shipment', 'Drop-off saved', `${where} • ${when} • ${boxes} boxes${notes?` • ${notes}`:''}`);
      // TODO: ajax('POST', BOOT.endpoints.save_dropoff, {where, when, boxes, notes}).catch(()=>{});
    });

    // Print actions
    $('#btnPrintOnly')  ?.addEventListener('click', () => doPrint(false));
    $('#btnPrintPacked')?.addEventListener('click', () => doPrint(true));
    $('#btnReady')      ?.addEventListener('click', () => doPrint(true));

    // Settings + overlay
    $('#btnSettings')   ?.addEventListener('click', openSettings);
    $('#closeDrawer')   ?.addEventListener('click', () => { const d=$('#drawer'); if(d) d.style.display='none'; });
    $('#dismissBlock')  ?.addEventListener('click', () => { const b=$('#uiBlock'); if(b) b.classList.remove('show'); });
    $('#btnSlipPrint')  ?.addEventListener('click', () => {
      doPrint(false, {slipOnly: true});
      showToast('Slip preview queued for printing.', 'info');
    });

    // Initial print pool check
    syncPrintPoolUI();
    refreshPrintPool();
  }

  // optional wrapper if you referenced it elsewhere
  function wirePkgInputs(){ /* delegated listeners already bound in wire(); */ }

  function setContainer(type){
    if (type === state.container) return;
    state.container = type;
    $('#btnSatchel')?.setAttribute('aria-pressed', type==='satchel' ? 'true' : 'false');
    $('#btnBox')    ?.setAttribute('aria-pressed', type==='box'     ? 'true' : 'false');
    loadPresetOptions();
    renderPackages();
    scheduleRatesRefresh('container-change', {force:true});
    log('system', 'Container set', type);
  }

  function openSettings(){
    const body = $('#settingsBody');
    if (!body) { return; }
    const rows = [
      ['Transfer', BOOT.transferId],
      ['From Outlet ID', BOOT.fromOutletId],
      ['To Outlet ID', BOOT.toOutletId],
      ['Carrier · NZ Post', BOOT.capabilities?.carriers?.nzpost ? 'Enabled' : 'Disabled'],
      ['Carrier · NZ Couriers', BOOT.capabilities?.carriers?.nzc ? 'Enabled' : 'Disabled'],
      ['Carrier Fallback Active', state.carriersFallbackUsed ? 'Yes' : 'No'],
      ['Print Pool Online', state.printPool?.online ? 'Yes' : 'No'],
      ['Print Pool Count', `${state.printPool?.onlineCount ?? 0} / ${state.printPool?.totalCount ?? 0}`],
      ['X-API-Key', BOOT.tokens?.apiKey || ''],
      ['X-NZPost-Token', BOOT.tokens?.nzPost || ''],
      ['X-GSS-Token', BOOT.tokens?.gss || ''],
      ['Rates URL', BOOT.endpoints?.rates || '—'],
      ['Create URL', BOOT.endpoints?.create || '—'],
      ['Address Facts URL', BOOT.endpoints?.address_facts || '—'],
      ['Print Pool URL', BOOT.endpoints?.print_pool || '—'],
    ];
    body.innerHTML = rows.map(([k,v]) => `<div style="margin:4px 0"><b>${k}:</b> <code>${String(v)}</code></div>`).join('');
    $('#drawer')?.style && ($('#drawer').style.display = 'grid');
  }

  // ------- Boot
  function boot(){
    const hydrated = applyBootAutoplan();
    loadPresetOptions();
    if (!hydrated) primeMetricsFromBoot();
    renderPackages();
  renderTrackingRefs();
    renderFeed();
    wire();
    syncPrintPoolUI();
    if (PACK_ONLY) {
      return;
    }
    scheduleRatesRefresh('boot', {force:true});
    refreshAddressFacts();
    refreshPrintPool();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();

