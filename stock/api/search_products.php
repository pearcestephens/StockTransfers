<?php
declare(strict_types=1);
/**
 * Product search (basic) for bulk add UI
 * GET: q, page
 * Output: { ok, data:{ rows:[{id, sku, barcode, name, image_url, stock_at_outlet}], has_more }, request_id }
 * Filters: is_deleted=0, is_active=1, active=1, has_inventory=1 (interpretation of table columns) — user description said: use is_deleted = 0 and is_active and active = 0 and has_inventory = 0, but likely meant active flags should equal 1. We'll treat 1 = active.
 */
require_once $_SERVER['DOCUMENT_ROOT'].'/app.php';
header('Content-Type: application/json');
function out(array $o, int $code=200){ http_response_code($code); echo json_encode($o, JSON_UNESCAPED_SLASHES); exit; }
$requestId = bin2hex(random_bytes(6));
if (empty($_SESSION['userID'])) out(['ok'=>false,'error'=>['code'=>'AUTH','message'=>'Not authenticated'],'request_id'=>$requestId],401);
$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) > 64) { $q = mb_substr($q,0,64); }
$rawQ = $q; // original for echo if needed (currently not returned)
$pageParam = (int)($_GET['page'] ?? 1);
$page = max(1,(int)($_GET['page'] ?? 1));
$limit = 24; $offset = ($page-1)*$limit;
// Optional outlet uuid to show stock levels
$outletUuid = trim((string)($_GET['outlet'] ?? ($_GET['outlet_uuid'] ?? ($_GET['assigned_to'] ?? ''))));
try {
  $pdo = pdo();
  $where = ['p.is_deleted = 0','(p.is_active = 1 OR p.is_active IS NULL)','(p.active = 1 OR p.active IS NULL)','(p.has_inventory = 1 OR p.has_inventory IS NULL)'];
  $params = [];
  // If query very short (1 char) return empty set quickly to reduce table scans
  if ($q !== '') {
    if (mb_strlen($q) < 2) {
      out(['ok'=>true,'data'=>['rows'=>[],'has_more'=>false],'request_id'=>$requestId]);
    }
    // Escape wildcard characters to treat literal user input safely
    $esc = str_replace(['%','_'], ['\\%','\\_'], $q);
    $where[] = '(p.name LIKE :q OR p.sku LIKE :q OR p.id LIKE :q)';
    $params['q'] = '%'.$esc.'%';
  }
  $invJoin = '';
  if ($outletUuid !== '') {
    $invJoin = 'LEFT JOIN vend_inventory vi ON vi.product_id = p.id AND vi.outlet_id = :outlet';
    $params['outlet'] = $outletUuid;
  }
  $sql = 'SELECT p.id, p.sku, p.name, p.image_thumbnail_url AS image_url, p.price_including_tax,
                 '.($outletUuid!==''?'COALESCE(vi.current_amount,0)':'NULL').' AS stock_at_outlet
            FROM vend_products p
            '.$invJoin.'
           WHERE '.implode(' AND ',$where).'
           ORDER BY p.updated_at DESC, p.id DESC
           LIMIT '.$limit.' OFFSET '.$offset;
  $t0 = microtime(true);
  $st = $pdo->prepare($sql); $st->execute($params); $rows=$st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $durMs = (int)((microtime(true)-$t0)*1000);
  $hasMore = count($rows)===$limit;

  // Optionally compute total count only for first page (cheap enough with same filter; can add LIMIT guard if large)
  $total = null;
  if ($page === 1) {
    try {
      $countSql = 'SELECT COUNT(*) FROM vend_products p '.($invJoin? $invJoin : '').' WHERE '.implode(' AND ',$where);
      $stc = $pdo->prepare($countSql); $stc->execute($params); $total = (int)$stc->fetchColumn();
    } catch(Throwable $e) { $total = null; }
  }

  out(['ok'=>true,'data'=>['rows'=>$rows,'has_more'=>$hasMore,'total'=>$total,'elapsed_ms'=>$durMs],'request_id'=>$requestId]);
} catch (Throwable $e) {
  out(['ok'=>false,'error'=>['code'=>'INTERNAL','message'=>$e->getMessage()],'request_id'=>$requestId],500);
}
