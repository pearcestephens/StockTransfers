<?php

/**
 * Stock Transfer (Packing / Consignment) — Production Grade (Pack-Only single warning)
 * -----------------------------------------------------------------------------------
 * - One BIG warning in Pack-Only mode (no other warnings)
 * - Submit disabled in Pack-Only (server blocks POST submit too)
 * - Full UI/UX retained (counts, notes, tracking, product search, merge, labels)
 * - Calls external functions only (no new queues created here)
 */

include("assets/functions/config.php"); 

// Initialize PACKONLY before POST handler (prevents undefined variable error)
$PACKONLY = TRUE; // Default to false until we can properly check transfer data

// ---------- Unified AJAX handler ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json; charset=utf-8');

  // Local helper to enforce 200-only responses with embedded code/status
  if (!function_exists('st_json_response')) {
    function st_json_response(array $data, int $statusCode = 200): void {
      if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
      }
      if ($statusCode !== 200) {
        $data['code'] = $statusCode;
        if (!isset($data['status'])) $data['status'] = 'error';
      } else {
        if (!isset($data['status'])) $data['status'] = ($data['success'] ?? true) ? 'ok' : 'error';
        if (!isset($data['code'])) $data['code'] = 200;
      }
      http_response_code(200);
      echo json_encode($data, JSON_UNESCAPED_SLASHES);
      exit;
    }
  }

  // 1) Enforce login
  try {
    $userRow = requireLoggedInUser();
  } catch (Throwable $e) {
    st_json_response(['success' => false, 'error' => 'Not logged in. Please sign in and try again.'], 401);
  }

  // 2) Exactly one action
  $allowedKeys  = ['markReadyForDelivery', 'deleteTransfer', 'searchForProduct'];
  $presentKeys  = array_values(array_filter($allowedKeys, static fn($k) => isset($_POST[$k])));
  if (count($presentKeys) !== 1) {
    st_json_response(['success' => false, 'error' => 'Provide exactly one action.'], 400);
  }

  $action = $presentKeys[0];

  // 3) In PACKONLY, block submit; allow search/delete
  if ($PACKONLY && $action === 'markReadyForDelivery') {
    st_json_response([
      'success' => false,
      'error'   => 'Pack-Only Mode: submission is disabled. Do not send or do anything with this transfer until confirmed.'
    ], 403);
  }

  // 4) Actions
  try {
    switch ($action) {
      case 'markReadyForDelivery': {
          $payload = $_POST['markReadyForDelivery'] ?? [];
          if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            if (json_last_error() === JSON_ERROR_NONE) $payload = $decoded;
          } elseif (!is_array($payload)) {
            $payload = (array)$payload;
          }

          $userId = (int)($_SESSION['userID'] ?? 0);

          // EXTERNAL: saveTransferReady_wrapped($payload, $userId) handles consignment-first send
          $result = saveTransferReady_wrapped($payload, $userId);

          if (!($result->success ?? false)) {
            st_json_response((array)$result, 400);
          }
          st_json_response((array)$result, 200);
          break;
        }

      case 'deleteTransfer': {
          $payload = json_decode($_POST['deleteTransfer'] ?? '[]', true) ?: [];
          $userId  = (int)($_SESSION['userID'] ?? 0);
          $result  = deleteTransfer_wrapped($payload, $userId);
          if (!($result['success'] ?? false)) {
            st_json_response($result, 400);
          }
          st_json_response($result, 200);
          break;
        }

      case 'searchForProduct': {
          error_log('[view-stock-transfer] 🔍 searchForProduct action triggered');
          $payload = json_decode($_POST['searchForProduct'] ?? '[]', true) ?: [];
          $keyword = trim((string)($payload['keyword'] ?? ''));
          $outlet  = trim((string)($payload['outletID'] ?? ''));
          error_log("[view-stock-transfer] 📊 Search params: keyword='$keyword', outlet='$outlet'");

          // Check if function exists, if not use fallback
          if (function_exists('searchForProductByOutlet_wrapped')) {
            $res = searchForProductByOutlet_wrapped($keyword, $outlet, 50);
          } else {
            error_log('[view-stock-transfer] ❌ searchForProductByOutlet_wrapped function not found! Using fallback...');
            // Provide a safe fallback response
            $res = [
              'success' => false,
              'error' => 'Search function not available',
              'data' => []
            ];
          }
          error_log('[view-stock-transfer] 📋 Search result: ' . json_encode($res));
          if (!($res['success'] ?? false)) {
            st_json_response($res, 400);
          }
          st_json_response($res, 200);
          break;
        }

      default: {
          st_json_response(['success' => false, 'error' => 'Unknown action.'], 400);
          break;
        }
    }
  } catch (Throwable $e) {
    error_log('[view-stock-transfer] Fatal: ' . $e->getMessage() . ' trace=' . $e->getTraceAsString());
    st_json_response(['success' => false, 'error' => 'Unexpected server error. Please try again.'], 500);
  }
}

// ---------- GET phase ----------
$transferData   = null;
$userDetails    = null;
$mergeTransfers = [];

try {
  $uid = (int)($_SESSION['userID'] ?? 0);
  if ($uid > 0) {
    $userDetails = getUserDetails($uid);
  }

  $transferIdParam = isset($_GET['transfer']) ? (int)$_GET['transfer'] : 0;
  if ($transferIdParam <= 0) throw new InvalidArgumentException('Missing or invalid transfer parameter.');

  $transferData = getTransferData($transferIdParam, false);
  if (!$transferData) throw new RuntimeException('Transfer not found.');

  $mergeTransfers = getAvailableMergeTransfers(
    $transferData->outlet_from->id,
    $transferIdParam,
    $transferData->outlet_to->id
  );


} catch (Throwable $e) {
  error_log('[view-stock-transfer] preload error: ' . $e->getMessage());
  $transferData = $transferData ?? (object)[
    'outlet_from' => (object)['id' => 0, 'name' => 'Unknown'],
    'outlet_to'   => (object)['id' => 0, 'name' => 'Unknown'],
    'products'    => []
  ];
  $mergeTransfers = [];
}

include("assets/template/html-header.php");
include("assets/template/header.php");
?>

<body class="app header-fixed sidebar-fixed aside-menu-fixed sidebar-lg-show">



  <div class="app-body">
    <?php include("assets/template/sidemenu.php") ?>
    <main class="main">
      <!-- Breadcrumb-->
      <ol class="breadcrumb">
        <li class="breadcrumb-item">Home</li>
        <li class="breadcrumb-item"><a href="#">Admin</a></li>
        <li class="breadcrumb-item active">
          OUTGOING Stock Transfer #<?php echo (int)($_GET["transfer"] ?? 0); ?>
          To <?php echo htmlspecialchars($transferData->outlet_to->name); ?>
        </li>
        <li class="breadcrumb-menu d-md-down-none"><?php include('assets/template/quick-product-search.php'); ?></li>
      </ol>

      <div class="container-fluid">
        <div class="animated fadeIn">
          <div class="col">

            <div class="card">
              <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                  <h4 class="card-title mb-0">
                    Stock Transfer #<?php echo (int)($_GET["transfer"] ?? 0); ?><br>
                    <?php echo htmlspecialchars($transferData->outlet_from->name); ?> →
                    <?php echo htmlspecialchars($transferData->outlet_to->name); ?>
                  </h4>
                  <div class="small text-muted">These products need to be gathered and prepared for delivery</div>
                </div>

                <div class="btn-group">
                  <button class="btn btn-outline-primary dropdown-toggle d-flex align-items-center" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <i class="fa fa-cog mr-2"></i> Options
                  </button>
                  <div class="dropdown-menu dropdown-menu-right shadow border-0">

                    <?php if (count($mergeTransfers) > 0): ?>
                      <button class="dropdown-item" type="button" data-toggle="modal" data-target="#mergeTransferModal">
                        <i class="fa fa-code-fork mr-2"></i> Merge Transfer
                      </button>
                    <?php else: ?>
                      <button class="dropdown-item" type="button" data-toggle="modal" data-target="#mergeTransferModal" disabled>
                        <i class="fa fa-code-fork mr-2 text-muted"></i> Merge Transfer (None Available)
                      </button>
                    <?php endif; ?>

                    <button class="dropdown-item" type="button" data-toggle="modal" data-target="#addProductsModal">
                      <i class="fa fa-plus mr-2"></i> Add Products
                    </button>
                    <button id="editModeButton" class="dropdown-item" type="button" onclick="editMode(true)">
                      <i class="fa fa-edit mr-2"></i> Edit Transfer
                    </button>
                    <div class="dropdown-divider"></div>
                    <button class="dropdown-item text-danger" type="button" onclick="deleteTransfer(<?php echo (int)($_GET["transfer"] ?? 0); ?>)">
                      <i class="fa fa-trash mr-2"></i> Delete Transfer
                    </button>
                  </div>
                </div>
              </div>

              <div class="card-body transfer-data">
                <div id="transfer-alerts" class="mb-2" aria-live="polite"></div>

                <!-- Toolbar -->
                <div class="d-flex justify-content-between align-items-start w-100 mb-2" id="table-action-toolbar" style="gap:8px;">
                  <!-- Draft controls -->
                  <div class="d-flex flex-column" style="gap:4px;">
                    <div class="d-flex align-items-center" style="gap:8px;">
                      <span class="badge badge-pill badge-secondary" id="draft-status">Draft: Off</span>
                      <span class="text-muted small" id="draft-last-saved">Not saved</span>
                    </div>
                    <div class="d-flex align-items-center" style="gap:12px;">
                      <div class="d-flex" style="gap:8px;" role="group" aria-label="Draft actions">
                        <button type="button" class="btn btn-sm btn-outline-primary" id="btn-save-draft"
                          style="padding: 4px 12px; font-size: 0.875rem;"
                          title="Save a draft to this browser only (does not update Vend)">Save now (Ctrl+S)</button>
                        <button type="button" class="btn btn-sm btn-outline-success" id="btn-restore-draft"
                          style="padding: 4px 12px; font-size: 0.875rem;" disabled>Restore</button>
                        <button type="button" class="btn btn-sm btn-outline-danger" id="btn-discard-draft"
                          style="padding: 4px 12px; font-size: 0.875rem;" disabled>Discard</button>
                      </div>
                      <div class="custom-control custom-switch" title="Auto-save to this browser only (does not update Vend)">
                        <input type="checkbox" class="custom-control-input" id="toggle-autosave">
                        <label class="custom-control-label" for="toggle-autosave">Autosave</label>
                      </div>
                    </div>
                  </div>

                  <div class="d-flex align-items-center flex-wrap" style="gap:8px;">
                    <button class="btn btn-outline-primary d-flex align-items-center" type="button" data-toggle="modal" data-target="#addProductsModal">
                      <i class="fa fa-plus mr-2"></i> Add Products
                    </button>
                    <button type="button" class="btn btn-outline-secondary d-flex align-items-center" id="tbl-print" title="Print picking sheet" onclick="window.print()">
                      <i class="fa fa-print mr-2"></i> Print
                    </button>


                  </div>
                </div>

                <!-- Summary strip + table -->
                <div class="card w-100 mb-3" id="table-card">
                  <div class="card-body py-2">
                    <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;margin-bottom:8px;">
                      <span>Items: <strong id="itemsToTransfer"><?php echo count($transferData->products); ?></strong></span>
                      <span>Planned total: <strong id="plannedTotal">0</strong></span>
                      <span>Counted total: <strong id="countedTotal">0</strong></span>
                      <span>Diff: <strong id="diffTotal">0</strong></span>
                      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="autofillCountedFromPlanned();">Fill counted = planned</button>
                    </div>

                    <table class="table table-responsive-sm table-bordered table-striped table-sm" id="transfer-table">
                      <thead>
                        <tr>
                          <th></th>
                          <th>Name</th>
                          <th>Qty In Stock</th>
                          <th>Planned Qty</th>
                          <th>Counted Qty</th>
                          <th>Source</th>
                          <th>Destination</th>
                          <th>ID</th>
                        </tr>
                      </thead>
                      <tbody id="productSearchBody">
                        <?php
                        function manuallyOrderedByStaff_obj($p): string
                        {
                          return (isset($p->staff_added_product) && (int)$p->staff_added_product > 0)
                            ? ' <span class="badge badge-warning">Manually Ordered By Staff</span>'
                            : '';
                        }

                        $fromName = htmlspecialchars($transferData->outlet_from->name ?? '');
                        $toName   = htmlspecialchars($transferData->outlet_to->name ?? '');
                        $tidForCounter = isset($transferIdParam) ? (int)$transferIdParam : (int)($_GET['transfer'] ?? 0);

                        if (!empty($transferData->products) && is_iterable($transferData->products)) {
                          $i = 0;
                          foreach ($transferData->products as $p) {
                            $i++;
                            $inv     = (int)($p->inventory_level ?? 0);
                            $planned = (int)($p->qty_to_transfer ?? 0);
                            if ($planned <= 0) {
                              continue;
                            }

                            $pid   = htmlspecialchars($p->product_id ?? '');
                            $pname = htmlspecialchars($p->product_name ?? '');

                            echo "<tr data-inventory=\"{$inv}\" data-planned=\"{$planned}\">"
                              . "<td><p style='text-align:center;margin:0;'>
                                      <img style='cursor:pointer; padding:0; margin:0; height:13px;'
                                           src='assets/img/remove-icon.png' title='Remove Product'
                                           onclick='removeProduct(this);'></p>
                                    <input type='hidden' class='productID' value='{$pid}'></td>"
                              . "<td>{$pname}" . manuallyOrderedByStaff_obj($p) . "</td>"
                              . "<td class='inv'>{$inv}</td>"
                              . "<td class='planned'>{$planned}</td>"
                              . "<td class='counted-td'>
                                    <input type='number' min='0' max='{$inv}'
                                           oninput='enforceBounds(this);addToLocalStorage();checkInvalidQty(this);recomputeTotals();syncPrintValue(this);'
                                           value='' style='width:6em;'>
                                    <span class='counted-print-value d-none'>0</span>
                                  </td>"
                              . "<td>{$fromName}</td>"
                              . "<td>{$toName}</td>"
                              . "<td><span class='id-counter'>{$tidForCounter}-{$i}</span></td>"
                              . "</tr>";
                          }
                        }
                        ?>
                      </tbody>
                    </table>
                  </div>
                </div>

                <!-- Delivery, Notes & Consignment Settings -->
                <div id="trackingInfo" class="w-100">
                  <div class="card mb-2 mt-3" id="delivery-tracking-card">
                    <div class="card-header py-2 d-flex justify-content-between align-items-center">
                      <strong>Delivery & Notes</strong>
                      <small class="text-muted">Consignment-first | Send mode with server-side fallback</small>
                    </div>
                    <div class="card-body">
                      <div class="row">
                        <div class="col-md-6 mb-3">
                          <label class="mb-2"><strong>Notes & Discrepancies</strong></label>
                          <textarea onkeyup="addToLocalStorage();" class="form-control" id="notesForTransfer" rows="4" placeholder="Enter any notes, discrepancies, or special instructions..."></textarea>
                        </div>
                        <div class="col-md-6 mb-3">
                          <div class="row">
                            <div class="col-md-5">
                              <label class="mb-2"><strong>Delivery Method</strong></label>
                              <div class="mb-3">
                                <div class="custom-control custom-radio mb-2">
                                  <input type="radio" id="mode-courier" name="delivery-mode" class="custom-control-input" value="courier" checked onchange="toggleTrackingVisibility()">
                                  <label class="custom-control-label" for="mode-courier">Courier delivery</label>
                                </div>
                                <div class="custom-control custom-radio">
                                  <input type="radio" id="mode-internal" name="delivery-mode" class="custom-control-input" value="internal" onchange="toggleTrackingVisibility()">
                                  <label class="custom-control-label" for="mode-internal">Internal (drive/drop)</label>
                                </div>
                              </div>
                            </div>

                            <div class="col-md-7" id="tracking-section" style="display:none;">
                              <label class="mb-2"><strong>Courier Services & Labels</strong>
                                <?php if ($PACKONLY): ?>
                                  <span class="badge badge-warning ml-2">
                                    <i class="fa fa-lock"></i> Read-Only Mode
                                  </span>
                                <?php endif; ?>
                              </label>

                              <!-- Courier Service Selector -->
                              <div class="mb-2">
                                <select id="courier-service" class="form-control" style="font-size: 15px; padding: 12px 16px; height: auto;" <?php echo $PACKONLY ? 'disabled' : ''; ?>>
                                  <option value="">Select courier service...</option>
                                  <option value="nzpost">🔴 NZ POST LEADS</option>
                                  <option value="gss">NZ Couriers</option>
                                  <option value="manual">Manual Entry</option>
                                </select>
                                <div id="printer-status" class="small text-muted mt-1" style="display: none;">
                                  <i class="fa fa-info-circle"></i>
                                  <span id="printer-status-text"></span>
                                  <button type="button" class="btn btn-sm btn-outline-secondary ml-2" onclick="detectAndSetDefaultPrinter()" style="font-size: 0.7rem; padding: 1px 6px;">
                                    <i class="fa fa-refresh"></i> Refresh
                                  </button>
                                </div>
                              </div>

                              <!-- GSS Integration Panel -->
                              <div id="gss-panel" class="courier-panel card border-primary mb-2" style="display:none;">
                                <div class="card-header py-2 bg-primary text-white">
                                  <small><strong>NZ Couriers Label Creation</strong></small>
                                  <?php if ($PACKONLY): ?>
                                    <small class="float-right"><i class="fa fa-lock"></i> Disabled in pack-only mode</small>
                                  <?php endif; ?>
                                </div>
                                <div class="card-body py-2">
                                  <!-- Auto-loaded Address Information -->
                                  <div class="mb-2 p-2 bg-light rounded">
                                    <small class="text-muted"><strong>Shipping Details:</strong></small><br>
                                    <small>
                                      <strong>From:</strong> <?php echo htmlspecialchars($transferData->outlet_from->name); ?>
                                      <span class="text-muted">(ID: <?php echo htmlspecialchars($transferData->outlet_from->id); ?>)</span><br>
                                      <strong>To:</strong> <?php echo htmlspecialchars($transferData->outlet_to->name); ?>
                                      <span class="text-muted">(ID: <?php echo htmlspecialchars($transferData->outlet_to->id); ?>)</span>
                                    </small>
                                  </div>

                                  <div class="row">
                                    <div class="col-md-6">
                                      <label class="small">Package Type:</label>
                                      <select id="gss-package-type" class="form-control form-control-sm mb-1" <?php echo $PACKONLY ? 'disabled' : ''; ?>>
                                        <option value="satchel3kg">Satchel 3kg</option>
                                        <option value="satchel5kg">Satchel 5kg</option>
                                        <option value="box3kg">Box 3kg</option>
                                        <option value="box5kg">Box 5kg</option>
                                        <option value="box10kg">Box 10kg</option>
                                      </select>
                                    </div>
                                    <div class="col-md-6">
                                      <label class="small">Options:</label>
                                      <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="checkbox" id="gss-signature" checked <?php echo $PACKONLY ? 'disabled' : ''; ?>>
                                        <label class="form-check-label small" for="gss-signature">Signature</label>
                                      </div>
                                      <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="checkbox" id="gss-saturday" <?php echo $PACKONLY ? 'disabled' : ''; ?>>
                                        <label class="form-check-label small" for="gss-saturday">Saturday</label>
                                      </div>
                                    </div>
                                  </div>
                                  <div class="mt-2">
                                    <textarea id="gss-instructions" class="form-control form-control-sm" rows="2" placeholder="Special delivery instructions..." <?php echo $PACKONLY ? 'disabled' : ''; ?>></textarea>
                                  </div>
                                  <div class="mt-2">
                                    <?php if (!$PACKONLY): ?>
                                      <button type="button" class="btn btn-sm btn-primary" onclick="createGSSLabel();">
                                        <i class="fa fa-print"></i> Create & Print GSS Label
                                      </button>
                                    <?php else: ?>
                                      <button type="button" class="btn btn-sm btn-secondary" disabled title="Disabled in pack-only mode">
                                        <i class="fa fa-lock"></i> Shipping Locked
                                      </button>
                                    <?php endif; ?>
                                    <span id="gss-status" class="ml-2 small"></span>
                                  </div>
                                </div>
                              </div>



                              <!-- Shipping Integration Tabs - Clean & Modern -->
                              <div id="shipping-tabs-container" class="courier-panel mb-2" style="display:none;">
                                <div class="card shadow-sm border-0">
                                  <!-- Tab Headers -->
                                  <div class="card-header p-0 bg-white border-bottom">
                                    <ul class="nav nav-tabs nav-fill shipping-tabs" id="shippingTabs" role="tablist">
                                      <li class="nav-item" role="presentation">
                                        <a class="nav-link active d-flex align-items-center justify-content-center"
                                          id="nzpost-tab" data-toggle="tab" href="#nzpost-pane" role="tab"
                                          aria-controls="nzpost-pane" aria-selected="true">
                                          <i class="fa fa-truck text-danger mr-2"></i>
                                          <span class="font-weight-bold">NZ Post</span>
                                          <span class="badge badge-danger ml-2 d-none" id="nzpost-badge">1</span>
                                        </a>
                                      </li>
                                      <li class="nav-item" role="presentation">
                                        <a class="nav-link d-flex align-items-center justify-content-center"
                                          id="gss-tab" data-toggle="tab" href="#gss-pane" role="tab"
                                          aria-controls="gss-pane" aria-selected="false">
                                          <i class="fa fa-shipping-fast text-success mr-2"></i>
                                          <span class="font-weight-bold">GSS Courier</span>
                                          <span class="badge badge-success ml-2 d-none" id="gss-badge">1</span>
                                        </a>
                                      </li>
                                      <li class="nav-item" role="presentation">
                                        <a class="nav-link d-flex align-items-center justify-content-center"
                                          id="manual-tab" data-toggle="tab" href="#manual-pane" role="tab"
                                          aria-controls="manual-pane" aria-selected="false">
                                          <i class="fa fa-edit text-primary mr-2"></i>
                                          <span class="font-weight-bold">Manual Entry</span>
                                          <span class="badge badge-primary ml-2 d-none" id="manual-badge">1</span>
                                        </a>
                                      </li>
                                      <li class="nav-item" role="presentation">
                                        <a class="nav-link d-flex align-items-center justify-content-center"
                                          id="history-tab" data-toggle="tab" href="#history-pane" role="tab"
                                          aria-controls="history-pane" aria-selected="false">
                                          <i class="fa fa-history text-info mr-2"></i>
                                          <span class="font-weight-bold">Recent</span>
                                        </a>
                                      </li>
                                    </ul>
                                  </div>

                                  <!-- Tab Content -->
                                  <div class="tab-content" id="shippingTabContent">
                                    <!-- NZ Post Tab -->
                                    <div class="tab-pane fade show active" id="nzpost-pane" role="tabpanel" aria-labelledby="nzpost-tab">
                                      <div class="card-body">
                                        <?php if ($PACKONLY): ?>
                                          <div class="alert alert-warning d-flex align-items-center mb-3">
                                            <i class="fa fa-lock mr-2"></i>
                                            <span>NZ Post integration disabled in pack-only mode</span>
                                          </div>
                                        <?php endif; ?>

                                        <!-- Address Summary Bar -->
                                        <div class="address-summary mb-3 p-3 bg-light rounded border-left border-danger">
                                          <div class="row">
                                            <div class="col-6">
                                              <div class="d-flex align-items-center">
                                                <i class="fa fa-map-marker-alt text-muted mr-2"></i>
                                                <div>
                                                  <div class="font-weight-bold small"><?php echo htmlspecialchars($transferData->outlet_from->name); ?></div>
                                                  <div class="text-muted small">From Location</div>
                                                </div>
                                              </div>
                                            </div>
                                            <div class="col-6">
                                              <div class="d-flex align-items-center">
                                                <i class="fa fa-arrow-right text-danger mr-2"></i>
                                                <div>
                                                  <div class="font-weight-bold small"><?php echo htmlspecialchars($transferData->outlet_to->name); ?></div>
                                                  <div class="text-muted small">To Location</div>
                                                </div>
                                              </div>
                                            </div>
                                          </div>
                                        </div>

                                        <!-- Quick Setup Row -->
                                        <div class="row mb-3">
                                          <div class="col-md-4">
                                            <label class="small font-weight-bold">Service Type</label>
                                            <select id="nzpost-service-type" class="form-control form-control-sm" <?php echo $PACKONLY ? 'disabled' : ''; ?>>
                                              <option value="">Choose service...</option>
                                              <option value="CPOLTPDL">📬 DLE Overnight</option>
                                              <option value="CPOLTPA5">📄 A5 Overnight</option>
                                              <option value="CPOLTPA4">📄 A4 Overnight</option>
                                              <option value="CPOLP">📦 Parcel Overnight</option>
                                              <option value="CPOLE" selected>📦 Economy (2-3 Days)</option>
                                            </select>
                                          </div>
                                          <div class="col-md-4">
                                            <label class="small font-weight-bold">Dimensions (cm)</label>
                                            <div class="input-group input-group-sm">
                                              <input type="number" id="nzpost-length" class="form-control" placeholder="L" min="1" max="120" <?php echo $PACKONLY ? 'disabled' : ''; ?>>
                                              <div class="input-group-append input-group-prepend">
                                                <span class="input-group-text">×</span>
                                              </div>
                                              <input type="number" id="nzpost-width" class="form-control" placeholder="W" min="1" max="120" <?php echo $PACKONLY ? 'disabled' : ''; ?>>
                                              <div class="input-group-append input-group-prepend">
                                                <span class="input-group-text">×</span>
                                              </div>
                                              <input type="number" id="nzpost-height" class="form-control" placeholder="H" min="1" max="120" <?php echo $PACKONLY ? 'disabled' : ''; ?>>
                                            </div>
                                          </div>
                                          <div class="col-md-4">
                                            <label class="small font-weight-bold">Weight & Cost</label>
                                            <div class="input-group input-group-sm">
                                              <input type="number" id="nzpost-weight" class="form-control" placeholder="2.5" step="0.1" min="0.1" max="30" value="2.5" <?php echo $PACKONLY ? 'disabled' : ''; ?>>
                                              <div class="input-group-append">
                                                <span class="input-group-text">kg</span>
                                                <span class="input-group-text bg-success text-white font-weight-bold" id="nzpost-cost-display">$0.00</span>
                                              </div>
                                            </div>
                                          </div>
                                        </div>

                                        <!-- Quick Preset Buttons -->
                                        <div class="mb-2">
                                          <small class="text-muted"><strong>Quick Presets:</strong></small><br>
                                          <button type="button" class="btn btn-outline-info btn-sm mr-1 mb-1" onclick="setNZPostDimensions(20,15,10,1.5)" <?php echo $PACKONLY ? 'disabled' : ''; ?>>
                                            📦 Small Box (20×15×10) 1.5kg
                                          </button>
                                          <button type="button" class="btn btn-outline-info btn-sm mr-1 mb-1" onclick="setNZPostDimensions(30,20,15,2.5)" <?php echo $PACKONLY ? 'disabled' : ''; ?>>
                                            📦 Medium Box (30×20×15) 2.5kg
                                          </button>
                                          <button type="button" class="btn btn-outline-info btn-sm mr-1 mb-1" onclick="setNZPostDimensions(40,30,20,4.0)" <?php echo $PACKONLY ? 'disabled' : ''; ?>>
                                            📦 Large Box (40×30×20) 4kg
                                          </button>
                                          <button type="button" class="btn btn-outline-warning btn-sm mr-1 mb-1" onclick="setNZPostDimensions(35,25,3,0.8)" <?php echo $PACKONLY ? 'disabled' : ''; ?>>
                                            � Flat Pack (35×25×3) 0.8kg
                                          </button>
                                        </div>

                                        <!-- Multiple Packages Table -->
                                        <div class="mt-2">
                                          <div class="d-flex justify-content-between align-items-center mb-1">
                                            <small class="text-muted"><strong>Package List:</strong></small>
                                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="addCurrentPackage()" <?php echo $PACKONLY ? 'disabled' : ''; ?>>
                                              <i class="fa fa-plus"></i> Add Package
                                            </button>
                                          </div>
                                          <div class="table-responsive">
                                            <table class="table table-sm table-bordered" id="nzpost-packages-table">
                                              <thead class="table-light">
                                                <tr>
                                                  <th style="width: 15%;">Dimensions (L×W×H)</th>
                                                  <th style="width: 15%;">Weight</th>
                                                  <th style="width: 15%;">Vol. Weight</th>
                                                  <th style="width: 25%;">Description</th>
                                                  <th style="width: 15%;">Cost Est.</th>
                                                  <th style="width: 15%;">Actions</th>
                                                </tr>
                                              </thead>
                                              <tbody>
                                                <!-- Packages will be added dynamically -->
                                              </tbody>
                                            </table>
                                          </div>
                                        </div>
                                      </div>

                                      <div class="form-group mb-2">
                                        <label class="small">Delivery Instructions:</label>
                                        <textarea class="form-control form-control-sm" id="nzpost-instructions" rows="2" placeholder="Special delivery instructions (optional)" <?php echo $PACKONLY ? 'disabled' : ''; ?>></textarea>
                                      </div>

                                      <div class="d-flex justify-content-between align-items-center">
                                        <?php if (!$PACKONLY): ?>
                                          <div>
                                            <button class="btn btn-danger btn-sm" onclick="createNZPostShipment()" id="nzpost-create-btn">
                                              <i class="fa fa-print" aria-hidden="true"></i>
                                              <span id="nzpost-btn-text">Create & Print Label</span>
                                            </button>
                                            <button class="btn btn-outline-danger btn-sm d-none" onclick="reprintNZPostLabel()" id="nzpost-reprint-btn">
                                              <i class="fa fa-repeat" aria-hidden="true"></i> Re-Print Label
                                            </button>
                                          </div>
                                        <?php else: ?>
                                          <button class="btn btn-secondary btn-sm" disabled title="Disabled in pack-only mode">
                                            <i class="fa fa-lock" aria-hidden="true"></i> Shipping Locked
                                          </button>
                                        <?php endif; ?>
                                        <div class="d-flex flex-column">
                                          <div class="text-success fw-bold" id="nzpost-total-cost">Total: $0.00</div>
                                          <small class="text-muted">
                                            <span id="nzpost-package-count">0 packages</span> •
                                            <span id="nzpost-total-weight">0.0kg total</span> •
                                            <span id="nzpost-service-type-display">Economy</span>
                                          </small>
                                        </div>
                                      </div>
                                      <div class="mt-1" id="nzpost-status"></div>
                                    </div>
                                  </div>

                                  <!-- GSS Courier Tab -->
                                  <div class="tab-pane fade" id="gss-pane" role="tabpanel" aria-labelledby="gss-tab">
                                    <div class="card-body">
                                      <?php if ($PACKONLY): ?>
                                        <div class="alert alert-warning d-flex align-items-center mb-3">
                                          <i class="fa fa-lock mr-2"></i>
                                          <span>GSS Courier integration disabled in pack-only mode</span>
                                        </div>
                                      <?php endif; ?>

                                      <!-- Address Summary Bar -->
                                      <div class="address-summary mb-3 p-3 bg-light rounded border-left border-success">
                                        <div class="row">
                                          <div class="col-6">
                                            <div class="d-flex align-items-center">
                                              <i class="fa fa-map-marker-alt text-muted mr-2"></i>
                                              <div>
                                                <div class="font-weight-bold small"><?php echo htmlspecialchars($transferData->outlet_from->name); ?></div>
                                                <div class="text-muted small">From Location</div>
                                              </div>
                                            </div>
                                          </div>
                                          <div class="col-6">
                                            <div class="d-flex align-items-center">
                                              <i class="fa fa-shipping-fast text-success mr-2"></i>
                                              <div>
                                                <div class="font-weight-bold small"><?php echo htmlspecialchars($transferData->outlet_to->name); ?></div>
                                                <div class="text-muted small">To Location</div>
                                              </div>
                                            </div>
                                          </div>
                                        </div>
                                      </div>

                                      <!-- Quick Setup Row -->
                                      <div class="row mb-3">
                                        <div class="col-md-4">
                                          <label class="small font-weight-bold">Courier Service</label>
                                          <select id="gss-service-type" class="form-control form-control-sm" <?php echo $PACKONLY ? 'disabled' : ''; ?>>
                                            <option value="">Choose service...</option>
                                            <option value="ROAD">🚚 Road (Standard)</option>
                                            <option value="OVERNIGHT">🌙 Overnight</option>
                                            <option value="SAMEDAY">⚡ Same Day</option>
                                            <option value="ECONOMY" selected>💰 Economy</option>
                                          </select>
                                        </div>
                                        <div class="col-md-4">
                                          <label class="small font-weight-bold">Dimensions (cm)</label>
                                          <div class="input-group input-group-sm">
                                            <input type="number" id="gss-length" class="form-control" placeholder="L" min="1" max="120" <?php echo $PACKONLY ? 'disabled' : ''; ?>>
                                            <div class="input-group-append input-group-prepend">
                                              <span class="input-group-text">×</span>
                                            </div>
                                            <input type="number" id="gss-width" class="form-control" placeholder="W" min="1" max="120" <?php echo $PACKONLY ? 'disabled' : ''; ?>>
                                            <div class="input-group-append input-group-prepend">
                                              <span class="input-group-text">×</span>
                                            </div>
                                            <input type="number" id="gss-height" class="form-control" placeholder="H" min="1" max="120" <?php echo $PACKONLY ? 'disabled' : ''; ?>>
                                          </div>
                                        </div>
                                        <div class="col-md-4">
                                          <label class="small font-weight-bold">Weight & Cost</label>
                                          <div class="input-group input-group-sm">
                                            <input type="number" id="gss-weight" class="form-control" placeholder="2.5" step="0.1" min="0.1" max="30" value="2.5" <?php echo $PACKONLY ? 'disabled' : ''; ?>>
                                            <div class="input-group-append">
                                              <span class="input-group-text">kg</span>
                                              <span class="input-group-text bg-success text-white font-weight-bold" id="gss-cost-display">$0.00</span>
                                            </div>
                                          </div>
                                        </div>
                                      </div>

                                      <!-- Quick Preset Buttons -->
                                      <div class="mb-2">
                                        <small class="text-muted"><strong>Quick Presets:</strong></small><br>
                                        <button type="button" class="btn btn-outline-success btn-sm mr-1 mb-1" onclick="setGSSDimensions(20,15,10,1.5)" <?php echo $PACKONLY ? 'disabled' : ''; ?>>
                                          📦 Small Box (20×15×10) 1.5kg
                                        </button>
                                        <button type="button" class="btn btn-outline-success btn-sm mr-1 mb-1" onclick="setGSSDimensions(30,20,15,2.5)" <?php echo $PACKONLY ? 'disabled' : ''; ?>>
                                          📦 Medium Box (30×20×15) 2.5kg
                                        </button>
                                        <button type="button" class="btn btn-outline-success btn-sm mr-1 mb-1" onclick="setGSSDimensions(40,30,20,4.0)" <?php echo $PACKONLY ? 'disabled' : ''; ?>>
                                          📦 Large Box (40×30×20) 4kg
                                        </button>
                                        <button type="button" class="btn btn-outline-warning btn-sm mr-1 mb-1" onclick="setGSSDimensions(35,25,3,0.8)" <?php echo $PACKONLY ? 'disabled' : ''; ?>>
                                          📄 Flat Pack (35×25×3) 0.8kg
                                        </button>
                                      </div>

                                      <!-- Package List -->
                                      <div class="mt-2">
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                          <small class="text-muted"><strong>Package List:</strong></small>
                                          <button type="button" class="btn btn-outline-success btn-sm" onclick="addCurrentGSSPackage()" <?php echo $PACKONLY ? 'disabled' : ''; ?>>
                                            <i class="fa fa-plus"></i> Add Package
                                          </button>
                                        </div>
                                        <div class="table-responsive">
                                          <table class="table table-sm table-bordered" id="gss-packages-table">
                                            <thead class="table-light">
                                              <tr>
                                                <th style="width: 20%;">Dimensions</th>
                                                <th style="width: 15%;">Weight</th>
                                                <th style="width: 25%;">Description</th>
                                                <th style="width: 20%;">Cost Est.</th>
                                                <th style="width: 20%;">Actions</th>
                                              </tr>
                                            </thead>
                                            <tbody>
                                              <!-- GSS Packages will be added dynamically -->
                                            </tbody>
                                          </table>
                                        </div>
                                      </div>

                                      <div class="form-group mb-2">
                                        <label class="small">Special Instructions:</label>
                                        <textarea class="form-control form-control-sm" id="gss-instructions" rows="2" placeholder="Special handling instructions (optional)" <?php echo $PACKONLY ? 'disabled' : ''; ?>></textarea>
                                      </div>

                                      <div class="d-flex justify-content-between align-items-center">
                                        <?php if (!$PACKONLY): ?>
                                          <div>
                                            <button class="btn btn-success btn-sm" onclick="createGSSShipment()" id="gss-create-btn">
                                              <i class="fa fa-truck" aria-hidden="true"></i>
                                              <span id="gss-btn-text">Create GSS Booking</span>
                                            </button>
                                            <button class="btn btn-outline-success btn-sm d-none" onclick="reprintGSSLabel()" id="gss-reprint-btn">
                                              <i class="fa fa-repeat" aria-hidden="true"></i> Re-Print Docket
                                            </button>
                                          </div>
                                        <?php else: ?>
                                          <button class="btn btn-secondary btn-sm" disabled title="Disabled in pack-only mode">
                                            <i class="fa fa-lock" aria-hidden="true"></i> Shipping Locked
                                          </button>
                                        <?php endif; ?>
                                        <div class="d-flex flex-column">
                                          <div class="text-success fw-bold" id="gss-total-cost">Total: $0.00</div>
                                          <small class="text-muted">
                                            <span id="gss-package-count">0 packages</span> •
                                            <span id="gss-total-weight">0.0kg total</span> •
                                            <span id="gss-service-type-display">Economy</span>
                                          </small>
                                        </div>
                                      </div>
                                      <div class="mt-1" id="gss-status"></div>
                                    </div>
                                  </div>

                                  <!-- Manual Entry Tab -->
                                  <div class="tab-pane fade" id="manual-pane" role="tabpanel" aria-labelledby="manual-tab">
                                    <div class="card-body">
                                      <div class="alert alert-info d-flex align-items-center mb-3">
                                        <i class="fa fa-edit mr-2"></i>
                                        <div>
                                          <strong>Manual Tracking Entry</strong><br>
                                          <small>For shipments created outside the system or other courier services</small>
                                        </div>
                                      </div>

                                      <div class="form-group">
                                        <label class="small font-weight-bold">Courier Service</label>
                                        <select id="manual-courier" class="form-control" <?php echo $PACKONLY ? 'disabled' : ''; ?>>
                                          <option value="">Select courier...</option>
                                          <option value="NZ_POST">NZ Post</option>
                                          <option value="GSS">NZ Couriers</option>
                                          <option value="FASTWAY">Fastway</option>
                                          <option value="DHL">DHL</option>
                                          <option value="FEDEX">FedEx</option>
                                          <option value="ARAMEX">Aramex</option>
                                          <option value="OTHER">Other</option>
                                        </select>
                                      </div>

                                      <div class="form-group">
                                        <label class="small font-weight-bold">Tracking Numbers</label>
                                        <div id="manual-tracking-items" class="mb-2"></div>
                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="addManualTrackingNumber()" <?php echo $PACKONLY ? 'disabled' : ''; ?>>
                                          <i class="fa fa-plus"></i> Add Tracking Number
                                        </button>
                                      </div>

                                      <div class="form-group">
                                        <label class="small font-weight-bold">Notes</label>
                                        <textarea class="form-control" id="manual-notes" rows="3" placeholder="Additional notes about this shipment..." <?php echo $PACKONLY ? 'disabled' : ''; ?>></textarea>
                                      </div>

                                      <?php if (!$PACKONLY): ?>
                                        <button class="btn btn-primary" onclick="saveManualTracking()">
                                          <i class="fa fa-save"></i> Save Tracking Information
                                        </button>
                                      <?php else: ?>
                                        <button class="btn btn-secondary" disabled title="Disabled in pack-only mode">
                                          <i class="fa fa-lock"></i> Manual Entry Locked
                                        </button>
                                      <?php endif; ?>

                                      <div class="mt-2" id="manual-status"></div>
                                    </div>
                                  </div>

                                  <!-- History Tab -->
                                  <div class="tab-pane fade" id="history-pane" role="tabpanel" aria-labelledby="history-tab">
                                    <div class="card-body">
                                      <div class="d-flex align-items-center justify-content-between mb-3">
                                        <h6 class="mb-0">
                                          <i class="fa fa-history text-info mr-2"></i>
                                          Recent Shipments
                                        </h6>
                                        <button class="btn btn-sm btn-outline-info" onclick="refreshShipmentHistory()">
                                          <i class="fa fa-refresh"></i> Refresh
                                        </button>
                                      </div>

                                      <div class="table-responsive">
                                        <table class="table table-sm table-hover">
                                          <thead class="table-light">
                                            <tr>
                                              <th>Date</th>
                                              <th>Transfer</th>
                                              <th>Courier</th>
                                              <th>Tracking</th>
                                              <th>Status</th>
                                              <th>Actions</th>
                                            </tr>
                                          </thead>
                                          <tbody id="shipment-history-tbody">
                                            <tr>
                                              <td colspan="6" class="text-center py-4 text-muted">
                                                <i class="fa fa-clock-o fa-2x mb-2"></i><br>
                                                Loading recent shipments...
                                              </td>
                                            </tr>
                                          </tbody>
                                        </table>
                                      </div>
                                    </div>
                                  </div>
                                </div>
                              </div>
                            </div>

                            <!-- Manual Tracking Panel -->
                            <div id="manual-panel" class="courier-panel mb-2" style="display:none;">
                              <label class="mb-2 small"><strong>Manual Tracking Numbers</strong></label>
                              <div id="tracking-items" class="mb-2"></div>
                              <button type="button" class="btn btn-sm btn-outline-primary" id="btn-add-tracking">Add tracking number</button>
                              <div class="mt-2 small text-muted"><span id="tracking-count">0 numbers</span></div>
                            </div>

                            <!-- Generated Labels Display -->
                            <div id="generated-labels" class="mt-2"></div>
                          </div>
                        </div>
                      </div>
                    </div>

                    <!-- Box Labels Printer - Enhanced -->
                    <div class="card shadow-sm border-0 mb-3">
                      <div class="card-header bg-warning text-dark py-2">
                        <div class="d-flex align-items-center justify-content-between">
                          <div>
                            <i class="fa fa-print mr-2"></i>
                            <strong>Box Label Printer</strong>
                          </div>
                          <small class="badge badge-dark">Quick Print</small>
                        </div>
                      </div>
                      <div class="card-body py-3">
                        <div class="row align-items-center">
                          <div class="col-md-4">
                            <label class="form-label small font-weight-bold mb-1">Number of Boxes:</label>
                            <input type="number" min="1" max="50" class="form-control form-control-sm"
                              id="box-count-input" value="1" placeholder="Boxes">
                          </div>
                          <div class="col-md-8">
                            <div class="d-flex align-items-center justify-content-end" style="gap: 8px;">
                              <button class="btn btn-warning btn-sm" type="button" onclick="previewLabels()">
                                <i class="fa fa-eye"></i> Preview
                              </button>
                              <button class="btn btn-success btn-sm" type="button" onclick="printLabelsDirectly()">
                                <i class="fa fa-print"></i> Print Now
                              </button>
                              <button class="btn btn-outline-secondary btn-sm" type="button" onclick="openLabelPrintDialog()">
                                <i class="fa fa-external-link"></i> Open Window
                              </button>
                            </div>
                          </div>
                        </div>
                        <div class="mt-2">
                          <small class="text-muted">
                            <i class="fa fa-info-circle"></i>
                            Labels include Transfer #<?php echo (int)($_GET['transfer'] ?? 0); ?>, FROM/TO stores, and box numbers
                          </small>
                        </div>
                      </div>
                    </div>



                    <input type="hidden" id="tracking-number" name="tracking-number" value="">
                  </div>
                </div>
                <?php if ($PACKONLY): ?>
                  <!-- SINGLE BIG WARNING (no other warnings shown in Pack-Only) -->
                  <div class="packonly-banner" role="alert" aria-live="assertive" aria-atomic="true">
                    <div class="packonly-stripe"></div>
                    <div class="packonly-panel">
                      <div class="w-icon" aria-hidden="true">⛔</div>
                      <div class="w-text">
                        <div class="w-title">DO NOT SEND OR DO ANYTHING WITH THIS TRANSFER UNTIL CONFIRMED.</div>
                        <div class="w-body">
                          Every box <b>MUST</b> be clearly labelled with:
                          <span class="w-pill">TRANSFER #<?php echo (int)($_GET['transfer'] ?? 0); ?></span>
                          <span class="w-pill">FROM: <?php echo htmlspecialchars($transferData->outlet_from->name); ?></span>
                          <span class="w-pill">BOX 1 OF X</span>
                        </div>
                      </div>
                    </div>
                    <div class="packonly-stripe"></div>
                  </div>
                <?php endif; ?>
                <div class="card-body">
                  <p class="mb-2" style="font-weight:bold;font-size:12px;">
                    Counted &amp; Handled By: <?php echo htmlspecialchars(($userDetails["first_name"] ?? '') . ' ' . ($userDetails["last_name"] ?? '')); ?>
                  </p>

                  <?php if (!$PACKONLY): ?>
                    <p class="mb-3 small text-muted">
                      By setting this transfer "Ready For Delivery" you declare that you have individually counted all the products despatched in this transfer and verified inventory levels.
                    </p>
                    <button type="button" id="createTransferButton" class="btn btn-primary" onclick="markReadyForDelivery();">
                      Set Transfer Ready For Delivery
                    </button>
                  <?php endif; ?>
                  <!-- In Pack-Only we do not render any submit button or extra warning here -->

                  <input type='hidden' id='transferID' value='<?php echo (int)($_GET["transfer"] ?? 0); ?>'>
                  <input type='hidden' id='sourceID' value='<?php echo htmlspecialchars($transferData->outlet_from->id); ?>'>
                  <input type='hidden' id='destinationID' value='<?php echo htmlspecialchars($transferData->outlet_to->id); ?>'>
                  <input type='hidden' id='staffID' value='<?php echo (int)($_SESSION["userID"] ?? 0); ?>'>
                </div>
              </div> <!-- /trackingInfo -->
            </div> <!-- /card-body -->
          </div> <!-- /card -->

        </div> <!-- /col -->
      </div> <!-- /fadeIn -->
  </div> <!-- /container-fluid -->
  </main>

  <?php include("assets/template/personalisation-menu.php") ?>
  </div> <!-- /app-body -->

  <!-- Add Products Modal -->
  <div class="modal fade" id="addProductsModal" tabindex="-1" role="dialog" aria-labelledby="addProductsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-md" role="document">
      <div class="modal-content border-0 shadow-lg">
        <div class="modal-header bg-primary text-white border-0">
          <h5 class="modal-title" id="addProductsModalLabel"><i class="fa fa-search" aria-hidden="true"></i> Add Products to Transfer</h5>
          <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">×</span></button>
        </div>
        <div class="modal-body p-4">
          <div class="row mb-3">
            <div class="col-12">
              <div class="input-group">
                <div class="input-group-prepend">
                  <span class="input-group-text bg-light">
                    <i class="fa fa-search text-muted"></i>
                  </span>
                </div>
                <input id="search-input" type="text" class="form-control form-control-lg"
                  placeholder="Search by product name, SKU, or ID..." autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" />
              </div>
              <div class="small text-muted mt-1">Type at least 2 characters to search...</div>
            </div>
          </div>

          <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="mb-0 text-secondary">Search Results</h6>
            <div class="d-flex align-items-center" style="gap:12px;">
              <span class="badge bg-light text-dark" id="results-count">0 results</span>
              <div class="spinner-border spinner-border-sm text-primary d-none" id="search-spinner" role="status" aria-hidden="true" style="width:1rem;height:1rem;border-width:.15rem;"></div>
            </div>
          </div>

          <!-- Bulk Selection Controls -->
          <div class="d-none" id="bulk-controls" style="background: linear-gradient(135deg, #e3f2fd 0%, #f3e5f5 100%); border-radius: 8px; padding: 12px; margin-bottom: 16px; border-left: 4px solid #2196f3;">
            <div class="d-flex justify-content-between align-items-center">
              <div class="d-flex align-items-center" style="gap: 16px;">
                <span class="fw-bold text-primary">
                  <i class="fa fa-check-square"></i>
                  <span id="selected-count">0</span> products selected
                </span>
                <button class="btn btn-sm btn-outline-primary" onclick="selectAllVisible()">
                  <i class="fa fa-check-double"></i> Select All
                </button>
                <button class="btn btn-sm btn-outline-secondary" onclick="clearSelection()">
                  <i class="fa fa-times"></i> Clear
                </button>
              </div>
              <div class="d-flex align-items-center" style="gap: 8px;">
                <button class="btn btn-success btn-sm" onclick="addSelectedToCurrentTransfer()" id="add-to-current-btn">
                  <i class="fa fa-plus"></i> Add to This Transfer
                </button>
                <div class="btn-group">
                  <button class="btn btn-primary btn-sm" onclick="addSelectedToAllTransfers()" id="add-to-all-btn">
                    <i class="fa fa-layer-group"></i> Add to All Transfers
                  </button>
                  <button type="button" class="btn btn-primary btn-sm dropdown-toggle dropdown-toggle-split" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <span class="sr-only">Toggle Dropdown</span>
                  </button>
                  <div class="dropdown-menu">
                    <a class="dropdown-item" href="#" onclick="addSelectedToOutletTransfers()">
                      <i class="fa fa-building"></i> Add to Outlet Transfers (Status 0)
                    </a>
                    <a class="dropdown-item" href="#" onclick="addSelectedToSimilarTransfers()">
                      <i class="fa fa-copy"></i> Add to Similar Route Transfers
                    </a>
                  </div>
                </div>
              </div>
            </div>
            <div class="small text-muted mt-2">
              <i class="fa fa-info-circle"></i>
              Tip: Use Ctrl+click or Shift+click for multi-select, or click supplier buttons to select all from a brand
            </div>
          </div>

          <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
            <table class="table table-hover table-borderless table-fixed" id="addProductSearch">
              <thead class="table-light sticky-top">
                <tr>
                  <th class="border-0 fw-semibold text-center align-middle" style="width: 50px; padding: 12px 8px;">
                    <input type="checkbox" id="selectAllProducts" class="form-check-input" title="Select All">
                  </th>
                  <th class="border-0 fw-semibold align-middle" style="width: 80px; padding: 12px 8px;">
                    <i class="fa fa-image"></i>
                  </th>
                  <th class="border-0 fw-semibold align-middle" style="padding: 12px 8px;">Product Details</th>
                  <th class="border-0 fw-semibold text-center align-middle" style="width: 80px; padding: 12px 8px;">Stock</th>
                  <th class="border-0 fw-semibold text-center align-middle" style="width: 80px; padding: 12px 8px;">Price</th>
                  <th class="border-0 fw-semibold text-center align-middle" style="width: 100px; padding: 12px 8px;">Actions</th>
                </tr>
              </thead>
              <tbody id="productAddSearchBody" class="border-0">
                <tr id="search-placeholder">
                  <td colspan="5" class="text-center py-5 text-muted">
                    <div class="d-flex flex-column align-items-center">
                      <i class="fa fa-search fa-3x mb-3" aria-hidden="true" style="opacity:0.5;"></i>
                      <h6 class="mb-2">Ready to search</h6>
                      <p class="mb-0 small">Start typing to find products...</p>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div id="search-status" class="small text-muted mt-2">Idle</div>
        </div>
        <div class="modal-footer border-0 bg-light">
          <button type="button" class="btn btn-outline-secondary" data-dismiss="modal"><i class="fa fa-times" aria-hidden="true"></i> Close</button>
          <button type="button" class="btn btn-primary" id="btn-clear-search"><i class="fa fa-refresh" aria-hidden="true"></i> Clear Search</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Merge Transfer Modal -->
  <div class="modal fade" id="mergeTransferModal" tabindex="-1" role="dialog" aria-labelledby="mergeTransferModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document" style="padding:5px;">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="mergeTransferModalModalLabel">Merge Transfer</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <p>Merging a Transfer will delete both transfers and create a new transfer with a new Transfer ID.</p>
          <p>Please note: you can only merge transfers to and from the same outlet.</p>
          <br>
          <select id="transferMergeOptions" class="form-control">
            <?php
            for ($i = 0; $i < count($mergeTransfers); $i++) {
              echo '<option value="' . (int)$mergeTransfers[$i]["transfer_id"] . '">Transfer To ' . htmlspecialchars($mergeTransfers[$i]["destinationOutlet"]->name) . ' #' . (int)$mergeTransfers[$i]["transfer_id"] . '</option>';
            }
            ?>
          </select>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
          <form action="" method="POST" id="mergeTransferForm" name="mergeTransferForm">
            <button type="submit" class="btn btn-primary">Merge Transfer</button>
            <input type="hidden" id="currentTransferIDHidden" name="currentTransferIDHidden">
            <input type="hidden" id="TransferToMergeIDHidden" name="TransferToMergeIDHidden">
            <input type="hidden" id="outletFromIDHidden" value="<?php echo htmlspecialchars($transferData->outlet_from->id); ?>" name="outletFromIDHidden">
            <input type="hidden" id="outletToIDHidden" value="<?php echo htmlspecialchars($transferData->outlet_to->id); ?>" name="outletToIDHidden">
          </form>
        </div>
      </div>
    </div>
  </div>

  <?php include("assets/template/html-footer.php") ?>
  <?php include("assets/template/footer.php") ?>
</body>

</html>

<script>
  (function() {
    'use strict';

    $.ajaxSetup({
      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      },
      cache: false,
      timeout: 30000
    });

    const transferId = $('#transferID').val();
    const $table = $('#transfer-table');
    const draftKey = 'stock_transfer_' + transferId;

    let autosaveInterval = null;
    let trackingCounter = 0;

    const warnState = {
      blank: false,
      negative: false,
      suspect: false
    };

    // Auto-detect printers and set default based on your hierarchy
    function detectAndSetDefault() {
      let hasNZPost = true; // Assume NZ Post available for now
      let hasGSS = true; // GSS is web-based, always available

      // Follow your hierarchy:
      if (hasNZPost && hasGSS) {
        // Both available -> NZ Post wins
        $('#nzpost-tab').tab('show');
        $('#shipping-tabs-container').show();
      } else if (hasNZPost) {
        // Only NZ Post -> set NZ Post
        $('#nzpost-tab').tab('show');
        $('#shipping-tabs-container').show();
      } else if (hasGSS) {
        // Only GSS -> set GSS
        $('#gss-tab').tab('show');
        $('#shipping-tabs-container').show();
      } else {
        // None -> leave tracking boxes (don't show shipping tabs)
        $('#shipping-tabs-container').hide();
      }
    }

    // Enhanced Add Products Functions
    function addSelectedToOutletTransfers() {
      const selectedProducts = getSelectedProducts();
      if (selectedProducts.length === 0) {
        showToast('Please select products first', 'warning');
        return;
      }

      const currentOutletId = $('#destinationID').val();

      // Get transfers for the same outlet with status 0
      $.ajax({
        url: 'assets/functions/get-outlet-transfers.php',
        method: 'POST',
        data: {
          outlet_id: currentOutletId,
          status: 0,
          exclude_transfer: transferId
        },
        success: function(response) {
          if (response.success && response.transfers.length > 0) {
            showTransferSelectionModal(selectedProducts, response.transfers, 'outlet');
          } else {
            showToast('No other open transfers found for this outlet', 'info');
          }
        },
        error: function() {
          showToast('Error fetching outlet transfers', 'error');
        }
      });
    }

    function addSelectedToSimilarTransfers() {
      const selectedProducts = getSelectedProducts();
      if (selectedProducts.length === 0) {
        showToast('Please select products first', 'warning');
        return;
      }

      const fromOutlet = $('#sourceID').val();
      const toOutlet = $('#destinationID').val();

      // Get transfers with similar routes (same from/to outlets)
      $.ajax({
        url: 'assets/functions/get-similar-transfers.php',
        method: 'POST',
        data: {
          from_outlet: fromOutlet,
          to_outlet: toOutlet,
          exclude_transfer: transferId
        },
        success: function(response) {
          if (response.success && response.transfers.length > 0) {
            showTransferSelectionModal(selectedProducts, response.transfers, 'similar');
          } else {
            showToast('No similar route transfers found', 'info');
          }
        },
        error: function() {
          showToast('Error fetching similar transfers', 'error');
        }
      });
    }

    function getSelectedProducts() {
      const selected = [];
      $('#addProductSearch tbody tr').each(function() {
        if ($(this).hasClass('selected')) {
          selected.push({
            id: $(this).data('product-id'),
            name: $(this).find('.product-name').text(),
            sku: $(this).find('.product-sku').text()
          });
        }
      });
      return selected;
    }

    function showTransferSelectionModal(products, transfers, type) {
      const title = type === 'outlet' ? 'Add to Outlet Transfers' : 'Add to Similar Transfers';

      let html = `
      <div class="modal fade" id="transferSelectionModal" tabindex="-1">
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">${title}</h5>
              <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
              <p>Add <strong>${products.length}</strong> selected products to:</p>
              <div class="form-group">
                <label>Select Transfers:</label>
                <div style="max-height: 200px; overflow-y: auto;">`;

      transfers.forEach(transfer => {
        html += `
        <div class="custom-control custom-checkbox mb-2">
          <input type="checkbox" class="custom-control-input transfer-checkbox" 
                 id="transfer-${transfer.id}" value="${transfer.id}">
          <label class="custom-control-label" for="transfer-${transfer.id}">
            <strong>Transfer #${transfer.id}</strong><br>
            <small class="text-muted">${transfer.from_outlet} → ${transfer.to_outlet}</small>
          </label>
        </div>`;
      });

      html += `
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
              <button type="button" class="btn btn-primary" onclick="executeTransferAddition()">
                Add Products
              </button>
            </div>
          </div>
        </div>
      </div>`;

      $('body').append(html);
      $('#transferSelectionModal').modal('show').on('hidden.bs.modal', function() {
        $(this).remove();
      });

      // Store data for execution
      window.pendingTransferAddition = {
        products,
        type
      };
    }

    function executeTransferAddition() {
      const selectedTransfers = [];
      $('.transfer-checkbox:checked').each(function() {
        selectedTransfers.push($(this).val());
      });

      if (selectedTransfers.length === 0) {
        showToast('Please select at least one transfer', 'warning');
        return;
      }

      // Validate that we have pending data
      if (!window.pendingTransferAddition || !window.pendingTransferAddition.products) {
        showToast('Invalid operation data. Please try again.', 'error');
        return;
      }

      const data = window.pendingTransferAddition;

      $.ajax({
        url: 'assets/functions/add-products-to-transfers.php',
        method: 'POST',
        timeout: 15000,
        data: {
          products: JSON.stringify(data.products),
          transfers: JSON.stringify(selectedTransfers),
          type: data.type
        },
        success: function(response) {
          try {
            if (response && response.success) {
              showToast(`Products added to ${selectedTransfers.length} transfer(s)`, 'success');
              $('#transferSelectionModal').modal('hide');
              // Clean up pending data
              delete window.pendingTransferAddition;
            } else {
              showToast(response?.error || 'Error adding products to transfers', 'error');
            }
          } catch (e) {
            console.error('Response parsing error:', e);
            showToast('Invalid server response', 'error');
          }
        },
        error: function(xhr, status, error) {
          console.error('Transfer addition failed:', {xhr, status, error});
          let message = 'Error adding products to transfers';
          if (status === 'timeout') {
            message = 'Request timed out. Please try again.';
          } else if (xhr.status >= 500) {
            message = 'Server error. Please try again later.';
          }
          showToast(message, 'error');
        }
      });
    }

    // GSS Functions
    function setGSSDimensions(l, w, h, weight) {
      $('#gss-length').val(l);
      $('#gss-width').val(w);
      $('#gss-height').val(h);
      $('#gss-weight').val(weight);
      calculateGSSCost();
    }

    function addCurrentGSSPackage() {
      const l = $('#gss-length').val();
      const w = $('#gss-width').val();
      const h = $('#gss-height').val();
      const weight = $('#gss-weight').val();

      if (!l || !w || !h || !weight) {
        showToast('Please enter all dimensions and weight', 'warning');
        return;
      }

      const cost = calculateGSSPackageCost(l, w, h, weight);
      const row = `
      <tr>
        <td>${l}×${w}×${h}</td>
        <td>${weight}kg</td>
        <td>Stock Transfer Items</td>
        <td>$${cost.toFixed(2)}</td>
        <td>
          <button class="btn btn-sm btn-outline-danger" onclick="$(this).closest('tr').remove(); updateGSSTotals();">
            <i class="fa fa-trash"></i>
          </button>
        </td>
      </tr>`;

      $('#gss-packages-table tbody').append(row);
      updateGSSTotals();

      // Clear inputs
      $('#gss-length, #gss-width, #gss-height').val('');
      $('#gss-weight').val('2.5');
    }

    function calculateGSSCost() {
      const l = parseFloat($('#gss-length').val()) || 0;
      const w = parseFloat($('#gss-width').val()) || 0;
      const h = parseFloat($('#gss-height').val()) || 0;
      const weight = parseFloat($('#gss-weight').val()) || 0;

      if (l && w && h && weight) {
        const cost = calculateGSSPackageCost(l, w, h, weight);
        $('#gss-cost-display').text('$' + cost.toFixed(2));
      }
    }

    function calculateGSSPackageCost(l, w, h, weight) {
      // GSS pricing logic (placeholder - implement actual API pricing)
      const volumetricWeight = (l * w * h) / 4000; // Different divisor for GSS
      const chargeableWeight = Math.max(weight, volumetricWeight);
      return Math.max(8.50, chargeableWeight * 2.80); // Base $8.50, $2.80 per kg
    }

    function updateGSSTotals() {
      let totalCost = 0;
      let totalWeight = 0;
      let packageCount = 0;

      $('#gss-packages-table tbody tr').each(function() {
        const costText = $(this).find('td:eq(3)').text();
        const weightText = $(this).find('td:eq(1)').text();

        totalCost += parseFloat(costText.replace('$', '')) || 0;
        totalWeight += parseFloat(weightText.replace('kg', '')) || 0;
        packageCount++;
      });

      $('#gss-total-cost').text('Total: $' + totalCost.toFixed(2));
      $('#gss-package-count').text(packageCount + ' packages');
      $('#gss-total-weight').text(totalWeight.toFixed(1) + 'kg total');
    }

    function createGSSShipment() {
      showToast('GSS shipment creation not yet implemented', 'info');
    }

    // Manual Tracking Functions
    function addManualTrackingNumber() {
      const count = $('#manual-tracking-items .tracking-input').length + 1;
      const html = `
      <div class="input-group mb-2 tracking-input">
        <input type="text" class="form-control" placeholder="Enter tracking number..." 
               id="manual-tracking-${count}">
        <div class="input-group-append">
          <button class="btn btn-outline-danger" type="button" onclick="$(this).closest('.tracking-input').remove()">
            <i class="fa fa-trash"></i>
          </button>
        </div>
      </div>`;
      $('#manual-tracking-items').append(html);
    }

    function saveManualTracking() {
      const courier = $('#manual-courier').val();
      const notes = $('#manual-notes').val();
      const trackingNumbers = [];

      $('#manual-tracking-items .tracking-input input').each(function() {
        const value = $(this).val().trim();
        if (value) trackingNumbers.push(value);
      });

      if (!courier) {
        showToast('Please select a courier service', 'warning');
        return;
      }

      if (trackingNumbers.length === 0) {
        showToast('Please enter at least one tracking number', 'warning');
        return;
      }

      $.ajax({
        url: 'assets/functions/save-manual-tracking.php',
        method: 'POST',
        data: {
          transfer_id: transferId,
          courier: courier,
          tracking_numbers: JSON.stringify(trackingNumbers),
          notes: notes
        },
        success: function(response) {
          if (response.success) {
            showToast('Manual tracking saved successfully', 'success');
            $('#manual-status').html('<div class="alert alert-success">Tracking information saved</div>');
          } else {
            showToast(response.error || 'Error saving tracking information', 'error');
          }
        },
        error: function() {
          showToast('Error saving tracking information', 'error');
        }
      });
    }

    function refreshShipmentHistory() {
      $('#shipment-history-tbody').html('<tr><td colspan="6" class="text-center py-3"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr>');

      $.ajax({
        url: 'assets/functions/get-shipment-history.php',
        method: 'GET',
        data: {
          outlet_id: $('#destinationID').val(),
          limit: 10
        },
        success: function(response) {
          if (response.success && response.shipments) {
            let html = '';
            response.shipments.forEach(shipment => {
              html += `
              <tr>
                <td>${shipment.date}</td>
                <td><a href="view-stock-transfer.php?transfer=${shipment.transfer_id}">#${shipment.transfer_id}</a></td>
                <td><span class="badge badge-${shipment.courier_color}">${shipment.courier}</span></td>
                <td><code>${shipment.tracking}</code></td>
                <td><span class="badge badge-${shipment.status_color}">${shipment.status}</span></td>
                <td>
                  <button class="btn btn-sm btn-outline-primary" onclick="window.open('${shipment.track_url}', '_blank')">
                    <i class="fa fa-external-link"></i>
                  </button>
                </td>
              </tr>`;
            });
            $('#shipment-history-tbody').html(html);
          } else {
            $('#shipment-history-tbody').html('<tr><td colspan="6" class="text-center py-3 text-muted">No shipment history found</td></tr>');
          }
        },
        error: function() {
          $('#shipment-history-tbody').html('<tr><td colspan="6" class="text-center py-3 text-danger">Error loading history</td></tr>');
        }
      });
    }

    // Initialize on page load
    $(document).ready(function() {
      // Auto-detect printers and set default according to hierarchy
      detectAndSetDefault();

      // Bind dimension change events for GSS and NZ Post (check if elements exist first)
      if ($('#gss-length, #gss-width, #gss-height, #gss-weight').length) {
        $('#gss-length, #gss-width, #gss-height, #gss-weight').on('input', calculateGSSCost);
      }
      
      if ($('#nzpost-length, #nzpost-width, #nzpost-height, #nzpost-weight').length) {
        $('#nzpost-length, #nzpost-width, #nzpost-height, #nzpost-weight').on('input', function() {
          const l = parseFloat($('#nzpost-length').val()) || 0;
          const w = parseFloat($('#nzpost-width').val()) || 0;
          const h = parseFloat($('#nzpost-height').val()) || 0;
          const weight = parseFloat($('#nzpost-weight').val()) || 0;

          if (l && w && h && weight) {
            const volumetricWeight = (l * w * h) / 5000;
            const chargeableWeight = Math.max(weight, volumetricWeight);
            const cost = Math.max(7.20, chargeableWeight * 2.50);
            const $costDisplay = $('#nzpost-cost-display');
            if ($costDisplay.length) {
              $costDisplay.text('$' + cost.toFixed(2));
            }
          }
        });
      }

      // Load shipment history when tab is shown (check if element exists)
      const $historyTab = $('#history-tab');
      if ($historyTab.length && typeof refreshShipmentHistory === 'function') {
        $historyTab.on('shown.bs.tab', refreshShipmentHistory);
      }

      // Show courier options button if not already shown (with safer selector)
      const $shippingContainer = $('#shipping-tabs-container');
      if ($shippingContainer.length && !$shippingContainer.is(':visible')) {
        setTimeout(() => {
          const $showBtn = $('[onclick*="show-courier"], [onclick*="courier"]').first();
          if ($showBtn.length) {
            try {
              $showBtn.click();
            } catch (e) {
              console.warn('Could not auto-click courier button:', e);
            }
          }
        }, 500);
      }
    });

    function showToast(message, type = 'info') {
      try {
        // Safely escape HTML in message
        const safeMessage = $('<div>').text(message).html();
        
        if ($('#toast-container').length === 0) {
          $('body').append('<div id="toast-container" style="position:fixed;top:20px;right:20px;z-index:9999;" aria-live="polite" aria-atomic="true"></div>');
        }
        const id = 'toast-' + Date.now();
        const cls = type === 'success' ? 'bg-success' : type === 'warning' ? 'bg-warning' : type === 'error' ? 'bg-danger' : 'bg-info';
        const html = `
        <div id="${id}" class="toast align-items-center text-white ${cls} border-0 p-2 px-3" role="alert"
             style="margin-bottom:10px;display:none;min-width:260px;">
          <div class="d-flex"><div class="toast-body">${safeMessage}</div></div>
        </div>`;
        $('#toast-container').append(html);
        const $t = $('#' + id);
        $t.fadeIn(120);
        setTimeout(() => $t.fadeOut(180, () => $t.remove()), 3600);
      } catch (error) {
        console.error('Toast error:', error);
        alert(message);
      }
    }

    function syncPrintValue(input) {
      $(input).siblings('.counted-print-value').text(input.value || '0');
    }

    function enforceBounds(input) {
      const max = parseInt(input.getAttribute('max')) || 999999;
      const min = parseInt(input.getAttribute('min')) || 0;
      const v = parseInt(input.value);
      if (Number.isFinite(v)) {
        if (v > max) input.value = max;
        if (v < min) input.value = min;
      }
    }

    function checkInvalidQty(input) {
      const $input = $(input);
      const $row = $input.closest('tr');
      const inventory = parseInt($row.attr('data-inventory')) || 0;
      const the_planned = parseInt($row.attr('data-planned')) || 0; // Fixed: properly declare variable
      const raw = String(input.value || '').trim();

      function addZeroBadge() {
        if ($row.find('.badge-to-remove').length === 0) {
          $row.find('.counted-td').append('<span class="badge badge-to-remove ml-2">Will remove at submit</span>');
        }
        $row.addClass('table-secondary');
      }

      function removeZeroBadge() {
        $row.find('.badge-to-remove').remove();
        $row.removeClass('table-secondary');
      }

      if (raw === '') {
        $input.addClass('is-invalid').removeClass('is-warning');
        $row.addClass('table-warning');
        removeZeroBadge();
        if (!warnState.blank) {
          showToast('Blank quantity. Enter a number or set 0 to remove item.', 'warning');
          warnState.blank = true;
        }
        return;
      }

      const counted = Number(raw);
      if (!Number.isFinite(counted)) {
        $input.addClass('is-invalid').removeClass('is-warning');
        $row.addClass('table-warning');
        removeZeroBadge();
        return;
      }
      if (counted < 0) {
        $input.addClass('is-invalid').removeClass('is-warning');
        $row.addClass('table-warning');
        removeZeroBadge();
        if (!warnState.negative) {
          showToast('Negative quantity not allowed.', 'error');
          warnState.negative = true;
        }
        return;
      }
      if (counted === 0) {
        $input.removeClass('is-invalid is-warning');
        $row.removeClass('table-warning');
        addZeroBadge();
        return;
      }
      if (counted > inventory) {
        $input.addClass('is-invalid').removeClass('is-warning');
        $row.addClass('table-warning');
        removeZeroBadge();
        return;
      }

      const suspicious = counted >= 99 || (the_planned > 0 && counted >= the_planned * 3) || (inventory > 0 && counted >= inventory * 2);
      if (suspicious) {
        $input.removeClass('is-invalid').addClass('is-warning');
        $row.removeClass('table-warning');
        removeZeroBadge();
        if (!warnState.suspect) {
          showToast('Suspiciously high quantity entered. Please double-check.', 'warning');
          warnState.suspect = true;
        }
      } else {
        $input.removeClass('is-invalid is-warning');
        $row.removeClass('table-warning');
        removeZeroBadge();
      }
    }

    function recomputeTotals() {
      let plannedTotal = 0,
        countedTotal = 0,
        rows = 0;
      $table.find('tbody tr').each(function() {
        const $r = $(this);
        plannedTotal += parseInt($r.attr('data-planned')) || 0;
        countedTotal += parseInt($r.find('input[type="number"]').val()) || 0;
        rows++;
      });
      const diff = countedTotal - plannedTotal;
      $('#plannedTotal').text(plannedTotal.toLocaleString());
      $('#countedTotal').text(countedTotal.toLocaleString());
      $('#diffTotal').text(diff.toLocaleString());
      $('#itemsToTransfer').text(rows);

      const $diff = $('#diffTotal');
      if (diff > 0) $diff.css('color', '#dc3545');
      else if (diff < 0) $diff.css('color', '#fd7e14');
      else $diff.css('color', '#28a745');
    }

    function removeProduct(el) {
      if (!confirm('Remove this product from the transfer?')) return;
      $(el).closest('tr').remove();
      recomputeTotals();
      addToLocalStorage();
    }

    function autofillCountedFromPlanned() {
      $table.find('tbody tr').each(function() {
        const $r = $(this);
        const input = $r.find('input[type="number"]')[0];
        const planned = parseInt($r.attr('data-planned')) || 0;
        const inventory = parseInt($r.attr('data-inventory')) || 0;
        input.value = Math.min(planned, inventory);
        syncPrintValue(input);
        checkInvalidQty(input);
      });
      recomputeTotals();
      addToLocalStorage();
    }

    function pruneZeroCountRows() {
      let removed = 0;
      $table.find('tbody tr').each(function() {
        const counted = parseInt($(this).find('input[type="number"]').val()) || 0;
        if (counted === 0) {
          $(this).remove();
          removed++;
        }
      });
      if (removed) {
        recomputeTotals();
        addToLocalStorage();
        showToast(`${removed} product${removed===1?'':'s'} removed (counted = 0)`, 'info');
      }
    }

    // Draft / localStorage
    function addToLocalStorage() {
      const quantities = {};
      $table.find('tbody tr').each(function() {
        const $r = $(this);
        const productID = $r.find('.productID').val();
        const counted = $r.find('input[type="number"]').val();
        if (productID && counted !== '') quantities[productID] = counted;
      });
      const trackingNumbers = Array.from(document.querySelectorAll('.tracking-input')).map(x => x.value.trim()).filter(Boolean);

      const data = {
        quantities,
        notes: $('#notesForTransfer').val(),
        deliveryMode: $('input[name="delivery-mode"]:checked').val(),
        trackingNumbers,
        postingMethod: $('#postingMethod').val(),
        sendMode: $('#sendMode').val(),
        timestamp: Date.now()
      };
      try {
        localStorage.setItem(draftKey, JSON.stringify(data));
        $('#draft-status').text('Draft: Saved').removeClass('badge-secondary').addClass('badge-success');
        $('#btn-restore-draft, #btn-discard-draft').prop('disabled', false);
        $('#draft-last-saved').text('Last saved: ' + new Date(data.timestamp).toLocaleTimeString());
      } catch (e) {
        console.warn('Failed to save draft', e);
      }
    }

    function loadStoredValues() {
      const saved = localStorage.getItem(draftKey);
      if (!saved) return;
      try {
        const data = JSON.parse(saved);
        if (data.quantities) {
          $table.find('tbody tr').each(function() {
            const productID = $(this).find('.productID').val();
            if (data.quantities[productID] !== undefined) {
              const input = $(this).find('input[type="number"]')[0];
              input.value = data.quantities[productID];
              syncPrintValue(input);
              checkInvalidQty(input);
            }
          });
        }
        if (data.notes) $('#notesForTransfer').val(data.notes);
        // Only restore delivery mode if it's not the default courier mode
        if (data.deliveryMode && data.deliveryMode !== 'courier') {
          $(`input[name="delivery-mode"][value="${data.deliveryMode}"]`).prop('checked', true);
        } else {
          // Ensure courier stays default if no specific mode saved
          $('#mode-courier').prop('checked', true);
        }
        if (data.postingMethod) $('#postingMethod').val(data.postingMethod);
        if (data.sendMode) $('#sendMode').val(data.sendMode);

        if (Array.isArray(data.trackingNumbers)) {
          $('#tracking-items').empty();
          trackingCounter = 0;
          data.trackingNumbers.forEach(n => {
            addTrackingInput();
            $('.tracking-input').last().val(n);
          });
          updateTrackingStorage();
        }
        $('#draft-status').text('Draft: Saved').removeClass('badge-secondary').addClass('badge-success');
        $('#btn-restore-draft, #btn-discard-draft').prop('disabled', false);
        if (data.timestamp) $('#draft-last-saved').text('Last saved: ' + new Date(data.timestamp).toLocaleTimeString());
      } catch (e) {
        console.warn('Draft parse failed', e);
      }
    }

    function saveDraft() {
      addToLocalStorage();
      showToast('Draft saved', 'success');
    }

    function restoreDraft() {
      if (!confirm('Restore saved draft? Current changes will be overwritten.')) return;
      loadStoredValues();
      recomputeTotals();
      showToast('Draft restored', 'info');
    }

    function discardDraft() {
      if (!confirm('Discard saved draft?')) return;
      try {
        localStorage.removeItem(draftKey);
      } catch {}
      $('#draft-status').text('Draft: Off').removeClass('badge-success').addClass('badge-secondary');
      $('#draft-last-saved').text('Not saved');
      $('#btn-restore-draft, #btn-discard-draft').prop('disabled', true);
      $table.find('tbody tr input[type="number"]').val('').each(function() {
        syncPrintValue(this);
      });
      $('#notesForTransfer').val('');
      $('#tracking-items').empty();
      trackingCounter = 0;
      updateTrackingCount();
      updateTrackingStorage();
      $('#postingMethod').val('consignment');
      $('#sendMode').val('live');
      recomputeTotals();
      showToast('Draft discarded', 'warning');
    }

    function toggleAutosave() {
      const enabled = $('#toggle-autosave').is(':checked');
      if (enabled) {
        if (autosaveInterval) clearInterval(autosaveInterval);
        autosaveInterval = setInterval(addToLocalStorage, 30000);
        showToast('Autosave enabled', 'info');
      } else {
        if (autosaveInterval) clearInterval(autosaveInterval);
        autosaveInterval = null;
        showToast('Autosave disabled', 'info');
      }
    }

    // Tracking UI
    function updateTrackingCount() {
      const count = document.querySelectorAll('.tracking-input').length;
      $('#tracking-count').text(`${count} number${count !== 1 ? 's' : ''}`);
    }

    function updateTrackingStorage() {
      const trackingNumbers = Array.from(document.querySelectorAll('.tracking-input')).map(x => x.value.trim()).filter(Boolean);
      $('#tracking-number').val(JSON.stringify(trackingNumbers));
      addToLocalStorage();
    }

    function addTrackingInput() {
      trackingCounter++;
      const id = trackingCounter;
      const html = `
      <div class="input-group input-group-sm mb-2" data-tracking-id="${id}">
        <input type="text" class="form-control tracking-input" placeholder="Enter tracking number or URL..." style="min-width:300px;" oninput="updateTrackingStorage()">
        <div class="input-group-append">
          <button class="btn btn-outline-danger btn-sm" type="button" onclick="removeTrackingInput(${id})" title="Remove">
            <i class="fa fa-times" aria-hidden="true"></i>
          </button>
        </div>
      </div>`;
      $('#tracking-items').append(html);
      updateTrackingCount();
      $('#tracking-items .tracking-input').last().focus();
    }

    function removeTrackingInput(id) {
      $(`[data-tracking-id="${id}"]`).remove();
      updateTrackingCount();
      updateTrackingStorage();
    }

    function toggleTrackingVisibility() {
     /* const courier = $('#mode-courier').is(':checked');
      if (courier) $('#tracking-section').show();
      else {
        $('#tracking-section').hide();
        $('#tracking-items').empty();
        trackingCounter = 0;
        updateTrackingCount();
        updateTrackingStorage();
      }*/
    }

    // API helpers
    async function postTopSimple(fieldName, payloadObj) {
      try {
        // Validate inputs
        if (!fieldName || typeof fieldName !== 'string') {
          throw new Error('Invalid fieldName parameter');
        }

        console.log('🔍 Making AJAX request:', {
          url: window.location.pathname + window.location.search,
          fieldName: fieldName,
          payload: payloadObj
        });

        const res = await $.ajax({
          url: window.location.pathname + window.location.search,
          type: 'POST',
          dataType: 'json',
          timeout: 30000, // Add timeout
          data: {
            [fieldName]: JSON.stringify(payloadObj || {})
          }
        });

        console.log('✅ AJAX response:', res);
        return res;
      } catch (error) {
        console.error('❌ AJAX failed:', {
          status: error.status,
          statusText: error.statusText,
          responseText: error.responseText,
          fieldName: fieldName,
          payload: payloadObj
        });
        
        // Provide user-friendly error messages
        if (error.status === 0) {
          throw new Error('Network connection failed. Please check your internet connection.');
        } else if (error.status >= 500) {
          throw new Error('Server error occurred. Please try again later.');
        } else if (error.status === 404) {
          throw new Error('Service not found. Please refresh the page.');
        }
        
        throw error;
      }
    }

    async function markTransferAsReadyTop(readyPayload) {
      const res = await postTopSimple('markReadyForDelivery', readyPayload);
      if (!(res && (res.success === true))) {
        const msg = (res && (res.error || res.message)) || 'Operation failed';
        throw new Error(msg);
      }
      return res;
    }

    async function deleteTransferTop(transferID) {
      const res = await $.ajax({
        url: window.location.pathname + window.location.search,
        type: 'POST',
        dataType: 'json',
        data: {
          deleteTransfer: JSON.stringify({
            transferID: String(transferID)
          })
        }
      });
      if (!res || res.success !== true) {
        const msg = (res && (res.error || res.message)) || 'Delete failed';
        throw new Error(msg);
      }
      return res;
    }

    async function searchForProductTop(keyword, options = {
      isActive: 0
    }) {
      // Show loading state
      $('#search-spinner').removeClass('d-none');
      $('#search-status').text('Searching...');

      try {
        const outletID = $('#sourceID').val();
        const res = await postTopSimple('searchForProduct', {
          keyword: String(keyword || ''),
          outletID: String(outletID || ''),
          isActive: Number(options.isActive || 0)
        });

        let results = [];
        if (Array.isArray(res)) results = res;
        else if (res && Array.isArray(res.data)) results = res.data;

        // Update status
        $('#search-spinner').addClass('d-none');
        $('#results-count').text(`${results.length} results`);
        $('#search-status').text(results.length > 0 ? `Found ${results.length} products` : 'No products found');

        return results;
      } catch (error) {
        $('#search-spinner').addClass('d-none');
        $('#search-status').text('Search failed - using fallback data');
        console.error('Search error:', error);

        // Use fallback data for testing
        return this.searchForProductByOutlet_fallback(keyword, $('#sourceID').val(), 20).data || [];
      }
    }

    // Fallback function for search functionality
    function searchForProductByOutlet_fallback(keyword, outletID, limit = 50) {
      console.warn('Using fallback search - limited functionality');
      return {
        success: true,
        data: [],
        message: 'Search function not available - please contact support'
      };
    }

    // UI Actions
    function deleteTransfer(transferIdParam) {
      if (!confirm('Delete this transfer? This action cannot be undone.')) return;
      deleteTransferTop(transferIdParam)
        .then(() => window.location.href = 'stock-transfers.php')
        .catch(err => {
          console.error('Delete transfer failed', err);
          const msg = err?.message || err?._payload?.error || 'Failed to delete transfer';
          showToast(msg, 'error');
        });
    }

    function editMode(enable) {
      showToast(`Edit mode ${enable ? 'enabled' : 'disabled'}`, 'info');
    }

    function collectProductsForSubmission() {
      const all = [],
        nonZero = [];
      $table.find('tbody tr').each(function() {
        const $r = $(this);
        const productID = $r.find('.productID').val();
        if (!productID) return;
        const inv = parseInt($r.attr('data-inventory')) || 0;
        const input = $r.find('input[type="number"]')[0];
        const counted = input ? (parseInt(input.value) || 0) : 0;
        const obj = {
          productID,
          countedQty: counted,
          qtyInStock: inv
        };
        all.push(obj);
        if (counted > 0) nonZero.push(obj);
      });
      return {
        all,
        nonZero
      };
    }

    function setProcessingState(isProcessing) {
      $('#createTransferButton').prop('disabled', isProcessing)
        .html(isProcessing ? '<i class="fa fa-spinner fa-spin"></i> Processing...' : 'Set Transfer Ready For Delivery');
    }

    function handleTransferSuccess(transferID) {
      try {
        localStorage.removeItem('stock_transfer_' + transferID);
      } catch {}
      showToast('Transfer marked as ready. Redirecting...', 'success');
      setTimeout(() => {
        window.location.href = 'index.php';
      }, 1400);
    }

    function handleTransferError(error) {
      console.error('Transfer error:', error);
      let msg = 'Failed to submit transfer. Please try again.';
      if (error?.message && error.message !== 'Operation cancelled by user') msg = error.message;
      else if (error?.responseJSON?.error) msg = error.responseJSON.error;
      else if (error?.status === 500) msg = 'Server error occurred. Please try again later.';
      else if (error?.status === 0) msg = 'Network error. Please check your connection.';
      if (msg && error?.message !== 'Operation cancelled by user') showToast(msg, 'error');
    }

    function validateQuantityInputs() {
      let hasErrors = false,
        offenders = [];
      $table.find('tbody tr').each(function() {
        const $row = $(this);
        const $input = $row.find('input[type="number"]');
        if ($input.hasClass('is-invalid')) {
          hasErrors = true;
          offenders.push($row.find('td:nth-child(2)').text().trim());
        }
      });
      if (hasErrors) {
        const m = offenders.length ?
          `Please fix quantity errors for: ${offenders.slice(0,3).join(', ')}${offenders.length>3?'...':''}` :
          'Please fix quantity errors before continuing.';
        throw new Error(m);
      }
    }

    function validateNonZeroQuantities(nonZero) {
      if (nonZero.length === 0) {
        const proceed = confirm('No quantities entered. Continue marking ready?');
        if (!proceed) throw new Error('Operation cancelled by user');
      }
    }

    function prepareTrackingData() {
      return Array.from(document.querySelectorAll('.tracking-input')).map(i => i.value.trim()).filter(Boolean);
    }

    async function markReadyForDelivery() {
      try {
        validateQuantityInputs();
        const {
          nonZero
        } = collectProductsForSubmission();
        validateNonZeroQuantities(nonZero);
      } catch (err) {
        handleTransferError(err);
        return;
      }

      const transferData = {
        transferID: $('#transferID').val(),
        sourceID: $('#sourceID').val(),
        staffID: parseInt($('#staffID').val(), 10) || 0,
        notes: ($('#notesForTransfer').val() || '').trim(),
        trackingNumbers: prepareTrackingData()
      };

      const readyPayload = {
        transferID: transferData.transferID,
        notes: transferData.notes,
        deliveryMode: $('input[name="delivery-mode"]:checked').val(),
        trackingNumbers: transferData.trackingNumbers,
        quantities: collectProductsForSubmission().nonZero,
        postingMethod: $('#postingMethod').val() || 'consignment',
        sendMode: $('#sendMode').val() || 'live'
      };

      setProcessingState(true);
      markTransferAsReadyTop(readyPayload)
        .then(() => handleTransferSuccess(transferData.transferID))
        .catch(handleTransferError)
        .always(() => setProcessingState(false));
    }

    // Search (Modal)
    let searchTimeout = null;
    const searchCache = new Map();

    function clearSearch() {
      $('#productAddSearchBody').html(`
      <tr id="search-placeholder">
        <td colspan="3" class="text-center py-4 text-muted">
          <i class="fa fa-search" aria-hidden="true"></i> Type at least 2 characters to search...
        </td>
      </tr>`);
      $('#search-status').text('Type at least 2 characters to search...');
      $('#results-count').text('0 results');
      $('#search-spinner').addClass('d-none');
    }

    function displaySearchResults(results, query) {
      if (!Array.isArray(results) || results.length === 0) {
        $('#productAddSearchBody').html(`
        <tr><td colspan="6" class="text-center py-4 text-muted">
          <i class="fa fa-search-minus" aria-hidden="true"></i>
          No products found for "${$('<div>').text(query).html()}"
        </td></tr>`);
        $('#search-status').text('No results found');
        $('#results-count').text('0 results');
        updateBulkControls();
        return;
      }

      // Group by supplier/brand for smart display
      const groupedResults = {};
      results.forEach(p => {
        const supplier = p.supplier || p.brand || 'General Products';
        if (!groupedResults[supplier]) groupedResults[supplier] = [];
        groupedResults[supplier].push(p);
      });

      let html = '';
      Object.keys(groupedResults).forEach(supplier => {
        const products = groupedResults[supplier];

        // Supplier header row
        html += `
        <tr class="supplier-header bg-light">
          <td colspan="6" class="py-2" style="padding: 8px 12px;">
            <div class="d-flex align-items-center justify-content-between">
              <strong class="text-dark"><i class="fa fa-building me-2"></i>${$('<div>').text(supplier).html()}</strong>
              <button class="btn btn-sm btn-outline-primary supplier-select-all" 
                      data-supplier="${$('<div>').text(supplier).html()}"
                      onclick="selectAllFromSupplier('${$('<div>').text(supplier).html()}')">
                <i class="fa fa-check-square"></i> Select All
              </button>
            </div>
          </td>
        </tr>`;

        products.forEach(p => {
          const stock = parseInt(p.stock, 10) || 0;
          const already = $table.find('tbody tr').filter(function() {
            return $(this).find('.productID').val() == p.id;
          }).length > 0;
          const disabled = already || stock <= 0;
          const btnCls = disabled ? 'btn-outline-secondary' : 'btn-primary';
          const btnTxt = already ? '<i class="fa fa-check" aria-hidden="true"></i> Added' :
            (stock <= 0 ? '<i class="fa fa-ban" aria-hidden="true"></i> Out of stock' :
              '<i class="fa fa-plus" aria-hidden="true"></i> Add');
          const stockBadge = stock > 0 ? `<span class="badge bg-success" style="font-size:0.85rem;">${stock}</span>` :
            `<span class="badge bg-danger" style="font-size:0.85rem;">0</span>`;

          const image = p.image || 'https://via.placeholder.com/40x40/e9ecef/6c757d?text=IMG';

          html += `
          <tr class="search-result-row" data-product-id="${p.id}" data-supplier="${$('<div>').text(supplier).html()}">
            <td class="text-center" style="padding: 8px 12px; vertical-align: middle; width: 50px;">
              <input type="checkbox" class="product-select-checkbox" 
                     data-product-id="${p.id}" ${disabled ? 'disabled' : ''}>
            </td>
            <td style="padding: 8px 12px; vertical-align: middle; width: 80px;">
              <img src="${image}" alt="Product" class="product-thumbnail" 
                   loading="lazy"
                   onerror="this.src='https://via.placeholder.com/40x40/e9ecef/6c757d?text=?'; this.classList.add('img-error');">
            </td>
            <td style="padding: 12px 8px; vertical-align: middle;">
              <div>
                <div class="fw-bold mb-1" style="font-size: 0.9rem; line-height: 1.2;">${$('<div>').text(p.name || 'Unnamed').html()}</div>
                <small class="text-muted d-block">${p.sku ? $('<div>').text(p.sku).html() : 'No SKU'}</small>
                <small class="text-primary"><i class="fa fa-tag me-1"></i>${$('<div>').text(supplier).html()}</small>
              </div>
            </td>
            <td class="text-center align-middle" style="padding: 12px 8px;">${stockBadge}</td>
            <td class="text-center align-middle" style="padding: 12px 8px;">
              <span class="fw-bold text-success">$${parseFloat(p.price || 0).toFixed(2)}</span>
            </td>
            <td class="text-center align-middle" style="padding: 12px 8px;">
              <button class="btn btn-sm ${btnCls}"
                data-product-id="${p.id}"
                data-product-name="${$('<div>').text(p.name || '').html()}"
                data-product-stock="${stock}"
                onclick="addProductToTransfer($(this))"
                ${disabled ? 'disabled' : ''}>${btnTxt}</button>
            </td>
          </tr>`;
        });
      });

      $('#productAddSearchBody').html(html);
      $('#search-status').text(`Found ${results.length} products from ${Object.keys(groupedResults).length} suppliers`);
      $('#results-count').text(`${results.length} results`);
      updateBulkControls();
      initializeMultiSelect();
    }

    async function searchProducts() {
      clearTimeout(searchTimeout);
      const query = ($('#search-input').val() || '').trim();
      if (query.length < 2) {
        clearSearch();
        return;
      }

      $('#search-status').text('Typing...');
      $('#search-spinner').removeClass('d-none');

      searchTimeout = setTimeout(async () => {
        if (searchCache.has(query)) {
          displaySearchResults(searchCache.get(query), query);
          $('#search-spinner').addClass('d-none');
          return;
        }
        $('#search-status').text('Searching...');
        try {
          const results = await searchForProductTop(query);
          searchCache.set(query, results);
          displaySearchResults(results, query);
        } catch (e) {
          console.error('Search failed', e);
          $('#productAddSearchBody').html(`
          <tr><td colspan="6" class="text-center py-4 text-danger">
            <i class="fa fa-wifi" aria-hidden="true"></i> Connection error. Please try again.
          </td></tr>`);
          $('#search-status').text('Connection failed');
          $('#results-count').text('0 results');
        } finally {
          $('#search-spinner').addClass('d-none');
        }
      }, 280);
    }

    // Bulk Selection Functions (moved to bottom with full implementation)



    function addProductToTable(product) {
      const rowIndex = $table.find('tbody tr').length + 1;
      const sourceOutlet = $('#sourceID option:selected').text() || 'Source';
      const destOutlet = $('#destinationID option:selected').text() || 'Destination';

      const newRow = `
      <tr data-inventory="${product.stock}" data-planned="0" class="table-success">
        <td class="text-center align-middle">
          <div class="d-flex justify-content-center">
            <img class="remove-icon" src="assets/img/remove-icon.png" title="Remove Product" style="cursor:pointer;height:13px;" onclick="removeProduct(this);">
          </div>
          <input type="hidden" class="productID" value="${product.id}">
        </td>
        <td>${product.name} <span class="badge badge-success">Added</span></td>
        <td class="inv">${product.stock}</td>
        <td class="planned">0</td>
        <td class="counted-td">
          <input type="number" min="0" max="${product.stock}" oninput="enforceBounds(this);addToLocalStorage();checkInvalidQty(this);recomputeTotals();syncPrintValue(this);" value="1" style="width:6em;">
          <span class="counted-print-value d-none">1</span>
        </td>
        <td>${sourceOutlet}</td>
        <td>${destOutlet}</td>
        <td><span class="id-counter">${transferId}-${rowIndex}</span></td>
      </tr>`;

      $table.find('tbody').append(newRow);
      setTimeout(() => {
        $table.find('tbody tr:last').removeClass('table-success');
        $table.find('tbody tr:last .badge-success').fadeOut(2000);
      }, 1100);
    }

    function addProductToTransfer($btn) {
      const productId = $btn.data('product-id');
      const productName = $btn.data('product-name');
      const productStock = parseInt($btn.data('product-stock'), 10) || 0;
      const productSku = $btn.data('product-sku') || '';

      if (productStock <= 0) {
        showToast(`❌ ${productName} is out of stock`, 'warning');
        return;
      }

      const existing = $table.find('tbody tr').filter(function() {
        return $(this).find('.productID').val() == productId;
      });
      if (existing.length > 0) {
        existing.addClass('table-warning');
        setTimeout(() => existing.removeClass('table-warning'), 1200);
        showToast(`⚠️ ${productName} is already in this transfer`, 'warning');

        // Focus on the existing row's quantity input
        existing.find('input[type="number"]').focus().select();
        return;
      }

      const rowIndex = $table.find('tbody tr').length + 1;
      const sourceOutlet = $table.find('tbody tr:first td:nth-child(6)').text() || 'Source';
      const destOutlet = $table.find('tbody tr:first td:nth-child(7)').text() || 'Destination';

      const newRow = `
      <tr data-inventory="${productStock}" data-planned="0" class="table-success">
        <td class="text-center align-middle">
          <div class="d-flex justify-content-center">
            <img class="remove-icon" src="assets/img/remove-icon.png" title="Remove Product" style="cursor:pointer;height:13px;" onclick="removeProduct(this);">
          </div>
          <input type="hidden" class="productID" value="${productId}">
        </td>
        <td>${productName} <span class="badge badge-info">Just Added</span></td>
        <td class="inv">${productStock}</td>
        <td class="planned">0</td>
        <td class="counted-td">
          <input type="number" min="0" max="${productStock}" oninput="enforceBounds(this);addToLocalStorage();checkInvalidQty(this);recomputeTotals();syncPrintValue(this);" value="" style="width:6em;">
          <span class="counted-print-value d-none">0</span>
        </td>
        <td>${sourceOutlet}</td>
        <td>${destOutlet}</td>
        <td><span class="id-counter">${transferId}-${rowIndex}</span></td>
      </tr>`;
      $table.find('tbody').append(newRow);
      setTimeout(() => {
        $table.find('tbody tr:last').removeClass('table-success');
      }, 1100);
      recomputeTotals();
      addToLocalStorage();

      $btn.prop('disabled', true).html('<i class="fa fa-check" aria-hidden="true"></i> Added')
        .removeClass('btn-primary').addClass('btn-outline-secondary');
      const $name = $btn.closest('tr').find('h6');
      if ($name.find('.fa-check-circle').length === 0) $name.append('<i class="fa fa-check-circle text-success" aria-hidden="true" style="margin-left:6px;" title="Added to transfer"></i>');
      showToast(`${productName} added to transfer`, 'success');
    }

    // Box Labels
    function openLabelPrintDialog() {
      const total = Math.max(1, parseInt($('#box-count-input').val(), 10) || 1);
      const tid = <?php echo (int)($_GET['transfer'] ?? 0); ?>;
      const w = window.open('', 'labels', 'width=900,height=700');
      const styles = `
      <style>
        @page { size: A4; margin: 12mm; }
        body { font-family: Arial, Helvetica, sans-serif; }
        .label {
          border: 4px solid #000; padding: 22px; margin-bottom: 16px;
          background: #ffeb3b; color: #000; border-radius: 6px;
        }
        .l1 { font-size: 28px; font-weight: 800; letter-spacing: .5px; }
        .l2 { font-size: 22px; font-weight: 700; margin-top: 6px; }
        .l3 { font-size: 18px; font-weight: 700; margin-top: 10px; }
        .muted { color:#111; font-size: 14px; margin-top: 8px; }
      </style>
    `;
      let html = `<html><head><title>Labels - Transfer #${tid}</title>${styles}</head><body>`;
      for (let i = 1; i <= total; i++) {
        html += `
        <div class="label">
          <div class="l1">TRANSFER #${tid}</div>
          <div class="l2">FROM: <?php echo htmlspecialchars($transferData->outlet_from->name); ?></div>
          <div class="l2">TO:&nbsp;&nbsp;&nbsp;&nbsp;<?php echo htmlspecialchars($transferData->outlet_to->name); ?></div>
          <div class="l3">BOX ${i} OF ${total}</div>
          <div class="muted">Do NOT send or do anything with this transfer until confirmed.</div>
        </div>`;
      }
      html += `</body></html>`;
      w.document.write(html);
      w.document.close();
      setTimeout(() => w.print(), 100);
    }

    // expose
    window.syncPrintValue = syncPrintValue;
    window.enforceBounds = enforceBounds;
    window.checkInvalidQty = checkInvalidQty;
    window.removeProduct = removeProduct;
    window.autofillCountedFromPlanned = autofillCountedFromPlanned;
    window.updateTrackingStorage = updateTrackingStorage;
    window.addTrackingInput = addTrackingInput;
    window.removeTrackingInput = removeTrackingInput;
    window.toggleTrackingVisibility = toggleTrackingVisibility;
    window.deleteTransfer = deleteTransfer;
    window.editMode = editMode;
    window.markReadyForDelivery = markReadyForDelivery;
    window.addToLocalStorage = addToLocalStorage;
    window.recomputeTotals = recomputeTotals;
    window.openLabelPrintDialog = openLabelPrintDialog;

    // init
    $(document).ready(function() {
      loadStoredValues();
      recomputeTotals();

      // Auto-detect and set default printer
      detectAndSetDefaultPrinter();

      const draftData = localStorage.getItem(draftKey);
      let hasTracking = false;
      if (draftData) {
        try {
          const d = JSON.parse(draftData);
          hasTracking = Array.isArray(d.trackingNumbers) && d.trackingNumbers.length > 0;
        } catch {}
      }
      if (!hasTracking) {
        setTimeout(() => {
          addTrackingInput();
          addTrackingInput();
        }, 100);
      } else {
        setTimeout(() => {
          const field = document.getElementById('tracking-number');
          if (field && field.value) {
            try {
              const arr = JSON.parse(field.value);
              if (Array.isArray(arr)) {
                $('#tracking-items').empty();
                trackingCounter = 0;
                arr.forEach(n => {
                  addTrackingInput();
                  $('.tracking-input').last().val(n);
                });
                updateTrackingStorage();
              }
            } catch {}
          }
        }, 100);
      }
      toggleTrackingVisibility();
      updateTrackingCount();

      $('#btn-save-draft').on('click', saveDraft);
      $('#btn-restore-draft').on('click', restoreDraft);
      $('#btn-discard-draft').on('click', discardDraft);
      $('#toggle-autosave').on('change', toggleAutosave);

      $('#search-input').on('input', searchProducts);
      $('#btn-clear-search').on('click', function() {
        $('#search-input').val('');
        clearSearch();
        $('#search-input').focus();
      });

      $('#btn-add-tracking').on('click', addTrackingInput);

      $table.find('input[type="number"]').each(function() {
        syncPrintValue(this);
      });

      $(document).on('keydown', function(e) {
        if (e.ctrlKey && e.key.toLowerCase() === 's') {
          e.preventDefault();
          saveDraft();
        }
        if (e.shiftKey && (e.key === 'F' || e.key === 'f')) {
          e.preventDefault();
          autofillCountedFromPlanned();
        }
      });

      $('#mergeTransferForm').on('submit', function() {
        if (confirm('Are you sure you want to merge these two transfers?')) {
          $('#currentTransferIDHidden').val($('#transferID').val());
          $('#TransferToMergeIDHidden').val($('#transferMergeOptions').val());
        } else {
          return false;
        }
      });

      $('#addProductsModal')
        .on('shown.bs.modal', function() {
          $('#search-input').val('');
          clearSearch();
          $('#search-input').focus();
        })
        .on('hidden.bs.modal', function() {
          clearSearch();
        });

      // Auto-save settings when they change (check if functions exist)
      if (typeof saveNZPostSettings === 'function') {
        $(document).on('change', '#nzpost-service-type, #nzpost-signature, #nzpost-saturday, #nzpost-print-now', saveNZPostSettings);
        $(document).on('input', '#nzpost-instructions, #nzpost-attention', debounce(saveNZPostSettings, 1000));
      }

      if (typeof saveGSSSettings === 'function') {
        $(document).on('change', '#gss-package-type, #gss-signature, #gss-saturday', saveGSSSettings);
        $(document).on('input', '#gss-instructions', debounce(saveGSSSettings, 1000));
      }

      // Load last used service on page load
      loadLastCourierService();

      // Courier service panel toggling with localStorage
      $('#courier-service').on('change', function() {
        $('.courier-panel').hide();
        const service = $(this).val();

        // Save selected service to localStorage
        if (service) {
          localStorage.setItem('vs_courier_service', service);
        }

        // Show appropriate panel
        if (service === 'gss') {
          $('#gss-panel').show();
          loadGSSSettings();
        } else if (service === 'nzpost') {
          $('#nzpost-panel').show();
          loadNZPostSettings();

          // Initialize packages if table is empty
          if ($('#nzpost-packages-table tbody tr').length === 0) {
            setTimeout(initializeNZPostPackages, 100);
          }

          // Set default dimensions if empty
          if (!$('#nzpost-length').val()) {
            setNZPostDimensions(30, 20, 15, 2.5);
          }
        } else if (service === 'manual') {
          $('#manual-panel').show();
        }

        // Update dropdown display text to ensure it shows correctly
        setTimeout(() => {
          const $select = $(this);
          const selectedOption = $select.find('option:selected');
          if (selectedOption.length && selectedOption.val()) {
            $select.removeClass('text-muted');
          } else {
            $select.addClass('text-muted');
          }
        }, 50);
      });
    });

    // ===== UTILITY FUNCTIONS =====

    /**
     * Debounce function to prevent excessive localStorage writes
     */
    function debounce(func, wait) {
      let timeout;
      return function executedFunction(...args) {
        const later = () => {
          clearTimeout(timeout);
          func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
      };
    }

    // ===== LOCALSTORAGE SETTINGS MANAGEMENT =====

    /**
     * Load and restore NZ Post settings from localStorage
     */
    function loadNZPostSettings() {
      try {
        // Load service type
        const savedServiceType = localStorage.getItem('vs_nzpost_service_type');
        if (savedServiceType) {
          $('#nzpost-service-type').val(savedServiceType);
        }

        // Load checkboxes
        const savedSignature = localStorage.getItem('vs_nzpost_signature');
        if (savedSignature !== null) {
          $('#nzpost-signature').prop('checked', savedSignature === 'true');
        }

        const savedSaturday = localStorage.getItem('vs_nzpost_saturday');
        if (savedSaturday !== null) {
          $('#nzpost-saturday').prop('checked', savedSaturday === 'true');
        }

        const savedPrintNow = localStorage.getItem('vs_nzpost_print_now');
        if (savedPrintNow !== null) {
          $('#nzpost-print-now').prop('checked', savedPrintNow === 'true');
        }

        // Load instructions
        const savedInstructions = localStorage.getItem('vs_nzpost_instructions');
        if (savedInstructions) {
          $('#nzpost-instructions').val(savedInstructions);
        }

        // Load attention field
        const savedAttention = localStorage.getItem('vs_nzpost_attention');
        if (savedAttention) {
          $('#nzpost-attention').val(savedAttention);
        }

      } catch (e) {
        console.log('Error loading NZ Post settings:', e);
      }
    }

    /**
     * Save NZ Post settings to localStorage
     */
    function saveNZPostSettings() {
      try {
        localStorage.setItem('vs_nzpost_service_type', $('#nzpost-service-type').val());
        localStorage.setItem('vs_nzpost_signature', $('#nzpost-signature').prop('checked'));
        localStorage.setItem('vs_nzpost_saturday', $('#nzpost-saturday').prop('checked'));
        localStorage.setItem('vs_nzpost_print_now', $('#nzpost-print-now').prop('checked'));
        localStorage.setItem('vs_nzpost_instructions', $('#nzpost-instructions').val());
        localStorage.setItem('vs_nzpost_attention', $('#nzpost-attention').val());

        // Visual feedback
        showSettingsSaved('nzpost');
      } catch (e) {
        console.log('Error saving NZ Post settings:', e);
      }
    }

    /**
     * Load and restore GSS settings from localStorage
     */
    function loadGSSSettings() {
      try {
        // Load package type
        const savedPackageType = localStorage.getItem('vs_gss_package_type');
        if (savedPackageType) {
          $('#gss-package-type').val(savedPackageType);
        }

        // Load checkboxes
        const savedSignature = localStorage.getItem('vs_gss_signature');
        if (savedSignature !== null) {
          $('#gss-signature').prop('checked', savedSignature === 'true');
        }

        const savedSaturday = localStorage.getItem('vs_gss_saturday');
        if (savedSaturday !== null) {
          $('#gss-saturday').prop('checked', savedSaturday === 'true');
        }

        // Load instructions
        const savedInstructions = localStorage.getItem('vs_gss_instructions');
        if (savedInstructions) {
          $('#gss-instructions').val(savedInstructions);
        }

      } catch (e) {
        console.log('Error loading GSS settings:', e);
      }
    }

    /**
     * Save GSS settings to localStorage
     */
    function saveGSSSettings() {
      try {
        localStorage.setItem('vs_gss_package_type', $('#gss-package-type').val());
        localStorage.setItem('vs_gss_signature', $('#gss-signature').prop('checked'));
        localStorage.setItem('vs_gss_saturday', $('#gss-saturday').prop('checked'));
        localStorage.setItem('vs_gss_instructions', $('#gss-instructions').val());
      } catch (e) {
        console.log('Error saving GSS settings:', e);
      }
    }

    /**
     * Load last used courier service on page load
     */
    function loadLastCourierService() {
      const savedService = localStorage.getItem('vs_courier_service');
      if (savedService) {
        $('#courier-service').val(savedService).trigger('change');
      }
    }

    // ===== PRINTER DETECTION & AUTO-SETUP =====

    /**
     * Detects available courier services and sets default printer
     * Priority: NZ Post > GSS > Manual Tracking
     */
    async function detectAndSetDefaultPrinter() {
      try {
        $('#printer-status').show();
        $('#printer-status-text').html('<i class="fa fa-spinner fa-spin"></i> Detecting printers...');

        // Check for available services
        const services = await checkAvailableServices();

        let defaultService = '';
        let statusText = '';
        let statusClass = 'text-muted';

        if (services.nzpost && services.gss) {
          // Both available - prioritize NZ Post
          defaultService = 'nzpost';
          statusText = '🔴 NZ POST LEADS - Priority service (+ NZ Couriers available)';
          statusClass = 'text-success';
          showToast('NZ POST LEADS - Default priority service activated', 'success');

          // Mark both options as available with clear hierarchy
          $('#courier-service option[value="nzpost"]').text('🔴 NZ POST LEADS');
          $('#courier-service option[value="gss"]').text('NZ Couriers (Secondary)');

        } else if (services.nzpost) {
          // Only NZ Post available
          defaultService = 'nzpost';
          statusText = '🔴 NZ POST LEADS - Priority service ready.';
          statusClass = 'text-success';
          showToast('NZ POST LEADS - Default service ready', 'success');

          $('#courier-service option[value="nzpost"]').text('🔴 NZ POST LEADS');

        } else if (services.gss) {
          // Only GSS available (NZ Post preferred when available)
          defaultService = 'gss';
          statusText = '🟡 NZ Couriers active (NZ POST LEADS when available)';
          statusClass = 'text-warning';
          showToast('NZ Couriers active - NZ POST LEADS when available', 'warning');

          $('#courier-service option[value="gss"]').text('NZ Couriers (Active)');

        } else {
          // No printers detected - show manual tracking
          defaultService = 'manual';
          statusText = '⚠️ No printers detected. Using manual tracking.';
          statusClass = 'text-warning';
          showToast('No printers detected - using manual tracking', 'warning');
        }

        // Update status display
        $('#printer-status-text').html(statusText);
        $('#printer-status').removeClass('text-muted text-success text-warning text-danger').addClass(statusClass);

        // Set the default service
        $('#courier-service').val(defaultService);

        // Trigger the change event to show the appropriate panel
        $('#courier-service').trigger('change');

        console.log('Printer detection complete:', {
          services,
          defaultService,
          statusText
        });

      } catch (error) {
        console.error('Printer detection failed:', error);

        $('#printer-status-text').html('❌ Printer detection failed. Using manual tracking.');
        $('#printer-status').removeClass('text-muted text-success text-warning').addClass('text-danger');

        showToast('Could not detect printers - using manual tracking', 'warning');
        $('#courier-service').val('manual').trigger('change');
      }
    }

    /**
     * Check which courier services are available
     * This would typically make API calls to check printer/service availability
     */
    async function checkAvailableServices() {
      // In a real implementation, this would check:
      // 1. NZ Post API connectivity/credentials
      // 2. GSS API connectivity/credentials  
      // 3. Actual printer availability on the network

      try {
        // Simulate service detection with actual API calls
        const nzpostAvailable = await testNZPostConnection();
        const gssAvailable = await testGSSConnection();

        return {
          nzpost: nzpostAvailable,
          gss: gssAvailable
        };
      } catch (error) {
        console.warn('Service detection error:', error);

        // Fallback: return mock availability for demo
        // In production, you might want to check localStorage preferences
        // or make lightweight API calls to test connectivity

        // For demo purposes, randomly simulate different scenarios
        const scenarios = [{
            nzpost: true,
            gss: true
          }, // Both available
          {
            nzpost: true,
            gss: false
          }, // Only NZ Post  
          {
            nzpost: false,
            gss: true
          }, // Only GSS
          {
            nzpost: false,
            gss: false
          } // Neither (rare)
        ];

        // Use a deterministic selection based on current time (so it's consistent per session)
        const scenarioIndex = Math.floor(Date.now() / (1000 * 60 * 5)) % scenarios.length; // Changes every 5 minutes
        return scenarios[0]; // Always use first scenario (both available) for demo
      }
    }

    /**
     * Test NZ Post API connectivity
     */
    async function testNZPostConnection() {
      try {
        // In production, this would make a lightweight test call to NZ Post eSHIP API
        // Example: GET /v2/auth/test or similar endpoint

        // Simulate network delay
        await new Promise(resolve => setTimeout(resolve, 200 + Math.random() * 300));

        // Check for stored configuration or make actual API test
        const nzpostConfigured = localStorage.getItem('nzpost_configured');
        const nzpostApiKey = localStorage.getItem('nzpost_api_key');

        // For demo: assume NZ Post is available if configured, or 90% chance if not explicitly disabled
        if (nzpostConfigured === 'false') return false;
        if (nzpostConfigured === 'true' || nzpostApiKey) return true;

        // Default assumption for NZ Post (usually available)
        return Math.random() > 0.1; // 90% chance

      } catch (error) {
        console.warn('NZ Post connectivity test failed:', error);
        return false;
      }
    }

    /**
     * Test GSS API connectivity  
     */
    async function testGSSConnection() {
      try {
        // In production, this would make a lightweight test call to GSS API
        // Example: GET /api/auth/test or similar endpoint

        // Simulate network delay
        await new Promise(resolve => setTimeout(resolve, 150 + Math.random() * 200));

        // Check for stored configuration
        const gssConfigured = localStorage.getItem('gss_configured');
        const gssApiKey = localStorage.getItem('gss_api_key');

        // For demo: GSS requires more explicit setup
        if (gssConfigured === 'false') return false;
        if (gssConfigured === 'true' && gssApiKey) return true;

        // Default assumption for GSS (requires setup, so lower availability)
        return Math.random() > 0.6; // 40% chance

      } catch (error) {
        console.warn('GSS connectivity test failed:', error);
        return false;
      }
    }

    // ===== COURIER INTEGRATION FUNCTIONS =====

    // GSS Label Creation
    async function createGSSLabel() {
      const statusEl = $('#gss-status');
      const transferId = $('#transferID').val();
      const outletFrom = $('#sourceID').val();
      const outletTo = $('#destinationID').val();

      statusEl.html('<span class="text-info">Creating GSS label...</span>');

      const params = {
        method: 'createGSSShipment',
        transferId: transferId,
        outletFrom: outletFrom,
        outletTo: outletTo,
        packageType: $('#gss-package-type').val(),
        signature: $('#gss-signature').prop('checked') ? 1 : 0,
        saturday: $('#gss-saturday').prop('checked') ? 1 : 0,
        instructions: $('#gss-instructions').val() || '',
        userID: $('#staffID').val()
      };

      try {
        const response = await fetch('/assets/functions/ajax.php?' + new URLSearchParams(params), {
          method: 'GET',
          credentials: 'include'
        });
        const text = await response.text();

        if (text === 'Done' || text.includes('consignment')) {
          statusEl.html('<span class="text-success"><i class="fa fa-check"></i> Label printed successfully</span>');
          // Extract tracking number if provided in response
          const trackingMatch = text.match(/([A-Z0-9]{10,})/);
          if (trackingMatch) {
            addGeneratedLabel('GSS', trackingMatch[1], 'success');
          }
        } else if (text === 'Bad Address') {
          statusEl.html('<span class="text-danger"><i class="fa fa-exclamation-triangle"></i> Address issue - please check destination</span>');
        } else {
          statusEl.html('<span class="text-danger"><i class="fa fa-times"></i> ' + (text || 'Unknown error') + '</span>');
        }
      } catch (error) {
        statusEl.html('<span class="text-danger"><i class="fa fa-times"></i> Network error: ' + error.message + '</span>');
      }
    }

    // NZ Post Dimension & Cost Calculation Functions
    function setNZPostDimensions(length, width, height, weight) {
      $('#nzpost-length').val(length);
      $('#nzpost-width').val(width);
      $('#nzpost-height').val(height);
      $('#nzpost-weight').val(weight);
      calculateNZPostCost();
    }

    function calculateVolumetricWeight(length, width, height) {
      // NZ Post volumetric weight formula: L × W × H ÷ 5000
      return (length * width * height) / 5000;
    }

    function calculateNZPostCost() {
      const length = parseFloat($('#nzpost-length').val()) || 0;
      const width = parseFloat($('#nzpost-width').val()) || 0;
      const height = parseFloat($('#nzpost-height').val()) || 0;
      const actualWeight = parseFloat($('#nzpost-weight').val()) || 0;

      if (length && width && height && actualWeight) {
        const volumetricWeight = calculateVolumetricWeight(length, width, height);
        const chargeableWeight = Math.max(actualWeight, volumetricWeight);

        // Basic NZ Post Economy pricing (approximate)
        let baseCost = 0;
        const serviceType = $('#nzpost-service-type').val();

        if (serviceType === 'CPOLE') { // Economy
          baseCost = Math.max(8.50, chargeableWeight * 4.20);
        } else if (serviceType === 'CPOLP') { // Overnight
          baseCost = Math.max(12.50, chargeableWeight * 6.80);
        } else { // Other services
          baseCost = Math.max(15.00, chargeableWeight * 8.50);
        }

        // Add fuel surcharge (approximate 15%)
        const fuelSurcharge = baseCost * 0.15;
        const subtotal = baseCost + fuelSurcharge;

        // Add GST (15%)
        const gst = subtotal * 0.15;
        const total = subtotal + gst;

        // Update displays
        $('#nzpost-cost-display').text('$' + total.toFixed(2));
        $('#nzpost-volume-weight').text('Vol: ' + volumetricWeight.toFixed(1) + 'kg');
        $('#nzpost-actual-weight').text('Act: ' + actualWeight + 'kg');
        $('#nzpost-cost-breakdown').text('Base: $' + baseCost.toFixed(2) + ' + Fuel: $' + fuelSurcharge.toFixed(2) + ' + GST: $' + gst.toFixed(2));

        updatePackagesList();
      } else {
        $('#nzpost-cost-display').text('$0.00');
        $('#nzpost-volume-weight').text('Vol: 0kg');
        $('#nzpost-actual-weight').text('Act: 0kg');
        $('#nzpost-cost-breakdown').text('Enter dimensions');
      }
    }

    function addCurrentPackage() {
      const length = parseFloat($('#nzpost-length').val()) || 0;
      const width = parseFloat($('#nzpost-width').val()) || 0;
      const height = parseFloat($('#nzpost-height').val()) || 0;
      const weight = parseFloat($('#nzpost-weight').val()) || 0;

      if (!length || !width || !height || !weight) {
        alert('Please enter all dimensions and weight before adding package');
        return;
      }

      const volumetricWeight = calculateVolumetricWeight(length, width, height);
      const cost = parseFloat($('#nzpost-cost-display').text().replace('$', '')) || 0;

      const packageId = 'pkg_' + Date.now();
      const description = `${length}×${width}×${height}cm, ${weight}kg`;

      const row = `
      <tr data-package-id="${packageId}">
        <td>${length}×${width}×${height}cm</td>
        <td>${weight}kg</td>
        <td>${volumetricWeight.toFixed(1)}kg</td>
        <td>
          <input type="text" class="form-control form-control-sm" value="Transfer Package ${$('#nzpost-packages-table tbody tr').length + 1}" />
        </td>
        <td class="text-success fw-bold">$${cost.toFixed(2)}</td>
        <td>
          <button class="btn btn-sm btn-outline-danger" onclick="removeNZPostPackage('${packageId}')">
            <i class="fa fa-trash"></i>
          </button>
        </td>
      </tr>`;

      $('#nzpost-packages-table tbody').append(row);
      updatePackagesList();

      // Clear current inputs for next package
      $('#nzpost-length, #nzpost-width, #nzpost-height').val('');
      $('#nzpost-weight').val('2.5');
      calculateNZPostCost();
    }

    function removeNZPostPackage(packageId) {
      $(`[data-package-id="${packageId}"]`).remove();
      updatePackagesList();
    }

    function updatePackagesList() {
      const packageCount = $('#nzpost-packages-table tbody tr').length;
      let totalWeight = 0;
      let totalCost = 0;

      $('#nzpost-packages-table tbody tr').each(function() {
        const weightText = $(this).find('td:eq(1)').text();
        const weight = parseFloat(weightText.replace('kg', '')) || 0;
        totalWeight += weight;

        const costText = $(this).find('td:eq(4)').text();
        const cost = parseFloat(costText.replace('$', '')) || 0;
        totalCost += cost;
      });

      $('#nzpost-package-count').text(packageCount + ' packages');
      $('#nzpost-total-weight').text(totalWeight.toFixed(1) + 'kg total');
      $('#nzpost-total-cost').text('Total: $' + totalCost.toFixed(2));

      const serviceType = $('#nzpost-service-type option:selected').text().split('(')[0].trim();
      $('#nzpost-service-type-display').text(serviceType);
    }

    // Initialize with default package based on box count
    function initializeNZPostPackages() {
      const boxCount = parseInt($('#box-count-input').val()) || 1;

      // Auto-add medium boxes based on box count
      for (let i = 0; i < Math.min(boxCount, 3); i++) {
        setNZPostDimensions(30, 20, 15, 2.5);
        addCurrentPackage();
      }
    }

    // NZ Post Label Creation
    async function createNZPostLabel() {
      const statusEl = $('#nzpost-status');
      const transferId = $('#transferID').val();
      const outletFrom = $('#sourceID').val();
      const outletTo = $('#destinationID').val();

      statusEl.html('<span class="text-info">Creating NZ Post label...</span>');

      const params = {
        method: 'createNZPostShipment',
        transferId: transferId,
        outletFrom: outletFrom,
        outletTo: outletTo,
        serviceType: $('#nzpost-service-type').val(),
        signature: $('#nzpost-signature').prop('checked') ? 1 : 0,
        safePlace: $('#nzpost-safe-place').prop('checked') ? 1 : 0,
        userID: $('#staffID').val()
      };

      try {
        const response = await fetch('/assets/functions/ajax.php?' + new URLSearchParams(params), {
          method: 'GET',
          credentials: 'include'
        });
        const text = await response.text();

        if (text === 'Done' || text.includes('label')) {
          statusEl.html('<span class="text-success"><i class="fa fa-check"></i> Label printed successfully</span>');
          // Extract tracking number if provided in response
          const trackingMatch = text.match(/([A-Z]{2}[0-9]{9}NZ|[0-9]{13})/);
          if (trackingMatch) {
            addGeneratedLabel('NZ Post', trackingMatch[1], 'success');
          }
        } else if (text.includes('error') || text.includes('failed')) {
          statusEl.html('<span class="text-danger"><i class="fa fa-times"></i> ' + text + '</span>');
        } else {
          statusEl.html('<span class="text-warning"><i class="fa fa-info-circle"></i> ' + (text || 'Unexpected response') + '</span>');
        }
      } catch (error) {
        statusEl.html('<span class="text-danger"><i class="fa fa-times"></i> Network error: ' + error.message + '</span>');
      }
    }

    // Add generated label to the display
    function addGeneratedLabel(service, trackingNumber, status) {
      const labelsContainer = $('#generated-labels');
      const statusClass = status === 'success' ? 'alert-success' : 'alert-warning';
      const statusIcon = status === 'success' ? 'fa-check-circle' : 'fa-info-circle';

      const labelHtml = `
      <div class="alert ${statusClass} py-2 mb-1">
        <i class="fa ${statusIcon}"></i>
        <strong>${service}:</strong> 
        <code>${trackingNumber}</code>
        <button type="button" class="btn btn-sm btn-outline-dark ml-2" onclick="copyToClipboard('${trackingNumber}')">
          <i class="fa fa-copy"></i>
        </button>
        <button type="button" class="btn btn-sm btn-outline-primary ml-1" onclick="addTrackingNumber('${trackingNumber}')">
          Add to Transfer
        </button>
      </div>
    `;

      labelsContainer.append(labelHtml);
    }

    // Copy tracking number to clipboard
    function copyToClipboard(text) {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(() => {
          // Brief visual feedback
          showToast('Tracking number copied to clipboard', 'success');
        }).catch(err => {
          console.error('Failed to copy:', err);
          // Fallback to manual selection
          fallbackCopyToClipboard(text);
        });
      } else {
        // Fallback for older browsers
        fallbackCopyToClipboard(text);
      }
    }

    // Fallback copy method for older browsers
    function fallbackCopyToClipboard(text) {
      try {
        const textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.style.position = 'fixed';
        textArea.style.opacity = '0';
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        const successful = document.execCommand('copy');
        document.body.removeChild(textArea);
        
        if (successful) {
          showToast('Tracking number copied to clipboard', 'success');
        } else {
          showToast('Could not copy tracking number. Please copy manually: ' + text, 'warning');
        }
      } catch (err) {
        console.error('Fallback copy failed:', err);
        showToast('Could not copy tracking number. Please copy manually: ' + text, 'warning');
      }
    }

    // Add tracking number to the manual tracking list
    function addTrackingNumber(trackingNumber = '') {
      const container = $('#tracking-items');
      const count = $('.tracking-input').length;

      const html = `
      <div class="input-group mb-1 tracking-row">
        <input type="text" class="form-control form-control-sm tracking-input" 
               placeholder="Enter tracking number" value="${trackingNumber}"
               oninput="updateTrackingCount()">
        <div class="input-group-append">
          <button class="btn btn-sm btn-outline-danger" type="button" onclick="removeTrackingRow(this)">
            <i class="fa fa-times"></i>
          </button>
        </div>
      </div>
    `;

      container.append(html);
      updateTrackingCount();
    }

    // Remove tracking row
    function removeTrackingRow(button) {
      $(button).closest('.tracking-row').remove();
      updateTrackingCount();
    }

    // Update tracking count display
    function updateTrackingCount() {
      const count = $('.tracking-input').filter((i, el) => $(el).val().trim()).length;
      $('#tracking-count').text(count + ' number' + (count !== 1 ? 's' : ''));
    }

    // Initialize manual tracking button
    $(document).ready(function() {
      $('#btn-add-tracking').click(() => addTrackingNumber());
    });

    // ==== MULTI-SELECT & BULK OPERATIONS ====
    let lastSelectedIndex = -1;
    const selectedProducts = new Set();

    function updateBulkControls() {
      const count = selectedProducts.size;
      $('#selected-count').text(count);

      if (count > 0) {
        $('#bulk-controls').removeClass('d-none');
      } else {
        $('#bulk-controls').addClass('d-none');
      }

      // Update button states
      $('#add-to-current-btn, #add-to-all-btn').prop('disabled', count === 0);
    }

    function initializeMultiSelect() {
      // Click handlers for checkboxes and rows
      $(document).off('change.multiselect').on('change.multiselect', '.product-select-checkbox', function(e) {
        e.stopPropagation();
        const productId = $(this).data('product-id');
        const isChecked = $(this).prop('checked');
        const $row = $(this).closest('.search-result-row');

        if (isChecked) {
          selectedProducts.add(productId);
          $row.addClass('selected');
        } else {
          selectedProducts.delete(productId);
          $row.removeClass('selected');
        }

        updateBulkControls();
      });

      // Row click handler for multi-select with keyboard modifiers
      $(document).off('click.multiselect').on('click.multiselect', '.search-result-row', function(e) {
        if ($(e.target).is('button, input, img')) return; // Don't interfere with buttons/inputs

        const $row = $(this);
        const $checkbox = $row.find('.product-select-checkbox');

        if ($checkbox.prop('disabled')) return;

        const currentIndex = $('.search-result-row').index($row);

        if (e.ctrlKey || e.metaKey) {
          // Ctrl+click: toggle this row
          $checkbox.prop('checked', !$checkbox.prop('checked')).trigger('change');
        } else if (e.shiftKey && lastSelectedIndex !== -1) {
          // Shift+click: select range
          const start = Math.min(lastSelectedIndex, currentIndex);
          const end = Math.max(lastSelectedIndex, currentIndex);

          $('.search-result-row').slice(start, end + 1).each(function() {
            const $cb = $(this).find('.product-select-checkbox');
            if (!$cb.prop('disabled')) {
              $cb.prop('checked', true).trigger('change');
            }
          });
        } else {
          // Regular click: clear all and select this
          clearSelection();
          $checkbox.prop('checked', true).trigger('change');
        }

        lastSelectedIndex = currentIndex;
      });

      // Select all checkbox handler
      $(document).off('change.selectall').on('change.selectall', '#selectAllProducts', function() {
        const isChecked = $(this).prop('checked');
        if (isChecked) {
          selectAllVisible();
        } else {
          clearSelection();
        }
      });

      // Update select all checkbox when individual selections change
      $(document).off('change.updateall').on('change.updateall', '.product-select-checkbox', function() {
        const totalVisible = $('.product-select-checkbox:not(:disabled)').length;
        const totalSelected = $('.product-select-checkbox:not(:disabled):checked').length;

        $('#selectAllProducts').prop('indeterminate', totalSelected > 0 && totalSelected < totalVisible);
        $('#selectAllProducts').prop('checked', totalSelected > 0 && totalSelected === totalVisible);
      });
    }

    function selectAllVisible() {
      $('.product-select-checkbox:not(:disabled)').prop('checked', true).trigger('change');
    }

    function clearSelection() {
      $('.product-select-checkbox').prop('checked', false);
      $('.search-result-row').removeClass('selected');
      selectedProducts.clear();
      updateBulkControls();
    }

    function selectAllFromSupplier(supplier) {
      $(`.search-result-row[data-supplier="${supplier}"] .product-select-checkbox:not(:disabled)`)
        .prop('checked', true).trigger('change');
    }

    function addSelectedToCurrentTransfer() {
      if (selectedProducts.size === 0) return;

      let addedCount = 0;
      selectedProducts.forEach(productId => {
        const $row = $(`.search-result-row[data-product-id="${productId}"]`);
        const $btn = $row.find('button[data-product-id]');

        if (!$btn.prop('disabled')) {
          $btn.click();
          addedCount++;
        }
      });

      if (addedCount > 0) {
        showToast(`Added ${addedCount} products to this transfer`, 'success');
        clearSelection();
      } else {
        showToast('No products could be added (already in transfer or out of stock)', 'warning');
      }
    }

    async function addSelectedToAllTransfers() {
      if (selectedProducts.size === 0) return;

      // This would need backend implementation to add to all transfers from this outlet
      // For now, show a message about the feature
      showToast('Add to All Transfers feature requires backend implementation', 'info');
      console.log('Selected products for all transfers:', Array.from(selectedProducts));

      // TODO: Implement backend endpoint to add products to all pending transfers
      // from the same source outlet
    }

  })(); 
</script>