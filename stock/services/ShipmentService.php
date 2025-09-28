<?php
declare(strict_types=1);

namespace Modules\Transfers\Stock\Services;

use Core\DB;
use PDO;
use Throwable;

final class ShipmentService
{
    private PDO $db;
    private TransferLogger $logger;

    public function __construct()
    {
        $this->db = DB::instance();
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->logger = new TransferLogger();
    }

    /**
     * Create a shipment wave for a transfer (courier/internal/pickup),
     * attach shipment items, create parcels (with dims/weight optional),
     * and distribute items into parcel_items (simple even split).
     *
     * @param array $itemsSent [ ['item_id'=>int, 'qty_sent'=>int], ... ]  — this wave quantities
     * @param array $parcels   [ ['weight_grams'?int,'length_mm'?int,'width_mm'?int,'height_mm'?int,'tracking'?string,'qty'?int], ... ]
     *                         'qty' expands to multiple identical parcels
     * @return array ['shipment_id'=>int, 'parcel_ids'=>int[]]
     */
    public function createShipmentWithParcelsAndItems(
        int $transferId,
        string $deliveryMode,
        string $carrierName,
        array $itemsSent,
        array $parcels,
        int $userId
    ): array {
        $this->db->beginTransaction();
        try {
            // 1) Create shipment
            $this->db->prepare(
                "INSERT INTO transfer_shipments
                   (transfer_id, delivery_mode, status, packed_at, packed_by, carrier_name, created_at)
                 VALUES (:tid, :mode, 'packed', NOW(), :uid, :carrier, NOW())"
            )->execute(['tid'=>$transferId,'mode'=>$deliveryMode,'uid'=>$userId,'carrier'=>$carrierName]);
            $shipmentId = (int)$this->db->lastInsertId();

            // 2) Insert shipment items (aggregated per item for this wave)
            $insSI = $this->db->prepare(
                "INSERT INTO transfer_shipment_items (shipment_id, item_id, qty_sent, qty_received)
                 VALUES (:sid, :iid, :sent, 0)
                 ON DUPLICATE KEY UPDATE qty_sent = qty_sent + VALUES(qty_sent)"
            );
            foreach ($itemsSent as $row) {
                $iid  = (int)($row['item_id'] ?? 0);
                $sent = max(0,(int)($row['qty_sent'] ?? 0));
                if ($iid <= 0 || $sent <= 0) continue;
                $insSI->execute(['sid'=>$shipmentId,'iid'=>$iid,'sent'=>$sent]);
            }

            // 3) Expand parcels with qty>1
            $expanded = [];
            foreach ($parcels as $p) {
                $n = max(1, (int)($p['qty'] ?? 1));
                for ($i=0;$i<$n;$i++) $expanded[] = $p;
            }
            if (!$expanded) $expanded = [['weight_grams'=>null]]; // at least one

            // 4) Insert parcels (box_number auto 1..N)
            $parcelIds = [];
            $insP = $this->db->prepare(
                "INSERT INTO transfer_parcels
                   (shipment_id, box_number, tracking_number, courier,
                    weight_grams, length_mm, width_mm, height_mm, weight_kg,
                    status, created_at, parcel_number)
                 VALUES (:sid, :no, :trk, :carrier, :wg, :L, :W, :H,
                         CASE WHEN :wg IS NULL THEN NULL ELSE ROUND(:wg/1000,2) END,
                         'pending', NOW(), :no)"
            );
            $no = 0;
            foreach ($expanded as $p) {
                $no++;
                $insP->execute([
                    'sid'=>$shipmentId,
                    'no'=>$no,
                    'trk'=>$p['tracking'] ?? null,
                    'carrier'=>$carrierName,
                    'wg'=>isset($p['weight_grams']) ? (int)$p['weight_grams'] : null,
                    'L'=>isset($p['length_mm']) ? (int)$p['length_mm'] : null,
                    'W'=>isset($p['width_mm'])  ? (int)$p['width_mm']  : null,
                    'H'=>isset($p['height_mm']) ? (int)$p['height_mm'] : null,
                ]);
                $parcelIds[] = (int)$this->db->lastInsertId();
            }

            // 5) Distribute per-item quantities into parcels (even split)
            //    (Upgrade later: weight-aware split, or BoxPlanner allocation)
            $insPI = $this->db->prepare(
                "INSERT INTO transfer_parcel_items (parcel_id, item_id, qty, qty_received, created_at)
                 VALUES (:pid, :iid, :qty, 0, NOW())
                 ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)"
            );
            foreach ($itemsSent as $row) {
                $iid = (int)($row['item_id'] ?? 0);
                $qty = max(0,(int)($row['qty_sent'] ?? 0));
                if ($iid<=0 || $qty<=0) continue;

                $n = count($parcelIds);
                $base = intdiv($qty, $n);
                $rem  = $qty % $n;
                foreach ($parcelIds as $index => $pid) {
                    $alloc = $base + ($index < $rem ? 1 : 0);
                    if ($alloc <= 0) continue;
                    $insPI->execute(['pid'=>$pid, 'iid'=>$iid, 'qty'=>$alloc]);
                }
            }

            // 6) Update transfer aggregates
            $wgTotal = (int)$this->db->query(
                "SELECT COALESCE(SUM(weight_grams),0) FROM transfer_parcels WHERE shipment_id=".$shipmentId
            )->fetchColumn();
            $this->db->prepare(
                "UPDATE transfers
                    SET total_boxes = COALESCE(total_boxes,0) + :boxes,
                        total_weight_g = COALESCE(total_weight_g,0) + :wg,
                        updated_at = NOW()
                  WHERE id = :tid"
            )->execute(['boxes'=>count($parcelIds), 'wg'=>$wgTotal, 'tid'=>$transferId]);

            // 7) Log
            $this->logger->log('PACKED', [
                'transfer_id'=>$transferId,
                'shipment_id'=>$shipmentId,
                'actor_user_id'=>$userId,
                'event_data'=>['parcels'=>count($parcelIds),'carrier'=>$carrierName]
            ]);

            $this->db->commit();
            return ['shipment_id'=>$shipmentId, 'parcel_ids'=>$parcelIds];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            $this->logger->log('EXCEPTION', [
                'transfer_id'=>$transferId,
                'severity'=>'error',
                'event_data'=>['op'=>'createShipment','error'=>$e->getMessage()]
            ]);
            throw $e;
        }
    }
}
