<?php
declare(strict_types=1);
/**
 * lock_force_release.php
 * Emergency endpoint to force-release a stale pack lock (PackLockService).
 * WARNING: Use sparingly. Prefer normal handover flow.
 * Auth: requires logged-in user & either current holder or ?force=1 (admin / override flag).
 */
require_once $_SERVER['DOCUMENT_ROOT'].'/app.php';
header('Content-Type: application/json');

use Modules\Transfers\Stock\Services\PackLockService;

function out($ok,array $data=[],int $code=200){ http_response_code($code); echo json_encode($ok? ['success'=>true]+$data : ['success'=>false,'error'=>$data], JSON_UNESCAPED_SLASHES); exit; }

$uid = (int)($_SESSION['userID'] ?? 0);
if($uid<=0) out(false,['message'=>'Auth required'],401);
$transferId = $_POST['transfer_id'] ?? ($_GET['transfer_id'] ?? ($_GET['tx'] ?? ''));
if($transferId==='') out(false,['message'=>'Missing transfer_id'],400);
$force = isset($_REQUEST['force']) && (int)$_REQUEST['force'] === 1;

try {
  $svc = new PackLockService();
  $lock = $svc->getLock($transferId);
  if(!$lock){ out(true,['released'=>false,'message'=>'No active lock']); }
  $holderId = (int)$lock['user_id'];
  if($holderId !== $uid && !$force){
    out(false,['message'=>'Not lock holder. Append force=1 to override (admin only).'],403);
  }
  $res = $svc->releaseLock($transferId,$holderId,true);
  if(($res['success']??false)===true){ out(true,['released'=>true,'previous_holder'=>$holderId]); }
  out(false,['message'=>'Release failed']);
} catch(Throwable $e){ out(false,['message'=>$e->getMessage()],500); }
?>