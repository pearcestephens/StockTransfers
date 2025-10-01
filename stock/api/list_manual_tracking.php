<?php
declare(strict_types=1);
/**
 * List Manual/Internal Tracking Entries (non-deleted)
 * GET: ?transfer_id=12345
 * Response: { ok, request_id, data:{ rows:[...] } }
 */
header('Content-Type: application/json; charset=utf-8');
require_once $_SERVER['DOCUMENT_ROOT'].'/modules/transfers/_local_shims.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/app.php';
$REQ_ID = bin2hex(random_bytes(8));
function env_ok(array $d){ return ['ok'=>true,'request_id'=>$GLOBALS['REQ_ID'],'data'=>$d]; }
function env_err($c,$m,$s=400){ http_response_code($s); echo json_encode(['ok'=>false,'request_id'=>$GLOBALS['REQ_ID'],'error'=>['code'=>$c,'message'=>$m]]); exit; }
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET') env_err('METHOD_NOT_ALLOWED','GET only',405);
if(empty($_GET['transfer_id'])) env_err('VALIDATION','transfer_id required',422);
$tid=(int)$_GET['transfer_id']; if($tid<=0) env_err('VALIDATION','transfer_id invalid',422);
try{ $pdo=pdo(); $st=$pdo->prepare('SELECT id,transfer_id,mode,tracking,carrier_code,carrier_name,notes,created_at FROM transfer_manual_tracking WHERE transfer_id=:t AND deleted_at IS NULL ORDER BY id ASC'); $st->execute([':t'=>$tid]); $rows=$st->fetchAll(PDO::FETCH_ASSOC); echo json_encode(env_ok(['rows'=>$rows]), JSON_UNESCAPED_SLASHES); }catch(Throwable $e){ env_err('SERVER_ERROR','Could not list tracking',500);}