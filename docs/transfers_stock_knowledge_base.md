# Stock Transfers Module Knowledge Base

> Comprehensive architectural reference for `/modules/transfers` with emphasis on stock movements, pack & send orchestration, and supporting services.

## Table of Contents
1. [Executive Summary](#executive-summary)
2. [Architecture Overview](#architecture-overview)
3. [Directory & Namespace Map](#directory--namespace-map)
4. [Primary Runtime Flows](#primary-runtime-flows)
   - [Pack Console (UI) Flow](#pack-console-ui-flow)
   - [Draft Save (`pack_save.php`)](#draft-save-pack_savephp)
   - [Pack & Send Orchestrator (`pack_send.php`)](#pack--send-orchestrator-pack_sendphp)
   - [Receive Flow (`stock/receive.php`)](#receive-flow-stockreceivephp)
   - [Lock Lifecycle](#lock-lifecycle)
5. [Service Catalogue](#service-catalogue)
6. [API Reference (`stock/api/`)](#api-reference-stockapi)
7. [Shared Infrastructure (`_shared/`)](#shared-infrastructure-_shared)
8. [Stock Shared Stack (`stock/_shared/`)](#stock-shared-stack-stock_shared)
9. [Front-end Assets & Boot Payload](#front-end-assets--boot-payload)
10. [Database Touchpoints & Migrations](#database-touchpoints--migrations)
11. [Security & Compliance Guards](#security--compliance-guards)
12. [Configuration & Environment](#configuration--environment)
13. [Observability & Logging](#observability--logging)
14. [Operational Checklists](#operational-checklists)
15. [Future Enhancements](#future-enhancements)

---

## Executive Summary
- **Purpose:** Manage inter-store stock transfers from packing through dispatch and receipt within CIS.
- **Key Capabilities:**
  - Rich Pack Console UI (`stock/pack.php`) with product search, parcel planning, manual courier workflow, and contextual diagnostics.
  - Distributed locking and audit trail to prevent concurrent edits on the same transfer.
  - REST-style APIs for freight data, lock coordination, label creation, tracking, and notes.
  - Pack & Send orchestrator (idempotent API) bridging warehouse actions, persistence, and Vend/Lightspeed sync.
  - Receiving console (`stock/receive.php`) to reconcile incoming stock.
- **Technology Stack:** PHP 8.x with strict typing, PDO/`mysqli` DB access, Bootstrap-based HTML, vanilla JS with modular assets, PSR-4 autoloading.
- **Design Goals:** Security first (session guards, CSRF, rate limits), auditability, modular services, predictable DTO contracts, and minimal coupling to external carriers.

## Architecture Overview
```
Browser (Pack UI)
      │
      ▼
stock/pack.php ──┬─► stock/api/*.php (AJAX)
                 │
                 └─► Pack JS assets (dispatch.js, transfers-pack.js, ship-ui.js)
                       │
                       ▼
                Services (Modules\Transfers\Stock\Services\*)
                       │
                       ▼
                Database (transfers, shipments, locks, vend_*, freight_*)

Pack & Send API (stock/api/pack_send.php)
      │
      ▼
  stock/_shared (OrchestratorImpl + handlers + persistence)
      │
      ▼
 Vend/Lightspeed integrations + DB writes
```

- **Vertical Layers:**
  1. Entry points (UI + APIs) enforcing guards.
  2. Domain services performing business logic and data persistence.
  3. Shared core/contract layers providing DTOs, validation, and cross-module utilities.
  4. External integrations (Vend, courier label engines) via dedicated service facades.

## Directory & Namespace Map
| Path | Namespace | Description |
| --- | --- | --- |
| `_shared/` | `CIS\Shared\…` | Legacy shared core: DTOs, contracts, persistence helpers, validation, guardian stub, vend integration. |
| `stock/` | `Modules\Transfers\Stock\…` | Stock transfer module: UIs, APIs, services, views, SQL, assets, tooling. |
| `stock/_shared/` | `Modules\Transfers\Stock\Shared\…` | Modern Pack & Send orchestrator stack (request validator, handlers, persistence, audit). |
| `stock/api/` | n/a | HTTP endpoints consumed by pack/receive consoles and external tooling. |
| `stock/services/` | `Modules\Transfers\Stock\Services` | Business services (transfer CRUD, freight calc, locking, shipments, notes, tracking). |
| `stock/views/` | n/a | PHP partials for pack/receive UIs. |
| `stock/sql/` | n/a | Schema migrations for transfers, shipments, tracking, locking. |
| `assets/` | n/a | Module-specific CSS/JS (served via `/modules/transfers/stock/assets/`). |
| `output.php` | n/a | Secure directory snapshot API for diagnostics.

## Primary Runtime Flows

### Pack Console (UI) Flow
1. `stock/pack.php` boots:
   - Sets timezone, requires `/app.php`, registers scoped autoloader for `Modules\`.
   - Includes shared guards (`config.php`, `JsonGuard`, `ApiResponder`, `HttpGuard`) and `lib/AccessPolicy.php`.
   - Validates active session (`$_SESSION['userID']`), fetches transfer id from query string, and checks access via `AccessPolicy::canAccessTransfer()`.
2. `TransfersService::getTransfer()` loads transfer header, outlet metadata, and `transfer_items` (with Vend product join) while normalising outlet IDs and names.
3. Additional enrichment:
   - `getSourceStockLevels()` queries `vend_inventory` for source outlet quantities.
   - `FreightCalculator::getWeightedItems()` produces per-line grams → aggregated `freightMetrics`.
   - `tfx_build_dispatch_autoplan()` converts totals into satchel/box parcel presets & heuristics.
   - `NotesService::listTransferNotes()` + `StaffNameResolver::name()` hydrate timeline entries.
4. `window.DISPATCH_BOOT` is emitted with transfer meta, capabilities, endpoints, tokens, metrics, optional auto plan, source stock map, timeline, and user info.
5. HTML renders:
   - Breadcrumbs, packaged-warning banner, product search card, editable transfer table, Pack & Ship (`psx-app`) console, manual courier summary, options/rates, and footer assets.
6. JS assets (`dispatch.js`, `transfers-pack.js`, `ship-ui.js`, `pack-draft-status.js`, `pack-product-search.js`) consume `DISPATCH_BOOT`, bind AJAX endpoints, maintain lock heartbeat, and manage UI state.

### Draft Save (`pack_save.php`)
1. Auth guard ensures `$_SESSION['userID']` and obtains JSON payload.
2. Verifies exclusive lock via `PackLockService::getLock()`; rejects if absent or held by another user.
3. Normalises payload to array with items, packages, carrier, notes.
4. `TransfersService::savePack()` workflow:
   - Calculates delta between posted `qty_sent_total` and persisted values.
   - Updates `transfer_items.qty_sent_total` (bounded by requested qty) inside DB transaction.
   - Sets `transfers.status='sent'` and `state='PACKAGED'`.
   - Writes `transfer_audit_log` entry (`PACK_SAVE`) and optionally `NotesService::addTransferNote()`.
   - Creates shipment and parcel rows via `ShipmentService::createShipmentWithParcelsAndItems()` if delta > 0, attaches tracking numbers through `TrackingService`.
   - Emits structured response (`success` flag, shipment data, warnings). Catches and reports exceptions.
5. `LockAuditService` logs save event; client keeps lock active.

### Pack & Send Orchestrator (`pack_send.php`)
1. POST-only endpoint: loads `/app.php`, bootstraps `stock/_shared/Bootstrap.php`, initialises session.
2. Parses JSON payload + idempotency key headers + request ID (falls back to UUID v4).
3. `PackSendRequest::fromHttp()` (in shared stack) builds DTO, capturing raw body hash.
4. `OrchestratorImpl::handle()` stages:
   - Validate via `RequestValidatorImpl`; compute guardian tier via `GuardianService` (TrafficGuardian hook).
   - Replay idempotent responses when hash matches existing entry in `IdempotencyStoreDb`; reject mismatched replays.
   - Resolve handler for `mode` (manual courier, pickup, internal, depot drop, receive-only).
   - Handler constructs `HandlerPlan` (parcel breakdown, delivery mode, warnings) and ensures meta is ready.
   - `PersistenceStock::commit()` writes shipment + parcel records, updates transfer state, records audit.
   - `VendServiceImpl::upsertManualConsignment()` pushes data back to Vend (warnings captured).
   - Persists idempotent response and writes audit log (`AuditLoggerDb`).
   - Returns envelope `{ ok, data, warnings, meta }` with guardian tier & handler lane metadata.
5. Errors convert to structured envelopes with guardian tier + warnings.

### Receive Flow (`stock/receive.php`)
1. Mirrors pack bootstrap (autoload, guards, `AccessPolicy`).
2. POST: enforces same-origin, rate limit (`HttpGuard::rateLimit`), optional CSRF & idempotency via `JsonGuard` helpers.
3. Validates user access to transfer, then delegates to `ReceiptService::saveReceive()` for persistence.
4. GET: loads transfer context and includes `views/receive.view.php` alongside shared CSS.

### Lock Lifecycle
1. `lock_acquire.php` calls `PackLockService::acquire()`; existing lock conflict returns holder meta (augmented with `StaffNameResolver`).
2. Front-end polls `lock_heartbeat.php` while editing; server extends `expires_at`.
3. Unlock via `lock_release.php` or automatic cleanup of stale entries (expired `expires_at` or missing heartbeat).
4. `lock_request.php` allows queueing takeover; holder reviews pending requests (`lock_requests_pending.php`) and responds via `lock_request_respond.php`.
5. `LockAuditService` records every acquire/release/request/expire/respond event to maintain accountability.

## Service Catalogue

### Transfers & Shipments
| Service | Core Responsibilities |
| --- | --- |
| `TransfersService` | Hydrates transfer header + items, outlet metadata, inventory levels; saves pack state and seeds shipments/tracking. |
| `ShipmentService` | Creates shipment headers, parcels, and parcel_items for deltas; interacts with `TransferLogger`. |
| `TransferLogger` | Structured logging for major lifecycle events (`PACKED`, `EXCEPTION`). |
| `ReceiptService` | Applies receipt quantities, updates transfer/parcel statuses, handles auditing. |

### Freight & Parcels
| Service | Purpose |
| --- | --- |
| `FreightCalculator` | Gathers weighted line items, freight rules, carrier detection, parcel planning heuristics, container selection. |
| `BoxAllocationService` | Suggests carton allocation based on product mix (consumed by API endpoints). |
| `ParcelService` | Utility operations for parcel CRUD and measurement handling. |

### Locks & Coordination
| Service | Purpose |
| --- | --- |
| `PackLockService` | Manages `transfer_pack_locks` (acquire, heartbeat, release, request, respond, cleanup). Uses `mysqli` for compatibility with existing lock schema. |
| `LockAuditService` | Writes audit rows (lock acquire/release/request/expire). |

### Notes & Tracking
| Service | Purpose |
| --- | --- |
| `NotesService` | CRUD for `transfer_notes`; ensures timeline entries persist with metadata. |
| `TrackingService` | Set and fetch tracking references for parcels. |
| `ProductSearchService` | Executes product lookup for pack UI (name, SKU, handle). |
| `StaffNameResolver` | Resolves staff IDs to readable names for UI and audit trails. |

### Labels & Execution
| Service | Purpose |
| --- | --- |
| `LabelsService` / `ShippingLabelsService` | Handles label generation, voiding, printing integration. |
| `ExecutionService` | Encapsulates remote executions (e.g., contacting external label providers) with retries/logging. |

### Shared (`CIS\Shared\Services` etc.)
| Service | Purpose |
| --- | --- |
| `PersistenceService` | Transactional shipment writes for manual flows (outside orchestrator). |
| `VendService` | Abstraction for Vend/Lightspeed interactions. |
| `GuardianService` (shared) | Reports guardian tier (currently `green`). |
| `AuditLogger` | Persists audit events to `transfer_audit_log`. |

## API Reference (`stock/api/`)

### Diagnostics & Utilities
- `_diag.php`, `_diag_headers.php` — Echo current headers/environment for troubleshooting.
- `print_pool_status.php` — Returns printer availability counts per outlet (consumed by pack UI).
- `services_live.php` — Lists enabled courier services and capabilities.
- `freight_catalog.php`, `freight_suggest.php`, `freight_price_preview.php` — Freight data surfaces for UI (rules, recommendations, pricing preview).
- `weight_suggest.php` — Suggests parcel weights for products.

### Pack Console Interactions
- `pack_save.php` — Draft save (requires lock); response includes shipment stats.
- `pack_ship_api.php` — Legacy pack-and-ship integration (still supported for backwards compatibility).
- `assign_tracking.php`, `save_manual_tracking.php` — Adds manual tracking references to parcels.
- `notes_add.php` — Appends transfer note & returns updated timeline entry.
- `address_facts.php` — Queries address metadata (rural, Saturday eligibility) for courier options.
- `rates.php` — Fetches courier rate cards for selected service/preset.
- `create_label.php` — Issues courier labels via `LabelsService`/`ShippingLabelsService` and returns printable artifacts.
- `void_label.php`, `void_bulk.php` — Cancels one or multiple labels.
- `pack_send.php` — Orchestrator endpoint (see flow above). Enforces POST, idempotency headers, guardian tier gating.

### Lock Management
- `lock_acquire.php`, `lock_release.php`, `lock_heartbeat.php` — Basic lock lifecycle.
- `lock_request.php`, `lock_requests_pending.php`, `lock_request_respond.php` — Queueing and responding to takeover requests.
- `lock_heartbeat.php` — Renews heartbeat; returns `not_holder` if lock lost.
- `expired.php` — Lists expired locks/requests for cleanup.

### Shipment Lifecycle
- `pack_send.php` — Idempotent orchestrator (detailed above).
- `parcel_receive.php` — Accepts receipt data per parcel for inbound transfers.
- `track_events.php` — Returns tracking history (used in diagnostic consoles).

> All endpoints enforce session authentication and most rely on `require_once $_SERVER['DOCUMENT_ROOT'].'/app.php'`, ensuring configuration, DB connections, and global guards are in place. CSRF, rate limiting, and idempotency protections are applied selectively via `HttpGuard`/`JsonGuard`.

## Shared Infrastructure (`_shared/`)
- **Autoload.php:** Registers PSR-4 loader for `CIS\Shared` classes.
- **Config.php:** Lazily provisions PDO connection using `DB_*` env vars, exposes helper toggles (`defaultTareG()`, `guardianEnabled()`, `afterPackUrl()`).
- **Contracts/**
  - `Dto.php`: DTOs for destination snapshots, parcel specs, totals, pickups, internal runs, depot drop, carrier options, pack/send request envelopes, shipment plan, transaction result.
  - `Enums.php`: Enumerations for pack modes, delivery modes, guardian tiers, error codes.
  - `Interfaces.php`: Interfaces for validator, guardian service, handler factory, handler, idempotency store, persistence, audit logging.
  - `Errors.php`: Defines typed error payloads/wrappers.
- **Core/**
  - `AuditLogger.php`: Inserts audit rows with before/after state JSON snapshots.
  - `GuardianService.php`: Guardian tier stub (returns `green`, ready for future escalation logic).
  - `HandlerFactory.php`: Maps `PackMode` enums to handler implementations.
  - `IdempotencyStore.php`: DB-backed store with automatic table creation and hash-based fetch/put methods.
  - `ParcelPlanner.php`: Pulls container capacities from `v_carrier_caps` and returns estimated parcel specs.
  - `PersistenceService.php`: Transactionally inserts shipments/parcels, updates transfers, audit logs, optional send events.
  - `Responder.php`, `Validation.php`: Response/validation helpers for consistent API envelopes.
  - `VendService.php`: Handles Vend interactions (consignment updates).
- **Services/** `PersistenceJuice`, `PersistencePo`, `PersistenceStaff` adapt persistence interface for other business units.
- **Support/** `Http`, `Json`, `Time`, `Types`, `Uuid` primitives used throughout module (e.g., JSON encoding with error handling, RFC4122 UUID generation).

## Stock Shared Stack (`stock/_shared/`)
- **Bootstrap.php** — Exposes `pack_send_bootstrap()` and `pack_send_orchestrator()` (singleton orchestrator factory).
- **Autoload.php** — Autoloader for `Modules\Transfers\Stock\Shared` namespace.
- **Handlers/** — Each extends `AbstractHandler` and implements `HandlerInterface` for specific modes:
  - `PackedNotSentHandler`, `CourierManualNzcHandler`, `CourierManualNzpHandler`, `PickupHandler`, `InternalDriveHandler`, `DepotDropHandler`, `ReceiveOnlyHandler`.
- **Services/**
  - `OrchestratorImpl`, `RequestValidatorImpl`, `GuardianService`, `IdempotencyStoreDb`, `ParcelPlannerDb`, `PersistenceStock`, `VendServiceImpl`, `AuditLoggerDb`, `Payloads.php` (DTO builders), plus specialized persistence adaptors (`PersistenceJuice`, `PersistencePo`, `PersistenceStaff`).
- **Util/** — JSON/UUID helpers, trait mixins for consistent request parsing.

## Front-end Assets & Boot Payload
- CSS: `assets/css/dispatch.css`, `transfers-common.css`, `transfers-pack.css`, `transfers-pack-inline.css`, plus shared theme assets.
- JS: `assets/js/dispatch.js`, `transfers-common.js`, `transfers-pack.js`, `ship-ui.js`, `pack-draft-status.js`, `pack-product-search.js` (all referenced by Pack UI).
- Boot payload (`window.DISPATCH_BOOT`): ensures JS operates offline-friendly by embedding API endpoints, capability flags, metrics, auto-plan suggestions, timeline, and user context. Maintain backwards compatibility when adding keys.
- Printing: `stock/print/box_slip.php` provides server-rendered slip template; JS triggers new window print flows.
- Sandbox UI: `stock/user-interface.php` offers static mock for design iterations (does not enforce guards).

## Database Touchpoints & Migrations

### Core Tables
- `transfers`, `transfer_items` — Master transfer records and line items.
- `transfer_shipments`, `transfer_parcels`, `transfer_parcel_items` — Shipment & parcel tracking.
- `transfer_carrier_orders` — Carrier metadata persisted per transfer.
- `transfer_audit_log` — Audit trail for pack/receive actions.
- `transfer_notes` — Timeline entries.
- `transfer_pack_locks`, `transfer_pack_lock_requests`, `transfer_pack_lock_audit` — Locking and audit trails.

### Supporting Tables
- `vend_products`, `vend_outlets`, `vend_inventory`, `vend_consignment_*` — Vend data and consignment exports.
- `freight_rules`, `containers`, `carriers`, `v_carrier_caps`, `category_weights` — Freight capacity & cost metadata.
- `idempotency_keys` — Created on demand by `IdempotencyStore`/`IdempotencyStoreDb`.

### Migrations (`stock/sql/`)
- `create_transfer_pack_locks.sql` — Creates lock tables & indexes.
- `20250926_1200_create_transfer_shipping_labels.sql` — Stores generated shipping labels.
- `20250926_1900_create_transfer_shipping_tracking_events.sql` — Tracking events per parcel.
- `20250929_1530_alter_transfer_shipments_mode_metadata.sql` — Extends shipments with mode metadata.

## Security & Compliance Guards
- **Session Enforcement:** Every entry point checks `$_SESSION['userID']`; orchestrator also reads `$_SESSION['staff_id']` for compatibility.
- **Access Control:** `Modules\Transfers\Stock\Lib\AccessPolicy::canAccessTransfer()` ensures user is permitted to view/edit transfer.
- **CSRF + Same-Origin:** `HttpGuard::sameOriginOr()` and `JsonGuard::csrfCheckOptional()` on sensitive POST routes.
- **Rate Limiting:** `HttpGuard::rateLimit()` used in receive flow; similar patterns available for other endpoints.
- **Idempotency:** `JsonGuard::idempotencyGuard()` and orchestrator idempotency headers prevent double submissions.
- **Audit Logging:** `transfer_audit_log`, lock audit tables, and orchestrator audit logger capture actor, action, before/after state.
- **Secrets Handling:** `Config::pdo()` reads credentials from environment; `output.php` redacts secrets when exporting files.
- **External Links:** All external assets referenced via HTTPS (e.g., `https://staff.vapeshed.co.nz/...`).

## Configuration & Environment
- **Mandatory Env Vars:** `DB_DSN`, `DB_USER`, `DB_PASS`, `CIS_API_KEY`, `CIS_GUARDIAN`, `CIS_OUTER_TARE_G`, `AFTER_PACK_URL`.
- **Outlet Metadata:** Courier capabilities derived from outlet record fields (`nz_post_api_key`, `nz_post_subscription_key`, `gss_token`, printer counts).
- **Module Constants:** `MODULES_PATH` must resolve before autoloaders run.
- **Time Zone:** Forced to `Pacific/Auckland` to ensure consistent timestamp logging.

## Observability & Logging
- **PHP Error Logs:** Refer to `logs/apache_phpstack-129337-518184.cloudwaysapps.com.error.log` for runtime errors (per High Quality instructions).
- **Audit Tables:** `transfer_audit_log`, `transfer_pack_lock_audit`, orchestrator audit store capture structured JSON payloads.
- **Application Logs:** `TransferLogger` persists JSON for pack/ship actions; ensure log rotation and monitoring.
- **Output API (`output.php`):** Allows controlled file inspection with minification & redaction (helpful for debugging config issues).

## Operational Checklists
### Deployments
1. Ensure migrations in `stock/sql/` executed (locks, shipping labels, tracking events, mode metadata).
2. Verify asset versions (CSS/JS) published to `https://staff.vapeshed.co.nz/modules/transfers/stock/assets/`.
3. Smoke test Pack Console: load `/modules/transfers/stock/pack.php?transfer=<id>`, confirm lock acquisition, auto-plan data, JS assets load.
4. Exercise Pack Save (with lock) and confirm shipment record creation.
5. Exercise Pack & Send orchestrator via POST (include idempotency header) and confirm Vend upsert success.
6. Validate receive flow by posting to `/modules/transfers/stock/receive.php?transfer=<id>` (with payload) in staging.

### Incident Response
- Lock conflicts: inspect `transfer_pack_locks` and `transfer_pack_lock_requests`; use `PackLockService::cleanup()` or manual delete with audit log note.
- Courier issues: check `LabelsService` responses, `freight_rules`, `containers` data; inspect API warnings.
- Vend sync issues: review `VendService` logs and orchestrator warnings.
- Timeline anomalies: verify `transfer_notes` integrity and StaffNameResolver lookups.

## Future Enhancements
- **Guardian Tiering:** Implement dynamic guardian rules (TrafficGuardian) to block high-risk dispatch scenarios.
- **PDO Harmonisation:** Migrate `PackLockService` to PDO wrapper for consistency and connection pooling.
- **Unit/Integration Tests:** Add automated tests for auto-plan heuristics, lock takeover flows, and orchestrator idempotency behaviour.
- **Print Pool Telemetry:** Augment printer metadata (names, IPs) and surface in Pack UI.
- **Frontend Bundling:** Consider module-level bundler (e.g., esbuild) for JS/CSS with sub-25 KB chunking per High Quality standard.
- **Monitoring:** Hook lock tables and orchestrator audit logs into Grafana/Sentry alerts for real-time visibility.
