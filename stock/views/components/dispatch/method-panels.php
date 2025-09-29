<?php
/**
 * Alternative Method Panels Component
 * 
 * Pickup, Internal, Drop-off, and Manual tracking panels
 */
?>

<!-- Pickup Method Panel -->
<div id="blkPickup" hidden>
  <div style="display:grid;gap:8px">
    <label class="mb-0 w-100">Picked up by
      <input id="pickupBy" class="form-control form-control-sm" placeholder="Driver / Company">
    </label>
    <label class="mb-0 w-100">Contact phone
      <input id="pickupPhone" class="form-control form-control-sm" placeholder="+64…">
    </label>
    <label class="mb-0 w-100">Pickup time
      <input id="pickupTime" class="form-control form-control-sm" type="datetime-local">
    </label>
    <label class="mb-0 w-100">Parcels
      <input id="pickupPkgs" class="form-control form-control-sm" type="number" min="1" value="1">
    </label>
    <label class="mb-0 w-100">Notes
      <textarea id="pickupNotes" class="form-control form-control-sm" rows="2"></textarea>
    </label>
  </div>
  <div style="display:flex;justify-content:flex-end;margin-top:10px">
    <button class="btn primary" id="btnSavePickup" type="button">Save Pickup</button>
  </div>
</div>

<!-- Internal Method Panel -->
<div id="blkInternal" hidden>
  <div style="display:grid;gap:8px">
    <label class="mb-0 w-100">Driver/Van
      <input id="intCarrier" class="form-control form-control-sm" placeholder="Internal run name">
    </label>
    <label class="mb-0 w-100">Depart
      <input id="intDepart" class="form-control form-control-sm" type="datetime-local">
    </label>
    <label class="mb-0 w-100">Boxes
      <input id="intBoxes" class="form-control form-control-sm" type="number" min="1" value="1">
    </label>
    <label class="mb-0 w-100">Notes
      <textarea id="intNotes" class="form-control form-control-sm" rows="2"></textarea>
    </label>
  </div>
  <div style="display:flex;justify-content:flex-end;margin-top:10px">
    <button class="btn primary" id="btnSaveInternal" type="button">Save Internal</button>
  </div>
</div>

<!-- Drop-off Method Panel -->
<div id="blkDropoff" hidden>
  <div style="display:grid;gap:8px">
    <label class="mb-0 w-100">Drop-off location
      <input id="dropLocation" class="form-control form-control-sm" placeholder="NZ Post / NZC depot">
    </label>
    <label class="mb-0 w-100">When
      <input id="dropWhen" class="form-control form-control-sm" type="datetime-local">
    </label>
    <label class="mb-0 w-100">Boxes
      <input id="dropBoxes" class="form-control form-control-sm" type="number" min="1" value="1">
    </label>
    <label class="mb-0 w-100">Notes
      <textarea id="dropNotes" class="form-control form-control-sm" rows="2"></textarea>
    </label>
  </div>
  <div style="display:flex;justify-content:flex-end;margin-top:10px">
    <button class="btn primary" id="btnSaveDrop" type="button">Save Drop-off</button>
  </div>
</div>

<!-- Manual Tracking Panel -->
<div id="blkManual" hidden>
  <div class="hdr" style="margin:6px 0">Manual Tracking</div>
  <div style="display:grid;gap:8px">
    <label class="mb-0 w-100">Carrier
      <select id="mtCarrier" class="form-control form-control-sm">
        <option>NZ Post</option>
        <option>NZ Couriers</option>
      </select>
    </label>
    <label class="mb-0 w-100">Tracking #
      <input id="mtTrack" class="form-control form-control-sm" placeholder="Ticket / tracking number">
    </label>
  </div>
  <div style="display:flex;justify-content:flex-end;margin-top:10px">
    <button class="btn primary" id="btnSaveManual" type="button">Save Number</button>
  </div>
</div>