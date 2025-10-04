<?php
declare(strict_types=1);

/**
 * CIS — Stock Transfers » Pack Controller (REFACTORED)
 *
 * Clean, modular controller with component-based views and auto-loading assets
 * 
 * @package Modules\Transfers\Stock
 * @author  Pearce Stephens <pearce.stephens@ecigdis.co.nz>
 * @since   2025-10-03
 * @version 2.0.0
 */

@date_default_timezone_set('Pacific/Auckland');

// ====== Bootstrap ======
$DOCUMENT_ROOT = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
if ($DOCUMENT_ROOT === '' || !is_dir($DOCUMENT_ROOT)) {
    http_response_code(500);
    die("Server misconfiguration: DOCUMENT_ROOT not set.");
}

require_once $DOCUMENT_ROOT . '/app.php';
require_once $DOCUMENT_ROOT . '/modules/transfers/_local_shims.php';

if (is_file($DOCUMENT_ROOT . '/modules/transfers/_shared/Autoload.php')) {
    require_once $DOCUMENT_ROOT . '/modules/transfers/_shared/Autoload.php';
}

use Modules\Transfers\Stock\Services\TransfersService;
use Modules\Transfers\Stock\Services\PackLockService;
use Modules\Transfers\Stock\Services\StaffNameResolver;
use Modules\Transfers\Stock\Lib\AccessPolicy;
use Modules\Transfers\Stock\Lib\AssetLoader;

// ====== Helper Functions ======

/**
 * Safe integer conversion
 */
function _int($val, int $fallback = 0): int {
    if (is_numeric($val)) return (int)$val;
    $v = filter_var($val, FILTER_VALIDATE_INT);
    return is_int($v) ? $v : $fallback;
}

/**
 * First non-empty value
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
 * Generate product tag (TRANSFERID-LINENUM)
 */
function _tfx_product_tag($txId, $lineNum): string {
    return htmlspecialchars("{$txId}-{$lineNum}", ENT_QUOTES, 'UTF-8');
}

/**
 * Format outlet address
 */
function format_outlet_address(?array $outlet, string $fallbackName = 'Unknown'): string {
    if (!$outlet) return $fallbackName;
    $name = (string)($outlet['name'] ?? $fallbackName);
    $parts = [];
    
    foreach (['physical_address_1', 'physical_address_2'] as $k) {
        if (!empty($outlet[$k])) $parts[] = $outlet[$k];
    }
    
    $cityBits = [];
    foreach (['physical_suburb', 'physical_city', 'physical_postcode'] as $k) {
        if (!empty($outlet[$k])) $cityBits[] = $outlet[$k];
    }
    if ($cityBits) $parts[] = implode(', ', $cityBits);
    
    if (!empty($outlet['physical_phone_number'])) {
        $parts[] = 'Ph: ' . $outlet['physical_phone_number'];
    }
    
    return $parts ? ($name . ' - ' . implode(', ', $parts)) : $name;
}

/**
 * Compute pack metrics
 */
function compute_pack_metrics(array $items): array {
    $planned = 0;
    $counted = 0;
    
    foreach ($items as $it) {
        $planned += (int)(_first($it['qty_requested'] ?? null, $it['planned_qty'] ?? 0) ?: 0);
        $counted += (int)($it['counted_qty'] ?? 0);
    }
    
    $diff = $counted - $planned;
    $diffLabel = ($diff > 0 ? '+' : '') . $diff;
    $accuracy = $planned > 0 ? (int)round(($counted / $planned) * 100) : 0;
    
    return compact('planned', 'counted', 'diff', 'diffLabel', 'accuracy');
}

// ====== Input & Guards ======

// Validate and sanitize transfer ID
$txIdRaw = $_GET['transfer'] ?? $_GET['tx'] ?? null;
if (!$txIdRaw) {
    http_response_code(400);
    die("Missing transfer parameter.");
}

// Strict validation for transfer ID (alphanumeric + dashes only)
$txStringId = is_string($txIdRaw) ? $txIdRaw : (string)$txIdRaw;
if (!preg_match('/^[a-zA-Z0-9\-]+$/', $txStringId)) {
    http_response_code(400);
    die("Invalid transfer ID format.");
}

$txId = _int($txStringId);
if ($txId <= 0) {
    http_response_code(400);
    die("Invalid transfer ID.");
}

// Current user
$currentUserId = (int)($_SESSION['userID'] ?? 0);
if ($currentUserId <= 0) {
    http_response_code(403);
    die("Authentication required.");
}

// ====== Service Initialization ======

$transfersService = new TransfersService();
$lockService = new PackLockService();
$staffResolver = new StaffNameResolver();

// ====== Fetch Transfer Data ======

try {
    $transfer = $transfersService->getTransfer($txId);
    if (!$transfer) {
        http_response_code(404);
        die("Transfer not found.");
    }
} catch (\Exception $e) {
    error_log("Pack: Failed to load transfer #{$txId}: " . $e->getMessage());
    http_response_code(500);
    die("Failed to load transfer data.");
}

$items = $transfer['items'] ?? [];

// ====== Outlet Resolution ======

$fromOutletUuid = (string)($transfer['source_outlet_id'] ?? '');
$toOutletUuid = (string)($transfer['destination_outlet_id'] ?? '');

$fromOutlet = $fromOutletUuid ? $transfersService->getOutletMeta($fromOutletUuid) : null;
$toOutlet = $toOutletUuid ? $transfersService->getOutletMeta($toOutletUuid) : null;

$fromLbl = $fromOutlet['name'] ?? $fromOutletUuid ?: 'Unknown';
$toLbl = $toOutlet['name'] ?? $toOutletUuid ?: 'Unknown';

$fromDisplay = format_outlet_address($fromOutlet, $fromLbl);
$toDisplay = format_outlet_address($toOutlet, $toLbl);

// ====== Source Stock Levels ======

$productIds = array_filter(
    array_map(
        fn($item) => (string)_first($item['product_id'] ?? null, $item['vend_product_id'] ?? ''),
        $items
    )
);

$sourceStockMap = [];
if (!empty($productIds) && $fromOutletUuid) {
    try {
        $sourceStockMap = $transfersService->getSourceStockLevels($productIds, $fromOutletUuid);
    } catch (\Exception $e) {
        error_log("Pack: Failed to load source stock levels: " . $e->getMessage());
    }
}

// ====== Compute Metrics ======

$metrics = compute_pack_metrics($items);
$plannedSum = $metrics['planned'];
$countedSum = $metrics['counted'];
$diff = $metrics['diff'];
$diffLabel = $metrics['diffLabel'];
$accuracy = $metrics['accuracy'];

// Calculate total weight
$estimatedWeight = 0; // in kg
foreach ($items as $item) {
    $countedQty = (int)($item['counted_qty'] ?? 0);
    $unitWeightG = (int)_first(
        $item['derived_unit_weight_grams'] ?? null,
        $item['avg_weight_grams'] ?? null,
        $item['product_weight_grams'] ?? null,
        $item['weight_g'] ?? null,
        100
    );
    $estimatedWeight += ($countedQty * $unitWeightG) / 1000; // convert to kg
}

// ====== Lock Status ======

$lockStatus = [
    'state' => 'unlocked',
    'has_lock' => false,
    'is_locked_by_other' => false,
    'owner_id' => null,
    'owner_name' => null,
    'tab_id' => null,
    'same_owner' => false,
    'can_request' => false,
    'lock_expires_at' => null,
    'lock_acquired_at' => null
];

try {
    // Cleanup expired locks first
    $lockService->cleanup();
    
    // Check current lock status
    $currentLock = $lockService->getLock($txStringId);
    
    if ($currentLock) {
        $lockHolderId = (int)$currentLock['user_id'];
        $lockTabId = $currentLock['tab_id'] ?? 'unknown';
        
        if ($lockHolderId === $currentUserId) {
            $lockStatus['state'] = 'acquired';
            $lockStatus['has_lock'] = true;
            $lockStatus['owner_id'] = $lockHolderId;
            $lockStatus['tab_id'] = $lockTabId;
            $lockStatus['same_owner'] = true;
        } else {
            $lockStatus['state'] = 'locked_by_other';
            $lockStatus['is_locked_by_other'] = true;
            $lockStatus['owner_id'] = $lockHolderId;
            $lockStatus['owner_name'] = $staffResolver->name($lockHolderId);
            $lockStatus['tab_id'] = $lockTabId;
            $lockStatus['same_owner'] = false;
            $lockStatus['can_request'] = true;
        }
        $lockStatus['lock_expires_at'] = $currentLock['expires_at'];
        $lockStatus['lock_acquired_at'] = $currentLock['acquired_at'];
    } else {
        // No lock exists - user can acquire it
        $lockStatus['state'] = 'unlocked';
        $lockStatus['can_request'] = true;
    }
} catch (\Exception $e) {
    error_log("Pack: Failed to get lock status: " . $e->getMessage());
    // Keep default unlocked state
}

// ====== Asset Loading ======

$assetLoader = new AssetLoader(
    __DIR__,
    '/modules/transfers/stock'
);

$assets = $assetLoader->loadPage('pack');

// ====== JavaScript Boot Payload ======

$bootPayload = [
    'transfer_id' => $txStringId,
    'transfer_id_int' => $txId,
    'user_id' => $currentUserId,
    'session_id' => session_id(),
    'from_outlet' => $fromLbl,
    'to_outlet' => $toLbl,
    'has_items' => !empty($items),
    'source_stock_available' => !empty($sourceStockMap),
    'lock_status' => $lockStatus,
    'performance_tracking_enabled' => true,
    'auto_heartbeat_interval' => 30000,
    'csrf_token' => $_SESSION['csrf_token'] ?? null,
    'lock_api_endpoint' => '/modules/transfers/stock/api/advanced_lock.php',
    'sse_endpoint' => '/modules/transfers/stock/api/lock_events.php',
];

// ====== Render View ======

// Pass variables to view scope (no extract!)
$viewData = [
    'DOCUMENT_ROOT' => $DOCUMENT_ROOT,
    'txId' => $txId,
    'txStringId' => $txStringId,
    'transfer' => $transfer,
    'items' => $items,
    'fromLbl' => $fromLbl,
    'toLbl' => $toLbl,
    'fromOutlet' => $fromOutlet,
    'toOutlet' => $toOutlet,
    'fromDisplay' => $fromDisplay,
    'toDisplay' => $toDisplay,
    'sourceStockMap' => $sourceStockMap,
    'plannedSum' => $plannedSum,
    'countedSum' => $countedSum,
    'diff' => $diff,
    'diffLabel' => $diffLabel,
    'accuracy' => $accuracy,
    'estimatedWeight' => $estimatedWeight,
    'lockStatus' => $lockStatus,
    'currentUserId' => $currentUserId,
    'bootPayload' => $bootPayload,
    'assets' => $assets,
];

// Include CIS template wrappers
include $DOCUMENT_ROOT . '/assets/template/html-header.php';
include $DOCUMENT_ROOT . '/assets/template/header.php';

// Include our view
require __DIR__ . '/views/pack.view.php';

// Footer
include $DOCUMENT_ROOT . '/assets/template/html-footer.php';
include $DOCUMENT_ROOT . '/assets/template/footer.php';
