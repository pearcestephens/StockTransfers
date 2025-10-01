<?php
declare(strict_types=1);

@date_default_timezone_set('Pacific/Auckland');

// Load the main system configuration using the same approach as _local_shims.php
require_once $_SERVER['DOCUMENT_ROOT'] . '/app.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

try {
  // Check authentication
  $currentUserId = (int)($_SESSION['user_id'] ?? $_SESSION['userID'] ?? 0);
  if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
  if ($currentUserId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated', 'debug' => 'No valid user ID in session']);
    exit;
  }

  // Get transfer ID
  $transferId = isset($_POST['transfer_id']) ? (int)$_POST['transfer_id'] : 0;
  if ($transferId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid transfer ID', 'debug' => 'Transfer ID missing or invalid']);
    exit;
  }

  $fingerprint = $_POST['fingerprint'] ?? '';
  
  // Truncate fingerprint to fit database column (64 chars max)
  if (strlen($fingerprint) > 64) {
    $fingerprint = substr($fingerprint, 0, 64);
  }
  
  // Connect to database using the main system's database connection
  $pdo = cis_pdo();
  
  // Ensure tables exist with proper constraints
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS transfer_pack_locks (
      transfer_id    INT UNSIGNED NOT NULL PRIMARY KEY,
      user_id        INT UNSIGNED NOT NULL,
      acquired_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      expires_at     DATETIME NOT NULL,
      heartbeat_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      client_fingerprint VARCHAR(64) DEFAULT NULL,
      INDEX (expires_at),
      INDEX (user_id),
      UNIQUE KEY unique_transfer_lock (transfer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");
  
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS transfer_pack_lock_requests (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");


  
  // Clean up expired locks first
  $pdo->prepare("DELETE FROM transfer_pack_locks WHERE expires_at <= NOW()")->execute();
  
  // Use a transaction to prevent race conditions
  $pdo->beginTransaction();
  
  try {
    // Check if there's an existing lock (with SELECT FOR UPDATE to prevent race conditions)
    $stmt = $pdo->prepare("
      SELECT user_id, acquired_at, expires_at, heartbeat_at, client_fingerprint
      FROM transfer_pack_locks 
      WHERE transfer_id = ? AND expires_at > NOW()
      FOR UPDATE
    ");
    $stmt->execute([$transferId]);
    $existingLock = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($existingLock) {
    // Lock exists
    if ((int)$existingLock['user_id'] === $currentUserId) {
      // User already has the lock - extend it to 1 hour
      $newExpiry = date('Y-m-d H:i:s', time() + 3600); // 1 hour
      $pdo->prepare("
        UPDATE transfer_pack_locks 
        SET expires_at = ?, heartbeat_at = NOW() 
        WHERE transfer_id = ? AND user_id = ?
      ")->execute([$newExpiry, $transferId, $currentUserId]);
      
      $pdo->commit(); // Commit transaction
      
      echo json_encode([
        'success' => true,
        'already_held' => true,
        'expires_at' => $newExpiry
      ]);
      exit;
    } else {
      // Someone else has the lock - DENY ACCESS
      $holderStmt = $pdo->prepare("SELECT CONCAT(first_name, ' ', last_name) as name FROM users WHERE id = ?");
      $holderStmt->execute([$existingLock['user_id']]);
      $holder = $holderStmt->fetch(PDO::FETCH_ASSOC);
      
      $pdo->commit(); // Commit transaction (even though we're denying)
      
      echo json_encode([
        'success' => false,
        'conflict' => true,
        'message' => 'This transfer is currently being edited by another user. The page is now in read-only mode.',
        'holder' => [
          'user_id' => $existingLock['user_id'],
          'holder_name' => $holder['name'] ?? 'Unknown User',
          'acquired_at' => $existingLock['acquired_at'],
          'expires_at' => $existingLock['expires_at']
        ]
      ]);
      exit;
    }
  }

  // No existing lock - acquire it for 1 hour
  $acquiredAt = date('Y-m-d H:i:s');
  $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 hour
  
  $insertStmt = $pdo->prepare("
    INSERT INTO transfer_pack_locks 
    (transfer_id, user_id, acquired_at, expires_at, heartbeat_at, client_fingerprint)
    VALUES (?, ?, ?, ?, ?, ?)
  ");
  
  $success = $insertStmt->execute([
    $transferId,
    $currentUserId, 
    $acquiredAt,
    $expiresAt,
    $acquiredAt,
    $fingerprint
  ]);

  if ($success) {
    // Get user name
    $userStmt = $pdo->prepare("SELECT CONCAT(first_name, ' ', last_name) as name FROM users WHERE id = ?");
    $userStmt->execute([$currentUserId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    
    $pdo->commit(); // Commit transaction
    
    echo json_encode([
      'success' => true,
      'lock' => [
        'transfer_id' => $transferId,
        'user_id' => $currentUserId,
        'holder_name' => $user['name'] ?? 'Unknown User',
        'acquired_at' => $acquiredAt,
        'expires_at' => $expiresAt,
        'client_fingerprint' => $fingerprint
      ]
    ]);
  } else {
    $pdo->rollback(); // Rollback on failure
    echo json_encode(['success' => false, 'error' => 'Failed to acquire lock']);
  }
  
  } catch (Exception $lockException) {
    $pdo->rollback(); // Rollback transaction on any error
    throw $lockException; // Re-throw to be caught by outer catch block
  }

} catch (Exception $e) {
  error_log("Lock acquire API error: " . $e->getMessage() . " in " . $e->getFile() . " line " . $e->getLine());
  http_response_code(500);
  
  // In development, provide more debugging info
  $debug = [];
  $debug['message'] = $e->getMessage();
  $debug['file'] = $e->getFile();
  $debug['line'] = $e->getLine();
  $debug['session_keys'] = array_keys($_SESSION ?? []);
  $debug['post_keys'] = array_keys($_POST ?? []);
  
  echo json_encode([
    'success' => false, 
    'error' => 'Internal server error',
    'debug' => $debug
  ]);
}
?>
