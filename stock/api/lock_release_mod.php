<?php
declare(strict_types=1);
/** Canonical lock release */
require_once $_SERVER['DOCUMENT_ROOT'].'/app.php';
header('Content-Type: application/json');
use Modules\Transfers\Stock\Services\PackLockService;
function out($ok,array $d=[],int $c=200){ http_response_code($c); echo json_encode($ok? ['success'=>true]+$d:['success'=>false,'error'=>$d], JSON_UNESCAPED_SLASHES); exit; }
$uid=(int)($_SESSION['userID']??0); if($uid<=0) out(false,['message'=>'Auth required'],401);
$transferId = $_POST['transfer_id'] ?? ($_GET['transfer_id'] ?? ''); if($transferId==='') out(false,['message'=>'Missing transfer_id'],400);
try{ $svc=new PackLockService(); $lock=$svc->getLock($transferId); if(!$lock) out(true,['released'=>false,'message'=>'No active lock']); if((int)$lock['user_id']!==$uid){ out(false,['message'=>'Not holder'],403);} $res=$svc->releaseLock($transferId,$uid,false); out(($res['success']??false),['released'=>($res['success']??false)]); }catch(Throwable $e){ out(false,['message'=>$e->getMessage()],500);} ?>