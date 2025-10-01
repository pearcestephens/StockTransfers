CREATE TABLE IF NOT EXISTS transfer_labels (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  transfer_id INT UNSIGNED    NOT NULL,
  carrier_code VARCHAR(32)    NOT NULL,
  service_code VARCHAR(64)    NOT NULL,
  total_inc    DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
  tracking     VARCHAR(64)    NOT NULL,
  label_url    VARCHAR(255)   NOT NULL,
  spooled      TINYINT(1)     NOT NULL DEFAULT 0,
  idem_key     VARCHAR(80)    NOT NULL,
  idem_hash    CHAR(64)       NOT NULL,
  created_at   DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_idem (idem_key),
  UNIQUE KEY uniq_xfer_track (transfer_id, tracking),
  KEY idx_transfer (transfer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;