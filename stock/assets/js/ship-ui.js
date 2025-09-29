// assets/js/stock-transfers/ship-ui.js
(function (window, document) {
  'use strict';
  // --- Dedup guard: prevents double wiring if included twice
  if (window.__shipUiLoaded) return;
  window.__shipUiLoaded = true;

  // ----------------------- helpers
  const $  = (s, r) => (r || document).querySelector(s);
  const $$ = (s, r) => [...(r || document).querySelectorAll(s)];
  const num = v => (typeof v === 'number' ? v : parseFloat(v || 0) || 0);
  const kg3 = v => (Math.round(num(v) * 1000) / 1000).toFixed(3);
  const money = v => (num(v)).toFixed(2);
  const tidFromPage = () => {
    const h = $('#transferID') || $('[name="transfer_id"]');
    if (h && h.value && parseInt(h.value, 10) > 0) return parseInt(h.value, 10);
    const qs = new URLSearchParams(location.search);
    return parseInt(qs.get('transfer') || qs.get('t') || '0', 10) || 0;
  };
  const safeJSON = (res) => res.text().then(t => {
    let data = {};
    try { data = t ? JSON.parse(t) : {}; } catch (e) {}
    return { ok: res.ok, status: res.status, data, raw: t };
  });

  // ----------------------- state
  const SW = {
    tid: 0,
    mode: 'pickup',       // 'pickup' | 'courier' | 'post_office' | 'dropoff'
    carrier: 'nz_post',   // 'nz_post' | 'gss' | 'manual'
    packages: [],         // { type, name, length_cm, width_cm, height_cm, weight_kg }
    summary: { items: 0, total_kg: 0, missing: 0, cap_kg: null, boxes: 0 },
    quotes: [],
    cheapest: null,
    printers: [],
    addrFrom: null,
    addrTo: null,
    warnings: [],
    lock: { services:false, rates:false, create:false, suggest:false },
    el: {}
  };

  // ----------------------- API
  const API = {
    servicesLive: (carrier) => fetch(`/modules/transfers/stock/api/services_live.php?transfer=${SW.tid}&carrier=${encodeURIComponent(carrier)}`).then(safeJSON),
    weightSuggest: () => fetch(`/modules/transfers/stock/api/weight_suggest.php?transfer=${SW.tid}`).then(safeJSON),
    rates: (carrier, packages) => fetch(`/modules/transfers/stock/api/rates.php`, {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ transfer_id: SW.tid, carrier, packages })
    }).then(safeJSON),
    createLabel: (payload) => fetch(`/modules/transfers/stock/api/create_label.php`, {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify(payload)
    }).then(async res => {
      const pack = await safeJSON(res);
      // Normalize lock-required handling
      if (pack.status === 423 || (pack.data && pack.data.code === 'lock_required')) {
        return { ok:false, status:423, data:{ success:false, error:'lock_required', message:'Exclusive packing lock required. Acquire or request access.' } };
      }
      return pack;
    }),
    validateAddress: (obj) => fetch(`/modules/transfers/stock/api/validate_address.php`, {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ transfer_id: SW.tid, address: obj })
    }).then(safeJSON),
  };

  // ----------------------- UI feedback
  function toast(msg) {
    const box = $('#sw-feedback');
    if (!box) return;
    box.textContent = msg || '';
    if (msg) setTimeout(()=>{ box.textContent=''; }, 4000);
  }
  function setWarn(list) {
    SW.warnings = Array.isArray(list) ? list : [];
    if (!SW.el.warn) return;
    if (SW.warnings.length) {
      SW.el.warn.innerHTML = `<strong>Heads up:</strong> ${SW.warnings.map(String).join(' · ')}`;
      SW.el.warn.classList.remove('d-none');
    } else {
      SW.el.warn.classList.add('d-none');
      SW.el.warn.innerHTML = '';
    }
  }

  // ----------------------- renderers
  function renderAddresses(d) {
    if (d && d.from) SW.addrFrom = d.from;
    if (d && d.to)   SW.addrTo   = d.to;
    $('#sw-from').textContent = [
      SW.addrFrom?.addr1, SW.addrFrom?.addr2, SW.addrFrom?.city, SW.addrFrom?.postcode, SW.addrFrom?.country
    ].filter(Boolean).join(', ') || '—';
    $('#sw-to').textContent = [
      SW.addrTo?.addr1, SW.addrTo?.addr2, SW.addrTo?.city, SW.addrTo?.postcode, SW.addrTo?.country
    ].filter(Boolean).join(', ') || '—';
  }

  function renderSummary() {
    $('#sw-items').textContent    = SW.summary.items ?? '0';
    $('#sw-total').textContent    = kg3(SW.summary.total_kg || 0);
    $('#sw-missing').textContent  = SW.summary.missing ?? '0';
    $('#sw-cap').textContent      = SW.summary.cap_kg != null ? kg3(SW.summary.cap_kg) : '—';
    $('#sw-boxes').textContent    = SW.summary.boxes ?? '0';
    $('#sw-summary-weight').textContent    = `${kg3(SW.summary.total_kg || 0)} kg`;
    $('#sw-summary-packages').textContent  = `${SW.packages.length} pkgs`;

    const bestRow = $('#sw-summary-quote');
    if (SW.mode === 'courier' && SW.cheapest) {
      $('#sw-best-name').textContent  = SW.cheapest.service_name || 'Cheapest';
      $('#sw-best-price').textContent = `($${money(SW.cheapest.total_price || 0)})`;
      bestRow.hidden = false;
    } else {
      bestRow.hidden = true;
    }
  }

  function rowHTML(p, i) {
    const n = p.name || (`Pkg ${i+1}`);
    const type = p.type || 'box';
    return `<tr data-idx="${i}">
      <td class="sw-dim"><select class="form-control form-control-sm sw-type"><option value="box"${type==='box'?' selected':''}>Box</option><option value="bag"${type==='bag'?' selected':''}>Bag</option></select></td>
      <td><input class="form-control form-control-sm sw-name" value="${n}"></td>
      <td class="sw-dim"><input class="form-control form-control-sm sw-l"  type="number" min="0" step="0.1"   value="${num(p.length_cm)||0}"></td>
      <td class="sw-dim"><input class="form-control form-control-sm sw-w"  type="number" min="0" step="0.1"   value="${num(p.width_cm)||0}"></td>
      <td class="sw-dim"><input class="form-control form-control-sm sw-h"  type="number" min="0" step="0.1"   value="${num(p.height_cm)||0}"></td>
      <td><input class="form-control form-control-sm sw-kg" type="number" min="0" step="0.001" value="${num(p.weight_kg)||0}"></td>
      <td><button class="btn btn-sm btn-outline-danger sw-del" type="button">Remove</button></td>
    </tr>`;
  }

  function renderPackages() {
    const tbody = $('#sw-packages tbody');
    if (!tbody) return;
    if (!SW.packages.length) {
      tbody.innerHTML = `<tr><td colspan="6" class="text-muted text-center">No packages yet.</td></tr>`;
    } else {
      tbody.innerHTML = SW.packages.map(rowHTML).join('');
      // tag bag rows for CSS hide if we later choose row based instead of global
      $$('#sw-packages tbody tr').forEach((tr,i)=>{ if((SW.packages[i]?.type)||'box'==='bag') tr.classList.add('is-bag'); });
    }
    renderSummary();
  }

  function populateServices(services, printers) {
    const flat = [];
    if (services && typeof services === 'object') {
      Object.keys(services).forEach(car => {
        (services[car] || []).forEach(s => flat.push({
          carrier: car,
          code: s.code || s.service_code || s.id || '',
          name: s.name || s.service_name || s.label || s.code || 'Service'
        }));
      });
    }
    SW.el.service.innerHTML = `<option value="">Service (live/manual)</option>` + flat.map(x =>
      `<option value="${x.carrier}::${x.code}">${x.name} (${x.carrier})</option>`
    ).join('');
    SW.el.service.disabled = (SW.mode !== 'courier');

    SW.printers = Array.isArray(printers) ? printers.map(p => (p.name || p)) : [];
    const dl = $('#sw-printer-datalist');
    if (dl) dl.innerHTML = SW.printers.map(n => `<option value="${n}">`).join('');

    // --- Restore last chosen service for this carrier (robustness tweak)
    try {
      const saved = localStorage.getItem('sw:lastService:' + SW.carrier);
      if (saved && [...SW.el.service.options].some(o => o.value === saved)) {
        SW.el.service.value = saved;
      }
    } catch(e) {}
  }

  function selectCheapest(quotes) {
    if (!Array.isArray(quotes) || !quotes.length) { SW.cheapest = null; return; }
    const sorted = quotes.slice().sort((a,b)=> num(a.total_price||a.price||a.total||1e9) - num(b.total_price||b.price||b.total||1e9));
    const c = sorted[0];
    SW.cheapest = {
      carrier: SW.carrier,
      service_code: c.service_code || c.code || c.id || '',
      service_name: c.service_name || c.name || 'Cheapest',
      total_price: num(c.total_price||c.price||c.total||0)
    };
    if (SW.mode === 'courier' && SW.el.service) {
      const val = `${SW.carrier}::${SW.cheapest.service_code}`;
      const opt = [...SW.el.service.options].find(o => o.value === val);
      if (opt) SW.el.service.value = val;
    }
  }

  // ----------------------- flows
  async function loadWeightSuggest() {
    if (SW.lock.suggest) return; SW.lock.suggest = true;
    try {
      const r = await API.weightSuggest();
      if (r.ok && r.data && r.data.success) {
        const d = r.data;
        SW.summary.items    = d.items_count || 0;
        SW.summary.total_kg = num(d.total_weight_kg || 0);
        SW.summary.missing  = d.missing_weights || 0;
        SW.summary.cap_kg   = d.plan?.cap_kg ?? null;
        SW.summary.boxes    = d.plan?.boxes ?? 0;
        if (!SW.packages.length && Array.isArray(d.packages)) {
          SW.packages = d.packages.map(p => ({
            name: p.name || 'Box',
            length_cm: num(p.l_cm||p.length_cm||0),
            width_cm:  num(p.w_cm||p.width_cm||0),
            height_cm: num(p.h_cm||p.height_cm||0),
            weight_kg: num(p.weight_kg||p.kg||0)
          }));
        }
        setWarn(d.warnings || []);
        renderPackages();
      }
    } finally { SW.lock.suggest = false; }
  }

  async function loadServices() {
    if (SW.mode !== 'courier') return;
    if (SW.lock.services) return; SW.lock.services = true;
    try {
      const r = await API.servicesLive(SW.carrier);
      const d = r.data || {};
      setWarn(d.warnings || []);
      renderAddresses(d);
      populateServices(d.services || {}, d.printers || []);
    } finally { SW.lock.services = false; }
  }

  async function loadRates() {
    if (SW.mode !== 'courier') { SW.quotes=[]; SW.cheapest=null; renderSummary(); return; }
    if (SW.lock.rates) return; SW.lock.rates = true;
    try {
      const pk = SW.packages.length ? SW.packages : [{ length_cm:40,width_cm:30,height_cm:20,weight_kg:1,name:'Box 1' }];
      const r = await API.rates(SW.carrier, pk);
      const d = r.data || {};
      const list =
        Array.isArray(d.quotes)  ? d.quotes  :
        Array.isArray(d.results) ? d.results :
        (d.quote ? [d.quote] : []);
      SW.quotes = list;
      selectCheapest(SW.quotes);
      renderSummary();
      renderRatesBox();
    } finally { SW.lock.rates = false; }
  }

  function renderRatesBox() {
    const box = $('#sw-rates');
    if (!box) return;
    if (SW.mode !== 'courier' || !SW.quotes.length) { box.classList.add('d-none'); box.innerHTML=''; return; }
    box.classList.remove('d-none');
    // track previous prices to surface deltas
    const prevMap = SW._prevQuotes || {}; SW._prevQuotes = {};
    box.innerHTML = `<div class="small text-muted mb-2">Live quotes:</div>` + SW.quotes.map(q => {
      const raw = (q.total_price || q.price || q.total || 0);
      const price = money(raw);
      const name  = q.service_name || q.name || q.code || 'Service';
      const key = name + '|' + (q.service_code||q.code||'');
      let deltaHtml='';
      if(prevMap[key]!=null){
        const diff = raw - prevMap[key];
        if(Math.abs(diff) >= 0.01){
          const sign = diff>0?'+':'';
          deltaHtml = `<span class="${diff<0?'sw-rate-delta-up':'sw-rate-delta-down'}">${sign}${diff.toFixed(2)}</span>`;
        }
      }
      SW._prevQuotes[key]=raw;
      return `<div>• ${name} — $${price}${deltaHtml}</div>`;
    }).join('');
  }

  // ----------------------- override + printer memory
  function readOverrideOrNull() {
    if ($('#sw-override-block').classList.contains('d-none')) return null;
    const v = id => ($('#'+id)?.value || '').trim();
    const has = [ 'sw-name','sw-street1','sw-city','sw-postcode' ].some(id => v(id));
    if (!has) return null;
    return {
      name: v('sw-name'), phone: v('sw-phone'),
      addr1: v('sw-street1'), addr2: v('sw-street2'),
      suburb: v('sw-suburb'), city: v('sw-city'),
      postcode: v('sw-postcode'), country: v('sw-country') || 'NZ'
    };
  }
  function rememberPrinter(name) {
    try {
      const key = 'sw_printers';
      const cur = JSON.parse(localStorage.getItem(key) || '[]');
      const list = [name, ...cur.filter(x => x !== name)].filter(Boolean).slice(0,5);
      localStorage.setItem(key, JSON.stringify(list));
      $('#sw-printer-default').textContent = list[0] || 'None';
      $('#sw-printer-recent').innerHTML = list.slice(1).map(n => `<span class="chip" data-name="${n}">${n}</span>`).join('');
    } catch(e) {}
  }
  function loadPrinterMemory() {
    try {
      const key = 'sw_printers';
      const list = JSON.parse(localStorage.getItem(key) || '[]');
      $('#sw-printer-default').textContent = list[0] || 'None';
      $('#sw-printer-recent').innerHTML = list.slice(1).map(n => `<span class="chip" data-name="${n}">${n}</span>`).join('');
    } catch(e) {}
  }

  // ----------------------- mode & events
    function setMode(mode) {
      SW.mode = mode;
      const courier = (mode==='courier');
      $$('.sw__courieronly').forEach(el => el.classList.toggle('d-none', !courier));
      $('#sw-tracking-block')?.classList.toggle('d-none', courier);
      SW.el.carrier.disabled = !courier;
      SW.el.service.disabled = !courier;
      let label='Internal';
      if(mode==='courier') label = (SW.carrier === 'nz_post' ? 'NZ Post (Starshipit)' : (SW.carrier === 'gss' ? 'NZ Couriers (GSS)' : 'Manual'));
      else if(mode==='post_office') label='Post Office';
      else if(mode==='dropoff') label='Drop / Pickup';
      $('#sw-summary-carrier').textContent = label;
      renderSummary();
      if(courier) loadServices().then(loadRates); else { SW.quotes=[]; SW.cheapest=null; renderSummary(); }
    }

  function addBox(copy=false) {
    if (copy && SW.packages.length) {
      const last = SW.packages[SW.packages.length-1];
      SW.packages.push({ ...last, name:`Box ${SW.packages.length+1}` });
    } else {
      SW.packages.push({ name:`Box ${SW.packages.length+1}`, length_cm:40, width_cm:30, height_cm:20, weight_kg:1 });
    }
    renderPackages();
  }

  function packagesFromTable() {
    const rows = $$('#sw-packages tbody tr');
    SW.packages = rows.map(tr => ({
      type:      $('.sw-type', tr)?.value || 'box',
      name:      $('.sw-name', tr).value || 'Pkg',
      length_cm: num($('.sw-l', tr)?.value),
      width_cm:  num($('.sw-w', tr)?.value),
      height_cm: num($('.sw-h', tr)?.value),
      weight_kg: num($('.sw-kg', tr)?.value)
    }));
    renderSummary();
  }

  function bindEvents() {
    // Mode tabs
    $$('.sw__modebtn').forEach(btn => {
      btn.addEventListener('click', () => {
        $$('.sw__modebtn').forEach(b => b.classList.remove('is-active'));
        btn.classList.add('is-active');
        setMode(btn.dataset.mode);
      });
    });

    SW.el.carrier.addEventListener('change', () => {
      SW.carrier = SW.el.carrier.value || 'nz_post';
      loadServices().then(loadRates);
    });

    SW.el.service.addEventListener('change', () => {
      const opt = SW.el.service.options[SW.el.service.selectedIndex];
      if (opt && opt.value) {
        const [car, code] = opt.value.split('::');
        SW.cheapest = {
          carrier: car,
          service_code: code,
          service_name: opt.textContent,
          total_price: SW.cheapest?.total_price || 0
        };
        // --- Save last chosen service for this carrier (robustness tweak)
        try { localStorage.setItem('sw:lastService:' + SW.carrier, SW.el.service.value || ''); } catch(e) {}
        renderSummary();
      }
    });

    $('#sw-suggest').addEventListener('click', () => loadWeightSuggest().then(()=> SW.mode==='courier' && loadRates()));
    $('#sw-add').addEventListener('click', () => addBox(false));
    $('#sw-copy').addEventListener('click', () => addBox(true));
    $('#sw-clear').addEventListener('click', () => { SW.packages = []; renderPackages(); });
    $('#sw-quotes').addEventListener('click', () => loadRates());

    $('#sw-override').addEventListener('click', () => $('#sw-override-block').classList.toggle('d-none'));
    $('#sw-validate').addEventListener('click', async () => {
      const ov = readOverrideOrNull(); if (!ov) return toast('Enter address details to validate.');
      const r = await API.validateAddress(ov);
      toast(r.ok ? 'Address looks valid.' : 'Validation failed or unavailable.');
    });

    // Table
    $('#sw-packages').addEventListener('input', (e) => {
      if (e.target.matches('.sw-name,.sw-l,.sw-w,.sw-h,.sw-kg,.sw-type')) packagesFromTable();
    });
    $('#sw-packages').addEventListener('click', (e) => {
      if (e.target.closest('.sw-del')) {
        const tr = e.target.closest('tr'); const idx = parseInt(tr.dataset.idx, 10);
        SW.packages.splice(idx, 1); renderPackages();
      }
    });

    // Actions
    $('#sw-preview').addEventListener('click', () => {
      const payload = buildPrintPayload();
      const enc = encodeURIComponent(btoa(JSON.stringify(payload)));
      window.open(`/modules/transfers/stock/print/box_slip.php?transfer=${SW.tid}&preview=1&meta=${enc}`, '_blank');
    });
    $('#sw-print').addEventListener('click', () => {
      const payload = buildPrintPayload();
      const enc = encodeURIComponent(btoa(JSON.stringify(payload)));
      window.open(`/modules/transfers/stock/print/box_slip.php?transfer=${SW.tid}&preview=1&meta=${enc}`, '_blank');
    });
    $('#sw-create').addEventListener('click', doCreateLabel);
    $('#sw-ready').addEventListener('click', () => {
      document.dispatchEvent(new CustomEvent('pack:mark-ready', { detail: { transfer_id: SW.tid } }));
      toast('Marked Ready (client-side). Hook your server to "pack:mark-ready".');
    });

    // Notes
    $('#sw-add-note')?.addEventListener('click', addNoteFromInput);
    $('#sw-note-input')?.addEventListener('keypress', e=>{ if(e.key==='Enter'){ e.preventDefault(); addNoteFromInput(); }});
    $('#sw-notes-list')?.addEventListener('click', e=>{ const rm=e.target.closest('.rm'); if(!rm) return; const li=rm.closest('li'); if(li){ li.remove(); saveNotes(); } });

    // Manual tracking
    $('#sw-add-track')?.addEventListener('click', addTrackingFromInput);
    $('#sw-track-input')?.addEventListener('keypress', e=>{ if(e.key==='Enter'){ e.preventDefault(); addTrackingFromInput(); }});
    $('#sw-tracking-list')?.addEventListener('click', e=>{ const btn=e.target.closest('button[data-track]'); if(!btn) return; btn.parentElement.remove(); saveTracking(); });

    // Printer chips
    $('#sw-printer-recent').addEventListener('click', (e) => {
      const chip = e.target.closest('.chip'); if (!chip) return;
      $('#sw-printer').value = chip.dataset.name || '';
    });
  }

  function addNoteFromInput(){
    const inp = $('#sw-note-input'); if(!inp) return; const v=(inp.value||'').trim(); if(!v) return; const li=document.createElement('li'); const user=(window.CIS_USER_NAME||'User');
    li.innerHTML = `<span><strong>${user}:</strong> ${v}</span> <span class="rm" title="Remove" aria-label="Remove note">×</span>`; $('#sw-notes-list').appendChild(li); inp.value=''; saveNotes(); }
  function saveNotes(){ try{ const list=[...$('#sw-notes-list').querySelectorAll('li')].map(li=>li.textContent); localStorage.setItem('sw_notes_'+SW.tid, JSON.stringify(list)); }catch(e){} }
  function loadNotes(){ try{ const list=JSON.parse(localStorage.getItem('sw_notes_'+SW.tid)||'[]'); list.forEach(txt=>{ const li=document.createElement('li'); li.innerHTML=`${txt} <span class=\"rm\" title=\"Remove\" aria-label=\"Remove note\">×</span>`; $('#sw-notes-list').appendChild(li); }); }catch(e){} }
  function addTrackingFromInput(){ const inp=$('#sw-track-input'); if(!inp) return; const v=(inp.value||'').trim(); if(!v) return; const div=document.createElement('div'); div.className='trk'; div.innerHTML=`<span>${v}</span><button type=\"button\" data-track=\"${v}\">×</button>`; $('#sw-tracking-list').appendChild(div); inp.value=''; saveTracking(); }
  function saveTracking(){ try{ const list=[...$('#sw-tracking-list').querySelectorAll('.trk span')].map(s=>s.textContent); localStorage.setItem('sw_trk_'+SW.tid, JSON.stringify(list)); }catch(e){} }
  function loadTracking(){ try{ const list=JSON.parse(localStorage.getItem('sw_trk_'+SW.tid)||'[]'); list.forEach(t=>{ const div=document.createElement('div'); div.className='trk'; div.innerHTML=`<span>${t}</span><button type=\"button\" data-track=\"${t}\">×</button>`; $('#sw-tracking-list').appendChild(div); }); }catch(e){} }

  function buildPrintPayload(){
    return {
      transfer_id: SW.tid,
      mode: SW.mode,
      packages: SW.packages.map(p=>({type:p.type||'box', name:p.name, l:p.length_cm, w:p.width_cm, h:p.height_cm, kg:p.weight_kg})),
      notes: [...($('#sw-notes-list')?.querySelectorAll('li')||[])].map(li=>li.textContent),
      tracking: [...($('#sw-tracking-list')?.querySelectorAll('.trk span')||[])].map(s=>s.textContent)
    };
  }

  // ----------------------- label creation
  async function doCreateLabel() {
    if (SW.lock.create) return; SW.lock.create = true;
    try {
      if (SW.mode !== 'courier') { toast('Pickup/Internal mode: no label to create.'); return; }
      const chosen = (SW.el.service.value || '').split('::')[1] || SW.cheapest?.service_code || '';
      if (!chosen) return toast('Select a service first (or fetch quotes).');

      const pk = SW.packages.length ? SW.packages : [{ length_cm:40, width_cm:30, height_cm:20, weight_kg:1, name:'Box 1' }];
      const payload = {
        transfer_id: SW.tid,
        carrier: SW.carrier,
        service_code: chosen,
        packages: pk.map(p => ({
          name: p.name || 'Box',
          length_cm: num(p.length_cm), width_cm: num(p.width_cm), height_cm: num(p.height_cm), weight_kg: num(p.weight_kg), qty: 1
        })),
        ship_to_override: readOverrideOrNull(),
        printer: ($('#sw-printer')?.value || '').trim() || null,
        options: {
          signature: $('#sw-signature')?.checked || false,
          saturday:  $('#sw-saturday')?.checked || false,
          atl:       $('#sw-atl')?.checked || false
        },
        persist: false
      };
      const r = await API.createLabel(payload);
      if (!r.ok || !r.data || r.data.success !== true) return toast('Create label failed.');
      const labels = r.data.labels || [];
      if (labels.length) window.open(labels[0], '_blank');
      toast(labels.length ? `Label created (${labels.length}).` : 'Label created.');
      rememberPrinter($('#sw-printer')?.value || '');
    } finally { SW.lock.create = false; }
  }

  // ----------------------- boot
  function boot() {
    SW.el.root    = $('#ship-wizard'); if (!SW.el.root) return;
    SW.el.warn    = $('#sw-warn');
    SW.el.carrier = $('#sw-carrier');
    SW.el.service = $('#sw-service');

    SW.tid = parseInt(SW.el.root.getAttribute('data-transfer') || '0', 10) || tidFromPage();
    $('#sw-tid').textContent = SW.tid || '—';

  loadPrinterMemory();
  setMode('pickup');
  loadNotes();
  loadTracking();

Promise.resolve()
  .then(async () => {
    if (!SW.tid || SW.tid <= 0) {
      toast('Error: No valid transfer ID found. Please reload or check URL.');
      console.error('[ShipUI] Missing or invalid transfer ID (tid=' + SW.tid + ')');
      return Promise.reject(new Error('Missing transfer ID'));
    }
    await loadWeightSuggest();
    await loadServices();
    await loadRates();
  })
  .catch(err => {
    const msg = (err && err.message) ? err.message : 'Failed to load shipping data.';
    toast(msg);
    console.error('[ShipUI] Boot failed:', err);
  });

bindEvents();

  }

  if (document.readyState !== 'loading') boot();
  else document.addEventListener('DOMContentLoaded', boot);

})(window, document);
