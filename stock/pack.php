<?php
/**
 * CIS — Transfers » Stock » Pack
 *
 * This file renders the Pack & Ship page for a specific transfer.
 * - Strict login + access guards
 * - Safe, predictable autoload for Modules\
 * - Defensive rendering of transfer context
 * - Semantic, accessible HTML; no third-party HTTP calls
 * - Robust in-page diagnostics (“Terminal”) for JS/API/config issues
 *
 * IMPORTANT:
 * - This page does NOT talk directly to carriers. All carrier actions are via your existing API endpoints.
 * - External JS/CSS links at the bottom are kept; page works even if any are missing (terminal shows warnings).
 */

declare(strict_types=1);

// --------------------------------------------------------------------------------------
// Bootstrap & Guards
// --------------------------------------------------------------------------------------
@date_default_timezone_set('Pacific/Auckland');

// Core app
$DOCUMENT_ROOT = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
if ($DOCUMENT_ROOT === '' || !is_dir($DOCUMENT_ROOT)) {
  http_response_code(500);
  echo "Server misconfiguration: DOCUMENT_ROOT not set.";
  exit;
}
require_once $DOCUMENT_ROOT . '/app.php';

// PSR-4ish autoloader for Modules\
spl_autoload_register(static function (string $class): void {
  $prefix = 'Modules\\';
  if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;

  $rel = substr($class, strlen($prefix));
  // Normalize to path fragments; prevent directory traversal
  $rel = str_replace(['\\', "\0"], ['/', ''], $rel);
  $rel = ltrim($rel, '/');

  // Search patterns (conservative)
  $base = defined('MODULES_PATH') ? MODULES_PATH : (__DIR__ . '/..');
  $candidates = [
    $base . '/' . $rel . '.php',
    $base . '/' . strtolower($rel) . '.php',
  ];

  // Also try splitting path to support Name/Space/Class layout with lowercase dirs
  $parts = explode('/', $rel);
  if (count($parts) > 1) {
    $file = array_pop($parts);
    $dir  = strtolower(implode('/', $parts));
    $candidates[] = $base . '/' . $dir . '/' . $file . '.php';
  }

  foreach ($candidates as $p) {
    if (is_string($p) && strlen($p) && is_file($p)) {
      require_once $p;
      return;
    }
  }
});

// Shared helpers
require_once $DOCUMENT_ROOT . '/assets/functions/config.php';
require_once $DOCUMENT_ROOT . '/assets/functions/JsonGuard.php';
require_once $DOCUMENT_ROOT . '/assets/functions/ApiResponder.php';
require_once $DOCUMENT_ROOT . '/assets/functions/HttpGuard.php';
require_once $DOCUMENT_ROOT . '/modules/transfers/stock/lib/AccessPolicy.php';

use Modules\Transfers\Stock\Services\TransfersService;
use Modules\Transfers\Stock\Lib\AccessPolicy;

// Session & login
if (empty($_SESSION['userID'])) {
  // Use a safe 302 to login; do not leak internal path
  http_response_code(302);
  header('Location: /login.php');
  exit;
}
$userId = (int)$_SESSION['userID'];

// Incoming transfer id (GET only; sanitize hard)
$transferId = 0;
if (isset($_GET['transfer'])) {
  $transferId = (int)$_GET['transfer'];
} elseif (isset($_GET['t'])) {
  $transferId = (int)$_GET['t'];
}
if ($transferId <= 0) {
  http_response_code(400);
  echo 'Missing ?transfer id';
  exit;
}

// Access control (for this user & transfer)
if (!AccessPolicy::canAccessTransfer($userId, $transferId)) {
  http_response_code(403);
  echo 'Forbidden';
  exit;
}

// Load transfer data
$svc = new TransfersService();
$transfer = $svc->getTransfer($transferId);
if (!$transfer || !is_array($transfer)) {
  http_response_code(404);
  echo 'Transfer not found';
  exit;
}

// --------------------------------------------------------------------------------------
// Safe Rendering Helpers
// --------------------------------------------------------------------------------------
/**
 * Clean arbitrary mixed->string without HTML; flatten JSON-ish text if detected.
 */
function tfx_clean_text(mixed $value): string {
  $text = trim((string)$value);
  if ($text === '') return '';
  $first = $text[0] ?? '';
  if ($first === '{' || $first === '[') {
    $decoded = json_decode($text, true);
    if (is_array($decoded)) {
      $flat = [];
      array_walk_recursive($decoded, static function ($node) use (&$flat): void {
        if ($node === null) return;
        $node = trim((string)$node);
        if ($node !== '') $flat[] = $node;
      });
      if ($flat) $text = implode(', ', $flat);
    }
  }
  return trim($text);
}

/** Return first non-empty (after clean) candidate. */
function tfx_first(array $candidates): string {
  foreach ($candidates as $c) {
    $t = tfx_clean_text($c);
    if ($t !== '') return $t;
  }
  return '';
}

/** Render product cell HTML (escaped). */
function tfx_render_product_cell(array $item): string {
  $name    = tfx_first([$item['product_name'] ?? null, $item['name'] ?? null, $item['title'] ?? null]);
  $variant = tfx_first([$item['product_variant'] ?? null, $item['variant_name'] ?? null, $item['variant'] ?? null]);
  $sku     = tfx_clean_text($item['product_sku'] ?? $item['sku'] ?? $item['variant_sku'] ?? '');
  $id      = tfx_clean_text($item['product_id'] ?? $item['vend_product_id'] ?? $item['variant_id'] ?? '');
  if ($sku === '' && $id !== '') $sku = $id;

  $primary = $name !== '' ? $name : ($variant !== '' ? $variant : ($sku !== '' ? $sku : 'Product'));
  $primary = htmlspecialchars($primary, ENT_QUOTES, 'UTF-8');
  $skuLine = $sku !== '' ? '<div class="tfx-product-sku text-muted small">SKU: ' . htmlspecialchars($sku, ENT_QUOTES, 'UTF-8') . '</div>' : '';

  return '<div class="tfx-product-cell"><strong class="tfx-product-name">' . $primary . '</strong>' . $skuLine . '</div>';
}

// --------------------------------------------------------------------------------------
// Derived Transfer Fields (escaped)
// --------------------------------------------------------------------------------------
$txId    = (int)($transfer['id'] ?? $transferId);
$items   = is_array($transfer['items'] ?? null) ? $transfer['items'] : [];

$fromRaw = (string)($transfer['outlet_from_name'] ?? $transfer['outlet_from'] ?? '');
$toRaw   = (string)($transfer['outlet_to_name']   ?? $transfer['outlet_to']   ?? '');

$fromLbl = htmlspecialchars($fromRaw !== '' ? $fromRaw : (string)($transfer['outlet_from'] ?? ''), ENT_QUOTES, 'UTF-8');
$toLbl   = htmlspecialchars($toRaw   !== '' ? $toRaw   : (string)($transfer['outlet_to']   ?? ''), ENT_QUOTES, 'UTF-8');

// --------------------------------------------------------------------------------------
// Templates
// --------------------------------------------------------------------------------------
include $DOCUMENT_ROOT . '/assets/template/html-header.php';
include $DOCUMENT_ROOT . '/assets/template/header.php';
?>
<body class="app header-fixed sidebar-fixed aside-menu-fixed sidebar-lg-show" data-page="transfer-pack" data-txid="<?= (int)$txId ?>">
  <div class="app-body">
    <?php include $DOCUMENT_ROOT . '/assets/template/sidemenu.php'; ?>
    <main class="main" id="main">
      <!-- Breadcrumb -->
      <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="/index.php">Home</a></li>
          <li class="breadcrumb-item"><a href="/modules/transfers">Transfers</a></li>
          <li class="breadcrumb-item active" aria-current="page">Pack</li>
        </ol>
      </nav>

      <div class="container-fluid animated fadeIn">
        <!-- Search / Add Panel -->
        <section class="card mb-3" id="product-search-card" aria-labelledby="product-search-title">
          <div class="card-header d-flex justify-content-between align-items-center" style="gap:12px;">
            <div class="d-flex align-items-center" style="gap:8px; flex:1;">
              <span class="sr-only" id="product-search-title">Product Search</span>
              <i class="fa fa-search text-muted" aria-hidden="true"></i>
              <input type="text" id="product-search-input" class="form-control form-control-sm"
                     placeholder="Search products by name, SKU, handle, ID… (use * wildcard)" autocomplete="off" aria-label="Search products">
              <button class="btn btn-sm btn-outline-primary" id="product-search-run" type="button" title="Run search" aria-label="Run search">
                <i class="fa fa-search" aria-hidden="true"></i>
              </button>
              <button class="btn btn-sm btn-outline-secondary" id="product-search-clear" type="button" title="Clear search" aria-label="Clear search">
                <i class="fa fa-times" aria-hidden="true"></i>
              </button>
            </div>
            <div class="btn-group btn-group-sm" role="group" aria-label="Bulk actions">
              <button class="btn btn-outline-primary" id="bulk-add-selected" type="button" disabled>Add Selected</button>
              <button class="btn btn-outline-secondary" id="bulk-add-to-other" type="button" disabled title="Add selected to other transfers (same origin outlet)">Add to Other…</button>
            </div>
          </div>
          <div class="card-body p-0">
            <div id="product-search-results" class="table-responsive" style="max-height:320px; overflow:auto;">
              <table class="table table-sm table-hover mb-0" id="product-search-table" aria-live="polite" aria-label="Product search results">
                <thead class="thead-light">
                  <tr>
                    <th style="width:34px;"><input type="checkbox" id="ps-select-all" aria-label="Select all results"></th>
                    <th style="width:56px;">Img</th>
                    <th>Product (Name + SKU)</th>
                    <th>Stock</th>
                    <th>Price</th>
                    <th style="width:42px;">Add</th>
                  </tr>
                </thead>
                <tbody id="product-search-tbody">
                  <tr>
                    <td colspan="6" class="text-muted small py-3 text-center">Type to search…</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </section>

        <!-- Header / Actions -->
        <section class="card mb-3" aria-labelledby="pack-title">
          <div class="card-header d-flex justify-content-between align-items-center">
            <div>
              <h1 class="card-title h4 mb-0" id="pack-title">
                Pack Transfer #<?= (int)$txId ?>
                <br><small class="text-muted"><?= $fromLbl ?> → <?= $toLbl ?></small>
              </h1>
              <p class="small text-muted mb-0">Count, label and finalize this consignment</p>
            </div>
            <div class="btn-group" role="group" aria-label="Save & Autofill">
              <button id="savePack" class="btn btn-primary">
                <i class="fa fa-save mr-1" aria-hidden="true"></i>Save Pack
              </button>
              <button class="btn btn-outline-secondary" id="autofillFromPlanned" type="button" title="Counted = Planned">
                <i class="fa fa-magic mr-1" aria-hidden="true"></i>Autofill
              </button>
            </div>
          </div>

          <div class="card-body transfer-data">
            <!-- Draft / metrics -->
            <div class="d-flex justify-content-between align-items-start w-100 mb-3" id="table-action-toolbar" style="gap:8px;">
              <div class="d-flex flex-column" style="gap:6px;">
                <div class="d-flex align-items-center" style="gap:10px;">
                  <!-- Draft Status Pill -->
                  <button type="button" id="draft-indicator" class="draft-status-pill status-idle"
                          data-state="idle" aria-live="polite"
                          aria-label="Draft status: idle. No unsaved changes." title="Draft status" disabled>
                    <span class="pill-icon" aria-hidden="true"></span>
                    <span class="pill-text" id="draft-indicator-text">Idle</span>
                  </button>
                </div>
                <div class="small text-muted">Last saved: <span id="draft-last-saved">Not saved</span></div>
              </div>
              <div class="d-flex align-items-center flex-wrap" style="gap:10px;">
                <span>Items: <strong id="itemsToTransfer"><?= count($items) ?></strong></span>
                <span>Planned total: <strong id="plannedTotal">0</strong></span>
                <span>Counted total: <strong id="countedTotal">0</strong></span>
                <span>Diff: <strong id="diffTotal">0</strong></span>
              </div>
            </div>

            <!-- Items table -->
            <div class="card tfx-card-tight mb-3" id="table-card" aria-labelledby="items-title">
              <div class="card-body py-2">
                <h2 class="sr-only" id="items-title">Items in this transfer</h2>
                <div class="tfx-pack-scope">
                  <table class="table table-responsive-sm table-bordered table-striped table-sm" id="transfer-table" aria-describedby="items-title">
                    <thead>
                      <tr>
                        <th style="width:38px;" scope="col">#</th>
                        <th scope="col">Product</th>
                        <th scope="col">Planned Qty</th>
                        <th scope="col">Counted Qty</th>
                        <th scope="col">To</th>
                        <th scope="col">ID</th>
                      </tr>
                    </thead>
                    <tbody id="productSearchBody">
                      <?php
                      $row = 0;
                      if ($items) {
                        foreach ($items as $i) {
                          $row++;
                          $iid       = (int)($i['id'] ?? 0);
                          $planned   = max(0, (int)($i['qty_requested'] ?? 0));
                          $sentSoFar = max(0, (int)($i['qty_sent_total'] ?? 0));
                          $inventory = max($planned, $sentSoFar);
                          if ($planned <= 0) continue;

                          echo '<tr data-inventory="' . $inventory . '" data-planned="' . $planned . '">';
                          echo "<td class='text-center align-middle'>
                                  <button class='tfx-remove-btn' type='button' data-action='remove-product' aria-label='Remove product' title='Remove product'>
                                    <i class='fa fa-times' aria-hidden='true'></i>
                                  </button>
                                  <input type='hidden' class='productID' value='{$iid}'>
                                </td>";
                          echo '<td>' . tfx_render_product_cell($i) . '</td>';
                          echo '<td class="planned">' . $planned . '</td>';
                          echo "<td class='counted-td'>
                                  <input type='number' min='0' max='{$inventory}' value='" . ($sentSoFar ?: '') . "' class='form-control form-control-sm tfx-num' inputmode='numeric' aria-label='Counted quantity'>
                                  <span class='counted-print-value d-none'>" . ($sentSoFar ?: 0) . "</span>
                                </td>";
                          echo '<td>' . $toLbl . '</td>';
                          echo '<td><span class="id-counter">' . $txId . '-' . $row . '</span></td>';
                          echo '</tr>';
                        }
                      } else {
                        echo '<tr><td colspan="6" class="text-center text-muted py-4">No items on this transfer.</td></tr>';
                      }
                      ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>

            <!-- ====================== PACK & SHIP — INLINE (HARDENED) ====================== -->
            <?php
              // Resolve minimal context used by the ship UI
              $PS_TID  = $txId;
              $PS_FROM = $fromLbl;
              $PS_TO   = $toLbl;
            ?>
            <section id="psx-app" class="psx container-fluid my-3" aria-label="Pack & Ship Panel">
              <!-- Header: Route, mode, health -->
              <div class="psx-card mb-3">
                <div class="psx-row">
                  <div class="psx-brand" aria-label="Route summary">
                    <div class="psx-logo" aria-hidden="true"></div>
                    <div>
                      <div class="psx-title">Pack &amp; Ship — Transfer #<?= (int)$PS_TID ?></div>
                      <div class="psx-sub"><?= $PS_FROM ?> → <?= $PS_TO ?></div>
                    </div>
                  </div>
                  <div class="psx-right">
                    <div class="psx-seg" role="tablist" aria-label="Mode">
                      <button class="psx-tab is-active" data-mode="easy"  aria-pressed="true">Easy</button>
                      <button class="psx-tab"          data-mode="pro"   aria-pressed="false">Pro</button>
                    </div>
                    <div class="psx-health" aria-label="Carrier health">
                      <span class="psx-chip"><span class="psx-dot" style="background:#3b82f6"></span> NZ Post: <b id="psx-nzpost">CHECK…</b></span>
                      <span class="psx-chip"><span class="psx-dot" style="background:#06b6d4"></span> NZ Couriers: <b id="psx-nzc">CHECK…</b></span>
                      <button class="psx-btn psx-btn-sm" id="psx-help" type="button">Help / FAQ</button>
                    </div>
                  </div>
                </div>
              </div>

              <div class="psx-grid">
                <!-- LEFT: Packages + Analytics -->
                <section class="psx-card" aria-labelledby="pkgs-title">
                  <div class="psx-card-head">
                    <div class="psx-hdr" id="pkgs-title">Packages</div>
                    <div class="psx-actions" role="group" aria-label="Package actions">
                      <button class="psx-btn psx-btn-sm" id="psx-add"   type="button">Add</button>
                      <button class="psx-btn psx-btn-sm" id="psx-copy"  type="button">Copy</button>
                      <button class="psx-btn psx-btn-sm" id="psx-reset" type="button">Reset</button>
                    </div>
                  </div>

                  <div class="psx-card-body">
                    <div class="psx-table-wrap">
                      <table class="psx-table" aria-label="Packages table">
                        <thead><tr>
                          <th scope="col">#</th><th scope="col">Name</th><th scope="col">W×L×H (cm)</th><th scope="col">Weight</th><th scope="col">Items</th><th scope="col" class="psx-right"></th>
                        </tr></thead>
                        <tbody id="psx-pkgs"></tbody>
                      </table>
                    </div>

                    <div class="psx-rows mt-2">
                      <div class="psx-analytics" aria-live="polite">
                        <div class="psx-anal-head">Overview &amp; Smart Analytics</div>
                        <div class="psx-anal-kpis">
                          <div class="psx-kpi"><div class="psx-kpi-l">Boxes</div><div class="psx-kpi-v" id="kpiBoxes">0</div></div>
                          <div class="psx-kpi"><div class="psx-kpi-l">Actual (kg)</div><div class="psx-kpi-v" id="kpiActual">0.0</div></div>
                          <div class="psx-kpi"><div class="psx-kpi-l">Volumetric (kg)</div><div class="psx-kpi-v" id="kpiVol">0.0</div></div>
                          <div class="psx-kpi"><div class="psx-kpi-l">Chargeable (kg)</div><div class="psx-kpi-v" id="kpiChg">0.0</div></div>
                        </div>
                        <div id="psx-warnings" class="psx-warnings" aria-live="polite"></div>
                      </div>

                      <div class="psx-capacity">
                        <div class="psx-subhead">Capacity meters (25kg cap; 15kg for bags)</div>
                        <div id="psx-meters"></div>
                      </div>
                    </div>

                    <div class="psx-card-sub">
                      <div class="psx-subhead">Slip Preview</div>
                      <div id="psx-slip" class="psx-slip"></div>
                    </div>
                  </div>
                </section>

                <!-- RIGHT: Options + Rates + Summary -->
                <aside class="psx-card" aria-labelledby="opts-title">
                  <div class="psx-card-head">
                    <div class="psx-hdr" id="opts-title">Options</div>
                    <div class="psx-muted">Delivery &amp; policy aware</div>
                  </div>

                  <div class="psx-card-body">
                    <div class="psx-optrow" role="group" aria-label="Delivery options">
                      <label class="psx-opt"><input type="checkbox" id="optSig" checked> <span>Signature</span></label>
                      <label class="psx-opt"><input type="checkbox" id="optATL"> <span>ATL</span></label>
                      <label class="psx-opt psx-opt-danger" title="R18 disabled for B2B"><input type="checkbox" id="optAge" disabled> <span>Age-Restricted</span></label>
                      <label class="psx-opt"><input type="checkbox" id="optSat"> <span>Saturday</span></label>
                    </div>

                    <hr class="psx-sep" aria-hidden="true">

                    <!-- Easy -->
                    <div class="psx-easy" id="blkEasy">
                      <div class="psx-row">
                        <div class="psx-muted">Rates &amp; Services</div>
                        <button class="psx-btn psx-btn-sm" id="btnGetRates" type="button">Get rates</button>
                      </div>
                      <div id="ratesList" class="psx-rates" aria-live="polite"></div>
                      <div class="psx-sum" aria-live="polite">
                        <div>
                          <div class="psx-muted">Selected</div>
                          <div id="sumCarrier" class="psx-strong">—</div>
                          <div id="sumService" class="psx-sub">—</div>
                        </div>
                        <div class="psx-right">
                          <div class="psx-muted">Total (GST incl)</div>
                          <div id="sumTotal" class="psx-total">$0.00</div>
                        </div>
                      </div>
                      <div class="psx-cta">
                        <button class="psx-btn psx-btn-lg psx-btn-green" id="btnPrintNow"   type="button">Print Now</button>
                        <button class="psx-btn psx-btn-lg psx-btn-blue"  id="btnCreateLabel" type="button">Create Label</button>
                      </div>
                    </div>

                    <!-- Pro -->
                    <div class="psx-pro d-none" id="blkPro">
                      <div class="psx-subhead">Live Rate Matrix (GST incl)</div>
                      <div class="psx-table-wrap">
                        <table class="psx-table psx-matrix" id="tblMatrix" aria-label="Rate matrix">
                          <thead>
                            <tr><th>Carrier</th><th>Service</th><th>Base</th><th>Fuel</th><th>Rural</th><th>Sat</th><th>Sig</th><th>Other</th><th class="psx-right">Total</th></tr>
                          </thead>
                          <tbody></tbody>
                        </table>
                      </div>
                      <div class="psx-subhead mt-2">Address Facts</div>
                      <div id="psx-facts" class="psx-facts" aria-live="polite"></div>
                      <div class="psx-muted small mt-2">If any surcharge is implied by flags but missing in a rate, we’ll warn and log <b>RATES_SURCHARGE_MISMATCH</b>.</div>
                    </div>

                    <hr class="psx-sep" aria-hidden="true">

                    <div class="psx-card-sub" aria-label="Route">
                      <div class="psx-subhead">Route</div>
                      <div class="psx-route"><span class="psx-tag">From</span> <?= $PS_FROM ?></div>
                      <div class="psx-route"><span class="psx-tag">To</span> <?= $PS_TO ?></div>
                    </div>
                  </div>
                </aside>
              </div>

              <!-- Sticky footer actions -->
              <div class="psx-footer psx-card" aria-label="Actions footer">
                <div class="psx-card-body psx-footgrid">
                  <div>
                    <label class="sr-only" for="noteText">Note</label>
                    <input id="noteText" class="psx-input" placeholder="Add a note… (saved with the action)">
                    <div class="psx-muted">Ctrl+Enter performs the primary action</div>
                  </div>
                  <div class="psx-footbtns" role="group" aria-label="Footer actions">
                    <button class="psx-btn" id="btnReset"      type="button">Reset</button>
                    <button class="psx-btn" id="btnPrintSlips"type="button">Print Slips</button>
                    <button class="psx-btn psx-btn-red"  id="btnCancel" type="button">Cancel</button>
                    <button class="psx-btn psx-btn-green" id="btnReady"  type="button">Mark Ready</button>
                  </div>
                </div>
              </div>

              <!-- Help / FAQ -->
              <div class="psx-card mt-3" id="psx-faq" aria-label="Help & FAQ">
                <div class="psx-card-head"><div class="psx-hdr">Help &amp; FAQ</div></div>
                <div class="psx-card-body">
                  <details class="psx-det"><summary><b>How do I go LIVE per outlet?</b></summary>
                    <ol class="psx-list">
                      <li>In <code>vend_outlets</code>, set tokens on the <b>FROM</b> outlet:
                        <ul>
                          <li><code>nz_post_api_key</code>, <code>nz_post_subscription_key</code> (Starshipit)</li>
                          <li><code>gss_token</code> (GoSweetSpot), <code>courier_account_number</code> (optional)</li>
                        </ul>
                      </li>
                      <li>Optionally set ENV for modes: <code>NZPOST_MODE=live</code>, <code>NZC_MODE=live</code>. Per-outlet keys take priority.</li>
                      <li>Health check: <code>POST /modules/transfers/stock/api/pack_ship_api.php?action=health</code> (look for ENABLED + CONFIGURED).</li>
                      <li>Services: <code>GET /modules/transfers/stock/api/services_live.php?transfer=ID&amp;carrier=nz_post</code> (or <code>gss</code>).</li>
                      <li>Rates: <code>POST /modules/transfers/stock/api/rates.php</code> with your packages.</li>
                      <li>Create: <code>POST /modules/transfers/stock/api/create_label.php?debug=1</code> with a valid <code>service_code</code>.</li>
                    </ol>
                  </details>

                  <details class="psx-det"><summary><b>How does chargeable weight work?</b></summary>
                    <div>Chargeable = max(<b>actual</b>, <b>volumetric</b>). We compute volumetric as <code>L×W×H (m³) × volumetric_factor</code> (default 200). Analytics shows both and the chargeable total.</div>
                  </details>

                  <details class="psx-det"><summary><b>Policies &amp; hard safety</b></summary>
                    <ul class="psx-list">
                      <li>Max 25 kg per box (bags ≤ 15 kg)</li>
                      <li>R18 ⇒ Signature and disallows ATL</li>
                      <li><b>B2B</b> ⇒ R18 disabled (UI + server)</li>
                      <li>GST-incl costs everywhere; breakdown visible in Pro</li>
                      <li>Idempotency via header + package hash</li>
                    </ul>
                  </details>

                  <details class="psx-det"><summary><b>Troubleshooting</b></summary>
                    <ul class="psx-list">
                      <li>“No live rates”: token missing or service payload needs dims/weights → add dims or check services_live.</li>
                      <li>“Create failed”: wrong service code or address → verify service list and address; use <code>?debug=1</code>.</li>
                      <li>Heavy box warning: split or pick larger container; keep under 25 kg (bag 15 kg).</li>
                    </ul>
                  </details>
                </div>
              </div>

              <!-- TERMINAL / Diagnostics -->
              <section class="psx-card mt-3" aria-labelledby="term-title">
                <div class="psx-card-head">
                  <div class="psx-hdr" id="term-title">Terminal / Diagnostics</div>
                  <div class="psx-muted">Live logs for connectivity & configuration</div>
                </div>
                <div class="psx-card-body">
                  <div id="psx-terminal" class="psx-terminal" role="log" aria-live="polite" aria-atomic="false"></div>
                </div>
              </section>
            </section>
            <!-- ==================== / PACK & SHIP — INLINE (HARDENED) ===================== -->
          </div>
        </section>
      </div>

      <!-- Page Assets (kept — page remains usable if any fail; terminal shows warnings) -->
      <link rel="stylesheet" href="/assets/css/stock-transfers/transfers-common.css?v=1">
      <link rel="stylesheet" href="/assets/css/stock-transfers/transfers-pack.css?v=1">
      <link rel="stylesheet" href="/assets/css/stock-transfers/ship-ui.css?v=1">
      <link rel="stylesheet" href="/assets/css/stock-transfers/transfers-pack-inline.css?v=1">
      <link rel="stylesheet" href="/assets/css/shipping/courier-control-tower.css?v=1">

      <script src="/assets/js/stock-transfers/transfers-common.js?v=1" defer></script>
      <script src="/assets/js/stock-transfers/transfers-pack.js?v=1" defer></script>
      <script src="/assets/js/stock-transfers/ship-ui.js?v=1" defer></script>
    </main>
  </div>

  <?php include $DOCUMENT_ROOT . '/assets/template/html-footer.php'; ?>
  <?php include $DOCUMENT_ROOT . '/assets/template/personalisation-menu.php'; ?>
  <?php include $DOCUMENT_ROOT . '/assets/template/footer.php'; ?>

  <!-- Inline CSS (scoped) -->
  <style>
    /* Minimal scoped styling for psx UI + terminal; keep classy & readable */
    .psx{--line:#e7e7ef;--ink:#0b1220;--muted:#667085;--blue1:#9ad2ff;--blue2:#3b82f6;--green1:#22c55e;--green2:#16a34a;--red:#ef4444;--shadow:0 16px 46px rgba(2,6,23,.10)}
    .psx-card{border:1px solid var(--line);border-radius:14px;background:#fff;box-shadow:var(--shadow)}
    .psx-row{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;padding:12px 14px}
    .psx-brand{display:flex;gap:12px;align-items:center}
    .psx-logo{width:40px;height:40px;border-radius:12px;background:conic-gradient(from 200deg,#c0a7ff,#7aa2ff,#22c55e)}
    .psx-title{font-weight:800}
    .psx-sub{color:var(--muted);font-size:12px}
    .psx-right{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
    .psx-seg{display:inline-flex;border:1px solid var(--line);border-radius:999px;overflow:hidden}
    .psx-tab{appearance:none;border:0;background:#fff;padding:8px 12px;font-weight:900;cursor:pointer}
    .psx-tab.is-active{background:linear-gradient(180deg,var(--blue1),var(--blue2));color:#fff}
    .psx-health{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
    .psx-chip{display:inline-flex;gap:6px;align-items:center;border:1px solid var(--line);border-radius:999px;padding:5px 8px;background:#fff;font-weight:700}
    .psx-dot{width:10px;height:10px;border-radius:50%}
    .psx-grid{display:grid;grid-template-columns:1.2fr .8fr;gap:16px}
    @media(max-width:1100px){.psx-grid{grid-template-columns:1fr}}
    .psx-card-head{display:flex;justify-content:space-between;align-items:center;padding:10px 14px;border-bottom:1px solid var(--line)}
    .psx-hdr{font-size:13px;font-weight:900;text-transform:uppercase;letter-spacing:.02em}
    .psx-card-body{padding:12px 14px}
    .psx-actions{display:flex;gap:8px}
    .psx-btn{border:1px solid var(--line);background:#fff;border-radius:10px;padding:8px 12px;cursor:pointer;font-weight:800}
    .psx-btn-sm{padding:6px 10px;font-size:12px}
    .psx-btn-lg{padding:12px 16px;font-size:16px}
    .psx-btn-blue{background:linear-gradient(180deg,var(--blue1),var(--blue2));color:#fff;border-color:transparent}
    .psx-btn-green{background:linear-gradient(180deg,var(--green1),var(--green2));color:#fff;border-color:transparent}
    .psx-btn-red{background:linear-gradient(180deg,#ff7b7b,var(--red));color:#fff;border-color:transparent}
    .psx-muted{color:var(--muted);font-size:12px}
    .psx-table{width:100%;border-collapse:collapse}
    .psx-table th,.psx-table td{padding:10px;border-bottom:1px solid var(--line);text-align:left}
    .psx-right{text-align:right}
    .psx-table-wrap{border:1px solid var(--line);border-radius:10px;overflow:hidden}
    .psx-rows{display:grid;grid-template-columns:1fr 1fr;gap:16px}
    @media(max-width:1100px){.psx-rows{grid-template-columns:1fr}}
    .psx-analytics{border:1px dashed var(--line);border-radius:12px;padding:10px}
    .psx-anal-head{font-weight:900;margin-bottom:8px}
    .psx-anal-kpis{display:flex;gap:8px;flex-wrap:wrap}
    .psx-kpi{border:1px solid var(--line);border-radius:10px;padding:8px 10px}
    .psx-kpi-l{font-size:11px;color:#475569}
    .psx-kpi-v{font-size:16px;font-weight:900}
    .psx-warnings .psx-warn{border:1px solid #fde68a;background:#fffbeb;color:#92400e;border-radius:10px;padding:8px 10px;margin-top:6px}
    .psx-capacity .bar{height:10px;border-radius:6px;background:#eef2ff;overflow:hidden}
    .psx-capacity .fill{height:100%;background:linear-gradient(90deg,var(--blue1),var(--blue2))}
    .psx-subhead{font-weight:900;margin:10px 0 6px}
    .psx-slip{min-height:120px;border:1px dashed var(--line);border-radius:10px;display:grid;place-items:center;color:#64748b;padding:14px}
    .psx-optrow{display:flex;gap:8px;flex-wrap:wrap}
    .psx-opt{display:inline-flex;gap:8px;align-items:center;border:1px solid var(--line);border-radius:999px;background:#fff;padding:6px 10px;font-weight:800}
    .psx-opt-danger{border-color:#f0caca;color:#b91c1c}
    .psx-sep{border:0;border-top:1px solid var(--line);margin:10px 0}
    .psx-rates .rate{border:1px solid var(--line);border-radius:10px;padding:10px;margin-bottom:8px;background:#fff;cursor:pointer}
    .psx-rates .rate.active{outline:2px solid var(--blue2);outline-offset:2px;background:linear-gradient(180deg,#fff,#f7fbff)}
    .psx-sum{display:flex;justify-content:space-between;gap:10px;margin-top:8px}
    .psx-strong{font-weight:900}
    .psx-total{font-size:22px;font-weight:900}
    .psx-cta{display:flex;flex-direction:column;gap:8px;margin-top:10px}
    .psx-route{margin-top:6px}
    .psx-tag{display:inline-block;border:1px solid var(--line);border-radius:999px;padding:2px 6px;font-size:11px;margin-right:6px}
    .psx-footer{position:sticky;bottom:0;background:linear-gradient(180deg,rgba(255,255,255,.9),#fff)}
    .psx-footgrid{display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center}
    .psx-input{width:100%;border:1px solid var(--line);border-radius:10px;padding:10px 12px}
    .psx-footbtns{display:flex;gap:8px}
    .psx-det{border:1px solid var(--line);border-radius:10px;padding:10px;margin-bottom:8px}
    .psx-flash{animation:psx-hi 0.8s ease}
    @keyframes psx-hi{0%{outline:2px solid var(--blue2)}100%{outline:0}}
    .psx-facts{display:flex;gap:8px;flex-wrap:wrap}
    .psx-facts .fact{border:1px solid var(--line);border-radius:999px;padding:4px 8px;font-weight:700}
    .psx-matrix td,.psx-matrix th{font-size:13px}
    .psx-btn:focus{outline:none;box-shadow:0 0 0 3px rgba(59,130,246,.2)}

    /* Terminal (diagnostics) */
    .psx-terminal{font:12px/1.5 ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
      border:1px solid var(--line);border-radius:10px;padding:10px;background:#0b1220;color:#e2e8f0;max-height:240px;overflow:auto}
    .psx-terminal .log{white-space:pre-wrap;margin:0 0 6px}
    .log--ok   {color:#86efac}
    .log--warn {color:#facc15}
    .log--err  {color:#fda4af}
    .log--info {color:#93c5fd}
  </style>

  <!-- Inline JS (robust fetch, timeouts, retries + terminal logs) -->
  <script>
  (function(){
    "use strict";

    // ---------------------------- Utilities ----------------------------
    const TXID = Number(document.body?.dataset?.txid || 0) || 0;

    const API = {
      HEALTH: '/modules/transfers/stock/api/pack_ship_api.php?action=health',
      RATES_SIMPLE: '/modules/transfers/stock/api/rates.php',
      PACK_API: '/modules/transfers/stock/api/pack_ship_api.php?action=rates',
      CREATE_LABEL: '/modules/transfers/stock/api/create_label.php'
    };

    // Terminal logger
    const termEl = document.getElementById('psx-terminal');
    function log(msg, level='info'){
      if(!termEl) return;
      const row = document.createElement('div');
      row.className = `log log--${level}`;
      const ts = new Date().toLocaleString();
      row.textContent = `[${ts}] ${String(msg)}`;
      termEl.appendChild(row);
      termEl.scrollTop = termEl.scrollHeight;
      // Console-safe mirror
      const fn = level==='err' ? 'error' : (level==='warn' ? 'warn' : (level==='ok' ? 'log' : 'info'));
      (console[fn]||console.log).call(console, `[Pack&Ship] ${msg}`);
    }

    // Abortable fetch with timeout & retries (exponential backoff + jitter)
    async function fetchJSON(url, opts={}, {timeoutMs=12000, retries=1, label='fetch'} = {}){
      const ctrl = new AbortController();
      const id = setTimeout(()=>ctrl.abort(), timeoutMs);
      try{
        const res = await fetch(url, {...opts, signal: ctrl.signal});
        const ct = (res.headers.get('content-type')||'').toLowerCase();
        let data = null;
        if (ct.includes('application/json')) {
          data = await res.json();
        } else {
          const text = await res.text();
          // Try parse; if fails, wrap
          try { data = JSON.parse(text); } catch { data = { ok:false, error:'NON_JSON_RESPONSE', raw:text }; }
        }
        if (!res.ok) {
          const code = res.status;
          throw Object.assign(new Error(`${label} HTTP ${code}`), {code, data});
        }
        return data;
      } catch (e){
        clearTimeout(id);
        const isAbort = e?.name === 'AbortError';
        const msg = isAbort ? `${label} timeout after ${timeoutMs}ms` : `${label} failed: ${e?.message||e}`;
        log(msg, 'warn');

        if (retries > 0) {
          const n = Math.max(0, 2 - retries + 1);
          const backoff = Math.min(2000 * n, 4000) + Math.random()*500;
          log(`Retrying ${label} in ${Math.round(backoff)}ms…`, 'info');
          await new Promise(r=>setTimeout(r, backoff));
          return fetchJSON(url, opts, {timeoutMs, retries: retries-1, label});
        }

        throw e;
      } finally {
        clearTimeout(id);
      }
    }

    function $(s,root){ return (root||document).querySelector(s); }
    function $all(s,root){ return Array.from((root||document).querySelectorAll(s)); }
    const num = v => Math.max(0, +v || 0);
    const fmt$ = n => '$' + (Number(n)||0).toFixed(2);
    const fmtKg = n => (Number(n)||0).toFixed(1) + ' kg';

    // ---------------------------- Pack & Ship State ----------------------------
    const state = {
      mode: 'easy',
      volumetricFactor: 200,
      packages: [{ name:'Box M 400×300×200', w:30, l:40, h:20, kg:4.2, items:9, kind:'box' }],
      selection: null,
      quotes: []
    };

    function kgVol(p){
      const Lm = (num(p.l)/100), Wm = (num(p.w)/100), Hm = (num(p.h)/100);
      if (Lm<=0 || Wm<=0 || Hm<=0) return 0;
      return (Lm*Wm*Hm) * state.volumetricFactor;
    }
    function chargeableKg(){
      let a=0,v=0;
      state.packages.forEach(p=>{ a += num(p.kg); v += Math.max(num(p.kg), kgVol(p)); });
      return { actual:a, volumetric:v, chargeable: Math.max(a, v) };
    }

    // ---------------------------- Renderers ----------------------------
    function renderPackages(){
      const body = $('#psx-pkgs');
      if(!body) return;
      body.innerHTML='';
      state.packages.forEach((p,i)=>{
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td>${i+1}</td>
          <td>${p.name}</td>
          <td><span class="psx-muted">${p.w}×${p.l}×${p.h}</span></td>
          <td>${fmtKg(p.kg)}</td>
          <td>${p.items||0}</td>
          <td class="psx-right"><button class="psx-btn psx-btn-sm" data-del="${i}" type="button" aria-label="Remove package ${i+1}">×</button></td>`;
        body.appendChild(tr);
      });
      body.onclick = (e)=>{
        const i = e.target.getAttribute('data-del'); if (i==null) return;
        state.packages.splice(+i,1); renderPackages(); renderAnalytics();
      };
      renderCapacity();
      renderAnalytics();
      const slip = $('#psx-slip');
      if (slip) {
        slip.innerHTML = `
          <div style="font-family:ui-monospace,Menlo,Consolas,monospace">
            <div><strong>TRANSFER #${TXID || '—'}</strong></div>
            <div>FROM: <?= $PS_FROM ?></div>
            <div>TO:   <?= $PS_TO ?></div>
            <div class="psx-muted" style="margin-top:6px">BOX 1 of ${Math.max(1,state.packages.length)}</div>
          </div>`;
      }
    }

    function renderCapacity(){
      const wrap = $('#psx-meters'); if(!wrap) return;
      wrap.innerHTML='';
      state.packages.forEach((p,i)=>{
        const cap = (p.kind==='bag') ? 15 : 25;
        const pct = Math.min(100, Math.round((num(p.kg)/cap)*100));
        const row = document.createElement('div');
        row.innerHTML = `
          <div class="psx-muted">Box ${i+1} · ${fmtKg(p.kg)} / ${cap.toFixed(1)} kg</div>
          <div class="bar"><div class="fill" style="width:${pct}%"></div></div>`;
        wrap.appendChild(row);
      });
    }

    function renderAnalytics(){
      const k = chargeableKg();
      const s = [
        ['#kpiBoxes',   state.packages.length],
        ['#kpiActual',  k.actual.toFixed(1)],
        ['#kpiVol',     k.volumetric.toFixed(1)],
        ['#kpiChg',     k.chargeable.toFixed(1)],
      ];
      s.forEach(([sel,val])=>{ const el=$(sel); if(el) el.textContent = String(val); });

      const warn = [];
      state.packages.forEach((p, i) => {
        const cap = p.kind === 'bag' ? 15 : 25;
        if (num(p.kg) > cap) warn.push(`Box ${i + 1} exceeds ${cap} kg cap (${fmtKg(p.kg)})`);
        if (!p.l || !p.w || !p.h) warn.push(`Box ${i + 1} missing dimensions; volumetric may be underestimated`);
      });

      const W = $('#psx-warnings');
      if(!W) return;
      W.innerHTML = '';
      if (warn.length) {
        warn.forEach(t => {
          const d = document.createElement('div');
          d.className = 'psx-warn';
          d.textContent = `⚠️ ${t}`;
          W.appendChild(d);
        });
      }
    }

    function clearSelection(){
      const c = $('#sumCarrier'), s = $('#sumService'), t = $('#sumTotal');
      if (c) c.textContent = '—';
      if (s) s.textContent = '—';
      if (t) t.textContent = '$0.00';
      state.selection = null;
    }

    function selectRate(card, carrier, service, total, name){
      $all('.psx-rates .rate').forEach(el=> el.classList.remove('active'));
      if (card) card.classList.add('active');
      state.selection = { carrier, service, total };
      const c = $('#sumCarrier'), s = $('#sumService'), t = $('#sumTotal');
      if (c) c.textContent = (carrier==='nz_post' ? 'NZ Post' : String(carrier||'').toUpperCase());
      if (s) s.textContent = name || service || '—';
      if (t) t.textContent = fmt$(+total||0);
    }

    // ---------------------------- Easy Rates ----------------------------
    async function getRatesEasy(){
      clearSelection();
      const list = $('#ratesList');
      if(list) list.innerHTML = '<div class="psx-muted">Loading rates…</div>';
      const pk = state.packages.map(p => ({ length_cm:p.l, width_cm:p.w, height_cm:p.h, weight_kg:p.kg }));
      const payload = { transfer_id: TXID, carrier:'nz_post', packages: pk };

      try{
        const d = await fetchJSON(API.RATES_SIMPLE, {
          method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)
        }, {timeoutMs:12000, retries:1, label:'rates-simple'});

        const quotes = Array.isArray(d?.quotes) ? d.quotes : [];
        renderRatesEasy(quotes);
        if(!quotes.length){
          log('No live rates returned. Check outlet credentials or provide dimensions/weight.', 'warn');
        } else {
          log(`Received ${quotes.length} rate(s) from simple rates endpoint.`, 'ok');
        }
      }catch(e){
        if(list) list.innerHTML = `<div class="psx-muted">Rate lookup failed.</div>`;
        log(`Easy rates error: ${e?.message||e}`, 'err');
      }
    }

    function renderRatesEasy(quotes){
      const list = $('#ratesList'); if (!list) return;
      list.innerHTML='';
      if (!quotes.length){
        list.innerHTML = `<div class="psx-muted">No live rates. You can still print slips or create labels.</div>`;
        return;
      }
      quotes.forEach((q,idx)=>{
        const div = document.createElement('div');
        div.className='rate';
        const total = +q.total_price || 0;
        const svc   = q.service_name || q.service_code || 'Service';
        div.innerHTML = `
          <div class="psx-row">
            <div><strong>${svc}</strong><div class="psx-muted">GST incl</div></div>
            <div style="font-weight:900">${fmt$(total)}</div>
          </div>`;
        div.addEventListener('click', ()=> selectRate(div,'nz_post',q.service_code,total,svc));
        list.appendChild(div);
        if (idx===0) div.click();
      });
    }

    // ---------------------------- Pro (Matrix) Rates ----------------------------
    async function getRatesPro(){
      const tb = $('#tblMatrix tbody'); if (tb) tb.innerHTML = `<tr><td colspan="9" class="psx-muted">Loading…</td></tr>`;
      const pk = state.packages.map(p => ({ l:num(p.l), w:num(p.w), h:num(p.h), kg:num(p.kg), items:p.items||0 }));
      const options = { sig: $('#optSig')?.checked, atl: $('#optATL')?.checked, age:false };
      const context = { rural: false, saturday: $('#optSat')?.checked };

      try{
        const d = await fetchJSON(API.PACK_API, {
          method:'POST', headers:{'Content-Type':'application/json'},
          body: JSON.stringify({ packages: pk, options, context })
        }, {timeoutMs:15000, retries:1, label:'rates-matrix'});

        const results = Array.isArray(d?.results) ? d.results : [];
        const rows = results.map(x=>{
          const base = +x?.breakdown?.base || 0;
          const perkg= +x?.breakdown?.perkg || 0;
          const opts = +x?.breakdown?.opts || 0;
          let total_incl = +x.total || 0;
          if (!total_incl && (base||perkg||opts)) {
            total_incl = Math.round((base+perkg+opts) * 115) / 115; // gross-up if ex-GST
          }
          return {
            carrier: String(x.carrier_name || x.carrier || ''),
            service: String(x.service_name  || x.service  || ''),
            base: Math.round(base*115)/115,
            fuel: 0, rural:0, saturday:0, signature:0, other: opts>0 ? Math.round(opts*115)/115 : 0,
            total_incl
          };
        });

        renderMatrix(rows);

        // Seed easy cards from whatever we have (pref NZ Post)
        const nzp = rows.filter(r=> r.carrier.toLowerCase().includes('post'));
        const seed = (nzp.length ? nzp : rows).map(e=>({ service_name:e.service, service_code:e.service, total_price:e.total_incl }));
        renderRatesEasy(seed);

        log(`Matrix rates loaded: ${rows.length} row(s).`, 'ok');
        if (!rows.length) log('No rates returned in matrix. Verify credentials or payload.', 'warn');
      }catch(e){
        if (tb) tb.innerHTML = `<tr><td colspan="9" class="psx-muted">Rate matrix lookup failed.</td></tr>`;
        log(`Matrix rates error: ${e?.message||e}`, 'err');
      }
    }

    function renderMatrix(rows){
      const tb = $('#tblMatrix tbody'); if(!tb) return;
      tb.innerHTML = '';
      if (!rows.length){
        tb.innerHTML = `<tr><td colspan="9" class="psx-muted">No rates</td></tr>`;
        return;
      }
      rows.forEach(r=>{
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td>${r.carrier || '—'}</td>
          <td>${r.service || '—'}</td>
          <td>${fmt$(r.base||0)}</td>
          <td>${fmt$(r.fuel||0)}</td>
          <td>${fmt$(r.rural||0)}</td>
          <td>${fmt$(r.saturday||0)}</td>
          <td>${fmt$(r.signature||0)}</td>
          <td>${fmt$(r.other||0)}</td>
          <td class="psx-right"><strong>${fmt$(r.total_incl||0)}</strong></td>`;
        tb.appendChild(tr);
      });

      const facts = $('#psx-facts'); if (facts){
        facts.innerHTML = '';
        ['Residential','Saturday available','DG: no'].forEach(t=>{
          const chip = document.createElement('span');
          chip.className = 'fact';
          chip.textContent = t;
          facts.appendChild(chip);
        });
      }
    }

    // ---------------------------- Health ----------------------------
    function setHealth(okNZP, okNZC){
      const nzp = $('#psx-nzpost'); const nzc = $('#psx-nzc');
      if (nzp) { nzp.textContent = okNZP ? 'LIVE' : 'DOWN'; nzp.style.color = okNZP ? '#16a34a' : '#ef4444'; }
      if (nzc) { nzc.textContent = okNZC ? 'LIVE' : 'DOWN'; nzc.style.color = okNZC ? '#16a34a' : '#ef4444'; }
    }
    async function checkHealth(){
      try{
        const d = await fetchJSON(API.HEALTH, {method:'POST', headers:{'Content-Type':'application/json'}, body:'{}'}, {timeoutMs:8000, retries:1, label:'health'});
        const okNZP = d?.checks?.nz_post === 'ENABLED';
        const okNZC = d?.checks?.nzc     === 'ENABLED';
        setHealth(okNZP, okNZC);
        log(`Health: NZ Post=${okNZP?'ENABLED':'DISABLED'}, NZ Couriers=${okNZC?'ENABLED':'DISABLED'}`, okNZP||okNZC ? 'ok' : 'warn');
        if (!okNZP || !okNZC) log('Missing credentials or configuration for one or more carriers. See Help/FAQ.', 'warn');
      }catch(e){
        setHealth(false,false);
        log(`Health check failed: ${e?.message||e}`, 'err');
      }
    }

    // ---------------------------- Actions ----------------------------
    async function createLabel(){
      if (!state.selection){
        alert('Pick a service first');
        log('Create Label blocked: no service selected.', 'warn');
        return;
      }
      const pk = state.packages.map(p=>({ l_cm:num(p.l), w_cm:num(p.w), h_cm:num(p.h), weight_kg:num(p.kg), ref:'' }));
      const body = {
        transfer_id: TXID,
        carrier: state.selection.carrier || 'nz_post',
        service_code: state.selection.service || '',
        packages: pk,
        options: { signature: $('#optSig')?.checked, saturday: $('#optSat')?.checked, atl: $('#optATL')?.checked }
      };

      try{
        // UX: disable while in flight
        const btn = $('#btnCreateLabel'); if (btn) btn.disabled = true;
        const d = await fetchJSON(API.CREATE_LABEL + '?debug=1', {
          method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)
        }, {timeoutMs:15000, retries:1, label:'create-label'});

        if (d && (d.success || d.ok)) {
          alert('Label created.');
          log('Create label: success.', 'ok');
        } else {
          const reason = d?.error || 'UNKNOWN';
          alert('Create failed.');
          log(`Create label: failed (${reason}).`, 'err');
        }
      }catch(e){
        alert('Create failed.');
        log(`Create label error: ${e?.message||e}`, 'err');
      }finally{
        const btn = $('#btnCreateLabel'); if (btn) btn.disabled = false;
      }
    }

    function bindUI(){
      // Tabs
      $all('.psx-tab').forEach(btn=>{
        btn.addEventListener('click', ()=>{
          $all('.psx-tab').forEach(x=> x.classList.remove('is-active'));
          btn.classList.add('is-active');
          state.mode = btn.dataset.mode;
          const easy = $('#blkEasy'), pro = $('#blkPro');
          if (state.mode==='easy'){ easy?.classList.remove('d-none'); pro?.classList.add('d-none'); }
          else { easy?.classList.add('d-none'); pro?.classList.remove('d-none'); getRatesPro(); }
        }, {passive:true});
      });

      // Packages
      $('#psx-add')?.addEventListener('click', ()=>{ state.packages.push({name:'Box', w:30,l:40,h:20,kg:2.0,items:0,kind:'box'}); renderPackages(); }, {passive:true});
      $('#psx-copy')?.addEventListener('click', ()=>{ if(state.packages.length){ state.packages.push({...state.packages[state.packages.length-1]}); renderPackages(); } }, {passive:true});
      $('#psx-reset')?.addEventListener('click', ()=>{ state.packages=[]; renderPackages(); }, {passive:true});

      // Rates
      $('#btnGetRates')?.addEventListener('click', ()=> state.mode==='easy' ? getRatesEasy() : getRatesPro());

      // CTAs
      $('#btnPrintNow')?.addEventListener('click', ()=> window.print());
      $('#btnCreateLabel')?.addEventListener('click', createLabel);
      $('#btnPrintSlips')?.addEventListener('click', ()=> window.open('/modules/transfers/stock/print/box_slip.php?transfer='+TXID+'&preview=1&n='+Math.max(1,state.packages.length),'_blank'));
      $('#btnReady')?.addEventListener('click', ()=> { alert('Marked Ready (UI)'); log('Marked Ready requested (client). Implement server action as needed.', 'info'); });
      $('#btnCancel')?.addEventListener('click', ()=> { alert('Cancelled (UI)'); log('Cancel requested (client). Implement server action as needed.', 'info'); });
      $('#btnReset')?.addEventListener('click', ()=>{ state.packages=[]; state.selection=null; renderPackages(); const r = $('#ratesList'); if(r) r.innerHTML=''; clearSelection(); });

      // Help / FAQ focus
      $('#psx-help')?.addEventListener('click', ()=> {
        const el=$('#psx-faq'); if (!el) return;
        el.scrollIntoView({behavior:'smooth',block:'center'});
        el.classList.add('psx-flash'); setTimeout(()=>el.classList.remove('psx-flash'),800);
      });

      // Keyboard shortcuts
      document.addEventListener('keydown', (e)=>{
        const k = (e.key||'').toLowerCase();
        if (k==='g'){ e.preventDefault(); state.mode==='easy' ? getRatesEasy() : getRatesPro(); }
        if (k==='c'){ e.preventDefault(); createLabel(); }
        if (k==='p'){ e.preventDefault(); window.print(); }
        if (k==='r'){ e.preventDefault(); $('#btnReady')?.click(); }
        if (e.ctrlKey && e.key==='Enter'){ e.preventDefault(); createLabel(); }
      });

      // Policy: B2B => disable R18, enforce Signature, disable ATL
      (function enforceR18(){
        const r18=$('#optAge'), sig=$('#optSig'), atl=$('#optATL');
        if (r18){ r18.checked=false; r18.disabled=true; r18.title='R18 not applicable for B2B consignments'; }
        if (sig) sig.checked = true;
        if (atl) atl.checked = false;
      })();
    }

    function boot(){
      if (!TXID) {
        log('TXID missing in DOM. The page will have limited functionality.', 'warn');
      }
      renderPackages();
      bindUI();
      checkHealth();

      // Asset presence checks (optional)
      const expected = [
        '/assets/js/stock-transfers/transfers-common.js?v=1',
        '/assets/js/stock-transfers/transfers-pack.js?v=1',
        '/assets/js/stock-transfers/ship-ui.js?v=1'
      ];
      expected.forEach(src=>{
        const ok = !!Array.from(document.scripts).find(s => (s.src||'').includes(src));
        if (!ok) log(`Optional asset not loaded: ${src}`, 'warn');
      });

      log('Pack & Ship UI ready.', 'ok');
    }

    // Kick off
    try { boot(); } catch (e){ log(`Boot failure: ${e?.message||e}`, 'err'); }
  })();
  </script>

  <!-- Page-specific integration scripts -->
  <script src="/assets/js/stock-transfers/pack-draft-status.js?v=1" defer></script>
  <script src="/assets/js/stock-transfers/pack-product-search.js?v=1" defer></script>
  <script src="/assets/js/stock-transfers/pack-ship-integration.js?v=1" defer></script>
  <script src="/assets/js/shipping/courier-control-tower.js?v=1" type="module"></script>
</body>
</html>
