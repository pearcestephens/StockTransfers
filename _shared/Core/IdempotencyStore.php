<?php
declare(strict_types=1);

namespace CIS\Shared\Core;

use CIS\Shared\Config;
use CIS\Shared\Contracts\IdempotencyStoreInterface;
use CIS\Shared\Support\Json;
use PDO;

/**
 * IdempotencyStore.php
 *
 * Basic database-backed idempotency store.
 *
 * @package CIS\Shared\Core
 */
final class IdempotencyStore implements IdempotencyStoreInterface
{
    /** @var PDO */
    private PDO $pdo;

    /**
     * @param PDO|null $pdo Optional PDO instance for testing.
     */
    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Config::pdo();
    }

    /**
     * {@inheritDoc}
     */
    public function hash(array $json): string
    {
        return hash('sha256', Json::encode($json));
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $key): ?array
    {
        $statement = $this->pdo->prepare('SELECT response_json FROM idempotency_keys WHERE idem_key = :key LIMIT 1');
        try {
            $statement->execute([':key' => $key]);
        } catch (\Throwable) {
            return null;
        }

        $row = $statement->fetch();
        if (!$row) {
            return null;
        }

        $raw = (string) ($row['response_json'] ?? '');
        if ($raw === '') {
            return null;
        }

        try {
            return Json::decode($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function put(string $key, array $envelope): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS idempotency_keys(
                idem_key VARCHAR(100) PRIMARY KEY,
                response_json LONGTEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $statement = $this->pdo->prepare(
            'INSERT INTO idempotency_keys (idem_key, response_json)
             VALUES (:key, :json)
             ON DUPLICATE KEY UPDATE response_json = VALUES(response_json)'
        );
        $statement->execute([':key' => $key, ':json' => Json::encode($envelope)]);
    }
}
