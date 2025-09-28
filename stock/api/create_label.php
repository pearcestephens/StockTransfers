<?php
declare(strict_types=1);
/**
 * create_label.php — Unified label creation (token-aware)
 * Ensures the selected carrier has valid credentials on this request.
 */
require __DIR__.'/_lib/validate.php';
cors_and_headers(); handle_options_preflight();
$headers = require_headers(false);

try {
  $body    = read_json_body();
  $ctx     = (array)($body['context'] ?? []);
  $sel     = (array)($body['selection'] ?? []);
  $parcels = sanitize_parcels((array)($body['parcels'] ?? []));
  $options = (array)($body['options'] ?? []);
  $idem    = (string)($body['idem'] ?? '');

  if (!$sel || !($sel['carrier'] ?? null)) {
    fail("BAD_SELECTION","Missing selection");
  }

  $h = array_change_key_case($headers, CASE_LOWER);
  $carrier = strtolower((string)$sel['carrier']);

  // Presence checks per carrier (headers can be dash/underscore)
  $hasGSS = !empty($h['x-gss-token']) || !empty($h['x_gss_token']);
  $hasNZP = (!empty($h['x-nzpost-api-key']) || !empty($h['x_nzpost_api_key']))
         && (!empty($h['x-nzpost-subscription-key']) || !empty($h['x_nzpost_subscription_key']))
         && (!empty($h['x-nzpost-base']) || !empty($h['x_nzpost_base']));

  require __DIR__.'/adapters/nzc_gss.php';
  require __DIR__.'/adapters/nz_post.php';

  switch ($carrier) {
    case 'nzc':
    case 'nzc_gss':
      if (!$hasGSS) fail("MISSING_CARRIER_TOKEN","GSS/NZC token missing for create_label");
      $resp = nzc_create($ctx, $sel, $parcels, $options, $headers, $idem);
      break;

    case 'nz_post':
    case 'nzpost':
      if (!$hasNZP) fail("MISSING_CARRIER_TOKEN","NZ Post keys/base missing for create_label");
      $resp = nz_post_create($ctx, $sel, $parcels, $options, $headers, $idem);
      break;

    default:
      fail("UNKNOWN_CARRIER","Carrier not supported", ["carrier"=>$sel['carrier']]);
  }

  ok(array_merge(["ok"=>true], $resp));
} catch (Throwable $e) {
  fail("EXCEPTION", "Failed to create label", ["type"=>get_class($e), "msg"=>$e->getMessage()]);
}
