<?php
declare(strict_types=1);

/**
 * Lock Diagnostic API Endpoint
 * Shows current lock state for debugging
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
  $transferId = (int)($_GET['transfer_id'] ?? 0);
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
  
  // Get ALL locks for this transfer (including expired ones for debugging)
  $stmt = $pdo->prepare("
    SELECT 
      transfer_id,
      user_id,
      acquired_at,
      expires_at,
      heartbeat_at,
      client_fingerprint,
      (expires_at > NOW()) as is_active,
      u.first_name,
      u.last_name,
      CONCAT(u.first_name, ' ', u.last_name) as user_name
    FROM transfer_pack_locks tpl
    LEFT JOIN users u ON u.id = tpl.user_id
    WHERE transfer_id = ?
    ORDER BY acquired_at DESC
  ");
  $stmt->execute([$transferId]);
  $allLocks = $stmt->fetchAll(PDO::FETCH_ASSOC);
  
  // Get active locks only
  $activeLocks = array_filter($allLocks, function($lock) {
    return $lock['is_active'] == 1;
  });
  
  // Count issues
  $issues = [];
  if (count($activeLocks) > 1) {
    $issues[] = "CRITICAL: Multiple active locks detected!";
  }
  
  // Get lock requests
  $requestStmt = $pdo->prepare("
    SELECT 
      id,
      user_id,
      requested_at,
      status,
      responded_at,
      expires_at,
      CONCAT(u.first_name, ' ', u.last_name) as user_name
    FROM transfer_pack_lock_requests tplr
    LEFT JOIN users u ON u.id = tplr.user_id
    WHERE transfer_id = ?
    ORDER BY requested_at DESC
    LIMIT 10
  ");
  $requestStmt->execute([$transferId]);
  $requests = $requestStmt->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode([
    'success' => true,
    'debug_info' => [
      'transfer_id' => $transferId,
      'current_user_id' => $currentUserId,
      'timestamp' => date('Y-m-d H:i:s'),
      'all_locks_count' => count($allLocks),
      'active_locks_count' => count($activeLocks),
      'issues' => $issues,
      'all_locks' => $allLocks,
      'active_locks' => $activeLocks,
      'recent_requests' => $requests
    ]
  ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
  error_log("Lock diagnostic API error: " . $e->getMessage());
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