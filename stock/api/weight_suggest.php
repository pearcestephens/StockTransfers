<?php
declare(strict_types=1);

/**
 * CIS — Weight Suggest (DB-driven, view-based; no constants)
 * Input JSON:
 * {
 *   "meta": { "transfer_id":12345, "from_outlet_id":1, "to_outlet_id":7 },
 *   "carrier": "nzc"|"nzpost",
 *   "container": "box"|"satchel",
 *   "total_kg": 12.8,                       // total content weight
 *   "prefer": { "nzc": ["E20","E40","E60"] } // optional precedence from UI; DB is the source of truth
 * }
 *
 * Output JSON (success):
 * {
 *   "ok": true,
 *   "plan": {
 *     "carrier": "nzc",
 *     "total_g": 12800,
 *     "boxes": [ {"code":"E20","name":"E20 Carton","count":1,"cap_g":25000,"tare_g":500,"content_cap_g":24500} ],
 *     "satchel": null
 *   },
 *   "notes": []
 * }
 *
 * If satchel overweight:
 * { "ok": true, "plan": { "error": "SATCHEL_OVERWEIGHT", "total_g": 3200, "max_g": 2000 } }
 */

require __DIR__.'/_lib/validate.php';
cors_and_headers([
  'allow_methods' => 'GET, POST, OPTIONS',
  'allow_headers' => 'Content-Type, X-API-Key',
  'max_age'       => 600
]);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'])) { fail('METHOD_NOT_ALLOWED','GET or POST only',405); }

// Handle both GET and POST inputs
if ($method === 'GET') {
  $transferId = (int)($_GET['transfer'] ?? 0);
  if (!$transferId) { fail('MISSING_PARAM','transfer ID required'); }
  
  // For GET requests, we'll use default values and fetch transfer data
  $in = [
    'carrier' => $_GET['carrier'] ?? 'nzc',
    'container' => $_GET['container'] ?? 'box',
    'total_kg' => (float)($_GET['total_kg'] ?? 1.0) // Default 1kg if not specified
  ];
} else {
  $in = json_input();
}
$carrier   = strtolower((string)($in['carrier'] ?? 'nzc'));
$container = strtolower((string)($in['container'] ?? 'box'));
$totalKg   = (float)($in['total_kg'] ?? 0);
if ($totalKg <= 0) { fail('MISSING_PARAM','total_kg required (>0)'); }
$total_g = (int)round($totalKg * 1000);

$pdo = pdo();

/* --- helpers --- */
function env_tare_g(): int {
  $env = getenv('CIS_OUTER_TARE_G');
  return ($env !== false && $env !== '') ? (int)$env : 500; // fallback 500 g until containers.tare_grams exists
}

/** Pull caps & container identity from views for the given carrier and an allowlist of codes (for NZC: E20,E40,E60). */
function fetch_carrier_caps(PDO $pdo, string $carrierName, array $codesAllowlist = []): array {
  $sql = "
    SELECT c.container_id,
           caps.container_code,
           c.name AS container_name,
           CAST(caps.container_cap_g AS UNSIGNED) AS cap_g
      FROM v_carrier_caps caps
      JOIN containers c ON c.code = caps.container_code
      JOIN carriers  car ON car.carrier_id = c.carrier_id
     WHERE car.name = :carrier
       AND caps.container_cap_g IS NOT NULL
  ";
  if ($codesAllowlist) {
    $in = implode(",", array_map(fn($x)=>$pdo->quote($x), $codesAllowlist));
    $sql .= " AND caps.container_code IN ($in)";
  }
  $sql .= " ORDER BY caps.container_code";
  $rows = $pdo->prepare($sql);
  $rows->execute([':carrier'=>$carrierName]);
  $out = $rows->fetchAll(PDO::FETCH_ASSOC) ?: [];
  foreach ($out as &$r) {
    // Use a column if you add it later; for now env fallback:
    $r['tare_g'] = env_tare_g();
    $r['content_cap_g'] = max(0, (int)$r['cap_g'] - (int)$r['tare_g']);
  }
  return $out;
}

/** Satchel cap rule (if you later model bags in DB, substitute here). */
function satchel_allowed_g(int $total_g): int {
  return 2000; // 2.0 kg
}

/* --- planning logic --- */
$notes = [];

/* Satchel: validate only total kg (no dims) */
if ($container === 'satchel') {
  $max_g = satchel_allowed_g($total_g);
  if ($total_g > $max_g) {
    ok(['plan' => ['error'=>'SATCHEL_OVERWEIGHT', 'total_g'=>$total_g, 'max_g'=>$max_g], 'notes'=>$notes]);
  }
  ok(['plan' => ['carrier'=>$carrier, 'total_g'=>$total_g, 'satchel' => ['total_g'=>$total_g], 'boxes'=>null], 'notes'=>$notes]);
}

/* Boxes: plan by DB caps */
if ($carrier === 'nzc') {
  // NZ Couriers carton preference: let DB be the source of truth; order E20→E40→E60 if present
  $prefer = $in['prefer']['nzc'] ?? ['E20','E40','E60'];
  $caps = fetch_carrier_caps($pdo, 'NZ Couriers', $prefer);

  // Hard guard
  if (!$caps) {
    fail('CONFIG','No NZ Couriers containers found in views (v_carrier_caps/containers).');
  }
  // reorder $caps by prefer list
  usort($caps, function($a,$b) use ($prefer){
    $ai = array_search($a['container_code'], $prefer, true);
    $bi = array_search($b['container_code'], $prefer, true);
    $ai = $ai === false ? 999 : $ai;
    $bi = $bi === false ? 999 : $bi;
    return $ai <=> $bi;
  });

  $remain = $total_g;
  $planBoxes = [];

  // Greedy by preferred carton: fill with the top option (typically E20)
  $first = $caps[0];
  $contentCap = max(1, (int)$first['content_cap_g']); // avoid /0
  $count = (int)ceil($remain / $contentCap);
  $planBoxes[] = [
    'code'          => (string)$first['container_code'],
    'name'          => (string)$first['container_name'],
    'count'         => $count,
    'cap_g'         => (int)$first['cap_g'],
    'tare_g'        => (int)$first['tare_g'],
    'content_cap_g' => (int)$first['content_cap_g']
  ];
  $remain = 0;

  ok([
    'plan' => [
      'carrier' => 'nzc',
      'total_g' => $total_g,
      'boxes'   => $planBoxes,
      'satchel' => null
    ],
    'notes' => $notes
  ]);
}

/* Default for other carriers (e.g., NZ Post): if you later store boxes, reuse the same pattern above */
ok([
  'plan' => [
    'carrier' => $carrier,
    'total_g' => $total_g,
    'boxes'   => null,   // no box catalog used for this carrier in this version
    'satchel' => null
  ],
  'notes' => $notes
]);
