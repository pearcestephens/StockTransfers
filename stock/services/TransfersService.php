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
                    vo_from.website_outlet_id AS outlet_from_legacy_id,
                    vo_from.name              AS outlet_from_name,
                    vo_to.website_outlet_id   AS outlet_to_legacy_id,
                    vo_to.name                AS outlet_to_name
               FROM transfers t
          LEFT JOIN vend_outlets vo_from ON vo_from.id = t.outlet_from
          LEFT JOIN vend_outlets vo_to   ON vo_to.id   = t.outlet_to
              WHERE t.id = :id
              LIMIT 1'
        );
        $tx->execute(['id' => $id]);
        $transfer = $tx->fetch(PDO::FETCH_ASSOC);
        if (!$transfer) return null;

        $fromUuid = trim((string)($transfer['outlet_from'] ?? ''));
        $toUuid   = trim((string)($transfer['outlet_to']   ?? ''));

        $transfer['outlet_from_uuid'] = $fromUuid;
        $transfer['outlet_to_uuid']   = $toUuid;

        $fromLegacy = (int)($transfer['outlet_from_legacy_id'] ?? 0);
        $toLegacy   = (int)($transfer['outlet_to_legacy_id']   ?? 0);

        $transfer['outlet_from'] = $fromLegacy;
        $transfer['outlet_to']   = $toLegacy;

        $fromMeta = $fromUuid !== '' ? $this->getOutletMeta($fromUuid) : null;
        if ($fromMeta) {
            $transfer['outlet_from_meta'] = $fromMeta;
            if (empty($transfer['outlet_from_name'])) {
                $transfer['outlet_from_name'] = $fromMeta['name'] ?? '';
            }
            if ($fromLegacy === 0 && !empty($fromMeta['website_outlet_id'])) {
                $transfer['outlet_from'] = $fromMeta['website_outlet_id'];
            }
        }

        $toMeta = $toUuid !== '' ? $this->getOutletMeta($toUuid) : null;
        if ($toMeta) {
            $transfer['outlet_to_meta'] = $toMeta;
            if (empty($transfer['outlet_to_name'])) {
                $transfer['outlet_to_name'] = $toMeta['name'] ?? '';
            }
            if ($toLegacy === 0 && !empty($toMeta['website_outlet_id'])) {
                $transfer['outlet_to'] = $toMeta['website_outlet_id'];
            }
        }

        if (empty($transfer['outlet_from_name']) && $fromUuid !== '') {
            $transfer['outlet_from_name'] = $fromUuid;
        }

        if (empty($transfer['outlet_to_name']) && $toUuid !== '') {
            $transfer['outlet_to_name'] = $toUuid;
        }

        unset($transfer['outlet_from_legacy_id'], $transfer['outlet_to_legacy_id']);

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
              ORDER BY vp.name ASC, vp.variant_name ASC, ti.id ASC'
        );
        $it->execute(['tid' => $id]);
        $transfer['items'] = $it->fetchAll(PDO::FETCH_ASSOC);

        return $transfer;
    }

    /**
     * Return quantity on hand for product IDs at a specific outlet.
     * @param array<int|string> $productIds
     * @param string $outletId UUID of the outlet
     * @return array<string,int> product_id => qty
     */
    public function getSourceStockLevels(array $productIds, string $outletId): array
    {
        $ids = array_values(array_unique(array_map(static fn($id) => (string)$id, $productIds)));
        if (!$ids || empty($outletId) || strlen($outletId) < 5) {
            return [];
        }

        static $inventoryMeta = null;
        if ($inventoryMeta === null) {
            $inventoryMeta = ['ready' => false, 'column' => null];
            try {
                $cols = $this->db->query('SHOW COLUMNS FROM vend_inventory')->fetchAll(PDO::FETCH_ASSOC);
                $colNames = [];
                foreach ($cols as $colRow) {
                    $colNames[strtolower((string)($colRow['Field'] ?? ''))] = (string)($colRow['Field'] ?? '');
                }
                foreach (['inventory_level','quantity','qty','stock_qty','on_hand','onhand','level'] as $candidate) {
                    if (isset($colNames[$candidate])) {
                        $inventoryMeta['column'] = $colNames[$candidate];
                        $inventoryMeta['ready'] = true;
                        break;
                    }
                }
            } catch (Throwable $e) {
                $inventoryMeta['ready'] = false;
            }
        }

        if (!$inventoryMeta['ready'] || empty($inventoryMeta['column'])) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = sprintf(
            'SELECT product_id, SUM(`%s`) AS qty
               FROM vend_inventory
              WHERE outlet_id = ? AND product_id IN (%s)
              GROUP BY product_id',
            $inventoryMeta['column'],
            $placeholders
        );

        $stmt = $this->db->prepare($sql);
        $bindIndex = 1;
        $stmt->bindValue($bindIndex++, $outletId, PDO::PARAM_STR);
        foreach ($ids as $pid) {
            $stmt->bindValue($bindIndex++, $pid, PDO::PARAM_STR);
        }

        try {
            $stmt->execute();
        } catch (Throwable $e) {
            error_log('TransfersService::getSourceStockLevels error: ' . $e->getMessage());
            return [];
        }

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pid = (string)($row['product_id'] ?? '');
            $qty = isset($row['qty']) ? (float)$row['qty'] : 0.0;
            if ($pid !== '') {
                $map[$pid] = (int)round($qty);
            }
        }
        return $map;
    }

    public function getOutletMeta(int|string $outletIdentifier): ?array
    {
        $uuid = null;
        $legacyId = null;

        if (is_int($outletIdentifier)) {
            if ($outletIdentifier <= 0) {
                return null;
            }
            $legacyId = $outletIdentifier;
        } else {
            $candidate = trim((string)$outletIdentifier);
            if ($candidate === '') {
                return null;
            }
            // Check if it's a UUID format (contains hyphens) or just digits
            if (strpos($candidate, '-') !== false) {
                $uuid = $candidate;
            } elseif (ctype_digit($candidate)) {
                $legacyId = (int)$candidate;
            } else {
                $uuid = $candidate;
            }
        }

        $conditions = [];
        $params     = [];
        if ($uuid !== null) {
            $conditions[] = 'id = :uuid';
            $params['uuid'] = $uuid;
        }
        if ($legacyId !== null && $legacyId > 0) {
            $conditions[] = 'website_outlet_id = :legacy';
            $params['legacy'] = $legacyId;
        }

        if (!$conditions) {
            return null;
        }

        $sql = sprintf(
            'SELECT id,
                    website_outlet_id,
                    name,
                    store_code,
                    physical_address_1       AS address1,
                    physical_address_2       AS address2,
                    physical_suburb          AS suburb,
                    physical_city            AS city,
                    physical_state           AS region,
                    physical_postcode        AS postcode,
                    physical_country_id      AS country_code,
                    physical_phone_number    AS phone,
                    email,
                    nz_post_api_key,
                    nz_post_subscription_key,
                    gss_token,
                    0 AS printers_online,
                    0 AS printers_total
               FROM vend_outlets
              WHERE %s
              LIMIT 1',
            implode(' OR ', $conditions)
        );

        $st = $this->db->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $row['id'] = (string)($row['id'] ?? '');
        $row['website_outlet_id'] = isset($row['website_outlet_id']) ? (string)$row['website_outlet_id'] : '';
        $row['company'] = 'The Vape Shed';

        $countryCode = strtoupper(trim((string)($row['country_code'] ?? '')));
        unset($row['country_code']);
        $row['country'] = match ($countryCode) {
            'NZ', 'NZL', '' => 'New Zealand',
            default          => $countryCode,
        };

        foreach (['address1', 'address2', 'suburb', 'city', 'region', 'postcode', 'phone', 'email', 'nz_post_api_key', 'nz_post_subscription_key', 'gss_token'] as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = trim((string)$row[$field]);
            }
        }

        $row['printers_online'] = (int)($row['printers_online'] ?? 0);
        $row['printers_total']  = (int)($row['printers_total'] ?? 0);

    $row['nz_post_enabled'] = ($row['nz_post_api_key'] !== '' || $row['nz_post_subscription_key'] !== '');
        $row['nzc_enabled']     = ($row['gss_token'] !== '');

        return $row;
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
