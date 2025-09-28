<?php
declare(strict_types=1);
/**
 * NZ Couriers (GoSweetSpot) — Live Adapter (header-driven creds)
 * Maps GSS Quote/Create/Void to the CIS unified schema.
 *
 * Required headers (either dash or underscore):
 *   X-GSS-Token:  <bearer-token>
 * Optional:
 *   X-GSS-Base:   https://api.gosweetspot.com (default if absent)
 *
 * Controller passes $headers from _lib/validate.php (normalized: dash + underscore keys).
 * Saturday/rural are enforced in the controller; we still pass request flags along.
 */

// ---- Shared helpers expected from _lib/validate.php ----
// - parcel_total_kg(array $parcels): float

/////////////////////////////
// Small internal utilities
/////////////////////////////
function _gss_hdr(array $h, string $name, ?string $fallback=null): ?string {
  $lk = strtolower($name);
  return $h[$lk] ?? $h[str_replace('-', '_', $lk)] ?? $fallback;
}
function _gss_api_base(array $h): string {
  $base = rtrim((string)_gss_hdr($h, 'x-gss-base', ''), '/');
  return $base !== '' ? $base : 'https://api.gosweetspot.com';
}
function _gss_token(array $h): string {
  $tok = (string) (_gss_hdr($h, 'x-gss-token', '') ?? '');
  if ($tok === '') throw new RuntimeException('GSS_NO_TOKEN: Provide X-GSS-Token header');
  return $tok;
}
function _gss_timeout(): int { return 12; }
function _gss_retries(): int { return 2; }

function _http_json_gss(string $method, string $url, array $headers, ?array $body=null): array {
  $ch = curl_init();
  $hdrs = [];
  foreach ($headers as $k=>$v) { $hdrs[] = $k.': '.$v; }
  curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => strtoupper($method),
    CURLOPT_HTTPHEADER     => $hdrs,
    CURLOPT_HEADER         => false,
    CURLOPT_TIMEOUT        => _gss_timeout(),
    CURLOPT_CONNECTTIMEOUT => 8,
  ]);
  if ($body !== null) {
    $json = json_encode($body, JSON_UNESCAPED_SLASHES);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
  }

  $attempts = _gss_retries() + 1;
  $lastErr=''; $lastCode=0; $lastBody='';
  for ($i=0; $i<$attempts; $i++) {
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($err === '' && $resp !== false && $code >= 200 && $code < 300) {
      curl_close($ch);
      $decoded = json_decode((string)$resp, true);
      if (!is_array($decoded)) throw new RuntimeException('GSS_BAD_JSON');
      return $decoded;
    }
    $lastErr=$err; $lastCode=$code; $lastBody=(string)$resp;

    if ($i < $attempts-1 && ($err !== '' || $code === 0 || $code >= 500 || $code === 429)) {
      usleep((int)(150000 * (1 + mt_rand(0, 100)/100))); // 150–300ms jitter
      continue;
    }
    break;
  }
  curl_close($ch);
  throw new RuntimeException("GSS_HTTP_FAIL code={$lastCode} err={$lastErr} body=".substr($lastBody,0,500));
}

///////////////////////////////////////
// Mappers: upstream → unified schema
///////////////////////////////////////
/** Map a GSS quote result list → unified rates. */
function _gss_map_quote_rows(array $rows): array {
  $out = [];
  foreach ($rows as $r) {
    $eta = isset($r['eta_days']) ? "+" . (int)$r['eta_days'] . " day" . (((int)$r['eta_days'])===1?'':'s') : ($r['eta'] ?? "+1 day");
    $charges = (array)($r['charges'] ?? []);
    $base    = (float)($charges['base'] ?? 0);
    $weight  = (float)($charges['weight'] ?? 0);
    $options = (float)($charges['options'] ?? 0);
    // assume totals already incl GST; if upstream returns ex GST, adjust here.
    $total_incl = (float)($r['total_incl_gst'] ?? $r['total'] ?? 0);
    $gst = (float)($charges['tax'] ?? max(0.0, round($total_incl - ($base+$weight+$options), 2)));

    $out[] = [
      "carrier"         => "nzc",
      "service_code"    => (string)($r['service_code'] ?? 'NZC_STANDARD'),
      "service_name"    => (string)($r['service_name'] ?? 'Standard'),
      "eta"             => $eta,
      "total_incl_gst"  => round($total_incl, 2),
      "breakdown"       => [
        "base"    => round($base, 2),
        "weight"  => round($weight, 2),
        "options" => round($options, 2),
        "gst"     => round($gst, 2),
      ],
      "notes"           => array_values((array)($r['notes'] ?? [])),
    ];
  }
  return $out;
}

/** Map a GSS create shipment response → unified create response. */
function _gss_map_create(array $resp, string $transferId): array {
  $tickets = [];
  foreach ((array)($resp['labels'] ?? []) as $lab) {
    $pid = (string)($lab['parcel_ref'] ?? 'p');
    $tickets[] = [
      "parcel_id" => $pid,
      "label_url" => (string)($lab['url'] ?? ''),
      "tracking"  => (string)($lab['tracking'] ?? ''),
    ];
  }
  return [
    "shipment_id" => (string)($resp['shipment_id'] ?? ''),
    "carrier"     => "nzc",
    "tickets"     => $tickets,
    "print"       => ["appendix_slip_url" => (string)(($resp['print']['appendix'] ?? '') ?: "slips/{$transferId}-all.pdf")],
  ];
}

/////////////////////
// Live operations
/////////////////////
function nzc_quote(array $ctx, string $container, array $parcels, array $options, array $facts, array $headers): array {
  $base = _gss_api_base($headers);
  $tok  = _gss_token($headers);

  $payload = [
    "container" => $container,
    "from"      => ["outlet_id" => (int)($ctx['from_outlet_id'] ?? 0)],
    "to"        => ["outlet_id" => (int)($ctx['to_outlet_id'] ?? 0)],
    "parcels"   => $parcels,
    "options"   => [
      "signature" => !empty($options['sig']),
      "atl"       => !empty($options['atl']),
      "age"       => !empty($options['age']),
      "saturday"  => !empty($options['sat']) && !empty($facts['saturday_serviceable']),
      "rural"     => !empty($facts['rural']),
    ],
    "currency"  => "NZD",
    "incl_gst"  => true
  ];

  $resp = _http_json_gss('POST', $base.'/rates/quotes', [
    'Content-Type' => 'application/json',
    'Accept'       => 'application/json',
    'Authorization'=> 'Bearer '.$tok,
  ], $payload);

  $rows = (array)($resp['rates'] ?? $resp['data'] ?? $resp);
  return _gss_map_quote_rows($rows);
}

function nzc_create(array $ctx, array $sel, array $parcels, array $options, array $headers, string $idem=''): array {
  $base = _gss_api_base($headers);
  $tok  = _gss_token($headers);
  $transfer = (string)($ctx['transfer_id'] ?? 'TBD');

  $payload = [
    "selection" => [
      "service_code" => (string)($sel['service_code'] ?? ''),
    ],
    "parcels" => $parcels,
    "options" => [
      "signature" => !empty($options['sig']),
      "atl"       => !empty($options['atl']),
      "age"       => !empty($options['age']),
      "saturday"  => !empty($options['sat']),
    ],
    "meta" => [
      "transfer_id" => $transfer,
      "from_outlet" => (int)($ctx['from_outlet_id'] ?? 0),
      "to_outlet"   => (int)($ctx['to_outlet_id'] ?? 0),
    ],
  ];

  $headersOut = [
    'Content-Type' => 'application/json',
    'Accept'       => 'application/json',
    'Authorization'=> 'Bearer '.$tok,
  ];
  if ($idem !== '') $headersOut['Idempotency-Key'] = $idem;

  $resp = _http_json_gss('POST', $base.'/shipments', $headersOut, $payload);
  return _gss_map_create($resp, $transfer);
}

function nzc_void(string $shipment_id, array $headers): bool {
  $base = _gss_api_base($headers);
  $tok  = _gss_token($headers);

  _http_json_gss('POST', $base.'/shipments/'.rawurlencode($shipment_id).'/void', [
    'Content-Type' => 'application/json',
    'Accept'       => 'application/json',
    'Authorization'=> 'Bearer '.$tok,
  ], []);

  return true;
}
