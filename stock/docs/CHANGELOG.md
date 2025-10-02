# Changelog – Stock Transfers Pack System

All notable changes are documented here. Date format: YYYY-MM-DD.

## 2025-10-01
### Fixed
* Resolved `SyntaxError: Unexpected token '{'` in `pack-unified.js` caused by corrupted `showLockDiagnostic()` implementation (unbalanced braces, stray statements).
* Added missing `initToastSystem()` to prevent init failures when toast module not globally provided.
* Hardened `initLockSystem()` with early retry exit if lock subsystem not yet loaded.

### Changed (Later same day – Shim Finalization)
* Replaced legacy monolith content inside `pack-unified.js` with a 2.0.0 production shim (`2.0.0-shim`).
* Removed in-file business logic (autosave, product search, lock badge rendering, UI mutation) – now exclusively in modular mixins via `ModularTransfersPackSystem`.
* Added resilient DISPATCH_BOOT retry (2s) + modular upgrade polling window (3s) to support flexible script load ordering.
* Introduced explicit `SHIM_VERSION` and retained global constructor for backward compatibility.
* Installed one-time diagnostic bridge for `showLockDiagnostic()` preserving legacy invocation patterns.
* Guarded duplicate autosave by asserting `window.PackAutoSaveLoaded` early.
* Ensured rollback path documented (restore `BACKUPS/pack-unified.v2.0.0.full.js`).

### Enhanced (UI Polish)
* Added `pack-system-ui.css` scoped styling for transfer pack UI (sticky headers, quantity state coloring, responsive search results, dark-mode hooks, RTL readiness).
* Introduced utility helpers (gap, truncate, inline badge) within `.transfer-pack` namespace – zero global leakage.
* Prepared for performance pipeline: minified JS + ready for CSS minification & SRI (follow-up below).

### Added
* `PACK_ARCHITECTURE.md` – architecture & migration guide for monolith → modular system.
* `TROUBLESHOOTING.md` – quick reference for common operational issues.
* Fallback toast system (console-based) for environments missing UI toast provider.
- Per-item weight column with dynamic total recalculation in `pack.view.php` using product → category → default(100g) precedence.
- Integration of `pricing_matrix` view into `BoxAllocationService` for dynamic cost-aware container selection (`loadPricingMatrix`, `chooseContainerForItem`, `createNewBoxForItem`).
- SQL migration `2025_10_01_pricing_matrix_view.sql` creating/updating `pricing_matrix` view from core pricing tables.
- Service `CarrierContainerOptimizer` for multi-box post-processing (consolidation + downsizing using pricing_matrix cost heuristics).
- Corrected pack controller weight aggregation: replaced static 0.15kg per-item + 0.1kg floor with product/category/default(100g) grams-based computation and added light satchel pricing tier.

### Changed
* Simplified legacy diagnostic path; returns normalized lock snapshot instead of executing malformed UI code.
* Box allocation now prefers cheapest suitable container (weight/volume filtered) before falling back to static templates.
* Default-weight indicator `(def)` appended to unit weight display when 100g fallback is used.

### Notes
* If `pricing_matrix` unavailable or query fails, system gracefully reverts to legacy medium-box template path.
* Future enhancement: multi-item consolidation scoring using cumulative volume density & carrier-specific caps.
* Optimizer is advisory; persistence layer must decide whether to apply changes.
* Future: add volumetric weight & zone-based surcharges adjustments.

## 2025-09-30
* Initial modular mixin extraction (`core`, `lock`, `autosave`, `ui`, `products`, `toast`, `events`, `locksafety`, `actions`).
* Added `pack-bootstrap.module.js` composer with rescue loop and compatibility bridge.

---
Future changes must include performance or behavioral impact notes and any required migration guidance.
