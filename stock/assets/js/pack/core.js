/**
 * Pack Core Module
 * 
 * Main orchestrator for pack page functionality
 * Coordinates between all feature modules
 * 
 * @module pack/core
 * @requires shared/api-client
 * @requires shared/modal
 */

(function(window) {
    'use strict';
    
    const PackCore = {
        config: {
            transferId: null,
            userId: null,
            sessionId: null,
            csrfToken: null,
        },
        
        /**
         * Initialize pack page
         * @param {Object} bootPayload - Server boot configuration
         */
        init(bootPayload) {
            console.log('[PackCore] Initializing...', bootPayload);
            
            // Merge boot config and map transfer_id to transferId
            const config = {
                transferId: bootPayload?.transfer_id,
                userId: bootPayload?.user_id,
                sessionId: bootPayload?.session_id,
                csrfToken: bootPayload?.csrf_token,
                ...bootPayload
            };
            
            Object.assign(this.config, config);
            
            // Validate required config
            if (!this.config.transferId) {
                console.error('[PackCore] Missing transfer ID', { bootPayload, config: this.config });
                return;
            }
            
            // Expose lock status globally for autosave and other modules
            window.lockStatus = this.config.lock_status || {};
            
            console.log('[PackCore] Configuration validated:', this.config);
            
            // Initialize all feature modules
            this.initModules();
            
            // Setup global event delegation
            this.setupEventDelegation();
            
            // Update lock interface
            this.updateLockInterface();
            
            console.log('[PackCore] Ready');
        },
        
        /**
         * Initialize feature modules
         */
        initModules() {
            // Initialize autosave if available
            if (window.PackAutosave) {
                window.PackAutosave.init(this.config);
            }
            
            // Initialize lock client if available
            if (window.PackLockClientAdvanced) {
                window.PackLockClientAdvanced.init(this.config);
            } else if (window.PackLockClient) {
                window.PackLockClient.init(this.config);
            }
            
            // Initialize validation if available
            if (window.PackValidation) {
                window.PackValidation.init(this.config);
            }
            
            // Initialize product search if available
            if (window.PackProductSearch) {
                window.PackProductSearch.init(this.config);
            }
            
            // Initialize shipping calculator if available
            if (window.PackShipping) {
                window.PackShipping.init(this.config);
            }
            
            // Initialize diagnostics if available
            if (window.PackDiagnostics) {
                window.PackDiagnostics.init(this.config);
            }
        },
        
        /**
         * Setup global event delegation
         */
        setupEventDelegation() {
            document.addEventListener('click', (e) => {
                const action = e.target.closest('[data-action]')?.dataset.action;
                if (!action) return;
                
                this.handleAction(action, e);
            });
            
            // Quantity input delegation
            document.addEventListener('input', (e) => {
                if (e.target.classList.contains('qty-input')) {
                    this.handleQuantityChange(e.target);
                }
            });
        },
        
        /**
         * Handle data-action clicks
         * @param {string} action - Action name
         * @param {Event} e - Click event
         */
        handleAction(action, e) {
            const handlers = {
                'add-product': () => window.PackProductSearch?.open(),
                'autofill': () => this.autofillQuantities(),
                'reset': () => this.resetQuantities(),
                'show-image': (el) => this.showProductImage(el),
                'open-diagnostic': () => window.PackDiagnostics?.open(),
                'refresh-diagnostics': () => window.PackDiagnostics?.refresh(),
                'copy-diagnostics': () => window.PackDiagnostics?.copy(),
                'close-diagnostic': () => window.PackDiagnostics?.close(),
                // Legacy lock actions
                'request-lock': () => this.requestLock(),
                'cancel-request': () => this.cancelLockRequest(),
                // Advanced lock actions
                'take-control': () => window.PackLockClientAdvanced?.takeControl(),
                'release-control': () => window.PackLockClientAdvanced?.releaseControl(),
                'request-takeover': () => window.PackLockClientAdvanced?.requestTakeover(),
                'allow-takeover': () => window.PackLockClientAdvanced?.allowTakeover(),
                'deny-takeover': () => window.PackLockClientAdvanced?.denyTakeover(),
                'dismiss-success': () => window.PackLockClientAdvanced?.hideAllLockBars(),
            };
            
            const handler = handlers[action];
            if (handler) {
                e.preventDefault();
                handler(e.target.closest('[data-action]'));
            }
        },
        
        /**
         * Handle quantity input changes
         * @param {HTMLInputElement} input
         */
        handleQuantityChange(input) {
            const value = parseInt(input.value) || 0;
            const itemId = input.dataset.itemId;
            
            // Trigger validation
            if (window.PackValidation) {
                window.PackValidation.validateQuantity(input, value);
            }
            
            // Trigger autosave
            if (window.PackAutosave) {
                window.PackAutosave.scheduleAutoSave();
            }
            
            // Update totals
            this.updateTotals();
        },
        
        /**
         * Autofill all quantities with planned values
         */
        autofillQuantities() {
            const inputs = document.querySelectorAll('.qty-input');
            inputs.forEach(input => {
                const planned = parseInt(input.dataset.planned) || 0;
                input.value = planned;
                this.handleQuantityChange(input);
            });
        },
        
        /**
         * Reset all quantities to zero
         */
        resetQuantities() {
            if (!confirm('Reset all quantities to zero?')) return;
            
            const inputs = document.querySelectorAll('.qty-input');
            inputs.forEach(input => {
                input.value = '';
                this.handleQuantityChange(input);
            });
        },
        
        /**
         * Show product image modal
         * @param {HTMLElement} el - Image element
         */
        showProductImage(el) {
            const src = el.dataset.src || el.src;
            const name = el.dataset.name || 'Product Image';
            
            if (window.SharedModal) {
                window.SharedModal.showImage(src, name);
            }
        },
        
        /**
         * Update footer totals
         */
        updateTotals() {
            let plannedTotal = 0;
            let countedTotal = 0;
            let totalWeight = 0;
            
            document.querySelectorAll('.pack-item-row').forEach(row => {
                const planned = parseInt(row.dataset.plannedQty) || 0;
                const input = row.querySelector('.qty-input');
                const counted = parseInt(input?.value) || 0;
                const unitWeight = parseInt(row.dataset.unitWeightG) || 0;
                
                plannedTotal += planned;
                countedTotal += counted;
                totalWeight += (counted * unitWeight);
            });
            
            const diff = countedTotal - plannedTotal;
            const accuracy = plannedTotal > 0 
                ? Math.round((countedTotal / plannedTotal) * 100) 
                : 0;
            
            // Update footer
            const plannedEl = document.getElementById('plannedTotalFooter');
            const countedEl = document.getElementById('countedTotalFooter');
            const diffEl = document.getElementById('diffTotalFooter');
            const weightEl = document.getElementById('totalWeightFooter');
            
            if (plannedEl) plannedEl.textContent = plannedTotal;
            if (countedEl) countedEl.textContent = countedTotal;
            if (diffEl) diffEl.textContent = (diff > 0 ? '+' : '') + diff;
            if (weightEl) weightEl.textContent = (totalWeight / 1000).toFixed(3) + 'kg';
        },
        
        /**
         * Request lock from another user
         */
        async requestLock() {
            if (window.PackLockClient) {
                const success = await window.PackLockClient.requestLock();
                if (success) {
                    this.updateLockInterface();
                    if (window.PackToast) {
                        window.PackToast.success('Lock acquired successfully');
                    }
                } else {
                    if (window.PackToast) {
                        window.PackToast.error('Failed to acquire lock');
                    }
                }
            }
        },
        
        /**
         * Cancel lock request
         */
        cancelLockRequest() {
            // Hide request buttons, show normal state
            this.updateLockInterface();
        },
        
        /**
         * Update lock interface based on current status
         */
        updateLockInterface() {
            const lockBar = document.getElementById('lockRequestBar');
            const requestBtn = document.getElementById('lockRequestBtn');
            const cancelBtn = document.getElementById('lockCancelRequestBtn');
            
            if (!window.lockStatus || !lockBar) return;
            
            if (window.lockStatus.has_lock) {
                // User has lock - hide the bar
                lockBar.style.display = 'none';
            } else if (window.lockStatus.is_locked_by_other) {
                // Someone else has lock - show request option
                lockBar.style.display = 'block';
                if (requestBtn) requestBtn.classList.remove('d-none');
                if (cancelBtn) cancelBtn.classList.add('d-none');
            } else {
                // No lock - hide the bar
                lockBar.style.display = 'none';
            }
        },
    };
    
    // Export to global scope
    window.PackCore = PackCore;
    
    // Auto-init when DISPATCH_BOOT is available
    if (window.DISPATCH_BOOT) {
        document.addEventListener('DOMContentLoaded', () => {
            PackCore.init(window.DISPATCH_BOOT);
        });
    }
    
})(window);
