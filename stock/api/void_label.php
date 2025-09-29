<?php
declare(strict_types=1);

/**
 * CIS — Void Courier Shipment (Sync)
 * Input (JSON OR query string):
 *   carrier: 'nzpost' | 'nzc'
 *   shipment_id: string  (the carrier’s consignment/shipment id)
 *
 * Success:
 *   { "ok": true, "carrier": "nzpost"|"nzc", "voided": true }
 */

require __DIR__.'/_lib/validate.php';
require __DIR__.'/_lib/adapters/nzpost.php'; // nz_post_void(string $shipment_id, array $headers): bool
require __DIR__.'/_lib/adapters/gss.php';    // nzc_void(string $shipment_id, array $headers): bool

cors_and_headers([
  'allow_methods' => 'POST, GET, OPTIONS',
  'allow_headers' => 'Content-Type, X-API-Key, X-NZPost-Token, X-NZPost-Base, X-GSS-Token, X-GSS-Base',
  'max_age'       => 600
]);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') { http_response_code(204); exit; }

// Accept GET for convenience (e.g. from admin tools), POST preferred
$carrier     = '';
$shipment_id = '';

if ($method === 'POST') {
  $in = json_input(); // throws on invalid
  $carrier     = (string)($in['carrier'] ?? '');
  $shipment_id = (string)($in['shipment_id'] ?? '');
} else { // GET
  $carrier     = (string)($_GET['carrier'] ?? '');
  $shipment_id = (string)($_GET['shipment_id'] ?? '');
}

if (!in_array($carrier, ['nzpost','nzc'], true)) fail('MISSING_PARAM','carrier must be nzpost|nzc');
if ($shipment_id === '') fail('MISSING_PARAM','shipment_id required');

// Normalize incoming HTTP_* headers to the dash/underscore keys your adapters accept
$headersLower = [];
foreach ($_SERVER as $k=>$v) {
  if (strpos($k,'HTTP_') === 0) {
    $h = strtolower(str_replace('_','-', substr($k,5)));
    $headersLower[$h] = (string)$v;
    $headersLower[str_replace('-','_',$h)] = (string)$v; // underscore alias too
  }
}

try {
  $ok = false;
  if ($carrier === 'nzpost') {
    $ok = nz_post_void($shipment_id, $headersLower);
  } else {
    $ok = nzc_void($shipment_id, $headersLower);
  }
  ok(['carrier'=>$carrier, 'voided'=> (bool)$ok]);
} catch (Throwable $e) {
  fail('CARRIER_VOID_FAILED', $e->getMessage(), 400);
}
