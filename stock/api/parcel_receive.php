<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'].'/assets/functions/config.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/assets/functions/ApiResponder.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/assets/functions/JsonGuard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/assets/functions/HttpGuard.php';

use Modules\Transfers\Stock\Services\ReceiptService;
use Modules\Transfers\Stock\Lib\AccessPolicy;

HttpGuard::allowMethods(['POST']);
HttpGuard::sameOriginOr([]);
HttpGuard::rateLimit('parcel_receive:'.(int)($_SESSION['userID']??0), 60, 60);
JsonGuard::csrfCheckOptional();
JsonGuard::idempotencyGuard();
HttpGuard::requireJsonContent();

if (empty($_SESSION['userID'])) ApiResponder::json(['success'=>false,'error'=>'Not authenticated'], 401);

$body        = JsonGuard::readJson();
$transferId  = (int)($body['transfer_id'] ?? 0);
$finalize    = (bool)($body['finalize'] ?? false);
$receipts    = $body['parcel_receipts'] ?? [];

if ($transferId <= 0 || !is_array($receipts)) {
    ApiResponder::json(['success'=>false,'error'=>'transfer_id and parcel_receipts required'], 400);
}
if (!AccessPolicy::canAccessTransfer((int)$_SESSION['userID'], $transferId)) {
    ApiResponder::json(['success'=>false,'error'=>'Forbidden'], 403);
}

$svc = new ReceiptService();
$rid = $svc->beginReceipt($transferId, (int)$_SESSION['userID']);

try {
    foreach ($receipts as $r) {
        $pid = (int)($r['parcel_id'] ?? 0);
        $iid = (int)($r['item_id'] ?? 0);
        $qty = (int)($r['qty'] ?? 0);
        $cond= isset($r['condition']) ? (string)$r['condition'] : null;
        $note= isset($r['notes']) ? (string)$r['notes'] : null;

        if ($pid<=0 || $iid<=0) continue;
        $svc->receiveParcelItem($pid, $iid, $qty, $cond, $note);
    }

    if ($finalize) {
        $svc->finalizeReceipt($transferId, $rid, (int)$_SESSION['userID']);
    }

    ApiResponder::json(['success'=>true, 'receipt_id'=>$rid, 'finalized'=>$finalize], 200);
} catch (\Throwable $e) {
    ApiResponder::json(['success'=>false,'error'=>$e->getMessage(),'receipt_id'=>$rid], 500);
}
