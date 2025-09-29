<?php
declare(strict_types=1);

/**
 * CIS — Track Events (Poll or Ingest)
 * Path: modules/transfers/stock/api/track_events.php
 *
 * Modes:
 *  A) Ingest mode (webhook push or internal push):
 *     POST JSON:
 *       {
 *         "mode": "ingest",
 *         "transfer_id": 12345,
 *         "carrier": "nzpost"|"nzc"|"other",
 *         "events": [
 *           { "tracking_number":"NZ123...", "event_code":"IN_TRANSIT", "event_text":"In transit",
 *             "occurred_at":"2025-09-30T10:02:00Z", "parcel_id":null }
 *         ]
 *       }
 *     Response: { ok:true, inserted:n, skipped:n }
 *
 *  B) Poll mode (pull from carriers – requires adapter functions you may add):
 *     POST JSON:
 *       {
 *         "mode": "poll",
 *         "transfer_id": 12345,
 *         "carrier": "nzpost"|"nzc",
 *         "shipment_id": "CARRIER_SHIPMENT_ID"   (or)
 *         "tracking_numbers": ["NZ123...", "..."],
 *         "since": "2025-09-28T00:00:00Z"       (optional filter)
 *       }
 *     Response: { ok:true, fetched:n, inserted:n, skipped:n, warnings:[] }
 */

require __DIR__.'/_lib/validate.php';
require __DIR__.'/_lib/adapters/nzpost.php'; // nz_post_void(...); optional: nz_post_track(...)
require __DIR__.'/_lib/adapters/gss.php';    // nzc_void(...);    optional: nzc_track(...)

cors_and_headers([
  'allow_methods' => 'POST, OPTIONS',
  'allow_headers' => 'Content-Type, X-API-Key, X-Transfer-ID, X-From-Outlet-ID, X-To-Outlet-ID, X-NZPost-Token, X-NZPost-Base, X-GSS-Token, X-GSS-Base',
  'max_age'       => 600
]);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST')     { fail('METHOD_NOT_ALLOWED','POST only',405); }

$in   = json_input();
$mode = strtolower((string)($in['mode'] ?? 'ingest'));
$carrier = strtolower((string)($in['carrier'] ?? ''));
$transferId = (int)($in['transfer_id'] ?? 0);
if (!$transferId) fail('MISSING_PARAM','transfer_id required');

$pdo = pdo();

/* ---------- helpers ---------- */

/** Convert string/DateTime to MySQL DATETIME */
function norm_dt(?string $ts): string {
  if (!$ts) return gmdate('Y-m-d H:i:s');
  $t = strtotime($ts);
  if ($t === false) return gmdate('Y-m-d H:i:s');
  return gmdate('Y-m-d H:i:s', $t);
}

/** Insert if not duplicate (by transfer_id + tracking + code + occurred_at) */
function insert_tracking_event(PDO $pdo, int $transferId, ?int $parcelId, string $tracking, string $carrierCode, string $code, string $text, string $occurredAt, array $raw): bool {
  // de-dupe check
  $chk = $pdo->prepare("
    SELECT id FROM transfer_tracking_events
    WHERE transfer_id=:t AND tracking_number=:trk AND carrier=:car AND event_code=:code AND occurred_at=:occ
    LIMIT 1
  ");
  $chk->execute([':t'=>$transferId, ':trk'=>$tracking, ':car'=>$carrierCode, ':code'=>$code, ':occ'=>$occurredAt]);
  if ($chk->fetchColumn()) return false;

  $ins = $pdo->prepare("
    INSERT INTO transfer_tracking_events
      (transfer_id, parcel_id, tracking_number, carrier, event_code, event_text, occurred_at, raw_json, created_at)
    VALUES
      (:t, :p, :trk, :car, :code, :text, :occ, :raw, NOW())
  ");
  $ins->execute([
    ':t'=>$transferId,
    ':p'=>$parcelId ?: null,
    ':trk'=>$tracking,
    ':car'=>$carrierCode,
    ':code'=>$code,
    ':text'=>$text,
    ':occ'=>$occurredAt,
    ':raw'=> json_encode($raw, JSON_UNESCAPED_SLASHES)
  ]);
  return true;
}

/** Light parcel/shipment status lift if we can */
function maybe_touch_parcel_and_shipment(PDO $pdo, int $transferId, ?int $parcelId, string $code): void {
  // Very conservative: only touch on clear signals
  if (in_array($code, ['LABEL_PRINTED','LABELLED'], true) && $parcelId) {
    $u = $pdo->prepare("UPDATE transfer_parcels SET status='labelled', updated_at=NOW() WHERE id=:p");
    $u->execute([':p'=>$parcelId]);
  } elseif (in_array($code, ['IN_TRANSIT','PICKED_UP'], true)) {
    $u = $pdo->prepare("UPDATE transfer_shipments SET status='in_transit', dispatched_at=IFNULL(dispatched_at,NOW()), updated_at=NOW() WHERE transfer_id=:t");
    $u->execute([':t'=>$transferId]);
  } elseif (in_array($code, ['DELIVERED'], true)) {
    $u = $pdo->prepare("UPDATE transfer_shipments SET status='received', received_at=IFNULL(received_at,NOW()), updated_at=NOW() WHERE transfer_id=:t");
    $u->execute([':t'=>$transferId]);
  }
}

/** Write a log row */
function add_log(PDO $pdo, int $transferId, string $etype, array $payload): void {
  $st = $pdo->prepare("
    INSERT INTO transfer_logs
      (transfer_id, event_type, event_data, severity, source_system, created_at)
    VALUES
      (:t, :type, :data, 'info', 'CIS', NOW())
  ");
  $st->execute([':t'=>$transferId, ':type'=>$etype, ':data'=>json_encode($payload, JSON_UNESCAPED_SLASHES)]);
}

/* ---------- main ---------- */

if ($mode === 'ingest') {
  // Accept normalized events now
  $events = (array)($in['events'] ?? []);
  if (!$events) fail('MISSING_PARAM','events[] required for ingest');

  $inserted = 0; $skipped = 0;
  foreach ($events as $ev) {
    $trk = trim((string)($ev['tracking_number'] ?? ''));
    $code= strtoupper((string)($ev['event_code'] ?? ''));
    $text= (string)($ev['event_text'] ?? $code);
    $tAt = norm_dt((string)($ev['occurred_at'] ?? 'now'));
    $pid = $ev['parcel_id'] ?? null;

    if ($trk === '' || $code === '') { $skipped++; continue; }
    $ok = insert_tracking_event($pdo, $transferId, is_numeric($pid)?(int)$pid:null, $trk, $carrier ?: 'other', $code, $text, $tAt, (array)$ev);
    if ($ok) {
      $inserted++;
      maybe_touch_parcel_and_shipment($pdo, $transferId, is_numeric($pid)?(int)$pid:null, $code);
    } else {
      $skipped++;
    }
  }
  add_log($pdo, $transferId, 'TRACK_INGEST', ['carrier'=>$carrier, 'inserted'=>$inserted, 'skipped'=>$skipped]);
  ok(['mode'=>'ingest','inserted'=>$inserted,'skipped'=>$skipped]);
}

/* -------- poll mode (requires adapter `*_track`) -------- */
if ($mode === 'poll') {
  if (!in_array($carrier, ['nzpost','nzc'], true)) fail('MISSING_PARAM','carrier must be nzpost|nzc for poll');

  $shipmentId = (string)($in['shipment_id'] ?? '');
  $numbers    = (array)($in['tracking_numbers'] ?? []);
  $since      = (string)($in['since'] ?? '');

  // normalize headers for adapters
  $headersLower = [];
  foreach ($_SERVER as $k=>$v) {
    if (strpos($k,'HTTP_') === 0) {
      $h = strtolower(str_replace('_','-', substr($k,5)));
      $headersLower[$h] = (string)$v;
      $headersLower[str_replace('-','_',$h)] = (string)$v;
    }
  }

  $warnings = [];
  $fetched  = 0; $inserted = 0; $skipped = 0;

  if ($carrier === 'nzpost') {
    if (!function_exists('nz_post_track')) {
      fail('UNSUPPORTED','Adapter nz_post_track() not implemented; add it to _lib/adapters/nzpost.php', 200);
    }
    $resp = nz_post_track([
      'transfer_id'=>$transferId,
      'shipment_id'=>$shipmentId,
      'tracking_numbers'=>$numbers,
      'since'=>$since
    ], $headersLower);
    // expected normalized array of {tracking, code, text, occurred_at, parcel_id?}
    foreach ((array)$resp as $ev) {
      $fetched++;
      $ok = insert_tracking_event(
        $pdo,
        $transferId,
        isset($ev['parcel_id']) && is_numeric($ev['parcel_id']) ? (int)$ev['parcel_id'] : null,
        (string)($ev['tracking'] ?? ''),
        'nzpost',
        strtoupper((string)($ev['code'] ?? '')),
        (string)($ev['text'] ?? ''),
        norm_dt($ev['occurred_at'] ?? ''),
        (array)$ev
      );
      if ($ok) { $inserted++; maybe_touch_parcel_and_shipment($pdo, $transferId, $ev['parcel_id'] ?? null, strtoupper((string)($ev['code'] ?? ''))); }
      else { $skipped++; }
    }
  } else { // nzc
    if (!function_exists('nzc_track')) {
      fail('UNSUPPORTED','Adapter nzc_track() not implemented; add it to _lib/adapters/gss.php', 200);
    }
    $resp = nzc_track([
      'transfer_id'=>$transferId,
      'shipment_id'=>$shipmentId,
      'tracking_numbers'=>$numbers,
      'since'=>$since
    ], $headersLower);
    foreach ((array)$resp as $ev) {
      $fetched++;
      $ok = insert_tracking_event(
        $pdo,
        $transferId,
        isset($ev['parcel_id']) && is_numeric($ev['parcel_id']) ? (int)$ev['parcel_id'] : null,
        (string)($ev['tracking'] ?? ''),
        'nzc',
        strtoupper((string)($ev['code'] ?? '')),
        (string)($ev['text'] ?? ''),
        norm_dt($ev['occurred_at'] ?? ''),
        (array)$ev
      );
      if ($ok) { $inserted++; maybe_touch_parcel_and_shipment($pdo, $transferId, $ev['parcel_id'] ?? null, strtoupper((string)($ev['code'] ?? ''))); }
      else { $skipped++; }
    }
  }

  add_log($pdo, $transferId, 'TRACK_POLL', ['carrier'=>$carrier, 'fetched'=>$fetched, 'inserted'=>$inserted, 'skipped'=>$skipped, 'warnings'=>$warnings]);
  ok(['mode'=>'poll','fetched'=>$fetched,'inserted'=>$inserted,'skipped'=>$skipped,'warnings'=>$warnings]);
}

fail('BAD_MODE','mode must be ingest or poll');
