<?php
declare(strict_types=1);

namespace Modules\Transfers\Stock\Services;

use Core\DB;
use PDO;
use Throwable;

final class LabelsService
{
    private PDO $db;
    private FreightCalculator $calc;

    public function __construct()
    {
        $this->db   = DB::instance();
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->calc = new FreightCalculator();
    }

    /**
     * Prepare shipment + parcel rows for a transfer (no external API).
     * - Computes total shipped grams
     * - Picks a container for the chosen carrier
     * - Creates one shipment and N parcels (weight split by cap)
     * - Updates transfers.total_boxes and transfers.total_weight_g
     *
     * @return array{carrier_code:string, container?:array, parcels:int, total_weight_g:int}
     */
    public function prepareParcels(int $transferId, int $userId, string $carrierCode = 'NZ_POST'): array
    {
        // Compute weight
        $items = $this->calc->getWeightedItems($transferId);
        $total = 0;
        foreach ($items as $r) { $total += (int)$r['line_weight_g']; }
        if ($total <= 0) {
            return ['carrier_code' => $carrierCode, 'parcels' => 0, 'total_weight_g' => 0];
        }

        // Resolve carrier → container pick
        $carrierId = $this->calc->getCarrierIdByCode($carrierCode);
        $container = null;
        if ($carrierId !== null) {
            $container = $this->calc->pickContainer($carrierId, $total);
        }

        // Plan parcels
        $cap = $container['max_weight_grams'] ?? null;
        $splits = $this->calc->planParcelsByCap($total, $cap);

        try {
            $this->db->beginTransaction();

            // Create shipment (courier; "packed")
            $sh = $this->db->prepare(
                "INSERT INTO transfer_shipments
                  (transfer_id, delivery_mode, status, packed_at, packed_by, carrier_name, created_at)
                 VALUES
                  (:tid, 'courier', 'packed', NOW(), :uid, :carrier, NOW())"
            );
            $sh->execute(['tid' => $transferId, 'uid' => $userId ?: null, 'carrier' => $carrierCode]);
            $shipmentId = (int)$this->db->lastInsertId();

            // Insert parcels
            $pi = $this->db->prepare(
                "INSERT INTO transfer_parcels
                  (shipment_id, box_number, tracking_number, courier,
                   weight_grams, weight_kg, status, created_at)
                 VALUES
                  (:sid, :no, NULL, :courier, :wg, :wkg, 'pending', NOW())"
            );

            $i = 0;
            foreach ($splits as $wg) {
                $i++;
                $pi->execute([
                    'sid'     => $shipmentId,
                    'no'      => $i,
                    'courier' => $carrierCode,
                    'wg'      => $wg,
                    'wkg'     => round($wg / 1000, 2),
                ]);
            }

            // (Optional) record one carrier order placeholder (unique per transfer+carrier)
            $orderNo = 'TR-'.$transferId;
            $this->db->prepare(
                "INSERT INTO transfer_carrier_orders (transfer_id, carrier, order_id, order_number, payload)
                 VALUES (:tid, :carrier, NULL, :num, JSON_OBJECT('total_weight_g', :wg))
                 ON DUPLICATE KEY UPDATE updated_at = NOW(), payload = VALUES(payload)"
            )->execute(['tid' => $transferId, 'carrier' => $carrierCode, 'num' => $orderNo, 'wg' => $total]);

            // Update transfer aggregates
            $this->db->prepare(
                "UPDATE transfers
                    SET total_boxes = :boxes, total_weight_g = :wg, updated_at = NOW()
                  WHERE id = :tid"
            )->execute(['boxes' => count($splits), 'wg' => $total, 'tid' => $transferId]);

            // Audit
            $this->db->prepare(
                "INSERT INTO transfer_audit_log
                   (entity_type, entity_pk, transfer_pk, action, status, actor_type, actor_id, metadata, created_at)
                 VALUES
                   ('transfer', :tid, :tid, 'FREIGHT_PREP', 'success', 'user', :uid,
                    JSON_OBJECT('carrier', :carrier, 'parcels', :p, 'weight_g', :wg), NOW())"
            )->execute([
                'tid' => $transferId,
                'uid' => $userId ?: null,
                'carrier' => $carrierCode,
                'p' => count($splits),
                'wg' => $total
            ]);

            $this->db->commit();

            return [
                'carrier_code'   => $carrierCode,
                'container'      => $container,
                'parcels'        => count($splits),
                'total_weight_g' => $total,
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            // Log failure as audit for observability
            $this->db->prepare(
                "INSERT INTO transfer_audit_log
                   (entity_type, entity_pk, transfer_pk, action, status, actor_type, actor_id, error_details, created_at)
                 VALUES
                   ('transfer', :tid, :tid, 'FREIGHT_PREP', 'failed', 'system', :uid, JSON_OBJECT('error', :err), NOW())"
            )->execute([
                'tid' => $transferId,
                'uid' => $userId ?: null,
                'err' => $e->getMessage(),
            ]);
            return [
                'carrier_code'   => $carrierCode,
                'parcels'        => 0,
                'total_weight_g' => $total,
                'error'          => 'Freight prep failed: '.$e->getMessage(),
            ];
        }
    }
}
