<?php
declare(strict_types=1);
/**
 * List draft transfers assigned to a specific outlet (destination or source — using outlet_to by requirement context)
 * GET params:
 *  - status=draft (required for now; only draft supported)
 *  - assigned_to=<OUTLET_UUID> (destination outlet uuid)
 *  - q (search across id, public_id, notes, outlet_to name)
 *  - page (1-based)
 * Output envelope: { ok, data:{ rows:[], has_more }, request_id }
 */
require_once $_SERVER['DOCUMENT_ROOT'].'/app.php';
header('Content-Type: application/json');

function out(array $o, int $code=200){ http_response_code($code); echo json_encode($o, JSON_UNESCAPED_SLASHES); exit; }
$requestId = bin2hex(random_bytes(6));
if (empty($_SESSION['userID'])) out(['ok'=>false,'error'=>['code'=>'AUTH','message'=>'Not authenticated'],'request_id'=>$requestId],401);
$staffId = (int)$_SESSION['userID'];

$status = isset($_GET['status']) ? trim((string)$_GET['status']) : 'draft';
if ($status !== 'draft') out(['ok'=>false,'error'=>['code'=>'UNSUPPORTED_STATUS','message'=>'Only draft supported'],'request_id'=>$requestId],400);
$outletUuid = trim((string)($_GET['assigned_to'] ?? ''));
if ($outletUuid==='') out(['ok'=>false,'error'=>['code'=>'MISSING_OUTLET','message'=>'assigned_to outlet uuid required'],'request_id'=>$requestId],400);
$q = trim((string)($_GET['q'] ?? ''));
$page = max(1,(int)($_GET['page'] ?? 1));
$limit = 20; $offset = ($page-1)*$limit;

try {
  $pdo = pdo();
  $where = ['t.status = :status','t.outlet_from <> t.outlet_to'];
  // Assigned to this outlet (= from outlet? or destination?). Requirements: "assigned_to your outlet" for packing likely means outlet_from = this outlet (Supplying). We'll support either by membership.
  // We'll match if either side equals the uuid to be flexible, but prioritize ones where the staff's outlet is source OR destination while status=draft.
  $where[] = '(t.outlet_from = :ou OR t.outlet_to = :ou)';
  $params = ['status'=>$status,'ou'=>$outletUuid];
  if ($q !== '') {
    $where[] = '(t.public_id LIKE :q OR t.notes LIKE :q)';
    $params['q'] = '%'.$q.'%';
  }
  $sql = "SELECT t.id, t.public_id, t.outlet_to, t.outlet_from, t.notes, t.updated_at,
                 (SELECT COUNT(*) FROM transfer_items ti WHERE ti.transfer_id=t.id AND ti.deleted_at IS NULL) AS item_count,
                 o_to.name AS to_outlet_name, o_from.name AS from_outlet_name
            FROM transfers t
            LEFT JOIN vend_outlets o_to ON o_to.id = t.outlet_to
            LEFT JOIN vend_outlets o_from ON o_from.id = t.outlet_from
           WHERE ".implode(' AND ',$where)." 
           ORDER BY t.updated_at DESC
           LIMIT $limit OFFSET $offset";
  $st = $pdo->prepare($sql); $st->execute($params); $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  // Normalize timestamps => ISO 8601
  foreach ($rows as &$r){ if(!empty($r['updated_at'])) $r['updated_at']=gmdate('c', strtotime($r['updated_at'])); }
  $hasMore = count($rows)===$limit;
  out(['ok'=>true,'data'=>['rows'=>$rows,'has_more'=>$hasMore],'request_id'=>$requestId]);
} catch (Throwable $e) {
  out(['ok'=>false,'error'=>['code'=>'INTERNAL','message'=>$e->getMessage()],'request_id'=>$requestId],500);
}
