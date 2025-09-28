<?php
declare(strict_types=1);

namespace Modules\Transfers\Stock\Services;

use Core\DB;
use PDO;

final class NotesService
{
    private PDO $db;
    private TransferLogger $logger;

    public function __construct()
    {
        $this->db = DB::instance();
        $this->logger = new TransferLogger();
    }

    /** Transfer-level note (multiple allowed, soft-delete supported by schema). */
    public function addTransferNote(int $transferId, string $text, int $userId): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO transfer_notes (transfer_id, note_text, created_by, created_at)
             VALUES (:tid, :text, :uid, NOW())"
        );
        $stmt->execute(['tid'=>$transferId, 'text'=>$text, 'uid'=>$userId]);
        $id = (int)$this->db->lastInsertId();

        $this->logger->log('NOTE', [
            'transfer_id' => $transferId,
            'actor_user_id' => $userId,
            'event_data' => ['scope'=>'transfer', 'note_id'=>$id],
        ]);
        return $id;
    }

    /** Shipment-level note (transfer_shipment_notes). */
    public function addShipmentNote(int $shipmentId, string $text, int $userId, ?int $transferId=null): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO transfer_shipment_notes (shipment_id, note_text, created_by, created_at)
             VALUES (:sid, :text, :uid, NOW())"
        );
        $stmt->execute(['sid'=>$shipmentId, 'text'=>$text, 'uid'=>$userId]);
        $id = (int)$this->db->lastInsertId();

        $this->logger->log('NOTE', [
            'transfer_id' => $transferId,
            'shipment_id' => $shipmentId,
            'actor_user_id' => $userId,
            'event_data' => ['scope'=>'shipment', 'note_id'=>$id],
        ]);
        return $id;
    }

    /** Parcel-level note: schema holds note text on parcel row; we append. */
    public function appendParcelNote(int $parcelId, string $note, int $userId, ?int $transferId=null, ?int $shipmentId=null): void
    {
        // Concatenate with newline if existing.
        $this->db->prepare(
            "UPDATE transfer_parcels
             SET notes = TRIM(CONCAT(COALESCE(notes,''), CASE WHEN notes IS NULL OR notes='' THEN '' ELSE '\n' END, :line)),
                 updated_at = NOW()
             WHERE id = :pid"
        )->execute(['line'=>('['.date('Y-m-d H:i').'] #'.$userId.' '.$note), 'pid'=>$parcelId]);

        $this->logger->log('NOTE', [
            'transfer_id' => $transferId,
            'shipment_id' => $shipmentId,
            'parcel_id'   => $parcelId,
            'actor_user_id' => $userId,
            'event_data'  => ['scope'=>'parcel'],
        ]);
    }
}
