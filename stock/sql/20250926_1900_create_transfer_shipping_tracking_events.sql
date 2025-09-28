-- Migration: transfer_shipping_tracking_events
CREATE TABLE IF NOT EXISTS transfer_shipping_tracking_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  label_id BIGINT UNSIGNED NULL,
  tracking_number VARCHAR(80) NOT NULL,
  event_ts DATETIME NOT NULL,
  status_code VARCHAR(64) NULL,
  description VARCHAR(255) NULL,
  location VARCHAR(120) NULL,
  raw_event JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_track_event (tracking_number, event_ts, status_code),
  KEY idx_tracking_number (tracking_number),
  KEY idx_label_id (label_id),
  CONSTRAINT fk_tracking_label FOREIGN KEY (label_id) REFERENCES transfer_shipping_labels(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
