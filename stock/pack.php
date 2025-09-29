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
// Safe Rendering Helpers
// --------------------------------------------------------------------------------------
/**
 * Clean arbitrary mixed->string without HTML; flatten JSON-ish text if detected.
 */
function tfx_clean_text(mixed $value): string
{
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
function tfx_first(array $candidates): string
{
  foreach ($candidates as $c) {
    $t = tfx_clean_text($c);
    if ($t !== '') return $t;
  }
  return '';
}

/** Render product cell HTML (escaped). */
function tfx_render_product_cell(array $item): string
{
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

function tfx_outlet_line(array $outlet): string
{
  $parts = array_filter([
    $outlet['name']   ?? null,
    $outlet['city']   ?? null,
    $outlet['postcode'] ?? null,
    $outlet['country']  ?? 'New Zealand',
    $outlet['phone']    ?? null,
    $outlet['email']    ?? null,
  ], static function ($value): bool {
    return $value !== null && trim((string)$value) !== '';
  });

  return $parts ? implode(' | ', array_map(static fn($v) => trim((string)$v), $parts)) : '';
}

/**
 * Build an automatic package plan for the Dispatch console based on the aggregate transfer weight.
 *
 * @param int               $totalWeightGrams Approximate total consignment weight in grams (goods only).
 * @param int               $totalItems       Total item count being shipped.
 * @param FreightCalculator $freight          Freight calculator instance for capacity helpers.
 *
 * @return array<string,mixed>|null Structure describing the preferred container type and package breakdown.
 */
function tfx_build_dispatch_autoplan(int $totalWeightGrams, int $totalItems, FreightCalculator $freight): ?array
{
  $totalWeightGrams = max(0, $totalWeightGrams);
  $totalItems       = max(0, $totalItems);

  if ($totalWeightGrams === 0 && $totalItems === 0) {
    return null;
  }

  $totalWeightKg = $totalWeightGrams / 1000;

  // Heuristic: satchels for lighter consignments, boxes otherwise (provide extra headroom below 15 kg cap).
  $preferSatchel = $totalWeightKg <= 12.0 && $totalItems <= 20;
  $container     = $preferSatchel ? 'satchel' : 'box';
  $capKg         = $container === 'satchel' ? 15.0 : 25.0;
  $tareReserveKg = $container === 'satchel' ? 0.25 : 2.5;
  $goodsCapKg    = max(1.0, $capKg - $tareReserveKg);
  $capGrams      = (int)round($goodsCapKg * 1000);

  $splitWeights = $freight->planParcelsByCap($totalWeightGrams > 0 ? $totalWeightGrams : 1, $capGrams);
  if (!$splitWeights) {
    $splitWeights = [$totalWeightGrams > 0 ? $totalWeightGrams : 1000];
  }

  $packageCount   = count($splitWeights);
  $baseItemsPer   = $packageCount > 0 ? intdiv($totalItems, $packageCount) : 0;
  $itemsRemainder = $packageCount > 0 ? $totalItems - ($baseItemsPer * $packageCount) : 0;

  $presetMeta = [
    'nzp_s' => ['w' => 22, 'l' => 31, 'h' => 4],
    'nzp_m' => ['w' => 28, 'l' => 39, 'h' => 4],
    'nzp_l' => ['w' => 33, 'l' => 42, 'h' => 4],
    'vs_m'  => ['w' => 30, 'l' => 40, 'h' => 20],
    'vs_l'  => ['w' => 35, 'l' => 45, 'h' => 25],
    'vs_xl' => ['w' => 40, 'l' => 50, 'h' => 30],
  ];

  $packages = [];
  foreach ($splitWeights as $index => $grams) {
    $goodsWeightKg = round(max(0, $grams) / 1000, 3);
    $presetId      = tfx_pick_autoplan_preset($container, $goodsWeightKg);
    $tareKg        = tfx_autoplan_tare($presetId);
    $shipWeightKg  = round($goodsWeightKg + $tareKg, 3);
    $itemsForBox   = $baseItemsPer + ($index < $itemsRemainder ? 1 : 0);

    $dims = $presetMeta[$presetId] ?? ($container === 'box'
      ? ['w' => 30, 'l' => 40, 'h' => 20]
      : ['w' => 28, 'l' => 39, 'h' => 4]);

    $packages[] = [
      'sequence'        => $index + 1,
      'preset_id'       => $presetId,
      'goods_weight_kg' => $goodsWeightKg,
      'ship_weight_kg'  => $shipWeightKg,
      'items'           => $itemsForBox,
      'dimensions'      => $dims,
    ];
  }

  return [
    'source'             => 'auto',
    'shouldHydrate'      => true,
    'container'          => $container,
    'cap_kg'             => $capKg,
    'goods_cap_kg'       => $goodsCapKg,
    'package_count'      => $packageCount,
    'total_weight_kg'    => round($totalWeightKg, 3),
    'total_weight_grams' => $totalWeightGrams,
    'total_items'        => $totalItems,
    'packages'           => $packages,
  ];
}

/**
 * Pick a default preset identifier for the calculated parcel weight.
 */
function tfx_pick_autoplan_preset(string $container, float $weightKg): string
{
  $weightKg = max(0, $weightKg);
  if ($container === 'box') {
    if ($weightKg > 20.0) return 'vs_xl';
    if ($weightKg > 14.0) return 'vs_l';
    return 'vs_m';
  }

  if ($weightKg > 9.0) return 'nzp_l';
  if ($weightKg > 5.0) return 'nzp_m';
  return 'nzp_s';
}

/**
 * Known tare weights for courier packaging presets (kg).
 */
function tfx_autoplan_tare(string $presetId): float
{
  return match ($presetId) {
    'nzp_s' => 0.15,
    'nzp_m' => 0.20,
    'nzp_l' => 0.25,
    'vs_m'  => 2.0,
    'vs_l'  => 2.5,
    'vs_xl' => 3.1,
    default => 0.0,
  };
}

// --------------------------------------------------------------------------------------
// Derived Transfer Fields (escaped)
// --------------------------------------------------------------------------------------
$txId        = (int)($transfer['id'] ?? $transferId);
$items       = is_array($transfer['items'] ?? null) ? $transfer['items'] : [];
$fromVendId  = (string)($transfer['outlet_from_uuid'] ?? $transfer['outlet_from_meta']['id'] ?? $transfer['outlet_from'] ?? '');
$toVendId    = (string)($transfer['outlet_to_uuid']   ?? $transfer['outlet_to_meta']['id']   ?? $transfer['outlet_to']   ?? '');

$fromOutletMeta = $transfer['outlet_from_meta'] ?? $svc->getOutletMeta($fromVendId) ?? [];
$toOutletMeta   = $transfer['outlet_to_meta']   ?? $svc->getOutletMeta($toVendId)   ?? [];

$fromOutletId = (int)($transfer['outlet_from'] ?? ($fromOutletMeta['website_outlet_id'] ?? 0));
$toOutletId   = (int)($transfer['outlet_to']   ?? ($toOutletMeta['website_outlet_id']   ?? 0));
$sourceStockMap = [];
if ($items && $fromOutletId > 0) {
  $productIds = [];
  foreach ($items as $itemRow) {
    $pid = (int)($itemRow['product_id'] ?? 0);
    if ($pid > 0) {
      $productIds[] = $pid;
    }
  }
  if ($productIds) {
    $sourceStockMap = $svc->getSourceStockLevels($productIds, $fromOutletId);
  }
}

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
$timeline = array_values(array_reduce($timeline, static function (array $carry, array $entry): array {
  $key = sprintf('%s|%s|%s', (string)($entry['scope'] ?? ''), (string)($entry['id'] ?? ''), (string)($entry['ts'] ?? ''));
  $carry[$key] = $entry;
  return $carry;
}, []));
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
        <?php if ($isPackaged): ?>
        <section class="alert alert-warning border-warning bg-white shadow-sm mb-4" role="status" aria-live="polite">
          <div class="d-flex align-items-start" style="gap:12px;">
            <i class="fa fa-exclamation-triangle text-warning" aria-hidden="true" style="font-size:1.4rem; padding-top:2px;"></i>
            <div>
              <h2 class="h5 mb-2 text-warning" style="font-weight:700;">Heads up: this transfer is in <span class="text-uppercase">PACKAGED</span> mode</h2>
              <p class="mb-2 text-muted">“Mark as Packed” already ran. You can still make last-minute edits, but dispatch isn’t locked until you send it.</p>
              <ul class="mb-2 pl-3">
                <li>Adjusting counts, parcels, or notes will update the existing packed shipment record.</li>
                <li>No data has been pushed to Lightspeed/Vend yet; that only happens when you mark it as sent.</li>
                <li>Accidental sends can’t be undone here—grab Ops if you need a rollback before dispatch.</li>
              </ul>
              <p class="mb-0"><strong>Ready to hand over?</strong> Use “Mark as Packed &amp; Send” from the Pack console when the consignment is actually leaving.</p>
            </div>
          </div>
        </section>
        <?php endif; ?>

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
                        <th scope="col">Qty in stock</th>
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
                          $iid         = (int)($i['id'] ?? 0);
                          $productId   = (int)($i['product_id'] ?? 0);
                          $planned     = max(0, (int)($i['qty_requested'] ?? 0));
                          $sentSoFar   = max(0, (int)($i['qty_sent_total'] ?? 0));
                          $stockOnHand = $productId > 0 ? max(0, (int)($sourceStockMap[$productId] ?? 0)) : null;
                          $inventory   = max($planned, $sentSoFar, $stockOnHand ?? 0);
                          if ($planned <= 0) continue;

                          echo '<tr data-product-id="' . $productId . '" data-inventory="' . $inventory . '" data-planned="' . $planned . '"' . ($stockOnHand !== null ? ' data-stock="' . $stockOnHand . '"' : '') . '>';
                          echo "<td class='text-center align-middle'>
                                  <button class='tfx-remove-btn' type='button' data-action='remove-product' aria-label='Remove product' title='Remove product'>
                                    <i class='fa fa-times' aria-hidden='true'></i>
                                  </button>
                                  <input type='hidden' class='productID' value='{$iid}'>
                                </td>";
                          echo '<td>' . tfx_render_product_cell($i) . '</td>';
                          echo '<td class="planned">' . $planned . '</td>';
                          $stockLabel = $stockOnHand !== null ? number_format($stockOnHand) : '&mdash;';
                          echo '<td class="stock">' . $stockLabel . '</td>';
                          echo "<td class='counted-td'>
                                  <input type='number' min='0' max='{$inventory}' value='" . ($sentSoFar ?: '') . "' class='form-control form-control-sm tfx-num' inputmode='numeric' aria-label='Counted quantity'>
                                  <span class='counted-print-value d-none'>" . ($sentSoFar ?: 0) . "</span>
                                </td>";
                          echo '<td>' . $toLbl . '</td>';
                          echo '<td><span class="id-counter">' . $txId . '-' . $row . '</span></td>';
                          echo '</tr>';
                        }
                      } else {
                        echo '<tr><td colspan="7" class="text-center text-muted py-4">No items on this transfer.</td></tr>';
                      }
                      ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
             </div>
             </section>

            <!-- ====================== PACK & SHIP — INLINE (HARDENED) ====================== -->
            <?php
            // Resolve minimal context used by the ship UI
            $PS_TID  = $txId;
            $PS_FROM = $fromLbl;
            $PS_TO   = $toLbl;
            ?>
            <section id="psx-app" class="psx<?= $showCourierDetail ? '' : ' psx-manual-mode' ?>" aria-label="Pack & Ship Panel">
                      <?php // Dispatch Console (View) ?>

  <link rel="stylesheet" href="https://staff.vapeshed.co.nz/modules/transfers/stock/assets/css/dispatch.css?v=1.2">
  <style>
    .psx .psx-manual-summary {
      border: 1px solid var(--line);
      border-radius: 12px;
      background: linear-gradient(135deg, #f7f7fb, #ffffff);
      padding: 14px 16px;
      margin-bottom: 16px;
    }
    .psx .psx-summary-label {
      font-weight: 700;
      letter-spacing: 0.08em;
    }
    .psx .psx-summary-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
      gap: 12px;
      margin: 6px 0 0;
    }
    .psx .psx-summary-row {
      display: flex;
      flex-direction: column;
      font-size: 0.9rem;
    }
    .psx .psx-summary-key {
      font-size: 0.75rem;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      margin-bottom: 2px;
    }
    .psx .psx-summary-val {
      font-weight: 600;
      color: #1f365c;
    }
    .psx .psx-comment-form {
      margin-top: 12px;
    }
    .psx .psx-comment-grid {
      display: grid;
      grid-template-columns: 1fr minmax(140px, 160px) auto;
      gap: 10px;
      align-items: center;
    }
    @media (max-width: 768px) {
      .psx .psx-comment-grid {
        grid-template-columns: 1fr;
      }
    }
    .psx .psx-note-input {
      border-radius: 10px;
      border: 1px solid rgba(31, 54, 92, 0.18);
      box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.04);
    }
    .psx .psx-note-input:focus {
      border-color: #4664d8;
      box-shadow: 0 0 0 0.2rem rgba(70, 100, 216, 0.2);
    }
    .psx .psx-note-btn {
      border-radius: 999px;
      padding-left: 20px;
      padding-right: 20px;
      font-weight: 600;
      box-shadow: 0 6px 12px rgba(71, 98, 209, 0.25);
    }
    .wrapper {
      width: 100%;
      max-width: 100%;
      margin: 18px 0;
      padding: 0;
    }
    .hrow-main {
      align-items: flex-start;
      gap: 18px;
      padding: 22px 24px 16px;
    }
    .title-block {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }
    .title-block h1 {
      margin: 0;
      font-size: 24px;
      font-weight: 700;
      line-height: 1.25;
      color: #111831;
    }
    .title-block h1 .mono {
      font-size: 20px;
      letter-spacing: 0.05em;
      color: #394266;
    }
    .title-block h1 .dest-text {
      color: #1d2ed8;
    }
    .title-block .subtitle {
      font-size: 14px;
      color: #3b4b70;
      font-weight: 500;
    }
    .title-block .subtitle span {
      font-weight: 600;
    }
    .contact-line {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
      align-items: center;
      font-size: 12px;
      color: #66738f;
    }
    .contact-line .label {
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: #4f5d86;
    }
    .contact-line .value {
      font-weight: 500;
      color: #212a4a;
    }
    .contact-line .divider {
      opacity: 0.6;
      font-weight: 700;
      color: #7a85a5;
    }
    .status-block {
      display: flex;
      flex-direction: column;
      align-items: flex-end;
      gap: 6px;
      padding-top: 4px;
    }
    .status-block .status-meta {
      font-size: 12px;
      color: #6a7491;
    }
    .hrow-meta {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 20px;
      padding: 14px 24px;
      border-top: 1px solid var(--line);
      background: linear-gradient(90deg, #f4f6ff, #f9faff);
    }
    .meta-chips {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
    }
    .chip {
      display: flex;
      flex-direction: column;
      gap: 4px;
      padding: 10px 14px;
      border-radius: 12px;
      border: 1px solid var(--line);
      background: #ffffff;
      min-width: 150px;
    }
    .chip .label {
      font-size: 10px;
      text-transform: uppercase;
      letter-spacing: 0.1em;
      color: #7a85a5;
      font-weight: 700;
    }
    .chip .value {
      font-size: 15px;
      font-weight: 600;
      color: #1a2550;
    }
    .chip-primary {
      background: linear-gradient(135deg, rgba(229,235,255,0.9), rgba(255,255,255,0.95));
      border-color: rgba(119, 134, 255, 0.4);
      box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.5);
    }
    .chip-primary .value {
      color: #1b2bdd;
    }
    .hrow-meta .tnav {
      margin-left: auto;
    }
    .hcard-body {
      padding: 0 24px 20px;
    }
    .psx.psx-manual-mode .psx-parcel-card > header,
    .psx.psx-manual-mode .psx-parcel-table,
    .psx.psx-manual-mode .psx-parcel-tools,
    .psx.psx-manual-mode .psx-parcel-switch,
    .psx.psx-manual-mode .psx-capacity-info {
      display: none !important;
    }
    .psx.psx-manual-mode .psx-slip-preview-wrap {
      grid-column: 1 / -1;
    }
    .psx.psx-manual-mode .psx-capacity-card {
      border-style: solid;
      background: linear-gradient(135deg, #f7f8ff, #ffffff);
    }
  </style>

  <div class="wrapper">
    <!-- HEADER & BODY -->
    <div class="hcard">
      <div class="hrow hrow-main">
        <div class="brand">
          <div class="logo" aria-hidden="true"></div>
          <div class="title-block">
            <h1>
              Transfer <span class="mono">#<?= (int)$txId ?></span>
              → <span class="dest-text" id="headDestination"><?= htmlspecialchars($dispatchToOutlet, ENT_QUOTES, 'UTF-8') ?></span>
            </h1>
            <div class="subtitle">
              Origin: <span id="headFrom"><?= htmlspecialchars($dispatchFromOutlet, ENT_QUOTES, 'UTF-8') ?></span>
              · Your role: <span id="headRole">Warehouse</span>
            </div>
            <div class="contact-line">
              <span class="label">Dispatching from:</span>
              <span class="value" id="fromLine"><?= htmlspecialchars($fromLine, ENT_QUOTES, 'UTF-8') ?></span><br>
              <span class="divider" aria-hidden="true">→</span>
              <span class="label">Recipient:</span>
              <span class="value" id="toLine"><?= htmlspecialchars($toLine !== '' ? $toLine : $dispatchToOutlet, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
          </div>
        </div>
        <div class="status-block" aria-label="Print pool status"<?= $showCourierDetail ? '' : ' hidden'; ?>>
          <span class="pstat" id="printPoolStatus">
            <span class="dot <?= $printPoolOnline ? 'ok' : 'err' ?>" id="printPoolDot"></span>
            <span id="printPoolText"><?= htmlspecialchars($printPoolOnline ? 'Print pool online' : 'Print pool offline', ENT_QUOTES, 'UTF-8') ?></span>
          </span>
          <span class="status-meta" id="printPoolMeta"><?= htmlspecialchars($printPoolMetaText, ENT_QUOTES, 'UTF-8') ?></span>
          <button class="btn small" id="btnSettings" type="button">Settings</button>
        </div>
      </div>
      <div class="hrow hrow-meta">
        <div class="meta-chips">
          <div class="chip chip-primary" aria-label="Destination outlet">
            <span class="label">Destination</span>
            <span class="value" id="toOutlet"><?= htmlspecialchars($dispatchToOutlet, ENT_QUOTES, 'UTF-8') ?></span>
          </div>
          <div class="chip">
            <span class="label">Origin</span>
            <span class="value" id="fromOutlet"><?= htmlspecialchars($dispatchFromOutlet, ENT_QUOTES, 'UTF-8') ?></span>
          </div>
          <div class="chip">
            <span class="label">Transfer</span>
            <span class="value mono">#<?= (int)$txId ?></span>
          </div>
          <div class="chip">
            <span class="label">Your role</span>
            <span class="value">Warehouse</span>
          </div>
        </div>
        <nav class="tnav" aria-label="Method">
          <a href="#" class="tab" data-method="courier" aria-current="page">Courier</a>
          <a href="#" class="tab" data-method="pickup">Pickup</a>
          <a href="#" class="tab" data-method="internal">Internal</a>
          <a href="#" class="tab" data-method="dropoff">Drop-off</a>
        </nav>
      </div>
    </div>

      <div class="hcard-body">
        <!-- GRID -->
        <div class="grid">
      <!-- LEFT -->
      <section class="card psx-parcel-card" aria-label="Parcels">
        <header>
          <div class="hdr">Mode</div>
          <div class="psx-parcel-tools" style="display:flex;gap:8px;align-items:center">
            <div class="switch psx-parcel-switch" role="group" aria-label="Container type">
              <button class="tog" id="btnSatchel" aria-pressed="true" type="button">Satchel</button>
              <button class="tog" id="btnBox" aria-pressed="false" type="button">Box</button>
            </div>
            <select id="preset" class="btn small" aria-label="Package preset"></select>
            <button class="btn small js-add"  type="button">Add</button>
            <button class="btn small js-copy" type="button">Copy</button>
            <button class="btn small js-clear" type="button">Clear</button>
            <button class="btn small js-auto"  type="button" title="Auto-assign products">Auto</button>
          </div>
        </header>
        <div class="body psx-parcel-body">
          <table class="psx-parcel-table" aria-label="Parcels table">
            <thead><tr>
              <th>#</th><th>Name</th><th>W x L x H (cm)</th><th>Weight</th><th>Items</th><th class="num"></th>
            </tr></thead>
            <tbody id="pkgBody"></tbody>
          </table>

          <div class="card psx-capacity-card" style="border:1px dashed var(--line);margin-top:12px">
            <div class="body">
                <div class="psx-capacity-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div class="psx-capacity-info">
                  <div class="sub" style="margin-bottom:6px">Capacity (25 kg boxes, 15 kg satchels)</div>
                  <div id="meters" style="display:grid;gap:8px"></div>
                </div>
                <div class="psx-slip-preview-wrap">
                  <div class="slip-head">
                    <div class="sub">Slip Preview</div>
                    <div class="slip-actions">
                      <button class="btn small" id="btnSlipPrint" type="button">Print slip</button>
                    </div>
                  </div>
                  <div class="slip slip-grid" aria-live="polite">
                    <div class="slip-col slip-col-preview">
                      <div class="rule"></div>
                      <div class="big mono">TRANSFER #<span id="slipT"><?= (int)$txId ?></span></div>
                      <div class="mono">FROM: <b id="slipFrom"><?= htmlspecialchars($dispatchFromOutlet, ENT_QUOTES, 'UTF-8') ?></b></div>
                      <div class="mono">TO:&nbsp;&nbsp; <b id="slipTo"><?= htmlspecialchars($dispatchToOutlet, ENT_QUOTES, 'UTF-8') ?></b></div>
                      <div class="mono" style="margin-top:6px">BOX <span id="slipBox">1</span> of
                        <input id="slipTotal" class="pn" type="number" min="1" value="1" style="width:70px" aria-label="Total boxes"></div>
                      <div class="rule"></div>
                    </div>
                    <div class="slip-col slip-col-tracking" id="manualTrackingWrap" hidden>
                      <div class="sub" style="margin-bottom:2px">Tracking codes / URLs</div>
                      <div class="tracking-input-row">
                        <input id="trackingInput" class="form-control form-control-sm" placeholder="Paste tracking code or URL" autocomplete="off" aria-label="Tracking code or URL">
                        <button class="btn small" id="trackingAdd" type="button">Add</button>
                      </div>
                      <ul class="tracking-list" id="trackingList" aria-live="polite" aria-label="Tracking references"></ul>
                      <div class="tracking-empty" id="trackingEmpty" aria-hidden="true">No tracking references yet.</div>
                      <div class="sub" style="font-size:11px;">These references print on the manual slip when the courier label is handled externally.</div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <h3 style="margin:12px 0 6px">Activity & Comments</h3>
          <div class="feed" id="activityFeed" aria-live="polite"></div>
          <form id="commentForm" class="psx-comment-form">
            <div class="psx-comment-grid">
              <input id="commentText" class="form-control form-control-sm psx-note-input" placeholder="Add a note… (saved to history)" autocomplete="off">
              <select id="commentScope" class="form-control form-control-sm">
                <option value="shipment">Shipment</option>
              </select>
              <button class="btn btn-primary btn-sm psx-note-btn" type="submit">Add note</button>
            </div>
          </form>
        </div>
      </section>

      <!-- RIGHT -->
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
            <div style="display:flex;gap:12px;flex-wrap:wrap"<?= $showCourierDetail ? '' : ' hidden'; ?>>
              <label><input type="checkbox" id="optSig" checked> Sig</label>
              <label><input type="checkbox" id="optATL"> ATL</label>
              <label title="R18 disabled for B2B"><input type="checkbox" id="optAge" disabled> R18</label>
              <label><input type="checkbox" id="optSat"> Saturday</label>
            </div>

            <!-- Reviewed By appears only in manual / pickup / internal / drop-off -->
            <div id="reviewedWrap" style="display:none">
              <label class="w-100 mb-0">Reviewed By
                <input id="reviewedBy" class="form-control form-control-sm" placeholder="Staff initials / name">
              </label>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;background:#fcfdff;border:1px solid var(--line);border-radius:12px;padding:8px"<?= $showCourierDetail ? '' : ' hidden'; ?>>
              <div><b>Address facts</b>
                <div class="sub">Rural: <span id="factRural" class="mono">—</span></div>
                <div class="sub">Saturday serviceable: <span id="factSat" class="mono">—</span></div>
              </div>
              <div><b>Notes</b><div class="sub">Saturday auto-disables if address isn’t serviceable.</div></div>
            </div>
          </div>

          <!-- Courier rates -->
          <div id="blkCourier">
            <?php
            if (!$showCourierDetail) {
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
              $manualSummaryFrom = htmlspecialchars($dispatchFromOutlet, ENT_QUOTES, 'UTF-8');
              $manualSummaryTo   = htmlspecialchars($dispatchToOutlet, ENT_QUOTES, 'UTF-8');
            }
            ?>
            <?php if (!$showCourierDetail): ?>
            <div class="card border-0 shadow-sm mb-3" style="background:linear-gradient(135deg,#f9fafc,#fff);">
              <div class="card-body py-3">
                <div class="psx-manual-summary">
                  <div class="text-uppercase text-secondary small psx-summary-label">Transfer summary</div>
                  <div class="psx-summary-grid">
                    <div class="psx-summary-row">
                      <span class="psx-summary-key text-muted">From</span>
                      <span class="psx-summary-val"><?= $manualSummaryFrom ?></span>
                    </div>
                    <div class="psx-summary-row">
                      <span class="psx-summary-key text-muted">To</span>
                      <span class="psx-summary-val"><?= $manualSummaryTo ?></span>
                    </div>
                    <div class="psx-summary-row">
                      <span class="psx-summary-key text-muted">Transfer</span>
                      <span class="psx-summary-val">#<?= (int)$txId ?></span>
                    </div>
                    <div class="psx-summary-row">
                      <span class="psx-summary-key text-muted">Estimated weight</span>
                      <span class="psx-summary-val"><?= $manualSummaryWeightLabel ?></span>
                    </div>
                    <div class="psx-summary-row">
                      <span class="psx-summary-key text-muted">Estimated boxes required</span>
                      <span class="psx-summary-val"><?= $manualSummaryBoxesLabel ?></span>
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
            <div class="rates" id="ratesList"<?= $showCourierDetail ? '' : ' hidden'; ?>></div>
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
            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:10px">
              <?php if ($showCourierDetail): ?>
              <button class="btn" id="btnPrintOnly" type="button">Print only</button>
              <?php endif; ?>
              <button class="btn primary" id="btnPrintPacked" type="button"><?= $showCourierDetail ? 'Print &amp; Mark Packed' : 'Mark as Packed' ?></button>
            </div>
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

          <!-- Other methods -->
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

          <div id="blkManual" hidden>
            <div class="hdr" style="margin:6px 0">Manual Tracking</div>
            <div style="display:grid;gap:8px">
              <label class="mb-0 w-100">Carrier
                <select id="mtCarrier" class="form-control form-control-sm"><option>NZ Post</option><option>NZ Couriers</option></select>
              </label>
              <label class="mb-0 w-100">Tracking #
                <input id="mtTrack" class="form-control form-control-sm" placeholder="Ticket / tracking number">
              </label>
            </div>
            <div style="display:flex;justify-content:flex-end;margin-top:10px">
              <button class="btn primary" id="btnSaveManual" type="button">Save Number</button>
            </div>
          </div>
        </div>
      </aside>
        </div> <!-- /.grid -->
      </div> <!-- /.hcard-body -->
    </div> <!-- /.hcard -->
  </div> <!-- /.wrapper -->


  <!-- Boot payload for JS -->
  <script>
  window.DISPATCH_BOOT = <?= json_encode($bootPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  </script>
  <script src="https://staff.vapeshed.co.nz/modules/transfers/stock/assets/js/dispatch.js?v=1.2"></script>

            </section>
          </div>

          <!-- Page Assets (kept — page remains usable if any fail; terminal shows warnings) -->
          <link rel="stylesheet" href="/assets/css/stock-transfers/transfers-common.css?v=1">
          <link rel="stylesheet" href="/assets/css/stock-transfers/transfers-pack.css?v=1">
<!--           <link rel="stylesheet" href="/assets/css/stock-transfers/shii.css?v=1"> -->
          <link rel="stylesheet" href="/assets/css/stock-transfers/transfers-pack-inline.css?v=1">

          <script src="/assets/js/stock-transfers/transfers-common.js?v=1" defer></script>
          <script src="/assets/js/stock-transfers/transfers-pack.js?v=1" defer></script>
      <script src="/assets/js/stock-transfers/ship-ui.js?v=1" defer></script>
    </main>
  </div>

  <?php include $DOCUMENT_ROOT . '/assets/template/html-footer.php'; ?>
  <?php include $DOCUMENT_ROOT . '/assets/template/personalisation-menu.php'; ?>
  <?php include $DOCUMENT_ROOT . '/assets/template/footer.php'; ?>


  <script src="/assets/js/stock-transfers/pack-draft-status.js?v=1" defer></script>
  <script src="/assets/js/stock-transfers/pack-product-search.js?v=1" defer></script>
</body>

</html>