<?php
declare(strict_types=1);

@date_default_timezone_set('Pacific/Auckland');

// Load the main system configuration
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
  $transferId = isset($_GET['transfer_id']) ? (int)$_GET['transfer_id'] : 0;
  if ($transferId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid transfer ID']);
    exit;
  }

  // Connect to database
  $pdo = cis_pdo();
  
  // Clean up expired requests first
  $pdo->prepare("DELETE FROM transfer_pack_lock_requests WHERE expires_at <= NOW()")->execute();

  // Get pending requests for this transfer
  $stmt = $pdo->prepare("
    SELECT r.id, r.transfer_id, r.user_id as requesting_user, r.requested_at as created_at, r.expires_at,
           'Ownership Request' as message, r.status
    FROM transfer_pack_lock_requests r
    WHERE r.transfer_id = ? AND r.expires_at > NOW()
    ORDER BY r.requested_at ASC
  ");
  $stmt->execute([$transferId]);
  $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode([
    'success' => true,
    'requests' => $requests
  ]);

} catch (Exception $e) {
  error_log("Lock requests pending API error: " . $e->getMessage());
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'Internal server error']);
}
?>
