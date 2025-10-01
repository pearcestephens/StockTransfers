<?php
http_response_code(410);
echo 'deprecated test_lock_enforcement';
        echo "<div style='background: #fff3cd; padding: 10px; border: 1px solid #ffeaa7; border-radius: 5px;'>\n";
        echo "<strong>🔒 LOCK EXISTS</strong><br>\n";
        echo "Lock ID: " . $currentLock['id'] . "<br>\n";
        echo "User ID: " . $currentLock['user_id'] . "<br>\n";
        echo "Transfer ID: " . htmlspecialchars($currentLock['transfer_id']) . "<br>\n";
        echo "Acquired: " . $currentLock['acquired_at'] . "<br>\n";
        echo "Expires: " . $currentLock['expires_at'] . "<br>\n";
        
        $lockHolderId = (int)$currentLock['user_id'];
        $holderName = $staffResolver->name($lockHolderId);
        echo "Lock Holder: " . htmlspecialchars($holderName) . " (ID: $lockHolderId)<br>\n";
        
        if ($lockHolderId === $currentUserId) {
            echo "<div style='color: green; font-weight: bold; margin-top: 10px;'>✅ YOU OWN THIS LOCK</div>\n";
        } else {
            echo "<div style='color: red; font-weight: bold; margin-top: 10px;'>❌ LOCKED BY SOMEONE ELSE</div>\n";
        }
        echo "</div>\n";
    } else {
        echo "<div style='background: #d1ecf1; padding: 10px; border: 1px solid #bee5eb; border-radius: 5px;'>\n";
        echo "<strong>🔓 NO LOCK EXISTS</strong><br>\n";
        echo "Transfer is available for locking\n";
        echo "</div>\n";
    }
    
    echo "<h2>3. Lock Status Array (as pack.php generates it)</h2>\n";
    
    // Build lock status exactly like pack.php does
    $lockStatus = [
        'has_lock' => false,
        'is_locked_by_other' => false,
        'holder_name' => null,
        'holder_id' => null,
        'can_request' => false,
        'lock_expires_at' => null,
        'lock_acquired_at' => null
    ];

    if ($currentLock) {
        $lockHolderId = (int)$currentLock['user_id'];
        if ($lockHolderId === $currentUserId) {
            $lockStatus['has_lock'] = true;
            $lockStatus['lock_expires_at'] = $currentLock['expires_at'];
            $lockStatus['lock_acquired_at'] = $currentLock['acquired_at'];
        } else {
            $lockStatus['is_locked_by_other'] = true;
            $lockStatus['holder_name'] = $staffResolver->name($lockHolderId);
            $lockStatus['holder_id'] = $lockHolderId;
            $lockStatus['can_request'] = true;
        }
    } else {
        $lockStatus['can_request'] = true;
    }
    
    echo "<pre>" . print_r($lockStatus, true) . "</pre>\n";
    
    echo "<h2>4. JavaScript Initialization Check</h2>\n";
    echo "<p>This is what would be output in the pack.view.php file:</p>\n";
    echo "<pre>let lockStatus = " . json_encode($lockStatus, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . ";</pre>\n";
    
    echo "<h2>5. Expected Behavior</h2>\n";
    if ($lockStatus['has_lock']) {
        echo "<div style='color: green; font-weight: bold;'>✅ User should have FULL ACCESS (editing enabled)</div>\n";
    } elseif ($lockStatus['is_locked_by_other']) {
        echo "<div style='color: red; font-weight: bold;'>❌ User should see READ-ONLY mode with sticky bar</div>\n";
        echo "<p>Holder: " . htmlspecialchars($lockStatus['holder_name']) . "</p>\n";
    } else {
        echo "<div style='color: orange; font-weight: bold;'>⚠️ No lock - user should be able to acquire lock automatically</div>\n";
    }
    
    echo "<h2>6. Test Lock Acquisition</h2>\n";
    echo "<p><a href='api/lock_acquire.php?transfer_id=" . urlencode($txId) . "&mode=manual' target='_blank'>Test Manual Lock Acquisition</a></p>\n";
    echo "<p><a href='api/lock_status.php?transfer_id=" . urlencode($txId) . "' target='_blank'>Test Lock Status API</a></p>\n";
    
} catch (Exception $e) {
    echo "<div style='color: red; background: #f8d7da; padding: 10px; border: 1px solid #f5c6cb; border-radius: 5px;'>\n";
    echo "<strong>ERROR:</strong> " . htmlspecialchars($e->getMessage()) . "\n";
    echo "</div>\n";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>\n";
}
?>