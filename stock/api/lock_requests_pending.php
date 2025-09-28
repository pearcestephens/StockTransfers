<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'].'/app.php';
header('Content-Type: application/json');

use Modules\Transfers\Stock\Services\PackLockService;
use Modules\Transfers\Stock\Services\StaffNameResolver;

if(!isset($_SESSION['userID'])){ http_response_code(401); echo json_encode(['success'=>false,'error'=>'unauth']); exit; }
$transferId = isset($_GET['transfer_id']) ? (int)$_GET['transfer_id'] : 0;
if($transferId<=0){ echo json_encode(['success'=>false,'error'=>'invalid_transfer']); exit; }

try {
    $svc = new PackLockService();
    $lock = $svc->getLock($transferId);
    if(!$lock || (int)$lock['user_id'] !== (int)$_SESSION['userID']){
        echo json_encode(['success'=>false,'error'=>'not_holder']);
        exit;
    }
    $requests = $svc->holderPendingRequests($transferId, (int)$_SESSION['userID']);
    $resolver=new StaffNameResolver();
    foreach($requests as &$r){ $r['request_user_name']=$resolver->name((int)$r['user_id']); }
    echo json_encode(['success'=>true,'requests'=>$requests]);
} catch(Throwable $e){
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'exception','message'=>$e->getMessage()]);
}
