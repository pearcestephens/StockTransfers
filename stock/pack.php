<?php

/**
 * CIS — Transfers » Stock » Pack (Refactored)
 *
 * This file handles the Pack & Ship page business logic and delegates
 * UI rendering to modular view components.
 * 
 * - Strict login + access guards
 * - Safe, predictable autoload for Modules\
 * - Defensive rendering of transfer context
 * - All UI components are now in views/components/
 *
 * IMPORTANT:
 * - This page does NOT talk directly to carriers. All carrier actions are via existing API endpoints.
 * - UI rendering is delegated to view components for maximum reusability.
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

// Load shared transfers infrastructure (includes AssetLoader)
require_once __DIR__ . '/../_shared/Autoload.php';

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

use Modules\Transfers\Stock\Lib\AccessPolicy;
use Modules\Transfers\Stock\Services\FreightCalculator;
use Modules\Transfers\Stock\Services\NotesService;
use Modules\Transfers\Stock\Services\StaffNameResolver;
use Modules\Transfers\Stock\Services\TransfersService;

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

$isPackaged = strtoupper((string)($transfer['state'] ?? '')) === 'PACKAGED';
$showCourierDetail = false; // Temporary: keep courier UI minimal (buttons only)

// --------------------------------------------------------------------------------------
// Business Logic & Data Processing
// --------------------------------------------------------------------------------------

// Include helper functions that views will need
require_once __DIR__ . '/lib/pack-helpers.php';

// Derived Transfer Fields (escaped)
$txId        = (int)($transfer['id'] ?? $transferId);
$items       = is_array($transfer['items'] ?? null) ? $transfer['items'] : [];
$fromVendId  = (string)($transfer['outlet_from_uuid'] ?? $transfer['outlet_from_meta']['id'] ?? $transfer['outlet_from'] ?? '');
$toVendId    = (string)($transfer['outlet_to_uuid']   ?? $transfer['outlet_to_meta']['id']   ?? $transfer['outlet_to']   ?? '');

$fromOutletMeta = $transfer['outlet_from_meta'] ?? $svc->getOutletMeta($fromVendId) ?? [];
$toOutletMeta   = $transfer['outlet_to_meta']   ?? $svc->getOutletMeta($toVendId)   ?? [];

// Use UUID outlet IDs instead of integers
$fromOutletId = $fromVendId; // Use the UUID directly
$toOutletId   = $toVendId;   // Use the UUID directly

// Debug outlet ID resolution
if (isset($_GET['debug']) && $_GET['debug'] === '1') {
  error_log("Pack Debug - fromVendId (UUID): $fromVendId");
  error_log("Pack Debug - fromOutletMeta: " . print_r($fromOutletMeta, true));
  error_log("Pack Debug - fromOutletId resolved to: $fromOutletId");
}

// --------------------------------------------------------------------------------------
// Draft Data Processing (Single Load Optimization)
// --------------------------------------------------------------------------------------

// Process draft data if available
$draftData = null;
$draftUpdatedAt = null;

if (!empty($transfer['draft_data'])) {
  try {
    $draftData = json_decode($transfer['draft_data'], true);
    $draftUpdatedAt = $transfer['draft_updated_at'];
    
    // Apply draft data to transfer items (merge counted quantities)
    if (is_array($draftData['counted_qty'] ?? null)) {
      foreach ($items as &$item) {
        $itemId = $item['id'] ?? null;
        if ($itemId && isset($draftData['counted_qty'][$itemId])) {
          $item['counted_qty'] = (int)$draftData['counted_qty'][$itemId];
        }
      }
      unset($item); // Clean up reference
    }
    
    // Debug draft data
    if (isset($_GET['debug']) && $_GET['debug'] === '1') {
      error_log("Pack Debug - Draft data loaded: " . print_r($draftData, true));
      error_log("Pack Debug - Draft updated at: $draftUpdatedAt");
    }
    
  } catch (Exception $e) {
    error_log("Pack Debug - Failed to parse draft data: " . $e->getMessage());
  }
}

// Get source stock levels
$sourceStockMap = [];
if ($items && !empty($fromOutletId)) {
  $productIds = [];
  foreach ($items as $itemRow) {
    $pid = (string)($itemRow['product_id'] ?? '');
    if ($pid !== '') {
      $productIds[] = $pid;
    }
  }
  if ($productIds) {
    $sourceStockMap = $svc->getSourceStockLevels($productIds, $fromOutletId);
    
    // Debug logging
    if (isset($_GET['debug']) && $_GET['debug'] === '1') {
      error_log("Pack Debug - fromOutletId: $fromOutletId");
      error_log("Pack Debug - productIds: " . implode(', ', $productIds));
      error_log("Pack Debug - sourceStockMap result: " . print_r($sourceStockMap, true));
    }
  }
}

// Build outlet labels and details
$fromRaw = tfx_first([
  $transfer['outlet_from_name'] ?? null,
  $fromOutletMeta['name'] ?? null,
  $fromOutletMeta['store_code'] ?? null,
  $fromVendId,
]);
$toRaw = tfx_first([
  $transfer['outlet_to_name'] ?? null,
  $toOutletMeta['name'] ?? null,
  $toOutletMeta['store_code'] ?? null,
  $toVendId,
]);

$fromName = tfx_first([
  $fromOutletMeta['name'] ?? null,
  $fromRaw,
]);
$toName = tfx_first([
  $toOutletMeta['name'] ?? null,
  $toRaw,
]);

$fromLbl = htmlspecialchars($fromName !== '' ? $fromName : ($fromVendId !== '' ? $fromVendId : (string)$fromOutletId), ENT_QUOTES, 'UTF-8');
$toLbl   = htmlspecialchars($toName   !== '' ? $toName   : ($toVendId   !== '' ? $toVendId   : (string)$toOutletId), ENT_QUOTES, 'UTF-8');

$fromLine = tfx_outlet_line($fromOutletMeta);
if ($fromLine === '') {
  $fromLine = sprintf('%s | %s', $fromName, $fromOutletMeta['city'] ?? '');
}
$fromLine = trim($fromLine);

$toLine = tfx_outlet_line($toOutletMeta);
if ($toLine === '') {
  $toLine = sprintf('%s | %s', $toName, $toOutletMeta['city'] ?? '');
}
$toLine = trim($toLine);

$dispatchFromOutlet = $fromName !== ''
  ? $fromName
  : ($fromOutletMeta['store_code'] ?? ($fromVendId !== '' ? substr($fromVendId, 0, 8) : sprintf('Outlet #%d', $fromOutletId ?: 0)));
$dispatchToOutlet   = $toName !== ''
  ? $toName
  : ($toOutletMeta['store_code'] ?? ($toVendId !== '' ? substr($toVendId, 0, 8) : sprintf('Outlet #%d', $toOutletId ?: 0)));

// Print pool and capabilities
$printersOnline = (int)($fromOutletMeta['printers_online'] ?? 0);
$printersTotal  = (int)($fromOutletMeta['printers_total']  ?? 0);
$printPoolOnline = $printersTotal <= 0 ? true : ($printersOnline > 0);
$printPoolMetaText = $printersTotal > 0
  ? sprintf('%d of %d printers ready', max(0, $printersOnline), max(0, $printersTotal))
  : 'Awaiting printer status';

$tokens = [
  'apiKey' => (string)(getenv('CIS_API_KEY') ?: ''),
  'nzPost' => (string)($fromOutletMeta['nz_post_api_key'] ?? $fromOutletMeta['nz_post_subscription_key'] ?? ''),
  'gss'    => (string)($fromOutletMeta['gss_token'] ?? ''),
];

$capNzPost = isset($fromOutletMeta['nz_post_enabled']) ? (bool)$fromOutletMeta['nz_post_enabled'] : ($tokens['nzPost'] !== '');
$capNzc    = isset($fromOutletMeta['nzc_enabled'])    ? (bool)$fromOutletMeta['nzc_enabled']    : ($tokens['gss'] !== '');

// Freight calculations
$freightCalculator = null;
$weightedItems      = [];
try {
  $freightCalculator = new FreightCalculator();
  $weightedItems     = $freightCalculator->getWeightedItems($txId);
} catch (Throwable $freightError) {
  $freightCalculator = null;
  $weightedItems     = [];
}

$totalWeightGrams = 0;
$totalItemUnits   = 0;
foreach ($weightedItems as $weightedRow) {
  $totalWeightGrams += max(0, (int)($weightedRow['line_weight_g'] ?? 0));
  $totalItemUnits   += max(0, (int)($weightedRow['qty'] ?? 0));
}

$freightMetrics = [
  'total_weight_grams' => $totalWeightGrams,
  'total_weight_kg'    => $totalWeightGrams > 0 ? round($totalWeightGrams / 1000, 3) : 0.0,
  'total_items'        => $totalItemUnits,
  'line_count'         => count($weightedItems),
];

$autoPlan = $freightCalculator instanceof FreightCalculator
  ? tfx_build_dispatch_autoplan($totalWeightGrams, $totalItemUnits, $freightCalculator)
  : null;

// Manual summary calculations
$manualSummaryWeightKg = max(0.0, (float)($freightMetrics['total_weight_kg'] ?? 0));
$manualSummaryWeightLabel = $manualSummaryWeightKg > 0
  ? number_format($manualSummaryWeightKg, $manualSummaryWeightKg >= 100 ? 0 : 1) . ' kg'
  : '—';
$manualSummaryBoxes = null;
if (is_array($autoPlan ?? null) && isset($autoPlan['package_count'])) {
  $manualSummaryBoxes = (int)$autoPlan['package_count'];
} elseif ($manualSummaryWeightKg > 0) {
  $manualSummaryBoxes = max(1, (int)ceil($manualSummaryWeightKg / 15));
}
$manualSummaryBoxesLabel = $manualSummaryBoxes !== null
  ? number_format($manualSummaryBoxes) . ' ' . ($manualSummaryBoxes === 1 ? 'box' : 'boxes')
  : '—';

// Timeline and notes
$timeline = [];
$currentUserName = '';
try {
  $staffResolver = new StaffNameResolver();
  $currentUserName = $staffResolver->name($userId) ?? '';

  $notesService = new NotesService();
  $notesRows = $notesService->listTransferNotes($txId);
  foreach ($notesRows as $noteRow) {
    $authorId = (int)($noteRow['created_by'] ?? 0);
    $authorName = $staffResolver->name($authorId) ?? ($authorId > 0 ? sprintf('User #%d', $authorId) : 'System');

    $timeline[] = [
      'id'   => (int)($noteRow['id'] ?? 0),
      'scope'=> 'note',
      'text' => (string)($noteRow['note_text'] ?? ''),
      'ts'   => (string)($noteRow['created_at'] ?? ''),
      'user' => $authorName,
    ];
  }
} catch (Throwable $timelineError) {
  $timeline = [];
}

$createdStamp = $transfer['created_at'] ?? null;
if ($createdStamp) {
  $timeline[] = [
    'id'      => 0,
    'scope'   => 'system',
    'text'    => sprintf('Transfer #%d created', $txId),
    'ts'      => (string)$createdStamp,
    'user'    => 'System',
    'persist' => true,
  ];
}

// Filter and sort timeline
$timeline = array_values(array_filter($timeline, static function (array $entry): bool {
  return ($entry['persist'] ?? false) === true;
}));

if (!$timeline) {
  $timeline[] = [
    'id'      => 0,
    'scope'   => 'system',
    'text'    => sprintf('Transfer #%d created', $txId),
    'ts'      => (string)($createdStamp ?: date('Y-m-d H:i:s')),
    'user'    => 'System',
    'persist' => true,
  ];
}

usort($timeline, static function (array $a, array $b): int {
  $ta = strtotime((string)($a['ts'] ?? '')) ?: 0;
  $tb = strtotime((string)($b['ts'] ?? '')) ?: 0;
  return $ta <=> $tb;
});

// Build boot payload for JS
$bootPayload = [
  'transferId'   => $txId,
  'fromOutletId' => $fromOutletId,
  'toOutletId'   => $toOutletId,
  'fromOutletVendId' => $fromVendId,
  'toOutletVendId'   => $toVendId,
  'fromOutlet'   => $fromName,
  'toOutlet'     => $toName,
  'fromLine'     => $fromLine,
  'capabilities' => [
    'carriers' => [
      'nzpost' => $capNzPost,
      'nzc'    => $capNzc,
    ],
    'printPool' => [
      'online'      => $printPoolOnline,
      'onlineCount' => $printersOnline,
      'totalCount'  => $printersTotal,
    ],
  ],
  'tokens'    => $tokens,
  'endpoints' => [
    'rates'          => '/modules/transfers/stock/api/rates.php',
    'create'         => '/modules/transfers/stock/api/create_label.php',
    'address_facts'  => '/modules/transfers/stock/api/address_facts.php',
    'print_pool'     => '/modules/transfers/stock/api/print_pool_status.php',
    'save_pickup'    => '/modules/transfers/stock/api/save_pickup.php',
    'save_internal'  => '/modules/transfers/stock/api/save_internal.php',
    'save_dropoff'   => '/modules/transfers/stock/api/save_dropoff.php',
    'notes_add'      => '/modules/transfers/stock/api/notes_add.php',
  ],
  'metrics'   => $freightMetrics,
  'ui'        => [
    'showCourierDetail' => $showCourierDetail,
  ],
  'timeline'  => $timeline,
  'sourceStockMap' => $sourceStockMap,
  'currentUser' => [
    'id'   => $userId,
    'name' => $currentUserName,
  ],
];

if ($autoPlan !== null) {
  $bootPayload['autoplan'] = $autoPlan;
}

// --------------------------------------------------------------------------------------
// Render View
// --------------------------------------------------------------------------------------

// Prepare initial draft data for JavaScript (single load optimization)
$initialDraftData = [];
if ($draftData && $draftUpdatedAt) {
  $initialDraftData = [
    'counted_qty' => $draftData['counted_qty'] ?? [],
    'added_products' => $draftData['added_products'] ?? [],
    'removed_items' => $draftData['removed_items'] ?? [],
    'courier_settings' => $draftData['courier_settings'] ?? [],
    'notes' => $draftData['notes'] ?? '',
    'saved_at' => $draftUpdatedAt
  ];
}

// Load templates
include $DOCUMENT_ROOT . '/assets/template/html-header.php';
include $DOCUMENT_ROOT . '/assets/template/header.php';

// Pass initial draft data to JavaScript (avoid startup queries)
echo '<script type="application/json" id="initialDraftData">' . json_encode($initialDraftData, JSON_UNESCAPED_UNICODE) . '</script>' . "\n";

// Render the main pack view using components
include __DIR__ . '/views/pack.view.php';