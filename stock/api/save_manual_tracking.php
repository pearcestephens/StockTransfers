<?php
declare(strict_types=1);

/**
 * CIS — Save Manual Tracking
 * Path: modules/transfers/stock/api/save_manual_tracking.php
 *
 * Input JSON:
 * {
 *   "transfer_id": int,
 *   "carrier": "nzpost" | "nzc" | "other",
 *   "tracking": string,
 *   "notes": string|null
 * }
 *
 * Success:
 * { "ok": true, "shipment_id": 123, "parcel_id": 456 }
 */

require __DIR__.'/_lib/validate.php'; // cors_and_headers(), json_input(), ok(), fail(), pdo()

cors_and_headers([
  'allow_methods' => 'POST, OPTIONS',
  'allow_headers' => 'Content-Type, X-API-Key',
  'max_age'       => 600
]);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST')     { fail('METHOD_NOT_ALLOWED','POST only',405); }

// ---- Parse
$in = json_input();
$transferId = (int)($in['transfer_id'] ?? 0);
$carrier    = (string)($in['carrier'] ?? '');
$tracking   = trim((string)($in['tracking'] ?? ''));
$notes      = trim((string)($in['notes'] ?? ''));

if (!$transferId) fail('MISSING_PARAM','transfer_id required');
if ($tracking === '') fail('MISSING_PARAM','tracking required');

$pdo = pdo();
$pdo->beginTransaction();

try {
  // 1) Ensure a shipment header exists (courier mode by default)
  $st = $pdo->prepare("SELECT id FROM transfer_shipments WHERE transfer_id=:t ORDER BY id DESC LIMIT 1");
  $st->execute([':t'=>$transferId]);
  $shipmentId = (int)($st->fetchColumn() ?: 0);

  if ($shipmentId <= 0) {
    $st = $pdo->prepare("
      INSERT INTO transfer_shipments
        (transfer_id, delivery_mode, status, packed_at, packed_by, created_at)
      VALUES
        (:t, 'courier', 'packed', NOW(), NULL, NOW())
    ");
    $st->execute([':t'=>$transferId]);
    $shipmentId = (int)$pdo->lastInsertId();
  }

  // 2) Insert parcel row with tracking
  $st = $pdo->prepare("
    INSERT INTO transfer_parcels
      (shipment_id, box_number, tracking_number, courier, status, created_at)
    VALUES
      (:s, 1 + COALESCE((SELECT MAX(box_number) FROM transfer_parcels WHERE shipment_id=:s), 0),
       :trk, :car, 'labelled', NOW())
  ");
  $st->execute([
    ':s'   => $shipmentId,
    ':trk' => $tracking,
    ':car' => ($carrier ?: 'manual')
  ]);
  $parcelId = (int)$pdo->lastInsertId();

  // 3) Log event
  $log = $pdo->prepare("
    INSERT INTO transfer_logs
      (transfer_id, shipment_id, event_type, event_data, actor_user_id, severity, source_system, created_at)
    VALUES
      (:t, :s, 'NOTE', :data, NULL, 'info', 'CIS', NOW())
  ");
  $log->execute([
    ':t'   => $transferId,
    ':s'   => $shipmentId,
    ':data'=> json_encode(['manual_tracking'=>$tracking,'carrier'=>$carrier,'notes'=>$notes], JSON_UNESCAPED_SLASHES)
  ]);

  $pdo->commit();
  ok(['shipment_id'=>$shipmentId, 'parcel_id'=>$parcelId]);
} catch (Throwable $e) {
  $pdo->rollBack();
  fail('SAVE_MANUAL_FAILED', $e->getMessage(), 400);
}
