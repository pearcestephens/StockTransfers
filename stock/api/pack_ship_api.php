<?php
declare(strict_types=1);

/**
 * File: modules/transfers/stock/api/pack_ship_api.php
 * CIS — Courier Control Tower API (Hardened, outlet-aware, idempotent)
 *
 * Purpose
 *  - JSON-only API for shipping workflows (Courier / Pickup / Internal / Drop-off)
 *  - Multi-carrier abstraction (NZ Post, NZ Couriers) with unified response schema
 *  - Actions: carriers, rules, health, rates, reserve, create, void, expired, track,
 *             audit, history, history_csv, bulk_print, tracking_events
 *
 * Security & Resilience
 *  - CORS allow-list via CIS_CORS_ORIGINS (comma-separated) or "*" for any
 *  - Optional API key in X-API-Key (enforced if CIS_API_KEY is set)
 *  - Soft APCu rate limiting per IP (+staff) — 180 req/min
 *  - Strict verb rules (POST for mutating)
 *  - 1.5MB JSON body cap; robust JSON decode
 *  - Idempotency via X-Idempotency-Key (APCu cache) for reserve/create (1h)
 *  - Centralized error envelopes with HTTP codes
 *
 * Integration Notes
 *  - POST/GET with ?action=<action>
 *  - JSON body for POST actions; request/response are always application/json
 *  - Outlet-specific credentials are auto-resolved from `vend_outlets` based on:
 *      1) transfer_id -> transfers.outlet_from
 *      2) outlet_from_id (direct parameter)
 *      3) fallback to ENV
 *
 * Assumptions
 *  - app.php provides session/bootstrap and cis_pdo() or $GLOBALS['pdo']
 *  - ShippingLabelsService exists at ../services/ShippingLabelsService.php
 */

require_once $_SERVER['DOCUMENT_ROOT'].'/modules/transfers/_local_shims.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/app.php'; // Sessions, config, autoload

// Map legacy session key userID -> staff_id (compat)
if (empty($_SESSION['staff_id']) && !empty($_SESSION['userID'])) {
    $_SESSION['staff_id'] = (int)$_SESSION['userID'];
}

require_once __DIR__ . '/../services/ShippingLabelsService.php';
use Modules\Transfers\Stock\Services\ShippingLabelsService;

// ----------------------------- Small Helpers ------------------------------
function ps_db(): PDO {
    if (function_exists('cis_pdo')) {
        $db = cis_pdo();
        if ($db instanceof PDO) return $db;
    }
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        return $GLOBALS['pdo'];
    }
    throw new RuntimeException('Database connection not available');
}

function ps_now_iso(): string { return date('c'); }

// ------------------------------ CORS (strict) ------------------------------
$reqOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowlist = trim((string)getenv('CIS_CORS_ORIGINS') ?: '');
$originToSend = 'null';
if ($allowlist === '*') {
    $originToSend = $reqOrigin ?: '*';
} elseif ($allowlist !== '') {
    $allowed = array_filter(array_map('trim', explode(',', $allowlist)));
    if ($reqOrigin && in_array($reqOrigin, $allowed, true)) {
        $originToSend = $reqOrigin;
    }
}
header('Vary: Origin');
header('Access-Control-Allow-Origin: ' . $originToSend);
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, X-Request-Id, X-Idempotency-Key');
header('Access-Control-Max-Age: 600');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

// ------------------------------ Request Parse ------------------------------
const PACK_SHIP_MAX_JSON_BYTES = 1_500_000; // ~1.5MB
$raw = file_get_contents('php://input');
if ($raw !== false && strlen($raw) > PACK_SHIP_MAX_JSON_BYTES) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => ['code' => 'payload_too_large', 'msg' => 'JSON body too large']]);
    exit;
}
$data = [];
if ($raw !== false && $raw !== '') {
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = [];
}
$action = (string)($_GET['action'] ?? ($data['action'] ?? ''));

// --------------------------- Auth / Verb Rules -----------------------------
$mutatingActions = ['rates','reserve','create','void','track','audit','bulk_print','tracking_events'];
$requiresPost    = ['rates','reserve','create','void','track','audit','bulk_print','tracking_events','history','history_csv']; // history endpoints accept POST for filters

if ($action !== '' && in_array($action, $requiresPost, true) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => ['code' => 'method_not_allowed', 'msg' => 'Use POST for this action']]);
    exit;
}

// Only allow mutating operations if staff logged in
if (in_array($action, ['reserve','create','void'], true)) {
    if (empty($_SESSION['staff_id'])) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => ['code' => 'login_required', 'msg' => 'Authentication required']]);
        exit;
    }
}

// Optional API key (service-to-service)
$REQUIRED_API_KEY = getenv('CIS_API_KEY') ?: '';
if ($REQUIRED_API_KEY !== '') {
    $got = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!hash_equals($REQUIRED_API_KEY, $got)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => ['code' => 'unauthorized', 'msg' => 'Invalid API key']]);
        exit;
    }
}

// ------------------------------ Rate Limiting ------------------------------
if (function_exists('apcu_fetch') && function_exists('apcu_store')) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $staff = (int)($_SESSION['staff_id'] ?? 0);
    $k = 'rate:pack_ship:' . $ip . ':' . $staff;
    $now = time();
    $bucket = apcu_fetch($k);
    if (!$bucket || !is_array($bucket) || ($now - ($bucket['t'] ?? 0) >= 60)) {
        $bucket = ['t' => $now, 'c' => 0];
    }
    $bucket['c'] += 1;
    apcu_store($k, $bucket, 120);
    $limit = 180;
    header('X-RateLimit-Limit: ' . $limit);
    header('X-RateLimit-Remaining: ' . max(0, $limit - (int)$bucket['c']));
    if ($bucket['c'] > $limit) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => ['code' => 'rate_limited', 'msg' => 'Too many requests']]);
        exit;
    }
}

// ------------------------------ Idempotency -------------------------------
$IDEMPOTENCY_KEY = (string)($_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? ($data['idempotency_key'] ?? ''));
$IDEMPOTENCY_CACHE_KEY = '';
if ($IDEMPOTENCY_KEY !== '' && in_array($action, ['reserve','create'], true)) {
    $IDEMPOTENCY_CACHE_KEY = 'idem:pack_ship:' . $action . ':' . hash('sha256', $IDEMPOTENCY_KEY);
    if (function_exists('apcu_fetch')) {
        $cached = apcu_fetch($IDEMPOTENCY_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            http_response_code(200);
            echo $cached;
            exit;
        }
    }
}

// ----------------------------- Errors & Logging ---------------------------
final class PackShipApiError extends \Exception {
    public function __construct(private string $codeStr, string $msg, int $http = 400) {
        parent::__construct($msg, $http);
    }
    public function code(): string { return $this->codeStr; }
}
function pack_ship_out(array $payload, int $http = 200, ?string $idemCacheKey = null): void {
    http_response_code($http);
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($idemCacheKey && function_exists('apcu_store')) {
        apcu_store($idemCacheKey, $json, 3600); // 1 hour
    }
    echo $json;
    exit;
}
function pack_ship_log(string $line): void { error_log('[pack_ship_api] ' . $line); }

// ---------------------- Outlet-Aware Shipping Config ----------------------
function pack_ship_get_outlet_config(int $outletFromId): array {
    try {
        $db = ps_db();
        $st = $db->prepare(
            'SELECT id, name, nz_post_api_key, nz_post_subscription_key, gss_token,
                    courier_account_number, address1, address2, city, region, postcode
             FROM vend_outlets WHERE id = :id LIMIT 1'
        );
        $st->execute(['id' => $outletFromId]);
        $outlet = $st->fetch(PDO::FETCH_ASSOC);

        if (!$outlet) {
            pack_ship_log("Outlet $outletFromId not found, using fallback config");
            return pack_ship_get_default_config();
        }

        return [
            'outlet' => $outlet,
            'rules' => getenv('CIS_RULES') ?: 'cheapest',
            'dim_factor' => (float)(getenv('CIS_DIM_FACTOR') ?: '5000'),
            'carriers' => [
                'nz_post' => [
                    'name'    => 'NZ Post',
                    'color'   => '#3b82f6',
                    'enabled' => !empty($outlet['nz_post_api_key']) && !empty($outlet['nz_post_subscription_key']),
                    'mode'    => getenv('NZPOST_MODE') ?: (!empty($outlet['nz_post_api_key']) ? 'live' : 'simulate'),
                    'base'    => getenv('NZPOST_BASE') ?: 'https://ship.nzpost.co.nz/api/v1',
                    'keys'    => [
                        'api_key'          => (string)$outlet['nz_post_api_key'],
                        'subscription_key' => (string)$outlet['nz_post_subscription_key'],
                        'account_number'   => (string)($outlet['courier_account_number'] ?? ''),
                    ],
                ],
                'nzc' => [
                    'name'    => 'NZ Couriers',
                    'color'   => '#06b6d4',
                    'enabled' => !empty($outlet['gss_token']),
                    'mode'    => getenv('NZC_MODE') ?: (!empty($outlet['gss_token']) ? 'live' : 'simulate'),
                    'base'    => getenv('NZC_BASE') ?: 'https://api.nzcouriers.co.nz/v1',
                    'keys'    => [
                        'api_key'        => (string)$outlet['gss_token'],
                        'account_number' => (string)($outlet['courier_account_number'] ?? ''),
                    ],
                ],
            ],
        ];
    } catch (Throwable $e) {
        pack_ship_log("Error getting outlet config: {$e->getMessage()}");
        return pack_ship_get_default_config();
    }
}

function pack_ship_get_default_config(): array {
    return [
        'outlet' => null,
        'rules' => getenv('CIS_RULES') ?: 'cheapest',
        'dim_factor' => (float)(getenv('CIS_DIM_FACTOR') ?: '5000'),
        'carriers' => [
            'nz_post' => [
                'name'    => 'NZ Post',
                'color'   => '#3b82f6',
                'enabled' => (getenv('NZPOST_ENABLED') ?: '0') === '1',
                'mode'    => getenv('NZPOST_MODE') ?: 'simulate',
                'base'    => getenv('NZPOST_BASE') ?: 'https://ship.nzpost.co.nz/api/v1',
                'keys'    => [
                    'api_key'          => getenv('NZPOST_API_KEY') ?: '',
                    'subscription_key' => getenv('NZPOST_SUBSCRIPTION_KEY') ?: '',
                    'account_number'   => getenv('NZPOST_ACCOUNT_NUMBER') ?: '',
                ],
            ],
            'nzc' => [
                'name'    => 'NZ Couriers',
                'color'   => '#06b6d4',
                'enabled' => (getenv('NZC_ENABLED') ?: '0') === '1',
                'mode'    => getenv('NZC_MODE') ?: 'simulate',
                'base'    => getenv('NZC_BASE') ?: 'https://api.nzcouriers.co.nz/v1',
                'keys'    => [
                    'api_key'        => getenv('NZC_API_KEY') ?: '',
                    'account_number' => getenv('NZC_ACCOUNT_NUMBER') ?: '',
                ],
            ],
        ],
    ];
}

// ----------------------------- HTTP Transport -----------------------------
final class PackShipHttpClient {
    public static function request(
        string $method, string $url, array $headers = [], ?string $body = null,
        int $timeout = 20, int $retries = 2
    ): array {
        $attempt = 0; $lastErr = null;
        while ($attempt <= $retries) {
            $attempt++;
            $ch = curl_init();
            $opts = [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => strtoupper($method),
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_HTTPHEADER => self::normalizeHeaders($headers),
            ];
            if ($body !== null) { $opts[CURLOPT_POSTFIELDS] = $body; }
            curl_setopt_array($ch, $opts);
            $respBody = curl_exec($ch);
            $status = (int)(curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: 0);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($curlErr) { $lastErr = $curlErr; }
            if ($status >= 200 && $status < 300 && $respBody !== false) {
                $decoded = json_decode((string)$respBody, true);
                return ['status' => $status, 'body' => ($decoded ?? $respBody)];
            }
            if ($status >= 500 || $status === 0) {
                usleep(120000 * $attempt);
                continue;
            }
            return ['status' => $status, 'body' => $respBody];
        }
        return ['status' => 0, 'body' => ['error' => $lastErr ?: 'network_error']];
    }

    private static function normalizeHeaders(array $headers): array {
        $out = []; $hasCT = false; $hasUA = false;
        foreach ($headers as $k => $v) {
            if (is_int($k)) { $out[] = $v; continue; }
            if (strtolower((string)$k) === 'content-type') $hasCT = true;
            if (strtolower((string)$k) === 'user-agent')  $hasUA = true;
            $out[] = $k . ': ' . $v;
        }
        if (!$hasCT) $out[] = 'Content-Type: application/json';
        if (!$hasUA) $out[] = 'User-Agent: CIS-PackShip/1.0';
        return $out;
    }
}

// ------------------------------ Domain Utils ------------------------------
final class PackShipDomain {
    public static function sanitizePackages(array $in): array {
        $out = [];
        foreach ($in as $p) {
            $l = max(1, (int)($p['l'] ?? $p['length'] ?? 0));
            $w = max(1, (int)($p['w'] ?? $p['width']  ?? 0));
            $h = max(1, (int)($p['h'] ?? $p['height'] ?? 0));
            $kg = max(0.01, (float)($p['kg'] ?? $p['weight'] ?? 0));
            $items = max(0, (int)($p['items'] ?? 0));
            $out[] = ['l' => $l, 'w' => $w, 'h' => $h, 'kg' => $kg, 'items' => $items];
        }
        return $out;
    }
    public static function sanitizeOptions(array $o): array {
        return ['sig' => !empty($o['sig']), 'atl' => !empty($o['atl']), 'age' => !empty($o['age'])];
    }
    public static function sanitizeContext(array $c): array {
        return [
            'from'     => (string)($c['from'] ?? ''),
            'to'       => (string)($c['to'] ?? ''),
            'declared' => max(0, (float)($c['declared'] ?? 0)),
            'rural'    => !empty($c['rural']),
            'saturday' => !empty($c['saturday']),
        ];
    }
    public static function volumetricKg(array $pkg, float $dimFactor): float {
        return max($pkg['kg'], ($pkg['l'] * $pkg['w'] * $pkg['h']) / $dimFactor);
    }
    public static function strategySort(string $strategy, array &$results): void {
        usort($results, function ($a, $b) use ($strategy) {
            $costCmp = ($a['total'] <=> $b['total']);
            if ($strategy === 'fastest') {
                $etaCmp = self::etaRank($a['eta']) <=> self::etaRank($b['eta']);
                return $etaCmp !== 0 ? $etaCmp : $costCmp;
            }
            if ($strategy === 'balanced') {
                $aw = ($a['total'] * 0.7) + (self::etaRank($a['eta']) * 0.3);
                $bw = ($b['total'] * 0.7) + (self::etaRank($b['eta']) * 0.3);
                return $aw <=> $bw;
            }
            return $costCmp; // cheapest
        });
    }
    private static function etaRank(string $eta): int {
        $e = strtolower($eta);
        if (str_contains($e, 'tomorrow')) return 0;
        if (str_contains($e, '+1 day'))   return 1;
        if (str_contains($e, 'sat'))      return 2;
        return 9;
    }
}

// --------------------------- Carrier Abstraction --------------------------
abstract class PackShipCarrierAdapter {
    protected array $cfg;
    public function __construct(array $cfg){ $this->cfg = $cfg; }
    abstract public function rates(array $packages, array $options, array $context, float $dimFactor): array;
    abstract public function reserve(array $payload): array;
    abstract public function create(array $payload): array;
    abstract public function void(string $labelId): array;
    abstract public function expired(): array;
    abstract public function track(string $tracking): array;

    protected function row(string $carrier, string $service, string $serviceName, string $eta, float $total, array $breakdown): array {
        return [
            'carrier'      => $carrier,
            'carrier_name' => $this->cfg['name'] ?? strtoupper($carrier),
            'service'      => $service,
            'service_name' => $serviceName,
            'eta'          => $eta,
            'total'        => round($total, 2),
            'breakdown'    => $breakdown,
            'color'        => $this->cfg['color'] ?? '#666666',
        ];
    }
}

// ------------------------------ NZ Post -----------------------------------
final class PackShipNZPost extends PackShipCarrierAdapter {
    public function rates(array $packages, array $options, array $context, float $dimFactor): array {
        $mode = $this->cfg['mode'] ?? 'simulate';
        $hasKeys = !empty($this->cfg['keys']['api_key']) && !empty($this->cfg['keys']['subscription_key']);

        // TODO: if ($mode==='live' && $hasKeys) { perform real API call; map response }

        $kg = array_sum(array_map(fn($p) => PackShipDomain::volumetricKg($p, $dimFactor), $packages));
        $rural = $context['rural'] ? 1.5 : 0;
        $sat   = $context['saturday'] ? 2.0 : 0;
        $sig   = $options['sig'] ? 0.3 : 0;
        $age   = $options['age'] ? 0.8 : 0;

        $overnight = 4.2 + 1.15 * $kg + $rural + $sat + $sig + $age;
        $economy   = 3.6 + 0.95 * $kg + ($rural * 0.8) + ($sat * 0.75) + $sig + $age;

        return [
            $this->row('nz_post', 'overnight', 'Overnight', 'ETA Tomorrow', $overnight, [
                'base' => 4.2, 'perkg' => 1.15 * $kg, 'opts' => $rural + $sat + $sig + $age
            ]),
            $this->row('nz_post', 'economy', 'Economy', 'ETA +2 days', $economy, [
                'base' => 3.6, 'perkg' => 0.95 * $kg, 'opts' => ($rural * 0.8) + ($sat * 0.75) + $sig + $age
            ]),
        ];
    }
    public function reserve(array $payload): array {
        return [
            'reservation_id' => uniqid('np_res_'),
            'number'         => 'NZX' . strtoupper(bin2hex(random_bytes(4))),
        ];
    }
    public function create(array $payload): array {
        return [
            'label_id'        => uniqid('np_lbl_'),
            'tracking_number' => 'NZX' . strtoupper(bin2hex(random_bytes(5))),
            'url'             => '/labels/' . uniqid('np_') . '.pdf',
        ];
    }
    public function void(string $labelId): array { return ['voided' => true, 'label_id' => $labelId]; }
    public function expired(): array {
        return [[
            'carrier'  => 'NZ Post',
            'type'     => 'Track #',
            'number'   => 'NZX123456789',
            'reserved' => date('Y-m-d H:i', strtotime('-6 days')),
            'expires'  => date('Y-m-d H:i'),
        ]];
    }
    public function track(string $tracking): array {
        return ['tracking' => $tracking, 'events' => [['ts' => ps_now_iso(), 'desc' => 'In transit']]];
    }
}

// --------------------------- NZ Couriers (GSS) ----------------------------
final class PackShipNZCouriers extends PackShipCarrierAdapter {
    public function rates(array $packages, array $options, array $context, float $dimFactor): array {
        // TODO: live/test mapping via GSS token when mode!=='simulate'
        $kg = array_sum(array_map(fn($p) => PackShipDomain::volumetricKg($p, $dimFactor), $packages));
        $rural = $context['rural'] ? 1.3 : 0;
        $sat   = $context['saturday'] ? 1.8 : 0;
        $sig   = $options['sig'] ? 0.25 : 0;
        $age   = $options['age'] ? 0.7 : 0;

        $standard = 4.9 + 1.05 * $kg + $rural + $sat + $sig + $age;
        $satam    = 6.2 + 1.15 * $kg + ($rural + 0.1) + 0.0 + $sig + $age;

        return [
            $this->row('nzc', 'standard', 'Standard', 'ETA +1 day', $standard, [
                'base' => 4.9, 'perkg' => 1.05 * $kg, 'opts' => $rural + $sat + $sig + $age
            ]),
            $this->row('nzc', 'sat_am', 'Sat AM', 'ETA Sat AM', $satam, [
                'base' => 6.2, 'perkg' => 1.15 * $kg, 'opts' => ($rural + 0.1) + 0 + $sig + $age
            ]),
        ];
    }
    public function reserve(array $payload): array {
        return [
            'reservation_id' => uniqid('nzc_res_'),
            'number'         => 'C' . strtoupper(bin2hex(random_bytes(4))),
        ];
    }
    public function create(array $payload): array {
        return [
            'label_id'        => uniqid('nzc_lbl_'),
            'tracking_number' => 'C' . strtoupper(bin2hex(random_bytes(5))),
            'url'             => '/labels/' . uniqid('nzc_') . '.pdf',
        ];
    }
    public function void(string $labelId): array { return ['voided' => true, 'label_id' => $labelId]; }
    public function expired(): array {
        return [[
            'carrier'  => 'NZ Couriers',
            'type'     => 'Ticket',
            'number'   => 'C123-998877',
            'reserved' => date('Y-m-d H:i', strtotime('-7 days')),
            'expires'  => date('Y-m-d H:i'),
        ]];
    }
    public function track(string $tracking): array {
        return ['tracking' => $tracking, 'events' => [['ts' => ps_now_iso(), 'desc' => 'In transit']]];
    }
}

// ------------------------------- Router -----------------------------------
final class PackShipRouter {
    private PackShipNZPost $nzpost;
    private PackShipNZCouriers $nzc;
    private ShippingLabelsService $labels;

    public function __construct(private array $cfg) {
        $this->nzpost = new PackShipNZPost($cfg['carriers']['nz_post'] ?? []);
        $this->nzc    = new PackShipNZCouriers($cfg['carriers']['nzc'] ?? []);
        $this->labels = new ShippingLabelsService();
    }

    public function dispatch(string $action, array $data, ?string $idemCacheKey): void {
        switch ($action) {
            case 'carriers':      $this->carriers(); return;
            case 'rules':         $this->rules(); return;
            case 'health':        $this->health(); return;
            case 'rates':         $this->rates($data); return;
            case 'reserve':       $this->reserve($data, $idemCacheKey); return;
            case 'create':        $this->create($data, $idemCacheKey); return;
            case 'void':          $this->void($data); return;
            case 'expired':       $this->expired(); return;
            case 'track':         $this->track($data); return;
            case 'audit':         $this->audit($data); return;
            case 'history':       $this->history($data); return;
            case 'history_csv':   $this->historyCsv($data); return;
            case 'bulk_print':    $this->bulkPrint($data); return;
            case 'tracking_events': $this->trackingEvents($data); return;
            default:
                throw new PackShipApiError('unknown_action', 'Unknown action: ' . $action);
        }
    }

    public function carriers(): void {
        $out = [];
        foreach ($this->cfg['carriers'] as $code => $c) {
            $out[] = [
                'code'    => $code,
                'name'    => (string)($c['name'] ?? strtoupper($code)),
                'enabled' => (bool)($c['enabled'] ?? false),
                'mode'    => (string)($c['mode'] ?? 'simulate'),
                'color'   => (string)($c['color'] ?? '#666666'),
            ];
        }
        pack_ship_out(['ok' => true, 'carriers' => $out]);
    }

    public function rules(): void {
        pack_ship_out(['ok' => true, 'strategies' => ['cheapest', 'fastest', 'balanced', 'custom']]);
    }

    public function health(): void {
        $outlet = $this->cfg['outlet'] ?? null;
        $checks = [
            'php'           => PHP_VERSION,
            'time'          => ps_now_iso(),
            'outlet'        => $outlet ? ['id' => $outlet['id'], 'name' => $outlet['name']] : 'DEFAULT_CONFIG',
            'nz_post'       => ($this->cfg['carriers']['nz_post']['enabled'] ?? false) ? 'ENABLED' : 'DISABLED',
            'nzc'           => ($this->cfg['carriers']['nzc']['enabled'] ?? false) ? 'ENABLED' : 'DISABLED',
            'nz_post_keys'  => !empty($this->cfg['carriers']['nz_post']['keys']['api_key']) ? 'CONFIGURED' : 'MISSING',
            'nzc_keys'      => !empty($this->cfg['carriers']['nzc']['keys']['api_key']) ? 'CONFIGURED' : 'MISSING',
        ];
        pack_ship_out(['ok' => true, 'checks' => $checks]);
    }

    public function rates(array $data): void {
        $carrier  = (string)($data['carrier'] ?? 'all');
        $packages = PackShipDomain::sanitizePackages($data['packages'] ?? []);
        $options  = PackShipDomain::sanitizeOptions($data['options'] ?? []);
        $context  = PackShipDomain::sanitizeContext($data['context'] ?? []);

        if (!$packages) throw new PackShipApiError('bad_request', 'No packages provided');
        if (count($packages) > 50) throw new PackShipApiError('too_many_packages', 'Max 50 packages');

        foreach ($packages as $p) {
            if ($p['l'] > 200 || $p['w'] > 200 || $p['h'] > 200) {
                throw new PackShipApiError('dims_exceed', 'Package dims exceed 200cm limit');
            }
            if ($p['kg'] > 50) {
                throw new PackShipApiError('weight_exceed', 'Package weight exceeds 50kg');
            }
        }

        $dim = (float)($this->cfg['dim_factor'] ?? 5000.0);
        $results = [];

        if ($carrier === 'all' || $carrier === 'nz_post') {
            if ($this->cfg['carriers']['nz_post']['enabled'] ?? false) {
                $results = array_merge($results, $this->nzpost->rates($packages, $options, $context, $dim));
            }
        }
        if ($carrier === 'all' || $carrier === 'nzc') {
            if ($this->cfg['carriers']['nzc']['enabled'] ?? false) {
                $results = array_merge($results, $this->nzc->rates($packages, $options, $context, $dim));
            }
        }

        PackShipDomain::strategySort((string)($this->cfg['rules'] ?? 'cheapest'), $results);
        pack_ship_out(['ok' => true, 'results' => $results]);
    }

    public function reserve(array $data, ?string $idemCacheKey): void {
        $carrier = (string)($data['carrier'] ?? '');
        $payload = (array)($data['payload'] ?? []);
        $service = (string)($payload['service'] ?? '');
        if ($carrier === '' || $service === '') {
            throw new PackShipApiError('bad_request', 'carrier and payload.service are required');
        }

        $ad  = $this->adapter($carrier);
        $res = $ad->reserve($payload);

        $mode   = (string)($this->cfg['carriers'][$carrier]['mode'] ?? 'simulate');
        $userId = (int)($_SESSION['staff_id'] ?? 0);
        $transferId = (int)($data['transfer_id'] ?? 0);

        // Persist reservation
        $dbId = $this->labels->recordReservation(
            $transferId,
            $carrier,
            $service,
            $res['reservation_id'],
            isset($payload['total']) ? (float)$payload['total'] : null,
            $payload,
            $mode,
            $userId,
            $payload, // request_snapshot
            $res      // response_snapshot
        );

        $out = ['ok' => true, 'db_id' => $dbId, 'simulated' => ($mode === 'simulate')] + $res;
        pack_ship_out($out, 200, $idemCacheKey);
    }

    public function create(array $data, ?string $idemCacheKey): void {
        $carrier = (string)($data['carrier'] ?? '');
        $payload = (array)($data['payload'] ?? []);
        $service = (string)($payload['service'] ?? '');
        if ($carrier === '' || $service === '') {
            throw new PackShipApiError('bad_request', 'carrier and payload.service are required');
        }

        $reservationId = (string)($data['reservation_id'] ?? ($payload['reservation_id'] ?? ''));
        $ad  = $this->adapter($carrier);
        $res = $ad->create($payload);

        $mode   = (string)($this->cfg['carriers'][$carrier]['mode'] ?? 'simulate');
        $userId = (int)($_SESSION['staff_id'] ?? 0);

        $dbId = null;
        if ($reservationId !== '') {
            $row = $this->labels->findByReservation($reservationId);
            if ($row) {
                $this->labels->upgradeToLabel((int)$row['id'], $res['label_id'], $res['tracking_number'], $res);
                $dbId = (int)$row['id'];
            }
        }
        if ($dbId === null) {
            $newId = $this->labels->recordReservation(
                (int)($data['transfer_id'] ?? 0),
                $carrier,
                $service,
                $res['label_id'], // placeholder in reservation field
                isset($payload['total']) ? (float)$payload['total'] : null,
                $payload,
                $mode,
                $userId,
                $payload,
                $res
            );
            $this->labels->upgradeToLabel($newId, $res['label_id'], $res['tracking_number'], $res);
            $dbId = $newId;
        }

        $out = ['ok' => true, 'db_id' => $dbId, 'simulated' => ($mode === 'simulate')] + $res;
        pack_ship_out($out, 200, $idemCacheKey);
    }

    public function void(array $data): void {
        $carrier = (string)($data['carrier'] ?? '');
        $labelId = (string)($data['label_id'] ?? '');
        if ($carrier === '' || $labelId === '') {
            throw new PackShipApiError('bad_request', 'carrier and label_id are required');
        }

        $ad = $this->adapter($carrier);
        $res = $ad->void($labelId);

        $row = $this->labels->findByLabel($labelId);
        $dbVoided = false;
        if ($row) {
            $dbVoided = $this->labels->voidLabel((int)$row['id']);
        }

        $mode = (string)($this->cfg['carriers'][$carrier]['mode'] ?? 'simulate');
        pack_ship_out(['ok' => true, 'db_voided' => $dbVoided, 'simulated' => ($mode === 'simulate')] + $res);
    }

    public function expired(): void {
        $rows = array_merge($this->nzpost->expired(), $this->nzc->expired());
        pack_ship_out(['ok' => true, 'rows' => $rows]);
    }

    public function track(array $data): void {
        $carrier  = (string)($data['carrier'] ?? '');
        $tracking = (string)($data['tracking'] ?? '');
        if ($carrier === '' || $tracking === '') {
            throw new PackShipApiError('bad_request', 'carrier and tracking are required');
        }

        $ad  = $this->adapter($carrier);
        $res = $ad->track($tracking);

        // Persist events (best-effort)
        $labelRow = $this->findLabelByTracking($tracking);
        $stored = 0;
        if (!empty($res['events']) && is_array($res['events'])) {
            $stored = $this->labels->storeTrackingEvents($labelRow ? (int)$labelRow['id'] : null, $tracking, $res['events']);
        }

        pack_ship_out(['ok' => true, 'stored_events' => $stored] + $res);
    }

    public function audit(array $data): void {
        $packages = PackShipDomain::sanitizePackages($data['packages'] ?? []);
        if (!$packages) throw new PackShipApiError('bad_request', 'No packages provided');

        $cap = 25.0; // display meter cap
        $suggestions = [];
        $meters = [];
        foreach ($packages as $i => $p) {
            $meters[] = ['box' => $i + 1, 'kg' => $p['kg'], 'cap' => $cap, 'pct' => min(100, round(($p['kg'] / $cap) * 100))];
            if ($p['kg'] > 23.0) $suggestions[] = 'Box ' . ($i + 1) . ' is ' . $p['kg'] . 'kg (>23). Consider split or larger box.';
            if (($p['items'] ?? 0) <= 0) $suggestions[] = 'Box ' . ($i + 1) . ' has zero items. Remove or assign.';
        }

        pack_ship_out(['ok' => true, 'suggestions' => $suggestions, 'meters' => $meters]);
    }

    public function history(array $data): void {
        $limit = (int)($data['limit'] ?? 50);
        if ($limit < 1 || $limit > 200) $limit = 50;

        $transferId = (int)($data['transfer_id'] ?? 0);
        $rows = $transferId > 0
            ? $this->labels->listRecentByTransfer($transferId, $limit)
            : $this->labels->listRecent($limit);

        pack_ship_out(['ok' => true, 'rows' => $rows, 'transfer_id' => $transferId, 'count' => count($rows)]);
    }

    public function historyCsv(array $data): void {
        $limit = (int)($data['limit'] ?? 500);
        if ($limit < 1 || $limit > 2000) $limit = 500;

        $transferId = (int)($data['transfer_id'] ?? 0);
        $rows = $transferId > 0 ? $this->labels->listRecentByTransfer($transferId, $limit) : $this->labels->listRecent($limit);
        if (!$rows) pack_ship_out(['ok' => true, 'csv' => '', 'filename' => 'labels_empty.csv', 'count' => 0]);

        $cols = ['id','transfer_id','carrier','service','status','mode','cost_total','tracking_number','created_at'];
        $f = fopen('php://temp', 'w+');
        fputcsv($f, $cols);
        foreach ($rows as $r) {
            $line = [];
            foreach ($cols as $c) { $line[] = $r[$c] ?? ''; }
            fputcsv($f, $line);
        }
        rewind($f);
        $csv = stream_get_contents($f);
        fclose($f);

        pack_ship_out([
            'ok'       => true,
            'csv'      => base64_encode($csv ?: ''),
            'filename' => 'labels_export_' . date('Ymd_His') . '.csv',
            'count'    => count($rows)
        ]);
    }

    public function bulkPrint(array $data): void {
        $labelIds = is_array($data['label_ids'] ?? null) ? $data['label_ids'] : [];
        $trackingNumbers = is_array($data['tracking_numbers'] ?? null) ? $data['tracking_numbers'] : [];
        $urls = [];

        foreach ($labelIds as $lid)        { $urls[] = '/labels/' . rawurlencode((string)$lid) . '.pdf'; }
        foreach ($trackingNumbers as $tn)   { $urls[] = '/labels/' . rawurlencode((string)$tn) . '.pdf'; }

        $urls = array_values(array_unique(array_filter($urls)));
        if (!$urls) {
            pack_ship_out(['ok' => true, 'bundle_html' => base64_encode('<html><body>No labels selected</body></html>'), 'count' => 0]);
        }

        $html = '<html><head><title>Bulk Print</title><meta charset="utf-8"><style>body{margin:0;padding:0} .pg{page-break-after:always}</style></head><body onload="window.print()">';
        foreach ($urls as $u) {
            $html .= '<div class="pg"><iframe src="' . htmlspecialchars($u, ENT_QUOTES) . '" style="width:100%;height:1000px;border:0"></iframe></div>';
        }
        $html .= '</body></html>';

        pack_ship_out(['ok' => true, 'bundle_html' => base64_encode($html), 'count' => count($urls)]);
    }

    public function trackingEvents(array $data): void {
        $tracking = (string)($data['tracking'] ?? '');
        if ($tracking === '') {
            throw new PackShipApiError('bad_request', 'tracking is required');
        }
        $row = $this->findLabelByTracking($tracking);
        pack_ship_out(['ok' => true, 'row' => $row]);
    }

    private function adapter(string $code): PackShipCarrierAdapter {
        return match ($code) {
            'nz_post' => $this->nzpost,
            'nzc'     => $this->nzc,
            default   => throw new PackShipApiError('bad_carrier', 'Unknown carrier: ' . $code),
        };
    }

    private function findLabelByTracking(string $tracking): ?array {
        try {
            $db = ps_db();
            $st = $db->prepare('SELECT * FROM transfer_shipping_labels WHERE tracking_number = :t LIMIT 1');
            $st->execute(['t' => $tracking]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            pack_ship_log('findLabelByTracking error: ' . $e->getMessage());
            return null;
        }
    }
}

// -------------------------------- Main ------------------------------------
try {
    if ($action === '') {
        throw new PackShipApiError('missing_action', 'No action provided');
    }

    // Resolve outlet for credentials
    $outletFromId = '';

    // Prefer transfer_id -> transfers.outlet_from
    $transferIdIn = (int)($data['transfer_id'] ?? 0);
    if ($transferIdIn > 0) {
        try {
            $db = ps_db();
            $st = $db->prepare('SELECT outlet_from FROM transfers WHERE id = :id LIMIT 1');
            $st->execute(['id' => $transferIdIn]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row && isset($row['outlet_from'])) {
                $outletFromId = (string)$row['outlet_from'];
                pack_ship_log("Resolved outlet_from via transfer {$transferIdIn}: {$outletFromId}");
            }
        } catch (Throwable $e) {
            pack_ship_log('Transfer lookup error: ' . $e->getMessage());
        }
    }

    // Fallback: direct outlet_from_id
    if (empty($outletFromId)) {
        $outletFromId = (string)($data['outlet_from_id'] ?? '');
        if (!empty($outletFromId)) {
            pack_ship_log("Using direct outlet_from_id: {$outletFromId}");
        }
    }

    // Load config
    $CONFIG = !empty($outletFromId) ? pack_ship_get_outlet_config($outletFromId) : pack_ship_get_default_config();
    if (empty($outletFromId)) {
        pack_ship_log('No outlet specified, using default config');
    }

    $router = new PackShipRouter($CONFIG);
    $router->dispatch($action, $data, $IDEMPOTENCY_CACHE_KEY !== '' ? $IDEMPOTENCY_CACHE_KEY : null);

} catch (PackShipApiError $e) {
    pack_ship_out(['ok' => false, 'error' => ['code' => $e->code(), 'msg' => $e->getMessage()]], $e->getCode() ?: 400);
} catch (Throwable $e) {
    pack_ship_log('Fatal: ' . $e->getMessage());
    pack_ship_out(['ok' => false, 'error' => ['code' => 'server_error', 'msg' => 'Unexpected error']], 500);
}
