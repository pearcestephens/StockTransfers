<?php
declare(strict_types=1);

@date_default_timezone_set('Pacific/Auckland');

/**
 * Test Auto-Grant Workflow - Live Test
 * 
 * This creates a real ownership request scenario and tests the auto-grant system.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/app.php';

// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

try {
    $pdo = cis_pdo();
    
    $transferId = isset($_GET['transfer_id']) ? (int)$_GET['transfer_id'] : 13219;
    $testUserId = 2; // User making the request (different from current lock holder)
    
    echo "<h3>🧪 Live Auto-Grant Workflow Test</h3>\n";
    echo "<pre>";
    
    // Step 1: Check current lock status
    echo "1. Current lock status for transfer $transferId:\n";
    $lockStmt = $pdo->prepare("SELECT * FROM transfer_pack_locks WHERE transfer_id = ? AND expires_at > NOW()");
    $lockStmt->execute([$transferId]);
    $currentLock = $lockStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($currentLock) {
        echo "   → Locked by User {$currentLock['user_id']} until {$currentLock['expires_at']}\n";
        $lockHolderId = (int)$currentLock['user_id'];
    } else {
        echo "   → No active lock found\n";
        echo "   → Creating test lock for User 1...\n";
        
        <?php
        http_response_code(410);
        header('Content-Type: application/json');
        echo json_encode(['success'=>false,'error'=>'_test_auto_grant_live deprecated']);
        exit;
        WHERE r.status = 'pending' 
        AND r.expires_at <= NOW()
        AND r.transfer_id = ?
    ");
    $expiredStmt->execute([$transferId]);
    $expiredRequests = $expiredStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "   → Found " . count($expiredRequests) . " expired requests\n";
    
    if (!empty($expiredRequests)) {
        foreach ($expiredRequests as $req) {
            echo "     - Request ID {$req['id']} from User {$req['user_id']} (expired at {$req['expires_at']})\n";
        }
        
        // Step 5: Test the auto-grant service
        echo "\n5. Running auto-grant service...\n";
        
        $processed = 0;
        foreach ($expiredRequests as $request) {
            $pdo->beginTransaction();
            
            try {
                // Auto-grant logic (same as in auto_grant_service.php)
                
                // Remove existing lock
                $pdo->prepare("DELETE FROM transfer_pack_locks WHERE transfer_id = ?")->execute([$request['transfer_id']]);
                
                // Grant new lock to requester
                $acquiredAt = date('Y-m-d H:i:s');
                $expiresAt = date('Y-m-d H:i:s', time() + 3600);
                
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
                    'auto-granted-live-test'
                ]);
                
                // Mark request as accepted
                $pdo->prepare("
                    UPDATE transfer_pack_lock_requests 
                    SET status = 'accepted', responded_at = NOW()
                    WHERE id = ?
                ")->execute([$request['id']]);
                
                $pdo->commit();
                $processed++;
                
                echo "     ✅ Auto-granted request ID {$request['id']} to User {$request['user_id']}\n";
                
            } catch (Exception $e) {
                $pdo->rollback();
                echo "     ❌ Failed to process request ID {$request['id']}: " . $e->getMessage() . "\n";
            }
        }
        
    } else {
        echo "   → No expired requests found (they may have been cleaned up already)\n";
    }
    
    // Step 6: Verify final state
    echo "\n6. Final verification:\n";
    
    $finalLockStmt = $pdo->prepare("SELECT * FROM transfer_pack_locks WHERE transfer_id = ? AND expires_at > NOW()");
    $finalLockStmt->execute([$transferId]);
    $finalLock = $finalLockStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($finalLock) {
        $newOwner = (int)$finalLock['user_id'];
        echo "   → Transfer now locked by User $newOwner\n";
        
        if ($newOwner === $testUserId) {
            echo "   ✅ SUCCESS: Ownership transferred via auto-grant!\n";
        } else if ($newOwner === $lockHolderId) {
            echo "   ⚠️  UNCHANGED: Original lock holder still has ownership\n";
        } else {
            echo "   ❓ UNEXPECTED: Lock is held by different user\n";
        }
    } else {
        echo "   → No active lock found\n";
    }
    
    $finalRequestStmt = $pdo->prepare("SELECT * FROM transfer_pack_lock_requests WHERE id = ?")->execute([$requestId]);
    $finalRequest = $pdo->prepare("SELECT * FROM transfer_pack_lock_requests WHERE id = ?")->execute([$requestId]);
    $finalRequestStmt = $pdo->prepare("SELECT * FROM transfer_pack_lock_requests WHERE id = ?");
    $finalRequestStmt->execute([$requestId]);
    $finalRequest = $finalRequestStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($finalRequest) {
        echo "   → Request status: {$finalRequest['status']}\n";
        if ($finalRequest['responded_at']) {
            echo "   → Responded at: {$finalRequest['responded_at']}\n";
        }
    }
    
    // Step 7: Cleanup (optional)
    $cleanup = isset($_GET['cleanup']) ? $_GET['cleanup'] === '1' : false;
    if ($cleanup) {
        echo "\n7. Cleaning up test data...\n";
        $pdo->prepare("DELETE FROM transfer_pack_locks WHERE transfer_id = ? AND client_fingerprint LIKE '%test%'")->execute([$transferId]);
        $pdo->prepare("DELETE FROM transfer_pack_lock_requests WHERE transfer_id = ? AND client_fingerprint LIKE '%test%'")->execute([$transferId]);
        echo "   → Test data cleaned up\n";
    } else {
        echo "\n7. Test data preserved (add ?cleanup=1 to URL to clean up)\n";
    }
    
    echo "\n✨ Live test completed!\n";
    echo "</pre>";
    
    // Also provide JSON output for API testing
    echo "\n<hr>\n<h4>JSON Output:</h4>\n<pre>";
    
    $result = [
        'success' => true,
        'test_completed' => true,
        'transfer_id' => $transferId,
        'original_lock_holder' => $lockHolderId,
        'test_requester' => $testUserId,
        'expired_requests_found' => count($expiredRequests ?? []),
        'requests_processed' => $processed ?? 0,
        'final_lock_holder' => $finalLock ? (int)$finalLock['user_id'] : null,
        'auto_grant_successful' => isset($finalLock) && (int)$finalLock['user_id'] === $testUserId,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    echo json_encode($result, JSON_PRETTY_PRINT);
    echo "</pre>";
    
} catch (Exception $e) {
    echo "<pre>❌ Test failed with error: " . $e->getMessage() . "</pre>";
    echo "<pre>Stack trace:\n" . $e->getTraceAsString() . "</pre>";
}
?>