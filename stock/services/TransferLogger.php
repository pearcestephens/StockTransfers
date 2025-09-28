<?php
declare(strict_types=1);

namespace Modules\Transfers\Stock\Services;

use PDO;

final class TransferLogger
{
    private PDO $db;

    public function __construct()
    {
        // Prefer your default Core\DB::instance(); fallback only if needed.
        if (class_exists('\Core\DB') && method_exists('\Core\DB', 'instance')) {
            $pdo = \Core\DB::instance();
        } elseif (function_exists('cis_pdo')) {
            $pdo = cis_pdo();
        } elseif (class_exists('\DB') && method_exists('\DB', 'instance')) {
            $pdo = \DB::instance();
        } elseif (!empty($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            $pdo = $GLOBALS['pdo'];
        } else {
            throw new \RuntimeException('DB not initialized — include /app.php before using services.');
        }

        if (!$pdo instanceof PDO) {
            throw new \RuntimeException('DB provider did not return a PDO instance.');
        }

        $this->db = $pdo;
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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
