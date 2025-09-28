<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'].'/app.php';
header('Content-Type: application/json');

use Modules\Transfers\Stock\Services\TransfersService;
use Modules\Transfers\Stock\Services\PackLockService;
use Modules\Transfers\Stock\Services\LockAuditService;

function jexit(array $o){ echo json_encode($o, JSON_UNESCAPED_SLASHES); exit; }

if(!isset($_SESSION['userID'])) jexit(['success'=>false,'error'=>'unauth']);
$uid=(int)$_SESSION['userID'];

$raw = file_get_contents('php://input');
$payload = [];
if($raw){ $dec=json_decode($raw,true); if(is_array($dec)) $payload=$dec; }
if(!$payload){ $payload = $_POST; }
$transferId = (int)($payload['transfer_id'] ?? 0);
if($transferId<=0) jexit(['success'=>false,'error'=>'missing_transfer']);

// Enforce active lock ownership
$lockSvc = new PackLockService();
$lock = $lockSvc->getLock($transferId);
if(!$lock || (int)$lock['user_id']!==$uid){
    jexit(['success'=>false,'error'=>'lock_required','detail'=>'Obtain exclusive lock before saving pack']);
}

// Actual save (re-use TransfersService->savePack contract)
$data = [
  'items'    => $payload['items'] ?? [],
  'packages' => $payload['packages'] ?? [],
  'carrier'  => $payload['carrier'] ?? ($payload['courier'] ?? 'NZ_POST'),
  'notes'    => $payload['notes'] ?? ''
];

$svc = new TransfersService();
$res = $svc->savePack($transferId, $data, $uid);

$audit = new LockAuditService();
if(!($res['success']??false)){
  $audit->lockRelease($transferId,$uid,false); // optional: keep lock; here just record context
  jexit($res);
}
$audit->lockAcquire($transferId,$uid,true); // reuse to annotate pack save context
jexit($res);
?>