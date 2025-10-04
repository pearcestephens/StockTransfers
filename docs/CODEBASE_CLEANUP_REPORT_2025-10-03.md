# Transfers Module Codebase Cleanup Report (2025-10-03)

## Objective
Remove obsolete, debug, and ad-hoc test artifacts from `stock/api/` to ensure only production endpoints remain. Existing backups created on 2025-10-02 were retained under `backups/2025-10-02_legacy_lock_cleanup/`.

## Removed Files (Live)
| Path | Category | Reason |
|------|----------|--------|
| stock/api/_debug_schema.php | Debug | Internal schema dump (security + noise) |
| stock/api/_debug_popup.html | Debug UI | Legacy popup viewer |
| stock/api/_workflow_debug.php | Debug | Workflow tracing script |
| stock/api/_test_auto_grant.php | Test | Ad-hoc permission/grant tester |
| stock/api/_test_auto_grant_live.php | Test | Live variant (risk) |
| stock/api/_test_suite.html | Test harness | Non-production test harness |
| stock/api/_test_api.php | Test | Generic test endpoint |
| stock/api/_test_ownership_request.php | Test | Ownership mock |
| stock/api/_syntax_test.html | Sandbox | Syntax / layout sandbox |
| stock/api/_table_test.php | Sandbox | Layout / table test script |

## Backups
All counterparts exist (with `.bak`) in: `backups/2025-10-02_legacy_lock_cleanup/`
No additional backups were generated today to avoid duplication.

## Rationale
- Reduce surface area & accidental exposure of internal debugging endpoints.
- Eliminate stale test harnesses that could confuse new contributors.
- Maintain audit trail via existing dated backups (immutable reference).

## Post-Cleanup Validation Checklist
- [x] No references to removed filenames in current production code (confirmed: only present in this report & backup folder)
- [x] Backups present for each removed file (verified prior to deletion)
- [x] CI / deployment scripts do not reference deleted artifacts (no matches outside backups/report)
- [x] Tooling drift detector passes (already independent of these files)

## Next Recommendations
1. Perform one more org-level grep outside this module during next deploy window.
2. After 30 days with no need to restore, compress or archive the backup folder.
3. Add / enforce filename lint rule blocking commits containing patterns: `_debug_`, `_test_`, `_syntax_`, `_workflow_debug` (see `SECURITY_NOTE.md`).

---
Generated: 2025-10-03
Updated: 2025-10-03 (Checklist finalized & security note reference added)