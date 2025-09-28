<?php
declare(strict_types=1);

namespace Modules\Transfers\Stock\Services;

use Core\DB;
use PDO;

final class ParcelService
{
    private PDO $db;
    private TransferLogger $logger;
    private TrackingService $tracking;

    public function __construct()
    {
        $this->db = DB::instance();
        $this->logger = new TransferLogger();
        $this->tracking = new TrackingService();
    }

    /** Attach an item quantity to a parcel (upsert). */
    public function addParcelItem(int $parcelId, int $itemId, int $qty): void
    {
        $qty = max(0,$qty);
        $this->db->prepare(
            "INSERT INTO transfer_parcel_items (parcel_id, item_id, qty, qty_received, created_at)
             VALUES (:pid, :iid, :qty, 0, NOW())
             ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)"
        )->execute(['pid'=>$parcelId,'iid'=>$itemId,'qty'=>$qty]);

        $this->logger->log('ADD_ITEM', ['parcel_id'=>$parcelId, 'item_id'=>$itemId, 'event_data'=>['qty'=>$qty]]);
    }

    /** Assign tracking & emit events. */
    public function setTracking(int $parcelId, string $tracking, string $carrier, ?int $transferId=null): void
    {
        $this->tracking->setParcelTracking($parcelId, $tracking, $carrier, $transferId);
    }
}
