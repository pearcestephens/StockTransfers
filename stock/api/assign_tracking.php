<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'].'/assets/functions/config.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/assets/functions/ApiResponder.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/assets/functions/JsonGuard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/assets/functions/HttpGuard.php';

use Modules\Transfers\Stock\Services\TrackingService;
use Modules\Transfers\Stock\Lib\AccessPolicy;

HttpGuard::allowMethods(['POST']);
HttpGuard::sameOriginOr([]);
HttpGuard::rateLimit('assign_tracking:'.(int)($_SESSION['userID']??0), 30, 60);
JsonGuard::csrfCheckOptional();
JsonGuard::idempotencyGuard();
HttpGuard::requireJsonContent();

if (empty($_SESSION['userID'])) ApiResponder::json(['success'=>false,'error'=>'Not authenticated'], 401);

$body       = JsonGuard::readJson();
$transferId = (int)($body['transfer_id'] ?? 0);
$parcelId   = (int)($body['parcel_id'] ?? 0);
$tracking   = (string)($body['tracking_number'] ?? '');
$carrier    = strtoupper((string)($body['carrier'] ?? 'NZ_POST'));

if ($transferId<=0 || $parcelId<=0 || trim($tracking)==='') {
    ApiResponder::json(['success'=>false,'error'=>'transfer_id, parcel_id, tracking_number required'], 400);
}
if (!AccessPolicy::canAccessTransfer((int)$_SESSION['userID'], $transferId)) {
    ApiResponder::json(['success'=>false,'error'=>'Forbidden'], 403);
}

(new TrackingService())->setParcelTracking($parcelId, $tracking, $carrier, $transferId);
ApiResponder::json(['success'=>true], 200);
