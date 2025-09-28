<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'].'/app.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/assets/functions/ApiResponder.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/assets/functions/HttpGuard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/modules/transfers/stock/services/FreightCalculator.php';

use PDO;
use Modules\Transfers\Stock\Services\FreightCalculator;

HttpGuard::allowMethods(['POST']);
HttpGuard::sameOriginOr([]);
HttpGuard::requireJsonContent();

$body = json_decode(file_get_contents('php://input')?:'[]',true) ?: [];
$carrierCode = strtolower((string)($body['carrier'] ?? 'nz_post'));
$weightKg    = (float)($body['weight_kg'] ?? 0.0);

$db = class_exists('\Core\DB') ? \Core\DB::instance() :
      (function_exists('cis_pdo') ? cis_pdo() : ($GLOBALS['pdo'] ?? null));
if (!$db instanceof PDO) ApiResponder::json(['success'=>false,'error'=>'DB not initialized'], 500);

$calc    = new FreightCalculator();
$carrier = $calc->normalizeCarrier($carrierCode);
$grams   = max(1, (int)round($weightKg * 1000));
$rule    = $calc->pickRuleForCarrier($carrier, $grams);
if (!$rule) ApiResponder::json(['success'=>true,'price'=>null],200);
ApiResponder::json([
      'success'=>true,
      'price'=>isset($rule['cost']) ? (float)$rule['cost'] : null,
      'container'=>$rule
],200);
