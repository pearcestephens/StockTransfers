<?php
declare(strict_types=1);

@date_default_timezone_set('Pacific/Auckland');

$DOCUMENT_ROOT = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$realDocRoot = realpath(__DIR__ . '/../../../');

if ($DOCUMENT_ROOT === '' || !is_dir($DOCUMENT_ROOT)) {
  $DOCUMENT_ROOT = $realDocRoot;
}

require_once $DOCUMENT_ROOT . '/app.php';

header('Content-Type: application/json');

try {
    $pdo = cis_pdo();
    
    // Get ALL requests (including expired) for debugging
    $stmt = $pdo->query("
        SELECT r.*, 
               l.user_id as lock_holder_id,
               CASE 
                 WHEN r.expires_at > NOW() THEN 'active'
                 ELSE 'expired'
               END as status_check,
               TIMESTAMPDIFF(SECOND, NOW(), r.expires_at) as seconds_until_expiry
        FROM transfer_pack_lock_requests r
        LEFT JOIN transfer_pack_locks l ON r.transfer_id = l.transfer_id AND l.expires_at > NOW()
        ORDER BY r.requested_at DESC
        LIMIT 10
    ");
    
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Also check the table structure
    $tableStmt = $pdo->query("SHOW COLUMNS FROM transfer_pack_lock_requests");
    $columns = $tableStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'debug' => true,
        'total_requests' => count($requests),
        'requests' => $requests,
        'table_columns' => $columns,
        'server_time' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>