<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'].'/assets/functions/config.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/assets/functions/JsonGuard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/assets/functions/ApiResponder.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/assets/functions/HttpGuard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/assets/functions/shipping/OutletRepo.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/modules/transfers/stock/lib/AccessPolicy.php';

use Core\DB;
use Modules\Transfers\Stock\Lib\AccessPolicy;

HttpGuard::allowMethods(['GET']);
HttpGuard::sameOriginOr([]);
JsonGuard::csrfCheckOptional();

if (empty($_SESSION['userID'])) ApiResponder::json(['success'=>false,'error'=>'Not authenticated'], 401);

$tid = (int)($_GET['transfer'] ?? 0);
if ($tid <= 0) ApiResponder::json(['success'=>false,'error'=>'Missing ?transfer'], 400);
if (!AccessPolicy::canAccessTransfer((int)$_SESSION['userID'], $tid)) {
  ApiResponder::json(['success'=>false,'error'=>'Forbidden'], 403);
}

$db = DB::instance();
$tx = $db->prepare("SELECT id, outlet_from, outlet_to FROM transfers WHERE id=:id");
$tx->execute(['id'=>$tid]);
$tr = $tx->fetch(PDO::FETCH_ASSOC);
if (!$tr) ApiResponder::json(['success'=>false,'error'=>'Transfer not found'], 404);

$from = outlet_by_vend_uuid((string)$tr['outlet_from']);
$to   = outlet_by_vend_uuid((string)$tr['outlet_to']);

if (!$from || !$to) ApiResponder::json(['success'=>false,'error'=>'Outlet lookup failed'], 404);

$hasGss     = !empty($from['gss_token']);
$hasNzPost  = !empty($from['nz_post_api_key']) && !empty($from['nz_post_subscription_key']);
$default    = $hasGss && $hasNzPost ? 'nz_post' : ($hasNzPost ? 'nz_post' : ($hasGss ? 'gss' : 'manual'));

ApiResponder::json([
  'success'=>true,
  'origin' => ['id'=>$from['id'], 'name'=>$from['name'], 'address'=>outlet_address_block($from)],
  'destination' => ['id'=>$to['id'], 'name'=>$to['name'], 'address'=>outlet_address_block($to)],
  'carriers'=> ['has_gss'=>$hasGss, 'has_nz_post'=>$hasNzPost, 'default'=>$default],
], 200);
