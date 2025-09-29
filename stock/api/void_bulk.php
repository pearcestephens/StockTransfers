<?php
declare(strict_types=1);

/**
 * CIS — Void Bulk Shipments
 * Path: modules/transfers/stock/api/void_bulk.php
 *
 * Input JSON:
 * {
 *   "items": [
 *     {"carrier":"nzpost", "shipment_id":"NZP-123"},
 *     {"carrier":"nzc",    "shipment_id":"NZC-456"}
 *   ]
 * }
 *
 * Success:
 * { "ok": true, "results":[ {"carrier":"nzpost","shipment_id":"NZP-123","ok":true}, {...} ] }
 */

require __DIR__.'/_lib/validate.php';
require __DIR__.'/_lib/adapters/nzpost.php'; // nz_post_void(string $shipment_id, array $headers): bool
require __DIR__.'/_lib/adapters/gss.php';    // nzc_void(string $shipment_id, array $headers): bool

cors_and_headers([
  'allow_methods' => 'POST, OPTIONS',
  'allow_headers' => 'Content-Type, X-API-Key, X-NZPost-Token, X-NZPost-Base, X-GSS-Token, X-GSS-Base',
  'max_age'       => 600
]);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST')     { fail('METHOD_NOT_ALLOWED','POST only',405); }

$in = json_input();
$items = (array)($in['items'] ?? []);
if (!$items) fail('MISSING_PARAM','items[] required');

$headersLower = [];
foreach ($_SERVER as $k=>$v) {
  if (strpos($k,'HTTP_') === 0) {
    $h = strtolower(str_replace('_','-', substr($k,5)));
    $headersLower[$h] = (string)$v;
    $headersLower[str_replace('-','_',$h)] = (string)$v;
  }
}

$results = [];
foreach ($items as $row) {
  $carrier = strtolower((string)($row['carrier'] ?? ''));
  $sid     = (string)($row['shipment_id'] ?? '');
  if (!in_array($carrier,['nzpost','nzc'],true) || $sid==='') {
    $results[] = ['carrier'=>$carrier,'shipment_id'=>$sid,'ok'=>false,'error'=>'INVALID_ROW'];
    continue;
  }
  try {
    $ok = ($carrier==='nzpost')
      ? nz_post_void($sid, $headersLower)
      : nzc_void($sid, $headersLower);
    $results[] = ['carrier'=>$carrier,'shipment_id'=>$sid,'ok'=>(bool)$ok];
  } catch (Throwable $e) {
    $results[] = ['carrier'=>$carrier,'shipment_id'=>$sid,'ok'=>false,'error'=>$e->getMessage()];
  }
}

ok(['results'=>$results]);
