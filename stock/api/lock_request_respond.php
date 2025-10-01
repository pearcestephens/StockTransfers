<?php
declare(strict_types=1);

/**
 * Lock Request Response API Endpoint
 * Handles automatic granting of ownership after timeout
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
  // Get current user ID from session
  $currentUserId = (int)($_SESSION['user_id'] ?? $_SESSION['userID'] ?? 0);
  if ($currentUserId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
  }

  $requestId = (int)($_POST['request_id'] ?? 0);
  $action = $_POST['action'] ?? '';
  $transferId = (int)($_POST['transfer_id'] ?? 0);
  
  if ($requestId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid request ID']);
    exit;
  }

  // Connect to database
  $pdo = cis_pdo();
  
  if ($action === 'accept') {
    // Handle manual accept by lock holder
    $pdo->beginTransaction();
    
    try {
      // Get the request details and verify current lock holder
      $requestStmt = $pdo->prepare("
        SELECT r.user_id, r.transfer_id, l.user_id as lock_holder_id
        FROM transfer_pack_lock_requests r
        LEFT JOIN transfer_pack_locks l ON r.transfer_id = l.transfer_id AND l.expires_at > NOW()
        WHERE r.id = ? AND r.status = 'pending' AND r.expires_at > NOW()
      ");
      $requestStmt->execute([$requestId]);
      $request = $requestStmt->fetch(PDO::FETCH_ASSOC);
      
      if (!$request) {
        $pdo->rollback();
        echo json_encode(['success' => false, 'error' => 'Request not found or expired']);
        exit;
      }
      
      // Verify current user is the lock holder
      if ((int)$request['lock_holder_id'] !== $currentUserId) {
        $pdo->rollback();
        echo json_encode(['success' => false, 'error' => 'You are not the current lock holder']);
        exit;
      }
      
      // Transfer ownership to the requester
      $updateLock = $pdo->prepare("
        UPDATE transfer_pack_locks 
        SET user_id = ?, expires_at = DATE_ADD(NOW(), INTERVAL 15 MINUTE)
        WHERE transfer_id = ? AND user_id = ?
      ");
      $updateLock->execute([$request['user_id'], $request['transfer_id'], $currentUserId]);
      
      // Mark request as accepted
      $updateRequest = $pdo->prepare("
        UPDATE transfer_pack_lock_requests 
        SET status = 'accepted', responded_at = NOW()
        WHERE id = ?
      ");
      $updateRequest->execute([$requestId]);
      
      // Clean up other pending requests
      $cleanupRequests = $pdo->prepare("
        UPDATE transfer_pack_lock_requests 
        SET status = 'cancelled'
        WHERE transfer_id = ? AND id != ? AND status = 'pending'
      ");
      $cleanupRequests->execute([$request['transfer_id'], $requestId]);
      
      $pdo->commit();
      
      echo json_encode([
        'success' => true, 
        'message' => 'Ownership transferred successfully',
        'new_owner_id' => $request['user_id']
      ]);
      exit;
      
    } catch (Exception $e) {
      $pdo->rollback();
      throw $e;
    }
    
  } elseif ($action === 'decline') {
    // Handle manual decline by lock holder
    $updateRequest = $pdo->prepare("
      UPDATE transfer_pack_lock_requests 
      SET status = 'declined', responded_at = NOW()
      WHERE id = ? AND status = 'pending'
    ");
    $updateRequest->execute([$requestId]);
    
    echo json_encode([
      'success' => true, 
      'message' => 'Request declined'
    ]);
    exit;
    
  } elseif ($action === 'auto_grant') {
    // Handle automatic grant after timeout (existing logic)
    $transferId = (int)($_POST['transfer_id'] ?? 0);
    if ($transferId <= 0) {
      echo json_encode(['success' => false, 'error' => 'Invalid transfer ID for auto grant']);
      exit;
    }
  
  $pdo->beginTransaction();
  
  try {
    if ($action === 'auto_grant') {
      // Get the request details
      $requestStmt = $pdo->prepare("
        SELECT user_id, transfer_id, expires_at 
        FROM transfer_pack_lock_requests 
        WHERE id = ? AND status = 'pending'
      ");
      $requestStmt->execute([$requestId]);
      $request = $requestStmt->fetch(PDO::FETCH_ASSOC);
      
      if (!$request) {
        $pdo->rollback();
        echo json_encode(['success' => false, 'error' => 'Request not found or already processed']);
        exit;
      }
      
      // Auto-grant ownership regardless of exact timing (request was made)
      
      // First, release any existing lock
      $pdo->prepare("DELETE FROM transfer_pack_locks WHERE transfer_id = ?")->execute([$transferId]);
      
      // Grant new lock to the requester
      $acquiredAt = date('Y-m-d H:i:s');
      $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 hour
      
      $grantStmt = $pdo->prepare("
        INSERT INTO transfer_pack_locks 
        (transfer_id, user_id, acquired_at, expires_at, heartbeat_at, client_fingerprint)
        VALUES (?, ?, ?, ?, ?, ?)
      ");
      
      $grantStmt->execute([
        $transferId,
        $request['user_id'],
        $acquiredAt,
        $expiresAt,
        $acquiredAt,
        'auto-granted'
      ]);
      
      // Mark request as granted
      $pdo->prepare("
        UPDATE transfer_pack_lock_requests 
        SET status = 'accepted', responded_at = NOW() 
        WHERE id = ?
      ")->execute([$requestId]);
      
      $pdo->commit();
      
      echo json_encode([
        'success' => true,
        'message' => 'Ownership automatically granted',
        'new_owner_id' => $request['user_id'],
        'expires_at' => $expiresAt
      ]);
      
    } else {
      $pdo->rollback();
      echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
    
  } catch (Exception $transactionException) {
    $pdo->rollback();
    throw $transactionException;
  }

} catch (Exception $e) {
  error_log("Lock request respond API error: " . $e->getMessage() . " in " . $e->getFile() . " line " . $e->getLine());
  http_response_code(500);
  
  echo json_encode([
    'success' => false, 
    'error' => 'Internal server error',
    'debug' => [
      'message' => $e->getMessage(),
      'file' => $e->getFile(),
      'line' => $e->getLine()
    ]
  ]);
}
            }
        }
    }
    echo json_encode($result);
} catch(Throwable $e){
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'exception','message'=>$e->getMessage()]);
}
