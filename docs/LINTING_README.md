# Linting & Style (Transfers Module)

Centralized linting is now enforced. Local per-module config files were removed in favour of **shared root / shared _shared** configurations.

## JavaScript
- Root Config: `public_html/.eslintrc.json` (or inherited defaults)
- Run (module scope): `npx eslint modules/transfers/stock/assets/js --ext .js --max-warnings=0`
- Run (all modules): `npx eslint "modules/**/stock/assets/js" --ext .js --max-warnings=0`
- Key Rules (root set): no unused vars (warn), eqeqeq enforced, curly braces required, no `var`, prefer `const`.

## PHP
- Root Config: `public_html/phpcs.xml`
- Run (module scope): `vendor/bin/phpcs --standard=phpcs.xml modules/transfers`
- Run (all modules): `vendor/bin/phpcs --standard=phpcs.xml modules`
- Standard: PSR-12 + line length limit 140 soft / 180 hard (see root file).

## Exclusions
- Backups directories (`*/backups/*`) excluded globally.
- Legacy deleted artifacts retained only as truncated backups under `backups/` for audit.

## Next Steps
1. Confirm root `vendor/` + root lint configs exist (or create via shared proposal doc).
2. Integrate unified commands into CI (single job covers all modules).
3. Address warnings iteratively; keep PRs small.
4. Adjust root ruleset when introducing new cross-module patterns.

## Historical Note
Local `.eslintrc.json`, `phpcs.xml`, and `.editorconfig` were removed on 2025-10-02 and backed up under `backups/2025-10-02_lint_config_cleanup/`.

## Shared Tooling (_shared Folder Strategy)
This module autoloader now probes for a shared Composer autoload in these locations (in order):
1. `public_html/vendor/autoload.php` (application-wide root)
2. `public_html/modules/_shared/vendor/autoload.php` (shared across all modules)
3. `public_html/modules/transfers/../_shared/vendor/autoload.php` (relative probe)
4. `public_html/../../vendor/autoload.php` (defensive fallback)
5. Module-local `vendor/autoload.php` (last resort; avoid for cross-module libs)

Recommendation:
- Place reusable dev + runtime libraries (e.g., PHP_CodeSniffer, phpstan, ramsey/uuid, monolog) in the **application root composer.json** or a dedicated `modules/_shared` Composer project to prevent duplication.
- Keep module-specific experimental dependencies local until stable, then promote them upward.

Diagnostics:
- Call `cis_shared_composer_autoload_paths()` (after including `_shared/Autoload.php`) to inspect which candidate paths are being checked.
- First existing path wins; subsequent paths are ignored.

CI Integration Hint:
- Run `phpcs` & any shared tools from the root so modules inherit the same dependency versions.
