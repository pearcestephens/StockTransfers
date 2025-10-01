<?php
declare(strict_types=1);
/**
 * File: OrchestratorImpl.php
 * Purpose: Core orchestrator for pack/send API flows
 * Author: GitHub Copilot
 * Last Modified: 2025-09-29
 * Dependencies: RequestValidatorImpl, GuardianService, ParcelPlannerDb, HandlerFactoryImpl, PersistenceStock, VendServiceImpl, IdempotencyStoreDb, AuditLoggerDb
 */

namespace Modules\Transfers\Shared\Services;

use Modules\Transfers\Shared\Handlers\HandlerFactoryImpl;
use Modules\Transfers\Shared\Handlers\HandlerInterface;
use Modules\Transfers\Shared\Util\Json;
use Throwable;

final class OrchestratorImpl
{
    public function __construct(
        private readonly RequestValidatorImpl $validator,
        private readonly GuardianService $guardian,
        private readonly IdempotencyStoreDb $idempotency,
        private readonly HandlerFactoryImpl $handlerFactory,
        private readonly ParcelPlannerDb $planner,
        private readonly PersistenceStock $persistence,
        private readonly VendServiceImpl $vend,
        private readonly AuditLoggerDb $audit
    ) {
    }

    /**
     * @return array{ok:bool,request_id:string,data:array<string,mixed>|null,error:array<string,mixed>|null,warnings:array<int,string>}
     */
    public function handle(PackSendRequest $request): array
    {
        $validation = $this->validator->validate($request);
        $guardian = $this->guardian->evaluate($request, $validation);

        if (!$validation->isValid()) {
            $envelope = $this->errorEnvelope($request, 'VALIDATION', 'Validation failed', $validation->errors, $guardian);
            $this->audit->log($request, $this->failedPlan($request), $guardian, $envelope, ['ok' => false], 'error');
            return $envelope;
        }

        if ($guardian->tier === 'red') {
            $envelope = $this->errorEnvelope($request, 'SYSTEM_RED', 'Guardian blocked dispatch', [], $guardian);
            $this->audit->log($request, $this->failedPlan($request), $guardian, $envelope, ['ok' => false], 'error');
            return $envelope;
        }

        $warnings = [];
        foreach ($validation->warnings as $warn) {
            $warnings[] = ($warn['message'] ?? 'Warning') . ' [' . ($warn['field'] ?? '') . ']';
        }
        foreach ($guardian->messages as $message) {
            if ($message !== '') {
                $warnings[] = $message;
            }
        }

        $idemKey = $request->idempotencyKey;
        if ($idemKey !== '') {
            $existing = $this->idempotency->fetch($idemKey);
            if ($existing !== null) {
                if (hash_equals($existing->bodyHash, $request->bodyHash())) {
                    $decoded = Json::decode($existing->responseJson);
                    if (!isset($decoded['meta']['idempotent_replay'])) {
                        $decoded['meta']['idempotent_replay'] = true;
                    }
                    return $decoded;
                }

                $envelope = $this->errorEnvelope(
                    $request,
                    'IDEMPOTENT_REPLAY',
                    'Idempotency key already used with different payload.',
                    [],
                    $guardian,
                    $warnings
                );
                $this->audit->log($request, $this->failedPlan($request), $guardian, $envelope, ['ok' => false], 'error');
                return $envelope;
            }
        }

        try {
            $handler = $this->resolveHandler($request->mode);
            $plan = $handler->plan($request, $validation, $this->planner);

            $txResult = $this->persistence->commit($request, $plan);

            $vendResult = $this->vend->upsertManualConsignment($request, $txResult, $plan);
            if (!($vendResult['ok'] ?? false) && isset($vendResult['warning'])) {
                $warnings[] = 'VEND_UPSERT_FAIL: ' . (string)$vendResult['warning'];
            }

            foreach ($plan->warnings as $warn) {
                $warnings[] = $warn;
            }

            $data = [
                'transfer_id' => $request->transferId,
                'shipment_id' => $txResult->shipmentId,
                'box_count' => $txResult->boxCount,
                'total_weight_kg' => $txResult->totalWeightKg,
                'mode' => $plan->mode,
                'send_now' => $plan->sendNow,
                'idempotency_key' => $idemKey ?: null,
                // Vend mirror metadata (may be null in stub/test mode)
                'vend_transfer_id' => $vendResult['vend_transfer_id'] ?? null,
                'vend_number' => $vendResult['vend_number'] ?? null,
                'vend_url' => $vendResult['vend_url'] ?? null,
                'vend_warning' => $vendResult['warning'] ?? null,
            ];

            $envelope = [
                'ok' => true,
                'request_id' => $request->requestId,
                'data' => $data,
                'error' => null,
                'warnings' => array_values(array_filter($warnings)),
                'meta' => [
                    'guardian_tier' => $guardian->tier,
                    'handler' => $plan->carrierLane,
                ],
            ];

            if ($idemKey !== '') {
                $this->idempotency->save($idemKey, $request->bodyHash(), $envelope);
            }

            $this->audit->log($request, $plan, $guardian, $envelope, $vendResult, 'success');

            return $envelope;
        } catch (Throwable $exception) {
            $warnings[] = 'UNHANDLED: ' . $exception->getMessage();
            $envelope = $this->errorEnvelope($request, 'UNEXPECTED_ERROR', 'Pack/Send failed', [], $guardian, $warnings);
            $this->audit->log($request, $this->failedPlan($request), $guardian, $envelope, ['ok' => false], 'error');
            return $envelope;
        }
    }

    private function resolveHandler(string $mode): HandlerInterface
    {
        return $this->handlerFactory->resolve($mode);
    }

    /**
     * @param list<array{code:string,field:string,message:string}> $details
     * @param list<string> $warnings
     */
    private function errorEnvelope(PackSendRequest $request, string $code, string $message, array $details, GuardianResult $guardian, array $warnings = []): array
    {
        return [
            'ok' => false,
            'request_id' => $request->requestId,
            'data' => null,
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ],
            'warnings' => array_values(array_filter($warnings)),
            'meta' => [
                'guardian_tier' => $guardian->tier,
            ],
        ];
    }

    private function failedPlan(PackSendRequest $request): HandlerPlan
    {
        $plan = new HandlerPlan();
        $plan->mode = $request->mode;
        $plan->deliveryMode = 'unknown';
        $plan->carrierLabel = 'failure';
        $plan->carrierLane = 'failure';
        $plan->sendNow = false;
        $plan->shouldDispatch = false;
        return $plan;
    }
}
