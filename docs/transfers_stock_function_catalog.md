# (Consolidated) Stock Transfers Module — Function & View Catalogue

> High-level architectural synthesis & quick references have moved to `TRANSFERS_MODULE_ARCHITECTURE.md`. This catalogue remains the exhaustive index; keep granular updates here.

> Exhaustive index of functions, class methods, and rendered views within `/modules/transfers`. Each entry captures the current responsibility, integration notes, and maturity status (Active, Legacy, In Progress).

## Legend
- **Status**
  - `Active` — production path in regular use.
  - `Legacy` — maintained for backward compatibility; earmarked for retirement.
  - `In Progress` — partially shipped or undergoing active development.
- **Scope** — describes the data/entities touched or the subsystem served.
- **Notes** — highlights dependencies, invocation points, or TODOs.

---

## 1. Shared Core (`_shared/` — namespace `CIS\Shared`)

### `_shared/Config.php` — Active
| Function | Purpose | Notes |
| --- | --- | --- |
| `pdo()` | Lazily instantiates a shared PDO connection using `DB_*` environment variables. | Used throughout shared services when module-level connection not injected. |
| `defaultTareG()` | Resolves fallback tare weight (grams) for cartons (env `CIS_OUTER_TARE_G`). | Referenced by parcel planners when outlet-specific tare not set. |
| `guardianEnabled()` | Checks env toggle `CIS_GUARDIAN` to determine guardian-tier availability. | Currently always returns `false` unless env explicitly enabled. |
| `afterPackUrl()` | Returns redirect URL post-pack completion (`AFTER_PACK_URL`, fallback `/transfers`). | Consumed by orchestrator/dashboard flows. |

### `_shared/Contracts/Interfaces.php` — Active
Interface method signatures for the shared orchestrator contracts:
- `HandlerInterface::handle`, `RequestValidatorInterface::validate`, `GuardianServiceInterface::tier`, `IdempotencyStoreInterface::{get,put,hash}`, `HandlerFactoryInterface::forMode`, `ParcelPlannerInterface::estimateByWeight`, `PersistenceServiceInterface::commit`, `VendServiceInterface::upsertConsignment`, `AuditLoggerInterface::write`.

### `_shared/Core/AuditLogger.php` — Active
| Method | Purpose | Notes |
| --- | --- | --- |
| `__construct(PDO $pdo = null)` | Accepts injected PDO or falls back to `Config::pdo()`. | | 
| `write()` | Persists audit log rows (`transfer_audit_log`) with before/after JSON snapshots and API response data. | Called after pack/receive actions. |

### `_shared/Core/GuardianService.php` — Active (stub)
| Method | Purpose |
| --- | --- |
| `tier()` | Returns guardian readiness (`green` or fallback) based on configuration. |

### `_shared/Core/HandlerFactory.php` — Active
| Method | Purpose |
| --- | --- |
| `forMode(PackMode)` | Returns handler instance for given `PackMode` enum (manual courier, pickup, etc.). |

### `_shared/Core/IdempotencyStore.php` — Active
| Method | Purpose |
| --- | --- |
| `__construct(PDO $pdo = null)` | Injects PDO or uses shared connection. |
| `hash(array $json)` | Generates SHA-256 hash of request payload for dedupe. |
| `get(string $key)` | Retrieves cached envelope for idempotent key. |
| `put(string $key, array $envelope)` | Upserts serialized response; creates table if missing. |

### `_shared/Core/ParcelPlanner.php` — Active
| Method | Purpose |
| --- | --- |
| `estimateByWeight()` | Produces parcel spec list using carrier capacity data. |
| `fetchCaps()` | Pulls container capacities from `v_carrier_caps` / `containers` with default tare applied. |

### `_shared/Core/PersistenceService.php` — Active
| Method | Purpose |
| --- | --- |
| `commit(ShipmentPlan)` | Atomic pack commit: inserts shipment, parcels, updates transfer totals, logs, optional send event. |
| `insertShipmentHeader()` | Creates `transfer_shipments` row. |
| `insertParcel()` | Inserts parcel rows with weight/dimension metadata. |
| `updateTransferTotals()` | Updates `transfers` totals + state. |
| `upsertCarrierOrder()` | Creates/updates `transfer_carrier_orders`. |
| `insertLog()` | Writes `transfer_audit_log` entries for pack/send transitions. |

### `_shared/Core/Responder.php` — Active Utility
| Method | Purpose |
| --- | --- |
| `ok()` / `fail()` | Builds standardized JSON envelopes for API responses. |

### `_shared/Core/Validation.php` — Active
| Method | Purpose |
| --- | --- |
| `validate()` | Shared validator harness (implementation defined per validator). |

### `_shared/Core/VendService.php` — Active
| Method | Purpose |
| --- | --- |
| `upsertConsignment()` | Syncs pack/send consignment data back to Vend/Lightspeed. |

### `_shared/Services` (`PersistenceJuice`, `PersistencePo`, `PersistenceStaff`) — Active
All three provide thin wrappers delegating to `PersistenceService::commit()` for domain-specific contexts (juice/PO/staff workflows).

### `_shared/Support` Helpers — Active
| File | Functions | Purpose |
| --- | --- | --- |
| `Http.php` | `envelopeOk`, `envelopeFail`, `header`, `uuid` | HTTP response helpers for consistent envelopes and header management. |
| `Json.php` | `decode`, `encode` | Safe JSON encoding/decoding with exceptions. |
| `Time.php` | `nowUtc` | UTC timestamp helper. |
| `Types.php` | `str`, `int`, `float`, `bool` | Type coercion utilities. |
| `Uuid.php` | `isUuid` | Simple UUID format validator. |

---

## 2. Module Output Utility

### `output.php` — Active
Provides secure recursive snapshot exporter under tight filters.
| Function | Purpose |
| --- | --- |
| `jexit()` | Outputs JSON response with appropriate status and exits. |
| `norm_path()` / `secure_join()` | Normalizes paths and prevents directory traversal. |
| `is_text_ext()` / `looks_text_file()` | Detects text files (extension + heuristic). |
| Minifiers (`strip_block_comments`, `strip_line_comments`, `min_css`, etc.) | Removes whitespace/comments depending on file type. |
| `redact_secrets()` / `minify_and_redact()` | Redacts sensitive tokens and optionally minifies content. |

---

## 3. Stock Shared Stack (`stock/_shared/` — namespace `Modules\Transfers\Stock\Shared`)

### `Bootstrap.php` — Active
| Function | Purpose |
| --- | --- |
| `pack_send_bootstrap()` | Ensures autoloader registration for shared stack. |
| `pack_send_orchestrator()` | Lazily instantiates singleton `OrchestratorImpl`. |

### Handlers (`Handlers/`) — Active
| File | Methods | Purpose | Status |
| --- | --- | --- | --- |
| `AbstractHandler.php` | `buildBasePlan`, `resolveParcels`, `ensureShipmentStatus`, `minInputs`, `plan`, `doPlan` (abstract) | Shared planning scaffolding for pack/send modes. | Active |
| `CourierManualNzcHandler.php` | `mode`, `minInputs`, `doPlan` | Manual NZ Couriers planning lane. | Active |
| `CourierManualNzpHandler.php` | same | Manual NZ Post planning lane. | Active |
| `DepotDropHandler.php` | `mode`, `minInputs`, `doPlan` | Handles depot drop-off workflows. | Active |
| `InternalDriveHandler.php` | ... | Handles internal fleet transfers. | Active |
| `PickupHandler.php` | ... | Handles pickup by external party. | Active |
| `PackedNotSentHandler.php` | ... | Marks transfers packed without dispatch. | Active |
| `ReceiveOnlyHandler.php` | `mode`, `doPlan` | For receive-only operations (no send). | Active |
| `HandlerInterface.php` | `mode`, `minInputs`, `plan` | Contract used by orchestrator. | Active |
| `HandlerFactoryImpl.php` | `__construct`, `register`, `resolve`, `supportedModes` | Runtime handler registry. | Active |

### Services (`Services/`) — Active
| File | Methods | Purpose |
| --- | --- | --- |
| `AuditLoggerDb.php` | `__construct`, `log` | Writes pack/send audit payloads. |
| `GuardianService.php` | `evaluate` | Applies guardian policy to validation result (current tier `green`). |
| `IdempotencyStoreDb.php` | `fetch`, `save`, `apcuKey` | Orchestrator response cache (DB + optional APCu). |
| `OrchestratorImpl.php` | `handle`, `resolveHandler`, `errorEnvelope`, `failedPlan` | Central orchestrator flow orchestrating validation, persistence, vend sync, audit. |
| `ParcelPlannerDb.php` | `estimateByWeight`, `loadCaps` | Parcel planning tailored for stock module. |
| `Payloads.php` | Multiple constructors, `fromHttp`, sanitizers | DTO builders for request payloads (transfer, pickup, internal, depot metadata). |
| `PersistenceStock.php` | `commit`, `insertShipment`, `insertParcels`, `updateTransfer`, `upsertCarrierOrder`, `insertLogs`, `insertLogRow` | Pack/send persistence tailored for stock transfers. |
| `PersistenceJuice/Po/Staff.php` | `commit` | Domain-specific persistence wrappers. |
| `RequestValidatorImpl.php` | `validate`, `addError`, `addWarning`, `isValid` | Validates pack send requests. |
| `VendServiceImpl.php` | `upsertManualConsignment` | Persists consignment state back to Vend. |

### Utilities (`Util/`) — Active
| File | Methods | Purpose |
| --- | --- | --- |
| `Db.php` | `pdo` | Dedicated PDO accessor with module-specific settings. |
| `Json.php` | `encode`, `decode` | Resilient JSON helpers. |
| `Time.php` | `nowUtc`, `nowUtcString`, `iso8601`, `toSqlDateTime` | Time conversions used in persistence/audit. |
| `Uuid.php` | `v4`, `isValid`, `normalize` | UUID helpers for request IDs and idempotency keys. |

---

## 4. Stock API Layer (`stock/api/`)

> All API scripts require `/app.php`, enforce session guards, and typically emit JSON bodies. Unless flagged legacy, they are active production endpoints.

### Diagnostics & Base Utilities
| File | Functions | Purpose | Status |
| --- | --- | --- | --- |
| `_diag.php` | `jout`, `jerr` | Dumps diagnostic data. | Active (internal use) |
| `_diag_headers.php` | (no functions: header snapshot) | Renders header diagnostics. | Active |
| `_lib/validate.php` | `cis_env`, `cors_and_headers`, `handle_options_preflight`, `get_all_headers_tolerant`, `require_headers`, `read_json_body`, `json_input`, `sanitize_bool`, `sanitize_parcels`, `parcel_total_kg`, `gst_parts_from_incl`, `ensure_saturday_rules`, `ok`, `fail` | Common validation + response helpers for API adapters. | Active |

### Carrier Adapters
| File | Functions | Purpose |
| --- | --- | --- |
| `adapters/nz_post.php` | `_nzp_hdr`, `_nzp_api_base`, `_nzp_token`, `_nzp_timeout`, `_nzp_retries`, `_http_json_nzp`, `_nzp_map_quote_rows`, `_nzp_map_create`, `nz_post_quote`, `nz_post_create`, `nz_post_void` | Wrap NZ Post API quoting/label/void endpoints with standard headers, retries, mapping. |
| `adapters/nzc_gss.php` | `_gss_hdr`, `_gss_api_base`, `_gss_token`, `_gss_timeout`, `_gss_retries`, `_http_json_gss`, `_gss_map_quote_rows`, `_gss_map_create`, `nzc_quote`, `nzc_create`, `nzc_void` | GoSweetSpot (NZ Couriers) adapter. |

### Box Allocation Suite — In Progress (new automation)
| File | Functions | Purpose |
| --- | --- | --- |
| `box_allocation_api.php` | `handleAllocateRequest`, `handleReallocateRequest`, `handleViewRequest`, `handleCarriersRequest`, `validateTransferForAllocation`, `applyUserModifications`, `moveItemBetweenBoxes`, `logAllocationEvent`, `recalculateAllocation` | HTTP handlers for allocation actions (trigger engine + persist). |
| `box_allocation_config.php` | `getConfig`, `setConfig`, `getConfigSchema`, `resetToDefaults`, `getPresets`, `applyPreset`, `validateConfigValue`, `castValue`, `initializeConfigTable`, `initializeDefaultConfig`, `logConfigChange` | Configuration management for allocation rules. |
| `box_allocation_engine.php` | `allocateTransferItems`, `getTransferItemsWithDimensions`, `getTransferRoute`, `getCarrierOptions`, `prioritizeItems`, `generateOptimalBoxes`, `canFitInBox`, `addItemToBox`, `createNewBox`, `splitLargeItem`, `getBestContainer`, `validateAndOptimize`, `attemptConsolidation`, `canMergeBoxes`, `mergeBoxes`, `calculateBoxCost`, `calculateShippingCost`, `saveBoxAllocations`, `clearExistingAllocations`, `createShipmentRecord`, `createParcelRecord`, `createParcelItems`, `updateTransferTotals`, `info`, `error`, `getRecommendations` | Core allocation engine and logging helpers. |

### Pack, Ship & Freight APIs — Active
| File | Functions | Purpose |
| --- | --- | --- |
| `pack_save.php` | `jexit` | Validates lock ownership and delegates to `TransfersService::savePack()`. |
| `pack_send.php` | (bootstrap + orchestrator call) | REST entrypoint for pack/send orchestrator. |
| `pack_ship_api.php` | Numerous helper classes & methods (e.g., `ps_db`, `pack_ship_out`, `pack_ship_log`, `request`, `sanitizePackages`, `volumetricKg`, `adapter` functions) | Legacy Starshipit/GSS integration retained for compatibility; handles label CRUD, rate quoting, history export. | Legacy |
| `create_label.php` | `outlet_carrier_creds`, `mark_transfer_packed` | Creates labels via `LabelsService`, marks transfer packed. |
| `rates.php` | `outlet_carrier_creds`, `resolve_tare_grams`, `fetch_nzc_containers`, `plan_nzc_mix_by_weight`, `satchel_allowed_total_g`, `adapter_nzpost_rates`, `adapter_gss_rates` | Produces courier rate options given payload. |
| `weight_suggest.php` | `env_tare_g`, `fetch_carrier_caps`, `satchel_allowed_g` | Suggests suitable weights/caps per carrier. |
| `services_live.php` | (no named functions) | Returns live services for UI toggles. |
| `freight_catalog.php` | (procedural) | Lists freight rules. |
| `freight_suggest.php` | (procedural) | Suggests freight combinations based on request. |
| `freight_price_preview.php` | (procedural) | Calculates price preview for selection. |

### Lock Management APIs — Active
| File | Functions | Purpose |
| --- | --- | --- |
| `lock_acquire.php` | (procedural) | Attempts to acquire pack lock, returns holder metadata. |
| `lock_release.php` | (procedural) | Releases active lock. |
| `lock_heartbeat.php` | (procedural) | Renews lock heartbeat. |
| `lock_request.php` | (procedural) | Queues access request for lock takeover. |
| `lock_request_respond.php` | (procedural) | Holder accepts/declines request; transfers lock if accepted. |
| `lock_requests_pending.php` | (procedural) | Lists outstanding requests for holder. |
| `expired.php` | (procedural) | Lists expired locks/requests for cleanup. |
|
### Tracking & Notes — Active
| File | Functions | Purpose |
| --- | --- | --- |
| `notes_add.php` | (procedural) | Adds transfer note via `NotesService`. |
| `track_events.php` | `norm_dt`, `insert_tracking_event`, `maybe_touch_parcel_and_shipment`, `add_log` | Ingests external tracking events; updates parcel/shipment state and audit log. |
| `assign_tracking.php` | (procedural) | Attaches manual tracking entries via `TrackingService`. |
| `save_manual_tracking.php` | (procedural) | Persists manual tracking numbers for non-labelled shipments. |

### Receive & Print Utilities — Active
| File | Functions | Purpose |
| --- | --- | --- |
| `parcel_receive.php` | (procedural) | Persists received parcel payload via `ReceiptService`. |
| `print_pool_status.php` | (procedural) | Returns printer availability for pack UI. |
| `void_label.php` / `void_bulk.php` | (procedural) | Cancels labels via `ShippingLabelsService`. |
| `assign_tracking.php` | (procedural) | Adds manual tracking references. |

---

## 5. Stock Services (`stock/services/` — namespace `Modules\Transfers\Stock\Services`)

### Core Workflow Services — Active
| File | Methods | Purpose | Notes |
| --- | --- | --- | --- |
| `TransfersService.php` | `__construct`, `getTransfer`, `getSourceStockLevels`, `getOutletMeta`, `savePack`, `fetchCurrentSentMap`, `fetchRequested`, `normalizePackages`, `asArray` | Primary data access layer for transfers/pack operations. | Relies on `core DB` or global PDO; logs via `TransferLogger`. |
| `ShipmentService.php` | `createShipmentWithParcelsAndItems` | Builds shipment + parcel records and triggers tracking assignments. | Active |
| `ReceiptService.php` | `saveReceive`, `beginReceipt`, `receiveParcelItem`, `finalizeReceipt`, `fetchSentMap`, `fetchSentRecvSums` | Handles receiving workflow and reconciles counts. | Active |
| `TransferLogger.php` | `log` | Structured event logging (pack/exception). | Active |
| `ExecutionService.php` | `begin`, `complete` | Execution context for pack/send operations (wraps steps with audit). | Active |

### Support Services — Active
| File | Methods | Purpose |
| --- | --- | --- |
| `FreightCalculator.php` | `getWeightedItems`, `getRules`, `getRulesGroupedByCarrier`, `pickRuleForCarrier`, `getCarrierIdByCode`, `pickContainer`, `planParcelsByCap`, `normalizeCarrier`, `detectCarrier`, `humanizeContainer` | Freight heuristics, container lookup, and parcel planning support. |
| `BoxAllocationService.php` | `generateOptimalAllocation`, `loadAllocationRules`, `saveAllocation`, etc. | Business-layer wrapper around allocation engine for UI/API. |
| `ProductSearchService.php` | `search` | Performs product lookup (name/SKU/handle) for pack console. |
| `NotesService.php` | `addTransferNote`, `listTransferNotes`, `addShipmentNote`, `appendParcelNote` | Note/timeline management. |
| `TrackingService.php` | `setParcelTracking`, `addEvent` | Update parcel tracking numbers and associated events. |
| `ShippingLabelsService.php` | `recordReservation`, `upgradeToLabel`, `voidLabel`, `findByReservation`, `findByLabel`, `listRecentByTransfer`, `listRecent`, `storeTrackingEvents`, `getTrackingEvents` | Persist label lifecycle state. |
| `LabelsService.php` | `prepareParcels` | Prepares label payloads (dimensions/weight) for adapters. |
| `ParcelService.php` | `addParcelItem`, `setTracking` | Parcel helper for manual adjustments. |
| `StaffNameResolver.php` | `name` | Maps staff IDs to display names (cached). |
| `TransferLogger.php` | `log` | JSON log writer. |

### Lock Services — Active
| File | Methods | Purpose |
| --- | --- | --- |
| `PackLockService.php` | `getLock`, `acquire`, `heartbeat`, `releaseLock`, `requestAccess`, `holderPendingRequests`, `respond`, `cleanup` | Manages lock lifecycle in `transfer_pack_locks` (uses `mysqli`). |
| `LockAuditService.php` | `lockAcquire`, `lockRelease`, `lockRequest`, `lockRespond`, `heartbeat`, `requestExpire`, `audit/log` helpers | Writes `transfer_pack_lock_audit` entries. |

### Logging/Utility Services — Active
| File | Purpose |
| --- | --- |
| `ExecutionService.php` | Wraps long-running operations with begin/complete markers. |
| `TransferLogger.php` | Persist structured logs. |

---

## 6. Stock Library & Access Control

### `stock/lib/AccessPolicy.php` — Active
| Function | Purpose |
| --- | --- |
| `pdo()` | Module-level PDO accessor. |
| `canAccessTransfer`, `canPackTransfer`, `canReceiveTransfer` | Access checks based on outlet membership/admin role. |
| `filterAccessible()` | Filters set of transfers by permissions. |
| `requireAccess()` | Throws/halts when user lacks access. |
| `fetchTransferOutlets()` | Fetches outlet metadata for transfer. |
| `isAdmin()`, `userHasOutlet()` | Utility checks for policy decisions. |

---

## 7. UI Entry Points & Views

### `stock/pack.php` — Active Pack Console View
Functions local to template:
| Function | Purpose |
| --- | --- |
| `tfx_clean_text`, `tfx_first` | Sanitize text values (JSON flattening). |
| `tfx_render_product_cell` | Renders product name + SKU cell markup. |
| `tfx_outlet_line` | Generates outlet contact/address summary string. |
| `tfx_build_dispatch_autoplan`, `tfx_pick_autoplan_preset`, `tfx_autoplan_tare` | Auto parcel planning heuristics. |

### `stock/receive.php` — Active Receive Console View
| Class/Method | Purpose |
| --- | --- |
| `User::__construct`, `User::requireLoggedIn` | Simple wrapper enforcing login. |
| Procedural body | Bootstraps receive view, handles POST (delegates to `ReceiptService`). |

### `stock/user-interface.php` — Legacy Sandbox View
Provides stand-alone dispatch console for front-end prototyping (dummy data). No server-side functions beyond templating.

### Views (`stock/views/`)
| File | Functions | Purpose |
| --- | --- | --- |
| `pack.view.php` | `tfx_pack_clean_text`, `tfx_pack_first_clean`, `tfx_pack_render_product_cell` | Reusable helpers for pack partial. |
| `pack_ship_slim.php` | `fmt`, `fmtKg`, `sumKg`, `renderPackages`, `renderMeters`, `applyR18B2BRule`, `getRates`, `renderRates`, `createLabel`, `bindUI`, `boot` | Slim version of pack/ship wizard for legacy flow. |
| `pack.meta.php` | (no functions) metadata include. |
| `dispatch_boot.php` | Boot snippet consumed by JS. |
| `receive.view.php` | (procedural) renders receive interface. |
| `print_wizard.php` | (procedural) handles print dialogue markup. |

### Printing
| File | Function | Purpose |
| --- | --- | --- |
| `print/box_slip.php` | `outlet_name_by_uuid` | Resolves outlet display name for slip template. |

### UI Tools
| File | Functions | Purpose |
| --- | --- | --- |
| `ui/box_editor.php` | `initializeDragAndDrop`, `bindEventHandlers`, `moveProduct`, `editBox`, `splitBox`, `deleteBox`, `regenerateBoxes`, `addNewBox`, `saveAllocation`, `regenerateWithSettings`, `moveProductToBox`, `showToast`, `showLoading`, `hideLoading` | Front-end JS for allocation editor (draggable boxes). |

---

## 8. Tools & Scripts

### `stock/tools/carrier_pack_validator.php` — Active Internal Tool
| Function | Purpose |
| --- | --- |
| `issue()` | Emits validation output for CLI usage. |
| `readCsv()` | Parses CSV inputs for validation runs. |

---

## 9. Progress Summary
| Area | Status | Notes |
| --- | --- | --- |
| Pack Console (`stock/pack.php` + services/APIs) | Active | Production hardened with locks, auto-planning, manual courier workflow. |
| Pack & Send Orchestrator (`stock/_shared`) | Active | Fully deployed; guardian tier currently permissive (`green`). |
| Box Allocation Suite (`stock/api/box_*`, `BoxAllocationService`) | In Progress | Engine and config present; confirm rollout per outlet before enabling by default. |
| Legacy Pack Ship (`pack_ship_api.php`, `pack_ship_slim.php`) | Legacy | Retained for compatibility; plan roadmap for retirement once orchestrator adoption reaches 100%. |
| Receive Flow (`stock/receive.php`, `ReceiptService`) | Active | Rate-limited, note to extend guardian once TrafficGuardian supports inbound flows. |
| Output Diagnostics (`output.php`) | Active | Useful for auditing deployments (ensure access restrictions remain enforced). |

---

## 10. Maintenance Notes & TODOs
- Migrate `PackLockService` from `mysqli` to PDO/connection pool for consistency and metrics.
- Expand unit/integration testing around `box_allocation_engine.php` before general release.
- Implement guardian tiers beyond `green` to block risky dispatch (#red conditions).
- Continue documenting API request/response schemas (JSON examples) alongside this catalogue in future revisions.
- Monitor asset size budgets (<25 KB) for JS/CSS referenced in pack console; consider bundling pipeline.
