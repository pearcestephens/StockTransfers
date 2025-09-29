<?php
declare(strict_types=1);
/**
 * File: IdempotencyStoreDb.php
 * Purpose: Persist idempotent responses for pack/send API
 * Author: GitHub Copilot
 * Last Modified: 2025-09-29
 * Dependencies: Util\Db, Services\Payloads
 */

namespace Modules\Transfers\Stock\Shared\Services;

use Modules\Transfers\Stock\Shared\Util\Db;
use Modules\Transfers\Stock\Shared\Util\Json;
use PDO;
use PDOException;

final class IdempotencyStoreDb
{
    private PDO $pdo;
    private bool $dbAvailable = true;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::pdo();
    }

    public function fetch(string $cacheKey): ?IdempotencyRecord
    {
        if ($cacheKey === '') {
            return null;
        }

        if ($this->dbAvailable) {
            try {
                $stmt = $this->pdo->prepare('SELECT cache_key, body_hash, envelope_json, stored_at FROM transfer_validation_cache WHERE cache_key = :cacheKey LIMIT 1');
                $stmt->execute([':cacheKey' => $cacheKey]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $record = new IdempotencyRecord();
                    $record->cacheKey = (string)$row['cache_key'];
                    $record->bodyHash = (string)$row['body_hash'];
                    $record->responseJson = (string)$row['envelope_json'];
                    $record->storedAt = (string)$row['stored_at'];
                    return $record;
                }
            } catch (PDOException) {
                $this->dbAvailable = false;
            }
        }

        if (function_exists('apcu_fetch')) {
            $cached = apcu_fetch($this->apcuKey($cacheKey));
            if (is_array($cached) && isset($cached['body_hash'], $cached['envelope_json'])) {
                $record = new IdempotencyRecord();
                $record->cacheKey = $cacheKey;
                $record->bodyHash = (string)$cached['body_hash'];
                $record->responseJson = (string)$cached['envelope_json'];
                $record->storedAt = $cached['stored_at'] ?? '';
                return $record;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $envelope
     */
    public function save(string $cacheKey, string $bodyHash, array $envelope): void
    {
        if ($cacheKey === '') {
            return;
        }

        $json = Json::encode($envelope);

        if ($this->dbAvailable) {
            try {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO transfer_validation_cache
                        (cache_key, body_hash, envelope_json, stored_at, expires_at)
                     VALUES
                        (:cache_key, :body_hash, :envelope_json, NOW(), DATE_ADD(NOW(), INTERVAL 3 DAY))
                     ON DUPLICATE KEY UPDATE
                        body_hash = VALUES(body_hash),
                        envelope_json = VALUES(envelope_json),
                        stored_at = NOW(),
                        expires_at = DATE_ADD(NOW(), INTERVAL 3 DAY)'
                );
                $stmt->execute([
                    ':cache_key' => $cacheKey,
                    ':body_hash' => $bodyHash,
                    ':envelope_json' => $json,
                ]);
            } catch (PDOException) {
                $this->dbAvailable = false;
            }
        }

        if (function_exists('apcu_store')) {
            apcu_store($this->apcuKey($cacheKey), [
                'body_hash' => $bodyHash,
                'envelope_json' => $json,
                'stored_at' => date('c'),
            ], 259200);
        }
    }

    private function apcuKey(string $cacheKey): string
    {
        return 'pack_send_idem:' . hash('sha256', $cacheKey);
    }
}
