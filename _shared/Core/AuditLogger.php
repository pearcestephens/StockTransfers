<?php
declare(strict_types=1);

namespace CIS\Shared\Core;

use CIS\Shared\Config;
use CIS\Shared\Contracts\AuditLoggerInterface;
use CIS\Shared\Support\Json;
use PDO;

/**
 * AuditLogger.php
 *
 * Persists audit events for transfer actions.
 *
 * @package CIS\Shared\Core
 */
final class AuditLogger implements AuditLoggerInterface
{
    /** @var PDO */
    private PDO $pdo;

    /**
     * @param PDO|null $pdo Optional PDO instance.
     */
    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Config::pdo();
    }

    /**
     * {@inheritDoc}
     */
    public function write(
        string $action,
        string $status,
        array $before,
        array $after,
        array $meta = [],
        ?array $apiResponse = null
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO transfer_audit_log
                (entity_type, action, status, actor_type, actor_id, data_before, data_after, metadata, api_response, created_at)
             VALUES ("transfer", :action, :status, :actor_type, :actor_id, :before, :after, :meta, :api, NOW())'
        );
        $statement->execute([
            ':action' => $action,
            ':status' => $status,
            ':actor_type' => 'user',
            ':actor_id' => null,
            ':before' => Json::encode($before),
            ':after' => Json::encode($after),
            ':meta' => Json::encode($meta),
            ':api' => $apiResponse ? Json::encode($apiResponse) : null,
        ]);
    }
}
