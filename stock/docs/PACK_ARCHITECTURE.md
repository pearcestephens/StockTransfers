# Stock Transfers Pack System – Architecture & Migration Guide

## Overview
The packing UI logic is in **migration** from a legacy monolithic script (`pack-unified.js`) to a **modular mixin-based system** located under:

```
/modules/transfers/stock/assets/js/pack/
  core.js        (Base class + fallback event bus)
  lock.js        (Lock acquisition + normalization)
  autosave.js    (Debounced, lock‑gated autosave)
  ui.js          (DOM bindings, row state, totals)
  products.js    (Search + add product modal)
  toast.js       (Toast wrapper + dedupe logic)
  events.js      (Event bus abstraction)
  locksafety.js  (Violation detection + overlays)
  actions.js     (High‑level actions: complete, labels)
  pack-bootstrap.module.js (Composer/bootstrap)
```

## Runtime Loading Strategy
1. Existing pages may still include `pack-unified.js` (legacy). This file now acts as a **compatibility layer** until templates are updated.
2. New implementation should prefer including:
   ```html
   <script type="module" src="/modules/transfers/stock/assets/js/pack/pack-bootstrap.module.js"></script>
   ```
3. A global `window.packSystem` object is exposed by both systems to avoid breaking inline handlers.

## Lock System
* Normalized fields: `hasLock/has_lock`, `isLockedByOther/is_locked_by_other`, `lockedBy/holderId`, `lockedByName/holderName`.
* Rescue loop attempts acquisition every 4s (max 6) if neither held nor owned by another user.
* Pending autosave flush triggers once lock is acquired.

## Autosave
* Debounce: 2s after last input.
* Max interval: 30s (forced save if due).
* Throttle: ≥ 500ms between attempts.
* Guard: Only runs when lock is definitively held.
* Single-flight: `_inFlightSave` prevents overlapping requests.

## Toast System
* Dedupe identical messages inside a sliding window (8s for errors, 2.5s others).
* Specific suppression for repeated "Auto-save failed" messages (<8s).

## Event Bus
* Uses global `PackBus` if present; otherwise a lightweight fallback is created (same `.on/.emit` API, plus `listen` alias).

## Product Search / Add Flow
* Debounced (300ms) search POST → `/modules/transfers/stock/api/search_products.php`.
* Add product POST → `add_product.php`; on success refreshes page after 1s.

## Known Legacy Elements Retained
* Global bridge: `window.showLockDiagnostic()` delegates to modular or legacy implementation.
* Inline onclick handlers can still call `packSystem.completePack()` etc.

## Recent Fix (2025-10-01)
**Issue:** `pack-unified.js:163 Uncaught SyntaxError: Unexpected token '{'` caused by a corrupted `showLockDiagnostic()` method (unbalanced braces, stray `badge` references, orphaned `else`). This prevented the script from evaluating and resulted in secondary init failure ("missing dependencies").

**Resolution:**
* Rewrote `showLockDiagnostic()` as a concise, side-effect safe fallback.
* Added missing `initToastSystem()` (previously referenced but undefined) so initialization sequence is complete.
* Hardened `initLockSystem()` with early retry exit instead of malformed trailing `else` block.

Commit changes applied in this patch:
* Add toast init fallback (console-based if PackToast absent).
* Normalize and retry lock system attachment.
* Remove syntactically invalid diagnostic code fragment.

## Migration Steps (Planned)
1. Update templates to load only the modular bootstrap (drop `pack-unified.js`).
2. Convert `pack-unified.js` into a thin shim that warns if loaded redundantly.
3. Remove deprecated diagnostic fallback once modular system proven stable for >1 release cycle.
4. Introduce optional build (Rollup/Vite) if JS size/duplication grows.

## Troubleshooting
| Symptom | Likely Cause | Action |
|---------|--------------|--------|
| Autosave never fires | No lock acquired | Check rescue loop logs, verify lock API endpoint. |
| Duplicate toasts | Legacy autosave script still included | Remove old `pack-autosave.js` include; rely on unified or modular system only. |
| Lock badge not updating | DOM id mismatch (`#lockStatusBadge`) | Ensure badge element present before init or adjust selector. |
| SyntaxError on load | Partial deployment / stale cache | Hard reload, verify file integrity vs repo. |

## Extension Points
* Add new behavior by creating a mixin file and importing it in `pack-bootstrap.module.js`, composing with existing chain.
* Use event bus (`pack:<event>` namespace) to decouple new UI widgets.

## Safety Considerations
* All writes gated by lock ownership.
* Autosave payload includes only counted quantities + notes; safe idempotent draft endpoint should validate server-side lock again.
* Single-flight prevents race conditions creating inconsistent progress states.

---
Maintainer Notes: Keep this document updated whenever lock/ autosave semantics, event schemas, or public API surface change.
