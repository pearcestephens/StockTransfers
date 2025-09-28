<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'].'/app.php';
header('Content-Type: application/json');

use Modules\Transfers\Stock\Services\PackLockService;
use Modules\Transfers\Stock\Services\LockAuditService;

if(!isset($_SESSION['userID'])){ http_response_code(401); echo json_encode(['success'=>false,'error'=>'unauth']); exit; }
$requestId = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
$accept = isset($_POST['accept']) ? (bool)$_POST['accept'] : false;
if($requestId<=0){ echo json_encode(['success'=>false,'error'=>'invalid_request']); exit; }

try {
    $uid = (int)$_SESSION['userID'];
    $svc = new PackLockService();
    $audit = new LockAuditService();
    $pre = $svc->getLock((int)($_POST['transfer_id']??0));
    $result = $svc->respond($requestId, $uid, $accept);
    if(($result['success']??false)){
        // Need to discover transfer id + request user
        if(isset($result['lock']['transfer_id'])){
            $tid = (int)$result['lock']['transfer_id'];
            // fetch request user for audit (simple query)
            $pdo = cis_pdo();
            if($pdo){
                $st=$pdo->prepare("SELECT user_id FROM transfer_pack_lock_requests WHERE id=? LIMIT 1");
                $st->execute([$requestId]);
                $ruid=(int)$st->fetchColumn();
                $audit->lockRespond($tid,$uid,$ruid,$requestId,(bool)($result['accepted']??false));
            }
        }
    }
    echo json_encode($result);
} catch(Throwable $e){
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'exception','message'=>$e->getMessage()]);
}
