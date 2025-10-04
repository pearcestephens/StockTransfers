/**
 * Advanced Pack Lock Client Module
 * 
 * Comprehensive lock system with:
 * - BroadcastChannel for instant tab communication (0ms)
 * - Server-Sent Events for real-time updates (50-500ms)
 * - Tab ownership detection and management
 * - 60-second takeover countdown
 * - Visual blur and disable effects
 * - Same-user vs different-user handling
 * 
 * @module pack/lock-client-advanced
 */
(function(window) {
    'use strict';
    
    const PackLockClientAdvanced = {
        config: {
            transferId: null,
            userId: null,
            sessionId: null,
            heartbeatInterval: 30000, // 30 seconds
            takeoverTimeout: 60000,   // 60 seconds
        },
        
        // Communication channels
        broadcastChannel: null,
        eventSource: null,
        
        // Timers
        heartbeatTimer: null,
        takeoverTimer: null,
        countdownTimer: null,
        
        // State management
        tabId: null,
        isOwner: false,
        isSpectator: false,
        currentLockState: null,
        pendingRequest: null,
        otherTabs: new Map(),
        
        /**
         * Initialize advanced lock client
         * @param {Object} config - Configuration object
         */
        init(config) {
            console.log('[PackLockClientAdvanced] Initializing...', config);
            
            // Map server fields to client config
            const clientConfig = {
                transferId: config?.transfer_id,
                userId: config?.user_id,
                sessionId: config?.session_id,
                ...config
            };
            
            Object.assign(this.config, clientConfig);
            
            if (!this.config.transferId) {
                console.error('[PackLockClientAdvanced] Missing transfer ID', { config, clientConfig: this.config });
                return;
            }
            
            console.log('[PackLockClientAdvanced] Config validated:', this.config);
            
            // Generate unique tab ID
            this.tabId = this.generateTabId();
            
            // Initialize BroadcastChannel for tab communication
            this.initBroadcastChannel();
            
            // Initialize Server-Sent Events
            this.initServerSentEvents();
            
            // Check initial lock state
            this.checkInitialLockState();
            
            // Setup event listeners
            this.setupEventListeners();
            
            // Setup page visibility handling
            this.setupVisibilityHandling();
            
            // Add lock status indicator
            this.addLockStatusIndicator();
            
            console.log('[PackLockClientAdvanced] Initialized with tabId:', this.tabId);
        },
        
        /**
         * Generate unique tab identifier
         */
        generateTabId() {
            return 'tab_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        },
        
        /**
         * Initialize BroadcastChannel for instant tab communication
         */
        initBroadcastChannel() {
            if (!window.BroadcastChannel) {
                console.warn('[PackLockClientAdvanced] BroadcastChannel not supported');
                return;
            }
            
            this.broadcastChannel = new BroadcastChannel('pack_lock_' + this.config.transferId);
            
            this.broadcastChannel.addEventListener('message', (event) => {
                this.handleBroadcastMessage(event.data);
            });
            
            // Announce this tab
            this.broadcastMessage({
                type: 'tab_announce',
                tabId: this.tabId,
                userId: this.config.userId,
                timestamp: Date.now()
            });
        },
        
        /**
         * Initialize Server-Sent Events for real-time lock updates
         */
        initServerSentEvents() {
            const sseUrl = `/modules/transfers/stock/api/lock_events.php?transfer_id=${this.config.transferId}&tab_id=${this.tabId}&user_id=${this.config.userId}`;
            
            this.eventSource = new EventSource(sseUrl);
            
            this.eventSource.addEventListener('lock_status', (event) => {
                try {
                    const data = JSON.parse(event.data);
                    this.handleLockStatusUpdate(data);
                } catch (e) {
                    console.error('[PackLockClientAdvanced] Failed to parse SSE data:', e);
                }
            });
            
            this.eventSource.addEventListener('lock_request', (event) => {
                try {
                    const data = JSON.parse(event.data);
                    this.handleLockRequest(data);
                } catch (e) {
                    console.error('[PackLockClientAdvanced] Failed to parse lock request:', e);
                }
            });
            
            this.eventSource.addEventListener('error', (event) => {
                console.warn('[PackLockClientAdvanced] SSE connection error, will retry automatically');
            });
        },
        
        /**
         * Handle broadcast messages from other tabs
         */
        handleBroadcastMessage(data) {
            switch (data.type) {
                case 'tab_announce':
                    if (data.tabId !== this.tabId && data.userId === this.config.userId) {
                        this.otherTabs.set(data.tabId, data);
                        this.handleSameUserMultipleTabs();
                    }
                    break;
                    
                case 'tab_close':
                    this.otherTabs.delete(data.tabId);
                    this.checkTabOwnership();
                    break;
                    
                case 'lock_taken':
                    if (data.newOwnerTabId !== this.tabId) {
                        this.handleLockTaken(data);
                    }
                    break;
                    
                case 'lock_released':
                    this.handleLockReleased(data);
                    break;
            }
        },
        
        /**
         * Broadcast message to other tabs
         */
        broadcastMessage(data) {
            if (this.broadcastChannel) {
                this.broadcastChannel.postMessage(data);
            }
        },
        
        /**
         * Handle same user multiple tabs scenario
         */
        handleSameUserMultipleTabs() {
            if (this.otherTabs.size > 0 && !this.isOwner) {
                this.showSameUserLockBar();
            }
        },
        
        /**
         * Show red bar for same user multiple tabs
         */
        showSameUserLockBar() {
            this.hideAllLockBars();
            const bar = document.getElementById('sameuserLockBar');
            if (bar) {
                bar.style.display = 'block';
                this.applySpectatorMode();
            }
        },
        
        /**
         * Show purple bar for different user lock
         */
        showOtherUserLockBar(ownerName) {
            this.hideAllLockBars();
            const bar = document.getElementById('otheruserLockBar');
            const ownerNameEl = document.getElementById('lockOwnerName');
            
            if (bar) {
                if (ownerNameEl && ownerName) {
                    ownerNameEl.textContent = ownerName;
                }
                bar.style.display = 'block';
                this.applySpectatorMode();
            }
        },
        
        /**
         * Show success banner when lock acquired
         */
        showLockAcquiredBanner() {
            this.hideAllLockBars();
            const banner = document.getElementById('lockAcquiredBanner');
            if (banner) {
                banner.style.display = 'block';
                this.removeSpectatorMode();
                
                // Auto-hide after 3 seconds
                setTimeout(() => {
                    banner.style.display = 'none';
                }, 3000);
            }
        },
        
        /**
         * Show incoming request banner for current owner
         */
        showIncomingRequestBanner(requesterName) {
            this.hideAllLockBars();
            const banner = document.getElementById('lockRequestIncoming');
            const nameEl = document.getElementById('requesterName');
            
            if (banner) {
                if (nameEl && requesterName) {
                    nameEl.textContent = requesterName;
                }
                banner.style.display = 'block';
                this.startResponseCountdown();
            }
        },
        
        /**
         * Hide all lock bars
         */
        hideAllLockBars() {
            const bars = [
                'sameuserLockBar',
                'otheruserLockBar', 
                'lockAcquiredBanner',
                'lockRequestIncoming'
            ];
            
            bars.forEach(id => {
                const el = document.getElementById(id);
                if (el) el.style.display = 'none';
            });
            
            this.clearCountdowns();
        },
        
        /**
         * Apply spectator mode (blur + disable)
         */
        applySpectatorMode() {
            this.isSpectator = true;
            this.updateLockStatusIndicator('spectator');
            
            // Disable all form inputs
            const inputs = document.querySelectorAll('input, select, textarea, button');
            inputs.forEach(input => {
                if (!input.closest('.lock-bar')) {
                    input.classList.add('lock-disabled');
                    input.disabled = true;
                }
            });
            
            // Apply spectator styling to main content
            const container = document.querySelector('.pack-container');
            if (container) {
                container.classList.add('lock-content-spectator');
            }
        },
        
        /**
         * Remove spectator mode
         */
        removeSpectatorMode() {
            this.isSpectator = false;
            this.updateLockStatusIndicator('owner');
            
            // Re-enable form inputs
            const inputs = document.querySelectorAll('input.lock-disabled, select.lock-disabled, textarea.lock-disabled, button.lock-disabled');
            inputs.forEach(input => {
                input.classList.remove('lock-disabled');
                input.disabled = false;
            });
            
            // Remove spectator styling
            const container = document.querySelector('.pack-container');
            if (container) {
                container.classList.remove('lock-content-spectator');
            }
        },
        
        /**
         * Add floating lock status indicator
         */
        addLockStatusIndicator() {
            const indicator = document.createElement('div');
            indicator.id = 'lockStatusIndicator';
            indicator.className = 'lock-status-indicator';
            indicator.textContent = 'Loading...';
            document.body.appendChild(indicator);
        },
        
        /**
         * Update lock status indicator
         */
        updateLockStatusIndicator(status) {
            const indicator = document.getElementById('lockStatusIndicator');
            if (!indicator) return;
            
            indicator.className = 'lock-status-indicator ' + status;
            
            switch (status) {
                case 'owner':
                    indicator.textContent = '🔓 Owner';
                    break;
                case 'spectator':
                    indicator.textContent = '👁️ Read-only';
                    break;
                case 'locked':
                    indicator.textContent = '🔒 Locked';
                    break;
                default:
                    indicator.textContent = '⏳ Loading...';
            }
        },
        
        /**
         * Start 60-second takeover countdown
         */
        startTakeoverCountdown() {
            let seconds = 60;
            const countdownEl = document.getElementById('takeoverCountdown');
            
            if (countdownEl) {
                countdownEl.classList.remove('d-none');
                
                this.countdownTimer = setInterval(() => {
                    const strongEl = countdownEl.querySelector('strong');
                    if (strongEl) {
                        strongEl.textContent = seconds;
                    }
                    
                    seconds--;
                    
                    if (seconds < 0) {
                        clearInterval(this.countdownTimer);
                        this.executeTakeover();
                    }
                }, 1000);
            }
        },
        
        /**
         * Start response countdown for current owner
         */
        startResponseCountdown() {
            let seconds = 60;
            const countdownEl = document.getElementById('responseCountdown');
            
            if (countdownEl) {
                this.countdownTimer = setInterval(() => {
                    const strongEl = countdownEl.querySelector('strong');
                    if (strongEl) {
                        strongEl.textContent = seconds;
                    }
                    
                    seconds--;
                    
                    if (seconds < 0) {
                        clearInterval(this.countdownTimer);
                        this.allowTakeover();
                    }
                }, 1000);
            }
        },
        
        /**
         * Clear all countdown timers
         */
        clearCountdowns() {
            if (this.countdownTimer) {
                clearInterval(this.countdownTimer);
                this.countdownTimer = null;
            }
        },
        
        /**
         * Setup event listeners for lock actions
         */
        setupEventListeners() {
            document.addEventListener('click', (e) => {
                const action = e.target.closest('[data-action]')?.dataset.action;
                if (!action) return;
                
                switch (action) {
                    case 'take-control':
                        this.takeControl();
                        break;
                    case 'release-control':
                        this.releaseControl();
                        break;
                    case 'request-takeover':
                        this.requestTakeover();
                        break;
                    case 'cancel-request':
                        this.cancelRequest();
                        break;
                    case 'allow-takeover':
                        this.allowTakeover();
                        break;
                    case 'deny-takeover':
                        this.denyTakeover();
                        break;
                    case 'dismiss-success':
                        this.hideAllLockBars();
                        break;
                }
            });
        },
        
        /**
         * Setup page visibility handling
         */
        setupVisibilityHandling() {
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) {
                    // Page became visible - check lock status
                    this.checkTabOwnership();
                }
            });
            
            window.addEventListener('beforeunload', () => {
                this.broadcastMessage({
                    type: 'tab_close',
                    tabId: this.tabId,
                    userId: this.config.userId
                });
            });
        },
        
        /**
         * API Methods for lock management
         */
        async takeControl() {
            try {
                const response = await fetch('/modules/transfers/stock/api/advanced_lock.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'steal',
                        resource_key: this.config.transferId,
                        owner_id: this.config.userId,
                        tab_id: this.tabId
                    }),
                    credentials: 'same-origin'
                });
                
                const data = await response.json();
                
                if (data.ok && data.acquired) {
                    this.isOwner = true;
                    this.showLockAcquiredBanner();
                    this.broadcastMessage({
                        type: 'lock_taken',
                        newOwnerTabId: this.tabId,
                        userId: this.config.userId
                    });
                }
            } catch (error) {
                console.error('[PackLockClientAdvanced] Take control failed:', error);
            }
        },
        
        async releaseControl() {
            // Just close this tab or navigate away
            window.close();
        },
        
        async requestTakeover() {
            try {
                const response = await fetch('/modules/transfers/stock/api/advanced_lock.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'request',
                        resource_key: this.config.transferId,
                        requester_id: this.config.userId,
                        requester_tab: this.tabId
                    }),
                    credentials: 'same-origin'
                });
                
                if (response.ok) {
                    this.startTakeoverCountdown();
                    this.toggleRequestButtons();
                }
            } catch (error) {
                console.error('[PackLockClientAdvanced] Request takeover failed:', error);
            }
        },
        
        async allowTakeover() {
            try {
                await fetch('/modules/transfers/stock/api/advanced_lock.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'response',
                        resource_key: this.config.transferId,
                        owner_id: this.config.userId,
                        tab_id: this.tabId,
                        allow: true
                    }),
                    credentials: 'same-origin'
                });
                
                this.isOwner = false;
                this.hideAllLockBars();
                this.applySpectatorMode();
            } catch (error) {
                console.error('[PackLockClientAdvanced] Allow takeover failed:', error);
            }
        },
        
        async denyTakeover() {
            this.hideAllLockBars();
            // Keep ownership, just dismiss the request
        },
        
        /**
         * Toggle request/cancel buttons
         */
        toggleRequestButtons() {
            const requestBtn = document.getElementById('requestTakeoverBtn');
            const cancelBtn = document.getElementById('cancelRequestBtn');
            
            if (requestBtn && cancelBtn) {
                requestBtn.classList.add('d-none');
                cancelBtn.classList.remove('d-none');
            }
        },
        
        /**
         * Handle various lock events
         */
        handleLockStatusUpdate(data) {
            this.currentLockState = data;
            
            if (data.owner_id === this.config.userId) {
                if (data.tab_id === this.tabId) {
                    this.isOwner = true;
                    this.hideAllLockBars();
                    this.removeSpectatorMode();
                } else {
                    this.showSameUserLockBar();
                }
            } else {
                this.showOtherUserLockBar(data.owner_name);
            }
        },
        
        handleLockRequest(data) {
            if (this.isOwner) {
                this.showIncomingRequestBanner(data.requester_name);
            }
        },
        
        handleLockTaken(data) {
            if (data.userId !== this.config.userId) {
                this.isOwner = false;
                this.showOtherUserLockBar(data.newOwnerName);
            }
        },
        
        handleLockReleased(data) {
            this.hideAllLockBars();
            this.removeSpectatorMode();
        },
        
        checkInitialLockState() {
            // Use the global lockStatus from server
            if (window.lockStatus) {
                this.handleLockStatusUpdate(window.lockStatus);
            }
        },
        
        checkTabOwnership() {
            // Logic to verify current tab ownership
            if (this.isOwner && this.otherTabs.size > 0) {
                this.showSameUserLockBar();
            }
        },
        
        async cancelRequest() {
            this.clearCountdowns();
            const requestBtn = document.getElementById('requestTakeoverBtn');
            const cancelBtn = document.getElementById('cancelRequestBtn');
            
            if (requestBtn && cancelBtn) {
                requestBtn.classList.remove('d-none');
                cancelBtn.classList.add('d-none');
            }
            
            const countdownEl = document.getElementById('takeoverCountdown');
            if (countdownEl) {
                countdownEl.classList.add('d-none');
            }
        },
        
        async executeTakeover() {
            // Force acquire lock after countdown
            await this.takeControl();
        }
    };
    
    // Export to global scope
    window.PackLockClientAdvanced = PackLockClientAdvanced;
    
})(window);