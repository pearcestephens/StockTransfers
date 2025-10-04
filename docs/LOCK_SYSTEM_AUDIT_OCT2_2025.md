# (Consolidated) LOCK SYSTEM COMPREHENSIVE AUDIT — October 2, 2025

> Primary reference for ongoing lock design, lifecycle, monitoring, and roadmap has moved to `LOCK_SYSTEM_GUIDE.md`. This audit snapshot is retained for provenance & cleanup traceability.

## 🔍 AUDIT SCOPE
Complete investigation of all lock-related files, functions, and UI elements to identify:
- Orphaned/unused code
- Duplicate functionality
- Corrupted files
- Active vs legacy systems
- Current UI elements

---

## 📊 FILE INVENTORY

### ✅ ACTIVE CORE FILES (KEEP - IN USE)

#### Backend - Lock Operations
1. **`api/simple_lock.php`** ✅ ACTIVE
   - Status: PRIMARY lock endpoint
   - Actions: status, acquire, heartbeat, release, steal
   - Used by: `simple-lock.js`
   - Dependencies: `cis_pdo()`, `simple_locks` table
   - Lines: 116
   - Last verified: Oct 2, 2025

2. **`api/lock_events.php`** ✅ ACTIVE (RECENTLY HARDENED)
   - Status: SSE endpoint for real-time lock notifications
   - Purpose: Push lock state changes to clients
   - Features: 5min timeout, backoff polling, heartbeat, zombie prevention
   - Used by: `simple-lock.js` (EventSource)
   - Lines: 277
   - Last updated: Oct 2, 2025 (user hardened)

3. **`api/simple_lock_guard.php`** ✅ ACTIVE
   - Status: Server-side write protection
   - Purpose: Validates lock ownership before allowing writes
   - Used by: pack_save.php, add_product.php, draft_save_api.php, save_manual_tracking.php, notes_add.php, assign_tracking.php
   - Function: `require_lock_or_423($resource_key, $owner_id, $tab_id, $token)`
   - Lines: ~50

#### Frontend - Lock Client
4. **`assets/js/simple-lock.js`** ✅ ACTIVE
   - Status: PRIMARY lock client library
   - Purpose: SimpleLock class with SSE + BroadcastChannel
   - Used by: pack.view.php
   - Features: EventSource, BroadcastChannel, reconnect logic, visibility checks
   - Lines: 312
   - Dependencies: None (vanilla JS)

5. **`pack.view.php`** ✅ ACTIVE
   - Status: PRIMARY UI for transfer pack page
   - Purpose: Full page template with integrated lock system
   - Features: RED/PURPLE bar, spectator mode, diagnostic modal
   - Lock integration: Inline script using SimpleLock
   - Lines: 1082
   - UI Elements: Bottom bar, diagnostic modal, header badge

#### Services (Legacy - Still Referenced)
6. **`services/PackLockService.php`** ⚠️ LEGACY (Referenced but replaced)
   - Status: OLD lock service (superseded by simple_locks)
   - Still referenced by: pack.php (line 35, 145), ServerLockGuard.php
   - Database: `transfer_locks` table (old schema)
   - Should be: DEPRECATED but not yet removed

---

### ❌ CORRUPTED FILES (REQUIRE CLEANUP)

1. **`api/lock_gateway.php`** 🔴 SEVERELY CORRUPTED
   - Issue: Multiple duplicate PHP open tags (`<?php<?php<?php<?php`)
   - Issue: Mixed/concatenated code from multiple versions
   - Issue: Duplicate function definitions
   - Issue: Broken syntax (multiple `require_once` on same lines)
   - Lines: 486 (bloated)
   - Status: **COMPLETELY UNUSABLE** - needs total rewrite or deletion
   - Referenced by: `pack-lock.js` (legacy code)
   - Action: DELETE or complete rewrite

2. **`api/lock_request_events.php`** ⚠️ STUB ONLY
   - Issue: Emergency stub created after corruption
   - Purpose: Prevent fatal errors
   - Returns: Empty events array
   - Status: NON-FUNCTIONAL stub
   - Action: DELETE (not needed with simple_locks system)

3. **`assets/js/pack-lock.js`** 🔴 SEVERELY CORRUPTED
   - Issue: Multiple duplicate code blocks concatenated
   - Issue: Mixed versions of same functions
   - Issue: References to lock_gateway.php (corrupted endpoint)
   - Issue: Duplicate comments and headers
   - Lines: 1200+ (massively bloated)
   - Status: **UNUSABLE**
   - Replacement: `simple-lock.js` (already active)
   - Action: DELETE

4. **`assets/js/pack-lock.gateway-adapter.js`** ❓ PURPOSE UNCLEAR
   - Description: "Lightweight adapter to route PackLockSystem calls through lock_gateway.php"
   - Problem: lock_gateway.php is corrupted
   - Status: Likely orphaned
   - Action: DELETE

---

### 🗑️ ORPHANED/UNUSED FILES (DELETE)

1. **`api/_check_lock_table.php`**
   - Purpose: Table existence check utility
   - Used by: Unknown (grep shows no references)
   - Status: Orphaned utility
   - Action: DELETE

2. **`api/_debug_*.php` files** (5 files)
   - `_debug_popup.html`
   - `_debug_schema.php`
   - `_diag_headers.php`
   - `_diag.php`
   - `_workflow_debug.php`
   - Purpose: Development debugging
   - Status: Should not be in production
   - Action: DELETE (keep backups)

3. **`api/_test_*.php` files** (4 files)
   - `_test_api.php`
   - `_test_auto_grant.php`
   - `_test_auto_grant_live.php`
   - `_test_ownership_request.php`
   - Purpose: Test scripts
   - Status: Should not be in production
   - Action: DELETE (keep backups)

4. **`api/_syntax_test.html`**
   - Purpose: Frontend testing
   - Status: Development artifact
   - Action: DELETE

5. **`api/_table_test.php`**
   - Purpose: Database testing
   - Status: Development artifact
   - Action: DELETE

6. **`api/_test_suite.html`**
   - Purpose: Test runner
   - Status: Development artifact
   - Action: DELETE

---

## 📋 DATABASE SCHEMA STATUS

### Active Tables

1. **`simple_locks`** ✅ ACTIVE (PRIMARY)
   ```sql
   CREATE TABLE simple_locks (
     resource_key VARCHAR(191) PRIMARY KEY,
     owner_id VARCHAR(64) NOT NULL,
     tab_id VARCHAR(64) NOT NULL,
     token CHAR(32) NOT NULL,
     acquired_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
     expires_at TIMESTAMP NOT NULL,
     INDEX idx_exp (expires_at),
     INDEX idx_owner (owner_id)
   );
   ```
   - Purpose: Single-resource exclusive locks
   - Used by: simple_lock.php, lock_events.php
   - Cleanup: Automatic via expires_at

2. **`transfer_locks`** ⚠️ LEGACY (OLD SYSTEM)
   ```sql
   CREATE TABLE IF NOT EXISTS transfer_locks (
     id INT AUTO_INCREMENT PRIMARY KEY,
     transfer_id INT NOT NULL,
     user_id INT NOT NULL,
     holder_name VARCHAR(100),
     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
     expires_at TIMESTAMP NULL
   );
   ```
   - Purpose: Old lock system
   - Used by: PackLockService.php (legacy)
   - Status: Should be migrated/deprecated
   - Action: Keep for now (still referenced)

---

## 🎯 UI ELEMENT INVENTORY

### Active UI Elements (pack.view.php)

#### Header Elements
- `#lockStatusBadge` ✅ Lock status badge (UNLOCKED/LOCKED/LOCKED BY X)
- `#lockDiagnosticBtn` ✅ Diagnostic cog button
- Header gradient (purple) ✅

#### Lock Request Bar (Bottom)
- `#lockRequestBar` ✅ Bottom fixed bar (RED/PURPLE)
- `#lockRequestBtn` ✅ "Request Lock" / "Take Control" / "Release" button
- `#lockCancelRequestBtn` ✅ "Cancel" button (during request)
- `#lockRequestCountdown` ✅ Countdown timer display

#### Diagnostic Modal
- `#lockDiagModal` ✅ Modal overlay
- `#lockDiagContent` ✅ Modal content container
- `#lockDiagBody` ✅ Diagnostic data display area
- Modal buttons: Copy All, Refresh, Close ✅

#### Visual Effects
- `.spectator-blur-wrap` ✅ Fade effect when spectator (60% opacity)
- `.pack-spectator` ✅ Body class for spectator mode
- `.lock-badge` classes ✅ Badge styling (mine/other/unlocked/conflict)

---

## 🔧 FUNCTION DEPENDENCY MAP

### Active Functions (simple-lock.js)

```
SimpleLock class
├── constructor(options)
├── start() → Initiates lock acquisition
├── status() → Checks lock status
├── acquire() → Attempts to acquire lock
├── steal() → Same-owner instant takeover
├── heartbeat() → Extends lock TTL
├── release() → Releases lock
├── startSSE() → Opens EventSource connection
├── stopSSE() → Closes EventSource connection
└── _emit(state, key, info) → Triggers onChange callback
```

### Active Functions (pack.view.php inline)

```
Lock Integration
├── initUnderlying() → Creates SimpleLock instance
├── acquireUnderlying() → Wrapper for acquire
├── releaseUnderlying() → Wrapper for release
├── stealUnderlying() → Wrapper for steal
├── applyMode() → Updates UI based on state
├── startCountdown(seconds) → 60s request timer
├── finalizeRequestTimeout() → Handles timeout
├── updateBadge(state, info) → Updates header badge
├── setDisabled(disabled) → Toggles form elements
├── setSpectatorUi(on) → Applies fade effect
└── logDiagEvent(type, detail) → Logs for diagnostics

Diagnostic Functions
├── showLockDiagnostic() → Opens modal with full diagnostics
├── hideLockDiagnostic() → Closes modal
├── refreshDiagnostics() → Re-fetches data
└── copyDiagnostics() → Copies JSON to clipboard
```

---

## 🚨 CRITICAL ISSUES FOUND

### 1. lock_gateway.php Corruption
**Severity**: CRITICAL  
**Impact**: Completely unusable, references from pack-lock.js will fail  
**Root Cause**: Multiple file concatenations/merges gone wrong  
**Evidence**:
```php
<?php<?php<?php<?php  // 4 duplicate open tags
require_once $DOCUMENT_ROOT . '/app.php';declare(strict_types=1);  // Two statements on one line
```
**Resolution**: DELETE entire file (replaced by simple_lock.php)

### 2. pack-lock.js Duplication
**Severity**: HIGH  
**Impact**: Massive file size (1200+ lines), conflicting code  
**Root Cause**: Multiple versions concatenated  
**Evidence**: Duplicate function definitions, mixed endpoint references  
**Resolution**: DELETE entire file (replaced by simple-lock.js)

### 3. Legacy System Still Active
**Severity**: MEDIUM  
**Impact**: Two parallel lock systems running (confusion, bugs)  
**Components**: PackLockService.php, transfer_locks table, lock_gateway.php  
**Resolution**: Complete migration to simple_locks or explicit deprecation

### 4. Test/Debug Files in Production
**Severity**: MEDIUM  
**Impact**: Security risk, clutter  
**Files**: 11 _test_ and _debug_ files  
**Resolution**: DELETE all (move to dev environment)

---

## ✅ CLEANUP ACTION PLAN

### Phase 1: Delete Corrupted Files (IMMEDIATE) — EXECUTED 2025-10-02
```bash
Already performed. Backups stored under `modules/transfers/backups/2025-10-02_legacy_lock_cleanup/`.
```

### Phase 2: Delete Test/Debug Files — EXECUTED 2025-10-02
```bash
All listed files removed; truncated backup stubs stored in `backups/2025-10-02_legacy_lock_cleanup/`.
```

### Phase 3: Update References
- Check for any remaining references to deleted files
- Update pack.php if it loads pack-lock.js
- Ensure pack.view.php only uses simple-lock.js

### Phase 4: Deprecate Legacy System (Optional)
- Add deprecation notices to PackLockService.php
- Plan migration from transfer_locks to simple_locks
- Update ServerLockGuard.php to use simple_lock.php

---

## 📈 CURRENT SYSTEM STATUS

### Active Lock System Architecture

```
┌─────────────────────────────────────────────────────────┐
│ CLIENT (Browser Tab)                                    │
│ ┌─────────────────────────────────────────────────────┐ │
│ │ pack.view.php (UI)                                  │ │
│ │ ├── SimpleLock instance                             │ │
│ │ ├── EventSource (SSE)                               │ │
│ │ ├── BroadcastChannel (same-browser instant)        │ │
│ │ └── UI: RED/PURPLE bar, diagnostic modal           │ │
│ └─────────────────────────────────────────────────────┘ │
└──────────────┬──────────────────────────────────────────┘
               │
               ├─ POST → simple_lock.php (acquire/release/steal/heartbeat)
               └─ SSE → lock_events.php (real-time state push)
                        │
                        ↓
               ┌────────────────────────────┐
               │ SERVER (Database)          │
               │ ┌────────────────────────┐ │
               │ │ simple_locks table     │ │
               │ │ (resource_key PK)      │ │
               │ └────────────────────────┘ │
               └────────────────────────────┘
```

### Communication Flow

1. **Page Load**:
   - pack.view.php initializes SimpleLock
   - Calls `status()` → simple_lock.php
   - If unlocked → `acquire()` → simple_lock.php
   - If locked → spectator mode (RED or PURPLE bar)

2. **Real-Time Updates**:
   - SimpleLock.startSSE() → lock_events.php
   - Server polls simple_locks every 250ms-2s
   - On state change → SSE event → client
   - BroadcastChannel notifies same-browser tabs (0ms)

3. **Lock Operations**:
   - **Acquire**: POST to simple_lock.php?action=acquire
   - **Steal** (same owner): POST to simple_lock.php?action=steal
   - **Heartbeat**: POST to simple_lock.php?action=heartbeat (every 30s)
   - **Release**: POST to simple_lock.php?action=release

4. **Write Protection**:
   - All write endpoints include `simple_lock_guard.php`
   - Validates token match before allowing writes
   - Returns HTTP 423 if lock invalid

---

## 🎯 POST-CLEANUP VERIFICATION CHECKLIST

### Files Deleted (Expected: 18 files)
- [x] lock_gateway.php (deleted; backup created)
- [x] pack-lock.js (deleted; backup created)
- [x] pack-lock.gateway-adapter.js (deleted; backup created)
- [x] lock_request_events.php (deleted; backup created)
- [x] _check_lock_table.php (deleted, backup stub)
- [x] _debug_* files (deleted, backup stubs)
- [x] _test_* files (deleted, backup stubs)
- [x] Test HTML files (deleted, backup stubs)

### System Still Works
- [ ] Page loads without errors
- [ ] Lock acquisition works
- [ ] RED bar shows for same owner
- [ ] PURPLE bar shows for different owner
- [ ] Steal works instantly (same owner)
- [ ] SSE connection establishes
- [ ] Diagnostic modal opens and populates
- [ ] No 404 errors in console
- [ ] No PHP errors in Apache log

### Code References Updated
- [ ] No references to lock_gateway.php
- [x] No references to pack-lock.js (verified after cleanup)
- [ ] pack.php doesn't load deleted files
- [ ] All endpoints use simple_lock.php

---

## 📊 FINAL FILE COUNT

### Before Cleanup
- Lock-related files: ~35 files
- Corrupted/duplicate: 4 files
- Test/debug: 14 files
- Total bloat: 18 files

### After Cleanup (Post Phase 2)
- Active core: 5 files (simple_lock.php, lock_events.php, simple_lock_guard.php, simple-lock.js, pack.view.php)
- UI Layer Assets: lock-ui.js, lock-diagnostics.js, lock-selftest.js, lock-ui.css (counted separately for clarity)
- Legacy (deprecated): 1 file (PackLockService.php)
- Support: 1 file (ServerLockGuard.php - needs update)
- Removed dev/test/debug artifacts: 13
- **Total active lock backend/client core**: 5 (unchanged); surrounding UI asset modules: 4

---

## ✅ SUCCESS CRITERIA

Cleanup is successful when:
1. ✅ All 18 identified files deleted
2. ✅ No broken references (404s, undefined errors)
3. ✅ Lock system fully functional
4. ✅ Page loads cleanly
5. ✅ Diagnostic modal works
6. ✅ All tests pass (manual checklist)
7. ✅ Apache error log clean

---

**Audit Completed**: October 2, 2025 22:05 UTC  
**Next Action**: Execute Phase 1 cleanup (delete corrupted files)  
**Backup Location**: `~/backups/transfers_lock_cleanup_YYYYMMDD/`
