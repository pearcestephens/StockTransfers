<?php
declare(strict_types=1);

@date_default_timezone_set('Pacific/Auckland');

// Load the main system configuration using the same approach as lock_acquire.php
require_once $_SERVER['DOCUMENT_ROOT'] . '/app.php';

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
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
  }

  // Get transfer ID
  $transferId = isset($_POST['transfer_id']) ? (int)$_POST['transfer_id'] : 0;
  if ($transferId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid transfer ID']);
    exit;
  }

  // Connect to database
  $pdo = cis_pdo();
  
  // Delete the lock if user owns it
  $stmt = $pdo->prepare("
    DELETE FROM transfer_pack_locks 
    WHERE transfer_id = ? AND user_id = ?
  ");
  $deleted = $stmt->execute([$transferId, $currentUserId]);
  
  if ($deleted && $stmt->rowCount() > 0) {
    echo json_encode(['success' => true, 'released' => true]);
  } else {
    echo json_encode(['success' => false, 'error' => 'No lock found or not owned by user']);
  }

} catch (Exception $e) {
  error_log("Lock release API error: " . $e->getMessage());
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'Internal server error']);
}
?>
