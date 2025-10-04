# Static Analysis & Quality Gate Proposal

Objective: Introduce layered static analysis (PHPStan + Psalm optional) with minimal friction and shared configuration.

## Tools
| Tool | Purpose | Mode |
|------|---------|------|
| PHPStan | Type inference + dead code + API misuse | Required |
| Psalm | Deeper type & taint flow (optional phase 2) | Optional |
| PHP_CodeSniffer | Style & basic structural rules | Existing |
| ESLint | JS syntax, quality rules | Existing |

## Recommended Root Files
`phpstan.neon.dist`
```neon
parameters:
  level: 7
  paths:
    - modules
  tmpDir: var/cache/phpstan
  excludePaths:
    - */backups/*
  checkMissingIterableValueType: true
  checkGenericClassInNonGenericObjectType: true
  reportUnmatchedIgnoredErrors: true
  memoryLimit: 512M
  ignoreErrors:
    - '#@psalm-suppress#'
```

`psalm.xml` (only when ready)
```xml
<?xml version="1.0"?>
<psalm errorLevel="6" resolveFromConfigFile="true" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="https://getpsalm.org/schema/config">
  <projectFiles>
    <directory name="modules"/>
    <ignoreFiles>
      <directory name="*/backups"/>
    </ignoreFiles>
  </projectFiles>
  <issueHandlers>
    <MixedAssignment errorLevel="info"/>
  </issueHandlers>
</psalm>
```

## Makefile Additions
```makefile
analyse-types: ## Run phpstan
\tvendor/bin/phpstan analyse --configuration=phpstan.neon.dist

analyse-psalm: ## Run psalm (if adopted)
\tvendor/bin/psalm --shepherd --stats || true
```

## CI Gate Strategy
Phase 1: Fail build on PHPStan level 5+; report (not fail) Psalm.
Phase 2: Raise PHPStan to 7/8, begin fixing high-signal Psalm issues.
Phase 3: Enforce Psalm errorLevel 4 or better.

## Rollout Plan
1. Commit templates (above) at root.
2. Run baseline: `vendor/bin/phpstan analyse` – capture initial issue count.
3. Create `phpstan-baseline.neon` if noise high; reduce over time (strict ratchet).
4. Add CI step (non-blocking first 48h) then enforce.
5. Introduce Psalm only after PHPStan noise < target threshold (e.g., <150 issues).

### Automation Aid
Use `modules/transfers/tools/phpstan_baseline_manager.php` to generate / update / verify baseline programmatically (JSON output for dashboards / CI parsers).

## Success Criteria
- CI pipeline surfaces 0 new PHPStan errors per PR.
- Drift detector shows zero per-module lint configs.
- Tooling health endpoint returns chosen autoload + tool versions.

Generated: 2025-10-02