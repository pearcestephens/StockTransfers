<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/app.php';
header('Content-Type: application/json');

use Modules\Transfers\Stock\Services\PackLockService;
use Modules\Transfers\Stock\Services\StaffNameResolver;

function respond($data, int $code=200){ http_response_code($code); echo json_encode($data, JSON_UNESCAPED_SLASHES); exit; }

$transferId = $_GET['transfer_id'] ?? ($_POST['transfer_id'] ?? '');
$uid = (int)($_SESSION['userID'] ?? 0);
if($uid <= 0) respond(['success'=>false,'error'=>['message'=>'Auth required']],401);
if($transferId==='') respond(['success'=>false,'error'=>['message'=>'Missing transfer_id']],400);

try {
  $pdo = cis_pdo();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $lockSvc = new PackLockService();
  $staffResolver = new StaffNameResolver();

  // Latest request in canonical table
  $st = $pdo->prepare("SELECT * FROM transfer_pack_lock_requests WHERE transfer_id=? ORDER BY id DESC LIMIT 1");
  $st->execute([$transferId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if(!$row){
    respond(['success'=>true,'state'=>'none']);
  }
  $state = $row['status'];
  $requestingUser = (int)$row['user_id'];
  $requestedAt = new DateTimeImmutable($row['requested_at']);
  // Deadlines derived (5s requester focus, 10s holder decision) or from expires_at if shorter
  $requesterDeadline = $requestedAt->add(new DateInterval('PT5S'));
  $holderDeadline = $row['expires_at'] ? new DateTimeImmutable($row['expires_at']) : $requestedAt->add(new DateInterval('PT10S'));
  $now = new DateTimeImmutable('now');
  $secondsRemainingHolder = max(0, $holderDeadline->getTimestamp() - $now->getTimestamp());
  $secondsRemainingRequester = max(0, $requesterDeadline->getTimestamp() - $now->getTimestamp());

  $lock = $lockSvc->getLock($transferId);
  $currentHolderId = $lock ? (int)$lock['user_id'] : null;

  // Auto-grant if pending & expired
  if($state === 'pending' && $secondsRemainingHolder === 0){
    if($currentHolderId !== $requestingUser){
      // Force transfer
      if($currentHolderId){ $lockSvc->releaseLock($transferId,$currentHolderId,true); }
      $acq = $lockSvc->acquire($transferId,$requestingUser,null);
      if(($acq['success']??false)===true){
        $up = $pdo->prepare("UPDATE transfer_pack_lock_requests SET status='accepted', responded_at=NOW() WHERE id=?");
        $up->execute([$row['id']]);
        $state='accepted';
        $currentHolderId = $requestingUser;
      }
    } else { $state='accepted'; }
  }

  $response = [
    'success'=>true,
    'state'=>$state,
    'state_alias'=>$state==='accepted'?'granted':$state,
    'request_id'=>(int)$row['id'],
    'requesting_user_id'=>$requestingUser,
    'requesting_user_name'=>$staffResolver->name($requestingUser),
    'holder_user_id'=>$currentHolderId,
    'requester_deadline'=>$requesterDeadline->format('c'),
    'holder_deadline'=>$holderDeadline->format('c'),
    'seconds_remaining_holder'=>$state==='pending'? $secondsRemainingHolder : 0,
    'seconds_remaining_requester'=>$state==='pending'? $secondsRemainingRequester : 0
  ];

  // Action required for lock holder
  if($state==='pending' && $currentHolderId === $uid){ $response['action_required']=true; }

  // Requestor perspective - acquired
  if(in_array($state,['accepted'],true) && $requestingUser === $uid){ $response['acquired']=true; }

  respond($response);
} catch(Throwable $e){
  respond(['success'=>false,'error'=>['message'=>$e->getMessage()]],500);
}
