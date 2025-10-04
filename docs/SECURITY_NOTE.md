# Transfers Module Security Note (Debug/Test Artifact Policy)

Date: 2025-10-03

## Purpose
Prevent reintroduction of ad-hoc debug, sandbox, or test harness endpoints inside production module paths (`stock/api/` and siblings) that can expand attack surface or cause contributor confusion.

## Disallowed Filename Patterns
The following substrings are prohibited in committed production file names unless explicitly reviewed and approved (e.g., in a dedicated internal tooling directory not web-accessible):

- `_debug_`
- `_test_`
- `_syntax_`
- `_workflow_debug`
- `_table_test`
- `_sandbox_`

## Scope
Applies to: `public_html/modules/transfers/stock/api/` and any web-exposed PHP, HTML, JS endpoints in this module.

## Rationale
- Prior cleanup removed 10 legacy artifacts that were not required for runtime.
- Minimizes risk of information disclosure (schema dumps, workflow traces).
- Reduces noise for security scanning & code review.

## Enforcement Strategy (Proposed)
1. Pre-commit Git hook or CI job scanning changed filenames:
   - If a new/renamed file matches the pattern, fail build with guidance.
2. Optional allowlist file: `docs/SECURITY_ALLOWED_DEBUG_FILES.txt` for rare approved cases (NOT created by default).
3. Daily drift job leveraging existing tooling drift detector to alert if new disallowed patterns appear.
4. Automated script: `stock/tools/check_disallowed_filenames.php` (exit code 2 on violations).

## Script Usage
From project or module root:
```
php modules/transfers/stock/tools/check_disallowed_filenames.php
php modules/transfers/stock/tools/check_disallowed_filenames.php --json
```
Integrate into CI before deployment; treat non-zero exit as failure.

## Suggested CI Grep (Legacy Fallback)
```
if git diff --name-only origin/main...HEAD | grep -E '(_debug_|_test_|_syntax_|_workflow_debug|_table_test|_sandbox_)'; then
  echo "ERROR: Disallowed debug/test filename detected." >&2
  exit 1
fi
```

## Developer Guidance
- Use local or non-public paths (`/private_html/tools/`) for temporary diagnostics.
- Never commit raw schema dump or broad workflow trace endpoints.
- For repeatable diagnostics, implement a controlled feature-flagged endpoint with auth + logging.

## Audit & Review
- Review this policy quarterly.
- After 30 days of inactivity for the legacy backup set, archive/compress and move to long-term storage.

---
Maintainer: (Auto-generated as part of cleanup hardening pass)
