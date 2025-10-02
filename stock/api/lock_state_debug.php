<?php
declare(strict_types=1);
/**
 * Debug: Current lock + latest request state (read-only)
 * WARNING: Keep internal. Add auth + minimal output.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/app.php';
header('Content-Type: application/json');

use Modules\Transfers\Stock\Services\PackLockService;
use Modules\Transfers\Stock\Services\StaffNameResolver;

function out($ok,array $data=[],int $code=200){ http_response_code($code); echo json_encode($ok? ['success'=>true]+$data : ['success'=>false,'error'=>$data], JSON_UNESCAPED_SLASHES); exit; }

$uid = (int)($_SESSION['userID'] ?? 0);
if($uid<=0) out(false,['message'=>'Auth required'],401);
$transferId = $_GET['transfer_id'] ?? ($_GET['tx'] ?? '');
if($transferId==='') out(false,['message'=>'Missing transfer_id'],400);

try {
  $pdo = cis_pdo();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $lockSvc = new PackLockService();
  $staff = new StaffNameResolver();
  $lock = $lockSvc->getLock($transferId);
  $lockFmt = null;
  if($lock){
    $lockFmt = [
      'user_id'=>(int)$lock['user_id'],
      'holder_name'=>$staff->name((int)$lock['user_id']),
      'acquired_at'=>$lock['acquired_at'] ?? null,
      'expires_at'=>$lock['expires_at'] ?? null,
    ];
  }
  $st = $pdo->prepare("SELECT * FROM transfer_pack_lock_requests WHERE transfer_id=? ORDER BY id DESC LIMIT 1");
  $st->execute([$transferId]);
  $req = $st->fetch(PDO::FETCH_ASSOC) ?: null;
  if($req){
    $requestedAt = $req['requested_at'];
    $requesterDeadline = (new DateTimeImmutable($requestedAt))->add(new DateInterval('PT5S'));
    $holderDeadline = $req['expires_at'] ? new DateTimeImmutable($req['expires_at']) : (new DateTimeImmutable($requestedAt))->add(new DateInterval('PT10S'));
    $req = [
      'id'=>(int)$req['id'],
      'status'=>$req['status'],
      'requesting_user_id'=>(int)$req['user_id'],
      'requesting_user_name'=>$staff->name((int)$req['user_id']),
      'requested_at'=>$req['requested_at'],
      'responded_at'=>$req['responded_at'],
      'requester_deadline'=>$requesterDeadline->format('c'),
      'holder_deadline'=>$holderDeadline->format('c'),
    ];
  }
  out(true,[ 'lock'=>$lockFmt, 'request'=>$req ]);
} catch(Throwable $e){ out(false,['message'=>$e->getMessage()],500); }
