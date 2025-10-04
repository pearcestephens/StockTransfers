<?php
/**
 * Migration Runner - Advanced Lock System
 */
require_once __DIR__ . '/../../../../app.php';

// Get PDO connection
if (!function_exists('cis_pdo')) {
  require_once dirname(__DIR__, 2) . '/_local_shims.php';
}

try {
    $pdo = cis_pdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Creating lock_requests table...\n";
    
    // Create lock_requests table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS lock_requests (
            resource_key VARCHAR(191) NOT NULL,
            requester_id VARCHAR(64) NOT NULL,
            requester_tab VARCHAR(64) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            expires_at TIMESTAMP NOT NULL DEFAULT '2000-01-01 00:00:00',
            PRIMARY KEY (resource_key),
            INDEX idx_expires (expires_at),
            INDEX idx_requester (requester_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    echo "lock_requests table created successfully.\n";
    
    echo "Ensuring simple_locks table exists...\n";
    
    // Ensure simple_locks table exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS simple_locks (
            resource_key VARCHAR(191) NOT NULL,
            owner_id VARCHAR(64) NOT NULL,
            tab_id VARCHAR(64) NOT NULL,
            token VARCHAR(64) NOT NULL,
            acquired_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            expires_at TIMESTAMP NOT NULL DEFAULT '2000-01-01 00:00:00',
            PRIMARY KEY (resource_key),
            INDEX idx_expires (expires_at),
            INDEX idx_owner (owner_id, tab_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    echo "simple_locks table verified.\n";
    echo "Migration completed successfully!\n";
    
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
}
?>