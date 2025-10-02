<?php
declare(strict_types=1);
/**
 * lock_status_mod.php
 * Canonical lock status (service-based) — replaces legacy lock_status.php usage.
 */
require_once $_SERVER['DOCUMENT_ROOT'].'/app.php';
header('Content-Type: application/json');
use Modules\Transfers\Stock\Services\PackLockService;
use Modules\Transfers\Stock\Services\StaffNameResolver;

function respond($ok,array $data=[],int $code=200){ http_response_code($code); echo json_encode($ok? ['success'=>true,'data'=>$data]: ['success'=>false,'error'=>$data], JSON_UNESCAPED_SLASHES); exit; }

$uid=(int)($_SESSION['userID']??0); if($uid<=0) respond(false,['message'=>'Auth required'],401);
$transferId = $_GET['transfer_id'] ?? ($_GET['tx'] ?? ''); if($transferId==='') respond(false,['message'=>'Missing transfer_id'],400);
try {
  $svc = new PackLockService();
  $resolver = new StaffNameResolver();
  $lock = $svc->getLock($transferId);
  $out = [ 'has_lock'=>false,'is_locked'=>false,'is_locked_by_other'=>false ];
  if($lock){
    $holderId=(int)$lock['user_id'];
    $out['holder_id']=$holderId;
    $out['holder_name']=$resolver->name($holderId);
    $out['lock_acquired_at']=$lock['acquired_at']??null;
    $out['lock_expires_at']=$lock['expires_at']??null;
    if($holderId===$uid){ $out['has_lock']=true; $out['is_locked']=true; }
    else { $out['is_locked']=true; $out['is_locked_by_other']=true; }
  }
  respond(true,$out);
} catch(Throwable $e){ respond(false,['message'=>$e->getMessage()],500); }
?>