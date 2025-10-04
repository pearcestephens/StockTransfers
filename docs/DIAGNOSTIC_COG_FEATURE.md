# (Consolidated) Lock System Diagnostic Cog — Feature Documentation

> Modal details now summarized in `LOCK_SYSTEM_GUIDE.md` (§8). Keep this feature spec for historical depth; do not extend—add new enhancements to the consolidated guide roadmap.

**Date**: October 2, 2025  
**Feature**: Comprehensive lock system diagnostics accessible via header cog icon  
**Location**: `pack.view.php` header (top-right corner)

---

## 🎯 Purpose

Provide developers and administrators with **instant visibility** into the lock system's state, SSE connections, event history, and server-side status — all accessible via a single click on the diagnostic cog button in the page header.

---

## 🔍 What It Shows

### 1. **Client State** 
- Transfer ID
- User ID  
- Tab ID (unique per browser tab)
- Current mode (`owning`, `spectator`, `requesting`, `acquiring`, `checking`)
- Same Owner flag (RED BAR vs PURPLE BAR indicator)
- Lock Token (32-char hex or `none`)
- Lock Alive status (YES/NO with color coding)

### 2. **SSE Connection**
- Ready State (0=CONNECTING, 1=OPEN, 2=CLOSED)
- Connection URL (full endpoint with parameters)
- Connected At timestamp
- Connection Duration (seconds)
- Reconnect Attempts (0-5, warns if >3)

### 3. **Server Lock Status** (Live Fetch)
- Locked (YES/NO)
- Owner ID (who holds the lock)
- Tab ID (which tab holds the lock)
- Expires In (seconds remaining)
- Is My Lock? (validates client vs server match)

### 4. **Last Blocked Info**
- Same Owner (YES/NO)
- Same Tab (YES/NO)
- Locked By (user ID)
- Locked Tab (tab ID)
- Expires In (when blocked lock expires)

### 5. **Recent Events** (Last 20)
- Timestamp (local time)
- Event Type (`lock_event`, `sse_event`, `user_action`)
- Event Detail (JSON preview, truncated to 100 chars)
- Scrollable event log with monospace formatting

### 6. **System Health**
- SSE Connection: ✓ HEALTHY / ✗ UNHEALTHY
- Lock State: ✓ ACTIVE / ○ INACTIVE
- Page State: ✓ NORMAL / ⚠ ABNORMAL
- Timestamp (ISO 8601 format)

---

## 🎨 UI/UX

### Modal Design
- **Dark theme** (#1a1a2e background, purple accents)
- **Responsive** (90% width, max 900px, max-height 90vh)
- **Scrollable** content area
- **Gradient header** (purple gradient with stethoscope icon)
- **Color-coded values**:
  - 🟢 Green = Good/Healthy
  - 🟠 Orange = Warning
  - 🔴 Red = Error/Unhealthy

### Layout
- **Grid layout** for key-value pairs (180px labels, flexible values)
- **Monospace font** for technical values (IDs, tokens, timestamps)
- **Scrollable event log** (max 200px height)
- **Action buttons** at bottom:
  - 🔷 **Copy All** — Copies full diagnostic JSON to clipboard
  - 🔄 **Refresh** — Re-fetches all data (including server status)
  - ✖️ **Close** — Dismisses modal

### Interactions
- Click cog icon → Opens modal
- Click outside modal → Closes modal
- Click Close button → Closes modal
- Click Refresh → Updates all data
- Click Copy → Copies to clipboard with success/fail alert

---

## 🛠️ Technical Implementation

### Event Logging
```javascript
const diagEventLog = [];
const maxEventLog = 50;
function logDiagEvent(type, detail){
  diagEventLog.unshift({time:new Date().toISOString(), type, detail});
  if(diagEventLog.length>maxEventLog) diagEventLog.pop();
}
```

**Logged Events**:
- Every `onChange` callback from SimpleLock
- State: `acquired`, `alive`, `blocked`, `lost`, `released`, `error`
- Includes full `info` object and `error` details

### Live Server Fetch
```javascript
const resp = await fetch('/modules/transfers/stock/api/simple_lock.php?action=status&resource_key=transfer:'+transferId);
serverStatus = await resp.json();
```

**Fetches**:
- Current lock status from database
- Owner/tab information
- Expiry countdown
- Validates client state matches server

### Copy to Clipboard
```javascript
const data = { client: window._lastDiagData, server: window._lastServerStatus };
const text = JSON.stringify(data, null, 2);
navigator.clipboard.writeText(text);
```

**Clipboard Format**:
```json
{
  "client": {
    "timestamp": "2025-10-02T21:45:30.123Z",
    "page": {...},
    "lock": {...},
    "sse": {...},
    "state": {...},
    "eventLog": [...]
  },
  "server": {
    "ok": true,
    "locked": true,
    "owner_id": "1",
    "tab_id": "abc-123-def",
    "expires_in": 75
  }
}
```

---

## 📊 Use Cases

### 1. **Debugging SSE Connection Issues**
- Check `SSE Connection` section
- Verify Ready State = 1 (OPEN)
- Check Reconnect Attempts (should be 0 if stable)
- Look for `sse_event` entries in event log

### 2. **Diagnosing "Another Tab Has Control" Issues**
- Check `Server Lock Status` to see who actually holds the lock
- Compare with `Client State` to verify mismatch
- Look at `Last Blocked Info` for same_owner/same_tab flags
- Check `Recent Events` for unexpected `lock_stolen` events

### 3. **Tracking Lock Handoffs**
- Open diagnostic modal before requesting lock
- Click "Request Lock" or "Take Control"
- Click "Refresh" in diagnostic modal
- Watch event log populate with:
  - `lock_event: {state: 'acquired'}`
  - `lock_event: {state: 'blocked'}` (on other tabs)

### 4. **Performance Monitoring**
- Check SSE Connection Duration
- Verify connection doesn't exceed 5 minutes (auto-disconnect)
- Check Reconnect Attempts stay below 3
- Monitor event log size (max 50 events)

### 5. **Support Ticket Documentation**
- Click "Copy All" button
- Paste into support ticket or GitHub issue
- Provides complete diagnostic snapshot
- No manual console log copying needed

---

## 🔐 Security Considerations

### What's Exposed
- ✅ Transfer ID (already in URL)
- ✅ User ID (own ID only)
- ✅ Tab ID (random UUID, no PII)
- ✅ Lock Token (ephemeral, expires in 90s)
- ✅ SSE endpoint URL (internal API, not sensitive)

### What's NOT Exposed
- ❌ Other users' personal information
- ❌ Database credentials
- ❌ Session tokens or auth cookies
- ❌ Internal file paths or server IPs

### Access Control
- No authentication required (intentional for debugging)
- Only shows data for current user's session
- Server-side lock API already validates ownership
- No write operations available from diagnostic modal

---

## 🧪 Testing Checklist

### Test 1: Modal Opens/Closes
- [ ] Click cog icon → modal appears
- [ ] Click outside modal → modal closes
- [ ] Click Close button → modal closes
- [ ] Press ESC key → modal closes (if implemented)

### Test 2: Data Population
- [ ] Client State shows correct transfer/user IDs
- [ ] SSE Connection shows OPEN state when lock held
- [ ] Server Status matches client state
- [ ] Last Blocked Info appears when blocked
- [ ] Event log populates on lock events

### Test 3: Refresh Functionality
- [ ] Click Refresh → all data updates
- [ ] Server Status shows current lock holder
- [ ] Timestamp updates to current time
- [ ] Event log preserves entries (doesn't reset)

### Test 4: Copy to Clipboard
- [ ] Click Copy All → success alert appears
- [ ] Paste into text editor → valid JSON
- [ ] JSON contains both client and server sections
- [ ] All diagnostic data present in output

### Test 5: Event Logging
- [ ] Acquire lock → `acquired` event logged
- [ ] Open 2nd tab → `blocked` event logged
- [ ] Steal lock → `lost` event logged (Tab 1), `acquired` event logged (Tab 2)
- [ ] Close tab → `released` event logged
- [ ] SSE disconnect → `error` event logged

### Test 6: Color Coding
- [ ] Healthy SSE = green
- [ ] Disconnected SSE = red
- [ ] High reconnect attempts = orange
- [ ] Own lock = green "YES"
- [ ] Other's lock = orange "NO"

---

## 🐛 Known Limitations

1. **Event Log Reset on Page Refresh**
   - Event log stored in memory only
   - Resets to empty on page reload
   - Workaround: Copy diagnostics before refresh

2. **No Historical Lock Data**
   - Shows current state only
   - No database audit trail (future enhancement)
   - Cannot see who had lock 5 minutes ago

3. **No Real-Time Auto-Refresh**
   - Must click Refresh button manually
   - Server status not pushed via SSE
   - Workaround: Click refresh every few seconds

4. **Limited Event Detail Truncation**
   - Event details truncated to 100 chars
   - Full detail available in clipboard copy
   - Cannot expand individual events inline

5. **No Export to File**
   - Copy to clipboard only
   - No JSON file download button
   - Workaround: Paste into text editor and save

---

## 🚀 Future Enhancements

### Short-Term
- [ ] Auto-refresh every 5 seconds (toggle button)
- [ ] ESC key to close modal
- [ ] Expand/collapse individual event details
- [ ] Filter events by type (lock/sse/user)

### Medium-Term
- [ ] Export to JSON file (download button)
- [ ] Color-coded event timeline visualization
- [ ] Show lock holder's name (join with staff table)
- [ ] Add "Force Release" button for admins

### Long-Term
- [ ] Database audit log integration
- [ ] Historical lock timeline (last 24 hours)
- [ ] WebSocket connection health graph
- [ ] Performance metrics (avg SSE latency, reconnect rate)

---

## 📞 Support

**If diagnostics show unexpected values**:

1. **SSE Connection = CLOSED**
   - Check Apache error logs for `lock_events.php` errors
   - Run: `php -l /path/to/lock_events.php`
   - Look for parse errors or PHP warnings

2. **Server Status = Error**
   - Check MySQL connection
   - Verify `simple_locks` table exists
   - Test API manually: `/modules/transfers/stock/api/simple_lock.php?action=status&resource_key=transfer:13217`

3. **Client vs Server Mismatch**
   - Check for clock skew (UTC timestamps)
   - Verify tab_id consistency
   - Look for race conditions in event log

4. **No Events Logged**
   - Verify `logDiagEvent()` called in onChange
   - Check browser console for JS errors
   - Ensure lockInstance initialized

---

## ✅ Success Criteria

Diagnostic cog is successful when:

- ✅ Opens instantly (<100ms)
- ✅ Shows all 6 data sections populated
- ✅ Server status matches client state
- ✅ Event log contains recent lock operations
- ✅ Copy button produces valid JSON
- ✅ No console errors when modal opens
- ✅ Helps resolve 90% of lock-related support tickets without code inspection

---

**Last Updated**: October 2, 2025 21:50 UTC  
**Implemented By**: AI Assistant (GitHub Copilot)  
**Status**: ✅ **COMPLETE & READY FOR USE**
