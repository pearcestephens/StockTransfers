/**
 * Universal Lock System - JavaScript Library
 * Can be used on any page that needs exclusive access control
 * 
 * Usage:
 * const lockSystem = new UniversalLockSystem({
 *   resourceType: 'transfer',     // or 'product', 'order', 'customer', etc.
 *   resourceId: 12345,
 *   apiBasePath: '/modules/transfers/stock/api',
 *   onLockAcquired: (lockInfo) => { ... },
 *   onLockLost: () => { ... },
 *   onLockRequested: (request) => { ... }
 * });
 */

class UniversalLockSystem {
  constructor(options = {}) {
    this.resourceType = options.resourceType || 'resource';
    this.resourceId = options.resourceId;
    this.apiBasePath = options.apiBasePath || '/api/locks';
    this.pollInterval = options.pollInterval || 10000; // 10 seconds
    this.lockDuration = options.lockDuration || 1800; // 30 minutes
    this.requestTimeout = options.requestTimeout || 60; // 60 seconds
    
    // Callbacks
    this.onLockAcquired = options.onLockAcquired || (() => {});
    this.onLockLost = options.onLockLost || (() => {});
    this.onLockRequested = options.onLockRequested || (() => {});
    this.onReadOnlyMode = options.onReadOnlyMode || (() => {});
    
    // State
    this.lockStatus = {
      has_lock: false,
      is_locked: false,
      is_locked_by_other: false,
      holder_name: null,
      expires_at: null
    };
    
    this.isPageVisible = !document.hidden;
    this.pollTimer = null;
    this.heartbeatTimer = null;
    
    this.init();
  }
  
  init() {
    if (!this.resourceId) {
      console.error('UniversalLockSystem: resourceId is required');
      return;
    }
    
    this.setupPageVisibilityHandling();
    this.setupBeforeUnloadHandler();
    this.checkLockStatus();
    this.startPolling();
  }
  
  setupPageVisibilityHandling() {
    document.addEventListener('visibilitychange', () => {
      this.isPageVisible = !document.hidden;
      
      if (this.isPageVisible) {
        this.startPolling();
        console.log('Page visible - resumed lock polling');
      } else {
        this.stopPolling();
        console.log('Page hidden - paused lock polling');
      }
    });
  }
  
  setupBeforeUnloadHandler() {
    window.addEventListener('beforeunload', () => {
      if (this.lockStatus.has_lock) {
        const formData = new FormData();
        formData.append('resource_type', this.resourceType);
        formData.append('resource_id', this.resourceId);
        navigator.sendBeacon(`${this.apiBasePath}/universal_lock_release.php`, formData);
      }
    });
  }
  
  async acquireLock(fingerprint = null) {
    try {
      const response = await fetch(`${this.apiBasePath}/universal_lock_acquire.php`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
          resource_type: this.resourceType,
          resource_id: this.resourceId,
          fingerprint: fingerprint || this.generateFingerprint(),
          duration: this.lockDuration
        })
      });
      
      const data = await response.json();
      
      if (data.success) {
        this.lockStatus.has_lock = true;
        this.lockStatus.is_locked = true;
        this.lockStatus.is_locked_by_other = false;
        this.lockStatus.holder_name = data.lock?.holder_name;
        this.lockStatus.expires_at = data.lock?.expires_at;
        
        this.startHeartbeat();
        this.onLockAcquired(data.lock);
        
        return { success: true, lock: data.lock };
      } else {
        return { success: false, error: data.error, conflict: data.conflict, holder: data.holder };
      }
    } catch (error) {
      console.error('Lock acquire failed:', error);
      return { success: false, error: 'Network error' };
    }
  }
  
  async releaseLock() {
    try {
      const response = await fetch(`${this.apiBasePath}/universal_lock_release.php`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
          resource_type: this.resourceType,
          resource_id: this.resourceId
        })
      });
      
      const data = await response.json();
      
      if (data.success) {
        this.lockStatus.has_lock = false;
        this.lockStatus.is_locked = false;
        this.lockStatus.is_locked_by_other = false;
        this.stopHeartbeat();
        this.onLockLost();
      }
      
      return data;
    } catch (error) {
      console.error('Lock release failed:', error);
      return { success: false, error: 'Network error' };
    }
  }
  
  async requestOwnership(message = 'Requesting access') {
    try {
      const response = await fetch(`${this.apiBasePath}/universal_lock_request.php`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
          resource_type: this.resourceType,
          resource_id: this.resourceId,
          message: message
        })
      });
      
      return await response.json();
    } catch (error) {
      console.error('Request ownership failed:', error);
      return { success: false, error: 'Network error' };
    }
  }
  
  async checkLockStatus() {
    try {
      const response = await fetch(
        `${this.apiBasePath}/universal_lock_status.php?resource_type=${this.resourceType}&resource_id=${this.resourceId}`,
        {
          method: 'GET',
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }
      );
      
      const data = await response.json();
      
      if (data.success) {
        this.updateLockStatus(data.data);
        
        // Check for ownership requests if we have the lock
        if (data.data.has_lock) {
          this.checkForOwnershipRequests();
        }
      }
      
      return data;
    } catch (error) {
      console.error('Lock status check failed:', error);
      return { success: false, error: 'Network error' };
    }
  }
  
  updateLockStatus(status) {
    const oldStatus = { ...this.lockStatus };
    this.lockStatus = { ...status };
    
    // Trigger callbacks based on status changes
    if (!oldStatus.has_lock && status.has_lock) {
      this.onLockAcquired(status);
      this.startHeartbeat();
    } else if (oldStatus.has_lock && !status.has_lock) {
      this.onLockLost();
      this.stopHeartbeat();
    }
    
    if (status.is_locked_by_other) {
      this.onReadOnlyMode(status);
    }
  }
  
  async checkForOwnershipRequests() {
    try {
      const response = await fetch(
        `${this.apiBasePath}/universal_lock_requests_pending.php?resource_type=${this.resourceType}&resource_id=${this.resourceId}`,
        {
          method: 'GET',
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }
      );
      
      const data = await response.json();
      
      if (data.success && data.requests && data.requests.length > 0) {
        this.onLockRequested(data.requests[0]);
      }
    } catch (error) {
      console.error('Check ownership requests failed:', error);
    }
  }
  
  async respondToOwnershipRequest(requestId, granted) {
    try {
      const response = await fetch(`${this.apiBasePath}/universal_lock_request_respond.php`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
          request_id: requestId,
          granted: granted ? 1 : 0
        })
      });
      
      const data = await response.json();
      
      if (data.success && granted) {
        // We granted ownership - we're now in read-only mode
        this.lockStatus.has_lock = false;
        this.lockStatus.is_locked_by_other = true;
        this.stopHeartbeat();
        this.onLockLost();
      }
      
      return data;
    } catch (error) {
      console.error('Respond to ownership request failed:', error);
      return { success: false, error: 'Network error' };
    }
  }
  
  startPolling() {
    if (this.pollTimer || !this.isPageVisible) return;
    
    this.pollTimer = setInterval(() => {
      if (this.isPageVisible) {
        this.checkLockStatus();
      }
    }, this.pollInterval);
  }
  
  stopPolling() {
    if (this.pollTimer) {
      clearInterval(this.pollTimer);
      this.pollTimer = null;
    }
  }
  
  startHeartbeat() {
    if (this.heartbeatTimer) return;
    
    this.heartbeatTimer = setInterval(async () => {
      if (this.lockStatus.has_lock) {
        try {
          await fetch(`${this.apiBasePath}/universal_lock_heartbeat.php`, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded',
              'X-Requested-With': 'XMLHttpRequest'
            },
            body: new URLSearchParams({
              resource_type: this.resourceType,
              resource_id: this.resourceId
            })
          });
        } catch (error) {
          console.error('Heartbeat failed:', error);
        }
      }
    }, 30000); // Every 30 seconds
  }
  
  stopHeartbeat() {
    if (this.heartbeatTimer) {
      clearInterval(this.heartbeatTimer);
      this.heartbeatTimer = null;
    }
  }
  
  generateFingerprint() {
    return btoa(navigator.userAgent + window.screen.width + window.screen.height + Date.now()).slice(0, 32);
  }
  
  // Utility methods for UI integration
  showLockBadge(selector, status = this.lockStatus) {
    const badge = document.querySelector(selector);
    if (!badge) return;
    
    if (status.has_lock) {
      badge.textContent = 'LOCKED BY YOU';
      badge.style.background = 'rgba(40, 167, 69, 0.9)';
      badge.style.border = '1px solid rgba(40, 167, 69, 1)';
      badge.style.color = 'white';
    } else if (status.is_locked_by_other) {
      badge.textContent = `LOCKED BY ${status.holder_name || 'OTHER USER'}`;
      badge.style.background = 'rgba(220, 53, 69, 0.9)';
      badge.style.border = '1px solid rgba(220, 53, 69, 1)';
      badge.style.color = 'white';
    } else {
      badge.textContent = 'ACQUIRING LOCK...';
      badge.style.background = 'rgba(255, 193, 7, 0.9)';
      badge.style.border = '1px solid rgba(255, 193, 7, 1)';
      badge.style.color = 'black';
    }
  }
  
  showReadOnlyOverlay(selector, status = this.lockStatus) {
    const overlay = document.querySelector(selector);
    if (!overlay) return;
    
    if (status.is_locked_by_other) {
      overlay.style.display = 'block';
      overlay.innerHTML = `
        <div class="text-center p-4">
          <i class="fa fa-lock fa-3x text-warning mb-3"></i>
          <h5>Resource Locked</h5>
          <p class="mb-3">This ${this.resourceType} is currently being edited by <strong>${status.holder_name || 'another user'}</strong></p>
          <p class="text-muted small mb-3">You are in read-only mode.</p>
          <button class="btn btn-warning" onclick="universalLockSystem.requestOwnership()">
            <i class="fa fa-hand-paper mr-2"></i>Request Ownership
          </button>
        </div>
      `;
    } else {
      overlay.style.display = 'none';
    }
  }
  
  // Cleanup method
  destroy() {
    this.stopPolling();
    this.stopHeartbeat();
    
    // Remove event listeners if needed
    if (this.lockStatus.has_lock) {
      this.releaseLock();
    }
  }
}

// Export for use in modules
if (typeof module !== 'undefined' && module.exports) {
  module.exports = UniversalLockSystem;
}

// Make available globally
window.UniversalLockSystem = UniversalLockSystem;