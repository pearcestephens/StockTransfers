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
    private int $lockSeconds = 300;      // hard expiry if heartbeats stop (5 min)
    private int $heartbeatGrace = 90;    // consider stale if no heartbeat in 90s
    private int $requestConfirmWindow = 60; // seconds requester has to accept

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?: $this->connect();
    }

    private function connect(): mysqli
    {
        global $db; // reuse global if available
        if ($db instanceof mysqli) return $db;
        throw new RuntimeException('DB handle not provided.');
    }

    private function now(): string { return date('Y-m-d H:i:s'); }

    public function getLock(int $transferId): ?array
    {
        $stmt = $this->db->prepare("SELECT transfer_id, user_id, acquired_at, expires_at, heartbeat_at FROM transfer_pack_locks WHERE transfer_id=? LIMIT 1");
        $stmt->bind_param('i', $transferId);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$res) return null;
        // Stale?
        if (strtotime($res['expires_at']) < time() || strtotime($res['heartbeat_at']) < time() - $this->heartbeatGrace) {
            $this->releaseLock($transferId, (int)$res['user_id'], true);
            return null;
        }
        return $res;
    }

    public function acquire(int $transferId, int $userId, ?string $fingerprint = null): array
    {
        $existing = $this->getLock($transferId);
        if ($existing && (int)$existing['user_id'] !== $userId) {
            return ['success'=>false,'conflict'=>true,'holder'=>$existing];
        }
        $expires = date('Y-m-d H:i:s', time() + $this->lockSeconds);
        if ($existing) {
            $stmt = $this->db->prepare("UPDATE transfer_pack_locks SET user_id=?, acquired_at=acquired_at, expires_at=?, heartbeat_at=? WHERE transfer_id=?");
            $now = $this->now();
            $stmt->bind_param('issi', $userId, $expires, $now, $transferId);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $this->db->prepare("REPLACE INTO transfer_pack_locks(transfer_id,user_id,acquired_at,expires_at,heartbeat_at,client_fingerprint) VALUES(?,?,NOW(),?,?,?)");
            $now = $this->now();
            $stmt->bind_param('iisss', $transferId, $userId, $expires, $now, $fingerprint);
            $stmt->execute();
            $stmt->close();
        }
        return ['success'=>true,'lock'=>$this->getLock($transferId)];
    }

    public function heartbeat(int $transferId, int $userId): array
    {
        $stmt = $this->db->prepare("UPDATE transfer_pack_locks SET heartbeat_at=NOW(), expires_at=DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE transfer_id=? AND user_id=?");
        $stmt->bind_param('iii', $this->lockSeconds, $transferId, $userId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected === 0) return ['success'=>false,'error'=>'not_holder'];
        return ['success'=>true,'lock'=>$this->getLock($transferId)];
    }

    public function releaseLock(int $transferId, int $userId, bool $force=false): array
    {
        if ($force) {
            $stmt = $this->db->prepare("DELETE FROM transfer_pack_locks WHERE transfer_id=?");
            $stmt->bind_param('i', $transferId);
        } else {
            $stmt = $this->db->prepare("DELETE FROM transfer_pack_locks WHERE transfer_id=? AND user_id=?");
            $stmt->bind_param('ii', $transferId, $userId);
        }
        $stmt->execute();
        $removed = $stmt->affected_rows > 0;
        $stmt->close();
        return ['success'=>$removed];
    }

    public function requestAccess(int $transferId, int $userId, ?string $fingerprint=null): array
    {
        $lock = $this->getLock($transferId);
        if ($lock && (int)$lock['user_id'] === $userId) {
            return ['success'=>true,'already_holder'=>true,'lock'=>$lock];
        }
        $expires = date('Y-m-d H:i:s', time() + $this->requestConfirmWindow);
        $stmt = $this->db->prepare("INSERT INTO transfer_pack_lock_requests(transfer_id,user_id,expires_at,client_fingerprint) VALUES(?,?,?,?)");
        $stmt->bind_param('iiss', $transferId, $userId, $expires, $fingerprint);
        $stmt->execute();
        $id = $stmt->insert_id;
        $stmt->close();
        return ['success'=>true,'request_id'=>$id,'expires_at'=>$expires,'holder'=>$lock];
    }

    public function holderPendingRequests(int $transferId, int $holderUserId): array
    {
        $stmt = $this->db->prepare("SELECT id,user_id,requested_at,expires_at FROM transfer_pack_lock_requests WHERE transfer_id=? AND status='pending' AND expires_at>NOW() ORDER BY requested_at ASC");
        $stmt->bind_param('i', $transferId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function respond(int $requestId, int $holderUserId, bool $accept): array
    {
        // fetch request + lock
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
            return ['success'=>true,'accepted'=>true,'lock'=>$this->getLock((int)$req['transfer_id'])];
        }
        $stmt = $this->db->prepare("UPDATE transfer_pack_lock_requests SET status='declined', responded_at=NOW() WHERE id=?");
        $stmt->bind_param('i', $requestId);
        $stmt->execute();
        $stmt->close();
        return ['success'=>true,'accepted'=>false];
    }

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
