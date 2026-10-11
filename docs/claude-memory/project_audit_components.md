---
name: audit-components
description: Zwei distinct Aurora-Komponenten für Audit/History UIs. Pro Zusammenhang entscheiden.
metadata: 
  node_type: memory
  type: project
  originSessionId: 00707aab-5219-4853-97ae-8ba832ca1266
---

Aurora-Trennung zwischen zwei Audit-/History-Komponenten:

**`.isms-trail`** (existierend, 14 CSS-Rules):
- Für: audit-relevante **Zeitabläufe** / Timeline-Pattern
- Visual: Vertical-Timeline mit Dots + Heads + colored stripes
- Use-Cases: Compliance-Milestones, Cert-History, periodische Reviews, mehrjährige Audit-Pfade
- Markup: `<div class="isms-trail">` + `<article class="isms-trail__entry">` + `isms-trail__dot--<tone>` + `__head` + `__title` + `__meta`

**`.fa-audit-feed` / `.fa-audit-row`** (canonical, in docs/design_system/admin-panel.css 430-515):
- Für: **entity change-log** (CRUD-Auditing-Listen)
- Visual: Flat 7-col grid (ts | sev-dot | actor | action | target | hash | pill)
- Use-Cases: Entity-Show-Page Audit-Sections, globale Audit-Log-Tables, User-Activity-Reports
- Markup: `<div class="fa-audit-feed">` + `<div class="fa-audit-row">` + `__ts/__sev/__actor/__action/__target/__hash/__pill` + Modifier `--sealed/--failed`
- Severity: `--info/--warning/--critical/--danger` als dot-color

**Entscheidung pro Kontext:**
- Entity change-log → `.fa-audit-row`
- Compliance/Cert/Review-Zeitablauf → `.isms-trail`

**Migration-Status** (Stand 2026-05-18):
- ✓ 6 entity show-pages (asset/audit/incident/risk/risk_appetite/risk_treatment_plan) → `.fa-audit-row` (PR #480)
- ❌ audit_log/entity_history.html.twig — nutzt `.isms-trail`, ist aber change-log → migrate auf `.fa-audit-row`
- ❌ audit_log/index.html.twig — nutzt `<table>`, ist change-log → migrate auf `.fa-audit-feed`
- ❌ audit_log/user_activity.html.twig — nutzt `<table>`, ist change-log → migrate auf `.fa-audit-feed`

User-Regel: "alle wo es Rows gibt müssen das neue Styling nutzen — pro Zusammenhang entscheiden, dann alles andere darauf migrieren".
