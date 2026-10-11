---
name: feedback_css_import_barrel_fouc
description: Aurora-CSS NIE als ein @import-Barrel-<link> laden — serieller @import-Wasserfall = FOUC. Parallele <link> nutzen.
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 1fb61597-8623-4c7b-a0d3-0b9024b54460
---

`assets/styles.css` ist ein 27-fach-`@import`-Barrel. Als **ein** `<link>` geladen entdeckt der Browser die Layer-Files erst nach Parse → serieller `@import`-Wasserfall → FOUC bei jeder Turbo-Navigation (Content malt mit Bootstrap-only, dann greifen Aurora-Layer).

**Why:** CSS `@import` parallelisiert NICHT wie mehrere `<link>`. Bootstrap (1 self-contained File) flasht nie, Aurora (Barrel) immer.

**How to apply:** Aurora-Layer als parallele render-blocking `<link>` emittieren — Shared-Partial `templates/_aurora_styles.html.twig` (27 Links, Cascade-Reihenfolge spiegelt `assets/styles.css`, Token-Layer zuerst). Included in den 3 Base-Templates `base.html.twig` · `base_auth.html.twig` · `setup/_layout.html.twig`. `importmap()` nur noch `['app']` (JS); `styles`-CSS-Entrypoint aus `importmap.php` raus. Beim Hinzufügen eines `@import` in styles.css: Eintrag im Partial spiegeln (sonst Layer fehlt). PR #795, merged 2026-05-31.

Verifikation immer am **gerenderten HTML** (dev-Server curl): Link-Position `<head>`, Leaf-CSS resolved 200 ohne nested @import, Barrel-`<link>` weg. NICHT nur grep am Source — kostete 2 Blind-Breaks. [[feedback_pre_pr_local_checks]]
