<?php
declare(strict_types=1);

/**
 * Token Injector for JavaScript Context
 * 
 * Securely injects outlet-specific API tokens into JavaScript context
 * for frontend API consumption.
 * 
 * @author Pearce Stephens <pearce.stephens@ecigdis.co.nz>
 * @copyright 2025 Ecigdis Limited
 * @since 1.0.0
 */

class TokenInjector
{
    private ?string $outletId = null;
    private array $tokens = [];
    
    /**
     * Initialize with outlet context
     */
    public function __construct(?string $outletId = null)
    {
        $this->outletId = $outletId;
        $this->loadTokensForOutlet();
    }
    
    /**
     * Load API tokens for specific outlet
     */
    private function loadTokensForOutlet(): void
    {
        if (!$this->outletId) {
            return;
        }
        
        try {
            $pdo = getDatabase();
            
            // Query to get outlet-specific API tokens
            $sql = "
                SELECT 
                    outlet_id,
                    nzpost_subscription_key,
                    nzpost_api_key,
                    gss_token,
                    is_active
                FROM outlet_api_tokens 
                WHERE outlet_id = ? AND is_active = 1
                LIMIT 1
            ";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$this->outletId]);
            $result = $stmt->fetch();
            
            if ($result) {
                $this->tokens = [
                    'nzpost_subscription_key' => $result['nzpost_subscription_key'],
                    'nzpost_api_key' => $result['nzpost_api_key'],
                    'gss_token' => $result['gss_token'],
                ];
            }
            
        } catch (PDOException $e) {
            // Fallback to environment variables or default tokens
            $this->loadFallbackTokens();
        }
    }
    
    /**
     * Load fallback tokens from environment or config
     */
    private function loadFallbackTokens(): void
    {
        // Use environment variables as fallback
        $this->tokens = [
            'nzpost_subscription_key' => env('NZPOST_SUBSCRIPTION_KEY'),
            'nzpost_api_key' => env('NZPOST_API_KEY'),
            'gss_token' => env('GSS_TOKEN'),
        ];
        
        // Filter out null values
        $this->tokens = array_filter($this->tokens);
    }
    
    /**
     * Set tokens manually (for testing or overrides)
     */
    public function setTokens(array $tokens): void
    {
        $this->tokens = array_merge($this->tokens, $tokens);
    }
    
    /**
     * Generate JavaScript code to inject tokens into page
     */
    public function generateJavaScriptInjection(): string
    {
        $js = "<!-- Stock Transfers API Token Injection -->\n<script>\n";
        
        // Inject outlet ID
        if ($this->outletId) {
            $js .= "window.CURRENT_OUTLET_ID = " . json_encode($this->outletId) . ";\n";
        }
        
        // Inject tokens securely
        foreach ($this->tokens as $key => $value) {
            if (!empty($value)) {
                $varName = strtoupper($key);
                $js .= "window.{$varName} = " . json_encode($value) . ";\n";
            }
        }
        
        // Initialize API client
        $js .= "\n// Initialize API client with tokens\n";
        $js .= "document.addEventListener('DOMContentLoaded', function() {\n";
        $js .= "    if (window.StockTransfersAPI) {\n";
        $js .= "        window.StockTransfersAPI.loadTokensFromGlobals();\n";
        $js .= "        window.StockTransfersAPI.loadOutletContext();\n";
        $js .= "    }\n";
        $js .= "});\n";
        
        $js .= "</script>\n";
        
        return $js;
    }
    
    /**
     * Generate meta tags for token injection (alternative method)
     */
    public function generateMetaTags(): string
    {
        $html = "<!-- Stock Transfers API Meta Tags -->\n";
        
        if ($this->outletId) {
            $html .= '<meta name="outlet-id" content="' . htmlspecialchars($this->outletId) . '">' . "\n";
        }
        
        foreach ($this->tokens as $key => $value) {
            if (!empty($value)) {
                $html .= '<meta name="api-' . htmlspecialchars($key) . '" content="' . htmlspecialchars($value) . '">' . "\n";
            }
        }
        
        return $html;
    }
    
    /**
     * Generate data attributes for script tag injection
     */
    public function generateScriptDataAttributes(): array
    {
        $attributes = [];
        
        if ($this->outletId) {
            $attributes['data-outlet-id'] = $this->outletId;
        }
        
        foreach ($this->tokens as $key => $value) {
            if (!empty($value)) {
                $dataKey = 'data-' . str_replace('_', '-', $key);
                $attributes[$dataKey] = $value;
            }
        }
        
        return $attributes;
    }
    
    /**
     * Get tokens for API calls (server-side)
     */
    public function getTokensForApi(): array
    {
        return array_filter($this->tokens);
    }
    
    /**
     * Check if outlet has any tokens configured
     */
    public function hasTokens(): bool
    {
        return !empty(array_filter($this->tokens));
    }
    
    /**
     * Get available carriers based on tokens
     */
    public function getAvailableCarriers(): array
    {
        $carriers = [];
        
        if (!empty($this->tokens['nzpost_subscription_key']) || !empty($this->tokens['nzpost_api_key'])) {
            $carriers[] = 'nzpost';
        }
        
        if (!empty($this->tokens['gss_token'])) {
            $carriers[] = 'gss';
        }
        
        return $carriers;
    }
    
    /**
     * Generate complete HTML head injection
     */
    public function generateHeadInjection(): string
    {
        return $this->generateMetaTags() . $this->generateJavaScriptInjection();
    }
    
    /**
     * Generate debug information (safe for logging)
     */
    public function getDebugInfo(): array
    {
        return [
            'outlet_id' => $this->outletId,
            'has_tokens' => $this->hasTokens(),
            'available_carriers' => $this->getAvailableCarriers(),
            'token_keys' => array_keys(array_filter($this->tokens)),
        ];
    }
}

/**
 * Helper function to easily inject tokens into a page
 */
function injectApiTokens(?string $outletId = null): string
{
    $injector = new TokenInjector($outletId);
    return $injector->generateHeadInjection();
}

/**
 * Helper function to get API-ready tokens for server-side calls
 */
function getApiTokensForOutlet(?string $outletId = null): array
{
    $injector = new TokenInjector($outletId);
    return $injector->getTokensForApi();
}