# ADR-0003: FairyAurora v4 Custom Design System (Bootstrap 5.3 Extension)

**Status:** Accepted  
**Date:** 2025-12-01 (retroactive documentation)  
**Deciders:** moag1000  
**Tags:** frontend, design-system, ux, aurora, bootstrap

---

## Context

The application started as a Bootstrap 5.3 project with standard utility classes applied ad-hoc per
template. By Sprint 3, recurring problems emerged:

1. **Inconsistent card patterns:** Some card headers used `.bg-primary .text-white`, others relied
   on Bootstrap defaults, some used custom inline styles. Bootstrap's specificity model meant utility
   classes silently lost (see CLAUDE.md pitfall 11).
2. **No shared KPI tile vocabulary:** Risk dashboards, CISO cockpits, and BCM overviews all
   hand-rolled their own "stat box" markup. Four distinct implementations existed for what is
   semantically one component.
3. **Dark mode fragility:** Aurora tokens required both `--bs-X` and `--bs-X-rgb` companions for
   Bootstrap's `rgba()` internals. Without a curated token file, dark-mode regressions appeared in
   every sprint (pitfall 8 in CLAUDE.md).
4. **Compliance-specific UI needs:** ISMS UI patterns (risk heatmaps, 5×5 matrices, audit trail
   rows, approval stage visualisations, HMAC chain indicators) have no Bootstrap equivalent.
   Third-party component libraries (Flowbite, daisyUI, Tailwind-based) don't integrate cleanly with
   Symfony/Twig or require a full CSS paradigm switch.

Alternatives evaluated:

| Option | Verdict |
|---|---|
| Bootstrap 5.3 only, enforce stricter conventions | Insufficient — no Twig macro reuse; every sprint re-invents |
| Replace Bootstrap with Tailwind 3 + daisyUI | Major migration cost; Hotwire/Turbo integration untested |
| Adopt a third-party Symfony UI kit | None covers compliance-specific components (heatmaps, audit rows, GDPR wizards) |
| Extend Bootstrap with a curated token + macro layer | **Chosen** — additive, backward-compatible, Twig-native |

---

## Decision

**Build FairyAurora v4 as a curated extension of Bootstrap 5.3**, consisting of:

1. **CSS token layer** (`assets/styles/fairy-aurora.css`) — overrides `--bs-*` CSS variables for
   colour, typography, spacing, elevation. Single source of truth for theme tokens. Dark mode via
   `[data-bs-theme="dark"]` attribute on `<html>`.
2. **Component CSS** (`assets/styles/fairy-aurora-components.css`) — Aurora-specific component
   classes (`.fa-bulk-bar`, `.fa-entity-card`, `.fa-matrix-table`, `.fa-drawer`, etc.) using BEM
   naming to avoid Bootstrap conflicts.
3. **Twig macro library** (`templates/_components/_fa_*.html.twig`) — 38 macros covering every
   reusable UI pattern. Macros accept typed parameters, emit accessible HTML, and apply the correct
   Aurora CSS classes internally. Import pattern: `{% import '_components/_fa_X.html.twig' as _fa_X %}`.
4. **Stimulus controllers** for interactive behaviour (bulk selection, async progress polling,
   density toggle, glossary tooltips, etc.) following Hotwire conventions.
5. **Live component gallery** at `/dev/design-system` (dev env only) — copyable snippets for all
   38 macros.

The design system is named "FairyAurora" as an internal brand name. The "v4" suffix tracks the
Aurora major version; Bootstrap major version is tracked separately.

### Macro naming convention

| Prefix | Purpose |
|---|---|
| `fa-` | Aurora component macros |
| `_fa_` | Template file name prefix |
| `isms-` | ISMS-specific compound components |
| `.fa-bulk-bar` | BEM CSS class; old `.bulk-action-bar*` is a deprecated shim |

---

## Consequences

### Positive

- **Consistency by default:** New templates that `{% import %}` an Aurora macro cannot accidentally
  use the wrong card pattern. The macro enforces the correct structure.
- **A11y baseline:** Every macro is authored to WCAG 2.2 AA: `aria-label` on icon-only buttons,
  `scope` attributes on table headers, `role="status"` on progress indicators.
- **Design-system-first reviews:** PRs that add UI are reviewed against the live `/dev/design-system`
  gallery, not against memory. Visual regressions are caught before merge.
- **Stylelint gate:** `npm run stylelint` bans raw hex values in 14 color-valued CSS properties,
  forcing token usage. Exceptions: `fairy-aurora.css` (SoT) and `alva.css` (SVG brand fills).

### Negative

- **Onboarding overhead:** New contributors must learn Aurora macro signatures before writing
  templates. `docs/onboarding/02-architecture-tour.md` and CLAUDE.md §"Aurora v4 Components"
  document this, but it is an extra surface area.
- **Embed-scope trap:** Twig `{% embed %}` creates a new scope — file-scope macro imports are NOT
  visible inside embed blocks. `scripts/quality/check_twig_macro_scope.py` is a CI gate that
  catches this (pitfall 10 in CLAUDE.md). Contributors must re-import macros inside embed blocks.
- **Bootstrap specificity coupling:** Aurora component CSS must use `.card > .card-header` selector
  patterns (specificity 0,2,0) to match Bootstrap's own selectors. Plain `.card-header` (0,1,0)
  loses. This is documented in CLAUDE.md pitfall 7.
- **Maintenance:** As Bootstrap releases 5.4 / 6.0, token variable names may change, requiring
  Aurora token file updates. Bootstrap `--bs-*-rgb` companion variables are the most likely
  breaking point.

---

## References

- `assets/styles/fairy-aurora.css` — token source of truth
- `assets/styles/fairy-aurora-components.css` — component classes
- `templates/_components/` — all 38+ macro files
- `templates/_components/_CARD_GUIDE.md` — anti-patterns for `.card` usage
- CLAUDE.md §"Aurora v4 Components" — full macro catalogue
- CLAUDE.md pitfalls 7, 8, 10, 10b, 11
- `scripts/quality/check_twig_macro_scope.py` — CI gate
- `/dev/design-system` — live gallery (dev env)
