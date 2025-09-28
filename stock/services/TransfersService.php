<?php
declare(strict_types=1);

namespace Modules\Transfers\Stock\Services;

use PDO;
use Throwable;

final class TransfersService
{
    private PDO $db;
    private TransferLogger $logger;

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

        $this->logger = new TransferLogger();
    }

    public function getTransfer(int $id): ?array
    {
        $tx = $this->db->prepare(
            'SELECT t.id,
                    t.public_id,
                    t.outlet_from,
                    t.outlet_to,
                    t.status,
                    t.state,
                    t.created_at,
                    vo_from.name AS outlet_from_name,
                    vo_to.name   AS outlet_to_name
               FROM transfers t
          LEFT JOIN vend_outlets vo_from ON vo_from.id = t.outlet_from
          LEFT JOIN vend_outlets vo_to   ON vo_to.id   = t.outlet_to
              WHERE t.id = :id
              LIMIT 1'
        );
        $tx->execute(['id' => $id]);
        $transfer = $tx->fetch(PDO::FETCH_ASSOC);
        if (!$transfer) return null;

        $it = $this->db->prepare(
            'SELECT ti.id,
                    ti.product_id,
                    ti.qty_requested,
                    ti.qty_sent_total,
                    ti.qty_received_total,
                    vp.name         AS product_name,
                    vp.variant_name AS product_variant,
                    vp.sku          AS product_sku,
                    vp.handle       AS product_handle,
                    vp.brand        AS product_brand
               FROM transfer_items ti
          LEFT JOIN vend_products vp ON vp.id = ti.product_id
              WHERE ti.transfer_id = :tid
              ORDER BY ti.id ASC'
        );
        $it->execute(['tid' => $id]);
        $transfer['items'] = $it->fetchAll(PDO::FETCH_ASSOC);

        return $transfer;
    }

    /**
     * Save PACK quantities (absolute values) and mark PACKAGED.
     * Then create a shipment wave with parcels and parcel_items for the DELTA (new - old).
     */
    public function savePack(int $transferId, array $payload, int $userId): array
    {
        if ($transferId <= 0) {
            return ['success' => false, 'error' => 'Missing transfer id'];
        }
        $postedItems = $payload['items'] ?? [];
        if (!is_iterable($postedItems)) {
            $postedItems = is_object($postedItems) ? (array)$postedItems : $postedItems;
        }
        if (!is_iterable($postedItems)) {
            return ['success' => false, 'error' => 'Invalid payload format'];
        }

        $current   = $this->fetchCurrentSentMap($transferId);  // [item_id => qty_sent_total]
        $waveItems = [];
        $packages  = $this->normalizePackages($payload['packages'] ?? []);
        $carrier   = strtoupper((string)($payload['carrier'] ?? 'NZ_POST'));
        $noteText  = isset($payload['notes']) ? trim((string)$payload['notes']) : '';

        try {
            $this->db->beginTransaction();

            $upd = $this->db->prepare(
                'UPDATE transfer_items
                    SET qty_sent_total = :sent, updated_at = NOW()
                  WHERE id = :iid AND transfer_id = :tid'
            );
            foreach ($postedItems as $rowRaw) {
                $row = $this->asArray($rowRaw);
                $iid  = (int)($row['id'] ?? 0);
                $new  = max(0, (int)($row['qty_sent_total'] ?? 0));
                if ($iid <= 0) continue;

                $req = $this->fetchRequested($iid, $transferId);
                if ($req !== null && $new > $req) $new = $req;

                $old   = $current[$iid] ?? 0;
                $delta = $new - $old;
                if ($delta > 0) {
                    $waveItems[] = ['item_id' => $iid, 'qty_sent' => $delta];
                }

                $upd->execute(['sent' => $new, 'iid' => $iid, 'tid' => $transferId]);
            }

            $this->db->prepare(
                "UPDATE transfers
                    SET status='sent', state='PACKAGED', updated_at = NOW()
                  WHERE id = :tid"
            )->execute(['tid' => $transferId]);

            $this->db->prepare(
                "INSERT INTO transfer_audit_log
                   (entity_type, entity_pk, transfer_pk, action, status, actor_type, actor_id, created_at)
                 VALUES ('transfer', :tid, :tid, 'PACK_SAVE', 'success', 'user', :uid, NOW())"
            )->execute(['tid' => $transferId, 'uid' => $userId ?: null]);

            if ($noteText !== '') {
                (new NotesService())->addTransferNote($transferId, $noteText, $userId);
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            return ['success' => false, 'error' => 'Save failed: ' . $e->getMessage()];
        }

        $shipmentResult = null;
        if (!empty($waveItems)) {
            try {
                $shipmentResult = (new ShipmentService())->createShipmentWithParcelsAndItems(
                    transferId:   $transferId,
                    deliveryMode: 'courier',
                    carrierName:  $carrier,
                    itemsSent:    $waveItems,
                    parcels:      $packages ?: [['weight_grams' => null]],
                    userId:       $userId
                );

                if (!empty($payload['trackingNumbers']) && is_array($payload['trackingNumbers'])) {
                    $track = array_values(array_filter(array_map('strval', $payload['trackingNumbers'])));
                    if (!empty($shipmentResult['parcel_ids']) && !empty($track)) {
                        $trackingSvc = new TrackingService();
                        foreach ($shipmentResult['parcel_ids'] as $idx => $pid) {
                            if (!isset($track[$idx])) break;
                            $trackingSvc->setParcelTracking((int)$pid, $track[$idx], $carrier, $transferId);
                        }
                    }
                }

                $this->logger->log('PACKED', [
                    'transfer_id'   => $transferId,
                    'shipment_id'   => $shipmentResult['shipment_id'] ?? null,
                    'actor_user_id' => $userId,
                    'event_data'    => [
                        'items'   => $waveItems,
                        'parcels' => $shipmentResult['parcel_ids'] ?? [],
                        'carrier' => $carrier
                    ]
                ]);
            } catch (Throwable $e) {
                $this->logger->log('EXCEPTION', [
                    'transfer_id' => $transferId,
                    'severity'    => 'error',
                    'event_data'  => ['op' => 'createShipment', 'error' => $e->getMessage()]
                ]);
                return [
                    'success' => true,
                    'warning' => 'Shipment creation failed: ' . $e->getMessage(),
                ];
            }
        } else {
            $this->logger->log('PACKED', [
                'transfer_id'   => $transferId,
                'actor_user_id' => $userId,
                'event_data'    => ['note' => 'No new quantities to ship in this wave']
            ]);
        }

        return [
            'success'     => true,
            'shipment_id' => $shipmentResult['shipment_id'] ?? null,
            'parcel_ids'  => $shipmentResult['parcel_ids']   ?? [],
            'carrier'     => $carrier,
        ];
    }

    // --- helpers -------------------------------------------------------------

    private function fetchCurrentSentMap(int $transferId): array
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

    private function fetchRequested(int $itemId, int $transferId): ?int
    {
        $st = $this->db->prepare(
            'SELECT qty_requested FROM transfer_items WHERE id = :iid AND transfer_id = :tid'
        );
        $st->execute(['iid' => $itemId, 'tid' => $transferId]);
        $v = $st->fetchColumn();
        return ($v === false) ? null : (int)$v;
    }

    private function normalizePackages(mixed $packages): array
    {
        $out = [];
        if (!is_iterable($packages)) {
            $packages = is_object($packages) ? (array)$packages : (array)$packages;
        }

        foreach ($packages as $pRaw) {
            $p = $this->asArray($pRaw);
            $out[] = [
                'weight_grams' => array_key_exists('weight_grams', $p) ? (int)$p['weight_grams'] : null,
                'length_mm'    => array_key_exists('length_mm', $p)    ? (int)$p['length_mm']    : null,
                'width_mm'     => array_key_exists('width_mm', $p)     ? (int)$p['width_mm']     : null,
                'height_mm'    => array_key_exists('height_mm', $p)    ? (int)$p['height_mm']    : null,
                'tracking'     => array_key_exists('tracking', $p)     ? (string)$p['tracking']  : null,
                'qty'          => max(1, (int)($p['qty'] ?? 1)),
            ];
        }
        return $out;
    }

    private function asArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            return (array)$value;
        }
        return [];
    }
}
