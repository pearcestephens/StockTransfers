<?php
declare(strict_types=1);

/**
 * CIS — Create Courier Label (Sync; No Queue)
 * Live adapters:
 *   - _lib/adapters/nzpost.php  (functions: nz_post_create, nz_post_void)
 *   - _lib/adapters/gss.php     (functions: nzc_create, nzc_void)
 *
 * INPUT JSON:
 * {
 *   "meta":       {"transfer_id":int,"from_outlet_id":int,"to_outlet_id":int},
 *   "selection":  {
 *      "carrier":"nzpost"|"nzc",
 *      "carrier_name":"NZ Post"|"NZ Couriers",
 *      "service_code":string,          // official carrier code
 *      "service_name":string,          // official name (for audit only)
 *      "package_code":string|null,     // pass-through if your adapter wants it
 *      "package_name":string|null,
 *      "total":number                  // Incl GST (display only)
 *   },
 *   "packages":   [{"name":string,"w":float,"l":float,"h":float,"kg":float,"items":int}],
 *   "options":    {"sig":bool,"atl":bool,"age":bool,"sat":bool},
 *   "address_facts":{"rural":bool,"saturday_serviceable":bool},
 *   "mark_packed": bool,
 *   "idem": string?
 * }
 *
 * SUCCESS:
 * { "ok": true, "carrier":"nzpost"|"nzc", "consignment_id":"...","label_url":null|"...","spooled":true }
 */

require __DIR__.'/_lib/validate.php';         // cors_and_headers(), json_input(), ok(), fail(), pdo(), etc.
require __DIR__.'/_lib/adapters/nzpost.php';  // nz_post_create(...)
require __DIR__.'/_lib/adapters/gss.php';     // nzc_create(...)

cors_and_headers([
  'allow_methods' => 'POST, OPTIONS',
  'allow_headers' => 'Content-Type, X-API-Key, X-Transfer-ID, X-From-Outlet-ID, X-To-Outlet-ID, X-NZPost-Token, X-NZPost-Base, X-NZPost-Subscription, X-GSS-Token, X-GSS-Base',
  'max_age'       => 600
]);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST')     { fail('METHOD_NOT_ALLOWED','POST only',405); }

$cl = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($cl > 1_500_000) fail('PAYLOAD_TOO_LARGE','>1.5MB',413);

// -------- Parse & validate --------
$in   = json_input();                 // throws on bad JSON
$meta = (array)($in['meta'] ?? []);
$sel  = (array)($in['selection'] ?? []);
$pkgs = (array)($in['packages'] ?? []);
$opt  = (array)($in['options'] ?? []);
$facts= (array)($in['address_facts'] ?? []);

$transferId   = (int)($meta['transfer_id']   ?? 0);
$fromOutletId = (int)($meta['from_outlet_id']?? 0);
$toOutletId   = (int)($meta['to_outlet_id']  ?? 0);

$carrier      = (string)($sel['carrier']      ?? '');
$serviceCode  = (string)($sel['service_code'] ?? '');
$serviceName  = (string)($sel['service_name'] ?? '');
$packageCode  = $sel['package_code'] ?? null;      // optional, adapter may ignore
$packageName  = $sel['package_name'] ?? null;

if (!$transferId)   fail('MISSING_PARAM','meta.transfer_id required');
if (!$fromOutletId) fail('MISSING_PARAM','meta.from_outlet_id required');
if (!$toOutletId)   fail('MISSING_PARAM','meta.to_outlet_id required');
if (!in_array($carrier,['nzpost','nzc'],true)) fail('MISSING_PARAM','selection.carrier must be nzpost|nzc');
if ($serviceCode==='')  fail('MISSING_PARAM','selection.service_code required');
if (!$pkgs) fail('MISSING_PARAM','packages required');

// parcels: adapter payload wants a simple parcel list; we pass cm/kg and a description
$parcels = [];
foreach ($pkgs as $p) {
  $parcels[] = [
    'description' => (string)($p['name'] ?? 'Parcel'),
    'length_cm'   => (float)($p['l'] ?? 0),
    'width_cm'    => (float)($p['w'] ?? 0),
    'height_cm'   => (float)($p['h'] ?? 0),
    'weight_kg'   => (float)($p['kg'] ?? 0),
    'items'       => (int)($p['items'] ?? 0),
  ];
}

// idempotency
$idem = (string)($in['idem'] ?? '');
if ($idem==='') {
  $idem = hash('sha256', json_encode([$transferId,$carrier,$serviceCode,$parcels], JSON_UNESCAPED_SLASHES));
}

// -------- Header creds (your requirement: client headers are accepted) --------
// Build a lower-cased header map so adapters can read both dash/underscore
$headersLower = [];
foreach ($_SERVER as $k=>$v) {
  if (strpos($k,'HTTP_') === 0) {
    $h = strtolower(str_replace('_','-', substr($k,5)));
    $headersLower[$h] = (string)$v;
    $headersLower[str_replace('-','_',$h)] = (string)$v; // underscore alias too
  }
}

// Fallback to DB only if header missing (still respecting your “headers accepted” policy)
$pdo = pdo();
if (empty($headersLower['x-nzpost-token']) && empty($headersLower['x-nzpost-subscription'])) {
  $row = outlet_carrier_creds($pdo, $fromOutletId); // nz_post_api_key, nz_post_subscription_key, gss_token
  if (!empty($row['nz_post_api_key']))        $headersLower['x-nzpost-token']        = (string)$row['nz_post_api_key'];
  if (!empty($row['nz_post_subscription_key']))$headersLower['x-nzpost-subscription'] = (string)$row['nz_post_subscription_key'];
}
if (empty($headersLower['x-gss-token'])) {
  $row = isset($row) ? $row : outlet_carrier_creds($pdo, $fromOutletId);
  if (!empty($row['gss_token'])) $headersLower['x-gss-token'] = (string)$row['gss_token'];
}

// safety: ensure required token exists for selected carrier
if ($carrier==='nzpost' && empty($headersLower['x-nzpost-token']) && empty($headersLower['x-nzpost-subscription'])) {
  fail('AUTH_MISSING','NZ Post credentials not provided (header or outlet '.$fromOutletId.')',400);
}
if ($carrier==='nzc' && empty($headersLower['x-gss-token'])) {
  fail('AUTH_MISSING','GSS token not provided (header or outlet '.$fromOutletId.')',400);
}

// -------- Build contexts for adapters --------
$ctx = [
  'transfer_id'    => $transferId,
  'from_outlet_id' => $fromOutletId,
  'to_outlet_id'   => $toOutletId,
];

$options = [
  'sig'=>!empty($opt['sig']),
  'atl'=>!empty($opt['atl']),
  'age'=>!empty($opt['age']),
  'sat'=>!empty($opt['sat']),
];

$facts = [
  'rural'=>!empty($facts['rural']),
  'saturday_serviceable'=>!empty($facts['saturday_serviceable']),
];

// Selection passed to adapter (use official codes, not our internal names)
$selForAdapter = [
  'service_code' => $serviceCode,
  'package_code' => $packageCode,
];

// -------- Dispatch to live adapter --------
try {
  if ($carrier === 'nzpost') {
    $resp = nz_post_create($ctx, $selForAdapter, $parcels, $options, $headersLower, $idem);
    $consignmentId = (string)($resp['shipment_id'] ?? $resp['consignment_id'] ?? '');
    $labelUrl      = (string)($resp['print']['appendix_slip_url'] ?? $resp['label_url'] ?? '');
  } else { // 'nzc'
    $resp = nzc_create($ctx, $selForAdapter, $parcels, $options, $headersLower, $idem);
    $consignmentId = (string)($resp['shipment_id'] ?? $resp['consignment_id'] ?? '');
    $labelUrl      = (string)($resp['print']['appendix_slip_url'] ?? $resp['label_url'] ?? '');
  }
} catch (Throwable $e) {
  fail('CARRIER_CREATE_FAILED', $e->getMessage(), 400);
}

// -------- Optional: mark packed in transfers (compact, safe) --------
if (!empty($in['mark_packed'])) {
  try { mark_transfer_packed($pdo, $transferId, $parcels); } catch (Throwable $e) { /* non-fatal */ }
}

// -------- Respond --------
ok([
  'carrier'        => $carrier,
  'consignment_id' => $consignmentId ?: null,
  'label_url'      => $labelUrl ?: null,   // agent usually prints automatically
  'spooled'        => true
]);

/* ================= Helpers ================= */

function outlet_carrier_creds(PDO $pdo, int|string $outletId): array {
  $legacy = null;
  $uuid   = null;

  if (is_int($outletId)) {
    if ($outletId > 0) {
      $legacy = $outletId;
    }
  } else {
    $candidate = trim((string)$outletId);
    if ($candidate !== '') {
      $uuid = $candidate;
      if (ctype_digit($candidate)) {
        $legacy = (int)$candidate;
      }
    }
  }

  $conditions = [];
  $params = [];
  if ($legacy !== null && $legacy > 0) {
    $conditions[] = 'website_outlet_id = :legacy';
    $params['legacy'] = $legacy;
  }
  if ($uuid !== null) {
    $conditions[] = 'id = :uuid';
    $params['uuid'] = $uuid;
  }

  if (!$conditions) {
    return [];
  }

  $sql = 'SELECT nz_post_api_key, nz_post_subscription_key, gss_token
             FROM vend_outlets
            WHERE ' . implode(' OR ', $conditions) . '
            LIMIT 1';

  $st = $pdo->prepare($sql);
  $st->execute($params);
  return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}

function mark_transfer_packed(PDO $pdo, int $transferId, array $parcels): void {
  $boxes = max(1, count($parcels));
  $grams = 0; foreach ($parcels as $p) { $grams += (int)round((float)($p['weight_kg'] ?? 0)*1000); }
  $st = $pdo->prepare("UPDATE transfers SET state='PACKAGED', total_boxes=:b, total_weight_g=:g WHERE id=:id");
  $st->execute([':b'=>$boxes, ':g'=>$grams, ':id'=>$transferId]);
}
