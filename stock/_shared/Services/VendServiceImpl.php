<?php
declare(strict_types=1);
/**
 * File: VendServiceImpl.php
 * Purpose: Placeholder Vend integration for manual consignments
 * Author: GitHub Copilot
 * Last Modified: 2025-09-29
 * Dependencies: Payloads.php
 */

namespace Modules\Transfers\Stock\Shared\Services;

use PDO; use Throwable;

final class VendServiceImpl
{
    /**
     * Stub manual consignment upsert.
     * Writes a mirror row into transfer_vend_mirror (create if not exists) and logs audit.
     * @return array{ok:bool,warning?:string,vend_transfer_id?:string,vend_number?:string,vend_url?:string}
     */
    public function upsertManualConsignment(PackSendRequest $request, TxResult $txResult, HandlerPlan $plan): array
    {
        $pdo = $this->pdo();
        $transferId = $request->transferId;
        $vendId = null; $vendNumber = null; $warning = null;
        try {
            // Ensure mirror table exists (idempotent lightweight DDL)
            $pdo->exec('CREATE TABLE IF NOT EXISTS transfer_vend_mirror (
                id INT AUTO_INCREMENT PRIMARY KEY,
                transfer_id INT NOT NULL,
                vend_transfer_id VARCHAR(64) NULL,
                vend_number VARCHAR(64) NULL,
                status VARCHAR(32) NOT NULL DEFAULT "stubbed",
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (transfer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

            $ins = $pdo->prepare('INSERT INTO transfer_vend_mirror (transfer_id, vend_transfer_id, vend_number, status) VALUES (:tid,:vid,:vnum,:st)');
            $ins->execute([
                ':tid' => $transferId,
                ':vid' => $vendId,
                ':vnum'=> $vendNumber,
                ':st'  => 'stubbed'
            ]);
            $mirrorId = (int)$pdo->lastInsertId();
            $this->audit($pdo, $transferId, 'vend_upsert_stub', [ 'mirror_id'=>$mirrorId, 'vend_transfer_id'=>$vendId, 'vend_number'=>$vendNumber ]);
        } catch (Throwable $e) {
            $warning = 'Vend mirror stub failed: '.$e->getMessage();
        }
        return [ 'ok'=> true, 'vend_transfer_id'=>$vendId, 'vend_number'=>$vendNumber, 'vend_url'=> null, 'warning'=>$warning ];
    }

    private function audit(PDO $pdo, int $transferId, string $action, array $data): void
    {
        try {
            $pdo->exec('CREATE TABLE IF NOT EXISTS transfer_audit_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                transfer_id INT NOT NULL,
                action VARCHAR(64) NOT NULL,
                meta JSON NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX(transfer_id), INDEX(action)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            $stmt=$pdo->prepare('INSERT INTO transfer_audit_log(transfer_id,action,meta) VALUES(:tid,:act,:meta)');
            $stmt->execute([':tid'=>$transferId,':act'=>$action,':meta'=>json_encode($data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
        } catch (Throwable $e) {
            error_log('VendServiceImpl audit fail: '.$e->getMessage());
        }
    }

    private function pdo(): PDO
    {
        if (class_exists('\\Core\\DB') && method_exists('\\Core\\DB','instance')) { $pdo=\Core\DB::instance(); }
        elseif (function_exists('cis_pdo')) { $pdo=cis_pdo(); }
        elseif (!empty($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO){ $pdo=$GLOBALS['pdo']; }
        else { throw new \RuntimeException('DB not initialised'); }
        if(!$pdo instanceof PDO) throw new \RuntimeException('No PDO instance for vend mirror');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }
}
