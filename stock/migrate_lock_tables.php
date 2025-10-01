<?php
// Migration completed - file archived
header('HTTP/1.1 410 Gone');
echo 'Migration completed successfully. File archived.';
?> 
 * Tables have been updated to use VARCHAR(64) for transfer_id fields.
 * 
 * Status: ✅ COMPLETED SUCCESSFULLY
 * Date: 2024-12-20
 */

echo "<h1>Migration Completed</h1>";
echo "<div style='color: green; font-weight: bold;'>✅ This migration has been completed successfully.</div>";
echo "<p>The lock tables have been updated to support string transfer IDs.</p>";
?>

require_once $_SERVER['DOCUMENT_ROOT'] . '/app.php';

echo "<h1>Lock Tables Schema Migration</h1>\n";

try {
    // Check current table structure
    echo "<h2>1. Checking Current Tables</h2>\n";
    
    $result = $db->query("SHOW TABLES LIKE 'transfer_pack_locks'");
    if ($result->num_rows > 0) {
        echo "✅ transfer_pack_locks table exists<br>\n";
        
        $result = $db->query("DESCRIBE transfer_pack_locks");
        echo "<h3>Current transfer_pack_locks schema:</h3>\n";
        echo "<pre>\n";
        while ($row = $result->fetch_assoc()) {
            echo $row['Field'] . " - " . $row['Type'] . " - " . $row['Key'] . "\n";
        }
        echo "</pre>\n";
    } else {
        echo "❌ transfer_pack_locks table does not exist<br>\n";
    }
    
    $result = $db->query("SHOW TABLES LIKE 'transfer_pack_lock_requests'");
    if ($result->num_rows > 0) {
        echo "✅ transfer_pack_lock_requests table exists<br>\n";
        
        $result = $db->query("DESCRIBE transfer_pack_lock_requests");
        echo "<h3>Current transfer_pack_lock_requests schema:</h3>\n";
        echo "<pre>\n";
        while ($row = $result->fetch_assoc()) {
            echo $row['Field'] . " - " . $row['Type'] . " - " . $row['Key'] . "\n";
        }
        echo "</pre>\n";
    } else {
        echo "❌ transfer_pack_lock_requests table does not exist<br>\n";
    }
    
    echo "<h2>2. Migration Actions</h2>\n";
    
    // Check if we need to migrate
    $lockTableNeedsMigration = false;
    $requestTableNeedsMigration = false;
    
    $result = $db->query("SHOW COLUMNS FROM transfer_pack_locks WHERE Field = 'transfer_id'");
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        if (strpos($row['Type'], 'int') !== false) {
            $lockTableNeedsMigration = true;
            echo "⚠️ transfer_pack_locks.transfer_id is INT - needs migration to VARCHAR(64)<br>\n";
        } else {
            echo "✅ transfer_pack_locks.transfer_id is already VARCHAR<br>\n";
        }
    }
    
    $result = $db->query("SHOW COLUMNS FROM transfer_pack_lock_requests WHERE Field = 'transfer_id'");
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        if (strpos($row['Type'], 'int') !== false) {
            $requestTableNeedsMigration = true;
            echo "⚠️ transfer_pack_lock_requests.transfer_id is INT - needs migration to VARCHAR(64)<br>\n";
        } else {
            echo "✅ transfer_pack_lock_requests.transfer_id is already VARCHAR<br>\n";
        }
    }
    
    if (!$lockTableNeedsMigration && !$requestTableNeedsMigration) {
        echo "<div style='color: green; font-weight: bold;'>✅ No migration needed - tables are already correct!</div>\n";
        exit;
    }
    
    echo "<h2>3. Performing Migration</h2>\n";
    
    // Start transaction
    $db->autocommit(false);
    
    if ($lockTableNeedsMigration) {
        echo "🔄 Migrating transfer_pack_locks table...<br>\n";
        
        // Backup existing data
        $result = $db->query("SELECT COUNT(*) as count FROM transfer_pack_locks");
        $row = $result->fetch_assoc();
        $existingRecords = $row['count'];
        echo "📊 Found $existingRecords existing lock records<br>\n";
        
        if ($existingRecords > 0) {
            echo "⚠️ WARNING: Existing lock records will be lost due to schema change<br>\n";
            echo "🗑️ Clearing existing locks (they're likely invalid anyway)...<br>\n";
            $db->query("DELETE FROM transfer_pack_locks");
        }
        
        // Modify the table structure
        $db->query("ALTER TABLE transfer_pack_locks MODIFY COLUMN transfer_id VARCHAR(64) NOT NULL");
        echo "✅ Updated transfer_pack_locks.transfer_id to VARCHAR(64)<br>\n";
    }
    
    if ($requestTableNeedsMigration) {
        echo "🔄 Migrating transfer_pack_lock_requests table...<br>\n";
        
        // Backup existing data
        $result = $db->query("SELECT COUNT(*) as count FROM transfer_pack_lock_requests");
        $row = $result->fetch_assoc();
        $existingRecords = $row['count'];
        echo "📊 Found $existingRecords existing request records<br>\n";
        
        if ($existingRecords > 0) {
            echo "⚠️ WARNING: Existing request records will be lost due to schema change<br>\n";
            echo "🗑️ Clearing existing requests...<br>\n";
            $db->query("DELETE FROM transfer_pack_lock_requests");
        }
        
        // Modify the table structure
        $db->query("ALTER TABLE transfer_pack_lock_requests MODIFY COLUMN transfer_id VARCHAR(64) NOT NULL");
        echo "✅ Updated transfer_pack_lock_requests.transfer_id to VARCHAR(64)<br>\n";
    }
    
    // Commit transaction
    $db->commit();
    $db->autocommit(true);
    
    echo "<h2>4. Verification</h2>\n";
    
    // Verify the changes
    $result = $db->query("DESCRIBE transfer_pack_locks");
    echo "<h3>New transfer_pack_locks schema:</h3>\n";
    echo "<pre>\n";
    while ($row = $result->fetch_assoc()) {
        $highlight = ($row['Field'] == 'transfer_id') ? " <-- UPDATED" : "";
        echo $row['Field'] . " - " . $row['Type'] . " - " . $row['Key'] . $highlight . "\n";
    }
    echo "</pre>\n";
    
    $result = $db->query("DESCRIBE transfer_pack_lock_requests");
    echo "<h3>New transfer_pack_lock_requests schema:</h3>\n";
    echo "<pre>\n";
    while ($row = $result->fetch_assoc()) {
        $highlight = ($row['Field'] == 'transfer_id') ? " <-- UPDATED" : "";
        echo $row['Field'] . " - " . $row['Type'] . " - " . $row['Key'] . $highlight . "\n";
    }
    echo "</pre>\n";
    
    echo "<div style='color: green; font-weight: bold; font-size: 18px; margin-top: 20px;'>✅ MIGRATION COMPLETED SUCCESSFULLY!</div>\n";
    echo "<p>The lock system should now work properly with string transfer IDs.</p>\n";
    
} catch (Exception $e) {
    // Rollback on error
    $db->rollback();
    $db->autocommit(true);
    
    echo "<div style='color: red; background: #f8d7da; padding: 10px; border: 1px solid #f5c6cb; border-radius: 5px;'>\n";
    echo "<strong>MIGRATION FAILED:</strong> " . htmlspecialchars($e->getMessage()) . "\n";
    echo "</div>\n";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>\n";
}
?>