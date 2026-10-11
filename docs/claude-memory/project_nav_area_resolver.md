---
name: project_nav_area_resolver
description: "Menu active-area + breadcrumb root both keyed by route prefix; single nav_active resolver in _mega_menu; breadcrumb crumbs link ONLY via url: key"
metadata: 
  node_type: memory
  type: project
  originSessionId: 8524ab1e-7912-42ad-be81-bb1c9410a3e9
---

Post–Wave-5 navigation cleanup (2026-05-31, PRs #788/#789).

**Menu active-area** is decided in `templates/_components/_mega_menu.html.twig` by a
single ORDERED resolver: `{% set nav_active %}` + `_nav_map` list of
`{ p: '<route-prefix>', c: '<area>' }`, most-specific-first, first-match-wins
(e.g. `app_audit_log_` before `app_audit_`; persona `app_dashboard_*` before
generic `app_dashboard`). Each L1 button checks `nav_active == '<area>'`.
**When adding a flyout link, add its route prefix to `_nav_map` in the right
position** — otherwise the page highlights NO area (the old 11-independent-
`starts with`-blocks bug that left ~24 routes unhighlighted + double-lit others).
Panel membership in `_mega_menu_panel_only.html.twig` is the source of truth for
which area a route belongs to. Non-panel landing routes (`app_bcm_*`,
`app_reports_*`) must still be added to the resolver.

**Breadcrumb gotcha:** `templates/_components/_breadcrumb.html.twig` renders a
crumb as a LINK **only from `crumb.url`**. `{ label, path: '…' }` or
`{ label, href: path('…') }` → the crumb is silently UNLINKED plain text. Always
use `{ label: '…'|trans(...), url: path('route') }`. ~50 templates had this dead-
link bug. NOTE: a dead `path:'x'` string is harmless even if `x` is invalid, but
converting to `url: path('x')` THROWS (500) if the route doesn't exist — always
`debug:router` a route before wrapping it in `path()`.

Related: [[feedback_locale_in_links]]
