<?php
// Debug current state
require_once $_SERVER['DOCUMENT_ROOT'] . '/app.php';

// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

try {
    $pdo = cis_pdo();
    
    $transferId = isset($_GET['transfer_id']) ? (int)$_GET['transfer_id'] : 13219;
    
    // Get current lock
    $lockStmt = $pdo->prepare("
        SELECT * FROM transfer_pack_locks 
        WHERE transfer_id = ? AND expires_at > NOW()
    ");
    $lockStmt->execute([$transferId]);
    $currentLock = $lockStmt->fetch(PDO::FETCH_ASSOC);
    
    // Get all requests (including expired)
    $requestsStmt = $pdo->prepare("
        SELECT *, 
               CASE WHEN expires_at <= NOW() THEN 'expired' ELSE 'active' END as status_check,
               TIMESTAMPDIFF(SECOND, NOW(), expires_at) as seconds_until_expiry
        FROM transfer_pack_lock_requests 
        WHERE transfer_id = ?
        ORDER BY requested_at DESC
    ");
    $requestsStmt->execute([$transferId]);
    $allRequests = $requestsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'transfer_id' => $transferId,
        'current_time' => date('Y-m-d H:i:s'),
        'current_lock' => $currentLock,
        'all_requests' => $allRequests,
        'workflow_issue' => 'Auto-grant should happen when request expires but lock holder has not responded'
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>