<?php
declare(strict_types=1);

@date_default_timezone_set('Pacific/Auckland');

/**
 * Auto-Grant Service
 * 
 * This script handles automatic ownership grants for expired requests.
 * It should be called periodically (every 30 seconds) to process expired requests.
 * 
 * Unlike manual accept/decline which requires the lock holder to respond,
 * this service automatically grants ownership when:
 * 1. A request has expired (60+ seconds old)
 * 2. The original lock holder hasn't responded
 * 3. The request is still pending
 */

// Load the main system configuration
try {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/app.php';
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Failed to load app.php',
        'details' => $e->getMessage()
    ]);
    exit;
}

header('Content-Type: application/json');

try {
    $pdo = cis_pdo();
    
    // Find all expired requests that are still pending
    $expiredRequestsStmt = $pdo->prepare("
        SELECT r.id, r.transfer_id, r.user_id, r.requested_at, r.expires_at,
               l.user_id as current_lock_holder
        FROM transfer_pack_lock_requests r
        LEFT JOIN transfer_pack_locks l ON r.transfer_id = l.transfer_id AND l.expires_at > NOW()
        WHERE r.status = 'pending' 
        AND r.expires_at <= NOW()
        ORDER BY r.requested_at ASC
    ");
    
    $expiredRequestsStmt->execute();
    $expiredRequests = $expiredRequestsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $processed = [];
    $errors = [];
    
    foreach ($expiredRequests as $request) {
        $pdo->beginTransaction();
        
        try {
            // Auto-grant ownership to the requester
            
            // 1. Remove any existing lock for this transfer
            $removeLockStmt = $pdo->prepare("DELETE FROM transfer_pack_locks WHERE transfer_id = ?");
            $removeLockStmt->execute([$request['transfer_id']]);
            
            // 2. Create new lock for the requester
            $acquiredAt = date('Y-m-d H:i:s');
            $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 hour
            
            $grantLockStmt = $pdo->prepare("
                INSERT INTO transfer_pack_locks 
                (transfer_id, user_id, acquired_at, expires_at, heartbeat_at, client_fingerprint)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $grantLockStmt->execute([
                $request['transfer_id'],
                $request['user_id'],
                $acquiredAt,
                $expiresAt,
                $acquiredAt,
                'auto-granted-by-service'
            ]);
            
            // 3. Mark the request as accepted
            $updateRequestStmt = $pdo->prepare("
                UPDATE transfer_pack_lock_requests 
                SET status = 'accepted', responded_at = NOW()
                WHERE id = ?
            ");
            $updateRequestStmt->execute([$request['id']]);
            
            // 4. Cancel any other pending requests for this transfer
            $cancelOthersStmt = $pdo->prepare("
                UPDATE transfer_pack_lock_requests 
                SET status = 'cancelled'
                WHERE transfer_id = ? AND id != ? AND status = 'pending'
            ");
            $cancelOthersStmt->execute([$request['transfer_id'], $request['id']]);
            
            $pdo->commit();
            
            $processed[] = [
                'request_id' => $request['id'],
                'transfer_id' => $request['transfer_id'],
                'new_owner_id' => $request['user_id'],
                'previous_owner_id' => $request['current_lock_holder'],
                'requested_at' => $request['requested_at'],
                'expired_at' => $request['expires_at'],
                'auto_granted_at' => $acquiredAt
            ];
            
        } catch (Exception $e) {
            $pdo->rollback();
            $errors[] = [
                'request_id' => $request['id'],
                'transfer_id' => $request['transfer_id'],
                'error' => $e->getMessage()
            ];
        }
    }
    
    // Also clean up old expired/cancelled requests (keep only last 24 hours)
    $cleanupStmt = $pdo->prepare("
        DELETE FROM transfer_pack_lock_requests 
        WHERE status IN ('declined', 'cancelled', 'accepted') 
        AND responded_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $cleanupStmt->execute();
    $cleanedUp = $cleanupStmt->rowCount();
    
    echo json_encode([
        'success' => true,
        'processed_count' => count($processed),
        'error_count' => count($errors),
        'cleaned_up_old_requests' => $cleanedUp,
        'processed_requests' => $processed,
        'errors' => $errors,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Auto-grant service failed: ' . $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
?>