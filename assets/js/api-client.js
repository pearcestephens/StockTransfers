/**
 * Stock Transfers API Client
 * 
 * JavaScript client library for making authenticated API calls to freight carriers.
 * Automatically handles token-based authentication for NZ Post and GSS/NZ Couriers.
 * 
 * @author Pearce Stephens <pearce.stephens@ecigdis.co.nz>
 * @copyright 2025 Ecigdis Limited
 * @since 1.0.0
 */

class StockTransfersApiClient {
    
    constructor(config = {}) {
        this.config = {
            apiUrl: config.apiUrl || '/stock/api/router.php',
            outletId: config.outletId || null,
            debug: config.debug || false,
            timeout: config.timeout || 30000,
            ...config
        };
        
        this.tokens = {
            nzpost_subscription_key: null,
            nzpost_api_key: null,
            gss_token: null
        };
        
        this.initialized = false;
        this.init();
    }
    
    /**
     * Initialize the client and load tokens from page context
     */
    init() {
        // Load tokens from global JavaScript variables (set by PHP)
        this.loadTokensFromGlobals();
        
        // Load outlet context
        this.loadOutletContext();
        
        this.initialized = true;
        this.log('API Client initialized', {
            outletId: this.config.outletId,
            hasTokens: this.hasAnyTokens()
        });
    }
    
    /**
     * Load tokens from global JavaScript variables
     * These should be set by PHP on each page load based on outlet
     */
    loadTokensFromGlobals() {
        // NZ Post tokens
        if (window.NZPOST_SUBSCRIPTION_KEY) {
            this.tokens.nzpost_subscription_key = window.NZPOST_SUBSCRIPTION_KEY;
        }
        
        if (window.NZPOST_API_KEY) {
            this.tokens.nzpost_api_key = window.NZPOST_API_KEY;
        }
        
        // GSS/NZ Couriers tokens
        if (window.GSS_TOKEN) {
            this.tokens.gss_token = window.GSS_TOKEN;
        }
        
        // Alternative: Load from data attributes
        const scriptTag = document.querySelector('script[data-nzpost-key]');
        if (scriptTag) {
            if (scriptTag.dataset.nzpostKey) {
                this.tokens.nzpost_subscription_key = scriptTag.dataset.nzpostKey;
            }
            if (scriptTag.dataset.nzpostApi) {
                this.tokens.nzpost_api_key = scriptTag.dataset.nzpostApi;
            }
            if (scriptTag.dataset.gssToken) {
                this.tokens.gss_token = scriptTag.dataset.gssToken;
            }
        }
    }
    
    /**
     * Load outlet context
     */
    loadOutletContext() {
        // From global variable
        if (window.CURRENT_OUTLET_ID) {
            this.config.outletId = window.CURRENT_OUTLET_ID;
        }
        
        // From meta tag
        const outletMeta = document.querySelector('meta[name="outlet-id"]');
        if (outletMeta) {
            this.config.outletId = outletMeta.content;
        }
        
        // From data attribute
        const bodyOutlet = document.body.dataset.outletId;
        if (bodyOutlet) {
            this.config.outletId = bodyOutlet;
        }
    }
    
    /**
     * Set tokens manually (useful for dynamic updates)
     */
    setTokens(tokens) {
        this.tokens = { ...this.tokens, ...tokens };
        this.log('Tokens updated', { hasTokens: this.hasAnyTokens() });
    }
    
    /**
     * Set outlet ID manually
     */
    setOutletId(outletId) {
        this.config.outletId = outletId;
        this.log('Outlet ID updated', { outletId });
    }
    
    /**
     * Check if any tokens are available
     */
    hasAnyTokens() {
        return Object.values(this.tokens).some(token => token !== null);
    }
    
    /**
     * Get available carriers based on tokens
     */
    getAvailableCarriers() {
        const carriers = [];
        
        if (this.tokens.nzpost_subscription_key || this.tokens.nzpost_api_key) {
            carriers.push('nzpost');
        }
        
        if (this.tokens.gss_token) {
            carriers.push('gss');
        }
        
        return carriers;
    }
    
    /**
     * Check authentication status
     */
    async checkAuthStatus() {
        return this.apiCall('auth_status');
    }
    
    /**
     * Get freight rates
     */
    async getRates(rateData, carrier = null) {
        return this.apiCall('rates', rateData, carrier);
    }
    
    /**
     * Create shipping label
     */
    async createLabel(labelData, carrier = null) {
        return this.apiCall('create_label', labelData, carrier);
    }
    
    /**
     * Track shipment
     */
    async trackShipment(trackingNumber, carrier = null) {
        return this.apiCall('track', { tracking_number: trackingNumber }, carrier);
    }
    
    /**
     * Void label
     */
    async voidLabel(labelId, carrier = null) {
        return this.apiCall('void_label', { label_id: labelId }, carrier);
    }
    
    /**
     * Validate address
     */
    async validateAddress(address, carrier = null) {
        return this.apiCall('address_validate', { address }, carrier);
    }
    
    /**
     * Get available services
     */
    async getServices(carrier = null) {
        return this.apiCall('services', {}, carrier);
    }
    
    /**
     * Get API capabilities
     */
    async getCapabilities(carrier = null) {
        return this.apiCall('capabilities', {}, carrier);
    }
    
    /**
     * Make generic API call
     */
    async apiCall(action, data = {}, carrier = null) {
        if (!this.initialized) {
            throw new Error('API client not initialized');
        }
        
        if (!this.hasAnyTokens()) {
            throw new Error('No authentication tokens available');
        }
        
        const requestData = {
            action,
            carrier,
            outlet_id: this.config.outletId,
            tokens: JSON.stringify(this.tokens),
            ...data
        };
        
        // Also send tokens as individual fields for fallback
        if (this.tokens.nzpost_subscription_key) {
            requestData.nzpost_subscription_key = this.tokens.nzpost_subscription_key;
        }
        if (this.tokens.nzpost_api_key) {
            requestData.nzpost_api_key = this.tokens.nzpost_api_key;
        }
        if (this.tokens.gss_token) {
            requestData.gss_token = this.tokens.gss_token;
        }
        
        const options = {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(requestData),
            signal: AbortSignal.timeout(this.config.timeout)
        };
        
        this.log('API Request', { action, carrier, outletId: this.config.outletId });
        
        try {
            const response = await fetch(this.config.apiUrl, options);
            const result = await response.json();
            
            this.log('API Response', { 
                action, 
                carrier, 
                success: result.success,
                status: response.status 
            });
            
            if (!response.ok) {
                throw new Error(`API request failed: ${response.status} ${response.statusText}`);
            }
            
            return result;
            
        } catch (error) {
            this.log('API Error', { action, carrier, error: error.message });
            throw error;
        }
    }
    
    /**
     * Convenience method for jQuery-style AJAX calls
     */
    ajax(options) {
        const action = options.action;
        const data = options.data || {};
        const carrier = options.carrier || null;
        
        return this.apiCall(action, data, carrier)
            .then(result => {
                if (options.success && typeof options.success === 'function') {
                    options.success(result);
                }
                return result;
            })
            .catch(error => {
                if (options.error && typeof options.error === 'function') {
                    options.error(error);
                }
                throw error;
            });
    }
    
    /**
     * Debug logging
     */
    log(message, data = {}) {
        if (this.config.debug) {
            console.log(`[StockTransfers API] ${message}`, data);
        }
    }
    
    /**
     * Display user-friendly error messages
     */
    displayError(error, container = null) {
        let message = 'An error occurred while processing your request.';
        
        if (error.message) {
            if (error.message.includes('No authentication tokens')) {
                message = 'Unable to connect to freight carriers. Please refresh the page and try again.';
            } else if (error.message.includes('timeout')) {
                message = 'Request timed out. Please check your connection and try again.';
            } else if (error.message.includes('Network')) {
                message = 'Network error. Please check your connection and try again.';
            } else {
                message = error.message;
            }
        }
        
        if (container) {
            const alert = document.createElement('div');
            alert.className = 'alert alert-danger alert-dismissible fade show';
            alert.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            if (typeof container === 'string') {
                container = document.querySelector(container);
            }
            
            if (container) {
                container.appendChild(alert);
            }
        } else {
            alert(message);
        }
    }
}

// Auto-initialize global instance if window is available
if (typeof window !== 'undefined') {
    window.StockTransfersAPI = new StockTransfersApiClient({
        debug: window.location.hostname === 'localhost' || window.location.search.includes('debug=1')
    });
    
    // jQuery plugin if jQuery is available
    if (typeof jQuery !== 'undefined') {
        jQuery.fn.stockTransfersAPI = function(options) {
            return this.each(function() {
                const $this = jQuery(this);
                const api = window.StockTransfersAPI;
                
                if (options && typeof options === 'object') {
                    api.ajax(options);
                }
            });
        };
    }
}