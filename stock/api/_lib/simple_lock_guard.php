<?php
/**
 * simple_lock_guard.php
 * Server-side enforcement for simple_locks ownership for mutation endpoints.
 * Usage:
 *   require_once __DIR__.'/simple_lock_guard.php';
 *   $lockInfo = require_lock_or_423('transfer:'.$transferId, (int)$_SESSION['userID']);
 * Exposes: require_lock_or_423(string $resourceKey, int $userId, ?string $token=null)
 */

declare(strict_types=1);

function _sl_pdo(): PDO {
  static $pdo=null; if($pdo) return $pdo;
  $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST')?:'localhost', getenv('DB_NAME')?:'cis');
  $pdo = new PDO($dsn, getenv('DB_USER')?:'root', getenv('DB_PASS')?:'', [ PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION ]);
  return $pdo;
}

function _sl_now(): string { return gmdate('Y-m-d H:i:s'); }

function require_lock_or_423(string $resourceKey, int $userId, ?string $token=null): array {
  $pdo = _sl_pdo();
  $stmt = $pdo->prepare('SELECT resource_key, owner_id, tab_id, token, expires_at FROM simple_locks WHERE resource_key = ? LIMIT 1');
  $stmt->execute([$resourceKey]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  $rid = bin2hex(random_bytes(4));
  if(!$row){
    _sl_respond_423($rid, 'NO_LOCK', 'No active lock found for resource');
  }
  // Expired? treat as no lock
  if(strtotime($row['expires_at']) <= time()){
    _sl_respond_423($rid, 'EXPIRED', 'Existing lock expired');
  }
  if((int)$row['owner_id'] !== $userId){
    _sl_respond_423($rid, 'LOCK_HELD', 'Resource locked by another user', ['owner_id'=>(int)$row['owner_id']]);
  }
  if($token && hash_equals($row['token'], $token) === false){
    _sl_respond_423($rid, 'TOKEN_MISMATCH', 'Lock token mismatch');
  }
  return $row;
}

function _sl_respond_423(string $requestId, string $code, string $message, array $extra=[]): void {
  http_response_code(423);
  header('Content-Type: application/json; charset=utf-8');
  $env = [
    'ok'=>false,
    'request_id'=>$requestId,
    'error'=>[
      'code'=>$code,
      'message'=>$message,
      'details'=>$extra
    ]
  ];
  echo json_encode($env, JSON_UNESCAPED_SLASHES); exit;
}
