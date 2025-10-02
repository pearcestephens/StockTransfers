# Stock Transfers Pack System – Troubleshooting Quick Reference

Updated: 2025-10-01

## 1. Script Fails to Load (SyntaxError)
Error: `Uncaught SyntaxError: Unexpected token '{'` at `pack-unified.js:<line>`

Cause: Corrupted `showLockDiagnostic()` (previous legacy fragment) – fixed by simplifying method. Ensure latest deployed file matches repository.

Check:
1. Open DevTools > Sources > locate `pack-unified.js` and verify `showLockDiagnostic()` matches repo.
2. Hard reload (Ctrl + Shift + R) to bust cache.

## 2. "Failed to initialize pack system - missing dependencies"
Cause: `DISPATCH_BOOT` not yet defined when `initializePackSystem()` ran.

Fix Options:
* Ensure bootstrap data script tag appears BEFORE pack system scripts.
* Or rely on retry logic (modular bootstrap waits). Legacy file only does single delayed attempt.

## 3. Autosave Not Triggering
Checklist:
* Do you hold the lock? (`packSystem.hasLock()` -> true)
* Any quantity inputs changed? (Modify one, wait 2s debounce)
* Console log should show: `Auto-save proceeding (trigger=input)`
* Network tab: POST to `draft_save_api.php` (legacy `draft_save.php` must return 410).

If skipped:
* Message: `Auto-save skipped - lock not yet acquired` → lock rescue still running.
* Message: `Auto-save skipped - no changes` → gathered payload identical to last save.

## 4. Frequent Duplicate Error Toasts
Cause: Multiple legacy scripts still active (e.g., old `pack-autosave.js`).
Action: Remove obsolete includes; rely on unified OR modular system (not both). Guard flag `window.PackAutoSaveLoaded` blocks duplicates—verify it is only set once.

## 5. Lock Never Acquired
Observe console:
* Should see rescue attempts: `🔒 Lock rescue attempt #n`.
* If none: lock system not attached → verify `PackLockSystem` loaded globally.
* If attempts but never success: backend lock endpoint failing – inspect network calls to `lock_acquire.php`.

## 6. Badge Shows UNLOCKED But You Have Lock
Possible stale `window.lockStatus` vs internal status.
Action: Run `packSystem.updateLockStatusDisplay()` manually; confirm normalized object from `packSystem.getLockStatus()`.

## 7. Add Product Modal Search Empty
Check POST `/modules/transfers/stock/api/search_products.php` returns JSON `{ success:true, products:[...] }`.
If HTML or 500: inspect server error log; verify CSRF/session not expired.

## 8. Manual Save Button Shows Success But Data Missing
Confirm which endpoint used:
* Draft/autosave: `draft_save_api.php`
* Manual/Complete: `pack_save.php`

Check response JSON; any parse error triggers: `Save response parse error`.

## 9. Lock Violation Overlays Persist
Overlay id: `#lockViolationOverlay`
Auto-removes after 30s.
If it doesn’t: ensure no JS errors occurred after creation (check console for follow-on exceptions).

## 10. Migrating to Modular Bootstrap
1. Remove `<script src=".../pack-unified.js"></script>` from template.
2. Add `<script type="module" src="/modules/transfers/stock/assets/js/pack/pack-bootstrap.module.js"></script>` AFTER `DISPATCH_BOOT` inline script.
3. Verify `window.packSystem` exists and version reported in console.

## 11. Quick Diagnostic Commands (Console)
```
packSystem.getLockStatus();
packSystem.hasLock();
packSystem.performAutoSave('manual_test');
packSystem.generateLabels();
```

## 12. When to File an Incident
Trigger if:
* Persistent 500s on save endpoints > 5 mins
* Lock acquisition failing for all users
* Autosave drops data (counts disappear after refresh)

Document in `/docs/notes/YYYY-MM-DD.md` per org policy.

---
Keep this file concise—add new entries only for recurring or high-impact issues.
