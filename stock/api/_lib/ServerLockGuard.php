<?php
/**
 * Server-Side Lock Validation Utility
 * Enforces transfer lock ownership at the API level
 * 
 * SECURITY: Prevents client-side bypass attempts by validating
 * lock ownership on the server before allowing any operations
 */

declare(strict_types=1);

require_once __DIR__ . '/LockBypassDetector.php';

class ServerLockGuard {
    
    private static $instance = null;
    private $bypassDetector;
    
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        $this->bypassDetector = new LockBypassDetector();
    }
    
    /**
     * Validate that current user owns the transfer lock
     * Returns true if valid, sends HTTP error response and exits if not
     */
    public function validateLockOrDie(int $transferId, int $userId, string $operation = 'operation'): bool {
        try {
            // Load lock service
            if (!class_exists('Modules\Transfers\Stock\Services\PackLockService')) {
                require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/transfers/stock/services/PackLockService.php';
            }
            
            $lockSvc = new \Modules\Transfers\Stock\Services\PackLockService();
            $lock = $lockSvc->getLock($transferId);
            
            // No lock exists - deny and log violation
            if (!$lock) {
                $this->logViolation($transferId, $userId, 'NO_LOCK', $operation, null);
                $this->sendLockError(423, 'LOCK_REQUIRED', "Lock required for {$operation}", [
                    'transfer_id' => $transferId,
                    'lock_status' => 'none',
                    'required_action' => 'acquire_lock'
                ]);
                return false;
            }
            
            // Lock exists but wrong user - deny and log violation
            if ((int)$lock['user_id'] !== $userId) {
                $this->logViolation($transferId, $userId, 'LOCK_HELD_BY_OTHER', $operation, $lock);
                $this->sendLockError(423, 'LOCK_DENIED', "Transfer locked by another user", [
                    'transfer_id' => $transferId,
                    'lock_status' => 'held_by_other',
                    'locked_by_user_id' => (int)$lock['user_id'],
                    'locked_by_name' => $lock['user_name'] ?? 'Unknown',
                    'locked_since' => $lock['acquired_at'] ?? null,
                    'required_action' => 'request_ownership'
                ]);
                return false;
            }
            
            // Valid lock ownership
            return true;
            
        } catch (Exception $e) {
            error_log("ServerLockGuard error for transfer {$transferId}: " . $e->getMessage());
            $this->sendLockError(500, 'LOCK_CHECK_FAILED', "Unable to verify lock status", [
                'transfer_id' => $transferId,
                'error_detail' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Log security violation with detailed forensics
     */
    private function logViolation(int $transferId, int $userId, string $violationType, string $operation, ?array $lockDetails): void {
        $suspiciousPatterns = $this->bypassDetector->detectSuspiciousPatterns($transferId, $userId);
        
        $this->bypassDetector->logBypassAttempt([
            'type' => $violationType,
            'transfer_id' => $transferId,
            'user_id' => $userId,
            'action' => $operation,
            'lock_holder' => $lockDetails['user_id'] ?? null,
            'lock_holder_name' => $lockDetails['user_name'] ?? null,
            'severity' => in_array($violationType, ['LOCK_HELD_BY_OTHER']) ? 'CRITICAL' : 'HIGH',
            'suspicious_patterns' => $suspiciousPatterns,
            'lock_details' => $lockDetails
        ]);
    }
    
    /**
     * Check lock ownership without dying (returns boolean)
     */
    public function hasValidLock(int $transferId, int $userId): bool {
        try {
            if (!class_exists('Modules\Transfers\Stock\Services\PackLockService')) {
                require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/transfers/stock/services/PackLockService.php';
            }
            
            $lockSvc = new \Modules\Transfers\Stock\Services\PackLockService();
            $lock = $lockSvc->getLock($transferId);
            
            return $lock && ((int)$lock['user_id'] === $userId);
            
        } catch (Exception $e) {
            error_log("ServerLockGuard::hasValidLock error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get current lock details for transfer
     */
    public function getLockDetails(int $transferId): ?array {
        try {
            if (!class_exists('Modules\Transfers\Stock\Services\PackLockService')) {
                require_once $_SERVER['DOCUMENT_ROOT'] . '/modules/transfers/stock/services/PackLockService.php';
            }
            
            $lockSvc = new \Modules\Transfers\Stock\Services\PackLockService();
            return $lockSvc->getLock($transferId);
            
        } catch (Exception $e) {
            error_log("ServerLockGuard::getLockDetails error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Send standardized lock error response and exit
     */
    private function sendLockError(int $httpCode, string $errorCode, string $message, array $details = []): void {
        http_response_code($httpCode);
        
        $response = [
            'success' => false,
            'ok' => false,
            'error' => [
                'code' => $errorCode,
                'message' => $message,
                'type' => 'LOCK_VIOLATION'
            ],
            'lock_violation' => true,
            'details' => $details,
            'request_id' => bin2hex(random_bytes(8)),
            'timestamp' => date('c')
        ];
        
        // Log the violation attempt
        error_log("LOCK VIOLATION: {$errorCode} - {$message} - Details: " . json_encode($details));
        
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    /**
     * Validate session authentication
     */
    public function validateAuthOrDie(): int {
        if (empty($_SESSION['userID'])) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'ok' => false,
                'error' => [
                    'code' => 'UNAUTHORIZED',
                    'message' => 'Authentication required',
                    'type' => 'AUTH_ERROR'
                ],
                'request_id' => bin2hex(random_bytes(8))
            ]);
            exit;
        }
        
        return (int)$_SESSION['userID'];
    }
    
    /**
     * Extract transfer ID from request data
     */
    public function extractTransferIdOrDie($data): int {
        $transferId = 0;
        
        // Try multiple common field names
        if (isset($data['transfer_id'])) {
            $transferId = (int)$data['transfer_id'];
        } elseif (isset($data['transferId'])) {
            $transferId = (int)$data['transferId'];
        } elseif (isset($data['id'])) {
            $transferId = (int)$data['id'];
        } elseif (isset($_GET['transfer_id'])) {
            $transferId = (int)$_GET['transfer_id'];
        } elseif (isset($_GET['id'])) {
            $transferId = (int)$_GET['id'];
        }
        
        if ($transferId <= 0) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'ok' => false,
                'error' => [
                    'code' => 'MISSING_TRANSFER_ID',
                    'message' => 'transfer_id is required',
                    'type' => 'VALIDATION_ERROR'
                ],
                'request_id' => bin2hex(random_bytes(8))
            ]);
            exit;
        }
        
        return $transferId;
    }
}