/**
 * form-serializer.js
 * Extracted module for gathering transfer pack form data.
 * Provides a single responsibility: collect counted quantities + notes safely.
 * Non-destructive: original gatherFormData in pack-unified.js falls back to legacy logic if this file fails to load.
 */
(function(window){
  'use strict';
  function parseQty(val){
    if (val == null) return 0; const n = parseInt(String(val).trim(),10); return Number.isFinite(n)?n:0;
  }
  const FormSerializer = {
    gatherFormData(transferId){
      const data = { transfer_id: transferId, timestamp: new Date().toISOString() };
      const quantities = {};
      document.querySelectorAll('.pack-quantity-input, .qty-input, input[name^="counted_qty"]').forEach(input => {
        let productId = input.dataset.productId || input.getAttribute('data-product-id');
        if (!productId) {
          const row = input.closest('tr');
            if (row && row.dataset.productId) productId = row.dataset.productId;
        }
        if (!productId) {
          const m = input.name && input.name.match(/counted_qty\[([^\]]+)\]/);
          if (m) productId = m[1];
        }
        if (productId && input.value && input.value.trim() !== '') {
          quantities[productId] = parseQty(input.value);
        }
      });
      data.counted_qty = quantities;
      const notesInput = document.querySelector('#notesForTransfer, [name="notes"]');
      if (notesInput && notesInput.value) data.notes = notesInput.value;
      return data;
    }
  };
  window.PackModules = window.PackModules || {};
  // Idempotent attach
  if (!window.PackModules.FormSerializer) {
    window.PackModules.FormSerializer = FormSerializer;
  }
})(window);
