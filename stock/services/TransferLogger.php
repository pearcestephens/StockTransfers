<?php
declare(strict_types=1);

namespace Modules\Transfers\Stock\Services;

use PDO;

final class TransferLogger
{
    private PDO $db;

    public function __construct()
    {
        // Use the CIS PDO connection function
        $pdo = cis_pdo();
        
        if (!$pdo instanceof PDO) {
            throw new \RuntimeException('DB provider did not return a PDO instance.');
        }

        $this->db = $pdo;
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createTablesIfNotExists();
    }
    
    private function createTablesIfNotExists(): void
    {
        try {
            // Create transfer logs table
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS transfer_logs (
                  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                  transfer_id      INT UNSIGNED NULL,
                  shipment_id      INT UNSIGNED NULL,
                  item_id          INT UNSIGNED NULL,
                  parcel_id        INT UNSIGNED NULL,
                  staff_transfer_id INT UNSIGNED NULL,
                  event_type       VARCHAR(100) NOT NULL,
                  event_data       JSON NULL,
                  actor_user_id    INT UNSIGNED NULL,
                  actor_role       VARCHAR(50) NULL,
                  severity         ENUM('debug','info','warning','error','critical') NOT NULL DEFAULT 'info',
                  source_system    VARCHAR(50) NOT NULL DEFAULT 'CIS',
                  trace_id         VARCHAR(100) NULL,
                  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  customer_id      INT UNSIGNED NULL,
                  INDEX (transfer_id, event_type),
                  INDEX (shipment_id),
                  INDEX (actor_user_id, created_at),
                  INDEX (created_at),
                  INDEX (event_type, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (\Throwable $e) {
            // Log but don't fail - tables may already exist
            error_log('TransferLogger table creation warning: ' . $e->getMessage());
        }
    }

    public function log(string $eventType, array $opts = []): void
    {
        $sql = "INSERT INTO transfer_logs
                  (transfer_id, shipment_id, item_id, parcel_id, staff_transfer_id,
                   event_type, event_data, actor_user_id, actor_role, severity,
                   source_system, trace_id, created_at, customer_id)
                VALUES
                  (:transfer_id, :shipment_id, :item_id, :parcel_id, NULL,
                   :event_type, :event_data, :actor_user_id, NULL, :severity,
                   :source_system, :trace_id, NOW(), NULL)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'transfer_id'   => $opts['transfer_id']   ?? null,
            'shipment_id'   => $opts['shipment_id']   ?? null,
            'item_id'       => $opts['item_id']       ?? null,
            'parcel_id'     => $opts['parcel_id']     ?? null,
            'event_type'    => $eventType,
            'event_data'    => isset($opts['event_data']) ? json_encode($opts['event_data'], JSON_UNESCAPED_SLASHES) : null,
            'actor_user_id' => $opts['actor_user_id'] ?? null,
            'severity'      => $opts['severity']      ?? 'info',
            'source_system' => $opts['source_system']  ?? 'CIS',
            'trace_id'      => $opts['trace_id']      ?? null,
        ]);
    }
}
