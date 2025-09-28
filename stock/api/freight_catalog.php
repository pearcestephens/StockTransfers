<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'].'/app.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/assets/functions/ApiResponder.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/assets/functions/HttpGuard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/assets/functions/shipping/OutletRepo.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/modules/transfers/stock/lib/AccessPolicy.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/modules/transfers/stock/services/FreightCalculator.php';

use Modules\Transfers\Stock\Lib\AccessPolicy;
use Modules\Transfers\Stock\Services\FreightCalculator;

HttpGuard::allowMethods(['GET']);
HttpGuard::sameOriginOr([]);

if (empty($_SESSION['userID'])) ApiResponder::json(['success'=>false,'error'=>'Not authenticated'], 401);

$tid = (int)($_GET['transfer'] ?? 0);
if ($tid <= 0) ApiResponder::json(['success'=>false,'error'=>'Missing transfer id'], 400);
if (!AccessPolicy::canAccessTransfer((int)$_SESSION['userID'], $tid)) {
  ApiResponder::json(['success'=>false,'error'=>'Forbidden'], 403);
}

$db = class_exists('\Core\DB') ? \Core\DB::instance() :
      (function_exists('cis_pdo') ? cis_pdo() : ($GLOBALS['pdo'] ?? null));
if (!$db instanceof PDO) ApiResponder::json(['success'=>false,'error'=>'DB not initialized'], 500);

// Transfer outlets → capabilities
$tx = $db->prepare("SELECT outlet_from,outlet_to FROM transfers WHERE id=:id");
$tx->execute(['id'=>$tid]);
$tr = $tx->fetch(PDO::FETCH_ASSOC);
if (!$tr) ApiResponder::json(['success'=>false,'error'=>'Transfer not found'], 404);

$from = outlet_by_vend_uuid((string)$tr['outlet_from']);
$to   = outlet_by_vend_uuid((string)$tr['outlet_to']);

$hasGss    = !empty($from['gss_token']);
$hasNzPost = !empty($from['nz_post_api_key']) && !empty($from['nz_post_subscription_key']);
$def       = $hasGss && $hasNzPost ? 'nz_post' : ($hasNzPost ? 'nz_post' : ($hasGss ? 'gss' : 'manual'));

// total weight
$calc   = new FreightCalculator();
$lines  = $calc->getWeightedItems($tid);
$sum_g  = 0; foreach ($lines as $ln) $sum_g += (int)$ln['line_weight_g'];

$rules    = $calc->getRules();
$services = $calc->getRulesGroupedByCarrier($rules);

// printers (GSS)
$printers = [];
if ($hasGss) {
  $hdr = outlet_get_gss_headers_by_outlet($from);
  $res = http_json('GET','https://api.gosweetspot.com/api/printers',$hdr,null,15,0);
  if (!empty($res['ok']) && isset($res['json']) && is_array($res['json'])) {
    foreach ($res['json'] as $p) $printers[] = (string)($p->Printer ?? $p->Name ?? '');
  }
}

ApiResponder::json([
  'success'=>true,
  'carriers'=>['has_gss'=>$hasGss,'has_nz_post'=>$hasNzPost,'default'=>$def],
  'weight_grams'=>$sum_g,'weight_kg'=>round($sum_g/1000,3),
  'services'=>$services,
  'rules'=>$rules,
  'printers'=>$printers
],200);
