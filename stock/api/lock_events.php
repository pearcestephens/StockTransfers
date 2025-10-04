<?php
/**
 * lock_events.php — Server-Sent Events endpoint for real-time lock notifications
 * Hardened for production (Cloudways/nginx/Apache/PHP-FPM friendly)
 *
 * Key features:
 * - Strict runtime cap + graceful self-termination (no zombies)
 * - Dynamic polling backoff (250ms → 2s) to protect DB/CPU
 * - Heartbeats + SSE "retry" to make reconnection smooth
 * - One-connection-per-tab lock guard (optional)
 * - Immediate disconnect detection and cleanup
 * - Safe output flushing (disables buffering/gzip)
 */
declare(strict_types=1);

// ========================= Tunables (safe defaults) =========================
const SSE_MAX_SECONDS         = 300;   // hard cap on stream lifetime (sec)
const SSE_MIN_INTERVAL_MS     = 250;   // initial poll interval
const SSE_MAX_INTERVAL_MS     = 2000;  // max poll interval on backoff
const SSE_HEARTBEAT_SECONDS   = 25;    // send heartbeat < typical proxy idle
const SSE_RETRY_MS            = 2000;  // browser reconnect backoff
const SSE_ONE_CONN_PER_TAB    = true;  // prevent duplicate listeners per tab
const SSE_DB_MAX_STMT_TIME_S  = 1;     // per-select time cap (best-effort)
const SSE_MEMORY_LIMIT        = '64M'; // keep small to avoid runaway
// ============================================================================

// -------- Headers for SSE + no buffering ----------
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-transform');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');       // nginx: disable response buffering
header('X-Content-Type-Options: nosniff');

// Hint to client on how fast to retry
echo "retry: " . SSE_RETRY_MS . "\n\n";

// Kill any output buffering / compression (avoid stalls)
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', 'off');
@ini_set('implicit_flush', '1');
@ini_set('memory_limit', SSE_MEMORY_LIMIT);
if (function_exists('apache_setenv')) { @apache_setenv('no-gzip', '1'); }
while (ob_get_level() > 0) { @ob_end_flush(); }
@flush();

// Do not continue if client disconnects
ignore_user_abort(false);

// Conservative execution limits
@set_time_limit(SSE_MAX_SECONDS);
@ini_set('max_execution_time', (string)SSE_MAX_SECONDS);
@ini_set('default_socket_timeout', '5'); // network-level socket timeout

// -------- Bootstrap CIS app for DB connection ----------
require_once $_SERVER['DOCUMENT_ROOT'] . '/app.php';
if (!function_exists('cis_pdo')) {
  require_once dirname(__DIR__, 2) . '/_local_shims.php';
}

// -------- Input handling & validation ----------
$resourceKey = $_GET['resource']  ?? '';
$ownerId     = $_GET['owner_id']  ?? '';
$tabId       = $_GET['tab_id']    ?? '';

// Basic presence
if ($resourceKey === '' || $ownerId === '' || $tabId === '') {
  sse('error', ['message' => 'Missing required parameters']); exit;
}

// Length & charset guards (permit common token chars only)
if (strlen($resourceKey) > 200 || strlen($ownerId) > 64 || strlen($tabId) > 64) {
  sse('error', ['message' => 'Invalid parameter length']); exit;
}
$validToken = '/^[A-Za-z0-9._:\-\|\/]+$/'; // allow slash/pipe for resource keys
if (!preg_match($validToken, $ownerId) || !preg_match($validToken, $tabId) || !preg_match($validToken, $resourceKey)) {
  sse('error', ['message' => 'Invalid characters in parameters']); exit;
}

// -------- Optional: one-connection-per-tab guard ----------
$lockFp = null;
$lockPath = sys_get_temp_dir() . '/cis_sse_' . hash('sha256', $resourceKey . '|' . $ownerId . '|' . $tabId) . '.lock';
if (SSE_ONE_CONN_PER_TAB) {
  $lockFp = @fopen($lockPath, 'c');
  if ($lockFp === false || !@flock($lockFp, LOCK_EX | LOCK_NB)) {
    sse('error', ['message' => 'Duplicate stream for this tab; close the other tab first.']);
    safeClose(null, $lockFp, $lockPath, false); // release attempt if any
    exit;
  }
}

// -------- DB connect + per-session query caps (best-effort) ----------
try {
  $pdo = cis_pdo();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

  // MariaDB: cap statement time (seconds)
  try { $pdo->exec('SET SESSION max_statement_time = ' . (int)SSE_DB_MAX_STMT_TIME_S); } catch (\Throwable $e) {}
  // MySQL 5.7+: cap execution time (milliseconds)
  try { $pdo->exec('SET SESSION MAX_EXECUTION_TIME = ' . (int)(SSE_DB_MAX_STMT_TIME_S * 1000)); } catch (\Throwable $e) {}

  $stmt = $pdo->prepare("
    SELECT owner_id, tab_id, token, UNIX_TIMESTAMP(expires_at) AS expires_ts
    FROM simple_locks
    WHERE resource_key = :rk
    LIMIT 1
  ");
} catch (\Throwable $e) {
  error_log('[SSE] DB bootstrap failed: ' . $e->getMessage());
  sse('error', ['message' => 'Database unavailable']); 
  safeClose(null, $lockFp, $lockPath);
  exit;
}

// -------- Initial push so proxies start streaming quickly ----------
echo ':' . str_repeat(' ', 2048) . "\n\n"; // "comment" padding
@flush();

// -------- Initial state snapshot ----------
$firstLock  = fetchLock($stmt, $resourceKey);
$firstSig   = fingerprint($firstLock);
$nowTs      = time();
$expiresIn  = $firstLock ? max(0, (int)$firstLock['expires_ts'] - $nowTs) : 0;
$isMeFirst  = $firstLock ? ($firstLock['owner_id'] === $ownerId && $firstLock['tab_id'] === $tabId) : false;

sse('connected', [
  'resource'     => $resourceKey,
  'tab_id'       => $tabId,
  'max_duration' => SSE_MAX_SECONDS
]);

sse('state', $firstLock
  ? [
      'state'      => 'locked',
      'by_owner'   => $firstLock['owner_id'],
      'by_tab'     => $firstLock['tab_id'],
      'is_me'      => $isMeFirst,
      'expires_in' => $expiresIn
    ]
  : ['state' => 'unlocked']
);

// -------- Monitor loop with dynamic backoff ----------
$deadlineAt    = microtime(true) + SSE_MAX_SECONDS - 1;
$lastBeatAt    = time();
$intervalMs    = SSE_MIN_INTERVAL_MS;
$signatureLast = $firstSig;
$iteration     = 0;

try {
  while (true) {
    $iteration++;

    // Hard cap & client disconnect checks
    if (microtime(true) >= $deadlineAt) {
      sse('timeout', ['message' => 'Max connection duration reached, reconnect']);
      break;
    }
    if (connection_aborted() || connection_status() !== CONNECTION_NORMAL) {
      // Client went away — stop immediately
      break;
    }

    // Poll state
    try {
      $lock = fetchLock($stmt, $resourceKey);
    } catch (\Throwable $e) {
      error_log('[SSE] DB poll failed: ' . $e->getMessage());
      sse('error', ['message' => 'Database error']);
      break;
    }

    $sig = fingerprint($lock);
    if ($signatureLast !== null && $sig !== $signatureLast) {
      if (!$lock) {
        sse('lock_released', ['resource' => $resourceKey]);
      } else {
        $isMe  = ($lock['owner_id'] === $ownerId && $lock['tab_id'] === $tabId);
        $same  = ($lock['owner_id'] === $ownerId);
        $exIn  = max(0, (int)$lock['expires_ts'] - time());

        if ($isMe) {
          // Our own lock (acquired/renewed)
          sse('lock_acquired', [
            'resource'   => $resourceKey,
            'by_owner'   => $lock['owner_id'],
            'by_tab'     => $lock['tab_id'],
            'expires_in' => $exIn
          ]);
        } else {
          // Someone else has it (lost or stolen)
          sse('lock_stolen', [
            'resource'   => $resourceKey,
            'by_owner'   => $lock['owner_id'],
            'by_tab'     => $lock['tab_id'],
            'same_owner' => $same,
            'expires_in' => $exIn
          ]);
        }
      }
      // Reset backoff after a change
      $intervalMs = SSE_MIN_INTERVAL_MS;
      $signatureLast = $sig;
    } else {
      // No change → gently back off (protect DB/CPU)
      $intervalMs = (int)min(SSE_MAX_INTERVAL_MS, max(SSE_MIN_INTERVAL_MS, $intervalMs * 1.25));
    }

    // Send heartbeat periodically to keep proxies happy
    $now = time();
    if ($now - $lastBeatAt >= SSE_HEARTBEAT_SECONDS) {
      sse('heartbeat', ['iteration' => $iteration, 'interval_ms' => $intervalMs]);
      $lastBeatAt = $now;
    }

    // Sleep with tiny jitter to avoid thundering herd effects
    $jitterMs = random_int(0, 50);
    usleep(($intervalMs + $jitterMs) * 1000);
  }

  sse('closed', ['reason' => 'normal', 'iterations' => $iteration]);
} catch (\Throwable $e) {
  error_log('[SSE] Fatal loop error: ' . $e->getMessage());
  sse('error', ['message' => 'Server error']);
} finally {
  safeClose($pdo ?? null, $lockFp, $lockPath);
  exit;
}

// ============================= Helpers =============================

/**
 * Send an SSE event with optional id. Forces a flush.
 */
function sse(string $event, array $data = [], ?string $id = null): void {
  echo "event: {$event}\n";
  if ($id !== null) {
    echo "id: {$id}\n";
  }
  $json = json_encode($data, JSON_UNESCAPED_SLASHES);
  if ($json === false) { $json = '{"ok":false}'; }
  echo "data: {$json}\n\n";
  if (ob_get_level() > 0) { @ob_flush(); }
  @flush();
}

/**
 * Fetch current lock row (returns assoc or null).
 */
function fetchLock(PDOStatement $stmt, string $resourceKey): ?array {
  $stmt->execute([':rk' => $resourceKey]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  $stmt->closeCursor();
  return $row ?: null;
}

/**
 * Build a minimal fingerprint for change detection.
 */
function fingerprint(?array $lock): string {
  return $lock ? ($lock['owner_id'] . ':' . $lock['tab_id'] . ':' . $lock['token']) : 'unlocked';
}

/**
 * Release resources, file locks, and DB connection gracefully.
 */
function safeClose(?PDO $pdo, $lockFp, string $lockPath, bool $sendClosed = false): void {
  if ($sendClosed) { sse('closed', ['reason' => 'shutdown']); }
  if ($pdo instanceof PDO) { try { $pdo = null; } catch (\Throwable $e) {} }
  if (is_resource($lockFp)) {
    @flock($lockFp, LOCK_UN);
    @fclose($lockFp);
    // Lock file is only advisory; safe to unlink
    @unlink($lockPath);
  }
}
