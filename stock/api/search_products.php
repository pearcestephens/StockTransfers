<?php
declare(strict_types=1);
/**
 * Product search API for add product functionality
 * POST: JSON with { query, transfer_id }
 * Output: { success: true, products: [...] }
 */
require_once $_SERVER['DOCUMENT_ROOT'].'/app.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/modules/transfers/_local_shims.php';

header('Content-Type: application/json');

function out(array $o, int $code=200){ 
    http_response_code($code); 
    echo json_encode($o, JSON_UNESCAPED_SLASHES); 
    exit; 
}

$requestId = bin2hex(random_bytes(6));

if (empty($_SESSION['userID'])) {
    out(['success' => false, 'error' => 'Not authenticated', 'request_id' => $requestId], 401);
}

// Handle JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    // Fallback to GET parameters
    $q = trim((string)($_GET['q'] ?? ''));
    $transferId = (int)($_GET['transfer_id'] ?? 0);
} else {
    $q = trim((string)($data['query'] ?? ''));
    $transferId = (int)($data['transfer_id'] ?? 0);
}

if (mb_strlen($q) > 64) { 
    $q = mb_substr($q, 0, 64); 
}

$limit = 50; // Reasonable limit for search results

try {
    $pdo = cis_pdo();
    
    // Get transfer details for outlet context
    $transferSql = "SELECT from_outlet_id FROM transfer_view WHERE id = ?";
    $transferStmt = $pdo->prepare($transferSql);
    $transferStmt->execute([$transferId]);
    $transfer = $transferStmt->fetch(PDO::FETCH_ASSOC);
    
    $outletId = $transfer['from_outlet_id'] ?? '';
    
    $where = [
        'p.is_deleted = 0',
        '(p.is_active = 1 OR p.is_active IS NULL)',
        '(p.active = 1 OR p.active IS NULL)',
        '(p.has_inventory = 1 OR p.has_inventory IS NULL)'
    ];
    $params = [];

    // If query too short, return empty set
    if ($q !== '' && mb_strlen($q) < 2) {
        out(['success' => true, 'products' => [], 'request_id' => $requestId]);
    }

    // Search criteria
    if ($q !== '') {
        // Escape wildcard characters
        $esc = str_replace(['%','_'], ['\\%','\\_'], $q);
        $where[] = '(p.name LIKE :q OR p.sku LIKE :q OR p.id LIKE :q)';
        $params['q'] = '%'.$esc.'%';
    }

    // Include inventory for stock levels
    $invJoin = '';
    if ($outletId !== '') {
        $invJoin = 'LEFT JOIN vend_inventory vi ON vi.product_id = p.id AND vi.outlet_id = :outlet';
        $params['outlet'] = $outletId;
    }

    $sql = "SELECT 
                p.id, 
                p.sku, 
                p.name, 
                p.image_thumbnail_url AS image_url, 
                p.price_including_tax AS price,
                p.brand,
                ".($outletId !== '' ? 'COALESCE(vi.current_amount, 0)' : '0')." AS stock_qty
            FROM vend_products p
            $invJoin
            WHERE ".implode(' AND ', $where)."
            ORDER BY p.updated_at DESC, p.id DESC
            LIMIT $limit";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Format products for frontend
    $formattedProducts = array_map(function($product) {
        return [
            'id' => $product['id'],
            'sku' => $product['sku'] ?: '',
            'name' => $product['name'] ?: '',
            'image_url' => $product['image_url'] ?: '',
            'price' => $product['price'] ? number_format((float)$product['price'], 2) : null,
            'brand' => $product['brand'] ?: '',
            'stock_qty' => (int)($product['stock_qty'] ?: 0)
        ];
    }, $products);

    out([
        'success' => true, 
        'products' => $formattedProducts, 
        'count' => count($formattedProducts),
        'request_id' => $requestId
    ]);

} catch (Exception $e) {
    error_log("Product search error: " . $e->getMessage());
    out([
        'success' => false, 
        'error' => 'Search failed', 
        'request_id' => $requestId
    ], 500);
}
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
