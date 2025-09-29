<?php
declare(strict_types=1);

/**
 * CIS — Live Rates (No Simulation) + DB-driven Plan
 * Input JSON: { meta, container, packages? | satchel?, options, address_facts, carriers_enabled }
 * Output JSON: { ok:true, rates:[...], plan:{...} }
 */

require __DIR__.'/_lib/validate.php';

cors_and_headers([
  'allow_methods' => 'POST, OPTIONS',
  'allow_headers' => 'Content-Type, X-API-Key, X-From-Outlet-ID, X-To-Outlet-ID, X-NZPost-Token, X-NZPost-Subscription, X-GSS-Token',
  'max_age'       => 600
]);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST')     { fail('METHOD_NOT_ALLOWED','POST only',405); }

try {
  $in      = json_input();

  // ---------------- Parse input (once) ----------------
  $meta      = (array)($in['meta'] ?? []);
  $container = (string)($in['container'] ?? 'box');               // 'box' | 'satchel'
  $pkgs      = (array)($in['packages'] ?? []);                    // only required for box
  $satchel   = (array)($in['satchel'] ?? []);                     // { total_kg, bag_code?, bag_name? }
  $opt       = (array)($in['options'] ?? []);
  $facts     = (array)($in['address_facts'] ?? []);
  $allow     = (array)($in['carriers_enabled'] ?? []);

  // Accept either legacy numeric outlet id (>0) OR UUID string; treat 0 / '' as empty.
  $fromLegacy  = isset($meta['from_outlet_id']) ? (int)$meta['from_outlet_id'] : 0;
  $toLegacy    = isset($meta['to_outlet_id'])   ? (int)$meta['to_outlet_id']   : 0;
  $fromUuid    = trim((string)($meta['from_outlet_uuid'] ?? ''));
  $toUuid      = trim((string)($meta['to_outlet_uuid']   ?? ''));
  $transferId  = (int)($meta['transfer_id'] ?? 0);

  $fromOutletId = '';
  $toOutletId   = '';
  $fromMode = '';
  $toMode   = '';
  if ($fromUuid !== '') { $fromOutletId = $fromUuid; $fromMode='uuid'; }
  elseif ($fromLegacy > 0) { $fromOutletId = (string)$fromLegacy; $fromMode='legacy_id'; }
  if ($toUuid !== '') { $toOutletId = $toUuid; $toMode='uuid'; }
  elseif ($toLegacy > 0) { $toOutletId = (string)$toLegacy; $toMode='legacy_id'; }

  $warnings = [];

  // Fallback: attempt to resolve from transfer record if both missing & transfer_id provided
  if ($transferId > 0 && ($fromOutletId === '' || $toOutletId === '')) {
    try {
      $pdoTmp = pdo();
      $queries = [
        'SELECT from_outlet_id, to_outlet_id, from_outlet_uuid, to_outlet_uuid FROM stock_transfers WHERE transfer_id = :id LIMIT 1',
        'SELECT from_outlet_id, to_outlet_id, from_outlet_uuid, to_outlet_uuid FROM transfers WHERE transfer_id = :id LIMIT 1',
        'SELECT from_outlet_id, to_outlet_id, from_outlet_uuid, to_outlet_uuid FROM transfers WHERE id = :id LIMIT 1'
      ];
      foreach ($queries as $sqlQ) {
        try {
          $stq = $pdoTmp->prepare($sqlQ);
          $stq->execute([':id'=>$transferId]);
          if ($row = $stq->fetch(PDO::FETCH_ASSOC)) {
            if ($fromOutletId === '') {
              if (!empty($row['from_outlet_uuid'])) { $fromOutletId = (string)$row['from_outlet_uuid']; $fromMode='uuid_fallback'; }
              elseif (!empty($row['from_outlet_id']) && (int)$row['from_outlet_id']>0) { $fromOutletId=(string)(int)$row['from_outlet_id']; $fromMode='legacy_fallback'; }
            }
            if ($toOutletId === '') {
              if (!empty($row['to_outlet_uuid'])) { $toOutletId = (string)$row['to_outlet_uuid']; $toMode='uuid_fallback'; }
              elseif (!empty($row['to_outlet_id']) && (int)$row['to_outlet_id']>0) { $toOutletId=(string)(int)$row['to_outlet_id']; $toMode='legacy_fallback'; }
            }
            if ($fromOutletId !== '' && $toOutletId !== '') break;
          }
        } catch (Throwable $ignore) { /* try next */ }
      }
    } catch (Throwable $ignoreOuter) { /* ignore fallback errors */ }
  }

  if ($fromOutletId === '') { $warnings[] = 'MISSING_FROM_OUTLET'; }
  if ($toOutletId   === '') { $warnings[] = 'MISSING_TO_OUTLET'; }
  // If still missing, downgrade to warning (200) instead of 400 per new requirement.

  // satchel: no dims required; box: packages are required
  if ($container === 'box' && !$pkgs) {
    fail('MISSING_PARAM','packages required for container=box');
  }

  if ($warnings) {
    ok(['rates'=>[], 'plan'=>['warnings'=>$warnings, 'note'=>'Outlet(s) unresolved; supply valid IDs or UUIDs.'], 'meta'=>['transfer_id'=>$transferId, 'from_mode'=>$fromMode, 'to_mode'=>$toMode]]);
  }

  // total content weight in grams
  $total_g = 0;
  if ($container === 'satchel' && isset($satchel['total_kg'])) {
    $total_g = (int)round(1000 * (float)$satchel['total_kg']);
  } else {
    foreach ($pkgs as $p) { $total_g += (int)round(1000 * (float)($p['kg'] ?? 0)); }
  }

  $pdo = pdo();

  // ---------------- Credentials (headers first, DB fallback) ----------------
  $hdr = static fn(string $k): string => (string)($_SERVER['HTTP_'.strtoupper(str_replace('-','_',$k))] ?? '');

  $creds = outlet_carrier_creds($pdo, $fromOutletId); // nz_post_api_key, nz_post_subscription_key, gss_token
  $tokens = [
    'nz_post_api_key'      => $hdr('X-NZPost-Token')        ?: (string)($creds['nz_post_api_key'] ?? ''),
    'nz_post_subscription' => $hdr('X-NZPost-Subscription') ?: (string)($creds['nz_post_subscription_key'] ?? ''),
    'gss_token'            => $hdr('X-GSS-Token')           ?: (string)($creds['gss_token'] ?? ''),
  ];

  // ---------------- Build common request to adapters ----------------
  $req = [
    'from_outlet_id' => $fromOutletId,
    'to_outlet_id'   => $toOutletId,
    'packages'       => array_map(static function($p){
      return [
        'name'=>(string)($p['name'] ?? ''),
        'w'   =>(float)($p['w'] ?? 0),
        'l'   =>(float)($p['l'] ?? 0),
        'h'   =>(float)($p['h'] ?? 0),
        'kg'  =>(float)($p['kg'] ?? 0),
        'items'=>(int)($p['items'] ?? 0),
      ];
    }, $pkgs),
    'options'        => [
      'sig'=>!empty($opt['sig']),
      'atl'=>!empty($opt['atl']),
      'age'=>!empty($opt['age']),
      'sat'=>!empty($opt['sat']),
    ],
    'facts'          => [
      'rural'=>!empty($facts['rural']),
      'saturday_serviceable'=>!empty($facts['saturday_serviceable']),
    ],
    // satchel hint for adapters (optional)
    'satchel'        => $container === 'satchel' ? [
      'total_kg'  => (float)($satchel['total_kg'] ?? ($total_g/1000)),
      'bag_code'  => $satchel['bag_code'] ?? null,
      'bag_name'  => $satchel['bag_name'] ?? null
    ] : null
  ];

  // ---------------- Plan (DB-driven) ----------------
  $plan = null;

  if ($container === 'satchel') {
    if (!satchel_allowed_total_g($total_g)) {
      ok(['rates'=>[], 'plan'=>['error'=>'SATCHEL_OVERWEIGHT', 'total_g'=>$total_g, 'max_g'=>2000]]);
    }
    // leave $plan as null or include a simple satchel echo if you wish
  } else {
    try {
      $plan = ['nzc' => plan_nzc_mix_by_weight($pdo, $total_g)];
    } catch (Throwable $e) {
      $plan = ['nzc' => [], 'warning' => 'UNABLE_TO_PLAN_NZC: '.$e->getMessage()];
    }
  }

  // ---------------- Live rates ----------------
  $rows = [];

  // NZ Post
  if (!empty($allow['nzpost']) && ($tokens['nz_post_api_key'] || $tokens['nz_post_subscription'])) {
    try {
      $nzp = adapter_nzpost_rates($req, $tokens);
      foreach ($nzp as $q) {
        $rows[] = [
          'carrier_code' => 'nzpost',
          'carrier_name' => $q['carrier_name'] ?? 'NZ Post',
          'service_code' => $q['service_code'] ?? '',
          'service_name' => $q['service_name'] ?? '',
          'package_code' => $q['package_code'] ?? null,
          'package_name' => $q['package_name'] ?? null,
          'eta'          => $q['eta'] ?? '',
          'total'        => (float)($q['total_incl_gst'] ?? 0.0),
          'incl_gst'     => true
        ];
      }
    } catch (Throwable $e) {
      // optionally audit
    }
  }

  // NZ Couriers (GSS)
  if (!empty($allow['nzc']) && $tokens['gss_token']) {
    try {
      // Optionally pass NZC carton plan hint for package type selection:
      if (!empty($plan['nzc'][0]['code'])) {
        $req['nzc_cartons'] = $plan['nzc']; // e.g., [{code:'E20', count:5}, ...]
      }
      $gss = adapter_gss_rates($req, $tokens);
      foreach ($gss as $q) {
        $rows[] = [
          'carrier_code' => 'nzc',
          'carrier_name' => $q['carrier_name'] ?? 'NZ Couriers',
          'service_code' => $q['service_code'] ?? '',
          'service_name' => $q['service_name'] ?? '',
          'package_code' => $q['package_code'] ?? null,
          'package_name' => $q['package_name'] ?? null,
          'eta'          => $q['eta'] ?? '',
          'total'        => (float)($q['total_incl_gst'] ?? 0.0),
          'incl_gst'     => true
        ];
      }
    } catch (Throwable $e) {
      // optionally audit
    }
  }

  usort($rows, static fn($a,$b)=> ($a['total'] <=> $b['total']));

  // ---------------- One envelope out ----------------
  ok(['rates'=>$rows, 'plan'=>$plan, 'meta'=>['from_mode'=>$fromMode, 'to_mode'=>$toMode, 'transfer_id'=>$transferId]]);

} catch (Throwable $e) {
  // keep envelope-style even on internal errors (per your guide)
  fail('INTERNAL_ERROR', $e->getMessage(), [
    'exception' => get_class($e),
    'time'      => gmdate('c')
  ]);
}

/* ================= Helpers & Adapters ================= */

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

/* ===== Box/Satchel planning helpers (DB-driven) ===== */

function resolve_tare_grams(PDO $pdo, int $containerId): int {
  // If you add containers.tare_grams, uncomment to fetch per-container tare.
  // $st = $pdo->prepare("SELECT tare_grams FROM containers WHERE container_id = :id LIMIT 1");
  // $st->execute([':id'=>$containerId]);
  // $v = $st->fetchColumn();
  // if ($v !== false && $v !== null) return (int)$v;

  $env = getenv('CIS_OUTER_TARE_G');
  return $env !== false && $env !== '' ? (int)$env : 500; // TEMP site-wide default
}

/** Get NZC containers (E20/E40/E60) with shipping cap g from your views. */
function fetch_nzc_containers(PDO $pdo): array {
  $sql = "
    SELECT c.container_id,
           caps.container_code,
           c.name AS container_name,
           CAST(caps.container_cap_g AS UNSIGNED) AS cap_g
      FROM v_carrier_caps caps
      JOIN containers c ON c.code = caps.container_code
      JOIN carriers  car ON car.carrier_id = c.carrier_id
     WHERE car.name = 'NZ Couriers'
       AND caps.container_code IN ('E20','E40','E60')
     ORDER BY FIELD(caps.container_code,'E20','E40','E60')
  ";
  $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
  foreach ($rows as &$r) {
    $r['tare_g']        = resolve_tare_grams($pdo, (int)$r['container_id']);
    $r['content_cap_g'] = max(0, (int)$r['cap_g'] - (int)$r['tare_g']);
  }
  return $rows;
}

/** Plan a best-fit mix by weight for NZC cartons (E20 default, DB caps). */
function plan_nzc_mix_by_weight(PDO $pdo, int $total_g): array {
  $containers = fetch_nzc_containers($pdo); // E20 → E40 → E60
  if (!$containers) return [];
  $pref = $containers[0];
  $content_cap_g = max(1, (int)$pref['content_cap_g']);
  $boxes = (int)ceil(max(0,$total_g) / $content_cap_g);
  return [['code'=>$pref['container_code'], 'name'=>$pref['container_name'], 'count'=>$boxes]];
}

/** Satchel cap rule (2.0 kg). Swap to DB if you model satchels there. */
function satchel_allowed_total_g(int $total_g): bool {
  return $total_g <= 2000;
}

/* ---- Adapters (stubs; wire real endpoints in your adapters) ---- */

function adapter_nzpost_rates(array $req, array $tokens): array {
  // Implement via Starshipit/eShip; return array of normalized rows.
  return [];
}
function adapter_gss_rates(array $req, array $tokens): array {
  // Implement via GSS; return array of normalized rows.
  return [];
}
