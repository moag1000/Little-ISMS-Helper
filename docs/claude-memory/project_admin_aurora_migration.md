---
name: Admin Panel Aurora-V4-Migration
description: Komplette FairyAurora-v4-Migration aller Admin-Templates über 6 Sprints; Hub ist primärer Admin-Entry-Point.
type: project
originSessionId: 51278c80-8f53-4e22-ae77-1c110a277764
---
6-Sprint-Plan vollständig abgeschlossen. Sämtliche Templates unter
`templates/admin/` (60 Dateien) sowie `templates/role_management/` (6 Dateien)
nutzen FairyAurora v4 Vokabular. Null `_components/_card.html.twig`-Embeds in
Admin-Bereich. Hub-Landing ist primärer Mega-Menu-Eintrag.

**Why:** Admin-Sub-Templates waren halb-Aurora mit Bootstrap-Resten. User wollte
durchgängige Aurora-Migration plus Hub-Card-Landing aus Design-System.

**How to apply:** Bei neuen Admin-Templates IMMER folgendes Vokabular nutzen:
- `{{ _fa_page_header.render({...}) }}` für Page-Header (badge / title / subtitle / actions)
- `<section class="fa-section">` mit `__header` / `__body` / `__footer` Slots
- `_fa_empty_state.render({...})` für leere Listen
- `_fa_kpi_card` / `_fa_feature_card` für KPI-Tiles
- `_fa_perm_matrix` / `_fa_audit_row` / `_fa_api_key` / `_fa_svc_tile` /
  `_fa_skel` / `_fa_confirm` für die Sprint-3-Komponenten
- `fa-cyber-btn fa-cyber-btn--variant` (primary/ghost/success/warning/danger/info)
  statt `btn btn-primary`
- Raw `<span class="badge bg-...">` statt `_components/_badge.html.twig`
- Aurora form-theme global via config/packages/twig.yaml — kein lokales
  `{% form_theme %}` setzen
- KEINE `bg-*` / `text-white` auf `.card` / `.card-header` (CLAUDE.md
  Pitfall #9 — Aurora gewinnt by load-order)
- Raw `<table class="table table-hover">` in `<div class="table-responsive">`
  statt `_components/_table.html.twig` Embed

## Commits (Sprint-Reihenfolge)
- `ddabab5e` — Sprint 1 Hub-Landing (AdminHubCatalog + HubController + Macro + CSS)
- `51270b34` — Sprint 2 Top-Subs (dashboard, modules, settings, sso, tenants/form)
- `3d811d30` — Sprint 3 Aurora-Komponenten (6 Macros + 2 Stimulus-Controller + Tests)
- `b8eca59a` — Sprint 4 Resterechner (15 Templates: forms, imports, standalones)
- `bad24f1d` — Sprint 5 role_management (5 Templates)
- `4c1164d7` — Sprint 6 Admin-Cleanup (29 Templates: tenants, compliance,
  settings, licensing, mapping_quality, data_repair etc.)
- `d0ba022d` — Hub-Promotion: Mega-Menu primärer Eintrag = Hub, Layout-
  Breadcrumb verlinkt Hub statt Dashboard

## Sprint-3 Komponenten (verfügbar zur Nutzung)
- `_fa_perm_matrix.html.twig` — RBAC Modul × Rolle × Action
- `_fa_audit_row.html.twig` — Audit-Log mit sealed/failed Tones
- `_fa_api_key.html.twig` — Token-Reveal mit Masking + `api_key_reveal` Stimulus
- `_fa_svc_tile.html.twig` — Service-Health (ok/warn/err/off)
- `_fa_skel.html.twig` — Skeleton-Loader (5 Varianten)
- `_fa_confirm.html.twig` — Confirm-Dialog warn/danger/nuclear + `fa_confirm` Stimulus
- 6 Test-Cases in `AdminPanelMacrosTest` — 21 Assertions

## Hub-Architektur
- Service: `App\Service\Admin\AdminHubCatalog` — 7 IA-Gruppen × 36 Module,
  tone-coded (cyan/pink/purple). Test: `AdminHubCatalogTest` (5 Cases, 287
  Assertions).
- Controller: `App\Controller\Admin\HubController` — Route `/admin/hub`
  named `admin_hub_index`. Module mit nicht-existenter Route werden
  auto-flagged als `coming_soon: true`.
- Template: `templates/admin/hub.html.twig` mit Search-Input.
- Macro: `templates/_components/_fa_admin_hub_card.html.twig`.
- CSS: `assets/styles/admin-panel.css` (878 Zeilen aus DS).
- Stimulus: `admin_hub_search_controller.js` Client-Side-Filter.
- Mega-Menu: `_mega_menu_panel_only.html.twig` listet Hub als primären
  Overview-Eintrag, Legacy-Dashboard als Sekundär.
- Layout-Breadcrumb: `admin/layout.html.twig` Home-Crumb verlinkt
  `admin_hub_index`.

## Status
4385 Tests / 12643 Assertions / 0 Failures / 0 Errors. Plan abgeschlossen.

Offen (kein Plan): Old `/admin` Route bleibt absichtlich erhalten für
Bookmark-Kompatibilität + Promo-Banner-Pfad. Dashboard-Template hat Hub-
Promo-Banner. Kein Hard-Switch.
