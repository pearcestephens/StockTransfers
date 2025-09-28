-- Create table for per-transfer exclusive packing locks and request queue
-- Safe to run multiple times (IF NOT EXISTS)
CREATE TABLE IF NOT EXISTS transfer_pack_locks (
  transfer_id    INT UNSIGNED NOT NULL PRIMARY KEY,
  user_id        INT UNSIGNED NOT NULL,
  acquired_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at     DATETIME NOT NULL,
  heartbeat_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  client_fingerprint VARCHAR(64) DEFAULT NULL,
  INDEX (expires_at),
  INDEX (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transfer_pack_lock_requests (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  transfer_id   INT UNSIGNED NOT NULL,
  user_id       INT UNSIGNED NOT NULL,
  requested_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status        ENUM('pending','accepted','declined','expired','cancelled') NOT NULL DEFAULT 'pending',
  responded_at  DATETIME NULL,
  expires_at    DATETIME NULL,
  client_fingerprint VARCHAR(64) DEFAULT NULL,
  INDEX (transfer_id, status),
  INDEX (expires_at),
  INDEX (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
