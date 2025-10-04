# (Consolidated) Lock System Diagnosis & Recovery

> NOTE: This file is now historical. The authoritative, maintained documentation lives in `LOCK_SYSTEM_GUIDE.md`. Keep legacy investigative narrative here; update new operational details only in the consolidated guide.

**Date**: October 2, 2025  
**Issue**: Slow page load, session timeout, "Another tab has control" error  
**Root Cause**: PHP Parse Error in `lock_events.php` causing SSE endpoint to crash repeatedly

---

## 🔴 CRITICAL ISSUE FOUND

### Symptoms Reported
- Page took ages to load
- User was logged out unexpectedly
- Message showing "Another tab has control" when returning
- Possible zombie lock from old tab

### Root Cause Analysis

**Apache Error Logs (21:28:46 - 21:30:27)**:
```
PHP Parse error: Unmatched '}' in lock_events.php on line 163
```

**Impact**:
1. ✗ **SSE endpoint completely broken** — EventSource failed to connect
2. ✗ **Client-side infinite reconnect loops** — Trying to connect every 2s
3. ✗ **Page load timeout** — SimpleLock.start() waiting for SSE connection
4. ✗ **Locks not released** — Tab close not detected due to SSE failure
5. ✗ **Session exhaustion** — PHP process crashes causing session loss

**Evidence**:
- 50+ identical parse errors logged between 21:28-21:30
- File only has 152 lines, error reported on line 163 (unmatched brace)
- Current database check shows **1 active lock** on transfer:13217 (user 1, expires in 39s)
- PHP syntax check now passes (issue was fixed manually)

---

## ✅ CURRENT STATUS

### System State
- `lock_events.php`: **FIXED** — syntax error corrected
- Active locks: **1 lock** (transfer:13217, owner_id=1, expires in 39s)
- SSE endpoint: **Working** (no syntax errors detected)
- Lock cleanup: **Automatic** via expires_at timestamp

### Two-Bar System Implemented
- **RED BAR**: Same owner/device → Instant steal (no countdown)
- **PURPLE BAR**: Different owner/device → 60s request + approval needed

---

## 🛡️ PREVENTIVE MEASURES

### 1. SSE Connection Hardening (Already Implemented)
- ✅ Max 5 reconnect attempts (prevents infinite loops)
- ✅ 5-minute connection timeout (prevents zombie connections)
- ✅ Max 300 iterations per connection (breaks runaway loops)
- ✅ Connection abort detection (`connection_aborted()` check every iteration)
- ✅ Exponential backoff (2s delay between reconnects)

### 2. Graceful Degradation Strategy

**Current Behavior**:
- If SSE fails after 5 attempts → System stops trying
- Lock system continues to work (just without instant notifications)
- User can still request/steal locks via button clicks

**Improvement Needed**:
Add user notification when SSE fails:
```javascript
if(this._sseReconnectAttempts > 5){
  console.error('[SimpleLock] SSE unavailable, real-time updates disabled');
  // Show UI notification: "Real-time lock updates temporarily unavailable"
  this._emit('sse_degraded', this.resourceKey, {message: 'Will check status on page refresh'});
}
```

### 3. Stuck Lock Detection & Auto-Cleanup

**Add to pack.view.php `initUnderlying()`**:
```javascript
// Before acquiring, check if any locks are expired and clean them up
const statusCheck = await lockInstance.status();
if(statusCheck.locked && statusCheck.expiresIn <= 0){
  console.warn('[Lock] Detected expired lock, forcing cleanup');
  // Server-side cleanup happens automatically in acquire() attempt
}
```

### 4. Error Monitoring & Alerting

**Add to `lock_events.php`**:
```php
// After catching exception
if ($iteration < 10) {
  // If connection failed in first 10 seconds, likely syntax/config error
  error_log("[SSE] CRITICAL: Connection failed early (iteration $iteration), check code syntax");
}
```

**Add to monitoring dashboard**:
- Alert when SSE errors > 10/minute
- Alert when lock_events.php parse errors detected
- Dashboard widget showing active locks + their expiry times

---

## 🧪 TESTING CHECKLIST

Before considering this fixed, test:

### Test 1: Normal Operation
- [ ] Tab 1 loads → acquires lock instantly
- [ ] Tab 2 loads → sees RED bar "Your Other Tab Has Control"
- [ ] Tab 2 clicks "Take Control" → instant steal (<100ms)
- [ ] Tab 1 sees purple bar within 500ms (SSE notification)

### Test 2: Different User/Device
- [ ] User A Tab 1 acquires lock
- [ ] User B Tab 1 sees PURPLE bar "Another User Has Control"
- [ ] User B clicks "Request Lock" → 60s countdown starts
- [ ] User A sees notification (future feature)

### Test 3: SSE Failure Recovery
- [ ] Temporarily break `lock_events.php` (add syntax error)
- [ ] Refresh page → SSE fails after 5 attempts
- [ ] Lock system still works (buttons respond)
- [ ] Fix `lock_events.php` → refresh page
- [ ] SSE reconnects successfully

### Test 4: Zombie Lock Cleanup
- [ ] Manually insert expired lock: `UPDATE simple_locks SET expires_at='2020-01-01' WHERE resource_key='transfer:13217'`
- [ ] Load page → should see "Acquiring Control" briefly
- [ ] Lock acquired successfully (expired lock cleaned up)

### Test 5: Tab Close Detection
- [ ] Tab 1 acquires lock
- [ ] SSE connection established
- [ ] Close Tab 1 (×)
- [ ] Tab 2 opens within 90s → should see spectator mode briefly
- [ ] After lock expires (90s) → Tab 2 acquires automatically

---

## 📊 MONITORING QUERIES

### Check Active Locks
```sql
SELECT 
  resource_key, 
  owner_id, 
  tab_id,
  acquired_at,
  expires_at,
  TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), expires_at) as expires_in
FROM simple_locks
WHERE expires_at > UTC_TIMESTAMP()
ORDER BY acquired_at DESC;
```

### Check Stuck/Expired Locks
```sql
SELECT 
  resource_key,
  owner_id,
  TIMESTAMPDIFF(SECOND, expires_at, UTC_TIMESTAMP()) as expired_ago_sec
FROM simple_locks
WHERE expires_at < UTC_TIMESTAMP();
```

### Cleanup Expired Locks (Manual)
```sql
DELETE FROM simple_locks WHERE expires_at < UTC_TIMESTAMP();
```

### Recent SSE Errors (Check Apache Logs)
```bash
tail -500 /home/master/applications/jcepnzzkmj/logs/apache_phpstack-129337-518184.cloudwaysapps.com.error.log \
  | grep -E "(lock_events|SSE)" \
  | tail -20
```

---

## 🔧 IMMEDIATE ACTIONS REQUIRED

1. **Monitor for 24 hours**:
   - Check Apache error logs hourly for parse errors
   - Check `simple_locks` table for stuck locks
   - Verify SSE connections are stable

2. **Add UI notification for SSE failure**:
   - When `_sseReconnectAttempts > 5`, show amber notification
   - Message: "⚠️ Real-time lock updates temporarily unavailable. Lock system still works, refresh page to check status."

3. **Add admin dashboard widget**:
   - Show all active locks with countdown timers
   - "Force Release" button for admin users only
   - SSE connection health indicator

4. **Document the parse error pattern**:
   - If `lock_events.php` was edited via FTP/SSH, ensure proper closing braces
   - Add pre-commit hook: `php -l lock_events.php` before deploying

---

## 🎯 LONG-TERM IMPROVEMENTS

### 1. Lock Request Approval Flow
Currently, different-owner requests just wait 60s and auto-acquire. Implement:
- Modal notification for current lock holder: "User X is requesting access"
- "Grant" button → immediate handoff
- "Deny" button → requester sees "Request denied" message
- Timeout after 60s → auto-grant if no response

### 2. Lock Holder Name Resolution
Currently shows "Another User Has Control". Enhance:
- Join with `vend_staff` table to show real name
- Display: "🔒 John Smith is currently editing this transfer"

### 3. WebSocket Migration (Optional)
If SSE continues to have issues, consider WebSocket:
- Bidirectional communication (no need for separate POST requests)
- Better connection recovery
- Lower latency
- Libraries: Socket.IO, Ratchet (PHP), or custom implementation

### 4. Lock Activity Log
Track all lock operations for debugging:
```sql
CREATE TABLE simple_lock_audit (
  id INT AUTO_INCREMENT PRIMARY KEY,
  resource_key VARCHAR(191),
  action VARCHAR(20), -- 'acquire', 'steal', 'release', 'expire'
  owner_id VARCHAR(64),
  tab_id VARCHAR(64),
  timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_resource (resource_key),
  INDEX idx_timestamp (timestamp)
);
```

---

## 📞 ESCALATION PATH

**If lock system fails again**:
1. Check Apache error logs for parse errors
2. Run: `php -l /path/to/lock_events.php`
3. Check for stuck locks: `SELECT * FROM simple_locks;`
4. Manual cleanup: `DELETE FROM simple_locks WHERE expires_at < UTC_TIMESTAMP();`
5. Restart PHP-FPM if SSE completely broken: `sudo systemctl restart php8.1-fpm`
6. Worst case: Disable SSE temporarily by commenting out `startSSE()` call in simple-lock.js

---

## ✅ RESOLUTION

**Issue**: Fixed manually by correcting syntax error in `lock_events.php`  
**Prevention**: Monitor logs for parse errors, add syntax check before deployment  
**Status**: **OPERATIONAL** — Two-bar system (RED/PURPLE) implemented and ready for testing

**Next Steps**:
1. Clear any remaining stuck locks: `DELETE FROM simple_locks WHERE resource_key='transfer:13217' AND expires_at < UTC_TIMESTAMP();`
2. Test both RED bar (same owner) and PURPLE bar (different owner) scenarios
3. Monitor SSE stability over next 24 hours
4. If old tab still exists, close it to release zombie lock

---

**Last Updated**: October 2, 2025 21:36 UTC  
**Verified By**: AI Assistant (GitHub Copilot)
