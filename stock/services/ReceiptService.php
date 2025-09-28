<?php
declare(strict_types=1);

namespace Modules\Transfers\Stock\Services;

use Core\DB;
use PDO;
use Throwable;

final class ReceiptService
{
    private PDO $db;
    private TransferLogger $logger;

    public function __construct()
    {
        $this->db = DB::instance();
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->logger = new TransferLogger();
    }

    /** Aggregated receive (back-compat): payload { items:[{ id, qty_received_total }] } */
    public function saveReceive(int $transferId, array $payload, int $userId): array
    {
        if ($transferId <= 0) return ['success'=>false,'error'=>'Missing transfer id'];
        $items = $payload['items'] ?? [];
        if (!is_array($items)) return ['success'=>false,'error'=>'Invalid payload format'];

        // Load current sent totals per item for bounds
        $sentMap = $this->fetchSentMap($transferId);

        try {
            $this->db->beginTransaction();

            $upd = $this->db->prepare(
                'UPDATE transfer_items
                    SET qty_received_total = :recv, updated_at = NOW()
                  WHERE id = :iid AND transfer_id = :tid'
            );

            foreach ($items as $row) {
                $iid  = (int)($row['id'] ?? 0);
                $recv = max(0, (int)($row['qty_received_total'] ?? 0));
                if ($iid <= 0) continue;

                $sent = $sentMap[$iid] ?? 0;
                if ($recv > $sent) $recv = $sent;

                $upd->execute(['recv' => $recv, 'iid' => $iid, 'tid' => $transferId]);
            }

            // Aggregate to decide state/status
            [$sumSent, $sumRecv] = $this->fetchSentRecvSums($transferId);
            $status = 'partial'; $state = 'RECEIVING';
            if ($sumSent > 0 && $sumRecv >= $sumSent) { $status = 'received'; $state = 'RECEIVED'; }

            $this->db->prepare(
                "UPDATE transfers SET status=:st, state=:state, updated_at = NOW() WHERE id = :tid"
            )->execute(['st' => $status, 'state' => $state, 'tid' => $transferId]);

            // Legacy audit row
            $this->db->prepare(
                "INSERT INTO transfer_audit_log
                   (entity_type, entity_pk, transfer_pk, action, status, actor_type, actor_id, created_at)
                 VALUES ('transfer', :tid, :tid, 'RECEIVE_SAVE', :st, 'user', :uid, NOW())"
            )->execute(['tid'=>$transferId, 'st'=>$status, 'uid'=>$userId ?: null]);

            $this->db->commit();

            // Log (immutable)
            $this->logger->log('RECEIVED', [
                'transfer_id'   => $transferId,
                'actor_user_id' => $userId,
                'event_data'    => ['mode'=>'aggregate','status'=>$status,'state'=>$state]
            ]);

            return ['success'=>true, 'status'=>$status, 'state'=>$state];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            $this->logger->log('EXCEPTION', [
                'transfer_id'=>$transferId,
                'severity'=>'error',
                'event_data'=>['op'=>'saveReceive','error'=>$e->getMessage()]
            ]);
            return ['success'=>false,'error'=>'Save failed: '.$e->getMessage()];
        }
    }

    /** Start a receipt session (header). */
    public function beginReceipt(int $transferId, int $userId): int
    {
        $this->db->prepare(
            "INSERT INTO transfer_receipts (transfer_id, received_by, created_at)
             VALUES (:tid, :uid, NOW())"
        )->execute(['tid'=>$transferId, 'uid'=>$userId]);
        $rid = (int)$this->db->lastInsertId();

        $this->logger->log('STATUS_CHANGE', [
            'transfer_id'=>$transferId,
            'actor_user_id'=>$userId,
            'event_data'=>['state'=>'RECEIVING']
        ]);

        $this->db->prepare(
            "UPDATE transfers SET state='RECEIVING', updated_at = NOW() WHERE id=:tid"
        )->execute(['tid'=>$transferId]);

        return $rid;
    }

    /**
     * Receive per-parcel per-item (box-by-box).
     * Updates: transfer_parcel_items.qty_received, transfer_shipment_items.qty_received, transfer_items.qty_received_total.
     */
    public function receiveParcelItem(int $parcelId, int $itemId, int $qty, ?string $condition=null, ?string $notes=null): void
    {
        $qty = max(0, $qty);

        $this->db->beginTransaction();
        try {
            // Lock row
            $row = $this->db->prepare(
                "SELECT tpi.qty, tpi.qty_received, tp.shipment_id
                   FROM transfer_parcel_items tpi
                   JOIN transfer_parcels tp ON tp.id = tpi.parcel_id
                  WHERE tpi.parcel_id=:pid AND tpi.item_id=:iid
                  FOR UPDATE"
            );
            $row->execute(['pid'=>$parcelId,'iid'=>$itemId]);
            $r = $row->fetch(PDO::FETCH_ASSOC);
            if (!$r) { $this->db->rollBack(); throw new \RuntimeException('Parcel item not found'); }

            $allowed = max(0, ((int)$r['qty'] - (int)$r['qty_received']));
            $take = min($qty, $allowed);

            // Parcel item
            $this->db->prepare(
                "UPDATE transfer_parcel_items
                    SET qty_received = qty_received + :take
                  WHERE parcel_id=:pid AND item_id=:iid"
            )->execute(['take'=>$take,'pid'=>$parcelId,'iid'=>$itemId]);

            // Shipment item
            $this->db->prepare(
                "UPDATE transfer_shipment_items
                    SET qty_received = qty_received + :take
                  WHERE shipment_id = :sid AND item_id = :iid"
            )->execute(['take'=>$take, 'sid'=>(int)$r['shipment_id'], 'iid'=>$itemId]);

            // Transfer item
            $this->db->prepare(
                "UPDATE transfer_items
                    SET qty_received_total = qty_received_total + :take
                  WHERE id = :iid"
            )->execute(['take'=>$take, 'iid'=>$itemId]);

            // Optional parcel note/condition
            if ($condition || $notes) {
                $line = trim(($condition ? ('['.$condition.'] ') : '').(string)$notes);
                if ($line !== '') {
                    $this->db->prepare(
                        "UPDATE transfer_parcels
                            SET notes = TRIM(CONCAT(COALESCE(notes,''), CASE WHEN notes IS NULL OR notes='' THEN '' ELSE '\n' END, :n)),
                                updated_at = NOW()
                          WHERE id=:pid"
                    )->execute(['n'=>$line, 'pid'=>$parcelId]);
                }
            }

            // Parcel status → received if fully received
            $all = $this->db->prepare(
                "SELECT SUM(GREATEST(0, qty - qty_received)) AS remaining
                   FROM transfer_parcel_items WHERE parcel_id = :pid"
            );
            $all->execute(['pid'=>$parcelId]);
            $remaining = (int)($all->fetchColumn() ?: 0);
            if ($remaining === 0) {
                $this->db->prepare(
                    "UPDATE transfer_parcels SET status='received', received_at = NOW(), updated_at = NOW() WHERE id = :pid"
                )->execute(['pid'=>$parcelId]);
            }

            $this->db->commit();
            $this->logger->log('RECEIVED', [
                'parcel_id'=>$parcelId, 'item_id'=>$itemId,
                'event_data'=>['qty'=>$take, 'condition'=>$condition]
            ]);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            $this->logger->log('EXCEPTION', [
                'parcel_id'=>$parcelId,'item_id'=>$itemId,
                'event_data'=>['op'=>'receiveParcelItem','error'=>$e->getMessage()],
                'severity'=>'error'
            ]);
            throw $e;
        }
    }

    /** Finalize receipt: roll up shipment and transfer states. */
    public function finalizeReceipt(int $transferId, int $receiptId, int $userId): void
    {
        [$sumSent, $sumRecv] = $this->fetchSentRecvSums($transferId);

        $status = 'partial'; $state = 'RECEIVING';
        if ($sumSent > 0 && $sumRecv >= $sumSent) { $status = 'received'; $state = 'RECEIVED'; }

        $this->db->prepare(
            "UPDATE transfers SET status=:status, state=:state, updated_at = NOW() WHERE id=:tid"
        )->execute(['status'=>$status,'state'=>$state,'tid'=>$transferId]);

        $this->db->prepare(
            "UPDATE transfer_receipts SET received_at = NOW(), received_by = :uid WHERE id = :rid"
        )->execute(['uid'=>$userId, 'rid'=>$receiptId]);

        $this->logger->log('STATUS_CHANGE', [
            'transfer_id'=>$transferId, 'actor_user_id'=>$userId,
            'event_data'=>['state'=>$state, 'status'=>$status]
        ]);

        // Legacy audit for BI parity
        $this->db->prepare(
            "INSERT INTO transfer_audit_log
               (entity_type, entity_pk, transfer_pk, action, status, actor_type, actor_id, created_at)
             VALUES ('transfer', :tid, :tid, 'RECEIVE_FINALIZE', :st, 'user', :uid, NOW())"
        )->execute(['tid'=>$transferId, 'st'=>$status, 'uid'=>$userId ?: null]);
    }

    // --- helpers -------------------------------------------------------------

    private function fetchSentMap(int $transferId): array
    {
        $st = $this->db->prepare(
            'SELECT id, qty_sent_total FROM transfer_items WHERE transfer_id = :tid'
        );
        $st->execute(['tid' => $transferId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['id']] = (int)$r['qty_sent_total'];
        }
        return $out;
    }

    /** @return array{0:int,1:int} [sum_sent, sum_received] */
    private function fetchSentRecvSums(int $transferId): array
    {
        $agg = $this->db->prepare(
            'SELECT SUM(qty_sent_total) AS sent, SUM(qty_received_total) AS recv
               FROM transfer_items
              WHERE transfer_id = :tid'
        );
        $agg->execute(['tid' => $transferId]);
        $row = $agg->fetch(PDO::FETCH_ASSOC) ?: ['sent' => 0, 'recv' => 0];
        return [ (int)($row['sent'] ?? 0), (int)($row['recv'] ?? 0) ];
    }
}

/**
 * Optional: keep legacy controllers working if they still refer to ReceiveService.
 * Remove this alias once controllers import ReceiptService directly.
 */
namespace Modules\Transfers\Stock\Services;
if (!class_exists(\Modules\Transfers\Stock\Services\ReceiveService::class, false)) {
    class_alias(\Modules\Transfers\Stock\Services\ReceiptService::class, \Modules\Transfers\Stock\Services\ReceiveService::class);
}
