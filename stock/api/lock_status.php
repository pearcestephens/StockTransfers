<?php
declare(strict_types=1);

/**
 * Lock Status API Endpoint
 * Returns current lock status for a transfer
 */

@date_default_timezone_set('Pacific/Auckland');

// Load the main system configuration using the same approach as lock_acquire.php
require_once $_SERVER['DOCUMENT_ROOT'] . '/app.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

try {
  // Get transfer ID
  $transferId = (int)($_GET['transfer_id'] ?? 0);
  $isDiagnostic = isset($_GET['diagnostic']) && $_GET['diagnostic'] === '1';
  
  if ($transferId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid transfer ID']);
    exit;
  }

  // Get current user ID from session
  $currentUserId = (int)($_SESSION['user_id'] ?? $_SESSION['userID'] ?? 0);
  if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
  if ($currentUserId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
  }

  // Connect to database using the main system's database connection
  $pdo = cis_pdo();
  
  // Clean up expired locks first
  $pdo->prepare("DELETE FROM transfer_pack_locks WHERE expires_at <= NOW()")->execute();
  
  // Check if lock exists
  $stmt = $pdo->prepare("
    SELECT user_id, acquired_at, heartbeat_at, expires_at
    FROM transfer_pack_locks 
    WHERE transfer_id = ? AND expires_at > NOW()
  ");
  $stmt->execute([$transferId]);
  $lock = $stmt->fetch(PDO::FETCH_ASSOC);

  $response = [
    'has_lock' => false,
    'is_locked' => false,
    'is_locked_by_other' => false,
    'holder_name' => null,
    'expires_at' => null
  ];
  
  // Add diagnostic information if requested
  if ($isDiagnostic) {
    $response['lock_acquired_at'] = null;
    $response['lock_heartbeat_at'] = null;
    $response['transfer_id'] = $transferId;
    $response['current_user_id'] = $currentUserId;
  }

  if ($lock) {
    $response['is_locked'] = true;
    $response['expires_at'] = $lock['expires_at'];
    
    // Add diagnostic information if requested
    if ($isDiagnostic) {
      $response['lock_acquired_at'] = $lock['acquired_at'];
      $response['lock_heartbeat_at'] = $lock['heartbeat_at'];
    }
    
    if ((int)$lock['user_id'] === $currentUserId) {
      $response['has_lock'] = true;
    } else {
      $response['is_locked_by_other'] = true;
      
      // Get holder name
      $holderStmt = $pdo->prepare("SELECT CONCAT(first_name, ' ', last_name) as name FROM users WHERE id = ?");
      $holderStmt->execute([$lock['user_id']]);
      $holder = $holderStmt->fetch(PDO::FETCH_ASSOC);
      $response['holder_name'] = $holder['name'] ?? 'Unknown User';
    }
  }

  echo json_encode(['success' => true, 'data' => $response]);

} catch (Exception $e) {
  error_log("Lock status API error: " . $e->getMessage());
  echo json_encode(['success' => false, 'error' => 'Internal server error']);
}