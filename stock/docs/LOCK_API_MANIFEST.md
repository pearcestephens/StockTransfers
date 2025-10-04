# Lock API Manifest (Gateway Consolidation)

## Gateway
`api/lock_gateway.php?action=...`

### Supported Actions
| Action | Method | Params | Description |
|--------|--------|--------|-------------|
| status | GET | transfer_id | Current lock snapshot |
| acquire | POST | transfer_id, [fingerprint] | Acquire or extend lock |
| release | POST | transfer_id | Release if holder |
| heartbeat | POST | transfer_id | Extend expiry + heartbeat |
| force_release | POST | transfer_id | Forced unlock (bypass ownership) |
| request_start | POST | transfer_id, [fingerprint] | Queue takeover request |
| request_decide | POST | transfer_id, decision=accept|decline | Holder decision on latest pending request |
| request_state | GET | transfer_id | Latest request + holder summary |

### Response Envelope
```
{ "success": true|false, "data": { ... } | "error": { message, ... } }
```

## Legacy/Redundant Endpoints (To Deprecate)
| Old Endpoint | Replaced By (Gateway) |
|--------------|-----------------------|
| lock_status_mod.php | action=status |
| lock_acquire_mod.php | action=acquire |
| lock_release_mod.php | action=release |
| lock_heartbeat_mod.php | action=heartbeat |
| lock_force_release.php | action=force_release |
| lock_request_start.php | action=request_start |
| lock_request_decide.php | action=request_decide |
| lock_request_poll.php | action=request_state |
| lock_state_debug.php | action=status + action=request_state |
| lock_diag_simple.php | action=status |

SSE streaming (`lock_request_events.php`) remains separate (real-time). Consider future merge via `Accept: text/event-stream` content-negotiation.

## Migration Plan
1. Front-end switch endpoints to single gateway URL + `action` param.
2. Keep old files for one deploy cycle returning HTTP 410 with JSON hint.
3. Remove legacy files after confirming no calls in logs (grep or access log scan).

## Security Notes
- Add RBAC check for `force_release` (TODO: integrate AccessPolicy).
- Rate-limit acquire/heartbeat (TODO: middleware wrapper).

## TODO Backlog
- Content negotiation for SSE (gateway + `action=events&stream=1`).
- Dedicated migration SQL file for lock tables (remove DDL from runtime service).
- Add audit logging on each gateway action (acceptance / declines / force releases).

Generated: 2025-10-02
