/**
 * CIS — Transfers » Stock » Pack Modern (Client-Side Logic)
 * =========================================================================
 * 
 * Features:
 * - Draft autosave with localStorage
 * - Real-time validation
 * - Product search with multi-select
 * - Quantity management
 * - Tracking number management
 * - Box label printing
 * - Transfer submission
 * 
 * @version 2.0
 * @date 2025-10-03
 */

(function() {
  'use strict';

  // ===== CONFIGURATION =====
  const CONFIG = {
    autosaveInterval: 30000, // 30 seconds
    searchDebounceMs: 300,
    apiTimeout: 30000
  };

  // ===== STATE =====
  const state = {
    transferId: $('#transferID').val(),
    draftKey: null,
    autosaveTimer: null,
    searchTimeout: null,
    searchCache: new Map(),
    selectedProducts: new Set(),
    warnState: {
      blank: false,
      negative: false,
      suspect: false
    }
  };

  // Initialize draft key
  state.draftKey = 'stock_transfer_' + state.transferId;

  // ===== AJAX SETUP =====
  $.ajaxSetup({
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    cache: false,
    timeout: CONFIG.apiTimeout
  });

  // ===== UTILITY FUNCTIONS =====

  function showToast(message, type = 'info') {
    const safeMessage = $('<div>').text(message).html();
    const cls = {
      'success': 'bg-success',
      'warning': 'bg-warning',
      'error': 'bg-danger',
      'info': 'bg-info'
    }[type] || 'bg-info';

    if ($('#toast-container').length === 0) {
      $('body').append('<div id="toast-container" style="position:fixed; top:20px; right:20px; z-index:9999;"></div>');
    }

    const id = 'toast-' + Date.now();
    const html = `
      <div id="${id}" class="toast align-items-center text-white ${cls} border-0 mb-2" role="alert">
        <div class="d-flex">
          <div class="toast-body">${safeMessage}</div>
          <button type="button" class="ml-2 mb-1 close text-white" data-dismiss="toast">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
      </div>`;

    $('#toast-container').append(html);
    const $toast = $('#' + id);
    $toast.toast({ delay: 3600 }).toast('show');
    setTimeout(() => $toast.remove(), 4000);
  }

  function debounce(func, wait) {
    let timeout;
    return function(...args) {
      clearTimeout(timeout);
      timeout = setTimeout(() => func.apply(this, args), wait);
    };
  }

  // ===== TABLE MANAGEMENT =====

  window.syncPrintValue = function(input) {
    $(input).siblings('.counted-print-value').text(input.value || '0');
  };

  window.enforceBounds = function(input) {
    const max = parseInt(input.getAttribute('max')) || 999999;
    const min = parseInt(input.getAttribute('min')) || 0;
    const v = parseInt(input.value);
    if (Number.isFinite(v)) {
      if (v > max) input.value = max;
      if (v < min) input.value = min;
    }
  };

  window.checkInvalidQty = function(input) {
    const $input = $(input);
    const $row = $input.closest('tr');
    const inventory = parseInt($row.attr('data-inventory')) || 0;
    const planned = parseInt($row.attr('data-planned')) || 0;
    const raw = String(input.value || '').trim();

    // Clear previous badges
    $row.find('.badge-to-remove').remove();
    $row.removeClass('table-secondary table-warning');

    if (raw === '') {
      $input.addClass('is-invalid');
      $row.addClass('table-warning');
      if (!state.warnState.blank) {
        showToast('⚠️ Some quantities are blank', 'warning');
        state.warnState.blank = true;
      }
      return;
    }

    const counted = Number(raw);
    if (!Number.isFinite(counted)) {
      $input.addClass('is-invalid');
      $row.addClass('table-warning');
      return;
    }

    if (counted < 0) {
      $input.addClass('is-invalid');
      $row.addClass('table-warning');
      if (!state.warnState.negative) {
        showToast('❌ Negative quantities not allowed', 'error');
        state.warnState.negative = true;
      }
      return;
    }

    if (counted === 0) {
      $input.removeClass('is-invalid is-warning');
      $row.addClass('table-secondary');
      $row.find('td:first').append('<span class="badge badge-secondary badge-to-remove ml-1">Zero</span>');
      return;
    }

    if (counted > inventory) {
      $input.addClass('is-invalid');
      $row.addClass('table-warning');
      return;
    }

    // Check suspicious values
    const suspicious = counted >= 99 || (planned > 0 && counted >= planned * 3) || (inventory > 0 && counted >= inventory * 2);
    if (suspicious) {
      $input.removeClass('is-invalid').addClass('is-warning');
      if (!state.warnState.suspect) {
        showToast('⚠️ Some quantities seem unusually high', 'warning');
        state.warnState.suspect = true;
      }
    } else {
      $input.removeClass('is-invalid is-warning');
    }
  };

  window.recomputeTotals = function() {
    let plannedTotal = 0;
    let countedTotal = 0;
    let rows = 0;

    $('#transfer-table tbody tr').each(function() {
      const $row = $(this);
      if ($row.find('td').length < 3) return; // Skip empty state row
      
      plannedTotal += parseInt($row.attr('data-planned')) || 0;
      countedTotal += parseInt($row.find('.pack-qty-input').val()) || 0;
      rows++;
    });

    const diff = countedTotal - plannedTotal;
    const accuracy = plannedTotal > 0 ? ((countedTotal / plannedTotal) * 100).toFixed(1) : 100;

    $('#plannedTotal').text(plannedTotal.toLocaleString());
    $('#countedTotal').text(countedTotal.toLocaleString());
    $('#summary-counted').text(countedTotal.toLocaleString());
    $('#diffTotal').text(diff >= 0 ? '+' + diff : diff);
    $('#itemsToTransfer').text(rows);
    $('#summary-accuracy').text(accuracy);

    const $diff = $('#diffTotal');
    if (diff > 0) $diff.css('color', '#dc3545');
    else if (diff < 0) $diff.css('color', '#fd7e14');
    else $diff.css('color', '#28a745');

    const $accuracy = $('#summary-accuracy').parent();
    if (accuracy >= 100) $accuracy.removeClass('text-warning').addClass('text-success');
    else $accuracy.removeClass('text-success').addClass('text-warning');
  };

  window.removeProduct = function(btn) {
    if (!confirm('Remove this product from the transfer?')) return;
    $(btn).closest('tr').remove();
    recomputeTotals();
    addToLocalStorage();
    showToast('Product removed', 'info');
  };

  window.autofillCountedFromPlanned = function() {
    $('#transfer-table tbody tr').each(function() {
      const $row = $(this);
      const $input = $row.find('.pack-qty-input');
      if (!$input.length) return;
      
      const planned = parseInt($row.attr('data-planned')) || 0;
      const inventory = parseInt($row.attr('data-inventory')) || 0;
      const value = Math.min(planned, inventory);
      
      $input.val(value);
      syncPrintValue($input[0]);
      checkInvalidQty($input[0]);
    });
    recomputeTotals();
    addToLocalStorage();
    showToast('Quantities auto-filled from planned amounts', 'success');
  };

  // ===== DRAFT MANAGEMENT =====

  function addToLocalStorage() {
    const quantities = {};
    $('#transfer-table tbody tr').each(function() {
      const $row = $(this);
      const productId = $row.find('.productID').val();
      const counted = $row.find('.pack-qty-input').val();
      if (productId && counted !== '') {
        quantities[productId] = counted;
      }
    });

    const trackingNumbers = [];
    $('.tracking-input').each(function() {
      const val = $(this).val().trim();
      if (val) trackingNumbers.push(val);
    });

    const data = {
      quantities,
      notes: $('#notesForTransfer').val(),
      trackingNumbers,
      timestamp: Date.now()
    };

    try {
      localStorage.setItem(state.draftKey, JSON.stringify(data));
      $('#draft-status').text('Draft: Saved').removeClass('badge-secondary').addClass('badge-success');
      $('#btn-restore-draft, #btn-discard-draft').prop('disabled', false);
      $('#draft-last-saved').text('Last saved: ' + new Date(data.timestamp).toLocaleTimeString());
    } catch (e) {
      console.warn('Failed to save draft', e);
    }
  }

  function loadStoredValues() {
    const saved = localStorage.getItem(state.draftKey);
    if (!saved) return false;

    try {
      const data = JSON.parse(saved);
      
      if (data.quantities) {
        Object.keys(data.quantities).forEach(productId => {
          const $input = $(`.productID[value="${productId}"]`).closest('tr').find('.pack-qty-input');
          if ($input.length) {
            $input.val(data.quantities[productId]);
            syncPrintValue($input[0]);
            checkInvalidQty($input[0]);
          }
        });
      }

      if (data.notes) {
        $('#notesForTransfer').val(data.notes);
      }

      if (Array.isArray(data.trackingNumbers)) {
        data.trackingNumbers.forEach(num => addTrackingInput(num));
      }

      $('#draft-status').text('Draft: Saved').removeClass('badge-secondary').addClass('badge-success');
      $('#btn-restore-draft, #btn-discard-draft').prop('disabled', false);
      if (data.timestamp) {
        $('#draft-last-saved').text('Last saved: ' + new Date(data.timestamp).toLocaleTimeString());
      }

      return true;
    } catch (e) {
      console.warn('Failed to load draft', e);
      return false;
    }
  }

  window.saveDraft = function() {
    addToLocalStorage();
    showToast('Draft saved', 'success');
  };

  window.restoreDraft = function() {
    if (!confirm('Restore saved draft? Current unsaved changes will be lost.')) return;
    
    // Clear current tracking inputs
    $('#tracking-items').empty();
    
    loadStoredValues();
    recomputeTotals();
    showToast('Draft restored', 'info');
  };

  window.discardDraft = function() {
    if (!confirm('Discard saved draft? This cannot be undone.')) return;

    try {
      localStorage.removeItem(state.draftKey);
    } catch (e) {
      console.warn('Failed to remove draft', e);
    }

    $('#draft-status').text('Draft: Off').removeClass('badge-success').addClass('badge-secondary');
    $('#draft-last-saved').text('Not saved');
    $('#btn-restore-draft, #btn-discard-draft').prop('disabled', true);
    
    // Clear inputs
    $('#transfer-table tbody tr .pack-qty-input').val('').each(function() {
      syncPrintValue(this);
    });
    $('#notesForTransfer').val('');
    $('#tracking-items').empty();
    
    recomputeTotals();
    showToast('Draft discarded', 'warning');
  };

  function toggleAutosave() {
    const enabled = $('#toggle-autosave').is(':checked');
    
    if (enabled) {
      if (state.autosaveTimer) clearInterval(state.autosaveTimer);
      state.autosaveTimer = setInterval(addToLocalStorage, CONFIG.autosaveInterval);
      showToast('Auto-save enabled', 'info');
    } else {
      if (state.autosaveTimer) {
        clearInterval(state.autosaveTimer);
        state.autosaveTimer = null;
      }
      showToast('Auto-save disabled', 'info');
    }
  }

  // ===== TRACKING MANAGEMENT =====

  function updateTrackingCount() {
    const count = $('.tracking-input').length;
    $('#tracking-count').text(`(${count} number${count !== 1 ? 's' : ''})`);
  }

  function addTrackingInput(value = '') {
    const id = 'tracking-' + Date.now() + Math.random().toString(36).substr(2, 5);
    const html = `
      <div class="input-group mb-2" data-tracking-id="${id}">
        <input type="text" class="form-control tracking-input" 
               placeholder="Enter tracking number or URL..." 
               value="${value ? $('<div>').text(value).html() : ''}"
               oninput="addToLocalStorage()">
        <div class="input-group-append">
          <button class="btn btn-outline-danger" type="button" onclick="removeTrackingInput('${id}')">
            <i class="fa fa-times"></i>
          </button>
        </div>
      </div>`;
    
    $('#tracking-items').append(html);
    updateTrackingCount();
    
    if (!value) {
      $('#tracking-items .tracking-input').last().focus();
    }
  }

  window.addTrackingInput = addTrackingInput;

  window.removeTrackingInput = function(id) {
    $(`[data-tracking-id="${id}"]`).remove();
    updateTrackingCount();
    addToLocalStorage();
  };

  // ===== PRODUCT SEARCH =====

  async function searchProducts() {
    const query = $('#search-input').val().trim();
    
    if (query.length < 2) {
      $('#search-status').html('<i class="fa fa-search fa-3x mb-3"></i><p>Type at least 2 characters to search...</p>');
      $('#productAddSearch').addClass('d-none');
      return;
    }

    // Check cache
    if (state.searchCache.has(query)) {
      displaySearchResults(state.searchCache.get(query));
      return;
    }

    $('#search-status').html('<i class="fa fa-spinner fa-spin fa-2x mb-3"></i><p>Searching...</p>');

    try {
      const response = await $.ajax({
        url: window.DISPATCH_BOOT.api_endpoints.product_search,
        method: 'POST',
        dataType: 'json',
        data: JSON.stringify({
          keyword: query,
          outletID: $('#sourceID').val()
        }),
        contentType: 'application/json'
      });

      const results = response.data || response.results || [];
      state.searchCache.set(query, results);
      displaySearchResults(results);
      
    } catch (error) {
      console.error('Search error:', error);
      $('#search-status').html('<i class="fa fa-exclamation-triangle fa-2x mb-3 text-warning"></i><p>Search failed. Please try again.</p>');
    }
  }

  function displaySearchResults(results) {
    if (!results || results.length === 0) {
      $('#search-status').html('<i class="fa fa-inbox fa-2x mb-3"></i><p>No products found</p>');
      $('#productAddSearch').addClass('d-none');
      $('#results-count').text('0 results');
      return;
    }

    let html = '';
    results.forEach(product => {
      const stock = product.stock || product.inventory || 0;
      const disabled = stock <= 0;
      
      html += `
        <tr class="search-result-row" data-product-id="${product.id}">
          <td>
            <input type="checkbox" class="product-select-checkbox" ${disabled ? 'disabled' : ''}>
          </td>
          <td>
            <strong>${$('<div>').text(product.name).html()}</strong>
            ${product.sku ? '<br><small class="text-muted">SKU: ' + $('<div>').text(product.sku).html() + '</small>' : ''}
          </td>
          <td class="text-center">
            <span class="badge ${stock > 0 ? 'badge-success' : 'badge-secondary'}">${stock}</span>
          </td>
          <td class="text-center">
            <button type="button" class="btn btn-sm btn-primary btn-add-product"
                    data-product-id="${product.id}"
                    data-product-name="${$('<div>').text(product.name).html()}"
                    data-product-stock="${stock}"
                    data-product-sku="${product.sku || ''}"
                    ${disabled ? 'disabled' : ''}>
              <i class="fa fa-plus"></i> Add
            </button>
          </td>
        </tr>`;
    });

    $('#productAddSearchBody').html(html);
    $('#productAddSearch').removeClass('d-none');
    $('#search-status').html('');
    $('#results-count').text(`${results.length} results`);
  }

  // ===== PRODUCT ADDITION =====

  function addProductToTransfer($btn) {
    const productId = $btn.data('product-id');
    const productName = $btn.data('product-name');
    const productStock = parseInt($btn.data('product-stock'), 10) || 0;
    const productSku = $btn.data('product-sku') || '';

    // Check if already in transfer
    const existing = $(`.productID[value="${productId}"]`).closest('tr');
    if (existing.length > 0) {
      existing.addClass('table-warning');
      setTimeout(() => existing.removeClass('table-warning'), 1200);
      showToast(`${productName} is already in this transfer`, 'warning');
      existing.find('.pack-qty-input').focus().select();
      return;
    }

    const rowIndex = $('#transfer-table tbody tr').length + 1;
    const transferId = state.transferId;

    const newRow = `
      <tr data-product-id="${productId}" data-inventory="${productStock}" data-planned="0" class="table-success">
        <td class="text-center align-middle">
          <input type="hidden" class="productID" value="${productId}">
          <i class="fas fa-grip-vertical text-muted"></i>
        </td>
        <td>
          <strong>${$('<div>').text(productName).html()}</strong>
          ${productSku ? '<br><small class="text-muted">SKU: ' + $('<div>').text(productSku).html() + '</small>' : ''}
        </td>
        <td class="text-center inv">${productStock}</td>
        <td class="text-center planned">0</td>
        <td class="counted-td text-center">
          <input type="number" class="form-control form-control-sm text-center pack-qty-input" 
                 value="0" min="0" max="${productStock}"
                 oninput="syncPrintValue(this); checkInvalidQty(this); recomputeTotals();">
          <span class="counted-print-value d-none">0</span>
        </td>
        <td class="text-center">
          <span class="badge badge-secondary id-counter">${transferId}-${rowIndex}</span>
        </td>
        <td class="text-center">
          <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeProduct(this)" title="Remove">
            <i class="fas fa-trash"></i>
          </button>
        </td>
      </tr>`;

    $('#transfer-table tbody').append(newRow);
    
    setTimeout(() => {
      $('#transfer-table tbody tr:last').removeClass('table-success');
    }, 1200);

    recomputeTotals();
    addToLocalStorage();

    $btn.prop('disabled', true).html('<i class="fa fa-check"></i> Added').removeClass('btn-primary').addClass('btn-outline-secondary');
    showToast(`${productName} added to transfer`, 'success');
  }

  // ===== BOX LABELS =====

  window.openLabelPrintDialog = function() {
    const boxCount = Math.max(1, parseInt($('#box-count-input').val(), 10) || 1);
    const transferId = state.transferId;
    const fromName = window.DISPATCH_BOOT.from_outlet.name;
    const toName = window.DISPATCH_BOOT.to_outlet.name;

    const w = window.open('', 'labels', 'width=900,height=700');
    const styles = `
      <style>
        @page { size: A4; margin: 12mm; }
        body { font-family: Arial, sans-serif; }
        .label { border: 2px solid #000; padding: 20px; margin-bottom: 20px; page-break-after: always; }
        .l1 { font-size: 32px; font-weight: 800; text-align: center; }
        .l2 { font-size: 24px; font-weight: 700; margin-top: 10px; text-align: center; }
        .l3 { font-size: 20px; font-weight: 600; margin-top: 15px; }
        .muted { color: #555; font-size: 14px; margin-top: 8px; }
      </style>`;

    let html = `<html><head><title>Box Labels - Transfer #${transferId}</title>${styles}</head><body>`;
    
    for (let i = 1; i <= boxCount; i++) {
      html += `
        <div class="label">
          <div class="l1">TRANSFER #${transferId}</div>
          <div class="l2">Box ${i} of ${boxCount}</div>
          <div class="l3">FROM: ${$('<div>').text(fromName).html()}</div>
          <div class="l3">TO: ${$('<div>').text(toName).html()}</div>
          <div class="muted">Date: ${new Date().toLocaleDateString()}</div>
        </div>`;
    }
    
    html += `</body></html>`;
    w.document.write(html);
    w.document.close();
    setTimeout(() => w.print(), 200);
  };

  // ===== TRANSFER SUBMISSION =====

  window.markReadyForDelivery = async function() {
    if (window.PACK_ONLY) {
      showToast('This transfer is already sent and cannot be modified', 'warning');
      return;
    }

    // Validate quantities
    let hasErrors = false;
    $('#transfer-table tbody tr .pack-qty-input').each(function() {
      if ($(this).hasClass('is-invalid')) {
        hasErrors = true;
        return false;
      }
    });

    if (hasErrors) {
      showToast('Please fix quantity errors before continuing', 'error');
      return;
    }

    // Collect quantities
    const quantities = [];
    $('#transfer-table tbody tr').each(function() {
      const $row = $(this);
      const productId = $row.find('.productID').val();
      if (!productId) return;
      
      const counted = parseInt($row.find('.pack-qty-input').val()) || 0;
      if (counted > 0) {
        quantities.push({
          product_id: productId,
          counted: counted
        });
      }
    });

    if (quantities.length === 0) {
      if (!confirm('No quantities entered. Mark this transfer as ready anyway?')) {
        return;
      }
    }

    // Collect tracking
    const trackingNumbers = [];
    $('.tracking-input').each(function() {
      const val = $(this).val().trim();
      if (val) trackingNumbers.push(val);
    });

    const payload = {
      transfer_id: state.transferId,
      quantities: quantities,
      notes: $('#notesForTransfer').val().trim(),
      trackingNumbers: trackingNumbers
    };

    $('#createTransferButton').prop('disabled', true).html('<i class="fa fa-spinner fa-spin mr-2"></i>Processing...');

    try {
      const response = await $.ajax({
        url: window.DISPATCH_BOOT.api_endpoints.send,
        method: 'POST',
        dataType: 'json',
        contentType: 'application/json',
        data: JSON.stringify(payload)
      });

      if (response.ok || response.success) {
        // Clear draft
        try {
          localStorage.removeItem(state.draftKey);
        } catch (e) {}

        showToast('Transfer marked as ready! Redirecting...', 'success');
        setTimeout(() => {
          window.location.href = '/modules/transfers/stock/list.php';
        }, 1500);
      } else {
        throw new Error(response.error?.message || response.error || 'Failed to submit transfer');
      }
    } catch (error) {
      console.error('Submission error:', error);
      const msg = error.responseJSON?.error?.message || error.message || 'Failed to submit transfer. Please try again.';
      showToast(msg, 'error');
      $('#createTransferButton').prop('disabled', false).html('<i class="fas fa-check mr-2"></i>Mark Ready for Delivery');
    }
  };

  // ===== DELETE TRANSFER =====

  window.deleteTransfer = async function(transferId) {
    if (!confirm('Delete this transfer? This action cannot be undone.')) return;

    try {
      const response = await $.ajax({
        url: window.DISPATCH_BOOT.api_endpoints.delete_transfer,
        method: 'POST',
        dataType: 'json',
        contentType: 'application/json',
        data: JSON.stringify({ transfer_id: transferId })
      });

      if (response.ok || response.success) {
        showToast('Transfer deleted', 'success');
        setTimeout(() => {
          window.location.href = '/modules/transfers/stock/list.php';
        }, 1000);
      } else {
        throw new Error(response.error?.message || 'Failed to delete transfer');
      }
    } catch (error) {
      console.error('Delete error:', error);
      const msg = error.responseJSON?.error?.message || error.message || 'Failed to delete transfer';
      showToast(msg, 'error');
    }
  };

  // ===== INITIALIZATION =====

  $(document).ready(function() {
    console.log('[Pack Modern] Initializing...');

    // Load draft if exists
    const draftLoaded = loadStoredValues();
    recomputeTotals();

    if (!draftLoaded) {
      // Add first tracking input if no draft
      addTrackingInput();
    }

    // Event handlers
    $('#btn-save-draft').on('click', saveDraft);
    $('#btn-restore-draft').on('click', restoreDraft);
    $('#btn-discard-draft').on('click', discardDraft);
    $('#toggle-autosave').on('change', toggleAutosave);
    
    $('#btn-autofill').on('click', autofillCountedFromPlanned);
    $('#btn-prune-zeros').on('click', function() {
      let removed = 0;
      $('#transfer-table tbody tr').each(function() {
        const counted = parseInt($(this).find('.pack-qty-input').val()) || 0;
        if (counted === 0) {
          $(this).remove();
          removed++;
        }
      });
      if (removed > 0) {
        recomputeTotals();
        addToLocalStorage();
        showToast(`${removed} product${removed === 1 ? '' : 's'} with zero count removed`, 'info');
      } else {
        showToast('No products with zero count found', 'info');
      }
    });

    $('#btn-add-product, #btn-add-first-product').on('click', function() {
      $('#addProductsModal').modal('show');
    });

    $('#btn-add-tracking').on('click', () => addTrackingInput());
    
    // Search
    const debouncedSearch = debounce(searchProducts, CONFIG.searchDebounceMs);
    $('#search-input').on('input', debouncedSearch);
    
    $('#btn-clear-search').on('click', function() {
      $('#search-input').val('');
      $('#search-status').html('<i class="fa fa-search fa-3x mb-3"></i><p>Type at least 2 characters to search...</p>');
      $('#productAddSearch').addClass('d-none');
    });

    // Product addition
    $(document).on('click', '.btn-add-product', function() {
      addProductToTransfer($(this));
    });

    // Modal focus
    $('#addProductsModal').on('shown.bs.modal', function() {
      $('#search-input').focus();
    });

    // Keyboard shortcuts
    $(document).on('keydown', function(e) {
      if (e.ctrlKey && e.key.toLowerCase() === 's') {
        e.preventDefault();
        addToLocalStorage();
        showToast('Draft saved', 'success');
      }
    });

    console.log('[Pack Modern] Ready');
  });

})();
