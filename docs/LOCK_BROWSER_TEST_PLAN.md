# Lock System Browser Test Plan
Date: 2025-10-03

Purpose: Provide a structured, reproducible manual browser validation of the transfer locking lifecycle across tabs, users, devices, network states, and failure modes.

> NEW: For quick backend contract verification before manual UI testing, run the CLI harness:
> ```
> php modules/transfers/stock/tools/lock_smoke_test.php --resource="transfer:TEST_LOCK" --userA=1001 --userB=2002 --cycles=1
> ```
> Add `--json` for machine-readable output.

## Pre-Flight
1. Ensure environment endpoints live:
   - /modules/transfers/stock/api/simple_lock.php
   - /modules/transfers/stock/api/lock_events.php
2. Clear existing locks for target transfer (or wait TTL) before starting.
3. Confirm console is clear on both tabs.
4. Optional: Load diagnostic probe (captures/prints events):
```
var s=document.createElement('script');
s.src='/modules/transfers/stock/assets/js/lock-diagnostic-probe.js?'+Date.now();
document.head.appendChild(s);
```
After loading, `window.__LOCK_EVENTS` accumulates structured events.

## Legend
- PASS: Observed behavior equals Expected.
- FAIL: Divergence (capture screenshot + console log, annotate). 
- NOTE: Non-blocking anomaly.

## Core State Change Events (Expected)
- acquired / alive
- blocked (fields: same_owner, same_tab)
- lost (reasons: broadcast|sse|refresh_status|visibility_check|focus_check|refresh_status_not_locked)
- released
- error

## Test Matrix (Condensed)
| ID | Scenario | Steps | Expected |
|----|----------|-------|----------|
| T1 | Baseline acquire | Open Tab A transfer page | Badge LOCKED (mine), mode owning | 
| T2 | Spectator second tab | Open Tab B same transfer | Bar visible, disabled buttons, blocked event | 
| T3 | Same-owner steal | In Tab B click Take Control | Tab B acquired; Tab A lost event | 
| T4 | Release path | In Tab B press Release | Tab B spectator; Tab A Request Lock available | 
| T5 | Re-acquire original | In Tab A click Request Lock | Tab A acquired | 
| T6 | Close owning tab | Close Tab A | Tab B on refresh/request acquires / poll does within 20s | 
| T7 | Forced SSE drop | In Tab (owning) DevTools > Network offline for event stream only | Reconnect attempts (<=5) then refresh loop keeps alive | 
| T8 | Offline owning | Toggle system offline (or dispatch offline event) | alive event with info.offline true, no JS errors | 
| T9 | Resume online | Restore connectivity | refresh -> alive or blocked real state | 
| T10 | Hidden > TTL | With lock, hide tab long enough TTL, show | lost (visibility_check) -> spectator | 
| T11 | Cross-user block | User B (different login) opens page while User A owns | blocked with same_owner=false | 
| T12 | Manual refresh while owning | Run lockInstance.refresh() | alive/acquired no flicker | 
| T13 | Manual refresh spectator | Run refresh() | blocked or acquired (if free) | 
| T14 | Rapid double click Request Lock | Double click | Single acquire attempt (no duplicate logs) | 
| T15 | Acquire + immediate steal other user (if allowed) | User B attempt steal | Policy: blocked again (no unauthorized steal) | 
| T16 | Crash simulation | Kill owning tab process (close without release) | Spectators blocked until TTL expiry then can acquire | 
| T17 | Reconnect button | Force error (simulate endpoint 500) then click Reconnect | refresh invoked, state corrected | 
| T18 | Spectator poll opportunistic | Owner releases silently (no interaction) | Spectator acquires within spectatorPollMs (20s) or on manual request | 
| T19 | Dirty autosave unaffected by spectator state | Edit counts while owning then lose lock | Inputs disable; no autosave pending attempts afterward | 
| T20 | Acquire after offline gap | Go offline (TTL > gap?) then online | If TTL passed -> lost → new acquire; else alive | 

## Detailed Procedure Examples
### T3 Same-owner Steal
1. Tab A (owner) open.
2. Tab B open (spectator same_owner true). 
3. Click Take Control.
4. Observe: Tab B: acquired event; Tab A: lost (reason broadcast or sse). PASS if no residual enabled controls on Tab A.

### T7 Forced SSE Drop
1. Acquire lock in Tab A.
2. In DevTools > Network conditions throttle/disconnect event stream (or manually call `lockInstance.eventSource.close()`).
3. Expect: onerror -> reconnect attempts; if fail >5, falls back to periodic refresh; state remains owning.

## Probe Inspection Commands
```
// Recent events
window.__LOCK_EVENTS.slice(-5)
// Count event types
__LOCK_EVENTS.reduce((m,e)=>{m[e.state]=(m[e.state]||0)+1;return m;}, {})
```

## PASS/FAIL Recording Template
Duplicate this block per test in internal QA sheet:
```
Test ID: T#
Result: PASS/FAIL/NOTE
Timestamp:
Observed Events: [...]
Console Warnings: [...]
Comments:
```

## Known Non-Critical Warnings
- BroadcastChannel unsupported (older browsers) – acceptable fallback.
- SSE early close with reconnect attempt – acceptable unless loops infinitely.

## Exit Criteria
All T1–T20 PASS (or documented acceptable NOTES) with screenshots for any NOTES. Any FAIL requires: issue ticket + root cause + patch + retest.

## Optional Automation (Future)
- Playwright dual context script to programmatically run T1–T6.
- Synthetic offline simulation via CDP setOfflineMode.
- Performance budget capture (acquire latency < 500ms p95).

---
Maintainer: Auto-generated test plan.
