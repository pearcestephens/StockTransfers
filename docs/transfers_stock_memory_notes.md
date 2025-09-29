# Transfers Stock Module – Memory Notes

> Fast-reference crib sheet for future work on Pack & Send.

## Environment & Globals
- `MODULES_PATH` must point to `/modules` root before services/autoloaders run.
- Required session keys: `$_SESSION['userID']` (primary), `$_SESSION['staff_id']` (fallback for orchestrator).
- Critical env vars: `DB_DSN`, `DB_USER`, `DB_PASS`, `CIS_API_KEY`, `CIS_OUTER_TARE_G`, `CIS_GUARDIAN`, `AFTER_PACK_URL`.
- Courier toggles sourced from outlet metadata: `nz_post_api_key`, `nz_post_subscription_key`, `gss_token`.

## Locking Workflow (Pack Console)
1. UI hits `stock/api/lock_acquire.php` → `PackLockService::acquire()`; success returns lock row + holder name.
2. Heartbeats every ~60s via `lock_heartbeat.php` (extends expiry to 5 minutes).
3. Save (`pack_save.php`) refuses when caller lacks lock; on success `LockAuditService::lockAcquire()` re-logs context.
4. `lock_release.php` frees lock; `lock_request.php` queues handover, `lock_request_respond.php` transfers ownership.
5. `PackLockService::cleanup()` culls expired rows and emits audit events before deletion.

## Pack Save vs Pack Send
- **`pack_save.php`:** Updates transfer items, enqueues shipment via `ShipmentService`, attaches tracking, logs `PACKED` events. Requires lock.
- **`pack_send.php`:** Full orchestrator (validation → handler → persistence → Vend upsert) controlled via `pack_send_orchestrator()` (no UI lock requirement but expects idempotency header).

## JS Boot Payload (`window.DISPATCH_BOOT`)
Keys relied on by front-end scripts:
- `transferId`, `fromOutletId`, `toOutletId`, `fromOutlet`, `toOutlet`, `fromLine`.
- `capabilities.printPool.{online,onlineCount,totalCount}` for blocking courier UI.
- `capabilities.carriers.{nzpost,nzc}` toggles courier tabs.
- `tokens.{apiKey,nzPost,gss}` optionally forwarded to browser when allowed.
- `endpoints.{rates,create,address_facts,print_pool,save_pickup,save_internal,save_dropoff,notes_add}` – keep stable.
- `metrics.{total_weight_grams,total_weight_kg,total_items,line_count}` informs capacity meters.
- Optional `autoplan` (packages array with `preset_id`, `ship_weight_kg`, `items`).
- `sourceStockMap[product_id]` populates qty hints.
- `timeline[]` hydrated from `NotesService` (persisted field `persist` ensures duplicates removed).

## Auto-Plan Thresholds
- Prefer **satchels** when total ≤ 12 kg **and** items ≤ 20; else boxes.
- Caps: satchel goods cap `15 kg - 0.25 tare`, box goods cap `25 kg - 2.5 tare`.
- Presets heuristics:
  - Satchel: `nzp_s` (<5 kg), `nzp_m` (5–9 kg), `nzp_l` (≥9 kg).
  - Box: `vs_m` (<14 kg), `vs_l` (14–20 kg), `vs_xl` (>20 kg).
- Tare weights: satchel `0.15–0.25 kg`, boxes `2.0–3.1 kg`.

## Timeline / Notes
- Timeline sources: `transfer_notes` via `NotesService::listTransferNotes()`, system events (transfer created), deduped by scope/id/ts.
- Notes added from UI via `notes_add.php`; stored with staff ID; names resolved live (`StaffNameResolver`).

## Receive Flow Highlights
- `stock/receive.php` enforces `HttpGuard::sameOriginOr`, rate-limits at `60 req / 60 s`, optional CSRF & idempotency guard.
- Payload persisted through `ReceiptService::saveReceive($transferId, $payload, $userId)`.

## Printing Considerations
- Print pool data currently placeholders (`printers_online`, `printers_total` default 0); ensure integrations update `vend_outlets` metadata.
- Offline pool triggers manual tracking mode in UI and blocks courier actions until dismissed.

## SQL Tables (Quick Reference)
- Transfer core: `transfers`, `transfer_items`, `transfer_shipments`, `transfer_parcels`, `transfer_carrier_orders`, `transfer_audit_log`.
- Pack locks: `transfer_pack_locks`, `transfer_pack_lock_requests`, `transfer_pack_lock_audit`.
- Orchestrator persistence: `transfer_shipping_labels`, `transfer_shipping_tracking_events`, `idempotency_keys`.
- Vendor data: `vend_products`, `vend_outlets`, `vend_inventory`, `vend_consignment_*`.
- Freight: `freight_rules`, `containers`, `v_carrier_caps`, `carriers`, `category_weights`.

## Frequently Touched Services
- `TransfersService::getTransfer()` — primary hydration call; reuses various metadata resolvers.
- `TransfersService::savePack()` — ensures delta shipments only for new quantities.
- `FreightCalculator::getWeightedItems()` — central for auto-plan + metrics.
- `PackLockService::heartbeat()` — must be polled every 60 s by front-end.
- `OrchestratorImpl::handle()` — idempotent pack/send entrypoint with guardian + audit.

## Testing Hooks & TODOs
- `stock/tools/carrier_pack_validator.php` for validating payloads against carrier capabilities.
- `output.php` can snapshot directories for quick audits (remember `ext`, `dir`, `search` filters).
- TODO flagged during review: unify DB access layer (PDO vs mysqli), strengthen guardian tiers, expand tests around packaging heuristics.
