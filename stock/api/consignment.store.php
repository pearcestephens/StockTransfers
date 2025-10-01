<?php
declare(strict_types=1);
/**
 * File: consignment.store.php (TEMP LOCATION - move to /assets/services/consignment.store.php for production path)
 * Purpose: Persist Vend consignment identifiers to transfers row.
 * Input (JSON POST): { transfer_id:int, vend_id:string, vend_number:string, pipeline_ref?:string }
 * Response: { ok:true } or { ok:false, error:"", code:"" }
 */
require_once $_SERVER['DOCUMENT_ROOT'].'/app.php';
header('Content-Type: application/json; charset=utf-8');

function cs_fail(int $http,string $code,string $msg){ http_response_code($http); echo json_encode(['ok'=>false,'error'=>$msg,'code'=>$code,'http'=>$http], JSON_UNESCAPED_SLASHES); exit; }
function cs_pdo(): PDO { if(function_exists('pdo')) return pdo(); if(function_exists('cis_pdo')) return cis_pdo(); throw new RuntimeException('No PDO'); }

try {
  if(strtoupper($_SERVER['REQUEST_METHOD']??'')!=='POST') cs_fail(405,'METHOD_NOT_ALLOWED','Use POST');
  if(session_status()!==PHP_SESSION_ACTIVE) session_start();
  $uid = (int)($_SESSION['staff_id'] ?? $_SESSION['userID'] ?? 0); if($uid<=0) cs_fail(401,'UNAUTHENTICATED','Login required');
  $raw = file_get_contents('php://input') ?: ''; $j = $raw? json_decode($raw,true):[]; if(!is_array($j)) cs_fail(400,'INVALID_JSON','Bad JSON');
  $transferId = (int)($j['transfer_id'] ?? 0); $vendId=trim((string)($j['vend_id']??'')); $vendNum=trim((string)($j['vend_number']??''));
  $pipelineRef = trim((string)($j['pipeline_ref']??''));
  if($transferId<=0||$vendId==='') cs_fail(422,'MISSING_FIELDS','transfer_id & vend_id required');
  $pdo = cs_pdo(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $stmt=$pdo->prepare('UPDATE transfers SET vend_transfer_id=COALESCE(vend_transfer_id,:vid), vend_number=COALESCE(vend_number,:vnum), updated_at=NOW() WHERE id=:tid');
  $stmt->execute([':vid'=>$vendId,':vnum'=>$vendNum?:$vendId,':tid'=>$transferId]);
  // lightweight audit insert (if table exists)
  try {
    $pdo->exec('CREATE TABLE IF NOT EXISTS transfer_audit_log (
      id INT AUTO_INCREMENT PRIMARY KEY,
      transfer_id INT NOT NULL,
      action VARCHAR(64) NOT NULL,
      status VARCHAR(16) NOT NULL,
      meta JSON NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX(transfer_id), INDEX(action)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $ins=$pdo->prepare('INSERT INTO transfer_audit_log(transfer_id,action,status,meta) VALUES(?,?,?,?)');
    $ins->execute([$transferId,'vend.consignment.store','ok', json_encode(['vend_id'=>$vendId,'vend_number'=>$vendNum,'pipeline_ref'=>$pipelineRef], JSON_UNESCAPED_SLASHES)]);
  } catch(Throwable $e){ error_log('[consignment.store] audit error: '.$e->getMessage()); }
  echo json_encode(['ok'=>true], JSON_UNESCAPED_SLASHES); exit;
} catch(Throwable $e){ cs_fail(500,'UNEXPECTED',$e->getMessage()); }
