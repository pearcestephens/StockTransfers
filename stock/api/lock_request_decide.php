<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/app.php';
header('Content-Type: application/json');

use Modules\Transfers\Stock\Services\PackLockService;

function respond($ok,$data=[], $code=200){ http_response_code($code); echo json_encode($ok? ['success'=>true]+$data : ['success'=>false,'error'=>$data], JSON_UNESCAPED_SLASHES); exit; }

$transferId = $_POST['transfer_id'] ?? ($_GET['transfer_id'] ?? '');
$decision = strtolower((string)($_POST['decision'] ?? $_GET['decision'] ?? ''));
$uid = (int)($_SESSION['userID'] ?? 0);
if($uid<=0) respond(false,['message'=>'Auth required'],401);
if($transferId==='') respond(false,['message'=>'Missing transfer_id'],400);
if(!in_array($decision,['grant','decline'],true)) respond(false,['message'=>'Invalid decision'],400);

try {
  $pdo = cis_pdo();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $st = $pdo->prepare("SELECT * FROM transfer_pack_lock_requests WHERE transfer_id=? AND status='pending' ORDER BY id DESC LIMIT 1");
  $st->execute([$transferId]);
  $req = $st->fetch(PDO::FETCH_ASSOC);
  if(!$req) respond(false,['message'=>'No pending request'],404);
  $requestingUser = (int)$req['user_id'];

  // Only current lock holder may decide
  $lockSvc = new PackLockService();
  $lock = $lockSvc->getLock($transferId);
  $holderId = $lock ? (int)$lock['user_id'] : null;
  if($holderId !== $uid) respond(false,['message'=>'Only lock holder may decide'],403);

  $grantMeta = json_decode($req['meta'] ?? '[]', true);
  $grantAt = isset($grantMeta['grant_at']) ? new DateTimeImmutable($grantMeta['grant_at']) : (new DateTimeImmutable())->add(new DateInterval('PT60S'));

  if($decision==='decline'){
    $up = $pdo->prepare("UPDATE transfer_pack_lock_requests SET status='declined', responded_at=NOW() WHERE id=?");
    $up->execute([$req['id']]);
    respond(true,['state'=>'declined','request_id'=>(int)$req['id']]);
  }
  // Accept / transfer
  if($lock){ $lockSvc->releaseLock($transferId,$holderId,true); }
  $acq = $lockSvc->acquire($transferId,$requestingUser,null);
  if(!( $acq['success'] ?? false)) respond(false,['message'=>'Failed to transfer lock'],500);
  $up = $pdo->prepare("UPDATE transfer_pack_lock_requests SET status='accepted', responded_at=NOW() WHERE id=?");
  $up->execute([$req['id']]);
  respond(true,['state'=>'accepted','request_id'=>(int)$req['id'],'new_owner'=>$requestingUser]);
} catch(Throwable $e){ respond(false,['message'=>$e->getMessage()],500); }
