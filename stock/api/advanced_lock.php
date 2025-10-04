<?php
/**
 * Advanced Lock API - Enhanced simple_lock.php with comprehensive features
 * 
 * Actions: status | acquire | steal | heartbeat | release | request | response
 * 
 * Features:
 * - Same-user tab detection and instant switching
 * - Different-user lock requests with 60s timeout
 * - Force takeover capabilities
 * - Lock request notifications via SSE
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

set_error_handler(function($sev,$msg,$file,$line){
  http_response_code(500);
  echo json_encode(['ok'=>false,'err'=>'php','msg'=>$msg,'file'=>basename($file),'line'=>$line]);
  exit;
});
set_exception_handler(function(Throwable $e){
  http_response_code(500);
  echo json_encode(['ok'=>false,'err'=>'ex','msg'=>$e->getMessage(),'file'=>basename($e->getFile()),'line'=>$e->getLine()]);
  exit;
});

function body_json(): array {
  static $cached=null; if($cached!==null) return $cached;
  $raw = file_get_contents('php://input') ?: '';
  $j = json_decode($raw,true);
  return $cached = (is_array($j)?$j:[]);
}
function inparam(string $k,$def=null){ $b=body_json(); if(array_key_exists($k,$b)) return $b[$k]; if(isset($_POST[$k])) return $_POST[$k]; if(isset($_GET[$k])) return $_GET[$k]; return $def; }
function must(string $k): string { $v=trim((string)inparam($k,'')); if($v==='') throw new RuntimeException("Missing param: $k"); return $v; }
function clamp_int($v,int $min,int $max,int $fallback):int{ $v=is_numeric($v)?(int)$v:$fallback; return max($min,min($max,$v)); }
function validate_key(string $k): string { if(!preg_match('#^[A-Za-z0-9:_-]{1,191}$#',$k)) throw new RuntimeException('Bad resource_key'); return $k; }

// Bootstrap CIS app for DB connection
require_once $_SERVER['DOCUMENT_ROOT'] . '/app.php';

// Get PDO from CIS
if (!function_exists('cis_pdo')) {
  require_once dirname(__DIR__, 2) . '/_local_shims.php';
}
$pdo = cis_pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$action = strtolower((string)inparam('action','status'));
$ttl = clamp_int(inparam('ttl',90),30,600,90);
$requestTimeout = 60; // 60 seconds for lock requests

// ======== STATUS Action ========
if($action==='status'){
  $key = validate_key(must('resource_key'));
  $stmt = $pdo->prepare("SELECT owner_id,tab_id,token,expires_at,UTC_TIMESTAMP() now_utc FROM simple_locks WHERE resource_key=?");
  $stmt->execute([$key]);
  $row = $stmt->fetch();
  
  if(!$row){ 
    echo json_encode(['ok'=>true,'locked'=>false,'resource_key'=>$key]); 
    exit; 
  }
  
  $secs = max(0,(int)$pdo->query("SELECT TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),".$pdo->quote($row['expires_at']).")")->fetchColumn());
  
  // Check for pending requests
  $reqStmt = $pdo->prepare("SELECT requester_id, requester_tab, created_at FROM lock_requests WHERE resource_key=? AND expires_at > UTC_TIMESTAMP()");
  $reqStmt->execute([$key]);
  $pendingRequest = $reqStmt->fetch();
  
  $response = [
    'ok' => true,
    'locked' => ($secs > 0),
    'resource_key' => $key,
    'owner_id' => $row['owner_id'],
    'tab_id' => $row['tab_id'],
    'expires_in' => $secs
  ];
  
  if ($pendingRequest) {
    $response['pending_request'] = [
      'requester_id' => $pendingRequest['requester_id'],
      'requester_tab' => $pendingRequest['requester_tab'],
      'requested_at' => $pendingRequest['created_at']
    ];
  }
  
  echo json_encode($response);
  exit;
}

// ======== ACQUIRE Action ========
if($action==='acquire'){
  $key = validate_key(must('resource_key')); 
  $owner = must('owner_id'); 
  $tab = must('tab_id');
  $force = (bool)inparam('force', false);
  $token = bin2hex(random_bytes(16));
  
  try{
    // Try direct insert first
    $ins = $pdo->prepare("INSERT INTO simple_locks(resource_key,owner_id,tab_id,token,acquired_at,expires_at) VALUES(?,?,?, ?,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND))");
    $ins->execute([$key,$owner,$tab,$token,$ttl]);
    echo json_encode(['ok'=>true,'acquired'=>true,'resource_key'=>$key,'token'=>$token,'expires_in'=>$ttl]);
    exit;
  } catch(PDOException $e){ 
    if($e->getCode()!=='23000') throw $e; 
  }
  
  if ($force) {
    // Force acquire - overwrite existing lock
    $upd = $pdo->prepare("UPDATE simple_locks SET owner_id=?,tab_id=?,token=?,acquired_at=UTC_TIMESTAMP(),expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND) WHERE resource_key=?");
    $upd->execute([$owner,$tab,$token,$ttl,$key]);
    
    // Clear any pending requests
    $pdo->prepare("DELETE FROM lock_requests WHERE resource_key=?")->execute([$key]);
    
    echo json_encode(['ok'=>true,'acquired'=>true,'resource_key'=>$key,'token'=>$token,'expires_in'=>$ttl,'forced'=>true]);
    exit;
  }
  
  // Try to acquire expired lock
  $upd = $pdo->prepare("UPDATE simple_locks SET owner_id=?,tab_id=?,token=?,acquired_at=UTC_TIMESTAMP(),expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND) WHERE resource_key=? AND expires_at < UTC_TIMESTAMP()");
  $upd->execute([$owner,$tab,$token,$ttl,$key]);
  
  if($upd->rowCount()===1){ 
    echo json_encode(['ok'=>true,'acquired'=>true,'resource_key'=>$key,'token'=>$token,'expires_in'=>$ttl]); 
    exit; 
  }
  
  // Lock is held by someone else
  $row = $pdo->prepare("SELECT owner_id,tab_id,expires_at FROM simple_locks WHERE resource_key=?"); 
  $row->execute([$key]); 
  $r = $row->fetch();
  $secs = 0; 
  if($r){ 
    $secs = (int)$pdo->query("SELECT GREATEST(0,TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),".$pdo->quote($r['expires_at'])."))")->fetchColumn(); 
  }
  
  echo json_encode([
    'ok' => true,
    'acquired' => false,
    'locked' => true,
    'resource_key' => $key,
    'locked_by' => $r ? $r['owner_id'] : null,
    'locked_tab' => $r ? $r['tab_id'] : null,
    'same_owner' => $r ? ($r['owner_id']===$owner) : false,
    'same_tab' => $r ? ($r['tab_id']===$tab) : false,
    'expires_in' => $secs
  ]);
  exit;
}

// ======== STEAL Action (Same-user tab switching) ========
if($action==='steal'){
  $key = validate_key(must('resource_key')); 
  $owner = must('owner_id'); 
  $tab = must('tab_id');
  $token = bin2hex(random_bytes(16));
  
  // Only allow steal if existing row owned by same owner but different tab
  $rowStmt = $pdo->prepare("SELECT owner_id,tab_id FROM simple_locks WHERE resource_key=? LIMIT 1");
  $rowStmt->execute([$key]);
  $row = $rowStmt->fetch();
  
  if(!$row || $row['owner_id']!==$owner){
    echo json_encode(['ok'=>false,'acquired'=>false,'reason'=>'not_same_owner']); 
    exit;
  }
  
  if($row['tab_id']===$tab){
    echo json_encode(['ok'=>true,'acquired'=>true,'resource_key'=>$key,'token'=>$token,'same_tab'=>true,'expires_in'=>$ttl]); 
    exit;
  }
  
  $upd = $pdo->prepare("UPDATE simple_locks SET tab_id=?, token=?, acquired_at=UTC_TIMESTAMP(), expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND) WHERE resource_key=? AND owner_id=?");
  $upd->execute([$tab,$token,$ttl,$key,$owner]);
  
  if($upd->rowCount()===1){
    echo json_encode(['ok'=>true,'acquired'=>true,'resource_key'=>$key,'token'=>$token,'stolen'=>true,'expires_in'=>$ttl]);
  } else {
    echo json_encode(['ok'=>false,'acquired'=>false,'reason'=>'update_failed']);
  }
  exit;
}

// ======== REQUEST Action (Request lock from different user) ========
if($action==='request'){
  $key = validate_key(must('resource_key')); 
  $requester = must('requester_id'); 
  $requesterTab = must('requester_tab');
  
  // Create or update lock request
  $req = $pdo->prepare("INSERT INTO lock_requests (resource_key, requester_id, requester_tab, created_at, expires_at) VALUES (?, ?, ?, UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND)) ON DUPLICATE KEY UPDATE created_at=UTC_TIMESTAMP(), expires_at=DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND)");
  $req->execute([$key, $requester, $requesterTab, $requestTimeout, $requestTimeout]);
  
  echo json_encode(['ok'=>true,'request_sent'=>true,'resource_key'=>$key,'timeout_seconds'=>$requestTimeout]);
  exit;
}

// ======== RESPONSE Action (Allow/Deny lock request) ========
if($action==='response'){
  $key = validate_key(must('resource_key')); 
  $owner = must('owner_id'); 
  $tab = must('tab_id');
  $allow = (bool)inparam('allow', false);
  
  if ($allow) {
    // Release current lock and let requester take it
    $del = $pdo->prepare("DELETE FROM simple_locks WHERE resource_key=? AND owner_id=? AND tab_id=?");
    $del->execute([$key, $owner, $tab]);
    
    // Clear the request
    $pdo->prepare("DELETE FROM lock_requests WHERE resource_key=?")->execute([$key]);
    
    echo json_encode(['ok'=>true,'released'=>true,'allowed_takeover'=>true]);
  } else {
    // Deny the request
    $pdo->prepare("DELETE FROM lock_requests WHERE resource_key=?")->execute([$key]);
    
    echo json_encode(['ok'=>true,'denied_takeover'=>true]);
  }
  exit;
}

// ======== HEARTBEAT Action ========
if($action==='heartbeat'){
  $key = validate_key(must('resource_key')); 
  $owner = must('owner_id'); 
  $tab = must('tab_id'); 
  $token = must('token');
  
  $q = $pdo->prepare("UPDATE simple_locks SET expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND) WHERE resource_key=? AND owner_id=? AND tab_id=? AND token=?");
  $q->execute([$ttl,$key,$owner,$tab,$token]);
  
  echo json_encode(['ok'=>true,'extended'=>($q->rowCount()===1),'expires_in'=>$ttl]);
  exit;
}

// ======== RELEASE Action ========
if($action==='release'){
  $key = validate_key(must('resource_key')); 
  $owner = must('owner_id'); 
  $tab = must('tab_id'); 
  $token = must('token');
  
  $del = $pdo->prepare("DELETE FROM simple_locks WHERE resource_key=? AND owner_id=? AND tab_id=? AND token=?");
  $del->execute([$key,$owner,$tab,$token]);
  
  // Also clear any pending requests for this resource
  $pdo->prepare("DELETE FROM lock_requests WHERE resource_key=?")->execute([$key]);
  
  echo json_encode(['ok'=>true,'released'=>($del->rowCount()===1)]);
  exit;
}

echo json_encode(['ok'=>false,'err'=>'bad_action','msg'=>'Use status|acquire|steal|request|response|heartbeat|release']);
?>