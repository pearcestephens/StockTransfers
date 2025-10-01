<?php
/**
 * Quick database table creation for pack locks
 * Run this once to ensure tables exist
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/app.php';

try {
    $pdo = cis_pdo();
    
    // Create transfer_pack_locks table
    $sql1 = "CREATE TABLE IF NOT EXISTS transfer_pack_locks (
      transfer_id    INT UNSIGNED NOT NULL PRIMARY KEY,
      user_id        INT UNSIGNED NOT NULL,
      acquired_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      expires_at     DATETIME NOT NULL,
      heartbeat_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      client_fingerprint VARCHAR(64) DEFAULT NULL,
      INDEX (expires_at),
      INDEX (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $pdo->exec($sql1);
    echo "✅ transfer_pack_locks table created/verified\n";
    
    // Create transfer_pack_lock_requests table
    $sql2 = "CREATE TABLE IF NOT EXISTS transfer_pack_lock_requests (
      id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      transfer_id   INT UNSIGNED NOT NULL,
      user_id       INT UNSIGNED NOT NULL,
      requested_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      status        ENUM('pending','accepted','declined','expired','cancelled') NOT NULL DEFAULT 'pending',
      responded_at  DATETIME NULL,
      expires_at    DATETIME NULL,
      client_fingerprint VARCHAR(64) DEFAULT NULL,
      INDEX (transfer_id, status),
      INDEX (expires_at),
      INDEX (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $pdo->exec($sql2);
    echo "✅ transfer_pack_lock_requests table created/verified\n";
    
    echo "\n🎉 Database tables ready for pack lock system!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
}
?>