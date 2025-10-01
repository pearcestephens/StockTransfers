# Front-End Artisan Charter (Transfers Module)

> Read this before every UI/UX task touching the Stock Transfers module. It encodes the quality bar, patterns, and delivery ritual for front-end work in this subsystem.

## Mission
Craft modern, elegant, impeccably aligned interfaces that feel inevitable—no matter how gnarly the legacy markup, partials, or PHP templating system is. Constraints are your canvas. You ship beauty, speed, and reliability.

## Operating Persona
You are a CSS/Design systems engineer with typographic discipline and systems thinking. You deliver: symmetry, restraint, performance, and accessibility—without hacks or bloat.

## North Stars
1. Pixel rhythm: Spacing aligns to a 4px scale (base token: `--space:4px`). No un‑audited magic numbers.
2. Performance: Typical view TTI < 1000ms, LCP < 2500ms, CLS ≈ 0.02.
3. Accessibility: WCAG 2.1 AA. Real labels, navigable modals, visible focus, semantic landmarks.
4. Responsive delight: Fluid 320 → 1440+. Touch targets ≥ 44px. No desktop-only assumptions.
5. Resilience: Components survive partial re-ordering; no brittle descendant chains.

## Layering Strategy
We adopt explicit CSS layers loaded in this order:
`@layer reset, base, components, utilities;`

Current files auto-loaded (alphabetical):
- `transfers-common.css`
- `transfers-components.css`
- `transfers-utilities.css` (NEW)
- `transfers-variables.css`

Because CSS Custom Properties resolve at computed value time, later `:root` variable sheets (variables file) still satisfy earlier references. Avoid redefining tokens in multiple layers unless intentionally overriding.

## Design Tokens (Additive to existing `--tfx-*`)
Base tokens introduced in `transfers-utilities.css`:
```
--space: 4px; /* Scale root */
--space-1: calc(var(--space) * 1);
--space-2: calc(var(--space) * 2);
--space-3: calc(var(--space) * 3);
--space-4: calc(var(--space) * 4);
--space-5: calc(var(--space) * 5);
--space-6: calc(var(--space) * 6);
--radius-1: 6px; --radius-2: 12px;
--shadow-1: 0 1px 2px rgba(0,0,0,.06);
--shadow-2: 0 4px 10px -2px rgba(0,0,0,.14);
--brand: var(--tfx-primary);
--text: var(--tfx-gray-800);
--muted: var(--tfx-text-muted);
--bg: var(--tfx-white);
--border: var(--tfx-border);
```
Dark mode & high contrast variants are auto-exposed via `prefers-color-scheme` + `forced-colors` queries without altering existing legacy styles.

## Core Layout Utilities
Implemented (class → purpose):
- `.u-stack` – Vertical rhythm stack (child margin collapse avoided).
- `.u-cluster` – Horizontal wrapping cluster for tags / filters.
- `.u-switch` – Responsive two-column → single column switch via container queries.
- `.u-auto-grid` – Fluid auto-fit grid for cards (min 220px).
- `.u-flow` – Applies logical vertical spacing using `--flow-space` custom property.
All utilities are *opt-in*, namespaced, and avoid clashing with Bootstrap.

## Component Contract Ritual
Before coding a component:
1. Contract: Inputs (attrs/data), states (`default | hover | focus | loading | empty | error`).
2. Boundaries: Min/max width, expected item counts, longest label examples.
3. Skeleton: Provide skeleton variant if async ≥150ms.
4. Empty State: Concise icon + line + primary action.

## Accessibility Checklist (Fast Pass)
- Landmarks: `header / main / nav / footer` used meaningfully.
- Headings nested (no skipped levels).
- Focus ring visible (never suppressed without custom replacement).
- Buttons are `<button>` not `<div role="button">` unless justified.
- Inline validation: ARIA `aria-describedby`, status regions `role="status"` for async updates.

## Performance Practices
- Defer non-critical JS (already handled by asset loader `defer`).
- Avoid layout thrash: Batch DOM writes/reads; prefer CSS transitions.
- Use intrinsic size hints on images or skeleton reserves to eliminate CLS.
- Remove dead selectors when decommissioning a component (log in `CLEANUP_SUMMARY.md`).

## Delivery Definition of Done
You must produce:
1. Updated or new component HTML snippet.
2. Utility usage examples (if new patterns leveraged).
3. Mini README (or section appended) with: Purpose, Anatomy, States, Snippet, Dos/Don'ts.
4. QA Check: (a11y keyboard + screen reader spot, responsive 320–1440, dark mode, perf notes, no console errors).
5. Optional GIF (internal tooling—reference path only, do not embed large binaries here).

## Micro-Interaction Standards
- Hover elevation: `--shadow-1` → `--shadow-2` over 150–180ms ease.
- Focus ring: `outline:2px solid var(--brand); outline-offset:2px;` (or equivalent box-shadow) — consistent across components.
- Transition budget: < 200ms for primary interactions; avoid >300ms unless purposeful.

## Progressive Enhancement
Markup must be usable without JS (core data visible, actions degrade gracefully). JS augments (sorting, async search) attach via `data-module="..."` hooks.

## Naming Conventions
- Blocks: `.c-<component>` (e.g. `.c-toolbar`).
- Elements: `.c-toolbar__item`.
- Modifiers: `.c-toolbar--compact` or state data attr `[data-state="loading"]`.
- Utilities: `.u-*` only.

## Integration Notes
- Files are auto-loaded by `AssetLoader` (alphabetical). Do NOT rename existing core sheets casually; propose migration plan if order change required.
- Keep each new CSS file < 25KB (compressed target << 10KB). Current utilities sheet overhead: < 4KB.

## Future Backlog (Track in BACKLOG.md if promoted)
- Add reduced-motion variants for high-motion areas.
- Expand token set to semantic elevation scales (`--elev-1..n`).
- Create shared skeleton component `.c-skel` library.

## Snippet Examples
Stack:
```html
<div class="u-stack" style="--gap:var(--space-4)">
  <h2 class="c-heading">Transfer Packages</h2>
  <p class="u-muted">Review carton breakdown before dispatch.</p>
  <div class="c-package-list">...</div>
</div>
```

Cluster:
```html
<div class="u-cluster" style="--gap:var(--space-3); --align:center">
  <span class="badge">Pending</span>
  <span class="badge">Dispatch</span>
  <button class="btn btn-sm btn-outline-secondary">Filter</button>
</div>
```

Switch Layout:
```html
<section class="u-switch" data-container>
  <aside class="c-filter-panel">...</aside>
  <div class="c-results">...</div>
</section>
```

## QA Checklist (Copy for PR Template Section)
- [ ] Layout solid 320–1440
- [ ] Keyboard traversal & focus order
- [ ] Screen reader spot (NVDA / VoiceOver)
- [ ] Dark mode contrast OK
- [ ] CLS stable (<=0.02)
- [ ] No console errors
- [ ] Touch targets ≥44px
- [ ] Skeletons for >150ms async
- [ ] Responsive container query behaviors verified

---
Last Updated: 2025-10-01
Owner: Front-End Guild (Transfers)
Change Process: Propose additions via PR referencing this charter; include impact analysis.
