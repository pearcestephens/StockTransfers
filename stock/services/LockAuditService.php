<?php
declare(strict_types=1);

namespace Modules\Transfers\Stock\Services;

use PDO;
use Throwable;

final class LockAuditService
{
    private PDO $db; 
    private TransferLogger $logger;
    
    public function __construct()
    { 
        $this->db = cis_pdo(); 
        $this->logger = new TransferLogger();
        $this->createTablesIfNotExists();
    }
    
    private function createTablesIfNotExists(): void
    {
        try {
            // Create general audit log table
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS transfer_audit_log (
                  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                  entity_type   VARCHAR(50) NOT NULL DEFAULT 'transfer',
                  entity_pk     INT UNSIGNED NOT NULL,
                  transfer_pk   INT UNSIGNED NOT NULL,
                  action        VARCHAR(100) NOT NULL,
                  status        VARCHAR(50) NOT NULL DEFAULT 'success',
                  actor_type    VARCHAR(20) NOT NULL DEFAULT 'user',
                  actor_id      INT UNSIGNED NULL,
                  metadata      JSON NULL,
                  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  INDEX (transfer_pk, action),
                  INDEX (actor_id, created_at),
                  INDEX (created_at),
                  INDEX (entity_type, entity_pk)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (\Throwable $e) {
            // Log but don't fail - tables may already exist
            error_log('LockAuditService table creation warning: ' . $e->getMessage());
        }
    }

    public function audit(int $transferId, string $action, string $status, array $extra=[]): void
    {
        try {
            // auto-augment metadata with contextual signals (ip, ua, optional fingerprint) for ML / security
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            if($ip && empty($extra['ip'])) $extra['ip'] = $ip;
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
            if($ua && empty($extra['ua'])) $extra['ua'] = substr($ua,0,255);
            $fp = $_POST['fingerprint'] ?? $_GET['fingerprint'] ?? null;
            if($fp && empty($extra['fingerprint'])) $extra['fingerprint'] = substr((string)$fp,0,120);
            $meta = $extra ? json_encode($extra, JSON_UNESCAPED_SLASHES) : null;
            $stmt = $this->db->prepare(
                "INSERT INTO transfer_audit_log (entity_type, entity_pk, transfer_pk, action, status, actor_type, actor_id, metadata, created_at)
                 VALUES ('transfer', :tid, :tid, :action, :status, 'user', :actor, :meta, NOW())"
            );
            $stmt->execute([
                'tid'=>$transferId,
                'action'=>$action,
                'status'=>$status,
                'actor'=>$extra['actor_id'] ?? null,
                'meta'=>$meta
            ]);
        } catch(Throwable $e) {
            // swallow; audit shouldn't break flow
        }
    }

    private function log(string $event, array $data): void
    { $this->logger->log($event, $data + ['event_data'=>$data['event_data'] ?? []]); }

    public function lockAcquire(int $transferId, int $userId, bool $reacquire=false): void
    { $this->audit($transferId,'LOCK_ACQUIRE','success',['actor_id'=>$userId,'reacquire'=>$reacquire]); $this->log('LOCK_ACQUIRE',[ 'transfer_id'=>$transferId,'actor_user_id'=>$userId,'event_data'=>['reacquire'=>$reacquire] ]); }

    public function lockRelease(int $transferId, int $userId, bool $force=false): void
    { $this->audit($transferId,'LOCK_RELEASE','success',['actor_id'=>$userId,'force'=>$force]); $this->log('LOCK_RELEASE',[ 'transfer_id'=>$transferId,'actor_user_id'=>$userId,'event_data'=>['force'=>$force] ]); }

    public function lockRequest(int $transferId, int $userId, int $requestId): void
    { $this->audit($transferId,'LOCK_REQUEST','pending',['actor_id'=>$userId,'request_id'=>$requestId]); $this->log('LOCK_REQUEST',[ 'transfer_id'=>$transferId,'actor_user_id'=>$userId,'event_data'=>['request_id'=>$requestId] ]); }

    public function lockRespond(int $transferId, int $holderUserId, int $requestUserId, int $requestId, bool $accepted): void
    { $this->audit($transferId,'LOCK_RESPOND',$accepted?'accepted':'declined',['actor_id'=>$holderUserId,'request_id'=>$requestId,'request_user'=>$requestUserId]); $this->log('LOCK_RESPOND',[ 'transfer_id'=>$transferId,'actor_user_id'=>$holderUserId,'event_data'=>['request_id'=>$requestId,'granted_to'=>$accepted?$requestUserId:null,'accepted'=>$accepted] ]); if($accepted){ $this->audit($transferId,'LOCK_TRANSFER','success',['actor_id'=>$holderUserId,'to'=>$requestUserId]); $this->log('LOCK_TRANSFER',[ 'transfer_id'=>$transferId,'actor_user_id'=>$holderUserId,'event_data'=>['to'=>$requestUserId] ]); } }

    public function heartbeat(int $transferId, int $userId, bool $ok): void
    { if(!$ok){ $this->audit($transferId,'LOCK_HEARTBEAT','lost',['actor_id'=>$userId]); $this->log('LOCK_HEARTBEAT',[ 'transfer_id'=>$transferId,'actor_user_id'=>$userId,'event_data'=>['lost'=>true] ]); } }

    public function requestExpire(int $transferId, int $requestId, int $requestUserId): void
    { $this->audit($transferId,'LOCK_REQUEST_EXPIRE','expired',[ 'actor_id'=>$requestUserId,'request_id'=>$requestId ]); $this->log('LOCK_REQUEST_EXPIRE',[ 'transfer_id'=>$transferId,'actor_user_id'=>$requestUserId,'event_data'=>['request_id'=>$requestId] ]); }
}
