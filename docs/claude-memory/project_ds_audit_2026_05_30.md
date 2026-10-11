---
name: project_ds_audit_2026_05_30
description: "Design-System Audit-Stand 2026-05-30 — AUDIT_TODO + APP_DELTA stale, DS-Fill-Liste an Design-Agent übergeben, App→Aurora-Backlog offen"
metadata: 
  node_type: memory
  type: project
  originSessionId: 1fb61597-8623-4c7b-a0d3-0b9024b54460
---

Tiefer Vergleich `docs/design_system/` ↔ App am 2026-05-30 (branch feat/eu-mapping-audit, App main@d03ece63e).

**Beide Audit-Docs stale (3-Wege-verifiziert: Template + JS + PHP-Grep):**
- `AUDIT_TODO.md` = 100% tot. Body gegen `main@11ac15d1` (echt kaputt), Header-Note gegen `e9abbb0` drangeklebt — aber bei e9abbb0 war schon alles fixed. Alle 2 Hoch + 6 Mittel resolved. Einziges echtes Rest: `ui-components.css:2499` Slate-Glow `rgb(100,116,139,0.3)` hardcoded.
- `APP_DELTA.md` = ~80% valide. Falsch: `_badge` "reines Bootstrap" (ist Dual-Class-Bridge), `fa-deadline-counter` "Orphan" (live in incident/show), §1-Größen (DS-Mirror jetzt 278KB nicht 258). Richtig: 3 Macro-Generationen, `_bulk_action_bar`-Widerspruch, fa-cmdp Reverse-Drift, Naming-Drift.

**Orphan-Methodik-Lehre:** CSS-Existenz ≠ Nutzung. Echter Test: `grep -rl klasse templates/` (0=Orphan) + Macro-Consumer zählen (Macro-File selbst ≠ Nutzung). 10 echte tote CSS-Familien gefunden (fa-framework-lockup, fa-confirm-modal, fa-aurora-bg, fa-brand-gradient, fa-focus-ring, fa-text-meta, fa-bulk-badge, fa-floating-toolbar, fa-skel-row, fa-setup-legacy-wrapper). **User will tote NICHT löschen** — Aurora darf ungenutztes haben.

**Ziel (User):** DS = Kanon, App soll Aurora bevorzugt IMMER anwenden. Klassen-NAMEN = App-CSS (Runtime-Vertrag), DS muss App-Namen dokumentieren.

**An Design-Agent übergeben (Update kommt später):** Liste fehlender DS-Karten (20 live fa-* ohne Karte: fa-owner-picker, fa-team-role-builder, fa-severity-*, fa-modal[53 Templates], fa-tabs, fa-form-layout, fa-settings-table, fa-matrix-table, fa-audit-row/-feed/-program-timeline, fa-maturity-heat-map, fa-escalation/subcontractor-chain, fa-norm-bridge, fa-risk-mini-heatmap, fa-json-builder, fa-success-criteria, fa-resource-list) + Naming-Drift-Tabelle (Phantom→Kanon: fa-btn→fa-cyber-btn, fa-pill→fa-status-pill, fa-audit-timeline→fa-audit-row, fa-quick-action-button→fa-quick-action-btn, fa-industry-baseline-chip→fa-industry-chip, fa-modal-confirm→fa-modal--confirm). DS-Baum intern inkonsistent: Update ergänzte App-Namen, ließ Phantoms in sections/feedback-systems.html + welle3-phase2-3.html (naming-bridge.html = bewusste Bridge, lassen).

**App→Aurora-Backlog offen (Bootstrap-Reste in Templates, je Files):** raw `class="card"` 71 · `badge bg-*` 57 · `btn btn-*` 50 · `alert alert-*` 23 · `progress` 18 · `table table-*` 9 · `bi bi-*` 5 · Emoji 65 · command-palette Legacy (0 fa-cmdp, 24 legacy — DS voraus) · `_macros/` 4 Bootstrap-Macros. Sauber: FontAwesome 0, Bootstrap-Modals 0.
