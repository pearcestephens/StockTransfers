<?php
declare(strict_types=1);

namespace Modules\Transfers\Stock\Services;

use Core\DB;
use PDO;

final class TrackingService
{
    private PDO $db;
    private TransferLogger $logger;
    public function __construct() { $this->db = DB::instance(); $this->logger = new TransferLogger(); }

    /** Assign or update a tracking number to a parcel, and write a timeline event. */
    public function setParcelTracking(int $parcelId, string $tracking, string $carrier, ?int $transferId=null): void
    {
        $this->db->prepare(
            "UPDATE transfer_parcels
               SET tracking_number = :trk, courier = :carrier, updated_at = NOW()
             WHERE id = :pid"
        )->execute(['trk'=>$tracking, 'carrier'=>$carrier, 'pid'=>$parcelId]);

        $this->db->prepare(
            "INSERT INTO transfer_tracking_events
               (transfer_id, parcel_id, tracking_number, carrier, event_code, event_text, occurred_at, raw_json, created_at)
             VALUES (:tid, :pid, :trk, :carrier, 'ASSIGNED', 'Tracking assigned', NOW(), NULL, NOW())"
        )->execute(['tid'=>$transferId, 'pid'=>$parcelId, 'trk'=>$tracking, 'carrier'=>$carrier]);

        $this->logger->log('TRACKING', [
            'transfer_id' => $transferId,
            'parcel_id'   => $parcelId,
            'event_data'  => ['tracking'=>$tracking, 'carrier'=>$carrier]
        ]);
    }

    /** Add a generic tracking event (e.g., webhook). */
    public function addEvent(int $transferId, ?int $parcelId, string $tracking, string $carrier, string $code, string $text, array $raw = []): void
    {
        $this->db->prepare(
            "INSERT INTO transfer_tracking_events
               (transfer_id, parcel_id, tracking_number, carrier, event_code, event_text, occurred_at, raw_json, created_at)
             VALUES (:tid, :pid, :trk, :carrier, :code, :text, NOW(), :raw, NOW())"
        )->execute([
            'tid'=>$transferId,'pid'=>$parcelId,'trk'=>$tracking,'carrier'=>$carrier,'code'=>$code,'text'=>$text,
            'raw'=> json_encode($raw, JSON_UNESCAPED_SLASHES)
        ]);

        $this->logger->log('WEBHOOK', [
            'transfer_id'=>$transferId, 'parcel_id'=>$parcelId,
            'event_data'=>['code'=>$code,'text'=>$text], 'severity'=>'info', 'source_system'=>'Carrier'
        ]);
    }
}
