-- Migration: create_transfer_shipping_labels
-- Purpose: Persist shipping label lifecycle events for stock transfers.
-- Idempotent safety: checks for table existence.

CREATE TABLE IF NOT EXISTS transfer_shipping_labels (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transfer_id BIGINT UNSIGNED NOT NULL,
  carrier VARCHAR(32) NOT NULL,
  service VARCHAR(64) NOT NULL,
  label_id VARCHAR(96) DEFAULT NULL,
  tracking_number VARCHAR(96) DEFAULT NULL,
  reservation_id VARCHAR(96) DEFAULT NULL,
  status ENUM('reserved','created','voided') NOT NULL DEFAULT 'reserved',
  mode ENUM('simulate','test','live') NOT NULL DEFAULT 'simulate',
  cost_total DECIMAL(10,2) DEFAULT NULL,
  cost_breakdown JSON DEFAULT NULL,
  raw_request JSON DEFAULT NULL,
  raw_response JSON DEFAULT NULL,
  created_by BIGINT UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_transfer (transfer_id),
  INDEX idx_tracking (tracking_number),
  INDEX idx_label (label_id),
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional events table for tracking history (future)
-- CREATE TABLE IF NOT EXISTS transfer_shipping_events (...);
