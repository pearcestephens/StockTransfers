/* ==========================================================================
   CIS Transfers — RECEIVE Page
   Depends: jQuery, /assets/js/transfers-common.js
   ========================================================================== */
(function (window, $) {
  'use strict';

  if (!window.CIS || !window.CIS.http) {
    console.error('[Transfers/Receive] CIS common not loaded.');
    return;
  }

  var $table;

  function enforceBounds(input) {
    var max = parseInt(input.getAttribute('max'), 10); if (!isFinite(max)) max = 999999;
    var min = parseInt(input.getAttribute('min'), 10); if (!isFinite(min)) min = 0;
    var v = parseInt(input.value, 10);
    if (isFinite(v)) {
      if (v > max) input.value = String(max);
      if (v < min) input.value = String(min);
    }
  }

  function recomputeTotals() {
    var sentTotal = 0, recvTotal = 0;
    $table.find('tbody tr').each(function () {
      var sent = parseInt($(this).attr('data-sent'), 10) || 0;
      var recv = parseInt($(this).find('input[type="number"]').val(), 10) || 0;
      var rem = Math.max(0, sent - recv);
      $(this).find('.rem').text(rem);
      sentTotal += sent;
      recvTotal += recv;
    });
    $('#sentTotal').text(sentTotal.toLocaleString());
    $('#recvTotal').text(recvTotal.toLocaleString());
    $('#remainingTotal').text(Math.max(0, sentTotal - recvTotal).toLocaleString());
  }

  function collectItems() {
    var items = [];
    $table.find('tbody tr').each(function () {
      var id = $(this).find('.itemID').val();
      var recv = parseInt($(this).find('input[type="number"]').val(), 10) || 0;
      items.push({ id: id, qty_received_total: recv });
    });
    return items;
  }

  function saveReceive() {
    var $btn = $('#saveReceive');
    $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Saving...');

    CIS.http.postJSON('', { items: collectItems() })
      .done(function (res) {
        if (res && res.success) {
          CIS.ui.toast('Receive saved (' + (res.state || 'updated') + ').', 'success');
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
        $btn.prop('disabled', false).html('<i class="fa fa-check mr-1"></i> Save Receive');
      });
  }

  function init() {
    $table = $('#receive-table');

    // Wire inputs
    $(document).on('input', '#receive-table input[type="number"]', function () {
      enforceBounds(this);
      recomputeTotals();
    });

    // Primary action
    $('#saveReceive').on('click', saveReceive);

    // First compute
    recomputeTotals();

    console.log('[Transfers/Receive] init complete.');
  }

  $(function () { init(); });

})(window, window.jQuery);
