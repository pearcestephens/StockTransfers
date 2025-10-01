<?php
/**
 * Add Product to Transfer API
 * Adds a product to an existing transfer
 * 
 * SECURITY: Requires valid transfer lock ownership
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/app.php';
require_once __DIR__ . '/_lib/ServerLockGuard.php';

header('Content-Type: application/json');

try {
    $guard = ServerLockGuard::getInstance();
    
    // Validate authentication
    $userId = $guard->validateAuthOrDie();
    
    // Get JSON input
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data) {
        echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
        exit;
    }
    
    // Extract and validate transfer ID
    $transferId = $guard->extractTransferIdOrDie($data);
    
    // CRITICAL: Validate lock ownership before allowing product addition
    $guard->validateLockOrDie($transferId, $userId, 'add product');
    
    // Validate required fields
    $productId = (int)($data['product_id'] ?? 0);
    $quantity = (int)($data['quantity'] ?? 1);
    
    if (!$productId || $quantity < 1) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }
    
    $pdo = pdo();
    
    // Verify transfer exists and user has access
    $transferCheck = $pdo->prepare("
        SELECT id, status 
        FROM transfer_view 
        WHERE id = ? 
        LIMIT 1
    ");
    $transferCheck->execute([$transferId]);
    $transfer = $transferCheck->fetch(PDO::FETCH_ASSOC);
    
    if (!$transfer) {
        echo json_encode(['success' => false, 'message' => 'Transfer not found']);
        exit;
    }
    
    // Don't allow adding to completed transfers
    if (in_array($transfer['status'], ['completed', 'cancelled'])) {
        echo json_encode(['success' => false, 'message' => 'Cannot add products to completed transfers']);
        exit;
    }
    
    // Get product details
    $productCheck = $pdo->prepare("
        SELECT id, name, sku
        FROM vend_products 
        WHERE id = ? 
        AND active = 1 
        AND deleted_at IS NULL
    ");
    $productCheck->execute([$productId]);
    $product = $productCheck->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) {
        echo json_encode(['success' => false, 'message' => 'Product not found or inactive']);
        exit;
    }
    
    // Check if product already exists in transfer
    $existingCheck = $pdo->prepare("
        SELECT id, planned_qty 
        FROM transfer_items 
        WHERE transfer_id = ? 
        AND product_id = ?
    ");
    $existingCheck->execute([$transferId, $productId]);
    $existing = $existingCheck->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        // Update existing item
        $newQuantity = $existing['planned_qty'] + $quantity;
        $updateStmt = $pdo->prepare("
            UPDATE transfer_items 
            SET planned_qty = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $updateStmt->execute([$newQuantity, $existing['id']]);
        
        echo json_encode([
            'success' => true, 
            'message' => "Updated {$product['name']} quantity to {$newQuantity}",
            'action' => 'updated',
            'new_quantity' => $newQuantity
        ]);
    } else {
        // Add new item
        $insertStmt = $pdo->prepare("
            INSERT INTO transfer_items (
                transfer_id, 
                product_id, 
                planned_qty,
                counted_qty,
                created_at,
                updated_at
            ) VALUES (?, ?, ?, 0, NOW(), NOW())
        ");
        $insertStmt->execute([$transferId, $productId, $quantity]);
        
        echo json_encode([
            'success' => true, 
            'message' => "Added {$quantity}x {$product['name']} to transfer",
            'action' => 'added',
            'quantity' => $quantity
        ]);
    }
    
    // Update transfer modified timestamp
    $updateTransfer = $pdo->prepare("
        UPDATE transfers 
        SET updated_at = NOW() 
        WHERE id = ?
    ");
    $updateTransfer->execute([$transferId]);

} catch (Exception $e) {
    error_log('Add product error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Failed to add product',
        'error' => $e->getMessage()
    ]);
}
?>