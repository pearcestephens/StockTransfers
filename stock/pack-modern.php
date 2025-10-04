<?php
declare(strict_types=1);

/**
 * CIS — Transfers » Stock » Pack (Modern Interface)
 * 
 * Enhanced packing interface with smart features
 * @version 2.0
 * @date 2025-10-03
 */

include($_SERVER['DOCUMENT_ROOT'] . "/assets/functions/config.php");

@date_default_timezone_set('Pacific/Auckland');

// Load module dependencies
require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/transfers/_local_shims.php';
if (is_file($_SERVER['DOCUMENT_ROOT'] . '/modules/transfers/_shared/Autoload.php')) {
  require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/transfers/_shared/Autoload.php';
}

use Modules\Transfers\Stock\Services\TransfersService;
use Modules\Transfers\Stock\Services\StaffNameResolver;


// --------------------------------------------------------------------------------------
// Helper Functions
// --------------------------------------------------------------------------------------

function _int($val, int $fallback = 0): int {
  if (is_numeric($val)) return (int)$val;
  $v = filter_var($val, FILTER_VALIDATE_INT);
  return is_int($v) ? $v : $fallback;
}

function _first(...$vals) {
  foreach ($vals as $v) {
    if (is_string($v) && $v !== '') return $v;
    if (is_numeric($v)) return $v;
    if (is_array($v) && !empty($v)) return $v;
  }
  return null;
}

function format_outlet_display(?array $outlet, string $fallback = 'Unknown'): string {
  if (!$outlet) return $fallback;
  $name = (string)($outlet['name'] ?? $fallback);
  $parts = [$name];
  if (!empty($outlet['physical_city'])) {
    $parts[] = $outlet['physical_city'];
  }
  return implode(' - ', $parts);
}

function compute_pack_metrics(array $items): array {
  $planned = 0;
  $counted = 0;
  foreach ($items as $it) {
    $planned += (int)(_first($it['qty_requested'] ?? null, $it['planned_qty'] ?? 0) ?: 0);
    $counted += (int)($it['counted_qty'] ?? 0);
  }
  $diff = $counted - $planned;
  $accuracy = $planned > 0 ? round(($counted / $planned) * 100, 1) : 100.0;
  return [
    'planned' => $planned,
    'counted' => $counted,
    'diff' => $diff,
    'accuracy' => $accuracy,
    'diff_label' => $diff > 0 ? '+' . $diff : (string)$diff
  ];
}

// --------------------------------------------------------------------------------------
// Auth & Guards
// --------------------------------------------------------------------------------------

if (!isset($_SESSION['userID']) || (int)$_SESSION['userID'] <= 0) {
  http_response_code(401);
  die("Unauthorized. Please log in.");
}

$currentUserId = (int)$_SESSION['userID'];
$currentUserName = $_SESSION['name'] ?? 'User';

// Get transfer ID
$txId = _int($_GET['transfer'] ?? $_GET['id'] ?? 0);
if ($txId <= 0) {
  http_response_code(400);
  die("Missing or invalid transfer ID.");
}

// --------------------------------------------------------------------------------------
// Data Loading
// --------------------------------------------------------------------------------------

$transfer = [];
$items = [];
$fromOutlet = null;
$toOutlet = null;
$fromOutletId = 0;
$toOutletId = 0;
$fromUuid = '';
$toUuid = '';
$sourceStockMap = [];
$transferStatus = 0;
$isPackaged = false;
$createdByName = 'Unknown';
$metrics = ['planned' => 0, 'counted' => 0, 'diff' => 0, 'accuracy' => 100, 'diff_label' => '0'];

try {
  $svc = new TransfersService();
  
  // Load transfer with items
  $transfer = $svc->getTransfer($txId);
  if (!$transfer) {
    throw new RuntimeException("Transfer #{$txId} not found.");
  }
  
  $items = $transfer['items'] ?? [];
  $fromOutletId = (int)($transfer['outlet_from'] ?? 0);
  $toOutletId = (int)($transfer['outlet_to'] ?? 0);
  $fromUuid = $transfer['outlet_from_uuid'] ?? '';
  $toUuid = $transfer['outlet_to_uuid'] ?? '';
  
  if ($fromUuid) {
    $fromOutlet = $svc->getOutletMeta($fromUuid);
  }
  if ($toUuid) {
    $toOutlet = $svc->getOutletMeta($toUuid);
  }
  
  if ($items && $fromUuid) {
    $productIds = array_column($items, 'product_id');
    $sourceStockMap = $svc->getSourceStockLevels($productIds, $fromUuid);
  }
  
  foreach ($items as &$item) {
    $productId = $item['product_id'] ?? null;
    $item['source_stock'] = $sourceStockMap[$productId] ?? 0;
    $item['planned_qty'] = $item['qty_requested'] ?? 0;
    $item['counted_qty'] = $item['qty_sent_total'] ?? 0;
  }
  unset($item);
  
  $metrics = compute_pack_metrics($items);
  $transferStatus = (int)($transfer['status'] ?? 0);
  $isPackaged = $transferStatus >= 1;
  
  $staffResolver = new StaffNameResolver();
  if (!empty($transfer['created_by'])) {
    $createdByName = $staffResolver->resolve((int)$transfer['created_by']);
  }
  
} catch (Throwable $e) {
  error_log("[pack-modern.php] Error: " . $e->getMessage());
  http_response_code(500);
  die("Error loading transfer: " . htmlspecialchars($e->getMessage()));
}

// --------------------------------------------------------------------------------------
// View Variables
// --------------------------------------------------------------------------------------

$assetVer = time();
$fromLbl = format_outlet_display($fromOutlet, $transfer['outlet_from_name'] ?? 'Source');
$toLbl = format_outlet_display($toOutlet, $transfer['outlet_to_name'] ?? 'Destination');
$fromDisplay = $fromOutlet['name'] ?? $transfer['outlet_from_name'] ?? 'Source Outlet';
$toDisplay = $toOutlet['name'] ?? $transfer['outlet_to_name'] ?? 'Destination Outlet';
$txStringId = str_pad((string)$txId, 6, '0', STR_PAD_LEFT);
$PACKONLY = $isPackaged;

$bootPayload = [
  'transfer_id' => $txId,
  'transfer_string_id' => $txStringId,
  'status' => $transferStatus,
  'is_packaged' => $isPackaged,
  'from_outlet' => ['id' => $fromOutletId, 'name' => $fromDisplay, 'uuid' => $fromUuid],
  'to_outlet' => ['id' => $toOutletId, 'name' => $toDisplay, 'uuid' => $toUuid],
  'metrics' => $metrics,
  'user' => ['id' => $currentUserId, 'name' => $currentUserName],
  'created_by' => $createdByName,
  'created_at' => $transfer['created_at'] ?? null,
  'api_endpoints' => [
    'save' => '/modules/transfers/stock/api/pack_save.php',
    'send' => '/modules/transfers/stock/api/pack_send.php',
    'product_search' => '/modules/transfers/stock/api/product_search.php',
    'delete_transfer' => '/modules/transfers/stock/api/delete_transfer.php',
  ],
];

// ######### CSS BEGINS HERE #########
?>
<style>
  .bg-gradient-primary {
    background: linear-gradient(135deg, #4e73df 0%, #224abe 100%) !important;
  }
  .pack-qty-input { max-width: 80px; font-weight: 600; }
  .pack-qty-input.is-invalid { border-color: #dc3545; background-color: #ffe5e5; }
  .pack-qty-input.is-warning { border-color: #ffc107; background-color: #fff3cd; }
  #toast-container { position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px; }
  .toast { box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
</style>
<?php
// ######### CSS ENDS HERE #########

// ######### HEADER BEGINS HERE #########
include($_SERVER['DOCUMENT_ROOT'] . "/assets/template/html-header.php");
include($_SERVER['DOCUMENT_ROOT'] . "/assets/template/header.php");
// ######### HEADER ENDS HERE #########
?>

<body class="app header-fixed sidebar-fixed aside-menu-fixed sidebar-lg-show">
  <div class="app-body">
    <?php include($_SERVER['DOCUMENT_ROOT'] . "/assets/template/sidemenu.php"); ?>
    <main class="main">
      <!-- Breadcrumb -->
      <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="/index.php">Home</a></li>
        <li class="breadcrumb-item"><a href="/modules/transfers/stock/list.php">Transfers</a></li>
        <li class="breadcrumb-item active">Pack #<?= (int)$txId ?></li>
        <li class="breadcrumb-menu d-md-down-none">
          <?php include($_SERVER['DOCUMENT_ROOT'] . '/assets/template/quick-product-search.php'); ?>
        </li>
      </ol>
      
      <div class="container-fluid">
        <div class="animated fadeIn">
          
          <?php if ($PACKONLY): ?>
          <div class="alert alert-warning alert-dismissible fade show">
            <h5 class="alert-heading"><i class="fas fa-exclamation-triangle mr-2"></i>Pack-Only Mode</h5>
            <p class="mb-0">This transfer has already been sent. View-only mode.</p>
            <button type="button" class="close" data-dismiss="alert">&times;</button>
          </div>
          <?php endif; ?>
          
          <div class="row">
            <div class="col">
              <div class="card">
                <div class="card-header bg-gradient-primary text-white">
                  <h4 class="card-title mb-0">
                    <i class="fas fa-exchange-alt mr-2"></i>Store Transfer #<?= (int)$txId ?>
                    <span class="badge badge-light ml-2"><?= $isPackaged ? 'SENT' : 'ACTIVE' ?></span>
                  </h4>
                  <div class="small text-light">
                    Created by <?= htmlspecialchars($createdByName, ENT_QUOTES) ?>
                    <?php if (!empty($transfer['created_at'])): ?>
                      on <?= date('M j, Y g:i A', strtotime($transfer['created_at'])) ?>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="card-body">
                  <div class="cis-content">
                    
                    <!-- FROM/TO Outlets -->
                    <div class="row mb-4">
                      <div class="col-md-6">
                        <div class="media">
                          <div class="mr-3 text-center" style="width:60px;">
                            <i class="fas fa-store fa-2x text-primary"></i>
                          </div>
                          <div class="media-body">
                            <h6 class="text-uppercase text-muted" style="font-size:0.75rem;">From</h6>
                            <p class="h5 mb-0"><?= htmlspecialchars($fromDisplay, ENT_QUOTES) ?></p>
                            <?php if (!empty($fromOutlet['physical_address_1'])): ?>
                              <small class="text-muted">
                                <i class="fas fa-map-marker-alt mr-1"></i>
                                <?= htmlspecialchars($fromOutlet['physical_address_1'], ENT_QUOTES) ?>
                              </small>
                            <?php endif; ?>
                          </div>
                        </div>
                      </div>
                      <div class="col-md-6">
                        <div class="media">
                          <div class="mr-3 text-center" style="width:60px;">
                            <i class="fas fa-map-marker-alt fa-2x text-success"></i>
                          </div>
                          <div class="media-body">
                            <h6 class="text-uppercase text-muted" style="font-size:0.75rem;">To</h6>
                            <p class="h5 mb-0"><?= htmlspecialchars($toDisplay, ENT_QUOTES) ?></p>
                            <?php if (!empty($toOutlet['physical_address_1'])): ?>
                              <small class="text-muted">
                                <i class="fas fa-map-marker-alt mr-1"></i>
                                <?= htmlspecialchars($toOutlet['physical_address_1'], ENT_QUOTES) ?>
                              </small>
                            <?php endif; ?>
                          </div>
                        </div>
                      </div>
                    </div>
                    
                    <!-- Draft Status Bar -->
                    <div class="card mb-3">
                      <div class="card-body py-2">
                        <div class="d-flex justify-content-between align-items-center flex-wrap">
                          <div class="d-flex align-items-center">
                            <span id="draft-status" class="badge badge-secondary mr-2">Draft: Off</span>
                            <small id="draft-last-saved" class="text-muted">Not saved</small>
                            <div class="custom-control custom-switch ml-3">
                              <input type="checkbox" class="custom-control-input" id="toggle-autosave">
                              <label class="custom-control-label" for="toggle-autosave">Auto-save (30s)</label>
                            </div>
                          </div>
                          <div>
                            <button type="button" id="btn-save-draft" class="btn btn-sm btn-outline-primary">
                              <i class="fa fa-save mr-1"></i>Save Draft
                            </button>
                            <button type="button" id="btn-restore-draft" class="btn btn-sm btn-outline-secondary" disabled>
                              <i class="fa fa-undo mr-1"></i>Restore
                            </button>
                          </div>
                        </div>
                      </div>
                    </div>
                    
                    <!-- Transfer Items Table -->
                    <div class="card mb-4">
                      <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-boxes mr-2"></i>Transfer Contents</h5>
                        <div>
                          <button type="button" id="btn-autofill" class="btn btn-outline-primary btn-sm mr-2" <?= $PACKONLY ? 'disabled' : '' ?>>
                            <i class="fa fa-magic mr-1"></i>Auto-fill
                          </button>
                          <button type="button" id="btn-add-product" class="btn btn-outline-secondary btn-sm" <?= $PACKONLY ? 'disabled' : '' ?>>
                            <i class="fa fa-plus mr-1"></i>Add Product
                          </button>
                        </div>
                      </div>
                      <div class="card-body p-0">
                        <div class="table-responsive">
                          <table class="table table-sm table-hover mb-0" id="transfer-table">
                            <thead class="thead-light">
                              <tr>
                                <th width="40"></th>
                                <th>Product</th>
                                <th width="80" class="text-center">Stock</th>
                                <th width="80" class="text-center">Planned</th>
                                <th width="100" class="text-center">Counted</th>
                                <th width="100" class="text-center">Tag</th>
                                <th width="60" class="text-center">Action</th>
                              </tr>
                            </thead>
                            <tbody>
                              <?php if (empty($items)): ?>
                                <tr>
                                  <td colspan="7" class="text-center text-muted py-5">
                                    <i class="fas fa-box-open fa-3x mb-3"></i>
                                    <p>No items in this transfer yet.</p>
                                  </td>
                                </tr>
                              <?php else: ?>
                                <?php foreach ($items as $idx => $item): 
                                  $productId = $item['product_id'] ?? 0;
                                  $productName = $item['product_name'] ?? 'Unknown Product';
                                  $sku = $item['product_sku'] ?? '';
                                  $sourceStock = (int)($item['source_stock'] ?? 0);
                                  $planned = (int)($item['planned_qty'] ?? 0);
                                  $counted = (int)($item['counted_qty'] ?? 0);
                                  $lineNum = $idx + 1;
                                ?>
                                <tr data-product-id="<?= (int)$productId ?>" data-inventory="<?= $sourceStock ?>" data-planned="<?= $planned ?>">
                                  <td class="text-center align-middle">
                                    <input type="hidden" class="productID" value="<?= (int)$productId ?>">
                                    <i class="fas fa-grip-vertical text-muted"></i>
                                  </td>
                                  <td>
                                    <strong><?= htmlspecialchars($productName, ENT_QUOTES) ?></strong>
                                    <?php if ($sku): ?>
                                      <br><small class="text-muted">SKU: <?= htmlspecialchars($sku, ENT_QUOTES) ?></small>
                                    <?php endif; ?>
                                  </td>
                                  <td class="text-center inv"><?= $sourceStock ?></td>
                                  <td class="text-center planned"><?= $planned ?></td>
                                  <td class="counted-td text-center">
                                    <input type="number" class="form-control form-control-sm text-center pack-qty-input" 
                                           value="<?= $counted ?>" min="0" max="<?= $sourceStock ?>"
                                           oninput="window.packModern?.handleQtyChange(this)"
                                           <?= $PACKONLY ? 'disabled' : '' ?>>
                                  </td>
                                  <td class="text-center">
                                    <span class="badge badge-secondary"><?= $txId ?>-<?= $lineNum ?></span>
                                  </td>
                                  <td class="text-center">
                                    <button type="button" class="btn btn-sm btn-outline-danger" 
                                            onclick="window.packModern?.removeProduct(this)" <?= $PACKONLY ? 'disabled' : '' ?>>
                                      <i class="fas fa-trash"></i>
                                    </button>
                                  </td>
                                </tr>
                                <?php endforeach; ?>
                              <?php endif; ?>
                            </tbody>
                            <tfoot class="bg-light">
                              <tr>
                                <th colspan="3" class="text-right">Totals:</th>
                                <th class="text-center"><span id="plannedTotal"><?= $metrics['planned'] ?></span></th>
                                <th class="text-center"><span id="countedTotal"><?= $metrics['counted'] ?></span></th>
                                <th colspan="2" class="text-center">
                                  Diff: <span id="diffTotal" style="font-weight:bold;"><?= $metrics['diff_label'] ?></span>
                                </th>
                              </tr>
                            </tfoot>
                          </table>
                        </div>
                      </div>
                    </div>
                    
                    <!-- Tracking & Notes -->
                    <div class="card mb-4">
                      <div class="card-header"><h5 class="mb-0"><i class="fas fa-clipboard mr-2"></i>Tracking & Notes</h5></div>
                      <div class="card-body">
                        <div class="form-group">
                          <label>Tracking Numbers</label>
                          <div id="tracking-items"></div>
                          <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="btn-add-tracking" <?= $PACKONLY ? 'disabled' : '' ?>>
                            <i class="fa fa-plus mr-1"></i>Add Tracking Number
                          </button>
                        </div>
                        <div class="form-group">
                          <label for="notesForTransfer">Notes</label>
                          <textarea class="form-control" id="notesForTransfer" rows="3" <?= $PACKONLY ? 'disabled' : '' ?>><?= htmlspecialchars($transfer['notes'] ?? '', ENT_QUOTES) ?></textarea>
                        </div>
                      </div>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="card">
                      <div class="card-body">
                        <div class="row">
                          <div class="col-md-8">
                            <h5>Transfer Summary</h5>
                            <div class="row text-center">
                              <div class="col-4">
                                <div class="h3 mb-0 text-info"><?= count($items) ?></div>
                                <small class="text-muted">Items</small>
                              </div>
                              <div class="col-4">
                                <div class="h3 mb-0 text-success"><span id="summary-counted"><?= $metrics['counted'] ?></span></div>
                                <small class="text-muted">Units Counted</small>
                              </div>
                              <div class="col-4">
                                <div class="h3 mb-0 <?= $metrics['accuracy'] >= 100 ? 'text-success' : 'text-warning' ?>">
                                  <span id="summary-accuracy"><?= $metrics['accuracy'] ?></span>%
                                </div>
                                <small class="text-muted">Accuracy</small>
                              </div>
                            </div>
                          </div>
                          <div class="col-md-4 text-right">
                            <?php if (!$PACKONLY): ?>
                              <button type="button" class="btn btn-lg btn-success" id="btn-mark-ready">
                                <i class="fas fa-check mr-2"></i>Mark Ready for Delivery
                              </button>
                            <?php else: ?>
                              <button type="button" class="btn btn-lg btn-secondary" disabled>
                                <i class="fas fa-check mr-2"></i>Already Sent
                              </button>
                            <?php endif; ?>
                          </div>
                        </div>
                      </div>
                    </div>
                    
                  </div><!-- /.cis-content -->
                </div><!-- /.card-body -->
              </div><!-- /.card -->
            </div><!-- /.col -->
          </div><!-- /.row -->
        </div><!-- /.animated fadeIn -->
      </div><!-- /.container-fluid -->
    </main>
    
    <?php include($_SERVER['DOCUMENT_ROOT'] . "/assets/template/personalisation-menu.php"); ?>
  </div><!-- /.app-body -->

  <!-- Hidden form fields -->
  <input type="hidden" id="transferID" value="<?= (int)$txId ?>">
  <input type="hidden" id="staffID" value="<?= (int)$currentUserId ?>">
  <input type="hidden" id="sourceID" value="<?= (int)$fromOutletId ?>">
  <input type="hidden" id="destinationID" value="<?= (int)$toOutletId ?>">

  <!-- ######### JAVASCRIPT BEGINS HERE ######### -->
  <script>
    window.DISPATCH_BOOT = <?= json_encode($bootPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    window.PACK_ONLY = <?= json_encode($PACKONLY) ?>;
  </script>
  <script src="/modules/transfers/stock/assets/js/pack-modern.js?v=<?= (int)$assetVer ?>" defer></script>
  <!-- ######### JAVASCRIPT ENDS HERE ######### -->

  <?php include($_SERVER['DOCUMENT_ROOT'] . "/assets/template/html-footer.php"); ?>
  <?php include($_SERVER['DOCUMENT_ROOT'] . "/assets/template/footer.php"); ?>
  <!-- ######### FOOTER ENDS HERE ######### -->
</body>
</html>
  if (is_numeric($val)) return (int)$val;
  $v = filter_var($val, FILTER_VALIDATE_INT);
  return is_int($v) ? $v : $fallback;
}

function _first(...$vals) {
  foreach ($vals as $v) {
    if (is_string($v) && $v !== '') return $v;
    if (is_numeric($v)) return $v;
    if (is_array($v) && !empty($v)) return $v;
  }
  return null;
}

function format_outlet_display(?array $outlet, string $fallback = 'Unknown'): string {
  if (!$outlet) return $fallback;
  $name = (string)($outlet['name'] ?? $fallback);
  $parts = [$name];
  
  if (!empty($outlet['physical_city'])) {
    $parts[] = $outlet['physical_city'];
  }
  
  return implode(' - ', $parts);
}

function compute_pack_metrics(array $items): array {
  $planned = 0;
  $counted = 0;
  
  foreach ($items as $it) {
    $planned += (int)(_first($it['qty_requested'] ?? null, $it['planned_qty'] ?? 0) ?: 0);
    $counted += (int)($it['counted_qty'] ?? 0);
  }
  
  $diff = $counted - $planned;
  $accuracy = $planned > 0 ? round(($counted / $planned) * 100, 1) : 100.0;
  
  return [
    'planned' => $planned,
    'counted' => $counted,
    'diff' => $diff,
    'accuracy' => $accuracy,
    'diff_label' => $diff > 0 ? '+' . $diff : (string)$diff
  ];
}

// --------------------------------------------------------------------------------------
// Auth & Guards
// --------------------------------------------------------------------------------------

if (!isset($_SESSION['userID']) || (int)$_SESSION['userID'] <= 0) {
  http_response_code(401);
  echo "Unauthorized. Please log in.";
  exit;
}

$currentUserId = (int)$_SESSION['userID'];
$currentUserName = $_SESSION['name'] ?? 'User';

// Get transfer ID
$txId = _int($_GET['transfer'] ?? $_GET['id'] ?? 0);
if ($txId <= 0) {
  http_response_code(400);
  echo "Missing or invalid transfer ID.";
  exit;
}

// --------------------------------------------------------------------------------------
// Data Loading
// --------------------------------------------------------------------------------------

$transfer = [];
$items = [];

try {
  $svc = new TransfersService();
  
  // Load transfer with items (uses getTransfer method like pack.php)
  $transfer = $svc->getTransfer($txId);
  if (!$transfer) {
    throw new RuntimeException("Transfer #{$txId} not found.");
  }
  
  // Extract items from transfer (they're included in the getTransfer response)
  $items = $transfer['items'] ?? [];
  
  // Get outlet IDs (normalized from UUID to legacy IDs)
  $fromOutletId = (int)($transfer['outlet_from'] ?? 0);
  $toOutletId = (int)($transfer['outlet_to'] ?? 0);
  $fromUuid = $transfer['outlet_from_uuid'] ?? '';
  $toUuid = $transfer['outlet_to_uuid'] ?? '';
  
  // Load full outlet metadata
  $fromOutlet = null;
  $toOutlet = null;
  
  if ($fromUuid) {
    $fromOutlet = $svc->getOutletMeta($fromUuid);
  }
  
  if ($toUuid) {
    $toOutlet = $svc->getOutletMeta($toUuid);
  }
  
  // Load source stock levels
  $sourceStockMap = [];
  if ($items && $fromUuid) {
    $productIds = array_column($items, 'product_id');
    $sourceStockMap = $svc->getSourceStockLevels($productIds, $fromUuid);
  }
  
  // Enrich items with source stock
  foreach ($items as &$item) {
    $productId = $item['product_id'] ?? null;
    $item['source_stock'] = $sourceStockMap[$productId] ?? 0;
    
    // Normalize quantity fields
    $item['planned_qty'] = $item['qty_requested'] ?? 0;
    $item['counted_qty'] = $item['qty_sent_total'] ?? 0;
  }
  unset($item);
  
  // Compute metrics
  $metrics = compute_pack_metrics($items);
  
  // Check if already packaged/sent
  $transferStatus = (int)($transfer['status'] ?? 0);
  $isPackaged = $transferStatus >= 1; // 1=sent, 2=received
  
  // Load staff details
  $staffResolver = new StaffNameResolver();
  $createdByName = 'Unknown';
  if (!empty($transfer['created_by'])) {
    $createdByName = $staffResolver->resolve((int)$transfer['created_by']);
  }
  
} catch (Throwable $e) {
  error_log("[pack-modern.php] Error loading transfer: " . $e->getMessage());
  http_response_code(500);
  echo "Error loading transfer: " . htmlspecialchars($e->getMessage());
  exit;
}

// --------------------------------------------------------------------------------------
// Prepare View Data
// --------------------------------------------------------------------------------------

// Asset versioning (bust cache on changes)
$assetVer = (int)($_SERVER['REQUEST_TIME'] ?? time());

// Lock status (simplified for this version)
$lockStatus = [
  'has_lock' => false,
  'owner_id' => 0,
  'owner_name' => '',
  'token' => ''
];

// Labels for display
$fromLbl = format_outlet_display($fromOutlet, $transfer['outlet_from_name'] ?? 'Source');
$toLbl = format_outlet_display($toOutlet, $transfer['outlet_to_name'] ?? 'Destination');
$fromDisplay = $fromOutlet['name'] ?? $transfer['outlet_from_name'] ?? 'Source Outlet';
$toDisplay = $toOutlet['name'] ?? $transfer['outlet_to_name'] ?? 'Destination Outlet';

// String transfer ID for display
$txStringId = str_pad((string)$txId, 6, '0', STR_PAD_LEFT);

// Prepare boot payload for JavaScript
$bootPayload = [
  'transfer_id' => $txId,
  'transfer_string_id' => $txStringId,
  'status' => $transferStatus,
  'is_packaged' => $isPackaged,
  'from_outlet' => [
    'id' => $fromOutletId,
    'name' => $fromDisplay,
    'uuid' => $fromUuid,
  ],
  'to_outlet' => [
    'id' => $toOutletId,
    'name' => $toDisplay,
    'uuid' => $toUuid,
  ],
  'metrics' => $metrics,
  'user' => [
    'id' => $currentUserId,
    'name' => $currentUserName,
  ],
  'created_by' => $createdByName,
  'created_at' => $transfer['created_at'] ?? null,
  'api_endpoints' => [
    'save' => '/modules/transfers/stock/api/pack_save.php',
    'send' => '/modules/transfers/stock/api/pack_send.php',
    'product_search' => '/modules/transfers/stock/api/product_search.php',
    'add_product' => '/modules/transfers/stock/api/add_product.php',
    'delete_transfer' => '/modules/transfers/stock/api/delete_transfer.php',
    'merge_transfer' => '/modules/transfers/stock/api/merge_transfer.php',
    'draft_save' => '/modules/transfers/stock/api/draft_save.php',
  ],
];

// Pack-only mode flag (if transfer already sent, block modifications)
$PACKONLY = $isPackaged;

// --------------------------------------------------------------------------------------
// Include View
// --------------------------------------------------------------------------------------

require_once __DIR__ . '/pack-modern.view.php';
?>
