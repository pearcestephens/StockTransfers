<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'].'/app.php';
header('Content-Type: application/json');
use Modules\Transfers\Stock\Services\PackLockService;

function respond($ok, $data = [], $code = 200){ http_response_code($code); echo json_encode($ok? ['success'=>true]+$data : ['success'=>false,'error'=>$data], JSON_UNESCAPED_SLASHES); exit; }

$transferId = $_POST['transfer_id'] ?? ($_GET['transfer_id'] ?? '');
$uid = (int)($_SESSION['userID'] ?? 0);
if($uid<=0) respond(false,['message'=>'Auth required'],401);
if($transferId==='') respond(false,['message'=>'Missing transfer_id'],400);

try {
  $pdo = cis_pdo();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $lockSvc = new PackLockService();
  $lock = $lockSvc->getLock($transferId);
  $holderId = $lock ? (int)$lock['user_id'] : null;
  if($holderId === $uid){ respond(true,[ 'state'=>'already_owner' ]); }

  $now = new DateTimeImmutable('now');
  $requesterWindow = 5; // seconds for requester countdown
  $holderWindow = 10;   // total window holder has before auto-grant logic
  $grantAt = $now->add(new DateInterval('PT'.$holderWindow.'S'));
  $sessionId = session_id();

  // Check duplicate pending in new canonical table
  $dup = $pdo->prepare("SELECT id FROM transfer_pack_lock_requests WHERE transfer_id=? AND user_id=? AND status='pending' AND requested_at > (NOW() - INTERVAL 15 SECOND) LIMIT 1");
  $dup->execute([$transferId,$uid]);
  if($dup->fetch()){
    respond(true,['state'=>'pending','duplicate'=>true]);
  }

  $ins = $pdo->prepare("INSERT INTO transfer_pack_lock_requests(transfer_id,user_id,expires_at,client_fingerprint) VALUES(?,?,DATE_ADD(NOW(), INTERVAL 10 SECOND),?)");
  $ins->execute([$transferId,$uid,$sessionId]);
  $requestId = (int)$pdo->lastInsertId();

  respond(true,[
    'state'=>'pending',
    'request_id'=>$requestId,
    'holder_user_id'=>$holderId,
    'requester_deadline'=>$now->add(new DateInterval('PT'.$requesterWindow.'S'))->format('c'),
    'holder_deadline'=>$grantAt->format('c')
  ]);
} catch(Throwable $e){ respond(false,['message'=>$e->getMessage()],500); }
