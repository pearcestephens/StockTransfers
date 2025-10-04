<?php
$__txId   = isset($txId) ? (int)$txId : (int)($_GET['transfer'] ?? 0);
$__userId = (int)($_SESSION['user']['id'] ?? (defined('CIS_USER_ID') ? CIS_USER_ID : 0));
if ($__txId <= 0) { echo '<div class="alert alert-danger">Courier Console requires a valid transfer id.</div>'; return; }
?>
<div id="courier-console" class="card shadow-sm mt-4" data-transfer-id="<?php echo $__txId; ?>" data-user-id="<?php echo $__userId; ?>">
  <div class="card-header d-flex align-items-center justify-content-between flex-wrap">
    <div class="h5 mb-0 text-white">Courier Console</div>
    <div class="btn-group btn-group-sm" role="group" aria-label="mode">
      <button class="btn btn-outline-light active" data-mode="auto">Auto</button>
      <button class="btn btn-outline-light" data-mode="manual">Manual</button>
      <button class="btn btn-outline-light" data-mode="dropoff">Drop‑off</button>
      <button class="btn btn-outline-light" data-mode="pickup">Pickup</button>
      <button id="cx-settings" class="btn btn-warning"><i class="fa fa-sliders-h"></i></button>
    </div>
  </div>
  <div class="card-body p-0">
    <div class="row no-gutters">
      <div class="col-lg-4 p-3 border-right">
        <div><strong>Suggested</strong> <span id="cx-status" class="badge badge-secondary">Booting…</span></div>
        <div id="cx-suggest" class="small text-muted mt-2">Auto‑pack + live rates coming…</div>
        <div class="mt-3">
          <button id="cx-print-pack" class="btn btn-success btn-block" disabled>Print & Pack</button>
          <button id="cx-print-only" class="btn btn-outline-success btn-block mt-2" disabled>Print Only</button>
          <div class="d-flex justify-content-between mt-2">
            <button id="cx-reprint" class="btn btn-light btn-sm" disabled>Reprint</button>
            <button id="cx-cancel" class="btn btn-outline-danger btn-sm" disabled>Cancel</button>
          </div>
        </div>
      </div>
      <div class="col-lg-8 p-3">
        <div class="mb-3">
          <div class="d-flex justify-content-between"><strong>Parcels</strong>
            <div class="btn-group btn-group-sm">
              <button id="cx-auto-pack" class="btn btn-outline-primary">Auto</button>
              <button id="cx-add-satchel" class="btn btn-outline-primary">Satchel</button>
              <button id="cx-add-box" class="btn btn-outline-primary">Box</button>
            </div>
          </div>
          <div class="table-responsive mt-2">
            <table class="table table-sm table-hover mb-0">
              <thead><tr><th>#</th><th>Type</th><th>Dims (mm)</th><th>Weight (g)</th><th>Assign</th><th></th></tr></thead>
              <tbody id="cx-parcel-rows"></tbody>
            </table>
          </div>
          <div class="small text-muted mt-2">Satchel ≤2kg; otherwise box (+ box adder); staff can adjust.</div>
        </div>
        <div class="mb-3">
          <div class="d-flex justify-content-between"><strong>Rates</strong>
            <div>
              <label class="mr-3 small"><input type="checkbox" id="cx-sig" checked> Signature</label>
              <label class="mr-3 small"><input type="checkbox" id="cx-sat"> Saturday</label>
              <span class="small text-muted">R18 disabled</span>
            </div>
          </div>
          <div class="table-responsive mt-2">
            <table class="table table-sm table-striped mb-0">
              <thead><tr><th></th><th>Carrier</th><th>Service</th><th>Container</th><th>Cost</th><th>Notes</th></tr></thead>
              <tbody id="cx-rate-rows"><tr><td colspan="6" class="text-muted">No rates yet.</td></tr></tbody>
            </table>
          </div>
        </div>
        <div>
          <div class="d-flex justify-content-between"><strong>Destination</strong><button id="cx-edit-address" class="btn btn-light btn-sm">Edit / Validate</button></div>
          <div class="small text-muted">Preloaded from outlet destination; change only if courier rejects.</div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Settings Drawer -->
<div class="modal fade" id="cxSettingsModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-slideout modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Courier Settings</h5><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button></div>
      <div class="modal-body">
        <div class="row">
          <div class="col-md-6">
            <h6>Packaging</h6>
            <div class="form-group form-check"><input type="checkbox" class="form-check-input" id="prefSatchel" checked> <label class="form-check-label" for="prefSatchel">Prefer satchel</label></div>
            <div class="form-group"><label>Box weight adder (g)</label><input type="number" class="form-control form-control-sm" id="boxAdder" value="300"></div>
            <div class="form-group"><label>Volumetric divisor</label><input type="number" class="form-control form-control-sm" id="volDiv" value="5000"></div>
          </div>
          <div class="col-md-6">
            <h6>Rules</h6>
            <div class="form-group form-check"><input type="checkbox" class="form-check-input" id="forceSignature" checked> <label class="form-check-label" for="forceSignature">Force Signature</label></div>
            <div class="form-group form-check"><input type="checkbox" class="form-check-input" id="allowSaturday"> <label class="form-check-label" for="allowSaturday">Allow Saturday</label></div>
            <div class="form-group"><label>Preferred carrier</label><select id="prefCarrier" class="form-control form-control-sm"><option value="auto" selected>Auto</option><option value="GSS">NZ Couriers (GSS)</option><option value="NZ_POST">NZ Post</option></select></div>
          </div>
        </div>
      </div>
      <div class="modal-footer"><button class="btn btn-light" data-dismiss="modal">Close</button><button id="saveSettings" class="btn btn-primary">Save</button></div>
    </div>
  </div>
</div>

<!-- Address Modal -->
<div class="modal fade" id="cxAddressModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Edit / Validate Address</h5><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button></div>
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group col-md-6"><label>Name</label><input id="addr_name" class="form-control"></div>
          <div class="form-group col-md-6"><label>Company</label><input id="addr_company" class="form-control"></div>
          <div class="form-group col-md-8"><label>Address 1</label><input id="addr_line1" class="form-control"></div>
          <div class="form-group col-md-4"><label>Address 2</label><input id="addr_line2" class="form-control"></div>
          <div class="form-group col-md-4"><label>Suburb</label><input id="addr_suburb" class="form-control"></div>
          <div class="form-group col-md-4"><label>City</label><input id="addr_city" class="form-control"></div>
          <div class="form-group col-md-4"><label>Postcode</label><input id="addr_postcode" class="form-control"></div>
          <div class="form-group col-md-6"><label>Email</label><input id="addr_email" class="form-control"></div>
          <div class="form-group col-md-6"><label>Phone</label><input id="addr_phone" class="form-control"></div>
        </div>
        <div class="d-flex align-items-center"><button id="addrValidate" class="btn btn-outline-primary mr-2">Validate</button><div id="addrStatus" class="small text-muted">NZ Post address suggestions supported via backend.</div></div>
      </div>
      <div class="modal-footer"><button class="btn btn-light" data-dismiss="modal">Close</button><button id="addrSave" class="btn btn-primary">Save</button></div>
    </div>
  </div>
</div>

<link rel="stylesheet" href="/modules/transfers/stock/assets/css/courier_console.css?v=showcase2">
<script src="/modules/transfers/stock/assets/js/courier_console.js?v=showcase2" defer></script>

<span id="cx-asset-ok" style="position:absolute;right:10px;top:10px;font:12px system-ui;background:#aaa;color:#fff;padding:2px 6px;border-radius:10px;">
  assets…
</span>
<script>
  (function(){
    var ok = document.getElementById('cx-asset-ok');
    if (!ok) return;
    // JS loaded if this runs; CSS test via style probe
    var cssOk = getComputedStyle(document.querySelector('#courier-console .table thead th')||document.body).backgroundColor !== 'rgba(0, 0, 0, 0)';
    ok.textContent = 'assets ' + (cssOk ? 'OK' : 'JS only');
    ok.style.background = cssOk ? '#28a745' : '#fd7e14';
  })();
</script>

