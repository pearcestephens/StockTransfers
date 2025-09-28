<?php
/**
 * Box Allocation Engine - Sophisticated Auto-Sorting Algorithm
 * 
 * Automatically sorts transfer items into optimal box configurations
 * using carrier constraints, product dimensions, and cost optimization.
 * 
 * @author CIS Development Team
 * @version 2.0
 * @created 2025-09-26
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/app.php';

class BoxAllocationEngine {
    
    private $db;
    private $logger;
    private $freight_system;
    
    // Algorithm configuration
    private $config = [
        'weight_safety_factor' => 0.9,      // Use 90% of max weight capacity
        'volume_safety_factor' => 0.85,     // Use 85% of max volume capacity
        'max_items_per_box' => 50,          // Maximum items in single box
        'prefer_single_category' => true,    // Try to keep categories together
        'fragile_separation' => true,        // Separate fragile items
        'liquid_separation' => true,         // Separate liquid items
        'priority_products' => [],           // High-value items get priority
        'cost_optimization' => true         // Optimize for shipping cost
    ];
    
    public function __construct() {
        global $db;
        $this->db = $db;
        $this->logger = new Logger('box_allocation');
        $this->freight_system = new FreightRecommendationEngine();
    }
    
    /**
     * Main entry point - allocate items to boxes for a transfer
     */
    public function allocateTransferItems($transfer_id, $options = []) {
        try {
            $this->logger->info("Starting box allocation for transfer {$transfer_id}");
            
            // Merge options with defaults
            $this->config = array_merge($this->config, $options);
            
            // Get transfer items with dimensions and weights
            $items = $this->getTransferItemsWithDimensions($transfer_id);
            if (empty($items)) {
                throw new Exception("No items found for transfer {$transfer_id}");
            }
            
            // Get available carrier options for this route
            $transfer_info = $this->getTransferRoute($transfer_id);
            $carrier_options = $this->getCarrierOptions($transfer_info);
            
            // Sort items by allocation priority
            $sorted_items = $this->prioritizeItems($items);
            
            // Generate optimal box configurations
            $box_allocations = $this->generateOptimalBoxes($sorted_items, $carrier_options);
            
            // Validate and optimize the allocation
            $validated_allocations = $this->validateAndOptimize($box_allocations, $carrier_options);
            
            // Save the allocation to database
            $this->saveBoxAllocations($transfer_id, $validated_allocations);
            
            $this->logger->info("Box allocation completed for transfer {$transfer_id}");
            
            return [
                'success' => true,
                'transfer_id' => $transfer_id,
                'total_boxes' => count($validated_allocations),
                'total_items' => count($items),
                'allocations' => $validated_allocations,
                'cost_estimate' => $this->calculateShippingCost($validated_allocations, $carrier_options)
            ];
            
        } catch (Exception $e) {
            $this->logger->error("Box allocation failed for transfer {$transfer_id}: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'transfer_id' => $transfer_id
            ];
        }
    }
    
    /**
     * Get transfer items with comprehensive dimension data
     */
    private function getTransferItemsWithDimensions($transfer_id) {
        $query = "
            SELECT 
                ti.id as item_id,
                ti.product_id,
                ti.qty_requested,
                vp.name as product_name,
                vp.retail_price,
                COALESCE(cd.weight_g, 0) as unit_weight_g,
                COALESCE(cd.length_mm, 0) as unit_length_mm,
                COALESCE(cd.width_mm, 0) as unit_width_mm,
                COALESCE(cd.height_mm, 0) as unit_height_mm,
                COALESCE(cd.volume_ml, 0) as unit_volume_ml,
                vc.name as category_name,
                CASE 
                    WHEN vc.name LIKE '%liquid%' OR vc.name LIKE '%juice%' THEN 1 
                    ELSE 0 
                END as is_liquid,
                CASE 
                    WHEN vp.name LIKE '%glass%' OR vc.name LIKE '%fragile%' THEN 1 
                    ELSE 0 
                END as is_fragile,
                CASE 
                    WHEN vp.retail_price > 100 THEN 1 
                    ELSE 0 
                END as is_high_value,
                -- Calculate total dimensions for this line item
                (ti.qty_requested * COALESCE(cd.weight_g, 0)) as total_weight_g,
                (ti.qty_requested * COALESCE(cd.volume_ml, 0)) as total_volume_ml,
                (ti.qty_requested * vp.retail_price) as total_value
            FROM transfer_items ti
            JOIN vend_products vp ON ti.product_id = vp.id
            LEFT JOIN vend_product_types vpt ON vp.product_type_id = vpt.id
            LEFT JOIN vend_categories vc ON vpt.category_id = vc.id
            LEFT JOIN category_dimensions cd ON vc.name = cd.category_name
            WHERE ti.transfer_id = ?
                AND ti.deleted_at IS NULL
                AND ti.qty_requested > 0
            ORDER BY 
                is_high_value DESC,
                total_value DESC,
                is_fragile DESC,
                is_liquid DESC
        ";
        
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $transfer_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->fetch_all(MYSQLI_ASSOC);
    }
    
    /**
     * Get transfer route information
     */
    private function getTransferRoute($transfer_id) {
        $query = "
            SELECT 
                t.outlet_from,
                t.outlet_to,
                from_outlet.name as from_name,
                to_outlet.name as to_name,
                from_outlet.physical_address1,
                to_outlet.physical_city,
                to_outlet.physical_postcode
            FROM transfers t
            LEFT JOIN vend_outlets from_outlet ON t.outlet_from = from_outlet.id
            LEFT JOIN vend_outlets to_outlet ON t.outlet_to = to_outlet.id
            WHERE t.id = ?
        ";
        
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $transfer_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->fetch_assoc();
    }
    
    /**
     * Get available carrier options with container specifications
     */
    private function getCarrierOptions($transfer_info) {
        $query = "
            SELECT DISTINCT
                pm.carrier,
                pm.service_level,
                pm.container_type,
                pm.max_weight_kg,
                pm.max_length_mm,
                pm.max_width_mm,
                pm.max_height_mm,
                pm.base_price_cents,
                pm.per_kg_cents,
                -- Calculate volume in ml
                (pm.max_length_mm * pm.max_width_mm * pm.max_height_mm / 1000) as max_volume_ml
            FROM v_pricing_matrix pm
            WHERE pm.is_active = 1
            ORDER BY pm.base_price_cents ASC, pm.max_weight_kg DESC
        ";
        
        $result = $this->db->query($query);
        return $result->fetch_all(MYSQLI_ASSOC);
    }
    
    /**
     * Prioritize items for box allocation
     */
    private function prioritizeItems($items) {
        // Sort by multiple criteria for optimal packing
        usort($items, function($a, $b) {
            // High-value items first
            if ($a['is_high_value'] != $b['is_high_value']) {
                return $b['is_high_value'] - $a['is_high_value'];
            }
            
            // Fragile items together
            if ($a['is_fragile'] != $b['is_fragile']) {
                return $b['is_fragile'] - $a['is_fragile'];
            }
            
            // Liquid items together
            if ($a['is_liquid'] != $b['is_liquid']) {
                return $b['is_liquid'] - $a['is_liquid'];
            }
            
            // Larger items first (easier to pack around)
            $volume_diff = $b['total_volume_ml'] - $a['total_volume_ml'];
            if ($volume_diff != 0) {
                return $volume_diff > 0 ? 1 : -1;
            }
            
            // Same category items together
            return strcmp($a['category_name'], $b['category_name']);
        });
        
        return $items;
    }
    
    /**
     * Generate optimal box configurations using advanced algorithms
     */
    private function generateOptimalBoxes($items, $carrier_options) {
        $boxes = [];
        $current_box = null;
        $box_number = 1;
        
        foreach ($items as $item) {
            $packed = false;
            
            // Try to fit item in existing boxes first
            foreach ($boxes as &$box) {
                if ($this->canFitInBox($item, $box, $carrier_options)) {
                    $this->addItemToBox($item, $box);
                    $packed = true;
                    break;
                }
            }
            
            // Create new box if item doesn't fit anywhere
            if (!$packed) {
                $new_box = $this->createNewBox($box_number++, $carrier_options);
                if ($this->canFitInBox($item, $new_box, $carrier_options)) {
                    $this->addItemToBox($item, $new_box);
                    $boxes[] = $new_box;
                } else {
                    // Item too large for any container - split into multiple units
                    $split_boxes = $this->splitLargeItem($item, $box_number, $carrier_options);
                    $boxes = array_merge($boxes, $split_boxes);
                    $box_number += count($split_boxes);
                }
            }
        }
        
        return $boxes;
    }
    
    /**
     * Check if item can fit in box considering weight, volume, and business rules
     */
    private function canFitInBox($item, &$box, $carrier_options) {
        $container = $this->getBestContainer($box, $carrier_options);
        if (!$container) return false;
        
        // Check weight capacity
        $new_weight = $box['total_weight_g'] + $item['total_weight_g'];
        $max_weight_g = $container['max_weight_kg'] * 1000 * $this->config['weight_safety_factor'];
        if ($new_weight > $max_weight_g) return false;
        
        // Check volume capacity
        $new_volume = $box['total_volume_ml'] + $item['total_volume_ml'];
        $max_volume_ml = $container['max_volume_ml'] * $this->config['volume_safety_factor'];
        if ($new_volume > $max_volume_ml) return false;
        
        // Check item count limit
        if (count($box['items']) >= $this->config['max_items_per_box']) return false;
        
        // Business rule: Don't mix liquids with electronics
        if ($item['is_liquid'] && $this->boxContainsElectronics($box)) return false;
        
        // Business rule: Keep fragile items together if possible
        if ($this->config['fragile_separation'] && 
            $item['is_fragile'] && 
            $this->boxContainsNonFragile($box)) return false;
        
        // Business rule: Try to keep same category together
        if ($this->config['prefer_single_category'] && 
            !empty($box['items']) && 
            $this->boxHasDifferentCategory($item, $box)) {
            // Allow if box is mostly empty
            return count($box['items']) <= 2;
        }
        
        return true;
    }
    
    /**
     * Add item to box and update totals
     */
    private function addItemToBox($item, &$box) {
        $box['items'][] = $item;
        $box['total_weight_g'] += $item['total_weight_g'];
        $box['total_volume_ml'] += $item['total_volume_ml'];
        $box['total_value'] += $item['total_value'];
        $box['item_count'] += $item['qty_requested'];
        
        // Update box characteristics
        $box['contains_fragile'] = $box['contains_fragile'] || $item['is_fragile'];
        $box['contains_liquid'] = $box['contains_liquid'] || $item['is_liquid'];
        $box['contains_high_value'] = $box['contains_high_value'] || $item['is_high_value'];
        
        // Track categories
        if (!in_array($item['category_name'], $box['categories'])) {
            $box['categories'][] = $item['category_name'];
        }
    }
    
    /**
     * Create a new empty box structure
     */
    private function createNewBox($box_number, $carrier_options) {
        return [
            'box_number' => $box_number,
            'items' => [],
            'total_weight_g' => 0,
            'total_volume_ml' => 0,
            'total_value' => 0.0,
            'item_count' => 0,
            'contains_fragile' => false,
            'contains_liquid' => false,
            'contains_high_value' => false,
            'categories' => [],
            'recommended_container' => null,
            'estimated_cost' => 0.0
        ];
    }
    
    /**
     * Handle items too large for standard containers
     */
    private function splitLargeItem($item, &$box_number, $carrier_options) {
        $boxes = [];
        $remaining_qty = $item['qty_requested'];
        
        // Find the largest available container
        $largest_container = $this->getLargestContainer($carrier_options);
        if (!$largest_container) {
            throw new Exception("No suitable containers available for large item: " . $item['product_name']);
        }
        
        // Calculate how many units can fit per box
        $max_weight_per_box = $largest_container['max_weight_kg'] * 1000 * $this->config['weight_safety_factor'];
        $max_volume_per_box = $largest_container['max_volume_ml'] * $this->config['volume_safety_factor'];
        
        $max_qty_by_weight = floor($max_weight_per_box / $item['unit_weight_g']);
        $max_qty_by_volume = floor($max_volume_per_box / $item['unit_volume_ml']);
        $max_qty_per_box = min($max_qty_by_weight, $max_qty_by_volume, $this->config['max_items_per_box']);
        
        if ($max_qty_per_box <= 0) {
            throw new Exception("Item too large for any available container: " . $item['product_name']);
        }
        
        // Split into multiple boxes
        while ($remaining_qty > 0) {
            $box_qty = min($remaining_qty, $max_qty_per_box);
            $split_item = $item;
            $split_item['qty_requested'] = $box_qty;
            $split_item['total_weight_g'] = $box_qty * $item['unit_weight_g'];
            $split_item['total_volume_ml'] = $box_qty * $item['unit_volume_ml'];
            $split_item['total_value'] = $box_qty * $item['retail_price'];
            
            $new_box = $this->createNewBox($box_number++, $carrier_options);
            $this->addItemToBox($split_item, $new_box);
            $boxes[] = $new_box;
            
            $remaining_qty -= $box_qty;
        }
        
        return $boxes;
    }
    
    /**
     * Get the best container for a box based on current contents
     */
    private function getBestContainer($box, $carrier_options) {
        $suitable_containers = [];
        
        foreach ($carrier_options as $container) {
            $max_weight_g = $container['max_weight_kg'] * 1000;
            $max_volume_ml = $container['max_volume_ml'];
            
            if ($box['total_weight_g'] <= $max_weight_g && 
                $box['total_volume_ml'] <= $max_volume_ml) {
                $suitable_containers[] = $container;
            }
        }
        
        if (empty($suitable_containers)) return null;
        
        // Sort by cost efficiency
        usort($suitable_containers, function($a, $b) {
            return $a['base_price_cents'] - $b['base_price_cents'];
        });
        
        return $suitable_containers[0];
    }
    
    /**
     * Get the largest available container
     */
    private function getLargestContainer($carrier_options) {
        $largest = null;
        $max_capacity = 0;
        
        foreach ($carrier_options as $container) {
            $capacity = $container['max_weight_kg'] * $container['max_volume_ml'];
            if ($capacity > $max_capacity) {
                $max_capacity = $capacity;
                $largest = $container;
            }
        }
        
        return $largest;
    }
    
    /**
     * Business rule helpers
     */
    private function boxContainsElectronics($box) {
        foreach ($box['items'] as $item) {
            if (stripos($item['category_name'], 'electronic') !== false ||
                stripos($item['category_name'], 'device') !== false ||
                stripos($item['category_name'], 'mod') !== false) {
                return true;
            }
        }
        return false;
    }
    
    private function boxContainsNonFragile($box) {
        foreach ($box['items'] as $item) {
            if (!$item['is_fragile']) {
                return true;
            }
        }
        return false;
    }
    
    private function boxHasDifferentCategory($item, $box) {
        if (empty($box['categories'])) return false;
        return !in_array($item['category_name'], $box['categories']);
    }
    
    /**
     * Validate and optimize the final allocation
     */
    private function validateAndOptimize($box_allocations, $carrier_options) {
        $optimized_boxes = [];
        
        foreach ($box_allocations as $box) {
            // Find optimal container for this box
            $optimal_container = $this->getBestContainer($box, $carrier_options);
            if (!$optimal_container) {
                throw new Exception("No suitable container found for box {$box['box_number']}");
            }
            
            $box['recommended_container'] = $optimal_container;
            $box['estimated_cost'] = $this->calculateBoxCost($box, $optimal_container);
            
            // Set dimensions based on container
            $box['length_mm'] = $optimal_container['max_length_mm'];
            $box['width_mm'] = $optimal_container['max_width_mm'];
            $box['height_mm'] = $optimal_container['max_height_mm'];
            $box['max_weight_kg'] = $optimal_container['max_weight_kg'];
            $box['carrier'] = $optimal_container['carrier'];
            $box['service_level'] = $optimal_container['service_level'];
            
            $optimized_boxes[] = $box;
        }
        
        // Try to consolidate boxes if possible
        if ($this->config['cost_optimization']) {
            $optimized_boxes = $this->attemptConsolidation($optimized_boxes, $carrier_options);
        }
        
        return $optimized_boxes;
    }
    
    /**
     * Attempt to consolidate boxes to reduce shipping costs
     */
    private function attemptConsolidation($boxes, $carrier_options) {
        $consolidated = [];
        $remaining_boxes = $boxes;
        
        while (!empty($remaining_boxes)) {
            $primary_box = array_shift($remaining_boxes);
            $merged_any = true;
            
            // Try to merge other boxes into this one
            while ($merged_any) {
                $merged_any = false;
                
                foreach ($remaining_boxes as $key => $candidate_box) {
                    if ($this->canMergeBoxes($primary_box, $candidate_box, $carrier_options)) {
                        $primary_box = $this->mergeBoxes($primary_box, $candidate_box);
                        unset($remaining_boxes[$key]);
                        $remaining_boxes = array_values($remaining_boxes);
                        $merged_any = true;
                        break;
                    }
                }
            }
            
            $consolidated[] = $primary_box;
        }
        
        // Renumber boxes
        foreach ($consolidated as $index => &$box) {
            $box['box_number'] = $index + 1;
        }
        
        return $consolidated;
    }
    
    /**
     * Check if two boxes can be merged
     */
    private function canMergeBoxes($box1, $box2, $carrier_options) {
        // Calculate combined metrics
        $combined_weight = $box1['total_weight_g'] + $box2['total_weight_g'];
        $combined_volume = $box1['total_volume_ml'] + $box2['total_volume_ml'];
        $combined_items = count($box1['items']) + count($box2['items']);
        
        // Check if any container can handle the combined load
        foreach ($carrier_options as $container) {
            $max_weight_g = $container['max_weight_kg'] * 1000 * $this->config['weight_safety_factor'];
            $max_volume_ml = $container['max_volume_ml'] * $this->config['volume_safety_factor'];
            
            if ($combined_weight <= $max_weight_g && 
                $combined_volume <= $max_volume_ml &&
                $combined_items <= $this->config['max_items_per_box']) {
                
                // Check business rules
                if (($box1['contains_liquid'] || $box2['contains_liquid']) && 
                    ($this->boxContainsElectronics($box1) || $this->boxContainsElectronics($box2))) {
                    continue;
                }
                
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Merge two boxes into one
     */
    private function mergeBoxes($box1, $box2) {
        $merged = $box1;
        
        // Combine items
        $merged['items'] = array_merge($box1['items'], $box2['items']);
        
        // Update totals
        $merged['total_weight_g'] = $box1['total_weight_g'] + $box2['total_weight_g'];
        $merged['total_volume_ml'] = $box1['total_volume_ml'] + $box2['total_volume_ml'];
        $merged['total_value'] = $box1['total_value'] + $box2['total_value'];
        $merged['item_count'] = $box1['item_count'] + $box2['item_count'];
        
        // Update characteristics
        $merged['contains_fragile'] = $box1['contains_fragile'] || $box2['contains_fragile'];
        $merged['contains_liquid'] = $box1['contains_liquid'] || $box2['contains_liquid'];
        $merged['contains_high_value'] = $box1['contains_high_value'] || $box2['contains_high_value'];
        
        // Merge categories
        $merged['categories'] = array_unique(array_merge($box1['categories'], $box2['categories']));
        
        return $merged;
    }
    
    /**
     * Calculate shipping cost for a box
     */
    private function calculateBoxCost($box, $container) {
        $base_cost = $container['base_price_cents'] / 100;
        $weight_cost = ($box['total_weight_g'] / 1000) * ($container['per_kg_cents'] / 100);
        
        return $base_cost + $weight_cost;
    }
    
    /**
     * Calculate total shipping cost for all boxes
     */
    private function calculateShippingCost($boxes, $carrier_options) {
        $total_cost = 0;
        
        foreach ($boxes as $box) {
            $total_cost += $box['estimated_cost'];
        }
        
        return $total_cost;
    }
    
    /**
     * Save the box allocations to database
     */
    private function saveBoxAllocations($transfer_id, $allocations) {
        $this->db->begin_transaction();
        
        try {
            // Clear existing allocations for this transfer
            $this->clearExistingAllocations($transfer_id);
            
            // Create shipment record
            $shipment_id = $this->createShipmentRecord($transfer_id, $allocations);
            
            // Create parcel records and items
            foreach ($allocations as $box) {
                $parcel_id = $this->createParcelRecord($shipment_id, $box);
                $this->createParcelItems($parcel_id, $box['items']);
            }
            
            // Update transfer totals
            $this->updateTransferTotals($transfer_id, $allocations);
            
            $this->db->commit();
            
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    /**
     * Clear existing box allocations
     */
    private function clearExistingAllocations($transfer_id) {
        // Get existing shipments for this transfer
        $query = "SELECT id FROM transfer_shipments WHERE transfer_id = ? AND deleted_at IS NULL";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $transfer_id);
        $stmt->execute();
        $shipments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        foreach ($shipments as $shipment) {
            // Soft delete parcels and their items
            $this->db->query("UPDATE transfer_parcels SET deleted_at = NOW() WHERE shipment_id = {$shipment['id']}");
            $this->db->query("UPDATE transfer_parcel_items tpi 
                             JOIN transfer_parcels tp ON tpi.parcel_id = tp.id 
                             SET tpi.deleted_at = NOW() 
                             WHERE tp.shipment_id = {$shipment['id']}");
        }
        
        // Soft delete shipments
        $this->db->query("UPDATE transfer_shipments SET deleted_at = NOW() WHERE transfer_id = {$transfer_id}");
    }
    
    /**
     * Create shipment record
     */
    private function createShipmentRecord($transfer_id, $allocations) {
        $query = "
            INSERT INTO transfer_shipments 
            (transfer_id, delivery_mode, status, created_at) 
            VALUES (?, 'courier', 'packed', NOW())
        ";
        
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $transfer_id);
        $stmt->execute();
        
        return $this->db->insert_id;
    }
    
    /**
     * Create parcel record
     */
    private function createParcelRecord($shipment_id, $box) {
        $query = "
            INSERT INTO transfer_parcels 
            (shipment_id, box_number, weight_kg, length_mm, width_mm, height_mm, 
             courier, status, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
        ";
        
        $stmt = $this->db->prepare($query);
        $weight_kg = $box['total_weight_g'] / 1000;
        $stmt->bind_param('iidddiis', 
            $shipment_id, 
            $box['box_number'],
            $weight_kg,
            $box['length_mm'],
            $box['width_mm'], 
            $box['height_mm'],
            $box['carrier']
        );
        $stmt->execute();
        
        return $this->db->insert_id;
    }
    
    /**
     * Create parcel item records
     */
    private function createParcelItems($parcel_id, $items) {
        foreach ($items as $item) {
            $query = "
                INSERT INTO transfer_parcel_items 
                (parcel_id, item_id, qty, created_at) 
                VALUES (?, ?, ?, NOW())
            ";
            
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('iii', $parcel_id, $item['item_id'], $item['qty_requested']);
            $stmt->execute();
        }
    }
    
    /**
     * Update transfer totals
     */
    private function updateTransferTotals($transfer_id, $allocations) {
        $total_boxes = count($allocations);
        $total_weight_g = array_sum(array_column($allocations, 'total_weight_g'));
        
        $query = "
            UPDATE transfers 
            SET total_boxes = ?, total_weight_g = ?, updated_at = NOW() 
            WHERE id = ?
        ";
        
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('iii', $total_boxes, $total_weight_g, $transfer_id);
        $stmt->execute();
    }
}

// Logger class for debugging
class Logger {
    private $context;
    
    public function __construct($context) {
        $this->context = $context;
    }
    
    public function info($message) {
        error_log("[INFO] [{$this->context}] {$message}");
    }
    
    public function error($message) {
        error_log("[ERROR] [{$this->context}] {$message}");
    }
}

// FreightRecommendationEngine placeholder
class FreightRecommendationEngine {
    public function getRecommendations($route, $items) {
        // Placeholder for freight system integration
        return [];
    }
}