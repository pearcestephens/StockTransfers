<?php
/**
 * Box Allocation API - Auto-sort items into optimal boxes
 * 
 * Provides REST API endpoints for the box allocation engine
 * 
 * @author CIS Development Team  
 * @version 2.0
 * @created 2025-09-26
 */

require_once $_SERVER['DOCUMENT_ROOT'].'/modules/transfers/_local_shims.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/app.php';
require_once 'box_allocation_engine.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $path_info = $_SERVER['PATH_INFO'] ?? '';
    $path_parts = explode('/', trim($path_info, '/'));
    
    $engine = new BoxAllocationEngine();
    
    switch ($method) {
        case 'POST':
            if ($path_parts[0] === 'allocate') {
                handleAllocateRequest($engine);
            } elseif ($path_parts[0] === 'reallocate') {
                handleReallocateRequest($engine);
            } else {
                throw new Exception('Invalid endpoint');
            }
            break;
            
        case 'GET':
            if ($path_parts[0] === 'view' && isset($path_parts[1])) {
                handleViewRequest($engine, (int)$path_parts[1]);
            } elseif ($path_parts[0] === 'carriers') {
                handleCarriersRequest();
            } else {
                throw new Exception('Invalid endpoint');
            }
            break;
            
        default:
            throw new Exception('Method not allowed');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('c')
    ]);
}

/**
 * Handle initial box allocation request
 */
function handleAllocateRequest($engine) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['transfer_id'])) {
        throw new Exception('transfer_id is required');
    }
    
    $transfer_id = (int)$input['transfer_id'];
    $options = $input['options'] ?? [];
    
    // Validate transfer exists and is in correct state
    validateTransferForAllocation($transfer_id);
    
    $result = $engine->allocateTransferItems($transfer_id, $options);
    
    // Log the allocation
    logAllocationEvent($transfer_id, 'AUTO_ALLOCATE', $result);
    
    echo json_encode($result);
}

/**
 * Handle reallocation request (when user modifies boxes)
 */
function handleReallocateRequest($engine) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['transfer_id']) || !isset($input['modifications'])) {
        throw new Exception('transfer_id and modifications are required');
    }
    
    $transfer_id = (int)$input['transfer_id'];
    $modifications = $input['modifications'];
    
    // Apply user modifications and re-optimize
    $result = applyUserModifications($engine, $transfer_id, $modifications);
    
    // Log the reallocation
    logAllocationEvent($transfer_id, 'USER_REALLOCATE', $result);
    
    echo json_encode($result);
}

/**
 * Handle view current allocation request
 */
function handleViewRequest($engine, $transfer_id) {
    global $db;
    
    // Get current box allocations
    $query = "
        SELECT 
            tp.id as parcel_id,
            tp.box_number,
            tp.weight_kg,
            tp.length_mm,
            tp.width_mm, 
            tp.height_mm,
            tp.courier,
            tp.status,
            ts.delivery_mode,
            -- Parcel items
            tpi.item_id,
            tpi.qty,
            ti.product_id,
            vp.name as product_name,
            vp.retail_price,
            vc.name as category_name,
            -- Dimensions
            COALESCE(cd.weight_g, 0) as unit_weight_g,
            COALESCE(cd.volume_ml, 0) as unit_volume_ml
        FROM transfer_parcels tp
        JOIN transfer_shipments ts ON tp.shipment_id = ts.id
        LEFT JOIN transfer_parcel_items tpi ON tp.id = tpi.parcel_id
        LEFT JOIN transfer_items ti ON tpi.item_id = ti.id
        LEFT JOIN vend_products vp ON ti.product_id = vp.id
        LEFT JOIN vend_product_types vpt ON vp.product_type_id = vpt.id
        LEFT JOIN vend_categories vc ON vpt.category_id = vc.id
        LEFT JOIN category_dimensions cd ON vc.name = cd.category_name
        WHERE ts.transfer_id = ? 
            AND tp.deleted_at IS NULL
            AND ts.deleted_at IS NULL
        ORDER BY tp.box_number, tpi.id
    ";
    
    $stmt = $db->prepare($query);
    $stmt->bind_param('i', $transfer_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Group by box
    $boxes = [];
    foreach ($rows as $row) {
        $box_num = $row['box_number'];
        
        if (!isset($boxes[$box_num])) {
            $boxes[$box_num] = [
                'parcel_id' => $row['parcel_id'],
                'box_number' => $box_num,
                'weight_kg' => $row['weight_kg'],
                'length_mm' => $row['length_mm'],
                'width_mm' => $row['width_mm'],
                'height_mm' => $row['height_mm'],
                'courier' => $row['courier'],
                'status' => $row['status'],
                'delivery_mode' => $row['delivery_mode'],
                'items' => [],
                'total_value' => 0,
                'item_count' => 0
            ];
        }
        
        if ($row['item_id']) {
            $boxes[$box_num]['items'][] = [
                'item_id' => $row['item_id'],
                'product_id' => $row['product_id'],
                'product_name' => $row['product_name'],
                'category_name' => $row['category_name'],
                'qty' => $row['qty'],
                'unit_price' => $row['retail_price'],
                'unit_weight_g' => $row['unit_weight_g'],
                'unit_volume_ml' => $row['unit_volume_ml'],
                'total_value' => $row['qty'] * $row['retail_price']
            ];
            
            $boxes[$box_num]['total_value'] += $row['qty'] * $row['retail_price'];
            $boxes[$box_num]['item_count'] += $row['qty'];
        }
    }
    
    echo json_encode([
        'success' => true,
        'transfer_id' => $transfer_id,
        'boxes' => array_values($boxes),
        'total_boxes' => count($boxes),
        'total_items' => array_sum(array_column($boxes, 'item_count'))
    ]);
}

/**
 * Handle carriers list request
 */
function handleCarriersRequest() {
    global $db;
    
    $query = "
        SELECT DISTINCT
            carrier,
            service_level,
            container_type,
            max_weight_kg,
            max_length_mm,
            max_width_mm, 
            max_height_mm,
            base_price_cents / 100 as base_price_dollars,
            per_kg_cents / 100 as per_kg_dollars,
            (max_length_mm * max_width_mm * max_height_mm / 1000) as max_volume_ml
        FROM v_pricing_matrix
        WHERE is_active = 1
        ORDER BY carrier, base_price_cents
    ";
    
    $result = $db->query($query);
    $carriers = $result->fetch_all(MYSQLI_ASSOC);
    
    echo json_encode([
        'success' => true,
        'carriers' => $carriers
    ]);
}

/**
 * Validate transfer is ready for allocation
 */
function validateTransferForAllocation($transfer_id) {
    global $db;
    
    $query = "
        SELECT 
            t.status,
            t.state,
            COUNT(ti.id) as item_count
        FROM transfers t
        LEFT JOIN transfer_items ti ON t.id = ti.transfer_id AND ti.deleted_at IS NULL
        WHERE t.id = ? AND t.deleted_at IS NULL
        GROUP BY t.id
    ";
    
    $stmt = $db->prepare($query);
    $stmt->bind_param('i', $transfer_id);
    $stmt->execute();
    $transfer = $stmt->get_result()->fetch_assoc();
    
    if (!$transfer) {
        throw new Exception("Transfer {$transfer_id} not found");
    }
    
    if ($transfer['item_count'] == 0) {
        throw new Exception("Transfer {$transfer_id} has no items to allocate");
    }
    
    if ($transfer['status'] === 'cancelled') {
        throw new Exception("Cannot allocate cancelled transfer");
    }
}

/**
 * Apply user modifications to existing allocation
 */
function applyUserModifications($engine, $transfer_id, $modifications) {
    global $db;
    
    $db->begin_transaction();
    
    try {
        foreach ($modifications as $mod) {
            switch ($mod['action']) {
                case 'move_item':
                    moveItemBetweenBoxes($mod['item_id'], $mod['from_parcel'], $mod['to_parcel'], $mod['qty']);
                    break;
                    
                case 'split_box':
                    splitBox($mod['parcel_id'], $mod['items_to_split']);
                    break;
                    
                case 'merge_boxes':
                    mergeBoxes($mod['parcel_ids']);
                    break;
                    
                case 'change_carrier':
                    changeBoxCarrier($mod['parcel_id'], $mod['new_carrier'], $mod['new_container']);
                    break;
                    
                default:
                    throw new Exception("Unknown modification action: {$mod['action']}");
            }
        }
        
        // Re-calculate totals and costs
        $updated_allocation = recalculateAllocation($transfer_id);
        
        $db->commit();
        
        return [
            'success' => true,
            'transfer_id' => $transfer_id,
            'modifications_applied' => count($modifications),
            'updated_allocation' => $updated_allocation
        ];
        
    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
}

/**
 * Move item between boxes
 */
function moveItemBetweenBoxes($item_id, $from_parcel_id, $to_parcel_id, $qty) {
    global $db;
    
    // Update quantities
    $db->query("
        UPDATE transfer_parcel_items 
        SET qty = qty - {$qty}
        WHERE parcel_id = {$from_parcel_id} AND item_id = {$item_id}
    ");
    
    // Insert or update in destination box
    $db->query("
        INSERT INTO transfer_parcel_items (parcel_id, item_id, qty, created_at)
        VALUES ({$to_parcel_id}, {$item_id}, {$qty}, NOW())
        ON DUPLICATE KEY UPDATE qty = qty + {$qty}
    ");
    
    // Remove empty entries
    $db->query("DELETE FROM transfer_parcel_items WHERE qty <= 0");
}

/**
 * Log allocation events for audit trail
 */
function logAllocationEvent($transfer_id, $action, $result) {
    global $db;
    
    $event_data = json_encode([
        'action' => $action,
        'result' => $result,
        'timestamp' => date('c')
    ]);
    
    $query = "
        INSERT INTO transfer_logs 
        (transfer_id, event_type, event_data, actor_user_id, source_system, created_at)
        VALUES (?, 'BOX_ALLOCATION', ?, NULL, 'CIS', NOW())
    ";
    
    $stmt = $db->prepare($query);
    $stmt->bind_param('is', $transfer_id, $event_data);
    $stmt->execute();
}

/**
 * Recalculate allocation after modifications
 */
function recalculateAllocation($transfer_id) {
    global $db;
    
    // Get updated box data
    $query = "
        SELECT 
            tp.id as parcel_id,
            tp.box_number,
            tp.weight_kg,
            tp.courier,
            SUM(tpi.qty * vp.retail_price) as total_value,
            SUM(tpi.qty) as item_count,
            SUM(tpi.qty * COALESCE(cd.weight_g, 0)) as total_weight_g
        FROM transfer_parcels tp
        JOIN transfer_shipments ts ON tp.shipment_id = ts.id
        LEFT JOIN transfer_parcel_items tpi ON tp.id = tpi.parcel_id
        LEFT JOIN transfer_items ti ON tpi.item_id = ti.id
        LEFT JOIN vend_products vp ON ti.product_id = vp.id
        LEFT JOIN vend_product_types vpt ON vp.product_type_id = vpt.id
        LEFT JOIN vend_categories vc ON vpt.category_id = vc.id
        LEFT JOIN category_dimensions cd ON vc.name = cd.category_name
        WHERE ts.transfer_id = ? 
            AND tp.deleted_at IS NULL
            AND ts.deleted_at IS NULL
        GROUP BY tp.id
        ORDER BY tp.box_number
    ";
    
    $stmt = $db->prepare($query);
    $stmt->bind_param('i', $transfer_id);
    $stmt->execute();
    $boxes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Update parcel weights
    foreach ($boxes as $box) {
        $weight_kg = $box['total_weight_g'] / 1000;
        $db->query("
            UPDATE transfer_parcels 
            SET weight_kg = {$weight_kg}, updated_at = NOW() 
            WHERE id = {$box['parcel_id']}
        ");
    }
    
    // Update transfer totals
    $total_boxes = count($boxes);
    $total_weight_g = array_sum(array_column($boxes, 'total_weight_g'));
    
    $db->query("
        UPDATE transfers 
        SET total_boxes = {$total_boxes}, total_weight_g = {$total_weight_g}, updated_at = NOW()
        WHERE id = {$transfer_id}
    ");
    
    return $boxes;
}