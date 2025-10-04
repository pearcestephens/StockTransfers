<?php
declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'].'/modules/transfers/_local_shims.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/app.php';
header('Content-Type: application/json; charset=utf-8');

use Modules\Transfers\Stock\Services\TransfersService;
// Legacy PackLockService removed in favor of simple_locks guard
require_once __DIR__.'/_lib/simple_lock_guard.php';

// Unified response helpers --------------------------------------------------
function rsp(array $env, int $code=200): void { http_response_code($code); echo json_encode($env, JSON_UNESCAPED_SLASHES); exit; }
function rid(): string { try { return bin2hex(random_bytes(8)); } catch(Throwable) { return dechex(mt_rand()); } }

// Auth ----------------------------------------------------------------------
if(!isset($_SESSION['userID'])){
  rsp(['ok'=>false,'request_id'=>rid(),'error'=>['code'=>'UNAUTH','message'=>'Login required']],401);
}
$uid=(int)$_SESSION['userID'];

// Method guard ---------------------------------------------------------------
if(($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST'){
  rsp(['ok'=>false,'request_id'=>rid(),'error'=>['code'=>'METHOD_NOT_ALLOWED','message'=>'POST only']],405);
}

// Parse body (JSON first, then x-www-form)
$raw = file_get_contents('php://input') ?: '';
$payload = [];
if($raw !== ''){ $dec=json_decode($raw,true); if(is_array($dec)) $payload=$dec; }
if(!$payload) $payload = $_POST; // fallback legacy

$transferId = (int)($payload['transfer_id'] ?? 0);
if($transferId<=0){ rsp(['ok'=>false,'request_id'=>rid(),'error'=>['code'=>'MISSING_TRANSFER','message'=>'transfer_id required']],400); }

// Acquire / verify lock (simple_locks server-side enforcement) -------------
$lockRow = require_lock_or_423('transfer:'.$transferId, $uid, $payload['lock_token'] ?? null);

// Build domain data ---------------------------------------------------------
$data = [
  'items'    => $payload['items'] ?? [],
  'packages' => $payload['packages'] ?? [],
  'carrier'  => $payload['carrier'] ?? ($payload['courier'] ?? 'NZ_POST'),
  'notes'    => $payload['notes'] ?? ''
];

// Idempotency (hash of logical content) -------------------------------------
$idemKey = (string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? '');
$bodyHash = hash('sha256', json_encode($data));
// (Future) store/replay can be injected here using shared IdempotencyStore

try {
  $svc = new TransfersService();
  $res = $svc->savePack($transferId, $data, $uid); // expected legacy shape { success:bool, error? }
} catch(Throwable $e) {
  rsp(['ok'=>false,'request_id'=>rid(),'error'=>['code'=>'EXCEPTION','message'=>$e->getMessage()]],500);
}

if(!($res['success']??false)){
  $env = ['ok'=>false,'request_id'=>rid(),'error'=>['code'=>'SAVE_FAILED','message'=>$res['error'] ?? 'Save failed']];
  if(isset($_GET['legacy']) && $_GET['legacy']==='1'){ $legacy=$res; echo json_encode($legacy, JSON_UNESCAPED_SLASHES); exit; }
  rsp($env,200);
}

$responseData = [
  'transfer_id'=>$transferId,
  'saved'=>true,
  'idempotency_key'=>$idemKey?:null,
  'hash'=>$bodyHash
];

// Legacy compatibility toggle
if(isset($_GET['legacy']) && $_GET['legacy']==='1'){
  echo json_encode($res, JSON_UNESCAPED_SLASHES); exit;
}

rsp(['ok'=>true,'request_id'=>rid(),'data'=>$responseData,'lock'=>['resource'=>$lockRow['resource_key'],'token'=>$lockRow['token']]]);
?>