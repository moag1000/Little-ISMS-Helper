# FairyAurora Inventuren (Agent-Scans 2026-04-21)

Drei parallele Explore-Agents haben den Codebase vermessen, bevor FA-7/9/10
implementiert wurden. Ergebnisse hier persistiert, damit kommende Sessions
nicht neu scannen müssen.

---

## I. Chart.js-Call-Sites (für FA-9)

**11 primäre Quellen**: 3 Stimulus-Controller + 7 Inline-Template-Charts + 2 Macro-basiert.

### Stimulus-Controller (zentral)

| Datei | Chart-Typen | Farb-Config (aktuell) |
|-------|-------------|------------------------|
| `assets/controllers/chart_controller.js` | bar, line, pie, doughnut, radar, polarArea | `getThemeColors()` mit manual dark/light detection, hardcoded |
| `assets/controllers/trend_chart_controller.js` | line, bar, line | Hardcoded Bootstrap-Colors (#dc3545, #ffc107, #28a745) |
| `assets/controllers/radar_chart_controller.js` | radar | Hardcoded Bootstrap-Grün (#28a745/#34d399) |

### Inline-Template-Charts

- `templates/home/dashboard.html.twig:311-338` — doughnut + bar (Risk-Distribution + Asset-Kategorien)
- `templates/portfolio_report/index.html.twig:108-120` — dual-axis line (Reuse-Trend)
- `templates/analytics/compliance_frameworks.html.twig:~130` — bar
- `templates/analytics/risk_forecast.html.twig:~120` — line + bar + line (Forecast/Velocity/Appetite)
- `templates/analytics/control_effectiveness.html.twig:~100` — bar + pie + bar
- `templates/analytics/asset_criticality.html.twig:~100` — pie + scatter/bubble
- `templates/report_builder/preview.html.twig:~100` — heatmap + bar + line (Report-Builder-konfigurierbar)

### Top-3-Prio für FA-9

1. `trend_chart_controller.js` — 3 Charts, AJAX, 6+ Hex
2. `home/dashboard.html.twig` — 2 Charts, zentrale Seite
3. `portfolio_report/index.html.twig` — Dual-Axis mit Dash-Pattern

### Empfohlener Migrations-Weg

Zentrales `assets/chart-theme.js`-Modul mit:
- 5-Slot-Farb-Palette (Light + Dark via CSS-Var-Read)
- Globale Chart-Optionen (Grid, Tooltip, Axis, Legend)
- Pattern-Safe-Dashes-Helper (opt-in per User-Pref)

Die 3 Controller lesen dann aus dem Modul. Template-inline-Charts migrieren
zu Data-Attributes + CSS-Var-Read.

**FTE-Schätzung:** 2-3 Tage (Plan sagt 2, passt).

---

## II. Email-Templates (für FA-10)

**18 vorhandene + 4 fehlende = 22 Templates total**.

### Existing (18)

**Incident Mgmt:**
- `emails/incident_notification.html.twig` — 10 Hex, standalone
- `emails/incident_update.html.twig` — 7 Hex, standalone
- `emails/incident_escalation.html.twig` — 21 Hex, standalone (Hot-Spot!)

**GDPR/Compliance:**
- `emails/data_breach_notification.html.twig` — 20 Hex, standalone
- `emails/data_breach_deadline_reminder.html.twig` — 30 Hex, extends `base.html.twig` (**broken!**)

**Audit/Control/Training:**
- `emails/audit_due_notification.html.twig` — 7 Hex
- `emails/control_due_notification.html.twig` — 9 Hex
- `emails/training_due_notification.html.twig` — 7 Hex

**Risk:**
- `emails/risk_review_notification.html.twig` — extends base (**broken!**)
- `emails/risk_acceptance_request.html.twig` — 11 Hex
- `emails/risk_acceptance_approved.html.twig` — 8 Hex
- `emails/risk_acceptance_rejected.html.twig` — 9 Hex

**Workflow/Approval:**
- `emails/workflow_assignment_notification.html.twig` — 9 Hex
- `emails/workflow_notification_step.html.twig` — 8 Hex
- `emails/workflow_overdue_notification.html.twig` — 8 Hex
- `emails/workflow_deadline_warning.html.twig` — 8 Hex

**Review/Mapping:**
- `emails/review_reminder.html.twig` — 11 Hex, extends base (**broken!**)
- `emails/inheritance_source_updated.html.twig` — 9 Hex

### Missing (4 — aktuell im Code referenziert, nicht existent)

- `emails/treatment_plan_overdue.html.twig` (→ `RiskTreatmentPlanMonitorCommand`)
- `emails/treatment_plan_approaching.html.twig` (→ `RiskTreatmentPlanMonitorCommand`)
- `emails/treatment_plan_approval_notification.html.twig` (→ `RiskTreatmentPlanApprovalService`)
- `emails/document_approval_notification.html.twig` (→ `DocumentApprovalService`)

### Struktur-Analyse

- **Alle** verwenden inline `<style>` im `<head>` — keine externen CSS (Mail-Client-Compat).
- **Keine** CSS-Variablen.
- Struktur konsistent: `.header { background: #X }` + `.content { bg gray }` + `.footer`.
- Häufige Hex: `#dc2626` (Red/Danger), `#2563eb` (Blue/Workflow), `#f59e0b` (Orange/Warning), `#059669` (Green/Success).

### FA-10 Umsetzungs-Plan

1. **Schaffe `templates/emails/base.html.twig` ZUERST** — Aurora-Gradient-Header + neutral Light-only Body.
2. Migriere die 3 broken Extends (data_breach_deadline_reminder, review_reminder, risk_review_notification).
3. Batch-Patch der 18 Standalone-Templates: Header-Gradient-Replacement + CTA-Button-Farben-Migration.
4. Implementiere die 4 fehlenden Templates auf neuem Base-Pattern.
5. Smoke-Test in Mailtrap/Sendgrid-Preview.

**FTE revidiert:** Plan sagte 1,5 FTE-d → Realität **6,0 FTE-d**.

---

## III. Inline-Hex-Sweep (für FA-7)

**138 Templates gescannt** (ohne `email/` und `pdf/`).

### Top-5-Hot-Spots

| Datei | Hex-Count | Dominante Farben |
|-------|-----------|------------------|
| `templates/asset/qr_label.html.twig` | 18 | #1a1a1a, #ddd, #f5f5f5 (QR-Print) |
| `templates/asset/qr_labels_sheet.html.twig` | 16 | gleiche Palette |
| `templates/security/login.html.twig` | 10 | sichtbar + kritisch (FA-6 hat Vorrang!) |
| `templates/admin/layout.html.twig` | 10 (pre-FA-1b) → reduziert | global-impact |
| `templates/data_reuse_hub/heatmap.html.twig` | 8 | Gradient-Charts |

### Farb-Mapping-Schlüssel

| Legacy-Hex | → Aurora-Token |
|------------|----------------|
| `#1a1a1a`, `#222` | `var(--fg)` |
| `#ddd`, `#cbd5e0` | `var(--border)` |
| `#f8f9fa`, `#f5f5f5` | `var(--surface)` oder `var(--bg)` |
| `#06b6d4` | `var(--primary)` |
| `#ec4899`, `#7c3aed`, `#8b5cf6` | `var(--accent)` |
| `#dc2626`, `#dc3545`, `#ef4444` | `var(--danger)` |
| `#d97706`, `#f59e0b`, `#ffc107` | `var(--warning)` |
| `#10b981`, `#059669`, `#28a745` | `var(--success)` |

### Prio-Roadmap FA-7

- **Phase A — Critical Frontends** (3 Files, ~28 min): `security/login.html.twig`, `data_reuse_hub/heatmap.html.twig`, `admin/layout.html.twig`
- **Phase B — Asset/Printing** (2 Files, ~2h): qr_label + qr_labels_sheet (größte Hex-Dichte, aber Print-Context)
- **Phase C — Secondary** (~18 Files, ~6h): restliche Templates mit 2-6 Inline-Hex-Treffern
- **Skip — PDF + Email** (Phase D/E): eigene FA-10 / PDF bleibt neutral

### Vorbilder (schon Aurora-korrekt)

- `templates/_components/_slider.html.twig` — nutzt `var(--bs-secondary)`
- `templates/_components/_audit_timeline.html.twig` — `var(--primary)` inline
- `templates/admin/compliance/index.html.twig` — Utility-First ohne Hex

**FTE revidiert:** Plan sagte 3,0 FTE-d → Realität **2,0-2,5 FTE-d** (Plan über-scoped).

---

## Netto-FTE-Update

| Phase | Plan-FTE | Agent-Revisiert | Delta |
|-------|---------|-----------------|-------|
| FA-7 | 3,0 | 2,5 | -0,5 |
| FA-9 | 2,0 | 2,5 | +0,5 |
| FA-10 | 1,5 | 6,0 | **+4,5** |
| **Total** | 36,0 | **40,5** | **+4,5** |

FA-10 ist der dominante Treiber. Begründung: viele bisher unentdeckte
Templates + bestehender architektonischer Fehler (broken extends) +
Inline-CSS-Pattern pro Template (keine Vererbung).