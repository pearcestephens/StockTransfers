<?php
declare(strict_types=1);
require __DIR__.'/_lib/validate.php';
cors_and_headers(); handle_options_preflight();
$headers = require_headers(false);

try {
  $body      = read_json_body();
  $ctx       = (array)($body['context'] ?? []);
  $facts     = (array)($ctx['address_facts'] ?? []);
  $container = (string)($body['container'] ?? 'box');
  $parcels   = sanitize_parcels((array)($body['parcels'] ?? []));
  $options   = (array)($body['options'] ?? []);
  $options = [
    "sig"=> isset($options['sig']) ? (bool)$options['sig'] : true,
    "atl"=> isset($options['atl']) ? (bool)$options['atl'] : false,
    "age"=> isset($options['age']) ? (bool)$options['age'] : false,
    "sat"=> isset($options['sat']) ? (bool)$options['sat'] : false,
  ];
  ensure_saturday_rules($options, $facts);

  // Decide which carriers to call based on headers present
  $h = array_change_key_case($headers, CASE_LOWER);
  $hasGSS = !empty($h['x-gss-token']) || !empty($h['x_gss_token']);
  $hasNZP = (!empty($h['x-nzpost-api-key']) || !empty($h['x_nzpost_api_key']))
         && (!empty($h['x-nzpost-subscription-key']) || !empty($h['x_nzpost_subscription_key']))
         && (!empty($h['x-nzpost-base']) || !empty($h['x_nzpost_base']));
  if (!$hasGSS && !$hasNZP) {
    fail("NO_CARRIER_CONFIG","No carrier credentials supplied on request.",[
      "need_one_of"=>[
        "GSS"=>["X-GSS-Token","(optional) X-GSS-Base"],
        "NZPost"=>["X-NZPost-Api-Key","X-NZPost-Subscription-Key","X-NZPost-Base"]
      ]
    ]);
  }

  // Defensively load adapters and validate symbol presence
  $nzc_path = __DIR__.'/adapters/nzc_gss.php';
  $nzp_path = __DIR__.'/adapters/nz_post.php';
  if ($hasGSS) {
    if (!is_file($nzc_path)) fail("ADAPTER_MISSING","Missing NZC adapter file",["path"=>$nzc_path]);
    require_once $nzc_path;
    if (!function_exists('nzc_quote')) fail("ADAPTER_LOAD_FAIL","NZC adapter did not define nzc_quote()",[
      "path"=>$nzc_path
    ]);
  }
  if ($hasNZP) {
    if (!is_file($nzp_path)) fail("ADAPTER_MISSING","Missing NZ Post adapter file",["path"=>$nzp_path]);
    require_once $nzp_path;
    if (!function_exists('nz_post_quote')) fail("ADAPTER_LOAD_FAIL","NZ Post adapter did not define nz_post_quote()",[
      "path"=>$nzp_path
    ]);
  }

  $rates = [];
  $notes = [];

  if ($hasGSS) {
    try { $rates = array_merge($rates, nzc_quote($ctx,$container,$parcels,$options,$facts,$headers)); }
    catch (Throwable $e) { $notes[] = "nzc_unavailable"; }
  }
  if ($hasNZP) {
    try { $rates = array_merge($rates, nz_post_quote($ctx,$container,$parcels,$options,$facts,$headers)); }
    catch (Throwable $e) { $notes[] = "nz_post_unavailable"; }
  }

  usort($rates, function($a,$b){
    $ta=(float)($a['total_incl_gst']??999999); $tb=(float)($b['total_incl_gst']??999999);
    return $ta===$tb ? strcmp((string)$a['carrier'],(string)$b['carrier']) : ($ta<=>$tb);
  });

  ok(["ok"=>true,"currency"=>"NZD","incl_gst"=>true,"rates"=>$rates,"notes"=>$notes]);
} catch (Throwable $e) {
  fail("EXCEPTION","Failed to quote",["type"=>get_class($e),"msg"=>$e->getMessage()]);
}
