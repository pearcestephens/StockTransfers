<?php
declare(strict_types=1);

/**
 * Lock Debug Test API
 * Tests current lock state for debugging
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
  $transferId = (int)($_GET['transfer_id'] ?? $_POST['transfer_id'] ?? 0);
  if ($transferId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid transfer ID']);
    exit;
  }

  // Get current user ID from session
  $currentUserId = (int)($_SESSION['user_id'] ?? $_SESSION['userID'] ?? 0);
  if ($currentUserId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
  }

  // Connect to database
  $pdo = cis_pdo();
  
  // Get ALL active locks for this transfer
  $stmt = $pdo->prepare("
    SELECT 
      transfer_id,
      user_id,
      acquired_at,
      expires_at,
    $userStmt->execute($userIds);
    $users = $userStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($users as $user) {
      $userNames[$user['id']] = $user['name'];
    }
  }
  
  // Add user names to locks
  foreach ($allLocks as &$lock) {
    $lock['user_name'] = $userNames[$lock['user_id']] ?? 'Unknown User';
  }

  echo json_encode([
    'success' => true,
    'debug_info' => [
      'transfer_id' => $transferId,
      'current_user_id' => $currentUserId,
      'current_time' => date('Y-m-d H:i:s'),
      'total_locks' => count($allLocks),
      'active_locks' => count($activeLocks),
      'test_acquire_result' => $testAcquire,
      'all_locks' => $allLocks,
      'active_locks_detail' => array_values($activeLocks)
    ]
  ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
  error_log("Lock debug API error: " . $e->getMessage());
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