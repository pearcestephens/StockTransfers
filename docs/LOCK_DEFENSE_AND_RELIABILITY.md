# Lock Defense & Reliability Matrix (2025-10-03)

This document enumerates the human usage situations and technical edge cases for the stock transfer locking system and maps them to concrete defenses now present in the codebase (simple-lock.js v2+, lock-ui.js hardened layer) plus any remaining improvement opportunities.

## Legend
- IMPLEMENTED: ✅ (defense in code)
- PARTIAL: ⚠ (mitigated but improvement available)
- TODO: ⏳ (not yet implemented)

## 1. Same-User / Same-Device
| Case | Description | Defense | Status |
|------|-------------|---------|--------|
| 1.1 | Single tab baseline | Normal acquire on start() | ✅ |
| 1.2 | Second tab spectator | status() + onChange blocked | ✅ |
| 1.3 | Instant steal same owner other tab | steal() path + UI instant attempt | ✅ |
| 1.4 | Actions after lost | setDisabled(true) spectator mode | ✅ |
| 1.5 | Rapid refresh spectator | refresh() rate limited (2s) | ✅ |
| 1.6 | Graceful close | beforeunload/pagehide release | ✅ |
| 1.7 | Tab crash (no unload) | TTL expiry fallback | ✅ |
| 1.8 | Explicit release | Release button + release() | ✅ |
| 1.9 | Idle alive | SSE + periodic refresh loop | ✅ |
| 1.10 | Hidden > TTL | visibility/focus verify triggers lost | ✅ |
| 1.11 | Rapid navigation back | start() status + acquire | ✅ |

## 2. Same-User / Multi-Browser
| Case | Description | Defense | Status |
|------|-------------|---------|--------|
| 2.1 | Chrome + Firefox | status() blocked | ✅ |
| 2.2 | Session divergence | server determines same_owner flag | ⚠ (back-end validation assumed) |
| 2.3 | Desktop + mobile | Same as multi-browser | ✅ |
| 2.4 | One offline | Offline event marks state (info.offline) | ✅ |
| 2.5 | Simultaneous acquire race | Server atomic acquire; loser blocked | ✅ |

## 3. Different Users
| Case | Defense | Status |
|------|---------|--------|
| 3.x | Cross-user blocked | status()/acquire responses | ✅ |
| 3.x | Cross-user steal prevented | Server policy (assumed) | ⚠ |
| 3.x | Name collision | Uppercasing only; optional future disambiguation | ⚠ |

## 4. Session/Auth
| Case | Defense | Status |
|------|---------|--------|
| Expired session | Acquire/heartbeat error → onChange error -> spectator | ⚠ (depends on backend error shape) |
| Logout other tab | Next call fails → error path | ⚠ |
| CSRF rotation | Not directly handled (server must permit) | ⏳ |

## 5. Device / Platform
| Case | Defense | Status |
|------|---------|--------|
| Mobile background > TTL | refresh / visibility triggers lost | ✅ |
| iOS purge | TTL fallback | ✅ |
| Sleep / wake | focus verify | ✅ |

## 6. Network
| Case | Defense | Status |
|------|---------|--------|
| SSE disconnect | Reconnect logic with attempt cap | ✅ |
| BroadcastChannel unsupported | SSE / polling fallback | ✅ |
| High latency | Non-blocking state machine | ✅ |
| Offline mode | offline/online listeners + refresh | ✅ |

## 7. Concurrency Races
| Case | Defense | Status |
|------|---------|--------|
| Double acquire spam | acquire() re-entrancy guard _acquiring | ✅ |
| Parallel refresh & acquire | Rate limit + acquiring flag | ✅ |
| Simultaneous steals | Server atomic; UI handles blocked | ✅ |

## 8. TTL / Tokens
| Case | Defense | Status |
|------|---------|--------|
| TTL expiry detection | Periodic refresh + SSE lock_stolen/lost | ✅ |
| Reuse stale token release | Server enforces; client send best-effort | ⚠ |
| Clock skew | Server-driven state; UI cosmetic only | ✅ |

## 9. UI Integrity
| Case | Defense | Status |
|------|---------|--------|
| Badge/UI mismatch | Single onChange funnel updates UI | ✅ |
| Countdown orphan | Interval cleared on transitions | ✅ |
| Offline visual state | OFFLINE indicator + reconnect btn | ✅ |

## 10. Security
| Case | Defense | Status |
|------|---------|--------|
| Forged ownerId | Backend must validate session vs ownerId | ⚠ |
| Replay token | Backend responsibility | ⚠ |
| Flood attempts | Client minimal; backend rate-limit recommended | ⚠ |

## 11. Observability
| Case | Defense | Status |
|------|---------|--------|
| Diagnostic logging | log('lock_event', ...) central | ✅ |
| Refresh spam | 2s rate limit | ✅ |
| Spectator drift | spectatorPoll() every 20s | ✅ |

## 12. Recovery
| Case | Defense | Status |
|------|---------|--------|
| Request timeout | Countdown finalize to spectator | ✅ |
| SSE hard failure | refresh() + polling loops | ✅ |
| Lost + fast reacquire | startLoops + acquire path | ✅ |

## Additional Hardenings Added (2025-10-03)
- Periodic verification (verifyEveryMs) when owning lock.
- Spectator polling (spectatorPollMs) for opportunistic lock acquisition.
- Offline/online event handling with UI indicator and auto refresh.
- Re-entrancy guard for acquire() to prevent overlapping calls.
- Manual refresh rate limiting (2 seconds).
- Reconnect button in UI when lost/error states occur.
- Offline annotated onChange payload (info.offline) for analytics.

## Opportunities / TODO
| Area | Recommendation |
|------|---------------|
| Backend enforcement | Ensure ownerId/session binding & cross-user steal rules documented. |
| CSRF rotation | Provide silent refresh or token-less internal endpoint. |
| Structured errors | Standardize error envelope fields for lock endpoints. |
| Audit trail | Persist lock acquisition/steal/release events to server audit log. |
| Name collision UX | Append short user ID hash in badge tooltip. |
| Rate limiting server | Add server-side rate limit (e.g., 10 acquire attempts / 10s per user/resource). |
| Backoff on acquire spam | Client exponential backoff after repeated blocked states. |
| Telemetry | Emit metrics: acquisition_latency_ms, steal_count, lost_due_to_offline_count. |
| Graceful degrade no SSE & no Broadcast | Increase spectatorPoll frequency moderately (e.g., 10s). |

## Testing Matrix (Abbreviated)
| Test | Steps | Expected |
|------|-------|---------|
| T1 Acquire baseline | Open page | state=acquired -> alive |
| T2 Second tab spectator | Open second tab | blocked same_owner flag |
| T3 Steal same owner | Click Take Control | first tab lost |
| T4 Offline owning | Disconnect network | alive (offline:true) no actions fail immediately |
| T5 Online resume | Reconnect network | refresh -> alive or blocked current reality |
| T6 SSE disconnect | Kill SSE (dev tools) | reconnect attempt or periodic refresh keeps state |
| T7 TTL expiry hidden | Hide > TTL | lost on visibility return |
| T8 Spectator opportunistic acquire | Hold lock tab closes | spectator poll or Request lock obtains ownership |

## Integration Notes
- UI reconnect button triggers lockInstance.refresh() which is rate-limited to avoid hammering.
- spectatorPoll() is intentionally passive—will not spam acquire when blocked by another user; it only re-attempts when free.
- All loops cleared only on page lifecycle end; no memory leak risk due to page context disposal.

---
Generated automatically as part of reliability hardening pass (2025-10-03).
