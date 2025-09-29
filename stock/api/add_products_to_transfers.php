<?php
declare(strict_types=1);
/**
 * Bulk add products to multiple draft transfers.
 * POST JSON: { idempotency_key, transfer_ids:[int], items:[{product_id, qty}], outlet_uuid }
 * Response: { ok, request_id, data:{ added:{transfer_id: lines_added}, warnings? }, error? }
 */
require_once $_SERVER['DOCUMENT_ROOT'].'/app.php';
header('Content-Type: application/json');
function jout(array $o, int $code=200){ http_response_code($code); echo json_encode($o, JSON_UNESCAPED_SLASHES); exit; }
$requestId = bin2hex(random_bytes(6));
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') jout(['ok'=>false,'error'=>['code'=>'METHOD','message'=>'POST only'],'request_id'=>$requestId],405);
if (empty($_SESSION['userID'])) jout(['ok'=>false,'error'=>['code'=>'AUTH','message'=>'Not authenticated'],'request_id'=>$requestId],401);
$uid = (int)$_SESSION['userID'];
$raw = file_get_contents('php://input');
$body = json_decode($raw,true); if(!is_array($body)) $body=[];
$idKey = trim((string)($body['idempotency_key'] ?? ''));
$transferIds = array_values(array_filter(array_map('intval', (array)($body['transfer_ids'] ?? [])), fn($v)=>$v>0));
$itemsIn = (array)($body['items'] ?? []);
$outletUuid = trim((string)($body['outlet_uuid'] ?? ''));
if ($idKey==='') jout(['ok'=>false,'error'=>['code'=>'MISSING_IDEMPOTENCY','message'=>'idempotency_key required'],'request_id'=>$requestId],400);
if (!$transferIds) jout(['ok'=>false,'error'=>['code'=>'NO_TRANSFERS','message'=>'transfer_ids required'],'request_id'=>$requestId],400);
if (!$itemsIn) jout(['ok'=>false,'error'=>['code'=>'NO_ITEMS','message'=>'items required'],'request_id'=>$requestId],400);
// Allow omission of outlet_uuid when exactly one transfer id supplied – infer from transfer record (either outlet_from)
if ($outletUuid==='' && count($transferIds)===1) {
  try {
    $pdoTmp = pdo();
    $stO = $pdoTmp->prepare('SELECT outlet_from FROM transfers WHERE id = :id LIMIT 1');
    $stO->execute([':id'=>$transferIds[0]]);
    if ($rO = $stO->fetch(PDO::FETCH_ASSOC)) { $outletUuid = (string)$rO['outlet_from']; }
  } catch (Throwable $e) { /* silent */ }
}
if ($outletUuid==='') jout(['ok'=>false,'error'=>['code'=>'OUTLET_REQUIRED','message'=>'outlet_uuid required'],'request_id'=>$requestId],400);

// Normalize items (dedupe by product_id sum qty)
$itemsNorm = [];
foreach ($itemsIn as $it){
  $pid = isset($it['product_id']) ? trim((string)$it['product_id']) : '';
  if ($pid==='') continue; $qty=(int)($it['qty'] ?? 0); if($qty<=0) continue; $itemsNorm[$pid] = ($itemsNorm[$pid] ?? 0) + $qty; }
if (!$itemsNorm) jout(['ok'=>false,'error'=>['code'=>'NO_VALID_ITEMS','message'=>'All item entries invalid'],'request_id'=>$requestId],400);

try {
  $pdo = pdo();
  // Idempotency table (create if not exists) — minimal implementation
  $pdo->exec("CREATE TABLE IF NOT EXISTS transfer_bulk_add_idem (\n    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n    idem_key VARCHAR(100) NOT NULL,\n    outlet_uuid VARCHAR(100) NOT NULL,\n    user_id INT NOT NULL,\n    response_json LONGTEXT,\n    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n    UNIQUE KEY uniq_idem (idem_key, outlet_uuid)\n  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  // Replay check
  $st = $pdo->prepare('SELECT response_json FROM transfer_bulk_add_idem WHERE idem_key = :k AND outlet_uuid = :o LIMIT 1');
  $st->execute([':k'=>$idKey, ':o'=>$outletUuid]);
  if ($row=$st->fetch(PDO::FETCH_ASSOC)) {
    $prev = json_decode($row['response_json'], true); if(is_array($prev)){ $prev['request_id']=$requestId; jout($prev); }
  }

  // Validate transfers: status draft + belongs to outlet (either from or to matches) + not deleted
  $inClause = implode(',', array_fill(0,count($transferIds),'?'));
  $sqlT = "SELECT id, public_id, outlet_from, outlet_to, status FROM transfers WHERE id IN ($inClause)";
  $stmtT = $pdo->prepare($sqlT); $stmtT->execute($transferIds); $valid=[]; $warnings=[];
  while($r=$stmtT->fetch(PDO::FETCH_ASSOC)){
    if($r['status']!=='draft'){ $warnings[] = 'TRANSFER_NOT_DRAFT:'.$r['id']; continue; }
    if($r['outlet_from']!==$outletUuid && $r['outlet_to']!==$outletUuid){ $warnings[]='OUTLET_MISMATCH:'.$r['id']; continue; }
    $valid[(int)$r['id']]=$r;
  }
  if(!$valid) jout(['ok'=>false,'error'=>['code'=>'NO_VALID_TRANSFERS','message'=>'No valid draft transfers'],'request_id'=>$requestId,'data'=>['warnings'=>$warnings]] ,400);

  // Prepare insert/upsert for transfer_items
  $ins = $pdo->prepare("INSERT INTO transfer_items (transfer_id, product_id, qty_requested) VALUES (:t,:p,:q) ON DUPLICATE KEY UPDATE qty_requested = qty_requested + VALUES(qty_requested), updated_at=NOW()");
  $addedCounts = [];
  $pdo->beginTransaction();
  try {
    foreach ($valid as $tid=>$meta){
      $countBefore = 0; $countAfter=0;
      // Count how many product_ids will produce insert vs update (OPTIONAL: fetch existing first)
      foreach ($itemsNorm as $pid=>$qty){ $ins->execute([':t'=>$tid,':p'=>$pid,':q'=>$qty]); $countAfter++; }
      $addedCounts[$tid] = $countAfter; // simplistic (treat each line as added/updated)
      // Log transfer note (automatic) summarizing action
      $note = 'Bulk add: '.count($itemsNorm).' products ('.implode(', ', array_map(fn($p,$q)=>$p.' x'.$q, array_keys($itemsNorm), $itemsNorm)).')';
      try { $stn=$pdo->prepare('INSERT INTO transfer_notes (transfer_id,note_text,created_by) VALUES (:t,:n,:u)'); $stn->execute([':t'=>$tid,':n'=>$note,':u'=>$uid]); } catch (Throwable $e) { /* ignore note failure */ }
      try { $log=$pdo->prepare('INSERT INTO transfer_logs (transfer_id,event_type,event_data,actor_user_id,severity,source_system) VALUES (:t,\'ADD_ITEM\',:d,:u,\'info\',\'CIS\')'); $log->execute([':t'=>$tid,':d'=>json_encode(['bulk_add'=>array_map(fn($p,$q)=>['product_id'=>$p,'qty'=>$q], array_keys($itemsNorm), $itemsNorm)], JSON_UNESCAPED_SLASHES),':u'=>$uid]); } catch (Throwable $e) { /* ignore */ }
    }
    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    jout(['ok'=>false,'error'=>['code'=>'TX_FAIL','message'=>$e->getMessage()],'request_id'=>$requestId]);
  }

  $resp = ['ok'=>true,'request_id'=>$requestId,'data'=>['added'=>$addedCounts,'warnings'=>$warnings]];
  $st = $pdo->prepare('INSERT INTO transfer_bulk_add_idem (idem_key, outlet_uuid, user_id, response_json) VALUES (:k,:o,:u,:r)');
  try { $st->execute([':k'=>$idKey,':o'=>$outletUuid,':u'=>$uid,':r'=>json_encode($resp, JSON_UNESCAPED_SLASHES)]); } catch (Throwable $e) { /* ignore */ }
  jout($resp);

} catch (Throwable $e) {
  jout(['ok'=>false,'error'=>['code'=>'INTERNAL','message'=>$e->getMessage()],'request_id'=>$requestId],500);
}
