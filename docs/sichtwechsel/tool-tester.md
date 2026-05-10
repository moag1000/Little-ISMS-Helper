# Tool-Tester-Sicht — Reale Umsetzung, i18n, UX-Konsistenz

> **Wer:** QA-Engineer mit 3–7 Jahren Erfahrung, davon 1–2 Jahre in GRC-Umfeld. ISMS-Basics: liest Klauseln + Artikel, kennt Annex-A-Controls als Konzept, Risiko-Behandlung in groben Zügen.
> **Denkweise:** "Funktioniert es WIRKLICH?" — Tool benutzen, Edge-Cases provozieren, Effizienz messen. Kein Berater-Tiefe — bei Norm-Auslegung fragt er den Compliance-Manager.
> **Frust-Trigger:** Hardcoded Text, Übersetzungs-Drift, Aurora-Inkonsistenzen, Empty-States ohne CTA, Dashboard-Drift (KPI ≠ Liste), Permissions-Lücken.
>
> Berichtsweg: Compliance-Manager (Test-Charters rein, strukturierte Bug-Listen raus). Schnittstellen: UX-Specialist, ISB.
>
> Volle Persona-Definition: [`.claude/skills/persona-tool-tester`](../../.claude/skills/persona-tool-tester/)

[← Zurück zur Übersicht](README.md)

---

## Aurora-Design-System (Component-Showcase)

Live-Preview aller Aurora-V4-Komponenten. Pflicht-Sicht für Tester: jede neue UI-Komponente muss hier 1:1 passen, keine Bootstrap-Default-Inseln.

![Aurora Design System](img/tool-tester/design-system.png)

> *"Ich klicke jede Komponente durch — wenn die Live-Verwendung im Modul anders aussieht als hier, ist das Drift."*

Referenzdoku: [`docs/design_system/`](../design_system/) + [`templates/_components/_*.html.twig`](../../templates/_components/) + [_CARD_GUIDE.md](../../templates/_components/_CARD_GUIDE.md). Nur dev-env reachable via `/de/dev/design-system`.

---

## Mapping-Quality-Dashboard

Cross-Framework-Mapping-Qualität als KPI. Konfidenz-Verteilung, Lifecycle-State (draft → review → approved → published), Provenance-Vollständigkeit.

![Mapping Quality](img/tool-tester/mapping-quality.png)

> *"Mappings unter Konfidenz X — wer reviewt? Welche haben keinen Provenance-Block?"*

Hier hängt der Tester am Compliance-Manager: er findet die Lücken, der CM steuert die Korrektur.

---

## Mapping-Coverage (alle Frameworks)

Welche Anforderungen jedes Frameworks sind durch Mappings abgedeckt? Welche transitiv? Welche gar nicht?

![Mapping Coverage](img/tool-tester/mapping-quality-coverage.png)

Critical-Befund-Quelle: Wenn ein Framework-Stamm-Control ohne Cross-Mapping bleibt, ist Data-Reuse kaputt.

---

## Data-Repair-Tools

Admin-Werkzeug für Schema-Drift, verwaiste Entities, Tenant-Mismatches, Duplikate. Tester provoziert Inkonsistenzen + verifiziert Fix.

![Data Repair](img/tool-tester/data-repair.png)

Test-Schwerpunkte:
- Schema-Reconcile-Lauf (Dry-Run + echt)
- Orphan-Asset-Zuordnung
- Tenant-Mismatch-Fix (Multi-Tenant-Isolation-Test)
- Dup-Resolver pro Entity

---

## Loader-Fixer

Idempotentes Nachladen aller Framework-Loader (22 Stück). Tester checkt: läuft jeder einzeln durch? Idempotent? Kein Daten-Verlust bei Re-Run?

![Loader Fixer](img/tool-tester/loader-fixer.png)

---

## Admin-Settings (Application + Features)

Application-, Security-, Feature-Toggle-Konfiguration. Tester prüft: aktiviert/deaktiviert Toggle wirklich, was Doku verspricht?

![Admin Settings](img/tool-tester/admin-settings.png)

![Admin Settings — Features](img/tool-tester/admin-settings-features.png)

---

## Tour-Content-Admin

Persona-spezifische Tour-Inhalte (Junior, ISB, CISO, Risk-Owner) admin-pflegbar. Tester prüft i18n-Parität DE/EN + UX-Flow.

![Tour Content Admin](img/tool-tester/tour-content-admin.png)

---

## EN-Locale-Sanity-Check

Sprachumschalter-Test. Jede DE-Seite muss EN-Pendant haben, übersetzt, ohne hardcoded-DE-Inseln.

![EN-Dashboard](img/tool-tester/en-locale-dashboard.png)

![EN-SoA](img/tool-tester/en-locale-soa.png)

> *"In `translations/risk.de.yaml` heißt es 'Eintrittswahrscheinlichkeit', im EN-Form-Label 'Likelihood'. Konsistent? Ja. Aber EN-Tooltip sagt 'Probability' — Drift."*

Tooling: [`scripts/quality/check_translation_issues.py`](../../scripts/quality/check_translation_issues.py) findet Hardcoded-Text + fehlende Domains.

---

## Querverweise

- **Aurora-Komponenten-Doku**: [`docs/design_system/`](../design_system/)
- **Translation-Quality-Tool**: [`scripts/quality/check_translation_issues.py`](../../scripts/quality/check_translation_issues.py)
- **Common Pitfalls (Aurora-Specificity)**: [`CLAUDE.md` Common Pitfalls #7–9](../../CLAUDE.md)
- **Compliance-Manager** (Vorgesetzter, Test-Charters): [Compliance-Manager-Sicht](compliance-manager.md)
- **UX-Specialist-Skill**: `.claude/skills/ux-specialist/`

---

## Was der Tool-Tester vermisst

Aus der [Persona-Definition](../../.claude/skills/persona-tool-tester/):

- **Translation-Drift-Diff-Report** als CI-Job (DE↔EN-Begriffs-Konsistenz)
- **Aurora-Konformität-Linter** über alle Templates (auto-detect Bootstrap-Override-Inseln)
- **Permissions-Matrix-Test** automatisiert pro Rolle (heute manuell-explorativ)
- **Dashboard-vs-Liste-Drift-Detector** (KPI-Zahl ≠ Liste-Count = automatisierter Befund)
- **Mapping-Provenance-Pflicht** beim Anlegen — verhindert Mappings ohne Methodik-Begründung

---

[← Externer Auditor](auditor-external.md) · [Zurück zur Sichtwechsel-Übersicht](README.md)
