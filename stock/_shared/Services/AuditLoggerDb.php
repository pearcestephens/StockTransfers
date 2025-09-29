<?php
declare(strict_types=1);
/**
 * File: AuditLoggerDb.php
 * Purpose: Persist audit trail entries for pack/send operations
 * Author: GitHub Copilot
 * Last Modified: 2025-09-29
 * Dependencies: Util\Db, Util\Json, Util\Time, Services\Payloads
 */

namespace Modules\Transfers\Stock\Shared\Services;

use Modules\Transfers\Stock\Shared\Util\Db;
use Modules\Transfers\Stock\Shared\Util\Json;
use Modules\Transfers\Stock\Shared\Util\Time;
use PDO;
use Throwable;

final class AuditLoggerDb
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::pdo();
    }

    /**
     * @param array<string, mixed> $envelope
     * @param array<string, mixed> $vendResult
     */
    public function log(PackSendRequest $request, HandlerPlan $plan, GuardianResult $guardian, array $envelope, array $vendResult, string $status): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO transfer_audit_log
                    (entity_type, entity_pk, transfer_pk, action, status, actor_type, actor_id, meta, payload_before, payload_after, api_response, created_at)
                 VALUES
                    (:entity_type, :entity_pk, :transfer_pk, :action, :status, :actor_type, :actor_id, :meta, :payload_before, :payload_after, :api_response, :created_at)'
            );

            $meta = [
                'guardian_tier' => $guardian->tier,
                'send_now' => $plan->sendNow,
                'mode' => $plan->mode,
                'warnings' => $plan->warnings,
            ];

            $payloadBefore = [
                'transfer_id' => $request->transferId,
                'mode' => $request->mode,
                'send_now' => $request->sendNow,
                'totals' => $request->totals,
            ];

            $payloadAfter = [
                'shipment_id' => $envelope['data']['shipment_id'] ?? null,
                'box_count' => $envelope['data']['box_count'] ?? null,
                'total_weight_kg' => $envelope['data']['total_weight_kg'] ?? null,
            ];

            $stmt->execute([
                ':entity_type' => 'transfer',
                ':entity_pk' => $request->transferId,
                ':transfer_pk' => $request->transferId,
                ':action' => $plan->mode,
                ':status' => $status,
                ':actor_type' => 'user',
                ':actor_id' => $request->userId ?: null,
                ':meta' => Json::encode($meta),
                ':payload_before' => Json::encode($payloadBefore),
                ':payload_after' => Json::encode($payloadAfter),
                ':api_response' => Json::encode([
                    'vend' => [
                        'ok' => $vendResult['ok'] ?? false,
                        'warning' => $vendResult['warning'] ?? null,
                        'vend_transfer_id' => $vendResult['vend_transfer_id'] ?? null,
                    ],
                ]),
                ':created_at' => Time::nowUtcString(),
            ]);
        } catch (Throwable $exception) {
            error_log('[pack_send_audit] failed to log audit row: ' . $exception->getMessage());
        }
    }
}
