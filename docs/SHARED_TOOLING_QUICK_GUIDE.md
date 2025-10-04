# Shared Tooling Quick Guide (Lean Model)

Goal: All modules share one vendor toolchain & JS lint setup. Keep duplication = zero.

## PHP (Composer)
Preferred locations (first existing wins):
1. `public_html/vendor/autoload.php`
2. `public_html/modules/_shared/vendor/autoload.php`
3. Module-local `vendor/` (discouraged; only for temporary experiments)

Your module autoloader now exposes:
- `cis_shared_composer_autoload_paths()` → candidate list (ordered)
- `cis_shared_composer_autoload_used()` → actual path chosen (or null)

## Minimal Root composer.json (If missing)
```json
{ "require": { "php": ">=8.1" }, "require-dev": { "squizlabs/php_codesniffer": "^3.10" } }
```
Install once at root:
```
composer install --no-interaction --prefer-dist
```

## Linting From Root (Examples)
```
vendor/bin/phpcs --standard=phpcs.xml modules/transfers
vendor/bin/phpcs --standard=phpcs.xml modules
```

## JS Lint (Root package.json)
```json
{ "private": true, "devDependencies": { "eslint": "^8.57.0" } }
```
Install + run:
```
npm install --no-audit --no-fund
npx eslint "modules/**/stock/assets/js" --ext .js --max-warnings=0
```

## CI Minimal
Run only if present (skip gracefully if missing):
```
[ -f vendor/bin/phpcs ] && vendor/bin/phpcs --standard=phpcs.xml modules || echo "phpcs skipped"
[ -f node_modules/.bin/eslint ] && npx eslint "modules/**/stock/assets/js" --ext .js || echo "eslint skipped"
```

## Verification
```
php -r 'require "modules/transfers/_shared/Autoload.php"; var_dump(cis_shared_composer_autoload_used());'
```

Expect a string path (root or shared). Null means no composer toolchain found.

Additional runtime JSON check:
```
curl -s https://staff.vapeshed.co.nz/modules/transfers/tools/tooling_health.php | jq '.data.autoload.used'
```

Aggregated dashboard:
```
curl -s https://staff.vapeshed.co.nz/modules/transfers/tools/tooling_dashboard.php | jq '.data'
```

## Rollback
If root vendor removal breaks a module, temporarily run a local `composer install` inside that module, then migrate back after fixing root.

## Drift Detection
From application root (after copying script):
```
php modules/transfers/tools/find_lint_config_drift.php
```
Exit code 0 = clean; 1 = duplicates found.

## Snapshots (Historical Tracking)
Cron (every 15m example):
```
*/15 * * * * php /path/to/public_html/modules/transfers/tools/update_tooling_snapshot.php > /dev/null 2>&1
```
Latest snapshot: `modules/transfers/var/tooling/snapshot.json`.
Append-only history: `modules/transfers/var/tooling/history.log` (rotate via logrotate).

Generated: 2025-10-02