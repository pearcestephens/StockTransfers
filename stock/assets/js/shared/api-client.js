/**
 * Shared API Client
 * 
 * Centralized API request handler with CSRF and error handling
 * 
 * @module shared/api-client
 */

(function(window) {
    'use strict';
    
    const ApiClient = {
        csrfToken: null,
        
        init(config) {
            this.csrfToken = config?.csrf_token || null;
        },
        
        async request(url, options = {}) {
            const defaults = {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            };
            
            // Add CSRF token for mutations
            if (['POST', 'PUT', 'DELETE', 'PATCH'].includes(options.method?.toUpperCase())) {
                if (this.csrfToken) {
                    defaults.headers['X-CSRF-Token'] = this.csrfToken;
                }
            }
            
            const config = { ...defaults, ...options };
            if (config.body && typeof config.body === 'object') {
                config.body = JSON.stringify(config.body);
            }
            
            try {
                const response = await fetch(url, config);
                const data = await response.json();
                
                if (!response.ok) {
                    throw new Error(data.error?.message || 'Request failed');
                }
                
                return data;
            } catch (error) {
                console.error('[ApiClient] Request failed:', error);
                throw error;
            }
        },
        
        get(url, params = {}) {
            const query = new URLSearchParams(params).toString();
            const fullUrl = query ? `${url}?${query}` : url;
            return this.request(fullUrl);
        },
        
        post(url, body) {
            return this.request(url, { method: 'POST', body });
        },
        
        put(url, body) {
            return this.request(url, { method: 'PUT', body });
        },
        
        delete(url) {
            return this.request(url, { method: 'DELETE' });
        },
    };
    
    window.SharedApiClient = ApiClient;
})(window);
