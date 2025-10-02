# Transfers Pack Module – Developer Onboarding

Last Updated: 2025-09-29

## Purpose
Packing workflow for stock transfers: count items, monitor discrepancies, apply labels & tracking, and finalize for dispatch. Recently refactored from a monolith to modular, event‑driven assets with unified toast notifications and autosave reliability improvements.

## High-Level Architecture
| Layer | Responsibility |
|-------|---------------|
| PHP View (`views/pack.view.php`) | Layout, data bootstrapping, asset version injection, initial draft detection toast |
| Components (`views/components/*.php`) | Header, tables, (hidden) pack & ship console |
| JS Core (`assets/js/pack-core.js`) | Event bus (`PackBus`), print chrome guard, pre‑toast queue shim, sheen animation |
| Toasts (`assets/js/pack-toast.js`) | Unified notification system with dedupe, actions, ESC dismiss |
| Metrics (`assets/js/pack-metrics.js`) | Row diff colouring, totals aggregation, emits `counts:updated` |
| Autosave (`assets/js/pack-autosave.js`) | Draft persistence with backoff, hash skip, toasts, events |
| Product Modal (`assets/js/pack-product-modal.js`) | Search & add products (idempotent bulk API) |
| Tracking Panel (`assets/js/pack-tracking.js`) | Manual / internal tracking numbers, emits `tracking:added` |
| Bulk Add UI (`add_products_bulk.php`) | Stand‑alone multi‑transfer product adder |
| APIs (`stock/api/*.php`) | Draft save, list transfers, search products, bulk add products |
| SQL (`stock/sql/*.sql`) | Draft data columns, shipping tables, tracking events |
| Asset Version (`_shared/asset_version.php`) | Central version & dev auto‑busting |

## Event Bus (`PackBus`)
Simple pub/sub implemented in `pack-core.js`.
```
PackBus.on(eventName, handler)
PackBus.off(eventName, handler)
PackBus.emit(eventName, payload)
```
### Core Events
| Event | Payload | Description |
|-------|---------|-------------|
| `toast:ready` | `{ show()... }` | Toast system initialized; pre‑queue drained |
| `product:added` | `{ product_id, qty }` | Single product added via modal |
| `tracking:added` | `{ tracking, carrier, notes, carrier_id? }` | Manual tracking saved |
| `packautosave:state` | `{ state:'saving'|'saved'|'error'|'noop', payload? }` | Autosave lifecycle |
| `counts:updated` | `{ planned, counted, diff }` | Totals recalculated (after edits/mutations) |

## Toast System (`PackToast`)
Methods: `show(message, type='info', opts)`, and shortcuts `info|success|warn|error`.
Options:
```
{ timeout?: ms, sticky?: bool, action?: {label, onClick}, force?: bool }
```
Features: max 5 visible, deduplicates same msg+type within 3s (unless `force`), hover pause, ESC dismiss (latest), accessible region.

## Autosave (`pack-autosave.js`)
Behavior:
1. Debounced (1s) save after quantity edits.
2. Skips server call if payload hash unchanged (`_lastHash`).
3. Exponential backoff on failures (1s→2s→4s→8s→15s cap) with toast retry action.
4. Emits state events and success/failure toasts (deduped).
5. Provides minimal monkey‑patch wrapper for metrics pill & external listeners.

## Asset Versioning
`_shared/asset_version.php` exposes `transfer_asset_version()` returning a base version (manual bump) plus dev auto‑suffix when `APP_ENV=dev` or `?devassets=1`.

## Draft Data
Column: `transfers.draft_data` JSON, updated by `draft_save_api.php` with structure:
```
{
  counted_qty: { productId:int },
  added_products: [],
  removed_items: [],
  courier_settings: [],
  notes: string,
  saved_by: userId,
  saved_at: timestamp
}
```

## APIs (Selected)
| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/stock/api/draft_save_api.php` | POST JSON | Save draft data (lock validated) |
| `/stock/api/list_transfers.php` | GET | Paginated draft transfers for outlet |
| `/stock/api/search_products.php` | GET | Product search (optionally outlet stock) |
| `/stock/api/add_products_to_transfers.php` | POST JSON (idempotent) | Bulk add products |

## Adding New Module Logic
1. Create new JS file in `assets/js/`.
2. Add `<script ... defer>` in `pack.view.php` after `pack-core.js` (and before feature modules if it augments them).
3. Use `PackBus.emit()` for cross‑cutting signals instead of directly mutating other module state.
4. For user feedback, call `PackToast.success()` etc.; avoid inline alert boxes.

## Removing Deprecated Shim
`pack-extra.js` was retained briefly as a no‑op for cache safety. It can now be deleted after confirming no legacy includes reference it. (Planned removal window: post‑deployment + CDN TTL.)

## Performance Targets
| Area | Target |
|------|--------|
| Initial DOM ready (pack view) | < 2.5s LCP |
| Autosave POST p95 | < 350ms (in‑region) |
| Product search response | < 700ms |
| Toast injection | < 5ms per call |

## Security & Hardening Notes
* All API endpoints check session auth; improve by centralising an AccessPolicy.
* Idempotency on bulk add prevents duplicate product lines.
* Draft save: consider size limit (JSON length) to protect table row. Future: archive old draft snapshots.
* Input sanitisation: client restricts tracking code length; server should validate (TODO: add explicit pattern).

## Future Backlog (High Value)
| Item | Rationale |
|------|-----------|
| AccessPolicy integration | Enforce outlet scoping consistently |
| Unified logger (client → server) | Observability across modules |
| Offline queue for autosave | Seamless edits during brief network drops |
| Convert PackBus to typed channel map | Reduce event name drift |
| Unit tests for API endpoints | Regression safety |

## Quick Start (Dev)
1. Set `APP_ENV=dev` to enable rolling asset version.
2. Hit pack view; confirm toast "Loaded prior draft data" appears if draft JSON exists.
3. Adjust quantities; observe autosave success toast and `counts:updated` event in console (enable `PackBus.on('*',...)` if adding wildcard debug helper).

## Contact / Ownership
Primary maintainer: CIS Engineering Team.

---
This document should be updated with each structural or cross‑module change.
