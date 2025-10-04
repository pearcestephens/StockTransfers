<?php
/**
 * Diagnostic: Check if simple_locks table exists and create if needed
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/app.php';
require_once dirname(__DIR__, 2) . '/_local_shims.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = cis_pdo();
    
    // Check if table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'simple_locks'");
    $exists = $stmt->fetch();
    
    if (!$exists) {
        echo "❌ Table 'simple_locks' does NOT exist!\n\n";
        echo "Creating table now...\n\n";
        
        $sql = "CREATE TABLE IF NOT EXISTS simple_locks (
          resource_key VARCHAR(191) NOT NULL COMMENT 'e.g. transfer:13219',
          owner_id     VARCHAR(64)  NOT NULL COMMENT 'User/account identifier',
          tab_id       VARCHAR(64)  NOT NULL COMMENT 'Per-tab session identifier',
          token        CHAR(32)     NOT NULL COMMENT 'Server issued random lock token',
          acquired_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
          expires_at   TIMESTAMP    NOT NULL,
          PRIMARY KEY (resource_key),
          KEY idx_exp   (expires_at),
          KEY idx_owner (owner_id, tab_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $pdo->exec($sql);
        echo "✅ Table created successfully!\n\n";
    } else {
        echo "✅ Table 'simple_locks' exists\n\n";
    }
    
    // Show current locks
    $stmt = $pdo->query("SELECT * FROM simple_locks");
    $locks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Current locks in table:\n";
    echo "======================\n";
    if (empty($locks)) {
        echo "(no locks currently held)\n";
    } else {
        print_r($locks);
    }
    
    echo "\n✅ Check complete!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
}
