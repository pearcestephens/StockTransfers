<?php
declare(strict_types=1);
/**
 * NZ Post / Starshipit — Live Adapter (header-driven creds)
 * Maps Quote/Create/Void to the CIS unified schema.
 *
 * Required headers (either dash or underscore):
 *   X-NZPost-Token:  <bearer-token>
 * Optional:
 *   X-NZPost-Base:   https://api.starshipit.com  (override if using direct NZ Post)
 *
 * If you use NZ Post direct Parcel API, tweak the endpoints and the mapper functions below.
 */

// ---- Shared helpers from _lib/validate.php ----
// - parcel_total_kg(array $parcels): float

/////////////////////////////
// Small internal utilities
/////////////////////////////
function _nzp_hdr(array $h, string $name, ?string $fallback=null): ?string {
  $lk = strtolower($name);
  return $h[$lk] ?? $h[str_replace('-', '_', $lk)] ?? $fallback;
}
function _nzp_api_base(array $h): string {
  $base = rtrim((string)_nzp_hdr($h, 'x-nzpost-base', ''), '/');
  return $base !== '' ? $base : 'https://api.starshipit.com';
}
function _nzp_token(array $h): string {
  $tok = (string) (_nzp_hdr($h, 'x-nzpost-token', '') ?? '');
  if ($tok === '') throw new RuntimeException('NZP_NO_TOKEN: Provide X-NZPost-Token header');
  return $tok;
}
function _nzp_timeout(): int { return 12; }
function _nzp_retries(): int { return 2; }

function _http_json_nzp(string $method, string $url, array $headers, ?array $body=null): array {
  $ch = curl_init();
  $hdrs = [];
  foreach ($headers as $k=>$v) { $hdrs[] = $k.': '.$v; }
  curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => strtoupper($method),
    CURLOPT_HTTPHEADER     => $hdrs,
    CURLOPT_HEADER         => false,
    CURLOPT_TIMEOUT        => _nzp_timeout(),
    CURLOPT_CONNECTTIMEOUT => 8,
  ]);
  if ($body !== null) {
    $json = json_encode($body, JSON_UNESCAPED_SLASHES);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
  }

  $attempts = _nzp_retries() + 1;
  $lastErr=''; $lastCode=0; $lastBody='';
  for ($i=0; $i<$attempts; $i++) {
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($err === '' && $resp !== false && $code >= 200 && $code < 300) {
      curl_close($ch);
      $decoded = json_decode((string)$resp, true);
      if (!is_array($decoded)) throw new RuntimeException('NZP_BAD_JSON');
      return $decoded;
    }
    $lastErr=$err; $lastCode=$code; $lastBody=(string)$resp;

    if ($i < $attempts-1 && ($err !== '' || $code === 0 || $code >= 500 || $code === 429)) {
      usleep((int)(150000 * (1 + mt_rand(0, 100)/100)));
      continue;
    }
    break;
  }
  curl_close($ch);
  throw new RuntimeException("NZP_HTTP_FAIL code={$lastCode} err={$lastErr} body=".substr($lastBody,0,500));
}

///////////////////////////////////////
// Mappers: upstream → unified schema
///////////////////////////////////////
/** Map an NZP/Starshipit quote list → unified rates. */
function _nzp_map_quote_rows(array $rows): array {
  $out = [];
  foreach ($rows as $r) {
    $eta = isset($r['eta_days']) ? "+" . (int)$r['eta_days'] . " day" . (((int)$r['eta_days'])===1?'':'s') : ($r['eta'] ?? "+1 day");

    $charges = (array)($r['charges'] ?? []);
    $base    = (float)($charges['base'] ?? 0);
    $weight  = (float)($charges['weight'] ?? 0);
    $options = (float)($charges['options'] ?? 0);

    $total_incl = (float)($r['total_incl_gst'] ?? $r['total'] ?? 0);
    $gst        = (float)($charges['tax'] ?? max(0.0, round($total_incl - ($base+$weight+$options), 2)));

    $out[] = [
      "carrier"         => "nz_post",
      "service_code"    => (string)($r['service_code'] ?? 'OVERNIGHT'),
      "service_name"    => (string)($r['service_name'] ?? 'Overnight'),
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

/** Map NZP/Starshipit create response → unified create response. */
function _nzp_map_create(array $resp, string $transferId): array {
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
    "carrier"     => "nz_post",
    "tickets"     => $tickets,
    "print"       => ["appendix_slip_url" => (string)(($resp['print']['appendix'] ?? '') ?: "slips/{$transferId}-all.pdf")],
  ];
}

/////////////////////
// Live operations
/////////////////////
function nz_post_quote(array $ctx, string $container, array $parcels, array $options, array $facts, array $headers): array {
  $base = _nzp_api_base($headers);
  $tok  = _nzp_token($headers);

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

  // Adjust endpoints if you use NZ Post direct Parcel API
  $resp = _http_json_nzp('POST', $base.'/rates/quotes', [
    'Content-Type' => 'application/json',
    'Accept'       => 'application/json',
    'Authorization'=> 'Bearer '.$tok,
  ], $payload);

  $rows = (array)($resp['rates'] ?? $resp['data'] ?? $resp);
  return _nzp_map_quote_rows($rows);
}

function nz_post_create(array $ctx, array $sel, array $parcels, array $options, array $headers, string $idem=''): array {
  $base = _nzp_api_base($headers);
  $tok  = _nzp_token($headers);
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

  // Adjust endpoint if using NZ Post direct
  $resp = _http_json_nzp('POST', $base.'/shipments', $headersOut, $payload);
  return _nzp_map_create($resp, $transfer);
}

function nz_post_void(string $shipment_id, array $headers): bool {
  $base = _nzp_api_base($headers);
  $tok  = _nzp_token($headers);

  _http_json_nzp('POST', $base.'/shipments/'.rawurlencode($shipment_id).'/void', [
    'Content-Type' => 'application/json',
    'Accept'       => 'application/json',
    'Authorization'=> 'Bearer '.$tok,
  ], []);

  return true;
}
