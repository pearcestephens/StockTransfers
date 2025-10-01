<?php
declare(strict_types=1);

@date_default_timezone_set('Pacific/Auckland');

// Enhanced error reporting for debug
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Load the main system configuration using the same approach as lock_acquire.php
try {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/app.php';
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Failed to load app.php',
        'debug' => [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]
    ]);
    exit;
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

try {
  // Check authentication
  $currentUserId = (int)($_SESSION['user_id'] ?? $_SESSION['userID'] ?? 0);
  if ($currentUserId <= 0) {
    http_response_code(401);
    echo json_encode([
        'success' => false, 
        'error' => 'Not authenticated',
        'debug' => [
            'session_user_id' => $_SESSION['user_id'] ?? 'not_set',
            'session_userID' => $_SESSION['userID'] ?? 'not_set',
            'session_keys' => array_keys($_SESSION ?? [])
        ]
    ]);
    exit;
  }

  // Get transfer ID
  $transferId = isset($_POST['transfer_id']) ? (int)$_POST['transfer_id'] : 0;
  if ($transferId <= 0) {
    echo json_encode([
        'success' => false, 
        'error' => 'Invalid transfer ID',
        'debug' => [
            'post_transfer_id' => $_POST['transfer_id'] ?? 'not_set',
            'post_keys' => array_keys($_POST ?? [])
        ]
    ]);
    exit;
  }

  $message = $_POST['message'] ?? 'Ownership request';

  // Connect to database with better error handling
  try {
    $pdo = cis_pdo();
  } catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Database connection failed',
        'debug' => [
            'message' => $e->getMessage()
        ]
    ]);
    exit;
  }
  
  // Check if transfer is currently locked by someone else
  $stmt = $pdo->prepare("
    SELECT user_id FROM transfer_pack_locks 
    WHERE transfer_id = ? AND expires_at > NOW()
  ");
  $stmt->execute([$transferId]);
  $lock = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$lock) {
    echo json_encode(['success' => false, 'error' => 'Transfer is not currently locked']);
    exit;
  }

  if ((int)$lock['user_id'] === $currentUserId) {
    echo json_encode(['success' => false, 'error' => 'You already own this transfer']);
    exit;
  }

  // Use the existing transfer_pack_lock_requests table
  // (This table was already created in lock_acquire.php)
  
  // Clean up expired requests
  $pdo->prepare("DELETE FROM transfer_pack_lock_requests WHERE expires_at <= NOW()")->execute();

  // Check if there's already a pending request from this user for this transfer
  $existingStmt = $pdo->prepare("
    SELECT id FROM transfer_pack_lock_requests 
    WHERE transfer_id = ? AND user_id = ? AND status = 'pending'
  ");
  $existingStmt->execute([$transferId, $currentUserId]);
  
  if ($existingStmt->fetch()) {
    echo json_encode(['success' => false, 'error' => 'You already have a pending request for this transfer']);
    exit;
  }

  // Create the ownership request (expires in 60 seconds)
  $expiresAt = date('Y-m-d H:i:s', time() + 60);
  
  // Get client IP address
  $clientIP = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
  if (strpos($clientIP, ',') !== false) {
    $clientIP = trim(explode(',', $clientIP)[0]);
  }
  
  // Enhanced fingerprint with IP
  $fingerprint = json_encode([
    'ip' => $clientIP,
    'user_agent' => $_POST['fingerprint'] ?? $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
    'timestamp' => time()
  ]);
  
  $insertStmt = $pdo->prepare("
    INSERT INTO transfer_pack_lock_requests 
    (transfer_id, user_id, requested_at, status, expires_at, client_fingerprint)
    VALUES (?, ?, NOW(), 'pending', ?, ?)
  ");
  
  $success = $insertStmt->execute([
    $transferId,
    $currentUserId,
    $expiresAt,
    $fingerprint
  ]);

  if ($success) {
    echo json_encode([
      'success' => true,
      'request_id' => $pdo->lastInsertId(),
      'expires_at' => $expiresAt
    ]);
  } else {
    echo json_encode(['success' => false, 'error' => 'Failed to create ownership request']);
  }

} catch (Exception $e) {
  error_log("Lock request API error: " . $e->getMessage() . " in " . $e->getFile() . " line " . $e->getLine());
  http_response_code(500);
  
  // Enhanced debugging info
  $debug = [];
  $debug['message'] = $e->getMessage();
  $debug['file'] = $e->getFile();
  $debug['line'] = $e->getLine();
  $debug['trace'] = $e->getTraceAsString();
  $debug['session_keys'] = array_keys($_SESSION ?? []);
  $debug['post_keys'] = array_keys($_POST ?? []);
  $debug['current_user_id'] = $currentUserId ?? 'not_set';
  $debug['transfer_id'] = $transferId ?? 'not_set';
  
  echo json_encode([
    'success' => false, 
    'error' => 'Internal server error: ' . $e->getMessage(),
    'debug' => $debug
  ]);
}
?>
