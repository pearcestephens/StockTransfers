<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'].'/app.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/assets/functions/ApiResponder.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/assets/functions/HttpGuard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/modules/transfers/stock/lib/AccessPolicy.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/modules/transfers/stock/services/FreightCalculator.php';

use Modules\Transfers\Stock\Lib\AccessPolicy;
use Modules\Transfers\Stock\Services\FreightCalculator;

HttpGuard::allowMethods(['POST']);
HttpGuard::sameOriginOr([]);
HttpGuard::requireJsonContent();

$body = json_decode(file_get_contents('php://input')?:'[]',true) ?: [];
$tid  = (int)($body['transfer_id'] ?? 0);
$carrierCode = strtolower((string)($body['carrier'] ?? 'nz_post'));
$weightKg    = (float)($body['weight_kg'] ?? 0.0);

if ($tid<=0) ApiResponder::json(['success'=>false,'error'=>'transfer_id required'],400);
if (!AccessPolicy::canAccessTransfer((int)$_SESSION['userID'], $tid)) ApiResponder::json(['success'=>false,'error'=>'Forbidden'],403);

$db = class_exists('\Core\DB') ? \Core\DB::instance() :
      (function_exists('cis_pdo') ? cis_pdo() : ($GLOBALS['pdo'] ?? null));
if (!$db instanceof PDO) ApiResponder::json(['success'=>false,'error'=>'DB not initialized'], 500);

$calc     = new FreightCalculator();
$carrier  = $calc->normalizeCarrier($carrierCode);
$grams    = max(1, (int)round($weightKg * 1000));
$rule     = $calc->pickRuleForCarrier($carrier, $grams);
if (!$rule) {
  ApiResponder::json(['success'=>true,'suggestion'=>null,'note'=>'No freight rule found for weight'],200);
}

$cap   = $rule['max_weight_grams'] ?? null;
$boxes = $calc->planParcelsByCap($grams, $cap);

ApiResponder::json([
  'success'=>true,
  'container'=>$rule,
  'boxes'=>count($boxes),
  'per_box_grams'=>$boxes
],200);
