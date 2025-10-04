<?php
declare(strict_types=1);

/**
 * CIS — Save Manual Tracking
 * Path: modules/transfers/stock/api/save_manual_tracking.php
 * 
 * SECURITY: Requires valid transfer lock ownership
 * Submit-only Manual/Internal Tracking Endpoint (idempotent + delete)
 * New Request (create): { commit:true, transfer_id, mode:'manual'|'internal', tracking?, carrier_id?, carrier_code, notes? }
 * Delete: POST ?action=delete { id, transfer_id }
 * Envelope: { ok, request_id, data?|error }
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once $_SERVER['DOCUMENT_ROOT'].'/modules/transfers/_local_shims.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/app.php';
require_once __DIR__ . '/_lib/simple_lock_guard.php';

// PackLockService removed: using simple_locks guard

$REQ_ID = bin2hex(random_bytes(8));
function env_ok(array $data): array { return ['ok'=>true,'request_id'=>$GLOBALS['REQ_ID'],'data'=>$data]; }
function env_err(string $code,string $msg,array $details=[]): array { return ['ok'=>false,'request_id'=>$GLOBALS['REQ_ID'],'error'=>['code'=>$code,'message'=>$msg,'details'=>$details]]; }
function send(array $e,int $code=200){ http_response_code($code); echo json_encode($e, JSON_UNESCAPED_SLASHES); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') send(env_err('METHOD_NOT_ALLOWED','POST required'),405);

if(!isset($_SESSION['userID'])){ send(env_err('UNAUTH','Auth required'),401); }
$userId = (int)$_SESSION['userID'];

$raw = file_get_contents('php://input') ?: '';
$in = json_decode($raw,true);
if (!is_array($in)) send(env_err('INVALID_JSON','JSON body required'),400);

// Delete branch
if (($_GET['action'] ?? '') === 'delete') {
  $id = (int)($in['id'] ?? 0); 
  $transferId = (int)($in['transfer_id'] ?? 0);
  if($transferId<=0) send(env_err('MISSING_TRANSFER','transfer_id required'),400);
  require_lock_or_423('transfer:'.$transferId, $userId, $in['lock_token'] ?? null);
  
  try {
    $pdo = pdo();
    $st = $pdo->prepare('UPDATE transfer_manual_tracking SET deleted_at = NOW() WHERE id=:id AND transfer_id=:t AND deleted_at IS NULL');
    $st->execute([':id'=>$id, ':t'=>$transferId]);
    send(env_ok(['deleted'=>true]));
  } catch (Throwable $e) { send(env_err('SERVER_ERROR','Could not delete tracking')); }
}

if (!($in['commit'] ?? false)) send(env_err('SUBMIT_ONLY','This endpoint saves only on explicit submit (commit:true).'));

$transferId = (int)($in['transfer_id'] ?? 0);
if($transferId<=0) send(env_err('MISSING_TRANSFER','transfer_id required'),400);
$mode        = (string)($in['mode'] ?? 'manual');
$tracking    = isset($in['tracking']) ? strtoupper(trim((string)$in['tracking'])) : null;
$carrierId   = isset($in['carrier_id']) ? (int)$in['carrier_id'] : null;
$carrierCode = (string)($in['carrier_code'] ?? '');
$notes       = isset($in['notes']) ? trim((string)$in['notes']) : null;

// CRITICAL: Validate lock ownership for create
require_lock_or_423('transfer:'.$transferId, $userId, $in['lock_token'] ?? null);

if (!in_array($mode,['manual','internal'],true)) send(env_err('VALIDATION','mode must be manual|internal'));
if ($mode==='manual') {
  if (!$tracking || strlen($tracking)<6) send(env_err('VALIDATION','tracking number looks invalid'));
  if ($carrierCode==='') send(env_err('VALIDATION','carrier_code required'));
}
if ($notes!==null && strlen($notes)>300) $notes = substr($notes,0,300);

$idemKey = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
$bodyHash = hash('sha256', json_encode([$transferId,$mode,$tracking,$carrierId,$carrierCode,$notes]));

try {
  $pdo = pdo();

  if ($idemKey !== '') {
    $st = $pdo->prepare('SELECT * FROM transfer_manual_tracking WHERE transfer_id=:t AND idem_key=:k AND body_hash=:h AND deleted_at IS NULL LIMIT 1');
    $st->execute([':t'=>$transferId, ':k'=>$idemKey, ':h'=>$bodyHash]);
    if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      send(['ok'=>true,'request_id'=>$REQ_ID,'data'=>format_row($row),'replay'=>true]);
    }
  }

  $carrierName = match(true) {
    str_starts_with($carrierCode,'NZC') => 'NZ Couriers (GSS)',
    str_starts_with($carrierCode,'NZP') => 'NZ Post',
    $carrierCode === 'INTERNAL_DELIVERY' => 'Internal Delivery',
    default => 'Manual / Other'
  };

  $st = $pdo->prepare('INSERT INTO transfer_manual_tracking (transfer_id, mode, tracking, carrier_id, carrier_code, carrier_name, notes, idem_key, body_hash, created_by)
    VALUES (:t,:m,:trk,:cid,:cc,:cname,:notes,:idem,:hash,:u)');
  $st->execute([
    ':t'=>$transferId, ':m'=>$mode, ':trk'=>$tracking, ':cid'=>$carrierId ?: null, ':cc'=>$carrierCode,
    ':cname'=>$carrierName, ':notes'=>$notes, ':idem'=>$idemKey ?: null, ':hash'=>$bodyHash, ':u'=>$userId
  ]);
  $id = (int)$pdo->lastInsertId();
  send(env_ok([
    'id'=>$id,
    'transfer_id'=>$transferId,
    'mode'=>$mode,
    'tracking'=>$tracking,
    'carrier_id'=>$carrierId,
    'carrier_code'=>$carrierCode,
    'carrier_name'=>$carrierName,
    'notes'=>$notes,
    'created_at'=>gmdate('c')
  ]));
} catch (Throwable $e) {
  send(env_err('SERVER_ERROR','Could not save tracking',['hint'=>$e->getMessage()]));
}

function format_row(array $r): array {
  return [
    'id'=>(int)$r['id'],
    'transfer_id'=>(int)$r['transfer_id'],
    'mode'=>$r['mode'],
    'tracking'=>$r['tracking'],
    'carrier_id'=>$r['carrier_id']!==null?(int)$r['carrier_id']:null,
    'carrier_code'=>$r['carrier_code'],
    'carrier_name'=>$r['carrier_name'],
    'notes'=>$r['notes'],
    'created_at'=>$r['created_at']
  ];
}
        'notes'=>$notes,
