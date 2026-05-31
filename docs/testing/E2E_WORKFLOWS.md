# End-to-End ISMS-Workflows (Test-Szenarien)

Vollständige Arbeitsabläufe — nicht einzelne Formulare, sondern die Ketten, die
ein ISB / Berater im Alltag durchläuft. Jeder Workflow ist als reproduzierbares
Test-Szenario formuliert: Route → Eingaben → erwartetes Ergebnis → Akzeptanz.

**Design-Linse:** Der *Senior-Berater* entwirft den realistischen Ablauf
(Methodik, Reihenfolge, Wiederverwendung). Der *Junior-ISB* annotiert jeden
Schritt konkret („was trage ich wo ein, warum"). So sind die Workflows zugleich
fachlich korrekt und mechanisch ausführbar.

**Test-User:** `p.nis@example.com` / `Admin123!` (Tenant 1, Admin, Seed-Daten).
Routen sind locale-prefixed (`/de/...`).

---

## Workflow 1 — Internes Audit → Feststellung → Korrekturmaßnahme → Wirksamkeit

**Norm:** ISO 27001 Cl. 9.2 (Internes Audit) + 10.1/10.2 (Nonconformity & CAPA),
ISO 19011 (Audit-Durchführung). **Status: live verifiziert** (Audit/1 →
Findings/2,3 → CAPA/3, alle verknüpft).

**Berater-Sicht:** Der klassische Audit-Zyklus *Planung → Durchführung →
Befund → Maßnahme → Nachweis*. Wiederverwendung: ein Audit erzeugt N
Feststellungen, jede Feststellung 0..N Maßnahmen — einmal erfasst, durchgängig
verknüpft.

**Junior-ISB:** Reihenfolge ist zwingend — eine Feststellung *braucht* ein
Audit (wie ein 9001-Abweichungsbericht ein Audit braucht), eine Maßnahme
*braucht* eine Feststellung. Ohne Audit zeigt `/audit-finding/new` einen
Empty-State („Zuerst ein Audit anlegen") statt einer toten Form.

| # | Schritt | Route | Kern-Eingaben | Erwartet |
|---|---|---|---|---|
| 1 | Audit planen | `/de/audit/new` | `internal_audit[title]`, `plannedDate`, scopeType | 302 → `/de/audit/{id}` |
| 2 | Feststellung erfassen | `/de/audit-finding/new` | `audit_finding[audit]`=Audit aus #1, `title`, `description`, `type` (z.B. minor_nc), `severity` | 302 → `/de/audit-finding/{id}` |
| 3 | Korrekturmaßnahme | `/de/corrective-action/new` | `corrective_action[finding]`=Finding aus #2, `title`, `actionType`, `responsiblePerson`, Fälligkeit | 302 → `/de/corrective-action/{id}` |
| 4 | Wirksamkeit prüfen | `/de/corrective-action/{id}` (Lifecycle-Transition) | Status → `verified` / Wirksamkeitsnachweis | Audit-Trail-Eintrag, Status sichtbar |

**Akzeptanzkriterien:**
- Nach #1–#3 existieren in der DB: 1 `internal_audit`, ≥1 `audit_findings`
  (mit `audit_id` = #1), ≥1 `corrective_action` (mit `finding_id` = #2).
- Auf `/audit-finding/new` **ohne** Audit erscheint der Empty-State mit
  CTA → `app_audit_new` (kein leeres required `audit`-Select).
- Jeder Lifecycle-Übergang schreibt einen Audit-Log-Eintrag (Cl. 7.5.3).

---

## Workflow 2 — Asset → Risiko → Behandlung → Control → Restrisiko-Akzeptanz

**Norm:** ISO 27001 Cl. 6.1.2 (Risikobewertung) + 6.1.3 (Risikobehandlung) +
8.3, ISO 27005. **Status: Risiko-Anlage live verifiziert** (`/de/risk/224`).

**Berater-Sicht:** Der Kern jedes ISMS. Data-Reuse zwingend: das Asset wird
*einmal* angelegt und im Risiko referenziert, das Control *einmal* und in der
Behandlung verknüpft — keine Doppelerfassung.

**Junior-ISB:** Bedrohung ≠ Schwachstelle (Bedrohung = Ursache, Schwachstelle =
Einfallstor — siehe Glossar-Tooltips auf `/risk/new`). Risikowert =
Wahrscheinlichkeit × Auswirkung. Restrisiko = was nach der Behandlung bleibt;
eine Risikoakzeptanz braucht eine Begründung (Cl. 6.1.3 d).

| # | Schritt | Route | Kern-Eingaben | Erwartet |
|---|---|---|---|---|
| 1 | Asset erfassen | `/de/asset/new` | `asset[name]`, Typ, Schutzbedarf (C/I/A) | 302 → `/de/asset/{id}` |
| 2 | Risiko bewerten | `/de/risk/new` | `risk[title]`, `description`, `category`, `asset`=#1, `probability`, `impact` | 302 → `/de/risk/{id}` |
| 3 | Behandlung wählen | `/de/risk/{id}/edit` | `treatmentStrategy` (z.B. mitigate), `riskOwner`, `treatmentDescription` | gespeichert |
| 4 | Control verknüpfen | Risiko-Show / SoA | Annex-A-Control (z.B. A.8.16) zuordnen | Verknüpfung sichtbar |
| 5 | Restrisiko + Akzeptanz | `/de/risk/{id}/edit` | `residualProbability/Impact`, bei Akzeptanz: `acceptanceJustification` + Approval (ROLE_MANAGER+) | Status `accepted`, Ablaufdatum |

**Akzeptanzkriterien:**
- Risiko trägt `asset_id` = #1 (Data-Reuse, kein Duplikat).
- Live-Heatmap auf `/risk/new` zeigt die Schwere sofort beim Setzen von
  Probability/Impact (Score = P×I).
- Akzeptanz ohne Begründung wird server-seitig abgelehnt; kritischer Score
  (≥ 20) erzeugt den Board-Approval-Hinweis (Info-Form-Hint).

---

## Workflow 3 — Security-Incident → Datenpanne → 72-h-Meldung

**Norm:** ISO 27001 A.5.24–A.5.26 (Incident-Management) + GDPR Art. 33/34
(Meldepflicht). **Status: Incident- + DataBreach-Anlage live verifiziert**
(`/de/data-breach/62`).

**Berater-Sicht:** Der zeitkritischste Ablauf. Wiederverwendung: aus dem
Incident wird beim Speichern automatisch eine DataBreach-Akte vorbefüllt — der
72-h-Countdown (Art. 33) startet ab Erkennungszeitpunkt, sichtbar im Workflow.

**Junior-ISB:** Nicht jeder Incident ist eine Datenpanne — erst wenn
personenbezogene Daten betroffen sind. Die 72-h-Frist ist *hart* (DSGVO Art. 33):
Aufsichtsbehörde melden, bei hohem Risiko zusätzlich Betroffene (Art. 34).

| # | Schritt | Route | Kern-Eingaben | Erwartet |
|---|---|---|---|---|
| 1 | Incident melden | `/de/incident/new` | `incident[title]`, `description`, `severity` (z.B. high), Erkennungszeitpunkt | 302 → `/de/incident/{id}` |
| 2 | Datenpanne-Bezug | Incident-Form: „personenbezogene Daten betroffen" | Checkbox/Severity → DataBreach-Vorbefüllung | 72-h-Timer-Hinweis erscheint |
| 3 | Datenpanne-Akte | `/de/data-breach/new` (oder auto aus #1) | `data_breach[*]`: betroffene Datenkategorien, Anzahl Betroffener, Meldepflicht | 302 → `/de/data-breach/{id}` |
| 4 | Behörden-Meldung | DataBreach-Workflow (Art. 33) | Meldung an Aufsichtsbehörde dokumentieren, Frist einhalten | Workflow-Schritt erledigt, Frist-Status grün |
| 5 | Betroffenen-Info (bedingt) | DataBreach-Workflow (Art. 34) | bei hohem Risiko: Betroffene informieren | Schritt erledigt / als n/a markiert |

**Akzeptanzkriterien:**
- Bei „personenbezogene Daten betroffen" wird eine `data_breach`-Akte mit
  Vorbefüllung aus dem Incident angelegt (gleicher Erkennungszeitpunkt).
- Der 72-h-Countdown ist sichtbar und an den Erkennungszeitpunkt gebunden.
- Workflow-Auto-Progression schaltet Schritte frei, sobald die Pflichtfelder
  gefüllt sind (FieldCompletionAutoTransition).

---

## Nutzung als automatisierte Tests

Die bestehende `tests/E2e/coverage/scenarios/*.yaml`-Harness ist **single-form**
(ein `route`/`fill`/`submit`/`expect` je Szenario, siehe `_schema.yaml`). Diese
Workflows sind **multi-step** (erzeugte IDs fließen in den nächsten Schritt).
Zwei Wege, sie ausführbar zu machen:

1. **Harness erweitern** um einen `flow:`-Typ: Liste von Schritten mit
   `capture:` (z.B. die `{id}` aus der Redirect-URL) und `${captured.audit_id}`-
   Interpolation im nächsten `fill`. Baut auf dem vorhandenen `resolve_id`-
   Mechanismus aus `clone_flows.yaml` auf.
2. **Dedizierte Playwright-Specs** (`tests/E2e/specs/workflow-*.spec.ts`), die je
   Workflow die Schritte sequenziell fahren und nach jedem Schritt die DB- bzw.
   Redirect-Assertion prüfen.

**Wichtig für die Assertion:** Erfolg = HTTP **302 → Show-Page** (`/de/<entity>/{id}`),
nicht das Verbleiben auf `/new`. Eine reine URL-Prüfung direkt nach dem Klick ist
unter Turbo flaky — auf die Show-Page-Navigation *warten* (oder die DB als
Ground-Truth heranziehen).
