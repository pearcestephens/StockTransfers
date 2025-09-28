<?php
declare(strict_types=1);

/**
 * CIS — Courier Control Tower API (Go-Live Pack)
 * _lib/validate.php — JSON I/O, CORS, headers, sanitizers, GST helpers
 */

const CIS_DEBUG = false;

function cis_env(string $key, ?string $default=null): ?string {
  $v = getenv($key);
  return $v !== false ? $v : $default;
}

function cors_and_headers(): void {
  $origins = array_filter(array_map('trim', explode(',', cis_env('CIS_CORS_ORIGINS', '*'))));
  $origin  = $_SERVER['HTTP_ORIGIN'] ?? '*';
  $allow   = in_array('*', $origins, true) ? '*' : (in_array($origin, $origins, true) ? $origin : 'null');

  header('Access-Control-Allow-Origin: '.$allow);
  header('Vary: Origin');
  header('Access-Control-Allow-Credentials: true');
  // Added carrier auth headers here:
  header('Access-Control-Allow-Headers: Content-Type, X-API-Key, X-Transfer-ID, X-From-Outlet-ID, X-To-Outlet-ID, X_Transfer_ID, X_From_Outlet_ID, X_To_Outlet_ID, X-GSS-Token, X-GSS-Base, X-NZPost-Api-Key, X-NZPost-Subscription-Key, X-NZPost-Base');
  header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
  header('Content-Type: application/json; charset=UTF-8');
}

function handle_options_preflight(): void {
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    echo '';
    exit;
  }
}

function get_all_headers_tolerant(): array {
  $out = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
  foreach ($_SERVER as $k => $v) {
    if (strpos($k, 'HTTP_') === 0) {
      $name = str_replace('_', '-', substr($k, 5));
      $out[$name] = $v;
    }
  }
  $norm = [];
  foreach ($out as $k => $v) {
    $lk = strtolower($k);
    $norm[$lk] = $v;
    $norm[str_replace('-', '_', $lk)] = $v;
  }
  return $norm;
}

function require_headers(bool $require_api_key=false): array {
  $h = get_all_headers_tolerant();
  if ($require_api_key && cis_env('CIS_API_KEY')) {
    $key = $h['x-api-key'] ?? $h['x_api_key'] ?? null;
    if (!$key || $key !== cis_env('CIS_API_KEY')) {
      http_response_code(401);
      echo json_encode(["ok"=>false,"error"=>"UNAUTH","message"=>"Missing or invalid API key"], JSON_UNESCAPED_SLASHES);
      exit;
    }
  }
  // For POST endpoints, enforce context headers; for GET we’re tolerant.
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    foreach (['x-transfer-id','x_transfer_id','x-from-outlet-id','x_from_outlet_id','x-to-outlet-id','x_to_outlet_id'] as $rk) {
      if (!isset($h[$rk])) {
        http_response_code(400);
        echo json_encode(["ok"=>false,"error"=>"MISSING_HEADER","message"=>"Required header missing: {$rk}"], JSON_UNESCAPED_SLASHES);
        exit;
      }
    }
  }
  return $h;
}

function read_json_body(int $maxBytes=1572864): array {
  $meth = $_SERVER['REQUEST_METHOD'] ?? 'GET';
  if ($meth !== 'POST') return [];
  $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
  if ($len > $maxBytes) {
    http_response_code(413);
    echo json_encode(["ok"=>false,"error"=>"BODY_TOO_LARGE","message"=>"Payload exceeds limit"], JSON_UNESCAPED_SLASHES);
    exit;
  }
  $raw = file_get_contents('php://input') ?: '';
  $data = json_decode($raw, true);
  if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(["ok"=>false,"error"=>"BAD_JSON","message"=>"Invalid JSON body"], JSON_UNESCAPED_SLASHES);
    exit;
  }
  return $data;
}

function sanitize_bool($v): bool { return filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false; }

function sanitize_parcels(array $parcels): array {
  $out = [];
  foreach ($parcels as $p) {
    $out[] = [
      "id"   => (string)($p["id"] ?? ""),
      "l_cm" => max(0.0, (float)($p["l_cm"] ?? 0)),
      "w_cm" => max(0.0, (float)($p["w_cm"] ?? 0)),
      "h_cm" => max(0.0, (float)($p["h_cm"] ?? 0)),
      "kg"   => max(0.0, (float)($p["kg"] ?? 0)),
      "items"=> max(0,   (int)($p["items"] ?? 0)),
    ];
  }
  return $out;
}

function parcel_total_kg(array $parcels): float {
  $sum = 0.0;
  foreach ($parcels as $p) $sum += (float)($p['kg'] ?? 0);
  return round($sum, 4);
}

function gst_parts_from_incl(float $incl, float $rate=0.15): array {
  $gst = round($incl * $rate / (1+$rate), 2);
  $ex  = round($incl - $gst, 2);
  return ["excl"=>$ex, "gst"=>$gst, "incl"=>$incl];
}

function ensure_saturday_rules(array &$options, array $facts): void {
  if (isset($facts['saturday_serviceable']) && !$facts['saturday_serviceable']) {
    $options['sat'] = false;
  }
}

function ok(array $data): void {
  http_response_code(200);
  echo json_encode($data, JSON_UNESCAPED_SLASHES);
  exit;
}
function fail(string $code, string $msg, array $details=[]): void {
  http_response_code(400);
  echo json_encode(["ok"=>false,"error"=>$code,"message"=>$msg,"details"=>$details], JSON_UNESCAPED_SLASHES);
  exit;
}
