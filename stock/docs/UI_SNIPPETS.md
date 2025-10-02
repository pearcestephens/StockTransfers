# Transfer Pack UI Snippets

Copy/paste friendly HTML patterns aligned with `pack-system-ui.css`.
All snippets assume inclusion of:

```
<link rel="stylesheet" href="/modules/transfers/stock/assets/css/pack-system-ui.min.css" integrity="<!-- optional SRI -->" />
<script src="/modules/transfers/stock/assets/js/pack-unified.min.js"></script>
```

## 1. Wrapper + Status Bar
```
<div class="transfer-pack">
  <div class="transfer-pack__status-bar">
    <span class="status-item">
      <span id="lockStatusBadge" class="transfer-pack__lock-badge badge badge-secondary">LOCK?</span>
    </span>
    <span class="status-item">
      <span id="autosavePill" class="autosave-pill status-idle">
        <span id="autosavePillText">IDLE</span>
      </span>
    </span>
    <span class="status-item u-soft-muted" id="lastSaveTime">Last updated: --:--:--</span>
    <span class="ml-auto d-flex u-gap-sm">
      <button class="btn btn-sm btn-outline-primary" id="autofillBtn"><i class="fa fa-magic mr-1"></i>Autofill</button>
      <button class="btn btn-sm btn-outline-secondary" id="resetBtn"><i class="fa fa-undo mr-1"></i>Reset</button>
      <button class="btn btn-sm btn-outline-info" id="headerAddProductBtn"><i class="fa fa-plus mr-1"></i>Add Product</button>
    </span>
  </div>
```

## 2. Scrollable Table Shell
```
<div class="transfer-pack-table-wrapper transfer-pack-table-wrapper--scroll">
  <table class="transfer-pack-table table-sm" id="packItemsTable">
    <thead>
      <tr>
        <th style="width:40px;">#</th>
        <th>Product</th>
        <th style="width:120px;">Planned</th>
        <th style="width:120px;">Counted</th>
        <th style="width:120px;">Status</th>
      </tr>
    </thead>
    <tbody>
      <!-- Rows rendered by PHP loop; apply qty-* classes via JS -->
      <tr data-product-id="123" class="qty-neutral">
        <td>1</td>
        <td>
          <div class="u-truncate" title="Sample Product Name">Sample Product Name</div>
          <div class="u-soft-muted">SKU1234</div>
        </td>
        <td class="text-center"><span class="u-inline-badge">24</span></td>
        <td class="text-center transfer-pack__qty-input">
          <input type="number" class="form-control form-control-sm qty-input" data-planned="24" min="0" />
        </td>
        <td class="text-center"><span class="badge badge-secondary">Pending</span></td>
      </tr>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="2" class="text-right font-weight-bold">Totals</td>
        <td class="text-center"><span id="plannedTotalFooter">--</span></td>
        <td class="text-center"><span id="countedTotalFooter">--</span></td>
        <td class="text-center"><span id="diffTotalFooter" class="text-muted">0</span></td>
      </tr>
    </tfoot>
  </table>
</div>
```

## 3. Product Search Modal
```
<div class="modal fade" id="addProdModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title mb-0">Add Product</h6>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span>&times;</span>
        </button>
      </div>
      <div class="modal-body p-2">
        <div class="input-group input-group-sm mb-2">
          <input type="text" id="productSearchInput" class="form-control" placeholder="Search product name / SKU" autocomplete="off" />
          <div class="input-group-append">
            <button class="btn btn-outline-secondary" id="clearSearchBtn" type="button"><i class="fa fa-times"></i></button>
          </div>
        </div>
        <div id="productSearchResults" class="border rounded" style="max-height:320px; overflow:auto; -webkit-overflow-scrolling:touch;">
          <div class="text-center text-muted py-4">
            <i class="fa fa-search fa-2x mb-2"></i>
            <p class="mb-0">Start typing to search for products...</p>
          </div>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button class="btn btn-sm btn-outline-secondary" data-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>
```

## 4. Lock Violation Overlay (injected dynamically)
JS creates an element with id `lockViolationOverlay`. Example for reference only:
```
<div id="lockViolationOverlay" class="alert alert-warning border-warning shadow-sm" style="position:fixed; top:80px; right:20px; z-index:1050; max-width:350px; font-size:.9rem;">
  ...content...
</div>
```

## 5. Utility Class Usage
```
<div class="transfer-pack d-flex u-gap">
  <div class="card flex-fill">
    <div class="card-body p-2">
      <h6 class="mb-2">Left Pane <span class="u-inline-badge">A</span></h6>
      <p class="u-soft-muted mb-0">Some supporting text that truncates if too long for width.</p>
    </div>
  </div>
  <div class="card flex-fill">
    <div class="card-body p-2">
      <h6 class="mb-2">Right Pane</h6>
      <p class="u-soft-muted mb-0">Additional content.</p>
    </div>
  </div>
</div>
```

## 6. Recommended Script Ordering
Place scripts near end of body to allow boot sequence ordering:
```
<script src="/modules/transfers/stock/assets/js/pack-unified.min.js"></script>
<script src="/modules/transfers/stock/assets/js/pack-bootstrap.module.js" type="module"></script>
<script>
  // Optional: custom inline hooks
  if(window.packSystem?.config?.debug){ console.log('Pack system debug active'); }
</script>
```

## 7. Notes
- Quantity row class updates are handled by JS; ensure server output does not pre-color rows incorrectly.
- For accessibility, ensure modals have appropriate `aria` attributes (Bootstrap already wires most of these).
- Keep product images constrained to 50px square to maintain consistent scannability.

---
Update & extend snippets as new patterns emerge.
