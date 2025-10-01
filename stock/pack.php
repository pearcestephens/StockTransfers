<?php
declare(strict_types=1);

/**
 * CIS — Transfers » Stock » Pack (Controller)
 *
 * Responsibilities:
 * - Bootstrap & guards
 * - Resolve transfer context (transfer, items, outlets)
 * - Compute derived metrics (totals, variance, status)
 * - Prepare JS boot payload
 * - Delegate to pack.view.php for rendering
 */

@date_default_timezone_set('Pacific/Auckland');

// --------------------------------------------------------------------------------------
// Bootstrap & Guards
// --------------------------------------------------------------------------------------
$DOCUMENT_ROOT = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
if ($DOCUMENT_ROOT === '' || !is_dir($DOCUMENT_ROOT)) {
  http_response_code(500);
  echo "Server misconfiguration: DOCUMENT_ROOT not set.";
  exit;
}

require_once $DOCUMENT_ROOT . '/app.php';
require_once $DOCUMENT_ROOT . '/modules/transfers/_local_shims.php';

use Modules\Transfers\Stock\Services\TransfersService;
use Modules\Transfers\Stock\Services\PackLockService;
use Modules\Transfers\Stock\Services\LockAuditService;
use Modules\Transfers\Stock\Services\PerformanceTracker;
use Modules\Transfers\Stock\Services\StaffNameResolver;
use Modules\Transfers\Stock\Lib\AccessPolicy;

// If your project already registers an autoloader for Modules\*, this is a no-op.
if (!class_exists('Modules\\_AutoloadShim', false)) {
  spl_autoload_register(static function(string $class): void {
    $prefix = 'Modules\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $rel = substr($class, strlen($prefix));
    $relSlashes = str_replace('\\', '/', $rel);

    $base = $GLOBALS['MODULES_PATH'] ?? ($GLOBALS['MODULES_PATH'] = $GLOBALS['DOCUMENT_ROOT'] . '/modules');
    $p1 = $base . '/' . $relSlashes . '.php';
    if (is_file($p1)) { require_once $p1; return; }

    $parts = explode('/', $relSlashes);
    $file  = array_pop($parts);
    $dir   = strtolower(implode('/', $parts));
    $p2 = $base . '/' . $dir . '/' . $file . '.php';
    if (is_file($p2)) { require_once $p2; return; }
  });
}

// --------------------------------------------------------------------------------------
/** Helpers */
// --------------------------------------------------------------------------------------

/**
 * Safe int fetch (GET/POST/var) with fallback
 */
function _int($val, int $fallback = 0): int {
  if (is_numeric($val)) return (int)$val;
  $v = filter_var($val, FILTER_VALIDATE_INT);
  return is_int($v) ? $v : $fallback;
}

/**
 * Merge-like: prefer first non-empty scalar
 */
function _first(...$vals) {
  foreach ($vals as $v) {
    if (is_string($v) && $v !== '') return $v;
    if (is_numeric($v)) return $v;
    if (is_array($v) && !empty($v)) return $v;
  }
  return null;
}

/**
 * Format an outlet to a single human string.
 */
function format_outlet_address(?array $outlet, string $fallbackName = 'Unknown'): string {
  if (!$outlet) return $fallbackName;
  $name = (string)($outlet['name'] ?? $fallbackName);
  $parts = [];

  foreach (['physical_address_1','physical_address_2'] as $k) {
    if (!empty($outlet[$k])) $parts[] = $outlet[$k];
  }

  $cityBits = [];
  foreach (['physical_suburb','physical_city','physical_postcode'] as $k) {
    if (!empty($outlet[$k])) $cityBits[] = $outlet[$k];
  }
  if ($cityBits) $parts[] = implode(', ', $cityBits);

  if (!empty($outlet['physical_phone_number'])) $parts[] = 'Ph: ' . $outlet['physical_phone_number'];
  return $parts ? ($name . ' - ' . implode(', ', $parts)) : $name;
}

/**
 * Compute metrics from items (planned/counts)
 * items[] expected fields: planned => qty_requested|planned_qty, counted => counted_qty
 */
function compute_pack_metrics(array $items): array {
  $planned = 0;
  $counted = 0;
  foreach ($items as $it) {
    $planned += (int)(_first($it['qty_requested'] ?? null, $it['planned_qty'] ?? 0) ?: 0);
    $counted += (int)($it['counted_qty'] ?? 0);
  }
  $diff      = $counted - $planned;
  $accuracy  = $planned > 0 ? round(($counted / $planned) * 100, 1) : 0.0;
  $diffLabel = ($diff >= 0 ? '+' : '') . (string)$diff;

  return [
    'plannedSum' => $planned,
    'countedSum' => $counted,
    'diff'       => $diff,
    'diffLabel'  => $diffLabel,
    'accuracy'   => $accuracy,
  ];
}

// --------------------------------------------------------------------------------------
// Resolve transfer context
// --------------------------------------------------------------------------------------

// txId can arrive as $txId (pre-wired), GET[transfer], or from $transfer['id']
$txId = isset($txId) ? _int($txId) : _int($_GET['transfer'] ?? ($_GET['tx'] ?? 0));
if ($txId <= 0 && !empty($transfer['id'])) $txId = _int($transfer['id']);

if ($txId <= 0) {
  http_response_code(400);
  echo "Bad request: missing transfer id.";
  exit;
}

// For lock service, we need the string transfer ID from URL if available
$txStringId = $_GET['tx'] ?? $_GET['transfer'] ?? (string)$txId;

// Include helper functions that views will need
require_once __DIR__ . '/lib/pack-helpers.php';

// Get current user
$currentUserId = (int)($_SESSION['userID'] ?? 0);
if ($currentUserId <= 0) {
    http_response_code(401);
    echo "Authentication required.";
    exit;
}

// Initialize services
$txSvc = new TransfersService();
$lockService = new PackLockService();
$performanceTracker = new PerformanceTracker();
$staffResolver = new StaffNameResolver();

// Check access permissions
try {
    AccessPolicy::requireAccess($currentUserId, $txId, 'pack');
} catch (\RuntimeException $e) {
    http_response_code(403);
    echo "Access denied: " . $e->getMessage();
    exit;
}

// Cleanup expired locks
$lockService->cleanup();

// Check current lock status
$currentLock = $lockService->getLock($txStringId);
$lockStatus = [
    'has_lock' => false,
    'is_locked_by_other' => false,
    'holder_name' => null,
    'holder_id' => null,
    'can_request' => false,
    'lock_expires_at' => null,
    'lock_acquired_at' => null
];

if ($currentLock) {
    $lockHolderId = (int)$currentLock['user_id'];
    if ($lockHolderId === $currentUserId) {
        $lockStatus['has_lock'] = true;
        $lockStatus['lock_expires_at'] = $currentLock['expires_at'];
        $lockStatus['lock_acquired_at'] = $currentLock['acquired_at'];
        
        // Record packing started if not already recorded
        if ($performanceTracker->isTransferBeingTimed($txId)) {
            $performanceTracker->recordPackingStarted($txId, $currentUserId, session_id());
        }
    } else {
        $lockStatus['is_locked_by_other'] = true;
        $lockStatus['holder_name'] = $staffResolver->name($lockHolderId);
        $lockStatus['holder_id'] = $lockHolderId;
        $lockStatus['can_request'] = true;
    }
} else {
    // No lock exists - user can acquire it
    $lockStatus['can_request'] = true;
}

// If upstream already provided these, respect them
$transfer = $transfer ?? [];
$items    = $items ?? [];

// Try to fetch transfer/items if not present (non-fatal if your app wires these elsewhere)
try {
    if (!$transfer) {
        // Use the same service as receive.php
        $transfer = $txSvc->getTransfer($txId) ?? [];
        
        // Extract items from transfer if they were loaded
        if (!$items && !empty($transfer['items'])) {
            $items = $transfer['items'];
        }
    }
  if (!$items) {
    // Fallback attempts (for legacy compatibility)
    if (class_exists('\\Modules\\Transfers\\Stock\\TransferRepository')) {
      /** @noinspection PhpFullyQualifiedNameUsageInspection */
      $items = \Modules\Transfers\Stock\TransferRepository::getItems($txId) ?? [];
    } elseif (function_exists('getTransferItems')) {
      $items = (array) getTransferItems($txId);
    } else {
      $items = []; // keep empty
    }
  }
} catch (\Throwable $e) {
  // Non-fatal: render page with available data
  trigger_error('Pack controller fetch warning: ' . $e->getMessage(), E_USER_WARNING);
}

// Outlets & labels
$fromOutlet = $fromOutlet ?? ($transfer['from_outlet'] ?? $transfer['outlet_from'] ?? null);
$toOutlet   = $toOutlet   ?? ($transfer['to_outlet']   ?? $transfer['outlet_to']   ?? null);

// Normalize to arrays when possible
if (is_string($fromOutlet)) $fromOutlet = ['id' => $fromOutlet, 'name' => ($transfer['outlet_from_name'] ?? 'From')];
if (is_string($toOutlet))   $toOutlet   = ['id' => $toOutlet,   'name' => ($transfer['outlet_to_name']   ?? 'To')];

$fromLbl = $fromLbl ?? (string)_first($transfer['outlet_from_name'] ?? null, $fromOutlet['name'] ?? 'From');
$toLbl   = $toLbl   ?? (string)_first($transfer['outlet_to_name']   ?? null, $toOutlet['name']   ?? 'To');

$fromDisplay = format_outlet_address(is_array($fromOutlet) ? $fromOutlet : null, $fromLbl);
$toDisplay   = format_outlet_address(is_array($toOutlet)   ? $toOutlet   : null, $toLbl);

// Stock map (source outlet on-hand by product id) – pass-through if provided upstream
$sourceStockMap = $sourceStockMap ?? [];
if (!$sourceStockMap && $items && !empty($transfer)) {
  // Use TransfersService to get actual stock levels from source outlet
  $fromOutletUuid = (string)($transfer['outlet_from_uuid'] ?? $transfer['outlet_from_meta']['id'] ?? '');
  if ($fromOutletUuid !== '' && isset($txSvc)) {
    // Collect product IDs from items
    $productIds = [];
    foreach ($items as $it) {
      $pid = (string)($it['product_id'] ?? '');
      if ($pid !== '') {
        $productIds[] = $pid;
      }
    }
    
    if ($productIds) {
      try {
        $sourceStockMap = $txSvc->getSourceStockLevels($productIds, $fromOutletUuid);
      } catch (\Throwable $stockError) {
        // Non-fatal: continue without stock levels
        error_log('Pack: Failed to load source stock levels: ' . $stockError->getMessage());
        $sourceStockMap = [];
      }
    }
  }
  
  // Fallback: try to extract from item data if available
  if (!$sourceStockMap) {
    foreach ($items as $it) {
      $pid = (string)_first($it['product_id'] ?? null, $it['vend_product_id'] ?? null, $it['id'] ?? '');
      if ($pid !== '') {
        $sourceStockMap[$pid] = (int)_first(
          $it['stock_at_outlet']   ?? null,
          $it['stock_at_source']   ?? null,
          $it['source_stock']      ?? null,
          $it['stock_qty']         ?? 0
        );
      }
    }
  }
}

// Derived metrics
$metrics = compute_pack_metrics($items);
$plannedSum = $metrics['plannedSum'];
$countedSum = $metrics['countedSum'];
$diff       = $metrics['diff'];
$diffLabel  = $metrics['diffLabel'];
$accuracy   = $metrics['accuracy'];

// Calculate actual weight from items
$estimatedWeight = 0.0;
if ($items) {
  foreach ($items as $item) {
    $qty = (int)($item['counted_qty'] ?? $item['planned_qty'] ?? 0);
    // Realistic vape product weights: average 150g per item
    $weight = (float)($item['weight'] ?? $item['product_weight'] ?? 0.15);
    $estimatedWeight += ($qty * $weight);
  }
}
$estimatedWeight = max($estimatedWeight, 0.1); // minimum 100g

// Calculate distance between outlets (simplified - you'd use real coordinates)
$distance = 0;
if (!empty($fromOutlet['outlet_lat']) && !empty($fromOutlet['outlet_long']) && 
    !empty($toOutlet['outlet_lat']) && !empty($toOutlet['outlet_long'])) {
  // Simple distance calculation (you could use proper geo distance)
  $latDiff = (float)$toOutlet['outlet_lat'] - (float)$fromOutlet['outlet_lat'];
  $lonDiff = (float)$toOutlet['outlet_long'] - (float)$fromOutlet['outlet_long'];
  $distance = (int)(sqrt($latDiff * $latDiff + $lonDiff * $lonDiff) * 111); // rough km conversion
}
$distance = max($distance, 50); // minimum 50km

// Calculate freight pricing based on weight
$satchelPrice = 8.50;
$smallBoxPrice = 12.90;
$mediumBoxPrice = 18.50;

if ($estimatedWeight <= 3.0) {
  $bestPrice = $satchelPrice;
  $bestOption = 'Satchel';
} elseif ($estimatedWeight <= 5.0) {
  $bestPrice = $smallBoxPrice;
  $bestOption = 'Small Box';
} else {
  $bestPrice = $mediumBoxPrice;
  $bestOption = 'Medium Box';
}

// Calculate car travel cost and CO2
$carCostPerKm = 0.85; // $0.85 per km (fuel + wear)
$carCost = $distance * $carCostPerKm;
$carCO2PerKm = 0.21; // 210g CO2 per km
$co2Saved = ($distance * $carCO2PerKm) / 1000; // convert to kg

// Pack status
$isPackaged = $isPackaged ?? false;
if (!$isPackaged) {
  $status = strtoupper((string)($transfer['status'] ?? ''));
  $isPackaged = ($status === 'PACKED') || ($plannedSum > 0 && $plannedSum === $countedSum);
}

// Asset version (cache bust). Now uses pack-unified.js mtime (so edits to unified script bust cache).
$assetCandidate = $DOCUMENT_ROOT . '/modules/transfers/stock/assets/js/pack-unified.js';
$assetVer = is_file($assetCandidate) ? (int)filemtime($assetCandidate) : time();

// JS boot payload (safe to extend)
$bootPayload = [
    'transfer_id' => $txStringId,  // Use string ID for lock APIs
    'transfer_id_int' => $txId,    // Keep integer ID for legacy operations
    'user_id' => $currentUserId,
    'from_outlet' => $fromLbl,
    'to_outlet' => $toLbl,
    'has_items' => !empty($items),
    'source_stock_available' => !empty($sourceStockMap),
    'lock_status' => $lockStatus,
    'performance_tracking_enabled' => true,
    'auto_heartbeat_interval' => 60000, // 60 seconds
    'csrf_token' => $_SESSION['csrf_token'] ?? null
];// Make variables available to the view:
$__view_vars = compact(
  'DOCUMENT_ROOT', 'txId', 'txStringId', 'transfer', 'items',
  'fromLbl', 'toLbl', 'fromOutlet', 'toOutlet',
  'fromDisplay', 'toDisplay', 'isPackaged',
  'sourceStockMap', 'plannedSum', 'countedSum', 'diff', 'diffLabel', 'accuracy',
  'estimatedWeight', 'distance', 'bestPrice', 'bestOption', 'carCost', 'co2Saved',
  'satchelPrice', 'smallBoxPrice', 'mediumBoxPrice',
  'bootPayload', 'assetVer', 'lockStatus', 'currentUserId'
);

// --------------------------------------------------------------------------------------
// Render
// --------------------------------------------------------------------------------------
extract($__view_vars, EXTR_SKIP);
unset($__view_vars);

// Include HTML headers
include $DOCUMENT_ROOT . '/assets/template/html-header.php';

include $DOCUMENT_ROOT . '/assets/template/header.php';

require __DIR__ . '/pack.view.php';
