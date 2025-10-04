<!--
  Transfers Module Architecture Guide
  Consolidated from:
    - MODULAR_REFACTOR_SUMMARY.md
    - ASSET_LOADING.md
    - transfers_stock_function_catalog.md
    - transfers_stock_memory_notes.md
    - LOCK_SYSTEM_* (only high-level reference; lock specifics live in LOCK_SYSTEM_GUIDE.md)
  Last Consolidation: 2025-10-02
-->

# 📦 Transfers Module Architecture & Component System

> Unified reference for structure, services, runtime flow, modular UI composition, auto asset loading, and pipeline overlays inside `/modules/transfers`.

## 1. High-Level Layering
| Layer | Path | Responsibility |
|-------|------|----------------|
| Views / Templates | `stock/*.php`, `stock/views/` | Render HTML using component includes + config arrays |
| UI Assets | `assets/css`, `assets/js` | Presentational styles & progressive enhancement scripts |
| API Endpoints | `stock/api/*.php` | Stateless request handlers (JSON envelopes) |
| Services | `stock/services/*.php` | Domain orchestration (pack, receive, freight, notes, tracking) |
| Shared Core | `_shared/Core`, `_shared/Services` | Cross-module primitives (orchestrator, persistence, idempotency, audit) |
| Support Helpers | `_shared/Support`, `stock/_shared/Util` | Low-level helpers (time, json, uuid, http) |
| Data / Persistence | MySQL tables | Canonical state (transfers, items, shipments, parcels, locks, audit) |

## 2. Runtime Pack → Send Flow (Modern Orchestrator)
```
pack.view.php (user action)
  POST /stock/api/pack_save.php (optional interim save)
  POST /stock/api/pack_send.php
    └─ pack_send_orchestrator() → OrchestratorImpl::handle()
         1. Validate (RequestValidatorImpl)
         2. Guardian tier (GuardianService)
         3. Plan parcels (ParcelPlannerDb / FreightCalculator)
         4. Persist (PersistenceStock / commit + audit)
         5. Vend mirror (VendServiceImpl stub ↗ future real API)
         6. Response envelope (Responder / Http helpers)
```

Pipeline Overlay (PulseLock / sync rail) surfaces step transitions & failure states with correlation IDs:
- `X-Pipeline-Ref`
- `X-Request-ID`
- `Idempotency-Key`

## 3. Modular View Composition
Refactor reduced monolith >1,100 lines into discrete components (SRP & reuse). Each component:
1. Receives config array (`$component_config` naming convention).
2. Contains *no* heavy business logic (fetch done pre-include).
3. Provides semantic HTML + minimal data attributes.

Benefits: Easier maintenance, reuse across pack/receive, potential central design system foundation.

## 4. Auto Asset Loading System
Auto-discovers `assets/css/*.css` and `assets/js/*.js` → outputs deterministic ordered `<link>` + `<script defer>` tags with mtime version query (cache bust). Replace manual includes with:
```php
<?= load_transfer_css(); ?>
<?= load_transfer_js(); ?>
```
Exclusions supported via params. Keep JS/CSS file budgets <25KB (policy).

## 5. Environment & Boot Payload (Essential Keys)
`window.DISPATCH_BOOT` (server → client hydration):
| Key Group | Examples | Purpose |
|-----------|----------|---------|
| Transfer Meta | transferId, fromOutlet, toOutlet | Context for operations + API param baseline |
| Capabilities | capabilities.printPool.*, carriers.* | Conditional UI gating (printer offline, carrier tabs) |
| Tokens | apiKey, nzPost, gss | Adapter requests (conditionally forwarded) |
| Endpoints | rates, create_label, address_facts, notes_add | Stable contract surface for front-end calls |
| Metrics | total_weight_grams, line_count | Progress meters & heuristics |
| Autoplan | autoplan[] packages | Pre-computed parcel planning seed |
| Timeline | timeline[] | Hydrated historical events / notes |

## 6. Core Service Responsibilities (Abbreviated)
| Service | Role | Notes |
|---------|------|-------|
| TransfersService | Hydration & pack persistence | Delta logic for shipments |
| ReceiptService | Receive reconciliation | Validates counts, writes receive log |
| FreightCalculator | Weight distribution & carrier heuristics | Drives planner & UI metrics |
| OrchestratorImpl | Pack/send high-level flow | Idempotent by key & payload hash |
| IdempotencyStoreDb | Replay protection | SHA-256 payload hash storage |
| PersistenceStock | DB atomic write set | Shipments, parcels, carrier orders, audit |
| VendServiceImpl | Vend mirror stub | Replace with real API integration |
| BoxAllocationService | Intelligent box allocation | Works with engine & config tables |
| PackLock (simple-lock.js + endpoints) | Exclusive editing | See LOCK_SYSTEM_GUIDE.md |

## 7. Lock System (Pointer)
Detailed operational, diagnostic & roadmap content has moved to `LOCK_SYSTEM_GUIDE.md` – only summary retained here.

## 8. Packaging Heuristics (Auto-Plan)
Rules (summary): Prefer satchels when weight ≤12kg & items ≤20. Weight brackets map to preset IDs (`nzp_s`, `nzp_m`, `nzp_l`, `vs_m`, `vs_l`, `vs_xl`). Tare adjustments: satchel 0.15–0.25kg, boxes 2.0–3.1kg. Exposed in boot metrics for client hints.

## 9. Pipeline Overlay & Resilience
Overlay steps: validate → lock → commit → vend → print → done.
Failure halts animations, marks step `.err` with raw message (no auto-advance). `TEST_VEND_ONLY` short-circuits after vend (temporary). Red degrade persists in `sessionStorage.PL_LOCK` until cleared by green path run.

## 10. Observability & Diagnostics
| Aspect | Mechanism |
|--------|-----------|
| Request Correlation | `X-Request-ID`, `X-Pipeline-Ref` surfaced in UI |
| Idempotency | Header + server hash storage |
| Lock Events | SSE + Broadcast + diagnostic modal |
| Audit Trail | `transfer_audit_log` (before/after JSON) |
| Future | Add lock audit, vend consignment real IDs |

## 11. Security & Compliance Notes
 - All API scripts must include `app.php` (session, guards, env).
 - Disallow direct modification of core function directories w/o review.
 - Output escaping in views (HTML safe wrappers).
 - Rate limiting example: receive flow (60 req / 60s).
 - No secrets in repo; tokens from environment forwarded only when allowed.

## 12. Legacy Assets & Decommission Plan
| Legacy Item | Status | Action |
|-------------|--------|--------|
| `pack_ship_api.php` | Legacy pack/ship | Retain until orchestrator 100% adoption |
| Legacy lock tables/services | Partial references | Remove after 30-day stable simple_locks window |
| Corrupted gateway & pack-lock scripts | Replaced | Remove after backup (see audit) |

## 13. Roadmap (Selected)
| Area | Next Step | Value |
|------|----------|-------|
| Vend Integration | Replace stub upsert with real API & retries | Accurate external sync |
| Lock Approval | Add grant/deny flow | Controlled handoffs |
| Allocation Engine | Expand test coverage | Confidence & rollout |
| Guardian Tiers | Implement amber/red gating | Safer operations |
| Diagnostics | SSE health widget in admin | Faster incident triage |

## 14. Contribution Guidelines (Delta Highlights)
1. New services: constructor DI (PDO optional injection) + docblock + strict types.
2. Keep JS modular (one responsibility per file, <25KB, versioned).
3. Extend component library instead of inline HTML expansions.
4. Update this guide & CHANGELOG for structural or contract changes.
5. For lock changes, ALWAYS update `LOCK_SYSTEM_GUIDE.md` simultaneously.

## 15. Quick Reference Tables
### Key Tables
`transfers`, `transfer_items`, `transfer_shipments`, `transfer_parcels`, `transfer_carrier_orders`, `transfer_audit_log`, `simple_locks`, (legacy) `transfer_pack_locks*`.

### High-Frequency API Endpoints
`pack_save.php`, `pack_send.php`, `rates.php`, `create_label.php`, `notes_add.php`, `assign_tracking.php`, `simple_lock.php`, `lock_events.php`.

## 16. Change Log (Excerpt)
| Date | Change | Summary |
|------|--------|---------|
| 2025-10-02 | Consolidation | Created architecture guide (this file) |
| 2025-10-02 | Modular Refactor | Components extracted (see Section 3) |
| 2025-10-02 | Auto Asset Loader | Replaced manual tags |

---
Maintain accuracy: PRs altering layer boundaries, orchestration, or core service contracts must update this document.
