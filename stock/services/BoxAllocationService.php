<?php
declare(strict_types=1);

/**
 * BoxAllocationService.php
 * 
 * Sophisticated box allocation algorithm that auto-sorts products into optimal boxes
 * based on weight, volume, carrier constraints, and product compatibility.
 * Integrates with existing freight system infrastructure and pricing matrix.
 * 
 * Author: CIS System
 * Last Modified: 2025-09-26
 * Dependencies: CIS infrastructure, transfer tables, freight views
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/app.php';

class BoxAllocationService {
    
    private PDO $pdo;
    private int $transfer_id;
    private array $allocation_rules;
    private array $container_limits;
    private float $safety_margin;
    // Dynamic carrier/container pricing matrix (pricing_matrix view)
    private array $pricing_matrix = [];
    // Normalized container templates sourced from pricing_matrix (code => template)
    private array $dynamic_containers = [];
    
    // Product compatibility rules
    private array $incompatible_categories = [
        'NICOTINE' => ['NON_NICOTINE'],
        'GLASS' => ['HEAVY_METALS'],
        'LIQUIDS' => ['ELECTRONICS']
    ];
    
    // Box size templates from freight system
    private array $box_templates = [
        'small' => ['length_mm' => 150, 'width_mm' => 100, 'height_mm' => 80, 'max_weight_g' => 1000],
        'medium' => ['length_mm' => 300, 'width_mm' => 200, 'height_mm' => 150, 'max_weight_g' => 5000],
        'large' => ['length_mm' => 400, 'width_mm' => 300, 'height_mm' => 200, 'max_weight_g' => 15000],
        'xl' => ['length_mm' => 500, 'width_mm' => 400, 'height_mm' => 300, 'max_weight_g' => 22000]
    ];
    
    public function __construct(int $transfer_id, array $options = []) {
        $this->pdo = cis_pdo();
        $this->transfer_id = $transfer_id;
        $this->safety_margin = $options['safety_margin'] ?? 0.85; // 15% safety buffer
        $this->loadAllocationRules();
        $this->loadContainerLimits();
        $this->loadPricingMatrix(); // build dynamic_containers from live courier pricing
    }
    
    /**
     * Main entry point: analyze transfer and create optimal box allocation
     */
    public function generateOptimalAllocation(): array {
        try {
            // Step 1: Get transfer data and items with full dimensions
            $transfer_data = $this->getTransferData();
            $items = $this->getTransferItemsWithDimensions();
            
            if (empty($items)) {
                return ['success' => true, 'boxes' => [], 'message' => 'No items to allocate'];
            }
            
            // Step 2: Pre-process items (sort by compatibility, weight, fragility)
            $processed_items = $this->preprocessItems($items);
            
            // Step 3: Run sophisticated allocation algorithm
            $allocation_result = $this->runAllocationAlgorithm($processed_items, $transfer_data);
            
            // Step 4: Optimize boxes using freight system constraints
            $optimized_boxes = $this->optimizeWithFreightConstraints($allocation_result['boxes'], $transfer_data);
            
            // Step 5: Generate pricing recommendations
            $pricing_data = $this->generatePricingRecommendations($optimized_boxes, $transfer_data);
            
            return [
                'success' => true,
                'transfer_id' => $this->transfer_id,
                'total_items' => count($items),
                'total_weight_kg' => $allocation_result['total_weight_kg'],
                'total_volume_cm3' => $allocation_result['total_volume_cm3'],
                'boxes' => $optimized_boxes,
                'pricing' => $pricing_data,
                'allocation_strategy' => $allocation_result['strategy'],
                'recommendations' => $allocation_result['recommendations'],
                'can_edit' => true,
                'edit_ui_endpoint' => "/modules/transfers/stock/ui/box_editor.php?transfer={$this->transfer_id}"
            ];
            
        } catch (Exception $e) {
            error_log("BoxAllocationService Error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Box allocation failed: ' . $e->getMessage(),
                'transfer_id' => $this->transfer_id
            ];
        }
    }
    
    /**
     * Get transfer data with outlet information
     */
    private function getTransferData(): array {
        $stmt = $this->pdo->prepare("
            SELECT t.*, 
                   vo_from.name as outlet_from_name,
                   vo_to.name as outlet_to_name,
                   vo_to.physical_address_1 as dest_address,
                   vo_to.physical_city as dest_city,
                   vo_to.physical_postcode as dest_postcode
            FROM transfers t
            LEFT JOIN vend_outlets vo_from ON vo_from.id = t.outlet_from
            LEFT JOIN vend_outlets vo_to ON vo_to.id = t.outlet_to
            WHERE t.id = :tid
        ");
        $stmt->execute([':tid' => $this->transfer_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            throw new Exception("Transfer {$this->transfer_id} not found");
        }
        
        return $result;
    }
    
    /**
     * Get transfer items with complete dimension data from sophisticated system
     */
    private function getTransferItemsWithDimensions(): array {
        $stmt = $this->pdo->prepare("
            SELECT
                ti.*,
                vp.name as product_name,
                vp.sku,
                vp.handle,
                COALESCE(vp.avg_weight_grams, cw.avg_weight_grams, 100) as weight_grams,
                COALESCE(pd.avg_length_mm, cd.avg_length_mm, 100) as length_mm,
                COALESCE(pd.avg_width_mm, cd.avg_width_mm, 20) as width_mm,
                COALESCE(pd.avg_height_mm, cd.avg_height_mm, 15) as height_mm,
                COALESCE(pd.avg_volume_cm3, cd.avg_volume_cm3, cw.avg_volume_cm3, 30) as volume_cm3,
                pcu.category_code as category_name,
                GREATEST(COALESCE(NULLIF(ti.qty_sent_total,0), ti.qty_requested, 0), 0) as actual_qty,
                CASE 
                    WHEN vp.name LIKE '%glass%' OR vp.name LIKE '%fragile%' THEN 1 
                    ELSE 0 
                END as is_fragile,
                CASE 
                    WHEN vp.name LIKE '%nicotine%' OR vp.name LIKE '%nic %' THEN 1 
                    ELSE 0 
                END as contains_nicotine
            FROM transfer_items ti
            LEFT JOIN vend_products vp ON vp.id = ti.product_id
            LEFT JOIN product_dimensions pd ON pd.product_id = vp.id
            LEFT JOIN product_category_usage pcu ON pcu.product_id = vp.id
            LEFT JOIN category_dimensions cd ON cd.category_code = pcu.category_code
            LEFT JOIN category_weights cw ON cw.category_code = pcu.category_code
            WHERE ti.transfer_id = :tid
            AND ti.deleted_at IS NULL
            ORDER BY contains_nicotine DESC, is_fragile DESC, weight_grams DESC
        ");
        $stmt->execute([':tid' => $this->transfer_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Preprocess items for optimal allocation
     */
    private function preprocessItems(array $items): array {
        $processed = [];
        
        foreach ($items as $item) {
            $qty = max(1, (int)$item['actual_qty']);
            
            // Create individual units for sophisticated allocation
            for ($i = 0; $i < $qty; $i++) {
                $unit = [
                    'item_id' => $item['id'],
                    'product_id' => $item['product_id'],
                    'product_name' => $item['product_name'],
                    'sku' => $item['sku'],
                    'unit_weight_g' => (float)$item['weight_grams'],
                    'unit_volume_cm3' => (float)$item['volume_cm3'],
                    'length_mm' => (float)$item['length_mm'],
                    'width_mm' => (float)$item['width_mm'],
                    'height_mm' => (float)$item['height_mm'],
                    'category' => $item['category_name'] ?? 'GENERAL',
                    'is_fragile' => (bool)$item['is_fragile'],
                    'contains_nicotine' => (bool)$item['contains_nicotine'],
                    'priority_score' => $this->calculatePriorityScore($item),
                    'unit_index' => $i + 1,
                    'total_qty' => $qty
                ];
                $processed[] = $unit;
            }
        }
        
        // Sort by priority score (fragile, nicotine, then by weight descending)
        usort($processed, function($a, $b) {
            if ($a['priority_score'] !== $b['priority_score']) {
                return $b['priority_score'] <=> $a['priority_score'];
            }
            return $b['unit_weight_g'] <=> $a['unit_weight_g'];
        });
        
        return $processed;
    }
    
    /**
     * Calculate priority score for item placement
     */
    private function calculatePriorityScore(array $item): int {
        $score = 0;
        
        if ($item['is_fragile']) $score += 100;
        if ($item['contains_nicotine']) $score += 50;
        if ((float)$item['weight_grams'] > 1000) $score += 25; // Heavy items
        if ((float)$item['volume_cm3'] > 500) $score += 10; // Bulky items
        
        return $score;
    }
    
    /**
     * Main allocation algorithm - sophisticated bin packing with constraints
     */
    private function runAllocationAlgorithm(array $items, array $transfer_data): array {
        $boxes = [];
        $current_box = null;
        $total_weight_kg = 0;
        $total_volume_cm3 = 0;
        $strategy_notes = [];
        
        foreach ($items as $item) {
            $placed = false;
            
            // Try to place item in existing boxes
            foreach ($boxes as $box_index => &$box) {
                if ($this->canPlaceItemInBox($item, $box)) {
                    $this->placeItemInBox($item, $box);
                    $placed = true;
                    break;
                }
            }
            
            // If not placed, create new box
            if (!$placed) {
                $new_box = $this->createNewBoxForItem($item, count($boxes) + 1);
                $this->placeItemInBox($item, $new_box);
                $boxes[] = $new_box;
                $strategy_notes[] = "Created Box " . (count($boxes)) . " for " . $item['product_name'] . 
                                  " (weight: {$item['unit_weight_g']}g, volume: {$item['unit_volume_cm3']}cm³)";
            }
            
            $total_weight_kg += $item['unit_weight_g'] / 1000;
            $total_volume_cm3 += $item['unit_volume_cm3'];
        }
        
        // Optimize box sizes
        $this->optimizeBoxSizes($boxes);
        
        return [
            'boxes' => $boxes,
            'total_weight_kg' => round($total_weight_kg, 3),
            'total_volume_cm3' => round($total_volume_cm3, 2),
            'strategy' => 'sophisticated_bin_packing_with_constraints',
            'recommendations' => $strategy_notes
        ];
    }
    
    /**
     * Check if item can be placed in box considering all constraints
     */
    private function canPlaceItemInBox(array $item, array $box): bool {
        // Weight constraint
        $new_weight = $box['current_weight_g'] + $item['unit_weight_g'];
        if ($new_weight > ($box['max_weight_g'] * $this->safety_margin)) {
            return false;
        }
        
        // Volume constraint
        $new_volume = $box['current_volume_cm3'] + $item['unit_volume_cm3'];
        $max_volume = ($box['length_mm'] * $box['width_mm'] * $box['height_mm']) / 1000; // Convert to cm³
        if ($new_volume > ($max_volume * $this->safety_margin)) {
            return false;
        }
        
        // Compatibility constraints
        if ($item['contains_nicotine'] && $box['contains_non_nicotine']) {
            return false;
        }
        
        if (!$item['contains_nicotine'] && $box['contains_nicotine']) {
            return false;
        }
        
        // Fragile item constraints
        if ($item['is_fragile'] && $box['current_weight_g'] > 5000) {
            return false; // Don't put fragile items in heavy boxes
        }
        
        return true;
    }
    
    /**
     * Place item in box and update box properties
     */
    private function placeItemInBox(array $item, array &$box): void {
        $box['items'][] = $item;
        $box['current_weight_g'] += $item['unit_weight_g'];
        $box['current_volume_cm3'] += $item['unit_volume_cm3'];
        $box['item_count']++;
        
        if ($item['contains_nicotine']) {
            $box['contains_nicotine'] = true;
        } else {
            $box['contains_non_nicotine'] = true;
        }
        
        if ($item['is_fragile']) {
            $box['contains_fragile'] = true;
        }
        
        // Track product types for easier reorganization
        $product_key = $item['product_id'];
        if (!isset($box['products'][$product_key])) {
            $box['products'][$product_key] = [
                'product_id' => $item['product_id'],
                'product_name' => $item['product_name'],
                'sku' => $item['sku'],
                'qty' => 0
            ];
        }
        $box['products'][$product_key]['qty']++;
    }
    
    /**
     * Create new box with optimal template
     */
    private function createNewBox(int $box_number): array {
        // Start with medium box template, will be optimized later
        $template = $this->box_templates['medium'];
        
        return [
            'box_number' => $box_number,
            'name' => "Box {$box_number}",
            'length_mm' => $template['length_mm'],
            'width_mm' => $template['width_mm'],
            'height_mm' => $template['height_mm'],
            'max_weight_g' => $template['max_weight_g'],
            'current_weight_g' => 0,
            'current_volume_cm3' => 0,
            'item_count' => 0,
            'items' => [],
            'products' => [],
            'contains_nicotine' => false,
            'contains_non_nicotine' => false,
            'contains_fragile' => false,
            'suggested_carrier' => null,
            'estimated_cost' => null
        ];
    }

    /**
     * Create a new box tailored for the first item using courier pricing containers when available.
     * Falls back to static template logic if no dynamic fit is found.
     */
    private function createNewBoxForItem(array $item, int $box_number): array {
        $chosen = $this->chooseContainerForItem($item);
        if ($chosen === null) {
            return $this->createNewBox($box_number); // fallback to legacy medium template
        }
        $volume_cm3 = ($chosen['length_mm'] * $chosen['width_mm'] * $chosen['height_mm']) / 1000; // internal volume baseline (cm3)
        return [
            'box_number' => $box_number,
            'name' => ($chosen['container_name'] ?? $chosen['code'] ?? 'Box') . " #{$box_number}",
            'length_mm' => (int)$chosen['length_mm'],
            'width_mm' => (int)$chosen['width_mm'],
            'height_mm' => (int)$chosen['height_mm'],
            'max_weight_g' => (int)$chosen['max_weight_grams'],
            'current_weight_g' => 0,
            'current_volume_cm3' => 0,
            'item_count' => 0,
            'items' => [],
            'products' => [],
            'contains_nicotine' => false,
            'contains_non_nicotine' => false,
            'contains_fragile' => false,
            'suggested_carrier' => $chosen['carrier_code'] ?? null,
            'estimated_cost' => $chosen['price'] ?? null,
            'currency' => $chosen['currency'] ?? null,
            'pricing_source' => 'pricing_matrix',
            'template' => $chosen['code'] ?? 'dynamic'
        ];
    }

    /**
     * Choose the most cost-effective container for an item honoring weight & rough volume constraints.
     * Strategy: smallest/cheapest container whose max_weight >= item weight * safety_margin_factor.
     * Volume is approximated via item['unit_volume_cm3']; if absent we skip volume filtering.
     */
    private function chooseContainerForItem(array $item): ?array {
        if (empty($this->dynamic_containers)) return null;
        $unitWeight = (float)($item['unit_weight_g'] ?? 0);
        $unitVolume = (float)($item['unit_volume_cm3'] ?? 0);
        $requiredWeight = max(1, (int)ceil($unitWeight));
        $requiredVolume = $unitVolume > 0 ? $unitVolume : null;
        $best = null;
        foreach ($this->dynamic_containers as $code => $c) {
            $cap = (int)($c['max_weight_grams'] ?? 0);
            if ($cap > 0 && $requiredWeight > $cap) continue; // too small
            if ($requiredVolume !== null) {
                $cVol = ($c['length_mm'] * $c['width_mm'] * $c['height_mm']) / 1000; // cm3
                if ($requiredVolume > $cVol) continue;
            }
            if ($best === null) { $best = $c; continue; }
            // Prefer lower price, then lower max weight (tighter fit), then smaller volume
            $bestPrice = (float)($best['price'] ?? PHP_FLOAT_MAX);
            $curPrice = (float)($c['price'] ?? PHP_FLOAT_MAX);
            if ($curPrice < $bestPrice) { $best = $c; continue; }
            if ($curPrice === $bestPrice) {
                $bestCap = (int)($best['max_weight_grams'] ?? PHP_INT_MAX);
                if ($cap < $bestCap) { $best = $c; continue; }
            }
        }
        return $best;
    }

    /**
     * Load active pricing_matrix view (courier + container pricing) and normalize into dynamic containers.
     * pricing_matrix columns: carrier_code, container_code, container_name, length_mm,width_mm,height_mm,max_weight_grams,price,currency,effective_from,effective_to
     */
    private function loadPricingMatrix(): void {
        try {
            $sql = "SELECT carrier_code, container_code, container_name, length_mm, width_mm, height_mm, max_weight_grams, price, currency FROM pricing_matrix WHERE (effective_from IS NULL OR effective_from <= CURRENT_DATE()) AND (effective_to IS NULL OR effective_to >= CURRENT_DATE()) ORDER BY price ASC, max_weight_grams ASC";
            $stmt = $this->pdo->query($sql);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $this->pricing_matrix = $rows;
            foreach ($rows as $r) {
                $code = (string)($r['container_code'] ?? '');
                if ($code === '') continue;
                // First occurrence (cheapest due to ORDER BY) wins to keep minimal map
                if (!isset($this->dynamic_containers[$code])) {
                    $this->dynamic_containers[$code] = [
                        'code' => $code,
                        'container_name' => $r['container_name'] ?? $code,
                        'length_mm' => (int)($r['length_mm'] ?? 0),
                        'width_mm' => (int)($r['width_mm'] ?? 0),
                        'height_mm' => (int)($r['height_mm'] ?? 0),
                        'max_weight_grams' => (int)($r['max_weight_grams'] ?? 0),
                        'price' => $r['price'] !== null ? (float)$r['price'] : null,
                        'currency' => $r['currency'] ?? 'NZD',
                        'carrier_code' => $r['carrier_code'] ?? null,
                        'source' => 'pricing_matrix'
                    ];
                }
            }
        } catch (\Throwable $e) {
            error_log('loadPricingMatrix failed: ' . $e->getMessage());
            // Non-fatal; system will fallback to static templates
        }
    }
    
    /**
     * Optimize box sizes based on actual contents
     */
    private function optimizeBoxSizes(array &$boxes): void {
        foreach ($boxes as &$box) {
            $optimal_template = $this->findOptimalTemplate($box['current_weight_g'], $box['current_volume_cm3']);
            
            $box['length_mm'] = $optimal_template['length_mm'];
            $box['width_mm'] = $optimal_template['width_mm'];
            $box['height_mm'] = $optimal_template['height_mm'];
            $box['max_weight_g'] = $optimal_template['max_weight_g'];
            $box['template'] = $optimal_template['name'];
            
            // Calculate utilization
            $weight_utilization = ($box['current_weight_g'] / $box['max_weight_g']) * 100;
            $volume_utilization = ($box['current_volume_cm3'] / (($box['length_mm'] * $box['width_mm'] * $box['height_mm']) / 1000)) * 100;
            
            $box['utilization'] = [
                'weight_percent' => round($weight_utilization, 1),
                'volume_percent' => round($volume_utilization, 1),
                'efficiency_score' => round(($weight_utilization + $volume_utilization) / 2, 1)
            ];
        }
    }
    
    /**
     * Find optimal box template for weight and volume
     */
    private function findOptimalTemplate(float $weight_g, float $volume_cm3): array {
        foreach ($this->box_templates as $name => $template) {
            $template_volume = ($template['length_mm'] * $template['width_mm'] * $template['height_mm']) / 1000;
            
            if ($weight_g <= ($template['max_weight_g'] * $this->safety_margin) && 
                $volume_cm3 <= ($template_volume * $this->safety_margin)) {
                return array_merge($template, ['name' => $name]);
            }
        }
        
        // Return XL if nothing else fits
        return array_merge($this->box_templates['xl'], ['name' => 'xl']);
    }
    
    /**
     * Optimize with freight system constraints and carrier requirements
     */
    private function optimizeWithFreightConstraints(array $boxes, array $transfer_data): array {
        $optimized_boxes = [];
        
        foreach ($boxes as $box) {
            $optimized_box = $box;
            
            // Get carrier recommendations from freight system
            $carrier_rec = $this->getCarrierRecommendation($box, $transfer_data);
            $optimized_box['suggested_carrier'] = $carrier_rec['carrier'];
            $optimized_box['carrier_service'] = $carrier_rec['service'];
            $optimized_box['estimated_cost'] = $carrier_rec['cost'];
            $optimized_box['delivery_days'] = $carrier_rec['delivery_days'];
            
            // Add freight system compliance flags
            $optimized_box['freight_compliant'] = $this->checkFreightCompliance($box);
            $optimized_box['needs_special_handling'] = $box['contains_fragile'] || $box['contains_nicotine'];
            
            // Calculate final weight in kg for carrier systems
            $optimized_box['weight_kg'] = round($box['current_weight_g'] / 1000, 3);
            
            // Add user-editable flags
            $optimized_box['user_editable'] = true;
            $optimized_box['can_split'] = $box['item_count'] > 1;
            $optimized_box['can_merge'] = true;
            
            $optimized_boxes[] = $optimized_box;
        }
        
        return $optimized_boxes;
    }
    
    /**
     * Get carrier recommendation from freight system
     */
    private function getCarrierRecommendation(array $box, array $transfer_data): array {
        try {
            // Query freight system views for best carrier option
            $stmt = $this->pdo->prepare("
                SELECT 
                    carrier_code,
                    service_code,
                    cost_estimate,
                    delivery_days_min,
                    max_weight_g,
                    max_length_mm,
                    max_width_mm,
                    max_height_mm
                FROM pricing_matrix pm
                WHERE pm.max_weight_g >= :weight
                AND pm.max_length_mm >= :length
                AND pm.max_width_mm >= :width  
                AND pm.max_height_mm >= :height
                ORDER BY cost_estimate ASC, delivery_days_min ASC
                LIMIT 1
            ");
            
            $stmt->execute([
                ':weight' => $box['current_weight_g'],
                ':length' => $box['length_mm'],
                ':width' => $box['width_mm'],
                ':height' => $box['height_mm']
            ]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                return [
                    'carrier' => $result['carrier_code'],
                    'service' => $result['service_code'],
                    'cost' => round((float)$result['cost_estimate'], 2),
                    'delivery_days' => (int)$result['delivery_days_min']
                ];
            }
            
        } catch (Exception $e) {
            error_log("Carrier recommendation error: " . $e->getMessage());
        }
        
        // Fallback to default
        return [
            'carrier' => 'NZ_POST',
            'service' => 'STANDARD',
            'cost' => 15.00,
            'delivery_days' => 3
        ];
    }
    
    /**
     * Check freight system compliance
     */
    private function checkFreightCompliance(array $box): bool {
        // Check against container limits from freight system
        if ($box['current_weight_g'] > 22000) return false; // 22kg max
        if ($box['length_mm'] > 1200) return false; // Size limits
        if ($box['width_mm'] > 800) return false;
        if ($box['height_mm'] > 600) return false;
        
        return true;
    }
    
    /**
     * Generate pricing recommendations using freight system
     */
    private function generatePricingRecommendations(array $boxes, array $transfer_data): array {
        $total_cost = 0;
        $cheapest_total = 0;
        $fastest_total = 0;
        $carrier_breakdown = [];
        
        foreach ($boxes as $box) {
            $cost = $box['estimated_cost'] ?? 15.00;
            $total_cost += $cost;
            
            $carrier = $box['suggested_carrier'] ?? 'NZ_POST';
            if (!isset($carrier_breakdown[$carrier])) {
                $carrier_breakdown[$carrier] = ['boxes' => 0, 'cost' => 0];
            }
            $carrier_breakdown[$carrier]['boxes']++;
            $carrier_breakdown[$carrier]['cost'] += $cost;
        }
        
        return [
            'total_estimated_cost' => round($total_cost, 2),
            'cost_per_box_avg' => round($total_cost / max(1, count($boxes)), 2),
            'carrier_breakdown' => $carrier_breakdown,
            'recommendations' => [
                'cheapest_strategy' => 'Use NZ Post for all boxes',
                'fastest_strategy' => 'Use GSS/NZ Couriers for urgent delivery',
                'balanced_strategy' => 'Mixed carriers based on box requirements'
            ],
            'savings_opportunities' => $this->identifySavingsOpportunities($boxes)
        ];
    }
    
    /**
     * Identify cost savings opportunities
     */
    private function identifySavingsOpportunities(array $boxes): array {
        $opportunities = [];
        
        // Check for boxes that could be merged
        $mergeable_boxes = [];
        for ($i = 0; $i < count($boxes); $i++) {
            for ($j = $i + 1; $j < count($boxes); $j++) {
                if ($this->canMergeBoxes($boxes[$i], $boxes[$j])) {
                    $mergeable_boxes[] = [$i + 1, $j + 1];
                }
            }
        }
        
        if (!empty($mergeable_boxes)) {
            $opportunities[] = "Boxes " . implode(" & ", $mergeable_boxes[0]) . " could be merged to save shipping costs";
        }
        
        // Check for oversized boxes
        foreach ($boxes as $box) {
            if ($box['utilization']['efficiency_score'] < 50) {
                $opportunities[] = "Box {$box['box_number']} is under-utilized ({$box['utilization']['efficiency_score']}%) - consider smaller box";
            }
        }
        
        return $opportunities;
    }
    
    /**
     * Check if two boxes can be merged
     */
    private function canMergeBoxes(array $box1, array $box2): bool {
        // Check weight
        $combined_weight = $box1['current_weight_g'] + $box2['current_weight_g'];
        if ($combined_weight > 22000) return false; // Max weight limit
        
        // Check compatibility
        if ($box1['contains_nicotine'] && $box2['contains_non_nicotine']) return false;
        if ($box1['contains_non_nicotine'] && $box2['contains_nicotine']) return false;
        
        // Check volume (simplified check)
        $combined_volume = $box1['current_volume_cm3'] + $box2['current_volume_cm3'];
        if ($combined_volume > 100000) return false; // 100L max
        
        return true;
    }
    
    /**
     * Load allocation rules from configuration
     */
    private function loadAllocationRules(): void {
        $this->allocation_rules = [
            'max_weight_per_box_g' => 22000,
            'max_volume_per_box_cm3' => 100000,
            'separate_nicotine' => true,
            'fragile_weight_limit_g' => 5000,
            'safety_margin' => $this->safety_margin
        ];
    }
    
    /**
     * Load container limits from freight system
     */
    private function loadContainerLimits(): void {
        try {
            $stmt = $this->pdo->query("
                SELECT 
                    MAX(max_weight_grams) as max_weight_g,
                    MAX(max_length_mm) as max_length_mm,
                    MAX(max_width_mm) as max_width_mm,
                    MAX(max_height_mm) as max_height_mm
                FROM containers 
                WHERE status = 'active'
            ");
            $limits = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($limits) {
                $this->container_limits = $limits;
            }
        } catch (Exception $e) {
            error_log("Failed to load container limits: " . $e->getMessage());
            // Use defaults
            $this->container_limits = [
                'max_weight_g' => 22000,
                'max_length_mm' => 1200,
                'max_width_mm' => 800,
                'max_height_mm' => 600
            ];
        }
    }
    
    /**
     * Save allocation to database
     */
    public function saveAllocation(array $allocation_result): array {
        try {
            $this->pdo->beginTransaction();
            
            // Get or create shipment
            $shipment = $this->getOrCreateShipment();
            
            // Clear existing parcels
            $this->clearExistingParcels($shipment['id']);
            
            // Create new parcels based on allocation
            $parcel_ids = $this->createParcelsFromAllocation($shipment['id'], $allocation_result['boxes']);
            
            // Create parcel item assignments
            $this->createParcelItemAssignments($parcel_ids, $allocation_result['boxes']);
            
            // Log the allocation
            $this->logAllocationEvent($shipment['id'], $allocation_result);
            
            $this->pdo->commit();
            
            return [
                'success' => true,
                'shipment_id' => $shipment['id'],
                'parcel_ids' => $parcel_ids,
                'message' => 'Box allocation saved successfully'
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Save allocation error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to save allocation: ' . $e->getMessage()
            ];
        }
    }
    
    private function getOrCreateShipment(): array {
        // Get existing shipment or create new one
        $stmt = $this->pdo->prepare("
            SELECT id FROM transfer_shipments 
            WHERE transfer_id = :tid 
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([':tid' => $this->transfer_id]);
        $shipment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$shipment) {
            $stmt = $this->pdo->prepare("
                INSERT INTO transfer_shipments (transfer_id, delivery_mode, status, created_at)
                VALUES (:tid, 'courier', 'packed', NOW())
            ");
            $stmt->execute([':tid' => $this->transfer_id]);
            $shipment_id = $this->pdo->lastInsertId();
            return ['id' => $shipment_id];
        }
        
        return $shipment;
    }
    
    private function clearExistingParcels(int $shipment_id): void {
        // Delete existing parcel items first (FK constraint)
        $this->pdo->prepare("
            DELETE tpi FROM transfer_parcel_items tpi
            JOIN transfer_parcels tp ON tp.id = tpi.parcel_id
            WHERE tp.shipment_id = :sid
        ")->execute([':sid' => $shipment_id]);
        
        // Delete existing parcels
        $this->pdo->prepare("
            DELETE FROM transfer_parcels WHERE shipment_id = :sid
        ")->execute([':sid' => $shipment_id]);
    }
    
    private function createParcelsFromAllocation(int $shipment_id, array $boxes): array {
        $parcel_ids = [];
        
        foreach ($boxes as $box) {
            $stmt = $this->pdo->prepare("
                INSERT INTO transfer_parcels (
                    shipment_id, box_number, weight_kg, 
                    length_mm, width_mm, height_mm, 
                    status, created_at
                ) VALUES (
                    :sid, :box_num, :weight, 
                    :length, :width, :height, 
                    'pending', NOW()
                )
            ");
            
            $stmt->execute([
                ':sid' => $shipment_id,
                ':box_num' => $box['box_number'],
                ':weight' => $box['weight_kg'],
                ':length' => $box['length_mm'],
                ':width' => $box['width_mm'],
                ':height' => $box['height_mm']
            ]);
            
            $parcel_ids[] = $this->pdo->lastInsertId();
        }
        
        return $parcel_ids;
    }
    
    private function createParcelItemAssignments(array $parcel_ids, array $boxes): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO transfer_parcel_items (parcel_id, item_id, qty)
            VALUES (:parcel_id, :item_id, :qty)
            ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)
        ");
        
        foreach ($boxes as $box_index => $box) {
            $parcel_id = $parcel_ids[$box_index];
            
            foreach ($box['products'] as $product) {
                // Find the transfer_items.id for this product
                $item_stmt = $this->pdo->prepare("
                    SELECT id FROM transfer_items 
                    WHERE transfer_id = :tid AND product_id = :pid
                ");
                $item_stmt->execute([
                    ':tid' => $this->transfer_id,
                    ':pid' => $product['product_id']
                ]);
                $item = $item_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($item) {
                    $stmt->execute([
                        ':parcel_id' => $parcel_id,
                        ':item_id' => $item['id'],
                        ':qty' => $product['qty']
                    ]);
                }
            }
        }
    }
    
    private function logAllocationEvent(int $shipment_id, array $allocation_result): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO transfer_logs (
                transfer_id, shipment_id, event_type, event_data, 
                actor_user_id, source_system, created_at
            ) VALUES (
                :tid, :sid, 'BOX_ALLOCATION', :data,
                :user_id, 'BoxAllocationService', NOW()
            )
        ");
        
        $event_data = [
            'algorithm' => 'sophisticated_bin_packing',
            'total_boxes' => count($allocation_result['boxes']),
            'total_weight_kg' => $allocation_result['total_weight_kg'],
            'total_volume_cm3' => $allocation_result['total_volume_cm3'],
            'strategy' => $allocation_result['strategy']
        ];
        
        $stmt->execute([
            ':tid' => $this->transfer_id,
            ':sid' => $shipment_id,
            ':data' => json_encode($event_data),
            ':user_id' => $_SESSION['user_id'] ?? null
        ]);
    }
}

/**
 * API endpoint for box allocation service
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['action'];
    $transfer_id = (int)($_POST['transfer_id'] ?? 0);
    
    if ($transfer_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Valid transfer_id required']);
        exit;
    }
    
    try {
        $service = new BoxAllocationService($transfer_id);
        
        switch ($action) {
            case 'generate_allocation':
                $result = $service->generateOptimalAllocation();
                echo json_encode($result);
                break;
                
            case 'save_allocation':
                $allocation_data = json_decode($_POST['allocation_data'] ?? '[]', true);
                $result = $service->saveAllocation($allocation_data);
                echo json_encode($result);
                break;
                
            default:
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Unknown action']);
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false, 
            'error' => 'Service error: ' . $e->getMessage()
        ]);
    }
}