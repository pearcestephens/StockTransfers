<?php
declare(strict_types=1);
// Server-Sent Events stream for lock & lock request state
// Emits an event each second (coalesced) for up to 30s; client should auto-reconnect.

require_once $_SERVER['DOCUMENT_ROOT'] . '/app.php';

// SSE / no buffering headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('X-Accel-Buffering: no'); // Disable Nginx buffering
header('Connection: keep-alive');

use Modules\Transfers\Stock\Services\PackLockService;
use Modules\Transfers\Stock\Services\StaffNameResolver;

function sse_emit(string $event, array $payload): void {
  $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
  echo "event: {$event}\n";
  echo "data: {$json}\n\n";
  @ob_flush(); @flush();
}

$transferId = $_GET['transfer_id'] ?? ($_GET['tx'] ?? '');
$uid = (int)($_SESSION['userID'] ?? 0);
if($uid <= 0){ sse_emit('error',['message'=>'Auth required']); exit; }
if($transferId===''){ sse_emit('error',['message'=>'Missing transfer_id']); exit; }

$pdo = null;
try { $pdo = cis_pdo(); } catch(Throwable $e){ sse_emit('error',['message'=>'DB unavailable']); exit; }
if(!$pdo){ sse_emit('error',['message'=>'DB handle missing']); exit; }
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$lockSvc = new PackLockService();
$staffResolver = new StaffNameResolver();

ignore_user_abort(true);
set_time_limit(35);

// Emission interval & loop duration (can be tuned)
$ITERATIONS = 30;            // ~ window length
$SLEEP_SEC  = 1;             // seconds between checks
$lastHash = null;            // change detection hash

for($i=0;$i<$ITERATIONS;$i++){
  try {
    // Acquire latest request
  // NOTE: Updated table name to match PackLockService schema (transfer_pack_lock_requests)
  $st = $pdo->prepare("SELECT * FROM transfer_pack_lock_requests WHERE transfer_id=? ORDER BY id DESC LIMIT 1");
    $st->execute([$transferId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
  $lock = $lockSvc->getLock($transferId);
    $currentHolderId = $lock ? (int)$lock['user_id'] : null;
    $now = new DateTimeImmutable('now');
    $response = [ 'success'=>true, 'transfer_id'=>$transferId ];
    if($row){
      $state = $row['status'];
      $requestingUser = (int)$row['user_id'];
      $requestedAt = new DateTimeImmutable($row['requested_at']);
      $requesterDeadline = $requestedAt->add(new DateInterval('PT5S'));
      $holderDeadline = $row['expires_at'] ? new DateTimeImmutable($row['expires_at']) : $requestedAt->add(new DateInterval('PT10S'));
      $holderRemaining = max(0,$holderDeadline->getTimestamp()-$now->getTimestamp());
      $requesterRemaining = max(0,$requesterDeadline->getTimestamp()-$now->getTimestamp());
      // Auto-accept if pending and holder window elapsed
      if($state==='pending' && $holderRemaining===0){
        if($currentHolderId !== $requestingUser){
          if($currentHolderId){ $lockSvc->releaseLock($transferId,$currentHolderId,true); }
          $acq = $lockSvc->acquire($transferId,$requestingUser,null);
          if(($acq['success']??false)===true){
            $up = $pdo->prepare("UPDATE transfer_pack_lock_requests SET status='accepted', responded_at=NOW() WHERE id=?");
            $up->execute([$row['id']]);
            $state='accepted';
            $currentHolderId=$requestingUser;
          }
        } else { $state='accepted'; }
      }
      $response += [
        'state'=>$state,
        'state_alias'=>$state==='accepted'?'granted':$state,
        'request_id'=>(int)$row['id'],
        'requesting_user_id'=>$requestingUser,
        'requesting_user_name'=>$staffResolver->name($requestingUser),
        'holder_user_id'=>$currentHolderId,
        'requester_deadline'=>$requesterDeadline->format('c'),
        'holder_deadline'=>$holderDeadline->format('c'),
        'seconds_remaining_requester'=>$state==='pending'? $requesterRemaining:0,
        'seconds_remaining_holder'=>$state==='pending'? $holderRemaining:0,
        'action_required'=> ($state==='pending' && $currentHolderId===$uid)
      ];
    } else { $response['state']='none'; }

    // Compute a lightweight hash to avoid emitting identical payloads
    $hash = md5(json_encode([
      $response['state'] ?? null,
      $response['seconds_remaining_holder'] ?? null,
      $response['seconds_remaining_requester'] ?? null,
      $response['holder_user_id'] ?? null,
      $response['requesting_user_id'] ?? null,
    ], JSON_UNESCAPED_SLASHES));
    if($hash !== $lastHash){
      sse_emit('lock',$response);
      $lastHash = $hash;
    } else {
      // Send a comment every few cycles to keep connection alive without full payload
      if($i % 5 === 0){ echo ": keepalive\n\n"; @ob_flush(); @flush(); }
    }
  } catch(Throwable $loopE){ sse_emit('error',['message'=>$loopE->getMessage()]); break; }
  if(connection_aborted()){ break; }
  sleep($SLEEP_SEC);
}
// Final comment to allow clean reconnect
echo ": end\n"; @ob_flush(); @flush();