# Root Tooling (Option A Implementation Plan)

This document provides **ready-to-drop** files for centralizing shared developer tooling at the application root (`public_html/`). Since this module workspace cannot write outside `modules/transfers/`, copy these artifacts manually to the root or `modules/_shared/` as indicated.

---
## 1. Root `composer.json`
Place at: `public_html/composer.json`

```json
{
  "name": "ecigdis/cis-app",
  "type": "project",
  "license": "proprietary",
  "require": {
    "php": ">=8.1"
  },
  "require-dev": {
    "squizlabs/php_codesniffer": "^3.10",
    "phpstan/phpstan": "^1.11",
    "friendsofphp/php-cs-fixer": "^3.64",
    "vimeo/psalm": "^5.26"
  },
  "scripts": {
    "lint:php": "vendor/bin/phpcs --standard=phpcs.xml modules",
    "fix:php": "vendor/bin/phpcbf --standard=phpcs.xml modules || true",
    "analyse:phpstan": "vendor/bin/phpstan analyse modules --memory-limit=512M",
    "analyse:psalm": "vendor/bin/psalm --show-info=true",
    "pre-commit": [
      "@lint:php"
    ]
  },
  "config": {
    "sort-packages": true
  }
}
```

If you want to restrict analysis scope, adjust the `modules` path accordingly.

Run after placement:
```
composer install --no-interaction --prefer-dist
```

---
## 2. Root `phpcs.xml` (Unified)
If a root file does not exist, create this at `public_html/phpcs.xml` to standardize style across *all* modules:

```xml
<?xml version="1.0"?>
<ruleset name="CISGlobalStandard">
  <description>Global coding standard: PSR-12 + selected strict rules.</description>
  <file>modules</file>
  <exclude-pattern>*/backups/*</exclude-pattern>
  <rule ref="PSR12" />
  <rule ref="Generic.Files.LineLength">
    <properties>
      <property name="lineLimit" value="140"/>
      <property name="absoluteLineLimit" value="180"/>
    </properties>
  </rule>
  <rule ref="Generic.Formatting.DisallowMultipleStatements" />
  <!-- Optional stricter rules (uncomment as teams align) -->
  <!-- <rule ref="SlevomatCodingStandard.TypeHints.ParameterTypeHintSpacing" /> -->
</ruleset>
```

You may keep the module-level `phpcs.xml`; PHPCS will honor the explicitly passed one. Recommended: delete per-module duplicates after rollout to avoid drift.

---
## 3. Root `package.json` (JS Tooling)
Place at: `public_html/package.json`

```json
{
  "name": "cis-root-tooling",
  "private": true,
  "type": "module",
  "devDependencies": {
    "eslint": "^8.57.0"
  },
  "scripts": {
    "lint:js": "eslint \"modules/**/stock/assets/js\" --ext .js --max-warnings=0",
    "lint:js:report": "eslint \"modules/**/stock/assets/js\" -f json -o eslint-report.json"
  }
}
```

Install:
```
npm install --no-audit --no-fund
```

(If using Yarn or PNPM, adapt accordingly.)

---
## 4. Optional Root `.eslintrc.json`
If you want a single ESLint config instead of per-module copies:

```json
{
  "root": true,
  "env": { "browser": true, "es2022": true },
  "extends": ["eslint:recommended"],
  "parserOptions": { "ecmaVersion": 2022, "sourceType": "module" },
  "rules": {
    "no-unused-vars": ["warn", { "args": "after-used", "ignoreRestSiblings": true }],
    "no-undef": "error",
    "eqeqeq": ["error", "always"],
    "curly": ["error", "all"],
    "semi": ["error", "always"],
    "no-var": "error",
    "prefer-const": "warn"
  }
}
```

Then remove the module `.eslintrc.json` files or leave them as overrides if needed.

---
## 5. Root `Makefile`

```makefile
# Quick automation for shared tooling
PHP_CS      = vendor/bin/phpcs
PHP_CBF     = vendor/bin/phpcbf
PHPSTAN     = vendor/bin/phpstan
PSALM       = vendor/bin/psalm
ESLINT      = node_modules/.bin/eslint
MODULES_DIR = modules

.PHONY: help install lint fix analyse analyse-all lint-js lint-php

help:
	@echo "Targets: install, lint, fix, analyse, lint-js, lint-php"

install:
	composer install --no-interaction --prefer-dist
	npm install --no-audit --no-fund || true

lint: lint-php lint-js

lint-php:
	$(PHP_CS) --standard=phpcs.xml $(MODULES_DIR)

fix:
	$(PHP_CBF) --standard=phpcs.xml $(MODULES_DIR) || true

lint-js:
	$(ESLINT) "modules/**/stock/assets/js" --ext .js --max-warnings=0

analyse:
	$(PHPSTAN) analyse $(MODULES_DIR) --level=8 --memory-limit=512M

analyse-all: analyse
	$(PSALM) --show-info=true
```

Usage examples:
```
make install
make lint
make fix
make analyse
```

---
## 6. CI (Example Snippet)
GitHub Actions (if you adopt it): `.github/workflows/lint.yml`
```yaml
name: Lint & Analyse
on: [push, pull_request]
jobs:
  qa:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
      - uses: actions/setup-node@v4
        with:
          node-version: 20
      - run: composer install --no-interaction --prefer-dist
      - run: npm install --no-audit --no-fund
      - run: vendor/bin/phpcs --standard=phpcs.xml modules
      - run: vendor/bin/phpstan analyse modules --level=8 --memory-limit=512M
      - run: npx eslint "modules/**/stock/assets/js" --ext .js --max-warnings=0
```

---
## 7. Migration Steps
1. Place the root `composer.json`, run `composer install`.
2. Place root `phpcs.xml`; verify `vendor/bin/phpcs` runs clean on the Transfers module.
3. Add root `package.json` + install.
4. (Optional) Move module `.eslintrc.json` → delete after confirming root config works.
5. (Optional) Add Makefile & CI pipeline.
6. Remove any duplicate vendor directories in individual modules (keep only root vendor). 
7. Update internal docs to reference unified commands.

---
## 8. Verification Checklist
- `vendor/autoload.php` loads before module local fallback (check logs or `cis_shared_composer_autoload_paths()`).
- `composer outdated` shows a single set of dev tools.
- Running `vendor/bin/phpcs` from root produces results for multiple modules.
- ESLint run from root covers each intended module path.
- No module references a removed local vendor directory.

---
## 9. Rollback Plan
If any module breaks after unifying:
1. Restore its previous module-local `vendor/` backup (if taken) or run `composer install` inside that module (temporary).
2. Re-enable its local autoloader by placing `vendor/autoload.php` again.
3. Investigate namespace collision or dependency version mismatch.

---
## 10. Notes
- Keep dev tools pinned; periodically run `composer update --with-dependencies` in a dedicated branch.
- If Psalm adoption is phased, you can gate it behind a separate make target.
- Add `phpstan.neon` or `psalm.xml` at root only after baseline suppression set is curated.

---
Generated: 2025-10-02
