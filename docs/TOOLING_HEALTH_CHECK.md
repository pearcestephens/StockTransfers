# Tooling Health Check

Runtime verification for shared tooling centralization.

## Endpoint
`modules/transfers/tools/tooling_health.php`

Returns JSON:
```json
{
  "success": true,
  "data": {
    "autoload": { "candidates": [], "used": null, "used_exists": false },
    "tool_versions": { "squizlabs/php_codesniffer": "3.x" },
    "composer_lock_paths_checked": ["/full/path/public_html/composer.lock"],
    "module": "transfers",
    "timestamp": "2025-10-02T00:00:00Z"
  },
  "meta": { "note": "Tooling health snapshot; safe for internal diagnostic dashboards." }
}
```

## Fields
- `autoload.candidates` Ordered probe list (first existing wins).
- `autoload.used` Actual autoload path included.
- `tool_versions` Versions (from composer.lock or class presence fallback).
- `composer_lock_paths_checked` Lock files examined during version extraction.

## Usage Examples
Embed in an internal dashboard card or cron ping:
```
curl -s https://staff.vapeshed.co.nz/modules/transfers/tools/tooling_health.php | jq '.data.autoload.used'
```

## Alerting Suggestions
- Alert if `used` becomes null.
- Alert if `tool_versions` missing `squizlabs/php_codesniffer` (indicates broken install).
- Alert if timestamp older than 5 minutes (caching / stale deploy proxy issue).

## Security
- Output contains no secrets.
- Keep behind authenticated staff portal; do NOT expose publicly.

## Extension Ideas
- Add `ci_last_run` (pull from a persisted file updated by CI).
- Add hash of root `composer.lock` for change detection.
- Integrate aggregated dashboard: `modules/transfers/tools/tooling_dashboard.php`.
- Periodic snapshot archival via `modules/transfers/tools/update_tooling_snapshot.php`.

Generated: 2025-10-02