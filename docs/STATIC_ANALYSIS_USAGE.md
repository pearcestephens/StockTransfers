# Static Analysis Usage (Module Focus)

This module provides helper tooling to run **PHPStan** (and optionally **Psalm**) scoped to the Transfers module while still using the shared vendor toolchain.

## Helper Script
`modules/transfers/tools/module_analyse.php`

### CLI Examples
```
php modules/transfers/tools/module_analyse.php --level=7
php modules/transfers/tools/module_analyse.php --level=8
php modules/transfers/tools/module_analyse.php --tool=psalm
php modules/transfers/tools/module_analyse.php --tool=phpstan --raw > phpstan_module.txt
```

### HTTP (Internal) Examples
```
https://staff.vapeshed.co.nz/modules/transfers/tools/module_analyse.php?level=7
https://staff.vapeshed.co.nz/modules/transfers/tools/module_analyse.php?tool=psalm
```

Returns JSON envelope:
```json
{
  "success": true,
  "tool": "phpstan",
  "level": "7",
  "exit_code": 0,
  "cmd": "/full/path/vendor/bin/phpstan analyse /full/path/modules/transfers --level=7 ...",
  "autoload_used": "/full/path/vendor/autoload.php",
  "stdout": "Analysis results...",
  "stderr": "",
  "truncated": { "stdout": false, "stderr": false },
  "timestamp": "2025-10-03T00:00:00Z"
}
```

## Exit Codes
- 0: No detected errors (success)
- >0: Tool reported issues or failed

## Parameters
| Param | Tool | Description | Default |
|-------|------|-------------|---------|
| level | phpstan | Analysis level (0–9) | 7 |
| tool  | both | phpstan or psalm | phpstan |
| raw   | both | If present, return raw tool output (CLI only) | (off) |

## Integration Suggestion
Add a CI job step for module-only quick scan (faster feedback):
```
php modules/transfers/tools/module_analyse.php --level=7 || true
```
Then run the full root `vendor/bin/phpstan analyse modules` as the gate.

## Notes
- Script auto-detects the shared vendor path using the enhanced autoloader.
- Output is truncated at 25KB per stream to avoid oversized responses.
- Safe for embedding into an internal dashboard card or developer portal.

Generated: 2025-10-03

---
## Baseline Management (PHPStan)

Automate baseline lifecycle (create/update/verify) via:
`modules/transfers/tools/phpstan_baseline_manager.php`

### Generate (initial)
```
php modules/transfers/tools/phpstan_baseline_manager.php --action=generate --level=7
```
Fails with 409 if baseline exists (use `--force` or action `update`).

### Update (rebuild after refactors)
```
php modules/transfers/tools/phpstan_baseline_manager.php --action=update --level=8
```

### Verify (CI gate for new issues only)
```
php modules/transfers/tools/phpstan_baseline_manager.php --action=verify --level=8
```
Exit code reflects phpstan process; JSON includes `residual_errors_estimate` (heuristic).

### With Raw Output
```
php modules/transfers/tools/phpstan_baseline_manager.php --action=verify --raw
```

### Notes
- Baseline stored at root: `phpstan-baseline.neon` (shared across modules).
- If root `phpstan.neon` / `phpstan.neon.dist` absent, a temporary config targeting `modules/` is synthesized.
- Adds `--baseline` automatically on `verify` if baseline present.
- Use baseline to ratchet: fix errors, regenerate baseline at higher `--level` gradually.

### CI Suggested Flow
1. (One-off) Generate baseline at chosen level.
2. On each PR:
  - Quick module scan: `php modules/transfers/tools/module_analyse.php --level=7 || true`
  - Global verify: `php modules/transfers/tools/phpstan_baseline_manager.php --action=verify --level=7`
  - Fail pipeline only if new residual errors appear (non-zero exit).

---