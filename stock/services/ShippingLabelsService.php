<?php
declare(strict_types=1);

namespace Modules\Transfers\Stock\Services;

use PDO; use Throwable;

/**
 * ShippingLabelsService
 * Persistence layer for transfer_shipping_labels lifecycle records.
 */
final class ShippingLabelsService
{
    private PDO $db;

    public function __construct()
    {
        if (class_exists('\Core\DB') && method_exists('\Core\DB','instance')) {
            $pdo = \Core\DB::instance();
        } elseif (function_exists('cis_pdo')) {
            $pdo = cis_pdo();
        } elseif (!empty($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            $pdo = $GLOBALS['pdo'];
        } else {
            throw new \RuntimeException('DB not initialised. Include app.php');
        }
        if (!$pdo instanceof PDO) throw new \RuntimeException('No PDO instance');
        $this->db = $pdo; $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function recordReservation(int $transferId, string $carrier, string $service, string $reservationId, ?float $costTotal, array $breakdown, string $mode, int $userId, array $rawReq = [], array $rawResp = []): int
    {
        $st = $this->db->prepare('INSERT INTO transfer_shipping_labels
            (transfer_id, carrier, service, reservation_id, status, mode, cost_total, cost_breakdown, raw_request, raw_response, created_by)
            VALUES (:tid,:carrier,:service,:res,:status,:mode,:cost,:breakdown,:rawReq,:rawResp,:uid)');
        $st->execute([
            'tid'=>$transferId,
            'carrier'=>$carrier,
            'service'=>$service,
            'res'=>$reservationId,
            'status'=>'reserved',
            'mode'=>$mode,
            'cost'=>$costTotal,
            'breakdown'=>json_encode($breakdown, JSON_UNESCAPED_SLASHES),
            'rawReq'=>json_encode($rawReq, JSON_UNESCAPED_SLASHES),
            'rawResp'=>json_encode($rawResp, JSON_UNESCAPED_SLASHES),
            'uid'=>$userId ?: null,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function upgradeToLabel(int $id, string $labelId, string $trackingNumber, array $rawResp = []): bool
    {
        $st = $this->db->prepare('UPDATE transfer_shipping_labels
            SET label_id = :lbl, tracking_number = :trk, status = "created", updated_at = NOW(), raw_response = :resp
            WHERE id = :id AND status IN ("reserved")');
        return $st->execute(['lbl'=>$labelId,'trk'=>$trackingNumber,'resp'=>json_encode($rawResp, JSON_UNESCAPED_SLASHES),'id'=>$id]);
    }

    public function voidLabel(int $id): bool
    {
        $st = $this->db->prepare('UPDATE transfer_shipping_labels
            SET status = "voided", updated_at = NOW() WHERE id = :id AND status != "voided"');
        return $st->execute(['id'=>$id]);
    }

    public function findByReservation(string $reservationId): ?array
    {
        $st=$this->db->prepare('SELECT * FROM transfer_shipping_labels WHERE reservation_id = :r LIMIT 1');
        $st->execute(['r'=>$reservationId]);
        $row=$st->fetch(PDO::FETCH_ASSOC); return $row ?: null;
    }

    public function findByLabel(string $labelId): ?array
    {
        $st=$this->db->prepare('SELECT * FROM transfer_shipping_labels WHERE label_id = :l LIMIT 1');
        $st->execute(['l'=>$labelId]);
        $row=$st->fetch(PDO::FETCH_ASSOC); return $row ?: null;
    }

    /**
     * List recent labels for a specific transfer (reservation + created + voided) newest first
     */
    public function listRecentByTransfer(int $transferId, int $limit = 50): array
    {
        $st=$this->db->prepare('SELECT id, transfer_id, carrier, service, reservation_id, label_id, tracking_number, status, mode, cost_total, created_at, updated_at
            FROM transfer_shipping_labels WHERE transfer_id = :tid ORDER BY id DESC LIMIT '.(int)$limit);
        $st->execute(['tid'=>$transferId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * List recent labels across all transfers (for control tower dashboard)
     */
    public function listRecent(int $limit = 50): array
    {
        $st=$this->db->query('SELECT id, transfer_id, carrier, service, reservation_id, label_id, tracking_number, status, mode, cost_total, created_at, updated_at
            FROM transfer_shipping_labels ORDER BY id DESC LIMIT '.(int)$limit);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Store tracking events (dedupe by unique key); returns number inserted.
     * @param int|null $labelId
     * @param string $tracking
     * @param array $events Each: ['ts'=>ISO8601,'code'=>?,'desc'=>?,'location'=>?]
     */
    public function storeTrackingEvents(?int $labelId, string $tracking, array $events): int
    {
        if (!$events) return 0;
        $ins = $this->db->prepare('INSERT IGNORE INTO transfer_shipping_tracking_events
            (label_id, tracking_number, event_ts, status_code, description, location, raw_event)
            VALUES (:lid,:trk,:ts,:code,:desc,:loc,:raw)');
        $count=0;
        foreach ($events as $ev) {
            $ts = isset($ev['ts']) ? date('Y-m-d H:i:s', strtotime($ev['ts'])) : date('Y-m-d H:i:s');
            $code = (string)($ev['code'] ?? '');
            $desc = (string)($ev['desc'] ?? ($ev['description'] ?? ''));
            $loc  = (string)($ev['location'] ?? '');
            $ins->execute([
                'lid'=>$labelId,
                'trk'=>$tracking,
                'ts'=>$ts,
                'code'=>$code ?: null,
                'desc'=>$desc ?: null,
                'loc'=>$loc ?: null,
                'raw'=>json_encode($ev, JSON_UNESCAPED_SLASHES)
            ]);
            if ($ins->rowCount()>0) $count++;
        }
        return $count;
    }

    /** Fetch stored tracking events */
    public function getTrackingEvents(string $tracking): array
    {
        $st=$this->db->prepare('SELECT tracking_number, event_ts, status_code, description, location FROM transfer_shipping_tracking_events WHERE tracking_number = :t ORDER BY event_ts DESC');
        $st->execute(['t'=>$tracking]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
