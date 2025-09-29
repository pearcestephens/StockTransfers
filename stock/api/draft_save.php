<?php
/**
 * Draft Auto-Save API Endpoint
 * 
 * Saves draft transfer pack data for auto-recovery
 * Handles: counted quantities, added products, removed products, courier settings
 */

declare(strict_types=1);

use PDO;
use Exception;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

try {
    // Bootstrap
    $DOCUMENT_ROOT = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    if ($DOCUMENT_ROOT === '' || !is_dir($DOCUMENT_ROOT)) {
        throw new Exception('Server misconfiguration: DOCUMENT_ROOT not set.');
    }
    require_once $DOCUMENT_ROOT . '/app.php';

    // Ensure session is started for user authentication
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Only accept POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }

    // Get user and validate login - use the same pattern as pack_save.php
    $userId = 0;
    if (isset($_SESSION['userID'])) {
        $userId = (int)$_SESSION['userID'];
    }
    
    if ($userId <= 0) {
        http_response_code(401);
        echo json_encode([
            'success' => false, 
            'error' => 'Not logged in',
            'debug_info' => [
                'session_status' => session_status(),
                'session_keys' => array_keys($_SESSION ?? []),
                'userID_exists' => isset($_SESSION['userID']) ? 'yes' : 'no'
            ]
        ]);
        exit;
    }

    // Parse JSON input
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
        exit;
    }

    // Validate required fields
    $transferId = (int)($data['transfer_id'] ?? 0);
    if ($transferId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing transfer_id']);
        exit;
    }

    // Access control - basic check for now
    // TODO: Implement proper access control via AccessPolicy::canAccessTransfer()
    
    // Prepare draft data structure
    $draftData = [
        'counted_qty' => $data['counted_qty'] ?? [],
        'added_products' => $data['added_products'] ?? [],
        'removed_items' => $data['removed_items'] ?? [],
        'courier_settings' => $data['courier_settings'] ?? [],
        'notes' => $data['notes'] ?? '',
        'saved_by' => $userId,
        'saved_at' => date('Y-m-d H:i:s')
    ];

    // Get database connection using the same pattern as TransfersService
    if (class_exists('\Core\DB') && method_exists('\Core\DB', 'instance')) {
        $db = \Core\DB::instance();
    } elseif (function_exists('cis_pdo')) {
        $db = cis_pdo();
    } elseif (class_exists('\DB') && method_exists('\DB', 'instance')) {
        $db = \DB::instance();
    } elseif (!empty($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        $db = $GLOBALS['pdo'];
    } else {
        throw new Exception('Database not initialized');
    }
    
    // Update the transfers table with draft data
    $sql = "UPDATE transfers 
            SET draft_data = ?, draft_updated_at = ? 
            WHERE id = ?";
    
    $stmt = $db->prepare($sql);
    $success = $stmt->execute([
        json_encode($draftData, JSON_UNESCAPED_UNICODE),
        $draftData['saved_at'],
        $transferId
    ]);

    if ($success) {
        echo json_encode([
            'success' => true,
            'saved_at' => $draftData['saved_at'],
            'message' => 'Draft saved successfully'
        ]);
    } else {
        throw new Exception('Failed to save draft');
    }

} catch (Exception $e) {
    // Log the actual error for debugging
    error_log('Draft save error: ' . $e->getMessage());
    error_log('Draft save trace: ' . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'debug_info' => [
            'transfer_id' => $transferId ?? null,
            'user_id' => $userId ?? null,
            'error_line' => $e->getLine(),
            'error_file' => basename($e->getFile())
        ]
    ]);
}