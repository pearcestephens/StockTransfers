<?php
declare(strict_types=1);
/**
 * File: PersistenceStock.php
 * Purpose: Transactional writes for pack/send shipments within stock transfers
 * Author: GitHub Copilot
 * Last Modified: 2025-09-29
 * Dependencies: Util\Db, Util\Json, Util\Time, Services\Payloads
 */

namespace Modules\Transfers\Stock\Shared\Services;

use Modules\Transfers\Stock\Shared\Util\Db;
use Modules\Transfers\Stock\Shared\Util\Json;
use Modules\Transfers\Stock\Shared\Util\Time;
use PDO;
use Throwable;

final class PersistenceStock
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::pdo();
    }

    public function commit(PackSendRequest $request, HandlerPlan $plan): TxResult
    {
        $pdo = $this->pdo;
        $pdo->beginTransaction();

        try {
            $shipmentId = $this->insertShipment($request, $plan);
            $this->insertParcels($shipmentId, $plan);
            $this->updateTransfer($request, $plan);
            $this->upsertCarrierOrder($request, $plan);
            $this->insertLogs($request, $plan, $shipmentId);

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        $result = new TxResult();
        $result->transferId = $request->transferId;
        $result->shipmentId = $shipmentId;
        $result->boxCount = $plan->totals->boxCount;
        $result->totalWeightKg = round($plan->totals->totalWeightG / 1000, 3);

        return $result;
    }

    private function insertShipment(PackSendRequest $request, HandlerPlan $plan): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO transfer_shipments
                (transfer_id, delivery_mode, status, packed_at, packed_by, carrier_name,
                 dest_name, dest_company, dest_addr1, dest_addr2, dest_suburb, dest_city,
                 dest_postcode, dest_email, dest_phone, dest_instructions,
                 dispatched_at, created_at,
                 pickup_contact_name, pickup_contact_phone, pickup_ready_at, pickup_box_count, pickup_notes,
                 internal_driver_name, internal_vehicle, internal_depart_at, internal_box_count, internal_notes,
                 depot_location, depot_drop_at, depot_box_count, depot_notes,
                 mode_notes)
             VALUES
                (:transfer_id, :delivery_mode, :status, :packed_at, :packed_by, :carrier_name,
                 NULL, NULL, NULL, NULL, NULL, NULL,
                 NULL, NULL, NULL, NULL,
                 :dispatched_at, :created_at,
                 :pickup_contact_name, :pickup_contact_phone, :pickup_ready_at, :pickup_box_count, :pickup_notes,
                 :internal_driver_name, :internal_vehicle, :internal_depart_at, :internal_box_count, :internal_notes,
                 :depot_location, :depot_drop_at, :depot_box_count, :depot_notes,
                 :mode_notes)'
        );

        $now = Time::nowUtcString();
        $status = $plan->shouldDispatch ? 'in_transit' : 'packed';
        $dispatchedAt = $plan->shouldDispatch ? $now : null;

        $pickup = $request->modeData['pickup'] ?? [];
        $internal = $request->modeData['internal'] ?? [];
        $depot = $request->modeData['depot'] ?? [];
        $modeNotes = is_string($request->modeData['notes'] ?? null) ? trim((string)$request->modeData['notes']) : null;

        $pickupReadyAt = Time::toSqlDateTime($pickup['time'] ?? null);
        $internalDepartAt = Time::toSqlDateTime($internal['depart_at'] ?? null);
        $depotDropAt = Time::toSqlDateTime($depot['drop_at'] ?? null);

        $pickupBoxCount = array_key_exists('parcels', $pickup) && is_int($pickup['parcels']) ? $pickup['parcels'] : null;
        $internalBoxCount = array_key_exists('boxes', $internal) && is_int($internal['boxes']) ? $internal['boxes'] : null;
        $depotBoxCount = array_key_exists('boxes', $depot) && is_int($depot['boxes']) ? $depot['boxes'] : null;

        $statement->execute([
            ':transfer_id' => $request->transferId,
            ':delivery_mode' => $plan->deliveryMode,
            ':status' => $status,
            ':packed_at' => $now,
            ':packed_by' => $request->userId ?: null,
            ':carrier_name' => $plan->carrierLabel ?: null,
            ':dispatched_at' => $dispatchedAt,
            ':created_at' => $now,
            ':pickup_contact_name' => $pickup['by'] ?? null,
            ':pickup_contact_phone' => $pickup['phone'] ?? null,
            ':pickup_ready_at' => $pickupReadyAt,
            ':pickup_box_count' => $pickupBoxCount,
            ':pickup_notes' => $pickup['notes'] ?? null,
            ':internal_driver_name' => $internal['driver'] ?? null,
            ':internal_vehicle' => $internal['vehicle'] ?? null,
            ':internal_depart_at' => $internalDepartAt,
            ':internal_box_count' => $internalBoxCount,
            ':internal_notes' => $internal['notes'] ?? null,
            ':depot_location' => $depot['location'] ?? null,
            ':depot_drop_at' => $depotDropAt,
            ':depot_box_count' => $depotBoxCount,
            ':depot_notes' => $depot['notes'] ?? null,
            ':mode_notes' => $modeNotes ?: null,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    private function insertParcels(int $shipmentId, HandlerPlan $plan): void
    {
        if ($plan->parcels === []) {
            return;
        }

        $sql = 'INSERT INTO transfer_parcels
                    (shipment_id, box_number, tracking_number, courier,
                     weight_kg, length_mm, width_mm, height_mm, status, notes, created_at)
                VALUES
                    (:shipment_id, :box_number, NULL, :courier,
                     :weight_kg, :length_mm, :width_mm, :height_mm, :status, :notes, :created_at)';

        $statement = $this->pdo->prepare($sql);
        $now = Time::nowUtcString();

        $numbers = [];
        foreach ($plan->parcels as $parcel) {
            if (in_array($parcel->boxNumber, $numbers, true)) {
                throw new \RuntimeException('Duplicate box_number detected in plan; aborting to protect integrity.');
            }
            $numbers[] = $parcel->boxNumber;

            $status = $parcel->estimated ? 'pending' : 'labelled';
            $statement->execute([
                ':shipment_id' => $shipmentId,
                ':box_number' => $parcel->boxNumber,
                ':courier' => $plan->carrierLane ?: 'MANUAL',
                ':weight_kg' => $parcel->weightKg,
                ':length_mm' => $parcel->lengthMm,
                ':width_mm' => $parcel->widthMm,
                ':height_mm' => $parcel->heightMm,
                ':status' => $status,
                ':notes' => $parcel->notes,
                ':created_at' => $now,
            ]);
        }
    }

    private function updateTransfer(PackSendRequest $request, HandlerPlan $plan): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE transfers
                SET state = "PACKAGED",
                    total_boxes = :boxes,
                    total_weight_g = :weight,
                    updated_at = NOW()
              WHERE id = :transfer_id'
        );
        $statement->execute([
            ':boxes' => $plan->totals->boxCount,
            ':weight' => $plan->totals->totalWeightG,
            ':transfer_id' => $request->transferId,
        ]);
    }

    private function upsertCarrierOrder(PackSendRequest $request, HandlerPlan $plan): void
    {
        $payload = Json::encode([
            'mode' => $plan->mode,
            'carrier' => $plan->carrierLane,
            'manual' => true,
        ]);

        $sql = 'INSERT INTO transfer_carrier_orders
                    (transfer_id, carrier, order_number, payload, created_at, updated_at)
                VALUES
                    (:transfer_id, :carrier, :order_number, :payload, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    order_number = VALUES(order_number),
                    payload = VALUES(payload),
                    updated_at = NOW()';

        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            ':transfer_id' => $request->transferId,
            ':carrier' => strtoupper($plan->carrierLane ?: 'MANUAL'),
            ':order_number' => 'TR-' . $request->transferId,
            ':payload' => $payload,
        ]);
    }

    private function insertLogs(PackSendRequest $request, HandlerPlan $plan, int $shipmentId): void
    {
        $payload = [
            'shipment_id' => $shipmentId,
            'mode' => $plan->mode,
            'carrier' => $plan->carrierLane,
            'box_count' => $plan->totals->boxCount,
            'total_weight_g' => $plan->totals->totalWeightG,
        ];

        $modeMeta = array_filter([
            'pickup' => $request->modeData['pickup'] ?? [],
            'internal' => $request->modeData['internal'] ?? [],
            'depot' => $request->modeData['depot'] ?? [],
            'notes' => $request->modeData['notes'] ?? null,
        ], static function ($value) {
            if (is_array($value)) {
                return $value !== [];
            }
            return $value !== null && $value !== '';
        });

        if ($modeMeta !== []) {
            $payload['mode_meta'] = $modeMeta;
        }

        $this->insertLogRow($request->transferId, $shipmentId, 'PACKED', $payload);

        if ($plan->shouldDispatch) {
            $payload['dispatched'] = true;
            $this->insertLogRow($request->transferId, $shipmentId, 'SENT', $payload);
        }
    }

    private function insertLogRow(int $transferId, int $shipmentId, string $eventType, array $data): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO transfer_logs
                (transfer_id, shipment_id, event_type, event_data, severity, source_system, created_at)
             VALUES
                (:transfer_id, :shipment_id, :event_type, :event_data, "info", "CIS", NOW())'
        );
        $statement->execute([
            ':transfer_id' => $transferId,
            ':shipment_id' => $shipmentId,
            ':event_type' => $eventType,
            ':event_data' => Json::encode($data),
        ]);
    }
}
