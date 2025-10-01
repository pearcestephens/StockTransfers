<?php
declare(strict_types=1);
/**
 * File: pack_send.php
 * Purpose: HTTP endpoint for pack/send orchestration
 * Author: GitHub Copilot (sanitized)
 * Last Modified: 2025-09-29
 * Dependencies: app.php, _shared Bootstrap stack
 */

require_once $_SERVER['DOCUMENT_ROOT'].'/modules/transfers/_local_shims.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/app.php';
require_once __DIR__ . '/../_shared/Bootstrap.php';
use PDO;

use Modules\Transfers\Stock\Shared\Services\PackSendRequest as PackSendRequestDto;
use Modules\Transfers\Stock\Shared\Util\Uuid;
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
<?php
declare(strict_types=1);
/**
 * File: pack_send.php
 * Purpose: HTTP endpoint for pack/send orchestration
 * 
 * SECURITY: Requires valid transfer lock ownership
 * Author: GitHub Copilot (sanitized)
 * Last Modified: 2025-10-01
 * Dependencies: app.php, _shared Bootstrap stack
 */

require_once $_SERVER['DOCUMENT_ROOT'].'/modules/transfers/_local_shims.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/app.php';
require_once __DIR__ . '/../_shared/Bootstrap.php';
require_once __DIR__ . '/_lib/ServerLockGuard.php';
use PDO;

use Modules\Transfers\Stock\Shared\Services\PackSendRequest as PackSendRequestDto;
use Modules\Transfers\Stock\Shared\Util\Uuid;
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

    $guard = ServerLockGuard::getInstance();
    
    $rawBody = file_get_contents('php://input') ?: '';
    $payload = [];
    if ($rawBody !== '') {
        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON payload supplied.');
        }
        $payload = $decoded;
    }

    // Extract transfer ID and validate lock EARLY
    $transferId = $guard->extractTransferIdOrDie($payload);
    $userId = $guard->validateAuthOrDie();
    
    // CRITICAL: Validate lock ownership before pack/send
    $guard->validateLockOrDie($transferId, $userId, 'pack and send');

    $pdo = pdo();
    $reqId = (string)($_SERVER['HTTP_X_REQUEST_ID'] ?? '');
    if (!Uuid::isValid($reqId)) $reqId = Uuid::v4();
    $idempotencyKey = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? ''));

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    // --- Idempotency (shared semantics with manual tracking)
    $input = $payload; // alias for clarity
    $idemKey  = $idempotencyKey ?: null;
    $idemHash = hash('sha256', json_encode($input, JSON_UNESCAPED_SLASHES));
    if ($idemKey) {
        $stmt = $pdo->prepare("SELECT response_json, status_code, idem_hash FROM transfer_idempotency WHERE idem_key=? LIMIT 1");
        $stmt->execute([$idemKey]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!hash_equals((string)$row['idem_hash'], $idemHash)) {
                http_response_code(409);
                echo json_encode(['ok'=>false,'request_id'=>$reqId,'error'=>['code'=>'IDEMPOTENCY_BODY_MISMATCH','message'=>'Same Idempotency-Key with different body']], JSON_UNESCAPED_SLASHES); exit;
            }
            $resp = json_decode($row['response_json'] ?? 'null', true) ?: [];
            $resp['replay'] = true;
            http_response_code((int)($row['status_code'] ?? 200));
            echo json_encode($resp, JSON_UNESCAPED_SLASHES); exit;
        }
    }

    $dto = PackSendRequestDto::fromHttp($reqId, $idempotencyKey, $userId, $payload, $rawBody);

    $orchestrator = pack_send_orchestrator();
    $response = $orchestrator->handle($dto);

    // Persist idempotent response (best-effort)
    $envelope = $response; // assume orchestrator returns unified envelope
    echo json_encode($envelope, JSON_UNESCAPED_SLASHES);
    if ($idemKey && ($envelope['ok'] ?? false)) {
        try {
            $ins = $pdo->prepare("INSERT INTO transfer_idempotency (idem_key, idem_hash, response_json, status_code, created_at) VALUES (?,?,?,?,NOW())");
            $ins->execute([$idemKey, $idemHash, json_encode($envelope, JSON_UNESCAPED_SLASHES), 200]);
        } catch (Throwable $e) { /* ignore persist failure */ }
    }
    return;
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
