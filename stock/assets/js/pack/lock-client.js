/**
 * Pack Lock Client Module
 * @module pack/lock-client
 * @requires shared/api-client
 */
(function(window) {
    'use strict';
    
    const PackLockClient = {
        config: {
            transferId: null,
            userId: null,
            heartbeatInterval: 60000, // 60 seconds
        },
        
        heartbeatTimer: null,
        
        /**
         * Initialize lock client
         * @param {Object} config - Configuration object
         */
        init(config) {
            console.log('[PackLockClient] Initializing...', config);
            
            Object.assign(this.config, config || {});
            
            if (!this.config.transferId) {
                console.error('[PackLockClient] Missing transfer ID');
                return;
            }
            
            // Start heartbeat if user has lock
            if (window.lockStatus && window.lockStatus.has_lock) {
                this.startHeartbeat();
            }
            
            // Setup lock status monitoring
            this.setupStatusMonitoring();
            
            console.log('[PackLockClient] Initialized');
        },
        
        /**
         * Start lock heartbeat to maintain possession
         */
        startHeartbeat() {
            if (this.heartbeatTimer) {
                clearInterval(this.heartbeatTimer);
            }
            
            this.heartbeatTimer = setInterval(() => {
                this.sendHeartbeat();
            }, this.config.heartbeatInterval);
            
            console.log('[PackLockClient] Heartbeat started');
        },
        
        /**
         * Stop lock heartbeat
         */
        stopHeartbeat() {
            if (this.heartbeatTimer) {
                clearInterval(this.heartbeatTimer);
                this.heartbeatTimer = null;
            }
            console.log('[PackLockClient] Heartbeat stopped');
        },
        
        /**
         * Send heartbeat to maintain lock
         */
        async sendHeartbeat() {
            if (!window.lockStatus || !window.lockStatus.has_lock) {
                this.stopHeartbeat();
                return;
            }
            
            try {
                // Use shared API client if available
                if (window.PackApiClient) {
                    await window.PackApiClient.post('/api/lock/heartbeat', {
                        transfer_id: this.config.transferId
                    });
                } else {
                    // Fallback direct fetch
                    await fetch('/modules/transfers/stock/api/lock/heartbeat.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            transfer_id: this.config.transferId
                        }),
                        credentials: 'same-origin'
                    });
                }
            } catch (error) {
                console.warn('[PackLockClient] Heartbeat failed:', error);
            }
        },
        
        /**
         * Request lock acquisition
         */
        async requestLock() {
            try {
                const response = await fetch('/modules/transfers/stock/api/lock/acquire.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        transfer_id: this.config.transferId
                    }),
                    credentials: 'same-origin'
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // Update global lock status
                    window.lockStatus = data.lock_status;
                    this.startHeartbeat();
                    
                    // Trigger lock acquired event
                    document.dispatchEvent(new CustomEvent('pack:lock-acquired', {
                        detail: data.lock_status
                    }));
                    
                    return true;
                } else {
                    console.warn('[PackLockClient] Lock request failed:', data.error);
                    return false;
                }
            } catch (error) {
                console.error('[PackLockClient] Lock request error:', error);
                return false;
            }
        },
        
        /**
         * Release current lock
         */
        async releaseLock() {
            try {
                await fetch('/modules/transfers/stock/api/lock/release.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        transfer_id: this.config.transferId
                    }),
                    credentials: 'same-origin'
                });
                
                this.stopHeartbeat();
                
                // Update global lock status
                if (window.lockStatus) {
                    window.lockStatus.has_lock = false;
                    window.lockStatus.state = 'unlocked';
                }
                
                // Trigger lock released event
                document.dispatchEvent(new CustomEvent('pack:lock-released'));
                
            } catch (error) {
                console.error('[PackLockClient] Lock release error:', error);
            }
        },
        
        /**
         * Setup monitoring for lock status changes
         */
        setupStatusMonitoring() {
            // Listen for visibility changes to pause/resume heartbeat
            document.addEventListener('visibilitychange', () => {
                if (document.hidden) {
                    this.stopHeartbeat();
                } else if (window.lockStatus && window.lockStatus.has_lock) {
                    this.startHeartbeat();
                }
            });
            
            // Listen for beforeunload to release lock
            window.addEventListener('beforeunload', () => {
                if (window.lockStatus && window.lockStatus.has_lock) {
                    // Use sendBeacon for reliable cleanup
                    navigator.sendBeacon('/modules/transfers/stock/api/lock/release.php', 
                        JSON.stringify({
                            transfer_id: this.config.transferId
                        })
                    );
                }
            });
        }
    };
    
    window.PackLockClient = PackLockClient;
})(window);
