<?php
http_response_code(410);
echo 'deprecated debug_lock_status';
            $lockStatus['holder_id'] = $lockHolderId;
            $lockStatus['can_request'] = true;
        }
    } else {
        $lockStatus['can_request'] = true;
    }
    
    echo "<h2>Current Lock in Database:</h2>\n";
    if ($currentLock) {
        echo "<pre>" . print_r($currentLock, true) . "</pre>\n";
    } else {
        echo "<p>No lock found in database</p>\n";
    }
    
    echo "<h2>Generated Lock Status Array:</h2>\n";
    echo "<pre>" . print_r($lockStatus, true) . "</pre>\n";
    
    echo "<h2>JavaScript Initialization:</h2>\n";
    echo "<pre>let lockStatus = " . json_encode($lockStatus, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . ";</pre>\n";
    
    echo "<h2>Expected Behavior:</h2>\n";
    if ($lockStatus['has_lock']) {
        echo "<div style='color: green; font-weight: bold;'>✅ User should have FULL ACCESS (editing enabled)</div>\n";
        echo "<p>enablePackingControls(true) should be called</p>\n";
    } elseif ($lockStatus['is_locked_by_other']) {
        echo "<div style='color: red; font-weight: bold;'>❌ User should see READ-ONLY mode with sticky bar</div>\n";
        echo "<p>enablePackingControls(false) should be called</p>\n";
        echo "<p>Holder: " . htmlspecialchars($lockStatus['holder_name']) . "</p>\n";
    } else {
        echo "<div style='color: orange; font-weight: bold;'>⚠️ No lock - user should auto-acquire lock</div>\n";
        echo "<p>acquireLock() should be called automatically</p>\n";
    }
    
} catch (Exception $e) {
    echo "<div style='color: red; background: #f8d7da; padding: 10px; border: 1px solid #f5c6cb; border-radius: 5px;'>\n";
    echo "<strong>ERROR:</strong> " . htmlspecialchars($e->getMessage()) . "\n";
    echo "</div>\n";
}
?>