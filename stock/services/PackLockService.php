<?php
declare(strict_types=1);
/**
 * PackLockService
 * Manages exclusive packing locks + queued access requests for a transfer.
 */
namespace Modules\Transfers\Stock\Services;

use mysqli;
use RuntimeException;

class PackLockService
{
    private mysqli $db;
    private int $lockSeconds = 3600;     // hard expiry if heartbeats stop (1 hour)
    private int $heartbeatGrace = 90;    // consider stale if no heartbeat in 90s
    // Holder decision / request confirmation window (seconds)
    // Default lowered from 60 to 10 to align with normalized auto-accept flow.
    private int $requestConfirmWindow = 10; 
    private static bool $tablesInitialized = false; // prevent repeat DDL per request
    private static ?mysqli $sharedConn = null;       // cache connection for reuse

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?: $this->connect();
        // Allow override via environment PACK_LOCK_REQUEST_WINDOW (integer seconds)
        $envOverride = getenv('PACK_LOCK_REQUEST_WINDOW');
        if($envOverride !== false && ctype_digit($envOverride) && (int)$envOverride > 0){
            $this->requestConfirmWindow = (int)$envOverride;
        }
        $this->createTablesIfNotExists();
    }

    private function connect(): mysqli
    {
        // 1. Reuse cached static connection
        if (self::$sharedConn instanceof mysqli) {
            return self::$sharedConn;
        }
        // 2. Reuse legacy global handle if present (provisioned by app bootstrap)
        global $db; // phpcs:ignore
        if ($db instanceof mysqli) {
            self::$sharedConn = $db;
            return $db;
        }
        // 3. Build from environment variables
        $env = static function(string $k, ?string $alt1=null, ?string $alt2=null, $default=null) {
            foreach ([$k,$alt1,$alt2] as $c) {
                if ($c && getenv($c) !== false && getenv($c) !== '') return getenv($c);
            }
            return $default;
        };
        $host = (string)$env('DB_HOST','DATABASE_HOST','DB_HOSTNAME','localhost');
        $port = (int)$env('DB_PORT','DATABASE_PORT',null,3306);
        $user = (string)$env('DB_USER','DB_USERNAME','DATABASE_USER','root');
        $pass = (string)$env('DB_PASS','DB_PASSWORD','DATABASE_PASSWORD','');
        $name = (string)$env('DB_NAME','DB_DATABASE','DATABASE_NAME','');
        $sock = (string)$env('DB_SOCKET','MYSQL_SOCKET',null,'');
        if ($name === '') {
            throw new RuntimeException('DB handle not provided (DB_NAME env missing and no global).');
        }
        mysqli_report(MYSQLI_REPORT_OFF);
        $conn = @new mysqli($host, $user, $pass, $name, $port, $sock !== '' ? $sock : null);
        if ($conn->connect_errno) {
            throw new RuntimeException('DB connect failure: '.$conn->connect_errno);
        }
        if (!$conn->set_charset('utf8mb4')) {
            error_log('PackLockService: failed to set charset utf8mb4: '.$conn->error);
        }
        self::$sharedConn = $conn;
        return $conn;
    }

    private function now(): string { return date('Y-m-d H:i:s'); }

    private function createTablesIfNotExists(): void
    {
        if (self::$tablesInitialized) return; // Already ensured this request
        // Create pack locks table
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS transfer_pack_locks (
              transfer_id    VARCHAR(64) NOT NULL PRIMARY KEY,
              user_id        INT UNSIGNED NOT NULL,
              acquired_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              expires_at     DATETIME NOT NULL,
              heartbeat_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              client_fingerprint VARCHAR(64) DEFAULT NULL,
              INDEX (expires_at),
              INDEX (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        // Create pack lock requests table
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS transfer_pack_lock_requests (
              id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              transfer_id   VARCHAR(64) NOT NULL,
              user_id       INT UNSIGNED NOT NULL,
              requested_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              status        ENUM('pending','accepted','declined','expired','cancelled') NOT NULL DEFAULT 'pending',
              responded_at  DATETIME NULL,
              expires_at    DATETIME NULL,
              client_fingerprint VARCHAR(64) DEFAULT NULL,
              INDEX (transfer_id, status),
              INDEX (expires_at),
              INDEX (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        // Create audit table
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS transfer_pack_lock_audit (
              id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              transfer_id   INT UNSIGNED NOT NULL,
              user_id       INT UNSIGNED NOT NULL,
              action        VARCHAR(50) NOT NULL,
              status        VARCHAR(20) NOT NULL DEFAULT 'success',
              metadata      JSON NULL,
              ip_address    VARCHAR(45) NULL,
              user_agent    VARCHAR(500) NULL,
              created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              INDEX (transfer_id, action),
              INDEX (user_id, created_at),
              INDEX (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        self::$tablesInitialized = true;
    }

    /**
     * Fetch active lock (returns null if none or stale). Accepts string|int.
     */
    public function getLock(string|int $transferId): ?array
    {
        $tid = (string)$transferId;
        $stmt = $this->db->prepare("SELECT transfer_id, user_id, acquired_at, expires_at, heartbeat_at FROM transfer_pack_locks WHERE transfer_id=? LIMIT 1");
        $stmt->bind_param('s', $tid);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$res) return null;
        // Stale?
        if (strtotime($res['expires_at']) < time() || strtotime($res['heartbeat_at']) < time() - $this->heartbeatGrace) {
            $this->releaseLock($tid, (int)$res['user_id'], true);
            return null;
        }
        return $res;
    }

    /** Acquire or extend lock. */
    public function acquire(string|int $transferId, int $userId, ?string $fingerprint = null): array
    {
        $tid = (string)$transferId;
        $existing = $this->getLock($tid);
        if ($existing && (int)$existing['user_id'] !== $userId) {
            return ['success'=>false,'conflict'=>true,'holder'=>$existing];
        }
        $expires = date('Y-m-d H:i:s', time() + $this->lockSeconds);
        if ($existing) {
            $stmt = $this->db->prepare("UPDATE transfer_pack_locks SET user_id=?, acquired_at=acquired_at, expires_at=?, heartbeat_at=? WHERE transfer_id=?");
            $now = $this->now();
            $stmt->bind_param('isss', $userId, $expires, $now, $tid);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $this->db->prepare("REPLACE INTO transfer_pack_locks(transfer_id,user_id,acquired_at,expires_at,heartbeat_at,client_fingerprint) VALUES(?,?,NOW(),?,?,?)");
            $now = $this->now();
            $stmt->bind_param('sisss', $tid, $userId, $expires, $now, $fingerprint);
            $stmt->execute();
            $stmt->close();
        }
        return ['success'=>true,'lock'=>$this->getLock($tid)];
    }

    /** Heartbeat to extend lock. */
    public function heartbeat(string|int $transferId, int $userId): array
    {
        $tid = (string)$transferId;
        $stmt = $this->db->prepare("UPDATE transfer_pack_locks SET heartbeat_at=NOW(), expires_at=DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE transfer_id=? AND user_id=?");
        $stmt->bind_param('isi', $this->lockSeconds, $tid, $userId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected === 0) return ['success'=>false,'error'=>'not_holder'];
        return ['success'=>true,'lock'=>$this->getLock($tid)];
    }

    /** Release lock (force ignores user ownership). */
    public function releaseLock(string|int $transferId, int $userId, bool $force=false): array
    {
        $tid = (string)$transferId;
        if ($force) {
            $stmt = $this->db->prepare("DELETE FROM transfer_pack_locks WHERE transfer_id=?");
            $stmt->bind_param('s', $tid);
        } else {
            $stmt = $this->db->prepare("DELETE FROM transfer_pack_locks WHERE transfer_id=? AND user_id=?");
            $stmt->bind_param('si', $tid, $userId);
        }
        $stmt->execute();
        $removed = $stmt->affected_rows > 0;
        $stmt->close();
        return ['success'=>$removed];
    }

    /** Queue a request to obtain lock ownership. */
    public function requestAccess(string|int $transferId, int $userId, ?string $fingerprint=null): array
    {
        $tid = (string)$transferId;
        $lock = $this->getLock($tid);
        if ($lock && (int)$lock['user_id'] === $userId) {
            return ['success'=>true,'already_holder'=>true,'lock'=>$lock];
        }
    $expires = date('Y-m-d H:i:s', time() + $this->requestConfirmWindow);
        $stmt = $this->db->prepare("INSERT INTO transfer_pack_lock_requests(transfer_id,user_id,expires_at,client_fingerprint) VALUES(?,?,?,?)");
        $stmt->bind_param('siss', $tid, $userId, $expires, $fingerprint);
        $stmt->execute();
        $id = $stmt->insert_id;
        $stmt->close();
        return ['success'=>true,'request_id'=>$id,'expires_at'=>$expires,'holder'=>$lock];
    }

    /** List pending takeover requests for current holder. */
    public function holderPendingRequests(string|int $transferId, int $holderUserId): array
    {
        $tid = (string)$transferId;
        $stmt = $this->db->prepare("SELECT id,user_id,requested_at,expires_at FROM transfer_pack_lock_requests WHERE transfer_id=? AND status='pending' AND expires_at>NOW() ORDER BY requested_at ASC");
        $stmt->bind_param('s', $tid);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    /** Respond to an access request (accept = transfer ownership). */
    public function respond(int $requestId, int $holderUserId, bool $accept): array
    {
        $stmt = $this->db->prepare("SELECT r.id,r.transfer_id,r.user_id,r.status,l.user_id AS holder FROM transfer_pack_lock_requests r LEFT JOIN transfer_pack_locks l ON l.transfer_id=r.transfer_id WHERE r.id=? LIMIT 1");
        $stmt->bind_param('i', $requestId);
        $stmt->execute();
        $req = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$req) return ['success'=>false,'error'=>'request_not_found'];
        if ((int)$req['holder'] !== $holderUserId) return ['success'=>false,'error'=>'not_holder'];
        if ($req['status'] !== 'pending') return ['success'=>false,'error'=>'already_final'];

        if ($accept) {
            // mark accepted
            $stmt = $this->db->prepare("UPDATE transfer_pack_lock_requests SET status='accepted', responded_at=NOW() WHERE id=?");
            $stmt->bind_param('i', $requestId);
            $stmt->execute();
            $stmt->close();
            // transfer lock ownership directly (bypass conflict logic)
            $expires = date('Y-m-d H:i:s', time() + $this->lockSeconds);
            $stmt = $this->db->prepare("UPDATE transfer_pack_locks SET user_id=?, acquired_at=NOW(), expires_at=?, heartbeat_at=NOW() WHERE transfer_id=?");
            $stmt->bind_param('isi', $req['user_id'], $expires, $req['transfer_id']);
            $stmt->execute();
            $stmt->close();
            return ['success'=>true,'accepted'=>true,'lock'=>$this->getLock($req['transfer_id'])];
        }
        $stmt = $this->db->prepare("UPDATE transfer_pack_lock_requests SET status='declined', responded_at=NOW() WHERE id=?");
        $stmt->bind_param('i', $requestId);
        $stmt->execute();
        $stmt->close();
        return ['success'=>true,'accepted'=>false];
    }

    /** Cleanup expired lock requests and stale locks. */
    public function cleanup(): void
    {
        // Audit expiries before deletion
        $expired = $this->db->query("SELECT id, transfer_id, user_id FROM transfer_pack_lock_requests WHERE status='pending' AND expires_at < NOW()");
        if($expired && $expired->num_rows){
            // Lazy create audit service only if needed
            try { $audit = new LockAuditService(); } catch(\Throwable $e){ $audit=null; }
            while($row = $expired->fetch_assoc()){
                if(isset($audit)) $audit->requestExpire((int)$row['transfer_id'], (int)$row['id'], (int)$row['user_id']);
            }
        }
        $this->db->query("DELETE FROM transfer_pack_lock_requests WHERE status='pending' AND expires_at < NOW()");
        $this->db->query("DELETE FROM transfer_pack_locks WHERE expires_at < NOW() OR heartbeat_at < DATE_SUB(NOW(), INTERVAL {$this->heartbeatGrace} SECOND)");
    }
}
