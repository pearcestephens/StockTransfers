<?php
declare(strict_types=1);

/**
 * Lock Cleanup API Endpoint
 * Cleans up duplicate locks and ensures data integrity
 */

@date_default_timezone_set('Pacific/Auckland');

// Load the main system configuration
require_once $_SERVER['DOCUMENT_ROOT'] . '/app.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

try {
  // Get transfer ID
  $transferId = (int)($_POST['transfer_id'] ?? $_GET['transfer_id'] ?? 0);
  if ($transferId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid transfer ID']);
    exit;
  }

  // Get current user ID from session
  $currentUserId = (int)($_SESSION['user_id'] ?? $_SESSION['userID'] ?? 0);
  if ($currentUserId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
  }

  // Connect to database
  $pdo = cis_pdo();
  
  $pdo->beginTransaction();
  
  try {
    // Step 1: Clean up expired locks
    $expiredStmt = $pdo->prepare("DELETE FROM transfer_pack_locks WHERE expires_at <= NOW()");
    $expiredStmt->execute();
    $expiredCount = $expiredStmt->rowCount();
    
    // Step 2: Get all active locks for this transfer
    $activeStmt = $pdo->prepare("
      SELECT 
        transfer_id,
        user_id,
        acquired_at,
        expires_at,
        heartbeat_at,
        client_fingerprint,
        CONCAT(u.first_name, ' ', u.last_name) as user_name
      FROM transfer_pack_locks tpl
      LEFT JOIN users u ON u.id = tpl.user_id
      WHERE transfer_id = ? AND expires_at > NOW()
      ORDER BY acquired_at ASC
    ");
    $activeStmt->execute([$transferId]);
    $activeLocks = $activeStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $cleanupActions = [];
    $duplicatesRemoved = 0;
    
    // Step 3: Handle duplicates - keep the oldest lock, remove others
    if (count($activeLocks) > 1) {
      $keepLock = $activeLocks[0]; // Keep the oldest
      $cleanupActions[] = "Found " . count($activeLocks) . " active locks - keeping oldest from user ID {$keepLock['user_id']}";
      
      // Remove all other locks
      for ($i = 1; $i < count($activeLocks); $i++) {
        $removeLock = $activeLocks[$i];
        $removeStmt = $pdo->prepare("
          DELETE FROM transfer_pack_locks 
          WHERE transfer_id = ? AND user_id = ? AND acquired_at = ?
        ");
        $removeStmt->execute([$transferId, $removeLock['user_id'], $removeLock['acquired_at']]);
        $duplicatesRemoved++;
        $cleanupActions[] = "Removed duplicate lock from user ID {$removeLock['user_id']} (acquired: {$removeLock['acquired_at']})";
      }
    }
    
    // Step 4: Get final state
    $finalStmt = $pdo->prepare("
      SELECT 
        user_id,
        acquired_at,
        expires_at,
        heartbeat_at,
        CONCAT(u.first_name, ' ', u.last_name) as user_name
      FROM transfer_pack_locks tpl
      LEFT JOIN users u ON u.id = tpl.user_id
      WHERE transfer_id = ? AND expires_at > NOW()
    ");
    $finalStmt->execute([$transferId]);
    $finalLock = $finalStmt->fetch(PDO::FETCH_ASSOC);
    
    $pdo->commit();
    
    echo json_encode([
      'success' => true,
      'cleanup_summary' => [
        'transfer_id' => $transferId,
        'expired_locks_removed' => $expiredCount,
        'duplicate_locks_removed' => $duplicatesRemoved,
        'cleanup_actions' => $cleanupActions,
        'final_lock_state' => $finalLock ? [
          'user_id' => $finalLock['user_id'],
          'user_name' => $finalLock['user_name'],
          'acquired_at' => $finalLock['acquired_at'],
          'expires_at' => $finalLock['expires_at'],
          'heartbeat_at' => $finalLock['heartbeat_at']
        ] : null,
        'current_user_has_lock' => $finalLock ? ((int)$finalLock['user_id'] === $currentUserId) : false
      ]
    ], JSON_PRETTY_PRINT);
    
  } catch (Exception $cleanupException) {
    $pdo->rollback();
    throw $cleanupException;
  }

} catch (Exception $e) {
  error_log("Lock cleanup API error: " . $e->getMessage());
  echo json_encode([
    'success' => false, 
    'error' => 'Internal server error',
    'debug' => [
      'message' => $e->getMessage(),
      'file' => $e->getFile(),
      'line' => $e->getLine()
    ]
  ], JSON_PRETTY_PRINT);
}
?>