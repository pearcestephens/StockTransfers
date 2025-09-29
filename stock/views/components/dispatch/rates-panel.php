<?php
/**
 * Rates & Options Panel Component
 * 
 * Right panel of dispatch console for courier options and rates
 * 
 * Required variables inherited from parent:
 * - $dispatch_config (all config)
 * - $showCourierDetail
 */

$showCourierDetail = $dispatch_config['show_courier_detail'] ?? false;
$transferId = $dispatch_config['transfer_id'] ?? 0;
$fromOutlet = $dispatch_config['from_outlet'] ?? '';
$toOutlet = $dispatch_config['to_outlet'] ?? '';
$printPoolOnline = $dispatch_config['print_pool']['online'] ?? false;

// Manual summary data
$manualSummary = $dispatch_config['manual_summary'] ?? [];
$manualSummaryWeightLabel = $manualSummary['weight_label'] ?? '—';
$manualSummaryBoxesLabel = $manualSummary['boxes_label'] ?? '—';
?>

<aside class="card" aria-label="Options & Rates" style="position:relative">
  <header>
    <div class="hdr">Options & Rates</div>
    <span class="badge">Incl GST</span>
  </header>

  <div class="blocker" id="uiBlock">
    <div class="msg">
      <div style="font-weight:700;margin-bottom:6px">Print pool offline</div>
      <div class="sub" style="margin-bottom:10px">Switched to Manual Tracking mode.</div>
      <button class="btn small" id="dismissBlock" type="button">Ok</button>
    </div>
  </div>

  <div class="body">
    <div style="display:grid;gap:10px;margin-bottom:10px">
      <!-- Courier options (only shown in full courier mode) -->
      <div style="display:flex;gap:12px;flex-wrap:wrap"<?= $showCourierDetail ? '' : ' hidden'; ?>>
        <label><input type="checkbox" id="optSig" checked> Sig</label>
        <label><input type="checkbox" id="optATL"> ATL</label>
        <label title="R18 disabled for B2B"><input type="checkbox" id="optAge" disabled> R18</label>
        <label><input type="checkbox" id="optSat"> Saturday</label>
      </div>

      <!-- Reviewed By (manual/pickup/internal/dropoff modes) -->
      <div id="reviewedWrap" style="display:none">
        <label class="w-100 mb-0">Reviewed By
          <input id="reviewedBy" class="form-control form-control-sm" placeholder="Staff initials / name">
        </label>
      </div>

      <!-- Address facts (courier mode only) -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;background:#fcfdff;border:1px solid var(--line);border-radius:12px;padding:8px"<?= $showCourierDetail ? '' : ' hidden'; ?>>
        <div>
          <b>Address facts</b>
          <div class="sub">Rural: <span id="factRural" class="mono">—</span></div>
          <div class="sub">Saturday serviceable: <span id="factSat" class="mono">—</span></div>
        </div>
        <div>
          <b>Notes</b>
          <div class="sub">Saturday auto-disables if address isn't serviceable.</div>
        </div>
      </div>
    </div>

    <!-- Courier rates and manual summary -->
    <div id="blkCourier">
      <!-- Manual courier summary (simplified mode) -->
      <?php if (!$showCourierDetail): ?>
        <div class="card border-0 shadow-sm mb-3" style="background:linear-gradient(135deg,#f9fafc,#fff);">
          <div class="card-body py-3">
            <div class="psx-manual-summary">
              <div class="text-uppercase text-secondary small psx-summary-label">Transfer summary</div>
              <div class="psx-summary-grid">
                <div class="psx-summary-row">
                  <span class="psx-summary-key text-muted">From</span>
                  <span class="psx-summary-val"><?= htmlspecialchars($fromOutlet, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div class="psx-summary-row">
                  <span class="psx-summary-key text-muted">To</span>
                  <span class="psx-summary-val"><?= htmlspecialchars($toOutlet, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div class="psx-summary-row">
                  <span class="psx-summary-key text-muted">Transfer</span>
                  <span class="psx-summary-val">#<?= (int)$transferId ?></span>
                </div>
                <div class="psx-summary-row">
                  <span class="psx-summary-key text-muted">Estimated weight</span>
                  <span class="psx-summary-val"><?= htmlspecialchars($manualSummaryWeightLabel, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div class="psx-summary-row">
                  <span class="psx-summary-key text-muted">Estimated boxes required</span>
                  <span class="psx-summary-val"><?= htmlspecialchars($manualSummaryBoxesLabel, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
              </div>
            </div>
            
            <div class="d-flex align-items-center justify-content-between flex-wrap" style="gap:12px;">
              <div>
                <h3 class="h6 mb-1 text-uppercase text-secondary" style="letter-spacing:0.08em;">Sent via Manual Courier</h3>
                <p class="mb-0 text-muted small">Pick the handover option so Ops can see how this consignment is leaving the warehouse.</p>
              </div>
              <div style="min-width:220px;">
                <label class="w-100 mb-0">
                  <span class="text-muted small d-block mb-1">Manual courier method</span>
                  <select id="manualCourierPreset" class="form-control form-control-sm">
                    <option value="">Select an option…</option>
                    <option value="nzpost_manifest">NZ Post Manifested</option>
                    <option value="nzpost_counter">NZ Post Counter Drop-off</option>
                    <option value="nzc_pickup">NZ Couriers Pick-up</option>
                    <option value="third_party">Third-party / Other</option>
                  </select>
                </label>
              </div>
            </div>
            
            <div class="manual-courier-status" id="manualCourierStatus" role="status" aria-live="polite">
              <span class="status-dot"></span>
              <span>Select a manual courier method to confirm the handover.</span>
            </div>
            
            <div class="manual-courier-extra" id="manualCourierExtraWrap" hidden>
              <label class="w-100 mb-0">
                <span class="text-muted small d-block mb-1">Describe third-party courier</span>
                <input id="manualCourierExtraDetail" class="form-control form-control-sm" placeholder="Carrier name / reference">
              </label>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <!-- Live courier rates (full mode) -->
      <div class="rates" id="ratesList"<?= $showCourierDetail ? '' : ' hidden'; ?>></div>
      
      <!-- Rate summary (full mode) -->
      <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px"<?= $showCourierDetail ? '' : ' hidden'; ?>>
        <div>
          <div class="sub">Selected</div>
          <div id="sumCarrier" style="font-weight:700">—</div>
          <div id="sumService" class="sub">—</div>
        </div>
        <div style="text-align:right">
          <div class="sub">Total (Incl GST)</div>
          <div id="sumTotal" style="font-weight:700;font-size:18px">$0.00</div>
        </div>
      </div>

      <!-- Main action buttons -->
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:10px">
        <?php if ($showCourierDetail): ?>
          <button class="btn" id="btnPrintOnly" type="button">Print only</button>
        <?php endif; ?>
        <button class="btn primary" id="btnPrintPacked" type="button">
          <?= $showCourierDetail ? 'Print &amp; Mark Packed' : 'Mark as Packed' ?>
        </button>
      </div>
      
      <!-- Help text -->
      <div class="print-help" id="printActionHelp" role="note">
        <div class="print-help-icon" aria-hidden="true">i</div>
        <div class="print-help-body">
          <div class="print-help-title">Packed vs Packed &amp; Sent</div>
          <ul class="print-help-list">
            <li><strong>Mark as Packed</strong> prints paperwork and keeps the transfer in the warehouse queue for later dispatch.</li>
            <li><strong>Mark as Packed &amp; Sent</strong> records the handover, stores the courier method + tracking numbers, and signals Ops that it has physically left.</li>
          </ul>
        </div>
      </div>
    </div>

    <!-- Alternative method panels -->
    <?php include __DIR__ . '/method-panels.php'; ?>
  </div>
</aside>