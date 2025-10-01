<?php
declare(strict_types=1);

@date_default_timezone_set('Pacific/Auckland');

/**
 * Test Auto-Grant Workflow
 * 
 * This script creates a test scenario and then verifies auto-grant works:
 * 1. Creates a fake lock for user 1
 * 2. Creates an expired request from user 2
 * 3. Runs auto-grant service
<?php
// Neutralized legacy test file: _test_auto_grant.php (cleanup 2025-10-01)
http_response_code(410);
header('Content-Type: application/json');
echo json_encode([
  'success' => false,
  'error' => 'This test endpoint has been removed. See REMOVAL_MANIFEST_2025-10-01.'
]);
exit;
            ");
            
            $grantLockStmt->execute([
                $request['transfer_id'],
                $request['user_id'],
                $acquiredAt,
                $expiresAt,
                $acquiredAt,
                'auto-granted-test'
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
    
    // Step 6: Show final state
    echo "\n6. Final state AFTER auto-grant:\n";
    
    $lockQuery->execute([$testTransferId]);
    $finalLock = $lockQuery->fetch(PDO::FETCH_ASSOC);
    echo "   → Lock holder: User " . ($finalLock['user_id'] ?? 'NONE') . "\n";
    
    $requestQuery->execute([$testTransferId]);
    $finalPendingRequests = $requestQuery->fetchAll(PDO::FETCH_ASSOC);
    echo "   → Pending requests: " . count($finalPendingRequests) . "\n";
    
    $processedQuery = $pdo->prepare("SELECT * FROM transfer_pack_lock_requests WHERE transfer_id = ? AND status = 'accepted'");
    $processedQuery->execute([$testTransferId]);
    $acceptedRequests = $processedQuery->fetchAll(PDO::FETCH_ASSOC);
    echo "   → Accepted requests: " . count($acceptedRequests) . "\n";
    
    // Step 7: Results
    echo "\n7. 🎯 TEST RESULTS:\n";
    
    if ($processed > 0 && $finalLock && (int)$finalLock['user_id'] === $requesterId) {
        echo "   ✅ SUCCESS: Auto-grant worked! Ownership transferred from User $lockHolderId to User $requesterId\n";
    } elseif ($processed === 0) {
        echo "   ❌ FAILED: No requests were processed (check expiry logic)\n";
    } else {
        echo "   ❌ FAILED: Requests processed but ownership didn't transfer correctly\n";
    }
    
    // Step 8: Cleanup
    echo "\n8. Cleaning up test data...\n";
    $pdo->prepare("DELETE FROM transfer_pack_locks WHERE transfer_id = ?")->execute([$testTransferId]);
    $pdo->prepare("DELETE FROM transfer_pack_lock_requests WHERE transfer_id = ?")->execute([$testTransferId]);
    echo "   → Test data cleaned up\n";
    
    echo "\n✨ Test completed!\n";
    echo "</pre>";
    
} catch (Exception $e) {
    echo "<pre>❌ Test failed with error: " . $e->getMessage() . "</pre>";
}
?>