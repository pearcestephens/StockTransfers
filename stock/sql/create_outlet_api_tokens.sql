-- Migration: Create outlet API tokens table
-- Date: 2025-09-28
-- Purpose: Store outlet-specific API tokens for freight carriers

CREATE TABLE IF NOT EXISTS outlet_api_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    outlet_id VARCHAR(50) NOT NULL,
    outlet_name VARCHAR(255),
    
    -- NZ Post API tokens
    nzpost_subscription_key VARCHAR(255),
    nzpost_api_key VARCHAR(255),
    nzpost_environment ENUM('sandbox', 'production') DEFAULT 'sandbox',
    
    -- GSS/NZ Couriers tokens  
    gss_token VARCHAR(255),
    gss_environment ENUM('sandbox', 'production') DEFAULT 'sandbox',
    
    -- Status and metadata
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by VARCHAR(100),
    updated_by VARCHAR(100),
    
    -- Indexes
    UNIQUE KEY unique_outlet (outlet_id),
    INDEX idx_active (is_active),
    INDEX idx_outlet_active (outlet_id, is_active),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert example data (replace with real tokens)
INSERT INTO outlet_api_tokens (
    outlet_id, 
    outlet_name, 
    nzpost_subscription_key, 
    nzpost_api_key, 
    gss_token,
    nzpost_environment,
    gss_environment,
    created_by
) VALUES 
(
    'store_001', 
    'Auckland CBD Store',
    'ad1bd29013ea4b56ab2036f4d1dabb0b',
    'c9ab68a9c1f74c688e00880d61fd790a',
    'suppo-6029-93353FCA505E0FC540F3616962041535328E2AB',
    'production',
    'production',
    'system'
),
(
    'store_002', 
    'Wellington Store',
    'different-nzpost-key-for-wellington',
    'different-api-key-for-wellington', 
    'different-gss-token-for-wellington',
    'production',
    'production',
    'system'
),
(
    'head_office', 
    'Head Office Warehouse',
    'head-office-nzpost-subscription',
    'head-office-nzpost-api',
    'head-office-gss-token',
    'production',
    'production',
    'system'
)
ON DUPLICATE KEY UPDATE
    outlet_name = VALUES(outlet_name),
    nzpost_subscription_key = VALUES(nzpost_subscription_key),
    nzpost_api_key = VALUES(nzpost_api_key),
    gss_token = VALUES(gss_token),
    updated_at = CURRENT_TIMESTAMP,
    updated_by = 'system';