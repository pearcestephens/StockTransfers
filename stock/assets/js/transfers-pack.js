/* ==========================================================================
   CIS Transfers — PACK Page
   Depends: jQuery, /assets/js/transfers-common.js
   ========================================================================== */
(function (window, $) {
  'use strict';

  if (!window.CIS || !window.CIS.http) {
    console.error('[Transfers/Pack] CIS common not loaded.');
    return;
  }

  var CIS = window.CIS;
  var Pack = {};
  var $table = null;
  var draftKey = null;
  var saveDebounceTimer = null;
  var indicatorResetTimer = null;
  var $indicator = null;
  var $indicatorText = null;
  var $shipCard = null;
  var lastSavedTimestamp = null;

  // ---------------------------------------------------------------------------
  // Indicator helpers
  // ---------------------------------------------------------------------------
  function setIndicatorState(state, label) {
    if (!$indicator || !$indicator.length) return;
    var upper = (label || '').toUpperCase();
    $indicator.attr('data-state', state || 'idle');
    if ($indicatorText && $indicatorText.length) {
      $indicatorText.text(upper);
    }
  }

  function markIndicatorIdle() { setIndicatorState('idle', 'IDLE'); }
  function markIndicatorSaving() { setIndicatorState('saving', 'SAVING'); }
  function markIndicatorError() {
    setIndicatorState('error', 'SAVE ERROR');
    if (indicatorResetTimer) clearTimeout(indicatorResetTimer);
    indicatorResetTimer = setTimeout(markIndicatorIdle, 4000);
  }

  function updateLastSavedDisplay(timestamp) {
    if (timestamp) {
      $('#draft-last-saved').text('Last saved: ' + new Date(timestamp).toLocaleTimeString());
    } else {
      $('#draft-last-saved').text('Not saved');
    }
  }

  function updateCarrierTheme(carrier) {
    if (!$shipCard || !$shipCard.length) return;
    var classMap = {
      nz_post: 'carrier-theme--nzpost',
      gss: 'carrier-theme--gss'
    };
    var cls = classMap[carrier] || 'carrier-theme--neutral';
    $shipCard
      .removeClass('carrier-theme--nzpost carrier-theme--gss carrier-theme--neutral')
      .addClass(cls);
  }

  // ---------------------------------------------------------------------------
  // Numeric helpers
  // ---------------------------------------------------------------------------
  function enforceBounds(input) {
    var max = parseInt(input.getAttribute('max'), 10); if (!isFinite(max)) max = 999999;
    var min = parseInt(input.getAttribute('min'), 10); if (!isFinite(min)) min = 0;
    var v = parseInt(input.value, 10);
    if (isFinite(v)) {
      if (v > max) input.value = String(max);
      if (v < min) input.value = String(min);
    }
  }

  function syncPrintValue(input) {
    var sib = input && input.parentElement ? input.parentElement.querySelector('.counted-print-value') : null;
    if (sib) sib.textContent = input.value || '0';
  }

  function checkInvalidQty(input) {
    var $row = $(input).closest('tr');
    var inventory = parseInt($row.attr('data-inventory'), 10) || 0;
    var planned = parseInt($row.attr('data-planned'), 10) || 0;
    var raw = String(input.value || '').trim();

    function addZeroBadge() {
      if ($row.find('.badge-to-remove').length === 0) {
        $row.find('.counted-td').append('<span class="badge badge-to-remove">Will remove at submit</span>');
      }
      $row.addClass('table-secondary');
    }
    function removeZeroBadge() {
      $row.find('.badge-to-remove').remove();
      $row.removeClass('table-secondary');
    }

    if (raw === '') {
      $(input).addClass('is-invalid').removeClass('is-warning');
      $row.addClass('table-warning');
      removeZeroBadge();
      return;
    }

    var counted = Number(raw);
    if (!isFinite(counted) || counted < 0) {
      $(input).addClass('is-invalid').removeClass('is-warning');
      $row.addClass('table-warning');
      removeZeroBadge();
      return;
    }
    if (counted === 0) {
      $(input).removeClass('is-invalid is-warning');
      $row.removeClass('table-warning');
      addZeroBadge();
      return;
    }
    if (inventory > 0 && counted > inventory) {
      $(input).addClass('is-invalid').removeClass('is-warning');
      $row.addClass('table-warning');
      removeZeroBadge();
      return;
    }

    var suspicious = (counted >= 99) || (planned > 0 && counted >= planned * 3) || (inventory > 0 && counted >= inventory * 2);
    if (suspicious) {
      $(input).removeClass('is-invalid').addClass('is-warning');
      $row.removeClass('table-warning');
      removeZeroBadge();
    } else {
      $(input).removeClass('is-invalid is-warning');
      $row.removeClass('table-warning');
      removeZeroBadge();
    }
  }

  // ---------------------------------------------------------------------------
  // Totals & rows
  // ---------------------------------------------------------------------------
  function recomputeTotals() {
    var plannedTotal = 0;
    var countedTotal = 0;
    var rows = 0;
    $table.find('tbody tr').each(function () {
      var $r = $(this);
      plannedTotal += parseInt($r.attr('data-planned'), 10) || 0;
      var v = parseInt($r.find('input[type="number"]').val(), 10) || 0;
      countedTotal += v;
      rows++;
    });
    var diff = countedTotal - plannedTotal;

    $('#plannedTotal').text(plannedTotal.toLocaleString());
    $('#countedTotal').text(countedTotal.toLocaleString());
    $('#diffTotal')
      .text(diff.toLocaleString())
      .css('color', diff > 0 ? '#dc3545' : diff < 0 ? '#fd7e14' : '#28a745');
    $('#itemsToTransfer').text(rows);
  }

  function removeProduct(el) {
    if (!confirm('Remove this product from the transfer?')) return;
    $(el).closest('tr').remove();
    recomputeTotals();
    scheduleDraftSave();
  }

  function autofillCountedFromPlanned() {
    $table.find('tbody tr').each(function () {
      var $r = $(this);
      var input = $r.find('input[type="number"]').first()[0];
      var planned = parseInt($r.attr('data-planned'), 10) || 0;
      var inventory = parseInt($r.attr('data-inventory'), 10) || 0;
      var val = Math.min(planned, inventory);
      input.value = String(val);
      syncPrintValue(input);
      checkInvalidQty(input);
    });
    recomputeTotals();
    scheduleDraftSave();
  }

  // ---------------------------------------------------------------------------
  // Tracking helpers
  // ---------------------------------------------------------------------------
  function updateTrackingCount() {
    var count = $('#tracking-items .tracking-input').length;
    $('#tracking-count').text(count + ' number' + (count !== 1 ? 's' : ''));
  }

  function addTrackingInput(prefill) {
    var html = [
      '<div class="input-group input-group-sm mb-2">',
      '<input type="text" class="form-control tracking-input" placeholder="Enter tracking number or URL..." value="', prefill ? $('<div>').text(String(prefill)).html() : '', '">',
      '<div class="input-group-append">',
      '<button class="btn btn-outline-danger btn-sm" type="button" data-action="tracking-remove"><i class="fa fa-times"></i></button>',
      '</div></div>'
    ].join('');
    $('#tracking-items').append(html);
  }

  function collectTrackingNumbers() {
    var trackingNumbers = [];
    $('#tracking-items .tracking-input').each(function () {
      var v = String($(this).val() || '').trim();
      if (v) trackingNumbers.push(v);
    });
    return trackingNumbers;
  }

  // ---------------------------------------------------------------------------
  // Shipping helpers
  // ---------------------------------------------------------------------------
  function renderPackageRow(pkg) {
    return [
      '<tr>',
      '<td><input class="form-control form-control-sm sl-name" value="' + (pkg.name || 'Box') + '"></td>',
      '<td><input class="form-control form-control-sm sl-l" type="number" step="0.1" min="0" value="' + (pkg.length || '') + '"></td>',
      '<td><input class="form-control form-control-sm sl-w" type="number" step="0.1" min="0" value="' + (pkg.width || '') + '"></td>',
      '<td><input class="form-control form-control-sm sl-h" type="number" step="0.1" min="0" value="' + (pkg.height || '') + '"></td>',
      '<td><input class="form-control form-control-sm sl-kg" type="number" step="0.01" min="0" value="' + (pkg.weight || '') + '"></td>',
      '<td class="text-end"><button type="button" class="btn btn-outline-secondary btn-sm sl-remove">Remove</button></td>',
      '</tr>'
    ].join('');
  }

  function collectShippingDraft() {
    if (!$('#ship-labels-card').length) return null;
    var shipping = {
      deliveryMode: $('#sl-delivery-mode').val() || 'courier',
      carrier: $('#sl-carrier').val() || 'manual',
      service: $('#sl-service').val() || '',
      signature: $('#sl-signature').is(':checked'),
      saturday: $('#sl-saturday').is(':checked'),
      atl: $('#sl-atl').is(':checked'),
      instructions: $('#sl-instructions').val() || '',
      printer: $('#sl-printer').val() || '',
      overrideVisible: !$('#sl-address').hasClass('d-none'),
      recipient: {
        name: $('#sl-name').val() || '',
        company: $('#sl-company').val() || '',
        email: $('#sl-email').val() || '',
        phone: $('#sl-phone').val() || '',
        street1: $('#sl-street1').val() || '',
        street2: $('#sl-street2').val() || '',
        suburb: $('#sl-suburb').val() || '',
        city: $('#sl-city').val() || '',
        state: $('#sl-state').val() || '',
        postcode: $('#sl-postcode').val() || '',
        country: $('#sl-country').val() || 'NZ'
      },
      packages: []
    };

    $('#sl-packages tbody tr').each(function () {
      var $r = $(this);
      shipping.packages.push({
        name: $r.find('.sl-name').val() || 'Box',
        length: $r.find('.sl-l').val() || '',
        width: $r.find('.sl-w').val() || '',
        height: $r.find('.sl-h').val() || '',
        weight: $r.find('.sl-kg').val() || ''
      });
    });
    return shipping;
  }

  function applyShippingDraft(shipping) {
    if (!shipping) return;
    $('#sl-delivery-mode').val(shipping.deliveryMode || 'courier');
    $('#sl-carrier').val(shipping.carrier || 'manual');
    $('#sl-service').val(shipping.service || '');
    $('#sl-signature').prop('checked', !!shipping.signature);
    $('#sl-saturday').prop('checked', !!shipping.saturday);
    $('#sl-atl').prop('checked', !!shipping.atl);
    $('#sl-instructions').val(shipping.instructions || '');
    $('#sl-printer').val(shipping.printer || '');

    if (shipping.overrideVisible) $('#sl-address').removeClass('d-none');
    else $('#sl-address').addClass('d-none');

    if (shipping.recipient) {
      $('#sl-name').val(shipping.recipient.name || '');
      $('#sl-company').val(shipping.recipient.company || '');
      $('#sl-email').val(shipping.recipient.email || '');
      $('#sl-phone').val(shipping.recipient.phone || '');
      $('#sl-street1').val(shipping.recipient.street1 || '');
      $('#sl-street2').val(shipping.recipient.street2 || '');
      $('#sl-suburb').val(shipping.recipient.suburb || '');
      $('#sl-city').val(shipping.recipient.city || '');
      $('#sl-state').val(shipping.recipient.state || '');
      $('#sl-postcode').val(shipping.recipient.postcode || '');
      $('#sl-country').val(shipping.recipient.country || 'NZ');
    }

    var $tbody = $('#sl-packages tbody');
    $tbody.empty();
    if (Array.isArray(shipping.packages) && shipping.packages.length) {
      shipping.packages.forEach(function (pkg) {
        $tbody.append(renderPackageRow(pkg));
      });
    }
        if ($tbody.find('tr').length === 0) {
          $tbody.append(renderPackageRow({ name: 'Box', length: '', width: '', height: '', weight: '' }));
        }

    updateCarrierTheme($('#sl-carrier').val());
  }

  function nzPostRecalc() {
    var l = parseFloat($('#nzpost-length').val()) || 0;
    var w = parseFloat($('#nzpost-width').val()) || 0;
    var h = parseFloat($('#nzpost-height').val()) || 0;
    var weight = parseFloat($('#nzpost-weight').val()) || 0;
    if (!(l && w && h && weight)) {
      $('#nzpost-cost-display').text('$0.00');
      return;
    }
    var volKg = (l * w * h) / 5000;
    var chargeKg = Math.max(weight, volKg);
    var service = $('#nzpost-service-type').val();
    var base = 0;
    if (service === 'CPOLE') base = Math.max(8.50, chargeKg * 4.20);
    else if (service === 'CPOLP') base = Math.max(12.50, chargeKg * 6.80);
    else base = Math.max(15.00, chargeKg * 8.50);
    var fuel = base * 0.15;
    var gst = (base + fuel) * 0.15;
    var total = base + fuel + gst;
    $('#nzpost-cost-display').text('$' + total.toFixed(2));
  }

  function gssRecalc() {
    var l = parseFloat($('#gss-length').val()) || 0;
    var w = parseFloat($('#gss-width').val()) || 0;
    var h = parseFloat($('#gss-height').val()) || 0;
    var weight = parseFloat($('#gss-weight').val()) || 0;
    if (!(l && w && h && weight)) {
      $('#gss-cost-display').text('$0.00');
      return;
    }
    var vol = (l * w * h) / 4000;
    var charge = Math.max(weight, vol);
    var total = Math.max(8.50, charge * 2.80);
    $('#gss-cost-display').text('$' + total.toFixed(2));
  }

  // ---------------------------------------------------------------------------
  // Draft persistence
  // ---------------------------------------------------------------------------
  function buildDraft() {
    var draft = {
      version: 2,
      transferId: $('#transferID').val() || '0',
      quantities: {},
      notes: $('#notesForTransfer').val() || '',
      trackingNumbers: collectTrackingNumbers(),
      shipping: collectShippingDraft()
    };

    $table.find('tbody tr').each(function () {
      var $r = $(this);
      var productID = $r.find('.productID').val() || $r.find('input[data-item]').data('item');
      var counted = $r.find('input[type="number"]').val();
      if (productID && counted !== '') {
        draft.quantities[productID] = counted;
      }
    });

    if (draft.shipping) {
      draft.deliveryMode = draft.shipping.deliveryMode;
    }

    return draft;
  }

  function persistDraftToStorage() {
    saveDebounceTimer = null;
    try {
      var payload = buildDraft();
      payload.timestamp = Date.now();
      localStorage.setItem(draftKey, CIS.util.safeStringify(payload));
      lastSavedTimestamp = payload.timestamp;
      updateLastSavedDisplay(lastSavedTimestamp);
      if (indicatorResetTimer) clearTimeout(indicatorResetTimer);
      indicatorResetTimer = setTimeout(markIndicatorIdle, 600);
    } catch (err) {
      console.warn('[Transfers/Pack] draft save failed', err);
      markIndicatorError();
    }
  }

  function scheduleDraftSave() {
    markIndicatorSaving();
    if (saveDebounceTimer) clearTimeout(saveDebounceTimer);
    saveDebounceTimer = setTimeout(persistDraftToStorage, 600);
  }

  function flushDraftSave() {
    markIndicatorSaving();
    if (saveDebounceTimer) clearTimeout(saveDebounceTimer);
    persistDraftToStorage();
  }

  function loadStoredValues() {
    try {
      var raw = localStorage.getItem(draftKey);
      if (!raw) {
        updateLastSavedDisplay(null);
        markIndicatorIdle();
        return;
      }
      var data = CIS.util.safeParse(raw, null);
      if (!data) return;

      if (data.quantities) {
        $table.find('tbody tr').each(function () {
          var $r = $(this);
          var productID = $r.find('.productID').val() || $r.find('input[data-item]').data('item');
          var val = data.quantities[productID];
          if (typeof val !== 'undefined') {
            var input = $r.find('input[type="number"]').first()[0];
            input.value = String(val);
            syncPrintValue(input);
            checkInvalidQty(input);
          }
        });
      }
      if (data.notes) $('#notesForTransfer').val(String(data.notes));
      if (data.deliveryMode) $('input[name="delivery-mode"][value="' + data.deliveryMode + '"]').prop('checked', true);
      if (data.shipping) applyShippingDraft(data.shipping);

      if (Array.isArray(data.trackingNumbers)) {
        $('#tracking-items').empty();
        data.trackingNumbers.forEach(function (v) { addTrackingInput(v); });
        updateTrackingCount();
      }

      lastSavedTimestamp = data.timestamp || null;
      updateLastSavedDisplay(lastSavedTimestamp);
      markIndicatorIdle();
    } catch (e) {
      console.warn('[Transfers/Pack] draft load failed', e);
      markIndicatorError();
    }
  }

  // ---------------------------------------------------------------------------
  // Submission
  // ---------------------------------------------------------------------------
  function validateQuantities() {
    var offenders = [];
    $table.find('tbody tr').each(function () {
      var $row = $(this);
      var $input = $row.find('input[type="number"]');
      if ($input.hasClass('is-invalid')) {
        offenders.push($row.find('td:nth-child(2)').text().trim());
      }
    });
    if (offenders.length) {
      throw new Error('Please fix quantity errors for: ' + offenders.slice(0, 3).join(', ') + (offenders.length > 3 ? '...' : ''));
    }
  }

  function collectItemsForSubmit() {
    var items = [];
    $table.find('tbody tr').each(function () {
      var $r = $(this);
      var id = $r.find('.productID').val() || $r.find('input[data-item]').data('item');
      if (!id) return;
      var qty = parseInt($r.find('input[type="number"]').val(), 10) || 0;
      items.push({ id: id, qty_sent_total: qty });
    });
    return items;
  }

  function markReadyForDelivery(triggerBtn) {
    try {
      validateQuantities();
    } catch (e) {
      CIS.ui.toast(e.message || 'Please fix quantity errors', 'error');
      return;
    }

    var items = collectItemsForSubmit();
    var payload = { items: items };
    var $actionButtons = $('#createTransferButton, #savePack, #markReadyForDelivery');
    var $primaryBtn = $(triggerBtn);
    if (!$primaryBtn.length) {
      $primaryBtn = $('#markReadyForDelivery');
    }

    $actionButtons.prop('disabled', true);
    if ($primaryBtn.length) {
      if (!$primaryBtn.data('default-html')) {
        $primaryBtn.data('default-html', $primaryBtn.html());
      }
      $primaryBtn.html('<i class="fa fa-spinner fa-spin"></i> Processing...');
    }

    CIS.http.postJSON('', payload)
      .done(function (res) {
        if (res && res.success) {
          try { localStorage.removeItem(draftKey); } catch (e) {}
          CIS.ui.toast('Transfer saved (PACKAGED).', 'success');
          setTimeout(function () { window.location.reload(); }, 800);
        } else {
          CIS.ui.toast((res && (res.error || res.message)) || 'Save failed', 'error');
        }
      })
      .fail(function (xhr) {
        var msg = 'Network or server error.';
        if (xhr && xhr.responseJSON && xhr.responseJSON.error) msg = xhr.responseJSON.error;
        CIS.ui.toast(msg, 'error');
      })
      .always(function () {
        $actionButtons.prop('disabled', false);
        $actionButtons.each(function () {
          var $btn = $(this);
          var defaultHtml = $btn.data('default-html');
          if (defaultHtml) {
            $btn.html(defaultHtml);
          }
        });
      });
  }

  // ---------------------------------------------------------------------------
  // Init
  // ---------------------------------------------------------------------------
  Pack.init = function () {
    $table = $('#transfer-table');
    $indicator = $('#draft-indicator');
    $indicatorText = $('#draft-indicator-text');
    $shipCard = $('#ship-labels-card');

    var transferId = $('#transferID').val() || '0';
    draftKey = 'stock_transfer_' + String(transferId || '0');

    $(document).on('input', '#transfer-table input[type="number"]', function () {
      enforceBounds(this);
      syncPrintValue(this);
      checkInvalidQty(this);
      recomputeTotals();
      scheduleDraftSave();
    });

    $(document).on('click', '[data-action="remove-product"]', function () { removeProduct(this); });
    $('#autofillFromPlanned').on('click', autofillCountedFromPlanned);

    $(document).on('keydown', function (e) {
      if (e.ctrlKey && e.key.toLowerCase() === 's') { e.preventDefault(); flushDraftSave(); }
      if (e.shiftKey && (e.key === 'F' || e.key === 'f')) { e.preventDefault(); autofillCountedFromPlanned(); }
    });

    $(document).on('click', '#btn-add-tracking', function () {
      addTrackingInput('');
      updateTrackingCount();
      scheduleDraftSave();
    });
    $(document).on('click', '[data-action="tracking-remove"]', function () {
      $(this).closest('.input-group').remove();
      updateTrackingCount();
      scheduleDraftSave();
    });
    $(document).on('input', '.tracking-input', CIS.util.debounce(function () {
      updateTrackingCount();
      scheduleDraftSave();
    }, 300));

    $('#notesForTransfer').on('input', CIS.util.debounce(scheduleDraftSave, 300));

    $('#sl-delivery-mode, #sl-carrier, #sl-service, #sl-signature, #sl-saturday, #sl-atl, #sl-instructions, #sl-printer')
      .on('change input', function () {
        if (this.id === 'sl-carrier') updateCarrierTheme(this.value);
        scheduleDraftSave();
      });

    $(document).on('click', '#sl-override', function () {
      scheduleDraftSave();
    });

    $(document).on('input change', '#sl-address input', CIS.util.debounce(scheduleDraftSave, 250));
    $(document).on('input change', '#sl-packages tbody input', CIS.util.debounce(scheduleDraftSave, 250));
    $(document).on('click', '#sl-add, #sl-copy, #sl-clear, .sl-remove', function () {
      setTimeout(scheduleDraftSave, 10);
    });

    if (CIS.util.exists('#courier-service')) {
      $('#courier-service').on('change', function () {
        scheduleDraftSave();
        var v = $(this).val();
        $('.courier-panel').hide();
        if (v === 'gss') $('#gss-panel').show();
        else if (v === 'nzpost') $('#nzpost-panel').show();
        else if (v === 'manual') $('#manual-panel').show();
        try { localStorage.setItem('vs_courier_service', v || ''); } catch (e) {}
      });
      $('#courier-service').trigger('change');
    }

    $(document).on('input', '#nzpost-length,#nzpost-width,#nzpost-height,#nzpost-weight,#nzpost-service-type', CIS.util.debounce(function () {
      nzPostRecalc();
      scheduleDraftSave();
    }, 200));
    $(document).on('input', '#gss-length,#gss-width,#gss-height,#gss-weight', CIS.util.debounce(function () {
      gssRecalc();
      scheduleDraftSave();
    }, 200));

    $('#savePack, #createTransferButton, #markReadyForDelivery').on('click', function () {
      flushDraftSave();
      markReadyForDelivery(this);
    });

    $('#savePack, #createTransferButton, #markReadyForDelivery').each(function () {
      var $btn = $(this);
      if (!$btn.data('default-html')) {
        $btn.data('default-html', $btn.html());
      }
    });

    (function initLabelPanel() {
      var $transferInput = $('#transferID');
      var transferId = parseInt($transferInput.val(), 10) || (window.TID ? parseInt(window.TID, 10) : 0);
      var $modal = $('#boxLabelPreviewModal');
  var previewBtn = document.getElementById('btn-preview-labels');
  var printBtn = document.getElementById('btn-print-labels');
  var countInput = document.getElementById('box-count-input') || document.getElementById('box-label-count');
      var frame = document.getElementById('boxLabelPreviewFrame');
      var loader = document.getElementById('boxLabelPreviewLoader');
      if (!previewBtn || !$modal.length || !countInput || !frame) return;
      if (!transferId) {
        previewBtn.setAttribute('disabled', 'disabled');
        if (printBtn) printBtn.setAttribute('disabled', 'disabled');
        return;
      }

      var minBoxes = parseInt(countInput.getAttribute('min'), 10) || 1;
      var maxBoxes = parseInt(countInput.getAttribute('max'), 10) || 99;

      var buildUrl = function (mode) {
        var total = Math.max(minBoxes, Math.min(maxBoxes, parseInt(countInput.value, 10) || minBoxes));
        var params = new URLSearchParams();
        params.set('transfer', transferId.toString());
        params.set('n', total.toString());
        if (mode === 'preview') {
          params.set('preview', '1');
        }
        return '/modules/transfers/stock/print/box_slip.php?' + params.toString();
      };

      var clampInput = function () {
        var val = parseInt(countInput.value, 10);
        if (!isFinite(val) || val < minBoxes) val = minBoxes;
        if (val > maxBoxes) val = maxBoxes;
        countInput.value = String(val);
      };

      countInput.addEventListener('change', clampInput);
      countInput.addEventListener('blur', clampInput);

      if (frame) {
        frame.addEventListener('load', function () {
          if (!loader) return;
          loader.classList.add('d-none');
        });
      }

      if (previewBtn) {
        previewBtn.addEventListener('click', function () {
          clampInput();
          if (loader) loader.classList.remove('d-none');
          frame.setAttribute('src', buildUrl('preview'));
          $modal.modal('show');
        });
      }

      if (printBtn) {
        printBtn.addEventListener('click', function () {
          clampInput();
          window.open(buildUrl('preview'), '_blank', 'noopener');
        });
      }

      $modal.on('hidden.bs.modal', function () {
        frame.setAttribute('src', 'about:blank');
        if (loader) loader.classList.remove('d-none');
      });
    })();

    $table.find('input[type="number"]').each(function () { syncPrintValue(this); });
    loadStoredValues();
    recomputeTotals();
    updateCarrierTheme($('#sl-carrier').val());

    window.enforceBounds = enforceBounds;
    window.syncPrintValue = syncPrintValue;
    window.checkInvalidQty = checkInvalidQty;
    window.removeProduct = removeProduct;
    window.autofillCountedFromPlanned = autofillCountedFromPlanned;
    window.recomputeTotals = recomputeTotals;
    window.markReadyForDelivery = markReadyForDelivery;

    Pack.scheduleDraftSave = scheduleDraftSave;
    Pack.flushDraftSave = flushDraftSave;
    Pack.updateCarrierTheme = updateCarrierTheme;

    console.log('[Transfers/Pack] init complete.');
  };

  $(function () { Pack.init(); });
  window.TransfersPack = Pack;

})(window, window.jQuery);
