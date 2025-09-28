<?php
/**
 * Box Allocation Configuration Manager
 * 
 * Manages configuration settings for the box allocation algorithm
 * Allows fine-tuning of allocation rules and business logic
 * 
 * @author CIS Development Team
 * @version 2.0  
 * @created 2025-09-26
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/app.php';

class BoxAllocationConfig {
    
    private $db;
    private $default_config = [
        // Weight and Volume Safety Factors
        'weight_safety_factor' => [
            'value' => 0.9,
            'type' => 'float',
            'min' => 0.5,
            'max' => 1.0,
            'description' => 'Use X% of maximum weight capacity (0.9 = 90%)',
            'category' => 'Safety Factors'
        ],
        'volume_safety_factor' => [
            'value' => 0.85,
            'type' => 'float', 
            'min' => 0.5,
            'max' => 1.0,
            'description' => 'Use X% of maximum volume capacity (0.85 = 85%)',
            'category' => 'Safety Factors'
        ],
        
        // Item Limits
        'max_items_per_box' => [
            'value' => 50,
            'type' => 'integer',
            'min' => 10,
            'max' => 100,
            'description' => 'Maximum individual items that can go in one box',
            'category' => 'Item Limits'
        ],
        'max_product_lines_per_box' => [
            'value' => 25,
            'type' => 'integer',
            'min' => 5,
            'max' => 50,
            'description' => 'Maximum different product types per box',
            'category' => 'Item Limits'
        ],
        
        // Business Rules - Separation
        'prefer_single_category' => [
            'value' => true,
            'type' => 'boolean',
            'description' => 'Try to keep same category items together when possible',
            'category' => 'Business Rules'
        ],
        'fragile_separation' => [
            'value' => true,
            'type' => 'boolean', 
            'description' => 'Separate fragile items from heavy/hard items',
            'category' => 'Business Rules'
        ],
        'liquid_separation' => [
            'value' => true,
            'type' => 'boolean',
            'description' => 'Keep liquid products separate from electronics',
            'category' => 'Business Rules'
        ],
        'electronics_protection' => [
            'value' => true,
            'type' => 'boolean',
            'description' => 'Protect electronic devices from liquids and heavy items',
            'category' => 'Business Rules'
        ],
        
        // High-Value Item Rules
        'high_value_threshold' => [
            'value' => 100.00,
            'type' => 'float',
            'min' => 50.00,
            'max' => 500.00,
            'description' => 'Dollar amount that makes an item "high-value"',
            'category' => 'High-Value Rules'
        ],
        'high_value_max_per_box' => [
            'value' => 5,
            'type' => 'integer',
            'min' => 1,
            'max' => 20,
            'description' => 'Maximum high-value items per box',
            'category' => 'High-Value Rules'
        ],
        'high_value_special_packing' => [
            'value' => true,
            'type' => 'boolean',
            'description' => 'Use special packing requirements for high-value items',
            'category' => 'High-Value Rules'
        ],
        
        // Cost Optimization
        'cost_optimization_enabled' => [
            'value' => true,
            'type' => 'boolean',
            'description' => 'Attempt to minimize total shipping costs',
            'category' => 'Cost Optimization'
        ],
        'consolidation_enabled' => [
            'value' => true,
            'type' => 'boolean',
            'description' => 'Try to consolidate boxes to reduce shipping costs',
            'category' => 'Cost Optimization'
        ],
        'prefer_cheaper_carriers' => [
            'value' => true,
            'type' => 'boolean',
            'description' => 'Prefer less expensive carriers when service levels are similar',
            'category' => 'Cost Optimization'
        ],
        
        // Carrier Preferences
        'preferred_carrier_priority' => [
            'value' => ['NZ_POST', 'GSS', 'COURIER_POST'],
            'type' => 'array',
            'description' => 'Carrier preference order (first = most preferred)',
            'category' => 'Carrier Preferences'
        ],
        'exclude_carriers' => [
            'value' => [],
            'type' => 'array',
            'description' => 'Carriers to never use for auto-allocation',
            'category' => 'Carrier Preferences'  
        ],
        
        // Special Product Handling
        'nicotine_special_handling' => [
            'value' => true,
            'type' => 'boolean',
            'description' => 'Apply special handling rules for nicotine products',
            'category' => 'Special Handling'
        ],
        'glass_protection_mode' => [
            'value' => 'strict',
            'type' => 'select',
            'options' => ['strict', 'moderate', 'minimal'],
            'description' => 'Level of protection for glass/fragile items',
            'category' => 'Special Handling'
        ],
        'battery_separation' => [
            'value' => true,
            'type' => 'boolean',
            'description' => 'Separate battery products from other items',
            'category' => 'Special Handling'
        ],
        
        // Performance Tuning
        'algorithm_timeout_seconds' => [
            'value' => 30,
            'type' => 'integer',
            'min' => 10,
            'max' => 120,
            'description' => 'Maximum time to spend on allocation algorithm',
            'category' => 'Performance'
        ],
        'max_optimization_iterations' => [
            'value' => 5,
            'type' => 'integer',
            'min' => 1,
            'max' => 10,
            'description' => 'Maximum optimization passes to attempt',
            'category' => 'Performance'
        ],
        
        // Debugging and Logging
        'debug_mode' => [
            'value' => false,
            'type' => 'boolean',
            'description' => 'Enable detailed logging for allocation process',
            'category' => 'Debugging'
        ],
        'log_allocation_decisions' => [
            'value' => true,
            'type' => 'boolean',
            'description' => 'Log key allocation decisions for review',
            'category' => 'Debugging'
        ]
    ];
    
    public function __construct() {
        global $db;
        $this->db = $db;
        $this->initializeConfigTable();
    }
    
    /**
     * Get current configuration values
     */
    public function getConfig($key = null) {
        if ($key) {
            return $this->getConfigValue($key);
        }
        
        // Return all configuration
        $query = "SELECT config_key, config_value, value_type FROM box_allocation_config WHERE is_active = 1";
        $result = $this->db->query($query);
        $stored_config = [];
        
        while ($row = $result->fetch_assoc()) {
            $stored_config[$row['config_key']] = $this->castValue($row['config_value'], $row['value_type']);
        }
        
        // Merge with defaults
        $final_config = [];
        foreach ($this->default_config as $key => $default) {
            $final_config[$key] = $stored_config[$key] ?? $default['value'];
        }
        
        return $final_config;
    }
    
    /**
     * Get single configuration value
     */
    public function getConfigValue($key) {
        $query = "SELECT config_value, value_type FROM box_allocation_config WHERE config_key = ? AND is_active = 1";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            return $this->castValue($row['config_value'], $row['value_type']);
        }
        
        // Return default if not found
        return $this->default_config[$key]['value'] ?? null;
    }
    
    /**
     * Update configuration value
     */
    public function setConfig($key, $value, $user_id = null) {
        if (!isset($this->default_config[$key])) {
            throw new Exception("Unknown configuration key: {$key}");
        }
        
        $config_def = $this->default_config[$key];
        
        // Validate value
        $this->validateConfigValue($key, $value, $config_def);
        
        // Store as JSON if array
        $stored_value = is_array($value) ? json_encode($value) : (string)$value;
        
        $query = "
            INSERT INTO box_allocation_config 
            (config_key, config_value, value_type, description, category, updated_by, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                config_value = VALUES(config_value),
                updated_by = VALUES(updated_by),
                updated_at = NOW()
        ";
        
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('sssssi', 
            $key, 
            $stored_value, 
            $config_def['type'],
            $config_def['description'],
            $config_def['category'],
            $user_id
        );
        $stmt->execute();
        
        // Log configuration change
        $this->logConfigChange($key, $value, $user_id);
        
        return true;
    }
    
    /**
     * Get configuration schema for UI
     */
    public function getConfigSchema() {
        $schema = [];
        $current_config = $this->getConfig();
        
        foreach ($this->default_config as $key => $config) {
            $schema[$key] = array_merge($config, [
                'current_value' => $current_config[$key]
            ]);
        }
        
        return $schema;
    }
    
    /**
     * Reset configuration to defaults
     */
    public function resetToDefaults($user_id = null) {
        foreach ($this->default_config as $key => $config) {
            $this->setConfig($key, $config['value'], $user_id);
        }
        
        return true;
    }
    
    /**
     * Get configuration presets for common scenarios
     */
    public function getPresets() {
        return [
            'cost_optimized' => [
                'name' => 'Cost Optimized',
                'description' => 'Minimize shipping costs, pack tightly',
                'config' => [
                    'weight_safety_factor' => 0.95,
                    'volume_safety_factor' => 0.9,
                    'max_items_per_box' => 75,
                    'cost_optimization_enabled' => true,
                    'consolidation_enabled' => true,
                    'prefer_single_category' => false
                ]
            ],
            'safety_first' => [
                'name' => 'Safety First',
                'description' => 'Maximum protection, conservative packing',
                'config' => [
                    'weight_safety_factor' => 0.8,
                    'volume_safety_factor' => 0.75,
                    'max_items_per_box' => 30,
                    'fragile_separation' => true,
                    'liquid_separation' => true,
                    'glass_protection_mode' => 'strict'
                ]
            ],
            'speed_packing' => [
                'name' => 'Speed Packing',
                'description' => 'Fast allocation, fewer restrictions',
                'config' => [
                    'prefer_single_category' => false,
                    'fragile_separation' => false,
                    'liquid_separation' => true,
                    'max_optimization_iterations' => 2,
                    'algorithm_timeout_seconds' => 15
                ]
            ],
            'high_value' => [
                'name' => 'High Value Items',
                'description' => 'Optimized for expensive products',
                'config' => [
                    'high_value_threshold' => 50.00,
                    'high_value_max_per_box' => 3,
                    'high_value_special_packing' => true,
                    'glass_protection_mode' => 'strict',
                    'prefer_cheaper_carriers' => false
                ]
            ]
        ];
    }
    
    /**
     * Apply configuration preset
     */
    public function applyPreset($preset_name, $user_id = null) {
        $presets = $this->getPresets();
        
        if (!isset($presets[$preset_name])) {
            throw new Exception("Unknown preset: {$preset_name}");
        }
        
        $preset = $presets[$preset_name];
        foreach ($preset['config'] as $key => $value) {
            $this->setConfig($key, $value, $user_id);
        }
        
        return true;
    }
    
    /**
     * Validate configuration value
     */
    private function validateConfigValue($key, $value, $config_def) {
        switch ($config_def['type']) {
            case 'float':
                if (!is_numeric($value)) {
                    throw new Exception("Configuration {$key} must be a number");
                }
                if (isset($config_def['min']) && $value < $config_def['min']) {
                    throw new Exception("Configuration {$key} must be at least {$config_def['min']}");
                }
                if (isset($config_def['max']) && $value > $config_def['max']) {
                    throw new Exception("Configuration {$key} must be at most {$config_def['max']}");
                }
                break;
                
            case 'integer':
                if (!is_integer($value) && !is_numeric($value)) {
                    throw new Exception("Configuration {$key} must be an integer");
                }
                $value = (int)$value;
                if (isset($config_def['min']) && $value < $config_def['min']) {
                    throw new Exception("Configuration {$key} must be at least {$config_def['min']}");
                }
                if (isset($config_def['max']) && $value > $config_def['max']) {
                    throw new Exception("Configuration {$key} must be at most {$config_def['max']}");
                }
                break;
                
            case 'boolean':
                if (!is_bool($value) && !in_array($value, [0, 1, '0', '1', 'true', 'false'])) {
                    throw new Exception("Configuration {$key} must be true or false");
                }
                break;
                
            case 'select':
                if (isset($config_def['options']) && !in_array($value, $config_def['options'])) {
                    throw new Exception("Configuration {$key} must be one of: " . implode(', ', $config_def['options']));
                }
                break;
                
            case 'array':
                if (!is_array($value)) {
                    throw new Exception("Configuration {$key} must be an array");
                }
                break;
        }
    }
    
    /**
     * Cast stored value to proper type
     */
    private function castValue($value, $type) {
        switch ($type) {
            case 'boolean':
                return in_array($value, [1, '1', 'true', true], true);
            case 'integer':
                return (int)$value;
            case 'float':
                return (float)$value;
            case 'array':
                return json_decode($value, true) ?: [];
            default:
                return $value;
        }
    }
    
    /**
     * Initialize configuration table if it doesn't exist
     */
    private function initializeConfigTable() {
        $query = "
            CREATE TABLE IF NOT EXISTS `box_allocation_config` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `config_key` varchar(100) NOT NULL,
                `config_value` text NOT NULL,
                `value_type` varchar(20) NOT NULL,
                `description` varchar(255) DEFAULT NULL,
                `category` varchar(50) DEFAULT NULL,
                `is_active` tinyint(1) NOT NULL DEFAULT 1,
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                `updated_by` int(11) DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_config_key` (`config_key`),
                KEY `idx_category` (`category`),
                KEY `idx_active` (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        
        $this->db->query($query);
        
        // Initialize with defaults if table is empty
        $count_query = "SELECT COUNT(*) as count FROM box_allocation_config";
        $result = $this->db->query($count_query);
        $count = $result->fetch_assoc()['count'];
        
        if ($count == 0) {
            $this->initializeDefaultConfig();
        }
    }
    
    /**
     * Initialize default configuration values
     */
    private function initializeDefaultConfig() {
        foreach ($this->default_config as $key => $config) {
            $value = is_array($config['value']) ? json_encode($config['value']) : (string)$config['value'];
            
            $query = "
                INSERT INTO box_allocation_config 
                (config_key, config_value, value_type, description, category, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ";
            
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('sssss', 
                $key, 
                $value,
                $config['type'],
                $config['description'],
                $config['category']
            );
            $stmt->execute();
        }
    }
    
    /**
     * Log configuration changes
     */
    private function logConfigChange($key, $value, $user_id) {
        $log_data = json_encode([
            'config_key' => $key,
            'new_value' => $value,
            'changed_by' => $user_id,
            'timestamp' => date('c')
        ]);
        
        // Log to transfer_logs table
        $query = "
            INSERT INTO transfer_logs 
            (event_type, event_data, actor_user_id, source_system, created_at)
            VALUES ('CONFIG_CHANGE', ?, ?, 'CIS', NOW())
        ";
        
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('si', $log_data, $user_id);
        $stmt->execute();
    }
}