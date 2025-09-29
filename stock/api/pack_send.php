<?php
declare(strict_types=1);
/**
 * File: pack_send.php
 * Purpose: HTTP endpoint for pack/send orchestration
 * Author: GitHub Copilot
 * Last Modified: 2025-09-29
 * Dependencies: app.php, _shared Bootstrap stack
 */

use Modules\Transfers\Stock\Shared\Services\PackSendRequest as PackSendRequestDto;
use Modules\Transfers\Stock\Shared\Util\Uuid;

require_once $_SERVER['DOCUMENT_ROOT'] . '/app.php';
require_once __DIR__ . '/../_shared/Bootstrap.php';

use function Modules\Transfers\Stock\Shared\pack_send_orchestrator;

header('Content-Type: application/json; charset=utf-8');

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        echo json_encode([
            'ok' => false,
            'request_id' => Uuid::v4(),
            'data' => null,
            'error' => [
                'code' => 'METHOD_NOT_ALLOWED',
                'message' => 'Use POST for pack/send requests.',
                'details' => [],
            ],
            'warnings' => [],
        ], JSON_UNESCAPED_SLASHES);
        return;
    }

    $rawBody = file_get_contents('php://input') ?: '';
    $payload = [];
    if ($rawBody !== '') {
        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON payload supplied.');
        }
        $payload = $decoded;
    }

    $idempotencyKey = (string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? '');
    $idempotencyKey = trim($idempotencyKey);

    $requestId = (string)($_SERVER['HTTP_X_REQUEST_ID'] ?? '');
    if (!Uuid::isValid($requestId)) {
        $requestId = Uuid::v4();
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $userId = (int)($_SESSION['staff_id'] ?? $_SESSION['userID'] ?? 0);

    $dto = PackSendRequestDto::fromHttp($requestId, $idempotencyKey, $userId, $payload, $rawBody);

    $orchestrator = pack_send_orchestrator();
    $response = $orchestrator->handle($dto);

    echo json_encode($response, JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    $fallback = [
        'ok' => false,
        'request_id' => Uuid::v4(),
        'data' => null,
        'error' => [
            'code' => 'UNEXPECTED_ERROR',
            'message' => $exception->getMessage(),
            'details' => [],
        ],
        'warnings' => [],
    ];
    echo json_encode($fallback, JSON_UNESCAPED_SLASHES);
}
