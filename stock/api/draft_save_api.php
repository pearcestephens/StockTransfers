<?php
/**
 * Draft Save API - Server-Side Auto-Save Endpoint
 * SECURITY: Requires valid transfer lock ownership
 * Saves counted quantities and notes as draft (non-final save)
 */

declare(strict_types=1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/app.php';
require_once __DIR__ . '/_lib/simple_lock_guard.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => ['code' => 'METHOD_NOT_ALLOWED', 'message' => 'POST only']
    ]);
    exit;
}

try {
    if(!isset($_SESSION['userID'])){ http_response_code(401); echo json_encode(['success'=>false,'error'=>['code'=>'UNAUTH','message'=>'Auth required']]); exit; }
    $userId = (int)$_SESSION['userID'];

    $DEBUG_DRAFT = (getenv('DEBUG_DRAFT_SAVE') === '1');
    $stage = 'init';
    if ($DEBUG_DRAFT) header('X-DraftSave-Debug: 1');

    $stage = 'read_body';
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('Draft save JSON parse error: ' . json_last_error_msg() . ' RAW=' . substr($raw, 0, 400));
    }
    if (!is_array($data)) { $data = $_POST; }
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => ['code' => 'INVALID_PAYLOAD', 'message' => 'Body must be JSON or form data'],
            'request_id' => bin2hex(random_bytes(8))
        ]);
        exit;
    }

    $stage = 'lock_validation';
    $transferId = (int)($data['transfer_id'] ?? 0);
    if($transferId<=0){ http_response_code(400); echo json_encode(['success'=>false,'error'=>['code'=>'MISSING_TRANSFER','message'=>'transfer_id required']]); exit; }
    require_lock_or_423('transfer:'.$transferId, $userId, $data['lock_token'] ?? null);

    $stage = 'payload_normalize';
    $countedQty = $data['counted_qty'] ?? [];
    $notes = trim((string)($data['notes'] ?? ''));
    $timestamp = $data['timestamp'] ?? date('c');

    $validCounts = [];
    if (is_array($countedQty)) {
        foreach ($countedQty as $pid => $qty) {
            $pid = trim((string)$pid);
            $qty = (int)$qty;
            if ($pid !== '' && $qty >= 0) { $validCounts[$pid] = $qty; }
        }
    }
    if ($DEBUG_DRAFT) {
        header('X-DraftSave-Counts: ' . count($validCounts));
    }

    $stage = 'db_connect';
    $db = get_database_connection();
    if (!$db) { throw new RuntimeException('Database connection unavailable'); }

    // Detect optional vend_product_id column ONLY once to decide SQL shape
    $stage = 'column_detect';
    $hasVendProductId = false;
    if ($result = $db->query("SHOW COLUMNS FROM stock_transfer_items LIKE 'vend_product_id'")) {
        $hasVendProductId = $result->num_rows > 0;
        $result->close();
    }
    if ($DEBUG_DRAFT) header('X-DraftSave-VendPid: ' . ($hasVendProductId ? '1' : '0'));

    $stage = 'tx_begin';
    $db->begin_transaction();
    try {
        $stage = 'item_updates';
        $updateCount = 0;
        if ($validCounts) {
            $itemsSql = $hasVendProductId
                ? "UPDATE stock_transfer_items SET counted_qty = ?, last_modified = NOW(), modified_by = ? WHERE transfer_id = ? AND (product_id = ? OR vend_product_id = ?)"
                : "UPDATE stock_transfer_items SET counted_qty = ?, last_modified = NOW(), modified_by = ? WHERE transfer_id = ? AND product_id = ?";
            foreach ($validCounts as $pid => $qty) {
                $stmt = $db->prepare($itemsSql);
                if (!$stmt) { throw new RuntimeException('Prepare failed (items) ' . $db->error); }
                $p = (string)$pid;
                if ($hasVendProductId) {
                    if (!$stmt->bind_param('iiiss', $qty, $userId, $transferId, $p, $p)) { $err = $stmt->error ?: $db->error; $stmt->close(); throw new RuntimeException('Bind failed (items) ' . $err); }
                } else {
                    if (!$stmt->bind_param('iiii', $qty, $userId, $transferId, $pid)) { $err = $stmt->error ?: $db->error; $stmt->close(); throw new RuntimeException('Bind failed (items-no-vpid) ' . $err); }
                }
                if (!$stmt->execute()) { $err = $stmt->error ?: $db->error; $stmt->close(); throw new RuntimeException('Execute failed (items) ' . $err); }
                if ($stmt->affected_rows > 0) { $updateCount++; }
                $stmt->close();
            }
            if ($DEBUG_DRAFT) header('X-DraftSave-ItemsUpdated: ' . $updateCount);
        }

        $stage = 'notes_update';
        if ($notes !== '') {
            $stmt = $db->prepare("UPDATE stock_transfers SET notes = ?, last_modified = NOW(), modified_by = ? WHERE id = ?");
            if (!$stmt) { throw new RuntimeException('Prepare failed (notes) ' . $db->error); }
            if (!$stmt->bind_param('sii', $notes, $userId, $transferId)) { $err = $stmt->error ?: $db->error; $stmt->close(); throw new RuntimeException('Bind failed (notes) ' . $err); }
            if (!$stmt->execute()) { $err = $stmt->error ?: $db->error; $stmt->close(); throw new RuntimeException('Execute failed (notes) ' . $err); }
            $stmt->close();
        }

        $stage = 'audit_insert';
        $stmt = $db->prepare("INSERT INTO transfer_audit_log (transfer_id, user_id, action, details, created_at) VALUES (?, ?, 'draft_save', ?, NOW())");
        if (!$stmt) { throw new RuntimeException('Prepare failed (audit) ' . $db->error); }
        $details = json_encode([
            'items_updated' => $updateCount,
            'notes_updated' => $notes !== '',
            'timestamp' => $timestamp,
            'counted_products' => array_keys($validCounts)
        ]);
        if (!$stmt->bind_param('iis', $transferId, $userId, $details)) { $err = $stmt->error ?: $db->error; $stmt->close(); throw new RuntimeException('Bind failed (audit) ' . $err); }
        if (!$stmt->execute()) { $err = $stmt->error ?: $db->error; $stmt->close(); throw new RuntimeException('Execute failed (audit) ' . $err); }
        $stmt->close();

        $stage = 'commit';
        $db->commit();
        echo json_encode([
            'success' => true,
            'data' => [
                'transfer_id' => $transferId,
                'items_updated' => $updateCount,
                'notes_updated' => $notes !== '',
                'timestamp' => date('c')
            ],
            'request_id' => bin2hex(random_bytes(8))
        ]);
    } catch (Exception $inner) {
        $db->rollback();
        throw $inner;
    }
} catch (Exception $e) {
    error_log('Draft save error: stage=' . ($stage ?? 'n/a') . ' msg=' . $e->getMessage());
    $isLock = stripos($e->getMessage(), 'lock') !== false;
    http_response_code($isLock ? 423 : 500);
    echo json_encode([
        'success' => false,
        'error' => [
            'code' => $isLock ? 'LOCK_VIOLATION' : 'SAVE_FAILED',
            'message' => $isLock ? 'Transfer lock not held' : 'Failed to save draft',
            'details' => $e->getMessage(),
            'stage' => $stage
        ],
        'request_id' => bin2hex(random_bytes(8))
    ]);
}