<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'].'/app.php';
header('Content-Type: application/json');

use Modules\Transfers\Stock\Services\PackLockService;
use Modules\Transfers\Stock\Services\LockAuditService;
use Modules\Transfers\Stock\Services\StaffNameResolver;

if(!isset($_SESSION['userID'])){ http_response_code(401); echo json_encode(['success'=>false,'error'=>'unauth']); exit; }
$transferId = isset($_POST['transfer_id']) ? (int)$_POST['transfer_id'] : 0;
if($transferId<=0){ echo json_encode(['success'=>false,'error'=>'invalid_transfer']); exit; }
$fingerprint = $_POST['fingerprint'] ?? null;

try {
    $uid = (int)$_SESSION['userID'];
    $svc = new PackLockService();
    $audit = new LockAuditService();
    $svc->cleanup();
    $result = $svc->requestAccess($transferId, $uid, $fingerprint);
    if(($result['success']??false) && isset($result['request_id'])){ $audit->lockRequest($transferId,$uid,(int)$result['request_id']); }
    if(isset($result['holder']['user_id'])){ $resolver=new StaffNameResolver(); $result['holder']['holder_name']=$resolver->name((int)$result['holder']['user_id']); }
    echo json_encode($result);
} catch(Throwable $e){
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'exception','message'=>$e->getMessage()]);
}
