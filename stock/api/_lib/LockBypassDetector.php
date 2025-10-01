<?php
/**
 * Lock Bypass Detection & Security Monitoring
 * 
 * Logs and alerts on attempts to bypass lock system
 * Can be included in sensitive APIs to track violations
 */

declare(strict_types=1);

class LockBypassDetector {
    
    private $logFile = '/tmp/lock_violations.log';
    
    public function __construct() {
        // Ensure log directory exists
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }
    
    /**
     * Log a lock bypass attempt with detailed forensics
     */
    public function logBypassAttempt(array $details): void {
        $forensics = [
            'timestamp' => date('c'),
            'violation_type' => $details['type'] ?? 'UNKNOWN',
            'transfer_id' => $details['transfer_id'] ?? 0,
            'user_id' => $_SESSION['userID'] ?? 0,
            'user_ip' => $this->getUserIP(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'referer' => $_SERVER['HTTP_REFERER'] ?? '',
            'session_id' => session_id(),
            'attempted_action' => $details['action'] ?? 'unknown',
            'current_lock_holder' => $details['lock_holder'] ?? null,
            'request_data' => $this->sanitizeRequestData(),
            'severity' => $details['severity'] ?? 'HIGH',
            'details' => $details
        ];
        
        // Log to file
        $logEntry = json_encode($forensics, JSON_UNESCAPED_SLASHES) . "\n";
        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
        
        // Log to system error log
        error_log("SECURITY VIOLATION: Lock bypass attempt - " . json_encode([
            'type' => $forensics['violation_type'],
            'user' => $forensics['user_id'],
            'transfer' => $forensics['transfer_id'],
            'ip' => $forensics['user_ip']
        ]));
        
        // If critical, send alert (implement as needed)
        if (($details['severity'] ?? 'HIGH') === 'CRITICAL') {
            $this->sendSecurityAlert($forensics);
        }
    }
    
    /**
     * Check for suspicious patterns in requests
     */
    public function detectSuspiciousPatterns(int $transferId, int $userId): array {
        $patterns = [];
        
        // Check for rapid-fire requests (potential script)
        if ($this->isRapidFireRequest()) {
            $patterns[] = 'RAPID_FIRE_REQUESTS';
        }
        
        // Check for unusual user agent
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if ($this->isSuspiciousUserAgent($userAgent)) {
            $patterns[] = 'SUSPICIOUS_USER_AGENT';
        }
        
        // Check for missing referer on sensitive operations
        if (empty($_SERVER['HTTP_REFERER']) && !$this->isAPIRequest()) {
            $patterns[] = 'MISSING_REFERER';
        }
        
        // Check for multiple session attempts
        if ($this->hasMultipleSessions($userId)) {
            $patterns[] = 'MULTIPLE_SESSIONS';
        }
        
        return $patterns;
    }
    
    /**
     * Get real user IP (considering proxies)
     */
    private function getUserIP(): string {
        $headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
    
    /**
     * Sanitize request data for logging (remove sensitive info)
     */
    private function sanitizeRequestData(): array {
        $data = [];
        
        // Sanitized POST data
        if (!empty($_POST)) {
            $data['POST'] = $this->sanitizeArray($_POST);
        }
        
        // Sanitized GET data
        if (!empty($_GET)) {
            $data['GET'] = $this->sanitizeArray($_GET);
        }
        
        // Headers (selective)
        $importantHeaders = [
            'HTTP_X_REQUESTED_WITH',
            'HTTP_CONTENT_TYPE',
            'HTTP_ACCEPT',
            'HTTP_ORIGIN'
        ];
        
        foreach ($importantHeaders as $header) {
            if (!empty($_SERVER[$header])) {
                $data['headers'][$header] = $_SERVER[$header];
            }
        }
        
        return $data;
    }
    
    /**
     * Remove sensitive data from arrays
     */
    private function sanitizeArray(array $data): array {
        $sensitiveKeys = ['password', 'token', 'secret', 'key', 'auth'];
        
        foreach ($data as $key => $value) {
            $lowerKey = strtolower($key);
            foreach ($sensitiveKeys as $sensitive) {
                if (strpos($lowerKey, $sensitive) !== false) {
                    $data[$key] = '[REDACTED]';
                    break;
                }
            }
            
            if (is_array($value)) {
                $data[$key] = $this->sanitizeArray($value);
            }
        }
        
        return $data;
    }
    
    /**
     * Check if this is a rapid-fire request pattern
     */
    private function isRapidFireRequest(): bool {
        $sessionKey = 'last_request_times';
        $maxRequests = 10;
        $timeWindow = 30; // seconds
        
        if (!isset($_SESSION[$sessionKey])) {
            $_SESSION[$sessionKey] = [];
        }
        
        $now = time();
        $times = $_SESSION[$sessionKey];
        
        // Remove old timestamps
        $times = array_filter($times, fn($t) => $now - $t <= $timeWindow);
        
        // Add current timestamp
        $times[] = $now;
        $_SESSION[$sessionKey] = $times;
        
        return count($times) > $maxRequests;
    }
    
    /**
     * Check for suspicious user agents
     */
    private function isSuspiciousUserAgent(string $userAgent): bool {
        $suspicious = [
            'curl', 'wget', 'python', 'bot', 'crawl', 'spider',
            'postman', 'insomnia', 'httpie', 'perl'
        ];
        
        $userAgent = strtolower($userAgent);
        
        foreach ($suspicious as $pattern) {
            if (strpos($userAgent, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if this is an API request
     */
    private function isAPIRequest(): bool {
        return strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false ||
               strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false ||
               !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
    }
    
    /**
     * Check for multiple concurrent sessions
     */
    private function hasMultipleSessions(int $userId): bool {
        // This would require database checking - simplified for now
        return false;
    }
    
    /**
     * Send security alert (implement as needed)
     */
    private function sendSecurityAlert(array $forensics): void {
        // Could send email, Slack notification, etc.
        error_log("CRITICAL SECURITY ALERT: " . json_encode($forensics));
    }
}

// Helper function for easy access
function detectLockBypass(array $details): void {
    $detector = new LockBypassDetector();
    $detector->logBypassAttempt($details);
}