<?php
declare(strict_types=1);

/**
 * API Authentication Manager
 * 
 * Handles dynamic token-based authentication for freight carriers
 * based on outlet context. Supports NZ Post and GSS/NZ Couriers.
 * 
 * @author Pearce Stephens <pearce.stephens@ecigdis.co.nz>
 * @copyright 2025 Ecigdis Limited
 * @since 1.0.0
 */

require_once __DIR__ . '/../../bootstrap.php';

class ApiAuthManager
{
    private array $tokens = [];
    private ?string $currentOutletId = null;
    
    /**
     * Initialize with tokens from JavaScript context
     */
    public function __construct()
    {
        $this->loadTokensFromRequest();
    }
    
    /**
     * Load tokens from request (JavaScript variables or POST data)
     */
    private function loadTokensFromRequest(): void
    {
        // Check for tokens in POST data (from JavaScript)
        if (isset($_POST['tokens'])) {
            $tokens = json_decode($_POST['tokens'], true);
            if (is_array($tokens)) {
                $this->tokens = $tokens;
            }
        }
        
        // Check for individual token parameters
        if (isset($_POST['nzpost_subscription_key'])) {
            $this->tokens['nzpost_subscription_key'] = sanitizeInput($_POST['nzpost_subscription_key']);
        }
        
        if (isset($_POST['nzpost_api_key'])) {
            $this->tokens['nzpost_api_key'] = sanitizeInput($_POST['nzpost_api_key']);
        }
        
        if (isset($_POST['gss_token'])) {
            $this->tokens['gss_token'] = sanitizeInput($_POST['gss_token']);
        }
        
        // Store outlet context
        if (isset($_POST['outlet_id'])) {
            $this->currentOutletId = sanitizeInput($_POST['outlet_id']);
        }
        
        // Log token availability (not the actual tokens!)
        $this->logTokenAvailability();
    }
    
    /**
     * Get NZ Post authentication headers
     */
    public function getNzPostHeaders(): array
    {
        $headers = [];
        
        if (isset($this->tokens['nzpost_subscription_key'])) {
            $headers['Ocp-Apim-Subscription-Key'] = $this->tokens['nzpost_subscription_key'];
        }
        
        if (isset($this->tokens['nzpost_api_key'])) {
            $headers['Authorization'] = 'Bearer ' . $this->tokens['nzpost_api_key'];
        }
        
        $headers['Content-Type'] = 'application/json';
        $headers['Accept'] = 'application/json';
        
        return $headers;
    }
    
    /**
     * Get GSS/NZ Couriers authentication headers
     */
    public function getGssHeaders(): array
    {
        $headers = [];
        
        if (isset($this->tokens['gss_token'])) {
            $headers['Authorization'] = 'Bearer ' . $this->tokens['gss_token'];
        }
        
        $headers['Content-Type'] = 'application/json';
        $headers['Accept'] = 'application/json';
        
        return $headers;
    }
    
    /**
     * Check if NZ Post tokens are available
     */
    public function hasNzPostAuth(): bool
    {
        return isset($this->tokens['nzpost_subscription_key']) || 
               isset($this->tokens['nzpost_api_key']);
    }
    
    /**
     * Check if GSS tokens are available
     */
    public function hasGssAuth(): bool
    {
        return isset($this->tokens['gss_token']);
    }
    
    /**
     * Get available carriers based on tokens
     */
    public function getAvailableCarriers(): array
    {
        $carriers = [];
        
        if ($this->hasNzPostAuth()) {
            $carriers[] = 'nzpost';
        }
        
        if ($this->hasGssAuth()) {
            $carriers[] = 'gss';
        }
        
        return $carriers;
    }
    
    /**
     * Get current outlet ID
     */
    public function getCurrentOutletId(): ?string
    {
        return $this->currentOutletId;
    }
    
    /**
     * Make authenticated API request to NZ Post
     */
    public function callNzPostApi(string $endpoint, array $data = [], string $method = 'POST'): array
    {
        if (!$this->hasNzPostAuth()) {
            throw new Exception('NZ Post authentication tokens not available');
        }
        
        $baseUrl = 'https://api.nzpost.co.nz/parceladdress/v2';
        $url = $baseUrl . $endpoint;
        
        return $this->makeApiRequest($url, $data, $this->getNzPostHeaders(), $method);
    }
    
    /**
     * Make authenticated API request to GSS/NZ Couriers
     */
    public function callGssApi(string $endpoint, array $data = [], string $method = 'POST'): array
    {
        if (!$this->hasGssAuth()) {
            throw new Exception('GSS authentication tokens not available');
        }
        
        $baseUrl = 'https://api.gss.co.nz/api';
        $url = $baseUrl . $endpoint;
        
        return $this->makeApiRequest($url, $data, $this->getGssHeaders(), $method);
    }
    
    /**
     * Generic API request handler
     */
    private function makeApiRequest(string $url, array $data, array $headers, string $method): array
    {
        $ch = curl_init();
        
        $curlOptions = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        
        if ($method === 'POST' || $method === 'PUT') {
            $curlOptions[CURLOPT_POST] = true;
            $curlOptions[CURLOPT_POSTFIELDS] = json_encode($data);
        } elseif ($method === 'GET' && !empty($data)) {
            $curlOptions[CURLOPT_URL] = $url . '?' . http_build_query($data);
        }
        
        curl_setopt_array($ch, $curlOptions);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($response === false || !empty($error)) {
            throw new Exception("API request failed: {$error}");
        }
        
        $responseData = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON response from API");
        }
        
        // Log API call (without sensitive data)
        $this->logApiCall($url, $method, $httpCode, $this->currentOutletId);
        
        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode,
            'data' => $responseData,
            'outlet_id' => $this->currentOutletId,
            'timestamp' => date('c'),
        ];
    }
    
    /**
     * Format headers for cURL
     */
    private function formatHeaders(array $headers): array
    {
        $formatted = [];
        foreach ($headers as $key => $value) {
            $formatted[] = "{$key}: {$value}";
        }
        return $formatted;
    }
    
    /**
     * Log token availability (security-safe)
     */
    private function logTokenAvailability(): void
    {
        $logFile = LOGS_DIR . '/api-auth-' . date('Y-m-d') . '.log';
        $timestamp = date('Y-m-d H:i:s');
        $outletId = $this->currentOutletId ?? 'unknown';
        
        $availableTokens = [];
        if (isset($this->tokens['nzpost_subscription_key'])) $availableTokens[] = 'nzpost_subscription';
        if (isset($this->tokens['nzpost_api_key'])) $availableTokens[] = 'nzpost_api';
        if (isset($this->tokens['gss_token'])) $availableTokens[] = 'gss';
        
        $tokensStr = empty($availableTokens) ? 'none' : implode(',', $availableTokens);
        
        $logEntry = "[{$timestamp}] Outlet: {$outletId} - Available tokens: {$tokensStr}\n";
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Log API calls (security-safe)
     */
    private function logApiCall(string $url, string $method, int $httpCode, ?string $outletId): void
    {
        $logFile = LOGS_DIR . '/api-calls-' . date('Y-m-d') . '.log';
        $timestamp = date('Y-m-d H:i:s');
        $outletId = $outletId ?? 'unknown';
        
        // Parse URL to get just the endpoint (no query params that might contain sensitive data)
        $parsedUrl = parse_url($url);
        $safeUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . $parsedUrl['path'];
        
        $logEntry = "[{$timestamp}] Outlet: {$outletId} - {$method} {$safeUrl} - HTTP {$httpCode}\n";
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Get authentication status for debugging
     */
    public function getAuthStatus(): array
    {
        return [
            'outlet_id' => $this->currentOutletId,
            'available_carriers' => $this->getAvailableCarriers(),
            'nzpost_auth' => $this->hasNzPostAuth(),
            'gss_auth' => $this->hasGssAuth(),
            'timestamp' => date('c'),
        ];
    }
}