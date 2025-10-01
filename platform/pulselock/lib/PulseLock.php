<?php
declare(strict_types=1);

/**
 * File: modules/platform/pulselock/lib/PulseLock.php
 * Purpose: Core library for CIS PulseLock guardian (checks, aggregation, persistence, locks).
 * Author: GitHub Copilot
 * Last Modified: 2025-09-25
 */

namespace CIS\PulseLock;

use PDO;
use PDOException;
use Throwable;

/**
 * Immutable value object for a single health check result.
 */
final class Result
{
    public string $checkKey;
    public string $label;
    public string $status;   // green|amber|red
    public float $score;      // 0..100, higher is healthier
    public int $tookMs;       // execution time in ms
    /** @var array<string,mixed> */
    public array $meta;

    /**
     * @param array<string,mixed> $meta
     */
    public function __construct(string $checkKey, string $label, string $status, float $score, int $tookMs, array $meta = [])
    {
        $this->checkKey = $checkKey;
        $this->label = $label;
        $this->status = $status;
        $this->score = $score;
        $this->tookMs = $tookMs;
        $this->meta = $meta;
    }
}

/**
 * Interface every check runner must implement.
 */
interface Checker
{
    public function run(): Result;

    /**
     * @return array<int,string> List of pipelines impacted by this check (e.g. purchase-orders, vend).
     */
    public function pipelines(): array;

    /**
     * @return string Frequency hint (fast|normal|slow) for scheduling cadence.
     */
    public function frequency(): string;
}

/**
 * Simple registry container for checkers.
 */
final class Registry
{
    /** @var array<int,Checker> */
    private array $checkers = [];

    public function add(Checker $checker): self
    {
        $this->checkers[] = $checker;
        return $this;
    }

    /**
     * @return array<int,Checker>
     */
    public function all(): array
    {
        return $this->checkers;
    }
}

/**
 * Aggregation, persistence, and global lock utilities.
 */
final class Guard
{
    /**
     * @param array<int,Result> $results
     * @return array{0:string,1:float,2:array<string,string>}
     */
    public static function aggregate(array $results): array
    {
        $rank = ['green' => 1, 'amber' => 2, 'red' => 3];
        $worst = 'green';
        $scoreSum = 0.0;
        $count = 0;
        $perPipeline = [];

        foreach ($results as $result) {
            $count++;
            $scoreSum += $result->score;
            if (($rank[$result->status] ?? 1) > ($rank[$worst] ?? 1)) {
                $worst = $result->status;
            }
            $pipelines = (array)($result->meta['pipelines'] ?? []);
            foreach ($pipelines as $pipeline) {
                $prev = $perPipeline[$pipeline] ?? 'green';
                if (($rank[$result->status] ?? 1) > ($rank[$prev] ?? 1)) {
                    $perPipeline[$pipeline] = $result->status;
                }
            }
        }

        $avgScore = $count > 0 ? round($scoreSum / $count, 2) : 100.0;
        return [$worst, $avgScore, $perPipeline];
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function writeJson(string $path, array $payload): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        try {
            file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (Throwable $e) {
            // Swallow but log to STDERR for cron visibility.
            fwrite(STDERR, '[PulseLock] Failed to write JSON cache: ' . $e->getMessage() . "\n");
        }
    }

    public static function db(): ?PDO
    {
        // Preferred: global db() helper if defined.
        if (function_exists('db')) {
            try {
                $pdo = db();
                if ($pdo instanceof PDO) {
                    return $pdo;
                }
            } catch (Throwable $e) {
                fwrite(STDERR, '[PulseLock] db() helper failed: ' . $e->getMessage() . "\n");
            }
        }

        // Fallback to environment variables.
        $host = $_ENV['DB_HOST'] ?? getenv('DB_HOST');
        $name = $_ENV['DB_NAME'] ?? getenv('DB_NAME');
        $user = $_ENV['DB_USER'] ?? getenv('DB_USER');
        $pass = $_ENV['DB_PASS'] ?? getenv('DB_PASS');
        if (!$host || !$name || !$user) {
            return null;
        }

        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $name);
        try {
            return new PDO($dsn, $user, (string)$pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            fwrite(STDERR, '[PulseLock] PDO connect failed: ' . $e->getMessage() . "\n");
            return null;
        }
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    public static function persist(array $snapshot): void
    {
        $pubCache = $_SERVER['DOCUMENT_ROOT'] . '/_cache/pulselock/status.json';
        $privCache = dirname($_SERVER['DOCUMENT_ROOT']) . '/private_html/pulselock/status.json';

        self::writeJson($pubCache, $snapshot);
        self::writeJson($privCache, $snapshot);

        $pdo = self::db();
        if ($pdo === null) {
            return;
        }

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO system_status_snapshots (status, score, pipelines_json, checks_json, took_ms, version)
                 VALUES (:status, :score, :pipelines, :checks, :took, :version)'
            );
            $stmt->execute([
                ':status' => (string)($snapshot['status'] ?? 'unknown'),
                ':score' => (float)($snapshot['score'] ?? 0.0),
                ':pipelines' => json_encode($snapshot['pipelines'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':checks' => json_encode($snapshot['checks'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':took' => (int)($snapshot['took_ms'] ?? 0),
                ':version' => (string)($snapshot['version'] ?? 'v1'),
            ]);
        } catch (Throwable $e) {
            fwrite(STDERR, '[PulseLock] Persist snapshot failed: ' . $e->getMessage() . "\n");
        }
    }

    /**
     * @param array<string,string> $pipelineWorst
     */
    public static function setGlobalLock(array $pipelineWorst, string $overall): void
    {
        $payload = [
            'active' => $overall !== 'green',
            'overall' => $overall,
            'pipelines' => $pipelineWorst,
            'updated' => gmdate('c'),
        ];

        self::writeJson($_SERVER['DOCUMENT_ROOT'] . '/_cache/pulselock/lock.json', $payload);

        $pdo = self::db();
        if ($pdo === null) {
            return;
        }

        try {
            $stmt = $pdo->prepare('REPLACE INTO system_flags (flag_key, flag_value) VALUES (:key, :value)');
            $stmt->execute([
                ':key' => 'pulselock',
                ':value' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable $e) {
            fwrite(STDERR, '[PulseLock] Persist lock failed: ' . $e->getMessage() . "\n");
        }
    }
}

/**
 * Base checker implementing shared helpers.
 */
abstract class BaseChecker implements Checker
{
    protected string $key;
    protected string $label;
    /** @var array<int,string> */
    protected array $pipelines;
    protected string $freq;

    /**
     * @param array<int,string> $pipelines
     */
    public function __construct(string $key, string $label, array $pipelines = [], string $freq = 'normal')
    {
        $this->key = $key;
        $this->label = $label;
        $this->pipelines = $pipelines;
        $this->freq = $freq;
    }

    public function pipelines(): array
    {
        return $this->pipelines;
    }

    public function frequency(): string
    {
        return $this->freq;
    }

    protected function now(): float
    {
        return microtime(true);
    }

    protected function ms(float $started): int
    {
        return (int)round((microtime(true) - $started) * 1000);
    }

    /**
     * @param array<string,mixed> $meta
     */
    protected function result(string $status, float $score, int $tookMs, array $meta = []): Result
    {
        $meta['pipelines'] = $this->pipelines();
        return new Result($this->key, $this->label, $status, $score, $tookMs, $meta);
    }
}

/**
 * HTTP endpoint probe + asset validation.
 */
final class HttpEndpointChecker extends BaseChecker
{
    private string $url;
    private int $timeout;
    private ?string $mustContain;
    private bool $checkAssets;
    /** @var array<int,string> */
    private array $assetHosts;

    /**
     * @param array<int,string> $pipelines
     * @param array<int,string> $assetHosts
     */
    public function __construct(
        string $key,
        string $label,
        string $url,
        array $pipelines = [],
        int $timeout = 8,
        ?string $mustContain = null,
        bool $checkAssets = true,
        array $assetHosts = []
    ) {
        parent::__construct($key, $label, $pipelines, 'fast');
        $this->url = $url;
        $this->timeout = $timeout;
        $this->mustContain = $mustContain;
        $this->checkAssets = $checkAssets;
        $this->assetHosts = $assetHosts;
    }

    public function run(): Result
    {
        $start = $this->now();
        $code = 0;
        $err = null;
        $size = 0;
        $assetErrors = [];
        $body = $this->curlGet($this->url, $this->timeout, $code, $err, $size);

        $ok = $code >= 200 && $code < 400;
        if ($ok && $this->mustContain !== null && strpos((string)$body, $this->mustContain) === false) {
            $ok = false;
        }

        if ($ok && $this->checkAssets && is_string($body)) {
            $assetErrors = $this->checkHtmlAssets($body, $this->url, $this->timeout);
            if (!empty($assetErrors)) {
                $ok = false;
            }
        }

        $status = $ok ? 'green' : ($code > 0 ? 'amber' : 'red');
        $score = $status === 'green' ? 100.0 : ($status === 'amber' ? 60.0 : 20.0);

        return $this->result($status, $score, $this->ms($start), [
            'code' => $code,
            'err' => $err,
            'size' => $size,
            'asset_errors' => $assetErrors,
            'url' => $this->url,
        ]);
    }

    /**
     * @param int $code
     * @param string|null $err
     * @param int $bytes
     */
    private function curlGet(string $url, int $timeout, int &$code, ?string &$err, int &$bytes): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
            CURLOPT_USERAGENT => 'CIS-PulseLock/1.0',
        ]);
        $body = curl_exec($ch);
        $bytes = is_string($body) ? strlen($body) : 0;
        if ($body === false) {
            $body = '';
        }
        if (curl_errno($ch) !== 0) {
            $err = curl_error($ch) ?: null;
        }
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return (string)$body;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function checkHtmlAssets(string $html, string $baseUrl, int $timeout): array
    {
        $bad = [];
        if (!preg_match_all('#<(?:script|link|img)[^>]+(?:src|href)=\"([^\"]+)\"#i', $html, $matches)) {
            return $bad;
        }
        $assets = $matches[1];
        $base = parse_url($baseUrl) ?: [];

        /** @var array<int,string> $assets */
        foreach ($assets as $asset) {
            if (stripos($asset, 'data:') === 0) {
                continue;
            }
            $url = $asset;
            if (stripos($asset, 'http') !== 0 && isset($base['scheme'], $base['host'])) {
                $path = ($asset[0] ?? '') === '/' ? $asset : rtrim((string)($base['path'] ?? ''), '/') . '/' . $asset;
                $url = $base['scheme'] . '://' . $base['host'] . $path;
            }
            if (!empty($this->assetHosts)) {
                $host = parse_url($url, PHP_URL_HOST);
                if ($host !== null && !in_array($host, $this->assetHosts, true)) {
                    continue;
                }
            }
            $assetCode = 0;
            $assetErr = null;
            $assetBytes = 0;
            $this->curlGet($url, $timeout, $assetCode, $assetErr, $assetBytes);
            if (!($assetCode >= 200 && $assetCode < 400)) {
                $bad[] = ['url' => $url, 'code' => $assetCode, 'err' => $assetErr];
            }
        }

        return $bad;
    }
}

/** Database connectivity health check. */
final class DbChecker extends BaseChecker
{
    public function run(): Result
    {
        $start = $this->now();
        try {
            $pdo = Guard::db();
            if (!$pdo) {
                return $this->result('amber', 55.0, $this->ms($start), ['err' => 'Database unavailable']);
            }
            $probeStart = microtime(true);
            $value = $pdo->query('SELECT 1')->fetchColumn();
            $latency = round((microtime(true) - $probeStart) * 1000, 2);
            $status = ((int)$value === 1) ? 'green' : 'amber';
            $score = $status === 'green' ? 100.0 : 60.0;
            return $this->result($status, $score, $this->ms($start), ['latency_ms' => $latency]);
        } catch (Throwable $e) {
            return $this->result('red', 10.0, $this->ms($start), ['err' => $e->getMessage()]);
        }
    }
}

/** Host-level CPU/memory/disk baseline check. */
final class SystemChecker extends BaseChecker
{
    public function run(): Result
    {
        $start = $this->now();
        $load = function_exists('sys_getloadavg') ? (sys_getloadavg()[0] ?? 0.0) : 0.0;
        $diskFree = @disk_free_space('/');
        $diskTotal = @disk_total_space('/');
        $diskUsedPct = ($diskTotal && $diskFree !== false) ? 100.0 - (($diskFree / $diskTotal) * 100.0) : 0.0;
        $memoryPct = $this->memoryUsedPct();

        $status = 'green';
        if ($diskUsedPct > 95.0 || $memoryPct > 95.0 || $load > 16.0) {
            $status = 'red';
        } elseif ($diskUsedPct > 90.0 || $memoryPct > 90.0 || $load > 8.0) {
            $status = 'amber';
        }
        $score = $status === 'green' ? 100.0 : ($status === 'amber' ? 55.0 : 15.0);

        return $this->result($status, $score, $this->ms($start), [
            'cpu_load_1m' => round($load, 2),
            'disk_used_pct' => round($diskUsedPct, 1),
            'mem_used_pct' => round($memoryPct, 1),
        ]);
    }

    private function memoryUsedPct(): float
    {
        if (is_readable('/proc/meminfo')) {
            $lines = file('/proc/meminfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $map = [];
            foreach ($lines as $line) {
                if (preg_match('/^(\w+):\s+(\d+)/', $line, $matches)) {
                    $map[$matches[1]] = (int)$matches[2];
                }
            }
            if (!empty($map['MemTotal']) && !empty($map['MemAvailable'])) {
                $total = (float)$map['MemTotal'];
                $available = (float)$map['MemAvailable'];
                return 100.0 - (($available / $total) * 100.0);
            }
        }
        return 0.0;
    }
}

/** Queue backlog pressure indicator. */
final class QueueChecker extends BaseChecker
{
    public function run(): Result
    {
        $start = $this->now();
        $pending = null;
        $err = null;
        $status = 'amber';
        $score = 60.0;

        try {
            $pdo = Guard::db();
            if (!$pdo) {
                return $this->result('amber', 50.0, $this->ms($start), ['err' => 'Database unavailable']);
            }
            $pending = (int)$pdo->query("SELECT COUNT(*) FROM queue_jobs WHERE status IN ('pending','retry')")->fetchColumn();
            if ($pending < 100) {
                $status = 'green';
                $score = 100.0;
            } elseif ($pending >= 1000) {
                $status = 'red';
                $score = 10.0;
            }
        } catch (Throwable $e) {
            $status = 'red';
            $score = 10.0;
            $err = $e->getMessage();
        }

        return $this->result($status, $score, $this->ms($start), ['pending' => $pending, 'err' => $err]);
    }
}

/** Cron recency guard based on cron_runs table. */
final class CronFreshnessChecker extends BaseChecker
{
    public function run(): Result
    {
        $start = $this->now();
        $late = [];
        try {
            $pdo = Guard::db();
            if (!$pdo) {
                return $this->result('amber', 50.0, $this->ms($start), ['err' => 'Database unavailable']);
            }
            $rows = $pdo->query('SELECT job_key, last_ok FROM cron_runs')->fetchAll();
            $now = time();
            foreach ($rows as $row) {
                $okAt = $row['last_ok'] ? strtotime((string)$row['last_ok']) : 0;
                if ($okAt === 0 || ($now - $okAt) > 900) {
                    $late[] = [
                        'job' => (string)$row['job_key'],
                        'age_s' => $okAt > 0 ? ($now - $okAt) : null,
                    ];
                }
            }
            $status = empty($late) ? 'green' : (count($late) > 5 ? 'red' : 'amber');
            $score = $status === 'green' ? 100.0 : ($status === 'amber' ? 55.0 : 15.0);
            return $this->result($status, $score, $this->ms($start), ['late' => $late]);
        } catch (Throwable $e) {
            return $this->result('red', 10.0, $this->ms($start), ['err' => $e->getMessage()]);
        }
    }
}

/** TLS expiry countdown. */
final class TlsCertChecker extends BaseChecker
{
    private string $host;
    private int $port;

    /**
     * @param array<int,string> $pipelines
     */
    public function __construct(string $key, string $label, string $host, int $port = 443, array $pipelines = [], string $freq = 'slow')
    {
        parent::__construct($key, $label, $pipelines, $freq);
        $this->host = $host;
        $this->port = $port;
    }

    public function run(): Result
    {
        $start = $this->now();
        $days = 0;
        $err = null;
        $status = 'amber';
        $score = 60.0;

        try {
            $context = stream_context_create([
                'ssl' => [
                    'capture_peer_cert' => true,
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ]);
            $socket = @stream_socket_client('ssl://' . $this->host . ':' . $this->port, $errno, $errstr, 6.0, STREAM_CLIENT_CONNECT, $context);
            if ($socket) {
                $params = stream_context_get_params($socket);
                $cert = $params['options']['ssl']['peer_certificate'] ?? null;
                if ($cert) {
                    $parsed = openssl_x509_parse($cert);
                    if ($parsed && isset($parsed['validTo_time_t'])) {
                        $days = (int)floor(($parsed['validTo_time_t'] - time()) / 86400);
                        if ($days > 14) {
                            $status = 'green';
                            $score = 100.0;
                        } elseif ($days <= 3) {
                            $status = 'red';
                            $score = 10.0;
                        }
                    }
                }
                fclose($socket);
            } else {
                $status = 'red';
                $score = 10.0;
                $err = $errstr ?: 'socket connect failed';
            }
        } catch (Throwable $e) {
            $status = 'red';
            $score = 10.0;
            $err = $e->getMessage();
        }

        return $this->result($status, $score, $this->ms($start), ['days_to_expiry' => $days, 'err' => $err, 'host' => $this->host]);
    }
}

/** Lightweight Vend API smoke test. */
final class VendApiChecker extends BaseChecker
{
    public function __construct(string $key = 'vend_api', string $label = 'Vend API', array $pipelines = ['vend', 'purchase-orders'], string $freq = 'normal')
    {
        parent::__construct($key, $label, $pipelines, $freq);
    }

    public function run(): Result
    {
        $start = $this->now();
        $base = $_ENV['VEND_BASE'] ?? getenv('VEND_BASE') ?? '';
        $token = $_ENV['VEND_TOKEN'] ?? getenv('VEND_TOKEN') ?? '';
        if ($base === '' || $token === '') {
            return $this->result('amber', 55.0, $this->ms($start), ['err' => 'VEND_BASE/VEND_TOKEN not configured']);
        }

        $url = rtrim($base, '/') . '/api/2.0/outlets?per_page=1';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
                'User-Agent: CIS-PulseLock/1.0',
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_errno($ch) !== 0 ? curl_error($ch) : null;
        curl_close($ch);

        $ok = $code >= 200 && $code < 300 && is_string($body) && (str_contains($body, '"data"') || str_contains($body, '"outlets"'));
        $status = $ok ? 'green' : ($code >= 400 && $code < 500 ? 'amber' : 'red');
        $score = $status === 'green' ? 100.0 : ($status === 'amber' ? 50.0 : 10.0);

        return $this->result($status, $score, $this->ms($start), ['code' => $code, 'err' => $err]);
    }
}
