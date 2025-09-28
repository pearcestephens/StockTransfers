<?php
declare(strict_types=1);

namespace Modules\Transfers\Stock\Services;

use Core\DB;
use PDO;

final class ExecutionService
{
    private PDO $db;
    public function __construct() { $this->db = DB::instance(); }

    public function begin(int $configId, int $transferId = 0, bool $simulation = true, ?string $alias=null, ?string $executedBy=null): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO transfer_executions
               (transfer_id, public_id, alias_code, config_id, simulation_mode, status, total_items_processed, created_at, updated_at, executed_by)
             VALUES (:tid, :pub, :alias, :cfg, :sim, 'running', 0, NOW(), NOW(), :by)"
        );
        $pub = bin2hex(random_bytes(8));
        $stmt->execute([
            'tid'=>$transferId ?: null,
            'pub'=>$pub,
            'alias'=>$alias,
            'cfg'=>$configId,
            'sim'=>$simulation ? 1 : 0,
            'by'=>$executedBy
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function complete(int $execId, int $processed, ?string $error=null): void
    {
        $status = $error ? 'failed' : 'completed';
        $stmt = $this->db->prepare(
            "UPDATE transfer_executions
                SET status=:st, total_items_processed=:p, completed_at = NOW(), updated_at = NOW(), error_message = :err
              WHERE id=:id"
        );
        $stmt->execute(['st'=>$status, 'p'=>$processed, 'id'=>$execId, 'err'=>$error]);
    }
}
