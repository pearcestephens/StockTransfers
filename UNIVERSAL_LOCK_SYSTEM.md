# Universal Lock System Documentation

## Overview

The Universal Lock System provides **exclusive access control** for any resource in any module. It's a **plug-and-play** solution that can be easily integrated into existing pages.

## Features

✅ **Multi-Resource Support** - Works with transfers, products, orders, customers, etc.  
✅ **Auto-Expiry** - Locks automatically expire (default 30 minutes)  
✅ **Real-time Polling** - Smart background updates when page is visible  
✅ **Ownership Requests** - Users can request access from current lock holder  
✅ **Heartbeat System** - Keeps active sessions alive  
✅ **Page Visibility Detection** - Pauses polling when tab is hidden  
✅ **Browser Unload Protection** - Releases locks when user leaves  
✅ **Cross-Module Compatible** - Works in any module with minimal setup  

## Quick Setup (3 Steps)

### 1. Include the JavaScript Library

```html
<script src="/modules/transfers/stock/assets/js/universal-lock-system.js"></script>
```

### 2. Initialize on Your Page

```javascript
// Minimal setup
const lockSystem = new UniversalLockSystem({
  resourceType: 'transfer',     // or 'product', 'order', 'customer', etc.
  resourceId: 12345,           // The ID of the resource to lock
  apiBasePath: '/modules/transfers/stock/api'  // Path to the API endpoints
});

// Try to acquire lock on page load
lockSystem.acquireLock();
```

### 3. Add Lock Badge/Overlay to Your HTML

```html
<!-- Lock status badge -->
<span id="lockBadge" class="badge">CHECKING...</span>

<!-- Read-only overlay (hidden by default) -->
<div id="lockOverlay" class="lock-overlay" style="display: none;"></div>
```

## Complete Integration Example

```javascript
const lockSystem = new UniversalLockSystem({
  resourceType: 'transfer',
  resourceId: transferId,
  apiBasePath: '/modules/transfers/stock/api',
  
  // Callbacks for different lock events
  onLockAcquired: (lockInfo) => {
    console.log('Lock acquired!', lockInfo);
    lockSystem.showLockBadge('#lockBadge');
    enableEditing(true);
  },
  
  onLockLost: () => {
    console.log('Lock lost!');
    enableEditing(false);
    showNotification('You no longer have exclusive access', 'warning');
  },
  
  onReadOnlyMode: (status) => {
    console.log('Read-only mode:', status);
    lockSystem.showReadOnlyOverlay('#lockOverlay', status);
    enableEditing(false);
  },
  
  onLockRequested: (request) => {
    console.log('Someone requested ownership:', request);
    showOwnershipRequestPopup(request);
  }
});

// Helper functions
function enableEditing(enabled) {
  const formControls = document.querySelectorAll('input, button, select, textarea');
  formControls.forEach(control => {
    control.disabled = !enabled;
  });
}

function showNotification(message, type = 'info') {
  // Your notification system
}

function showOwnershipRequestPopup(request) {
  if (confirm(`${request.requester_name} wants to edit this ${lockSystem.resourceType}. Grant access?`)) {
    lockSystem.respondToOwnershipRequest(request.id, true);
  } else {
    lockSystem.respondToOwnershipRequest(request.id, false);
  }
}

// Auto-acquire lock when page loads
document.addEventListener('DOMContentLoaded', () => {
  lockSystem.acquireLock();
});
```

## Usage in Different Modules

### Products Module
```javascript
const productLockSystem = new UniversalLockSystem({
  resourceType: 'product',
  resourceId: productId,
  apiBasePath: '/modules/products/api'  // Copy the API files here
});
```

### Orders Module
```javascript
const orderLockSystem = new UniversalLockSystem({
  resourceType: 'order',
  resourceId: orderId,
  apiBasePath: '/modules/orders/api'    // Copy the API files here
});
```

### Customers Module
```javascript
const customerLockSystem = new UniversalLockSystem({
  resourceType: 'customer',
  resourceId: customerId,
  apiBasePath: '/modules/customers/api' // Copy the API files here
});
```

## API Endpoints Required

Copy these 6 files to your module's `/api/` directory:

1. `universal_lock_status.php` - Check lock status
2. `universal_lock_acquire.php` - Acquire exclusive lock  
3. `universal_lock_release.php` - Release lock
4. `universal_lock_heartbeat.php` - Keep lock alive
5. `universal_lock_request.php` - Request ownership
6. `universal_lock_request_respond.php` - Respond to requests

## Database Tables

The system auto-creates these tables:

### `universal_locks`
```sql
CREATE TABLE universal_locks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  resource_type VARCHAR(50) NOT NULL,    -- 'transfer', 'product', etc.
  resource_id INT NOT NULL,              -- The resource ID
  user_id INT NOT NULL,                  -- Who owns the lock
  acquired_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  expires_at TIMESTAMP NOT NULL,         -- Auto-expiry
  heartbeat_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  client_fingerprint VARCHAR(255),
  UNIQUE KEY unique_resource (resource_type, resource_id)
);
```

### `universal_ownership_requests`
```sql
CREATE TABLE universal_ownership_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  resource_type VARCHAR(50) NOT NULL,
  resource_id INT NOT NULL,
  requester_user_id INT NOT NULL,        -- Who wants access
  target_user_id INT NOT NULL,           -- Current owner
  message TEXT,
  status ENUM('pending', 'granted', 'denied') DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  expires_at TIMESTAMP NOT NULL          -- 60 second timeout
);
```

## Configuration Options

```javascript
const lockSystem = new UniversalLockSystem({
  resourceType: 'transfer',              // Required: type of resource
  resourceId: 12345,                     // Required: ID of resource
  apiBasePath: '/api',                   // Required: path to API endpoints
  pollInterval: 10000,                   // Optional: polling frequency (ms)
  lockDuration: 1800,                    // Optional: lock duration (seconds)
  requestTimeout: 60,                    // Optional: request timeout (seconds)
  
  // Optional callbacks
  onLockAcquired: (lock) => {},
  onLockLost: () => {},
  onReadOnlyMode: (status) => {},
  onLockRequested: (request) => {}
});
```

## Safety Features

🔒 **Auto-Expiry** - Locks expire automatically (30 minutes default)  
🔄 **Auto-Cleanup** - Expired locks cleaned up on every API call  
👁️ **Page Visibility** - Polling pauses when tab is hidden  
📡 **sendBeacon** - Reliable lock release on page unload  
💓 **Heartbeat** - Active sessions keep locks alive  
⏱️ **Request Timeout** - Ownership requests expire in 60 seconds  

## Migration from Old System

Replace your existing lock code with:

```javascript
// OLD (transfer-specific)
function acquireLock() { /* custom implementation */ }

// NEW (universal)
const lockSystem = new UniversalLockSystem({
  resourceType: 'transfer',
  resourceId: transferId,
  apiBasePath: '/modules/transfers/stock/api'
});
lockSystem.acquireLock();
```

The system is **backward compatible** and can coexist with existing lock implementations during migration.

## Summary

This Universal Lock System provides **enterprise-grade exclusive access control** that can be applied to **any resource in any module** with just 3 lines of code. It's designed to be:

- ✅ **Drop-in compatible** with existing pages
- ✅ **Self-managing** with auto-expiry and cleanup  
- ✅ **Collaborative** with ownership request system
- ✅ **Scalable** across multiple modules and resource types
- ✅ **Bulletproof** with multiple safety mechanisms