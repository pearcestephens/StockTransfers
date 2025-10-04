<!--
  Consolidated Lock System Guide
  Source Materials Merged:
    - LOCK_SYSTEM_DIAGNOSIS.md
    - LOCK_SYSTEM_AUDIT_OCT2_2025.md
    - DIAGNOSTIC_COG_FEATURE.md
    - DIAGNOSTIC_QUICK_REFERENCE.txt
    - transfers_stock_memory_notes.md (lock sections)
  Last Consolidation: 2025-10-02
  Owner: Transfers Module Maintainers
-->

# 🔒 Lock System Guide (Transfers Pack Console)

> Single‑resource exclusive locking for Transfer Pack UI with real‑time (<100ms intra‑browser / sub‑second cross‑browser) visibility, dual state bars (RED vs PURPLE), hardened SSE channel, diagnostics, and operational run‑book.

## 1. Purpose & Scope
Ensure ONLY 1 BROWSER / 1 TAB actively edits a transfer while providing: instant local updates, safe takeover, spectator clarity, and deep diagnostics for support.

## 2. Architecture Overview
```
Browser Tab (pack.view.php)
 ├─ simple-lock.js (core engine)
 ├─ lock-ui.js (state → UI adapter)
 ├─ lock-diagnostics.js (modal + event log)
 ├─ BroadcastChannel (same-browser instant sync)
 ├─ EventSource → api/lock_events.php (SSE push)
 └─ REST POSTs → api/simple_lock.php (status/acquire/steal/heartbeat/release)
              DB: simple_locks (ttl expiry)
```

## 3. Data Model (Current)
`simple_locks`
| Column | Purpose |
|--------|---------|
| resource_key (PK) | e.g. `transfer:13217` |
| owner_id          | Staff / user id (string) |
| tab_id            | Per-tab UUID (v4) |
| token             | 32 char hex session token (auth link) |
| acquired_at       | Acquisition timestamp |
| expires_at        | Absolute expiry (rolling window) |

TTL: 90s rolling; heartbeat every 30–60s extends `expires_at`.

## 4. Client State Machine (Simplified)
States: `checking` → (`owning` | `spectator`) → `requesting` (→ `owning` or timeout) → `lost` (if stolen) → `released`.

Events (emit): `status`, `acquired`, `alive`, `blocked`, `stolen`, `lost`, `released`, `error`, `sse_degraded`.

## 5. Dual Bar Semantics
| Color | Condition | Action Button | Behavior |
|-------|-----------|---------------|----------|
| RED   | Same owner (another tab / window) | "Take Control" | Instant steal (no countdown) |
| PURPLE| Different owner | "Request Lock" | 60s countdown; auto-acquire on expiry (future: approval flow) |

Spectator visual: body class `.pack-spectator` + fade wrapper.

## 6. Acquisition & Renewal Flow
1. Page load → `status()` (REST)
2. If unlocked → `acquire()` (REST) immed.
3. If locked → spectator UI; evaluate same-owner vs other.
4. Heartbeat timer (setInterval) → POST `heartbeat` (extends 90s TTL) while owning.
5. Page visibility lost > TTL with missed heartbeats → expiry → other tab / user may acquire.

## 7. Real-Time Layer
SSE: `lock_events.php`
 - Poll / diff push cadence: adaptive 250ms→2s (backoff)
 - Loop hardening:
   * Max runtime 5 min per connection
   * Max reconnect attempts: 5
   * Iteration cap: 300
   * `connection_aborted()` break
 - BroadcastChannel: zero‑latency updates between tabs of same browser (owner stolen / released).

Failure Handling: After >5 failed SSE reconnects emit `sse_degraded` → UI can display amber notice; lock still functional via REST.

## 8. Diagnostic Modal (Cog Button)
Sections:
1. Client State (transferId, userId, tabId, mode, token, alive)
2. SSE Connection (readyState, URL, since, duration, reconnectAttempts)
3. Server Lock Status (locked?, owner_id, tab_id, expires_in, isMine)
4. Last Blocked Info (same_owner, same_tab, locked_by, locked_tab, expires_in)
5. Recent Events (ring buffer: 50 max with timestamp + type + detail)
6. System Health (SSE, lock, page) + timestamp

Actions: Copy All (JSON snapshot), Refresh, Close.

Event Log Capture: All SimpleLock emits + user actions (request, steal, release) + SSE transitions.

## 9. Operational Monitoring
SQL – Active Locks:
```sql
SELECT resource_key, owner_id, tab_id,
       TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), expires_at) AS expires_in
FROM simple_locks WHERE expires_at > UTC_TIMESTAMP();
```

Expired Locks Cleanup (manual):
```sql
DELETE FROM simple_locks WHERE expires_at < UTC_TIMESTAMP();
```

Apache Log Scan (recent SSE issues):
```bash
tail -500 logs/apache_phpstack-129337-518184.cloudwaysapps.com.error.log | grep -E "(lock_events|SSE)"
```

## 10. Troubleshooting Guide
| Symptom | Likely Cause | Action |
|---------|-------------|--------|
| Endless "Acquiring" | SSE parse error / fatal loop | `php -l api/lock_events.php`; check logs; fix syntax; refresh |
| Purple bar never switches after expiry | Heartbeat still renewing | Confirm TTL; check server time skew; run active locks query |
| Immediate logout / session loss | Crash loop overwhelming PHP | Inspect error log for repeated parse errors |
| Button disabled unexpectedly | Lost lock event (stolen) | Diagnostics → Recent Events; reacquire if appropriate |
| No real-time updates | SSE degraded | Display amber notice; rely on manual refresh or page reload |

## 11. Legacy Cleanup & Migration
To Remove (post verification): `api/lock_gateway.php`, `assets/js/pack-lock.js`, `assets/js/pack-lock.gateway-adapter.js`, `api/lock_request_events.php`, legacy `transfer_pack_locks*` tables & services (`PackLockService`, `LockAuditService`).

Migration Steps:
1. Confirm no code paths call legacy endpoints (grep).
2. Add deprecation header to legacy service PHP files.
3. Export legacy audit tables (backup).
4. Drop legacy tables after 30-day observation window.

## 12. Security Considerations
 - Tokens never exposed outside current session context.
 - No PII surfaced in diagnostics (IDs only).
 - Write endpoints enforce token + owner_id + tab_id match via guard.
 - Steal permitted only for same owner (session continuity) otherwise timed request.

## 13. Performance Targets
| Metric | Target | Notes |
|--------|--------|-------|
| Intra‑tab propagation | <10ms | BroadcastChannel |
| Cross‑browser update | <750ms p95 | SSE push cadence |
| SSE reconnect storms | None (≤5 attempts) | Hard cap safeguards |
| Lock acquisition race | Single winner | DB PK enforcement |

## 14. Future Roadmap
| Item | Benefit | Notes |
|------|---------|-------|
| Approval-based handoff | Explicit granting | Modal prompt for current holder |
| Lock holder name resolution | Clarity for spectators | Join staff table at status fetch |
| DB audit table `simple_lock_audit` | Forensics & metrics | Acquire/steal/release/expire rows |
| SSE → WebSocket (optional) | Bi-directional + lower overhead | Evaluate only if scaling issues |
| Admin dashboard widget | Ops visibility | Force release (role-gated) |

## 15. Quick Reference Cheat Sheet
```
Acquire: POST simple_lock.php?action=acquire
Heartbeat: POST simple_lock.php?action=heartbeat (30–60s)
Release: POST simple_lock.php?action=release
Steal (same owner only): POST simple_lock.php?action=steal
SSE Stream: GET lock_events.php?resource_key=transfer:<id>&token=<token>&tab_id=<uuid>
States: owning | spectator | requesting | lost | released | error
UI Bars: RED = same owner; PURPLE = other user
Countdown: 60s (PURPLE only) → auto acquire
Diagnostics: Click cog → Copy/Refresh/Close
```

## 16. Change Log (Excerpt)
| Date | Change | Summary |
|------|--------|---------|
| 2025-10-02 | Consolidated docs | Created single guide (this file) |
| 2025-10-02 | SSE Hardening | Added runtime/reconnect/iteration caps |
| 2025-10-02 | Dual Bars | RED vs PURPLE semantics finalized |
| 2025-10-02 | Diagnostics Modal | Full feature shipped |

---
Maintainers: Update this guide whenever lock endpoints, SSE contract, or diagnostic data schema changes. Keep legacy file stubs pointing here.
