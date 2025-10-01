<?php
declare(strict_types=1);
/**
 * Product Search API
 * Supports new POST JSON contract and legacy GET fallback for existing JS.
 * New Request (preferred): POST JSON { q:string, limit?:int, outlet_id?:int }
 * New Response: { ok:bool, request_id, data?:{ products:[...] }, error?:{code,message} }
 * Legacy GET (temporary): /product_search.php?q=TERM[&limit=25] returns { success:bool, products:[], error? }
 * TODO: Remove legacy shape once front-end fully migrated.
 */

use Modules\Transfers\Stock\Services\ProductSearchService;

require_once $_SERVER['DOCUMENT_ROOT'].'/modules/transfers/_local_shims.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/app.php';
header('Content-Type: application/json; charset=utf-8');
$reqId = bin2hex(random_bytes(8));

function respond(array $payload, int $code = 200): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

// ---------------- Legacy GET Fallback ----------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && isset($_GET['q'])) {
  $q = (string)($_GET['q'] ?? '');
  $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 25;
  $outletId = isset($_GET['outlet_id']) ? (int)$_GET['outlet_id'] : null;
  try {
    $svc = new ProductSearchService();
    $res = $svc->search($q,$limit,$outletId,false);
    if (!($res['success'] ?? false)) {
      echo json_encode(['success'=>false,'error'=>'search_fail'], JSON_UNESCAPED_SLASHES); exit;
    }
    // Legacy shape uses 'products' directly
    echo json_encode(['success'=>true,'products'=>$res['products']], JSON_UNESCAPED_SLASHES); exit;
  } catch (Throwable $e) {
    error_log('product_search legacy GET error: '.$e->getMessage());
    echo json_encode(['success'=>false,'error'=>'exception'], JSON_UNESCAPED_SLASHES); exit;
  }
}

// ---------------- New POST Contract ----------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  respond(['ok'=>false,'request_id'=>$reqId,'error'=>['code'=>'METHOD_NOT_ALLOWED','message'=>'POST required']],405);
}

$raw = file_get_contents('php://input');
if ($raw === false) {
  respond(['ok'=>false,'request_id'=>$reqId,'error'=>['code'=>'IO_ERROR','message'=>'Failed to read input']],400);
}
$in = json_decode($raw,true);
if (!is_array($in)) {
  respond(['ok'=>false,'request_id'=>$reqId,'error'=>['code'=>'BAD_JSON','message'=>'Invalid JSON body']],400);
}

$q = isset($in['q']) ? (string)$in['q'] : '';
$limit = isset($in['limit']) ? (int)$in['limit'] : 25;
$outletId = isset($in['outlet_id']) ? (int)$in['outlet_id'] : null;

// Basic rate limit (60/min per session/IP)
$rlKey = 'ps_api_rl_'.($_SERVER['REMOTE_ADDR'] ?? 'x');
if (!isset($_SESSION)) session_start();
$now = time();
if (!isset($_SESSION[$rlKey])) { $_SESSION[$rlKey] = []; }
// prune entries older than 60s
$_SESSION[$rlKey] = array_filter((array)$_SESSION[$rlKey], fn($ts)=> ($ts + 60) > $now);
if (count($_SESSION[$rlKey]) >= 60) {
  respond(['ok'=>false,'request_id'=>$reqId,'error'=>['code'=>'RATE_LIMIT','message'=>'Too many searches, slow down']],429);
}
$_SESSION[$rlKey][] = $now;

try {
  $svc = new ProductSearchService();
  $res = $svc->search($q,$limit,$outletId,false);
  if (!$res['success']) {
    respond(['ok'=>false,'request_id'=>$reqId,'error'=>['code'=>'SEARCH_FAIL','message'=>'Search failed']],500);
  }
  $products = $res['products'];
  // Normalize output shape
  $out = [];
  foreach ($products as $p) {
    $out[] = [
      'id' => $p['id'],
      'name' => $p['name'],
      'variant' => $p['variant'],
      'sku' => $p['sku'],
      'handle' => $p['handle'],
      'brand' => $p['brand'],
      'price' => $p['price'],
      'rrp' => $p['rrp'],
      'image_url' => $p['image_url'],
      'stock_qty' => $p['stock_qty'],
    ];
  }
  respond(['ok'=>true,'request_id'=>$reqId,'data'=>['products'=>$out]]);
} catch (Throwable $e) {
  error_log('product_search.php error: '.$e->getMessage());
  respond(['ok'=>false,'request_id'=>$reqId,'error'=>['code'=>'EXCEPTION','message'=>'Internal error']],500);
}
