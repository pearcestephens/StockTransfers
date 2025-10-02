<?php
declare(strict_types=1);
/** Canonical lock acquire */
require_once $_SERVER['DOCUMENT_ROOT'].'/app.php';
header('Content-Type: application/json');
use Modules\Transfers\Stock\Services\PackLockService;
use Modules\Transfers\Stock\Services\StaffNameResolver;
function out($ok,array $d=[],int $c=200){ http_response_code($c); echo json_encode($ok? ['success'=>true]+$d:['success'=>false,'error'=>$d], JSON_UNESCAPED_SLASHES); exit; }
$uid=(int)($_SESSION['userID']??0); if($uid<=0) out(false,['message'=>'Auth required'],401);
$transferId = $_POST['transfer_id'] ?? ($_GET['transfer_id'] ?? ''); if($transferId==='') out(false,['message'=>'Missing transfer_id'],400);
$fingerprint = $_POST['fingerprint'] ?? null;
try{ $svc=new PackLockService(); $resolver=new StaffNameResolver(); $res=$svc->acquire($transferId,$uid,$fingerprint); if(!($res['success']??false)){ if(!empty($res['conflict'])){ $holder=$res['holder']??[]; out(false,['message'=>'Lock held','holder'=>[ 'user_id'=>$holder['user_id']??null ]],409);} out(false,['message'=>'Acquire failed'],500);} $lock=$res['lock']??[]; $lock['holder_name']=$resolver->name((int)$lock['user_id']); out(true,['lock'=>$lock]); }catch(Throwable $e){ out(false,['message'=>$e->getMessage()],500);} ?>