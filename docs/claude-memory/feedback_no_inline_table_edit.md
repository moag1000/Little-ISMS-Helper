---
name: feedback_no_inline_table_edit
description: "Keine Inline-Tabellen-Edit-Funktion verwenden — Edits laufen über Drawer/Form (fa-drawer), nicht direkt in Tabellenzellen."
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 1fb61597-8623-4c7b-a0d3-0b9024b54460
---

**Inline-Tabellen-Edit (direkt in der Zelle editieren) NICHT verwenden.** Gilt insb. für das
Planungs-/Ressourcen-Modul (Maßnahmenplanung · Kapazitäts-Roadmap · Stammdaten — Tasks/Teams/Personen),
aber als generelle UX-Regel zu behandeln.

**Why:** User-Entscheidung 2026-06-06. Inline-Edit-Grids sind fehleranfällig/unklar; das Design-System
hat dafür bessere Patterns.

**How to apply:** Edits über **`fa-drawer`-Formular** (Zeile klicken → Drawer mit eigenem Speichern/Abbrechen,
wie die Maßnahmenplanung im Design `docs/design_system/sections/planning-roadmap.html` als Referenz zeigt)
oder normale Form-Seite. Die `fa-savebar` (schwebende Speichern-Leiste) bleibt OK für gestagete Änderungen
in Planungs-Grids (z.B. Kapazitäts-KW-Planung), aber NICHT als Träger einer Inline-Zell-Edit-Tabelle.
Bezug: [[project_market_positioning_review]] (Ressourcenplanung-Feature Cl 6.2/7.1).
