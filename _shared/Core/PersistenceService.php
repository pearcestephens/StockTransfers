<?php
declare(strict_types=1);

namespace CIS\Shared\Core;

use CIS\Shared\Config;
use CIS\Shared\Contracts\DeliveryMode;
use CIS\Shared\Contracts\ParcelSpec;
use CIS\Shared\Contracts\PersistenceServiceInterface;
use CIS\Shared\Contracts\ShipmentPlan;
use CIS\Shared\Contracts\TxResult;
use CIS\Shared\Support\Json;
use PDO;
use Throwable;

/**
 * PersistenceService.php
 *
 * Handles transactional writes for manual shipment flows.
 *
 * @package CIS\Shared\Core
 */
final class PersistenceService implements PersistenceServiceInterface
{
    /** @var PDO */
    private PDO $pdo;

    /**
     * @param PDO|null $pdo Optional PDO instance for testing.
     */
    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Config::pdo();
    }

    /**
     * {@inheritDoc}
     */
    public function commit(ShipmentPlan $plan): TxResult
    {
        $pdo = $this->pdo;
        $pdo->beginTransaction();

        try {
            $shipmentId = $this->insertShipmentHeader($plan);

            foreach ($plan->parcels as $spec) {
                $this->insertParcel($shipmentId, $spec, $plan->carrier_label);
            }

            $this->updateTransferTotals(
                $plan->transfer_id,
                $plan->totals->box_count,
                $plan->totals->total_weight_g
            );

            $this->upsertCarrierOrder($plan->transfer_id, $plan->carrier_label);

            $this->insertLog(
                $plan->transfer_id,
                $shipmentId,
                'PACKED',
                [
                    'box_count' => $plan->totals->box_count,
                    'total_weight_g' => $plan->totals->total_weight_g,
                    'delivery_mode' => $plan->delivery_mode->value,
                    'carrier' => $plan->carrier_label,
                ]
            );

            if ($plan->send_now) {
                $this->insertLog($plan->transfer_id, $shipmentId, 'SENT', ['dispatched' => true]);
            }

            $pdo->commit();

            $result = new TxResult();
            $result->transfer_id = $plan->transfer_id;
            $result->shipment_id = $shipmentId;
            $result->box_count = $plan->totals->box_count;
            $result->total_weight_kg = round($plan->totals->total_weight_g / 1000, 3);

            return $result;
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    /**
     * Insert the shipment header row.
     *
     * @param ShipmentPlan $plan Shipment plan.
     *
     * @return int Generated shipment identifier.
     */
    private function insertShipmentHeader(ShipmentPlan $plan): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO transfer_shipments
                (transfer_id, delivery_mode, status, packed_at, packed_by, carrier_name,
                 dest_name, dest_company, dest_addr1, dest_addr2, dest_suburb, dest_city, dest_postcode, dest_email, dest_phone, dest_instructions,
                 dispatched_at, created_at)
             VALUES
                (:transfer_id, :mode, :status, NOW(), NULL, :carrier,
                 :dest_name, :dest_company, :dest_addr1, :dest_addr2, :dest_suburb, :dest_city, :dest_postcode, :dest_email, :dest_phone, :dest_instructions,
                 :dispatched_at, NOW())'
        );

        $status = $plan->send_now ? 'in_transit' : 'packed';
        $snapshot = $plan->dest_snapshot;

        $statement->execute([
            ':transfer_id' => $plan->transfer_id,
            ':mode' => $plan->delivery_mode->value,
            ':status' => $status,
            ':carrier' => $plan->carrier_label ?: null,
            ':dest_name' => $snapshot?->name,
            ':dest_company' => $snapshot?->company,
            ':dest_addr1' => $snapshot?->addr1,
            ':dest_addr2' => $snapshot?->addr2,
            ':dest_suburb' => $snapshot?->suburb,
            ':dest_city' => $snapshot?->city,
            ':dest_postcode' => $snapshot?->postcode,
            ':dest_email' => $snapshot?->email,
            ':dest_phone' => $snapshot?->phone,
            ':dest_instructions' => $snapshot?->instructions,
            ':dispatched_at' => $plan->send_now ? date('Y-m-d H:i:s') : null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Insert an individual parcel record.
     *
     * @param int        $shipmentId   Shipment identifier.
     * @param ParcelSpec $spec         Parcel specification.
     * @param string     $carrierLabel Carrier label metadata.
     *
     * @return void
     */
    private function insertParcel(int $shipmentId, ParcelSpec $spec, string $carrierLabel): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO transfer_parcels
                (shipment_id, box_number, tracking_number, courier,
                 weight_kg, length_mm, width_mm, height_mm, status, notes, created_at)
             VALUES
                (:shipment_id, :box_number, NULL, :courier,
                 :weight_kg, :length_mm, :width_mm, :height_mm, :status, :notes, NOW())'
        );
        $statement->execute([
            ':shipment_id' => $shipmentId,
            ':box_number' => $spec->box_number,
            ':courier' => $carrierLabel ?: 'MANUAL',
            ':weight_kg' => $spec->weight_kg,
            ':length_mm' => $spec->length_mm,
            ':width_mm' => $spec->width_mm,
            ':height_mm' => $spec->height_mm,
            ':status' => $spec->estimated ? 'pending' : 'labelled',
            ':notes' => $spec->notes,
        ]);
    }

    /**
     * Update the transfer totals record.
     *
     * @param string|int $transferId Transfer identifier.
     * @param int        $boxes      Number of boxes.
     * @param int        $weightG    Total weight in grams.
     *
     * @return void
     */
    private function updateTransferTotals(string|int $transferId, int $boxes, int $weightG): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE transfers
                SET state = "PACKAGED", total_boxes = :boxes, total_weight_g = :weight, updated_at = NOW()
              WHERE id = :id'
        );
        $statement->execute([
            ':boxes' => $boxes,
            ':weight' => $weightG,
            ':id' => $transferId,
        ]);
    }

    /**
     * Upsert carrier order metadata.
     *
     * @param string|int $transferId   Transfer identifier.
     * @param string     $carrierLabel Carrier label.
     *
     * @return void
     */
    private function upsertCarrierOrder(string|int $transferId, string $carrierLabel): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO transfer_carrier_orders (transfer_id, carrier, order_id, order_number, payload, created_at, updated_at)
             VALUES (:transfer_id, :carrier, NULL, :order_number, :payload, NOW(), NOW())
             ON DUPLICATE KEY UPDATE order_number = VALUES(order_number), payload = VALUES(payload), updated_at = NOW()'
        );
        $statement->execute([
            ':transfer_id' => $transferId,
            ':carrier' => strtoupper($carrierLabel ?: 'MANUAL'),
            ':order_number' => 'TR-' . $transferId,
            ':payload' => Json::encode(['manual' => true]),
        ]);
    }

    /**
     * Insert a transfer log entry.
     *
     * @param string|int             $transferId Transfer identifier.
     * @param int                    $shipmentId Shipment identifier.
     * @param string                 $type       Log type.
     * @param array<string, mixed>   $data       Payload data.
     *
     * @return void
     */
    private function insertLog(string|int $transferId, int $shipmentId, string $type, array $data): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO transfer_logs
                (transfer_id, shipment_id, event_type, event_data, severity, source_system, created_at)
             VALUES (:transfer_id, :shipment_id, :event_type, :event_data, "info", "CIS", NOW())'
        );
        $statement->execute([
            ':transfer_id' => $transferId,
            ':shipment_id' => $shipmentId,
            ':event_type' => $type,
            ':event_data' => Json::encode($data),
        ]);
    }
}
