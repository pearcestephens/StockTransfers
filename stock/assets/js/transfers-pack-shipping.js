/* transfers-pack-shipping.js — original slot, fixed preview + graceful services + sane UX */
(function () {
  'use strict';

  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const TID = (() => {
    const val = $('#transferID')?.value || new URLSearchParams(location.search).get('transfer') || '0';
    return parseInt(val, 10) || 0;
  })();

  /* -----------------------------
     Helpers
  ------------------------------ */
  function csrf() {
    if (window.CIS_CSRF) return window.CIS_CSRF;
    const m = document.cookie.match(/(?:^|;)\s*XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
  }
  async function jget(url) {
    const r = await fetch(url, {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-CSRF': csrf() }
    });
    let j = {};
    try { j = await r.json(); } catch {}
    if (!r.ok) throw new Error(`GET ${url} → ${r.status}`);
    // Accept either {success:true,data:{...}} or plain payloads; never throw on "expected" warnings.
    return j?.data || j;
  }
  async function jpost(url, body) {
    const r = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf() },
      body: JSON.stringify(body)
    });
    let j = {};
    try { j = await r.json(); } catch {}
    if (!r.ok) throw new Error(`POST ${url} → ${r.status}`);
    return j?.data || j;
  }
  function toast(msg, type) {
    // minimal inline notice; avoids adding libraries
    const fb = $('#sl-feedback');
    if (!fb) return;
    fb.textContent = msg || '';
    const classes = ['small'];
    if (type === 'error') classes.push('text-danger');
    else if (type === 'success') classes.push('text-success');
    else classes.push('text-muted');
    fb.className = classes.join(' ');
  }

  function renderWarnings(warnings) {
    if (!warningList) return;
    warningList.innerHTML = '';
    if (!Array.isArray(warnings) || warnings.length === 0) return;
    warnings.forEach((msg) => {
      if (!msg) return;
      const li = document.createElement('li');
      li.textContent = String(msg);
      warningList.appendChild(li);
    });
  }

  function setCarrierSummary() {
    if (!summaryCarrier || !carrierSel) return;
    const opt = carrierSel.options?.[carrierSel.selectedIndex];
    const label = opt ? opt.textContent.trim() : (carrierSel.value || '').toUpperCase();
    summaryCarrier.textContent = label || 'Select carrier';
  }

  function setWeightSummary(kg, source = 'api') {
    if (!summaryWeight) return;
    summaryWeight.dataset.source = source;
    if (kg === null || kg === undefined || Number.isNaN(Number(kg))) {
      summaryWeight.textContent = source === 'manual' ? 'Manual entry' : 'Pending';
      return;
    }
    const num = Number(kg);
    summaryWeight.textContent = Number.isFinite(num) ? `${num.toFixed(2)} kg` : 'Pending';
  }

  function computePackageKg() {
    const rows = $$('#sl-packages tbody .box-kg');
    return rows.reduce((total, input) => {
      const val = parseFloat(input.value || '0');
      return Number.isFinite(val) ? total + Math.max(val, 0) : total;
    }, 0);
  }

  function updatePackageSummary() {
    if (!summaryPackages) return;
    const rows = $('#sl-packages tbody')?.querySelectorAll('tr').length || 0;
    summaryPackages.textContent = rows ? `${rows} pkg${rows > 1 ? 's' : ''}` : 'No packages';
    if (!summaryWeight) return;
    if (summaryWeight.dataset.source !== 'api' || (carrierSel && carrierSel.value === 'manual')) {
      const kg = computePackageKg();
      setWeightSummary(kg, 'manual');
    }
  }

  /* -----------------------------
     Preview / Print / Ready wiring
  ------------------------------ */
  (function wirePreviewPrintReady() {
    const countInput = $('#box-count-input');
    const btnPrev = $('#btn-preview-labels');
    const btnPrint = $('#btn-print-labels');
    const btnReady = $('#markReadyForDelivery');

    function preview() {
      if (!TID) return alert('Missing transfer id');
      const n = Math.max(1, parseInt(countInput?.value || '1', 10));
      const url = `/modules/transfers/stock/print/box_slip.php?transfer=${TID}&preview=1&n=${n}`;

      const frame = $('#boxLabelPreviewFrame');
      const loader = $('#boxLabelPreviewLoader');
      const modal = $('#boxLabelPreviewModal');
      if (frame && loader && modal) {
        loader.style.display = 'flex';
        frame.src = url;
        if (window.jQuery && jQuery(modal).modal) jQuery(modal).modal('show');
        frame.onload = () => (loader.style.display = 'none');
      } else {
        window.open(url, '_blank');
      }
    }

    btnPrev && btnPrev.addEventListener('click', preview);

    btnPrint && btnPrint.addEventListener('click', () => {
      // If you already have a “Create Labels” handler elsewhere, trigger it; else fall back to preview
      const existing = $$('button,a').find(el => /Create Labels|Print labels/i.test(el.textContent || ''));
      if (existing && existing !== btnPrint) existing.click(); else preview();
    });

    btnReady && btnReady.addEventListener('click', () => {
      const action =
        $('[data-action="ready-delivery"]') ||
        $$('button,a').find(el => /Mark as Ready for Delivery/i.test(el.textContent || ''));
      action?.click();
    });
  })();

  /* -----------------------------
     Shipping & Services (graceful)
  ------------------------------ */
  const carrierSel = $('#sl-carrier');
  const serviceSel = $('#sl-service');
  const printerInput = $('#sl-printer'); // free text or datalist if you wire it
  const refreshBtn = $('#sl-service-refresh');
  const summaryCarrier = $('#sl-summary-carrier');
  const summaryWeight = $('#sl-summary-weight');
  const summaryPackages = $('#sl-summary-packages');
  const warningList = $('#sl-warning-list');

  async function loadServices() {
    if (!TID || !carrierSel || !serviceSel) return;
    const carrier = (carrierSel.value || 'nz_post').toLowerCase();
    const url = `/modules/transfers/stock/api/services_live.php?transfer=${TID}&carrier=${carrier}`;
    setCarrierSummary();
    renderWarnings([]);

    try {
      const j = await jget(url);
      // Support both envelopes: {services:{…}, printers:[…], total_weight_kg} or nested under data
      const services = j.services || {};
      const list = carrier === 'gss' ? (services.gss || []) : (services.nz_post || []);
      const isManual = carrier === 'manual';
      serviceSel.disabled = isManual;
      serviceSel.innerHTML = isManual
        ? '<option value="manual">Manual service (not required)</option>'
        : '<option value="">Select a live service</option>';
      if (!isManual) {
        list.forEach(s => {
          const code = (s.code || s.service_code || s.id || '').toString();
          const name = (s.name || s.display || code).toString();
          if (code) serviceSel.insertAdjacentHTML('beforeend', `<option value="${code}">${name}</option>`);
        });
        if (list.length === 1) {
          serviceSel.value = list[0].code || list[0].service_code || list[0].id || '';
        }
      } else {
        setWeightSummary(computePackageKg(), 'manual');
      }
      // printers (optional; keep input as free text if you don’t have a select)
      if (Array.isArray(j.printers) && printerInput && printerInput.tagName === 'INPUT') {
        // if you want, you can attach a datalist here; leaving as-is to preserve your original markup
      }
      setWeightSummary(j.total_weight_kg, 'api');
      renderWarnings(Array.isArray(j.warnings) ? j.warnings : []);
      toast('', 'info');
      if (window.TransfersPack && typeof window.TransfersPack.scheduleDraftSave === 'function') {
        window.TransfersPack.scheduleDraftSave();
      }
    } catch (e) {
      // Do NOT kill the UI — leave manual entry usable
      toast('Live services unavailable (manual OK).', 'info');
  serviceSel.innerHTML = '<option value="">Service (manual)</option>';
  serviceSel.disabled = false;
  setWeightSummary(computePackageKg(), 'manual');
      renderWarnings([e?.message || 'Live services unavailable.']);
      if (window.TransfersPack && typeof window.TransfersPack.scheduleDraftSave === 'function') {
        window.TransfersPack.scheduleDraftSave();
      }
    }
  }

  carrierSel && carrierSel.addEventListener('change', () => {
    loadServices();
  });
  refreshBtn && refreshBtn.addEventListener('click', () => loadServices());
  loadServices();
  setCarrierSummary();

  /* -----------------------------
     Package table helpers (original IDs)
  ------------------------------ */
  const tableBody = $('#sl-packages tbody');
  function addRow(copyFrom) {
    const l = copyFrom?.querySelector('.box-l')?.value || 40;
    const w = copyFrom?.querySelector('.box-w')?.value || 30;
    const h = copyFrom?.querySelector('.box-h')?.value || 20;
    const kg = copyFrom?.querySelector('.box-kg')?.value || 2.0;
    const html = `
      <tr class="box-row" data-l="${l}" data-w="${w}" data-h="${h}" data-kg="${kg}">
        <td><input class="form-control form-control-sm" value="Box"></td>
        <td><input class="form-control form-control-sm box-l" type="number" min="1" step="0.1" value="${l}"></td>
        <td><input class="form-control form-control-sm box-w" type="number" min="1" step="0.1" value="${w}"></td>
        <td><input class="form-control form-control-sm box-h" type="number" min="1" step="0.1" value="${h}"></td>
        <td><input class="form-control form-control-sm box-kg" type="number" min="0.01" step="0.01" value="${kg}"></td>
        <td><button type="button" class="btn-icon-xs" title="Remove" aria-label="Remove"><i class="fa fa-times" aria-hidden="true"></i></button></td>
      </tr>`;
    tableBody?.insertAdjacentHTML('beforeend', html);
    updatePackageSummary();
  }
  $('#sl-add')?.addEventListener('click', () => addRow(null));
  $('#sl-copy')?.addEventListener('click', () => {
    const last = $('#sl-packages tbody tr:last-child');
    addRow(last || null);
  });
  $('#sl-clear')?.addEventListener('click', () => {
    if (tableBody) tableBody.innerHTML = '';
    updatePackageSummary();
  });
  tableBody?.addEventListener('click', (ev) => {
    const btn = ev.target.closest('.btn-icon-xs');
    if (!btn) return;
    const tr = btn.closest('tr');
    tr?.remove();
    updatePackageSummary();
  });
  tableBody?.addEventListener('input', () => updatePackageSummary());

  updatePackageSummary();
  renderWarnings([]);

  /* -----------------------------
     Address override toggle (original IDs)
  ------------------------------ */
  $('#sl-override')?.addEventListener('click', () => {
    const block = $('#sl-address');
    if (block) block.classList.toggle('d-none');
  });
})();
