# Drei-Personas-Walkthrough: Data-Reuse & Framework-Mapping

> **Status 2026-04-21 — VOLLSTÄNDIG UMGESETZT.** Alle 12 Items aus
> HIGH + MEDIUM + LOW (15 FTE-Tage) sind committet. Details siehe
> „Umsetzungs-Status" am Ende dieses Dokuments. Dieser Plan wird als
> historischer Referenzpunkt aufbewahrt.

**Erstellt:** 2026-04-21
**Teilnehmer:**
- **CM** — Compliance-Manager (6–12 J. GRC, Data-Reuse-obsessed)
- **Junior** — Implementer neu in InfoSec, 9001-Hintergrund
- **Consultant** — Senior-Berater GRC/ISMS (Moderation + Synthese)

**Ausgangslage:** Audit-Doc v2.2 Residual-Budget 0 FTE-d — Tool ist
feature-vollständig aus CM-Sicht. Consultant-Review regt an: *„wenn das
Tool technisch fertig ist, wird die nächste Verbesserungs-Runde zur
Frage ob es **sprechender, hilfreicher, effizienter** für Neulinge wird."*
Konkret die beiden Module **Reuse** und **Framework-Mapping** sind
inhaltlich am weitesten vorn, aber UX-seitig historisch gewachsen.

**Methode:** Beide Module werden real im Tool geklickt, jede Persona
kommentiert in ihrer Stimme mit konkreten Feld-/Seiten-Referenzen.
Consultant konsolidiert + priorisiert nach Business-Value.

---

## Modul 1: Data-Reuse

### Bestand

| Einstieg | Template | Status |
|----------|----------|--------|
| `/compliance/framework/{id}/data-reuse` | `compliance/data_reuse_insights.html.twig` | Produktiv |
| `/compliance/transitive-compliance` | `compliance/transitive_compliance.html.twig` | Produktiv |
| `/reports/management/portfolio` (Inheritance-Rate + FTE-saved) | `portfolio_report/index.html.twig` | Produktiv |
| `InverseCoverageService` auf Document + Supplier Show | Partial-Widget | Sprint-3-Neuauflage |
| `TransitiveCoverageService` auf ComplianceRequirement-Show | Partial-Widget | Sprint-2-Neuauflage |

### Junior-Stimme

> *„Ich klicke auf `/compliance/framework/X/data-reuse`. Vier KPI-Kacheln:
> `Total Data Points`, `Reused Data`, `Reuse Rate %`, `Effort Saved h`.*
> *Was ist ein **Data Point**? Ein Asset? Ein Risiko? Ein Control? Ich hab
> 5 Assets im System, hier steht '127 Data Points'."*

> *„Die Cards `Reuse Opportunities` sagen *'12h Time Savings'*, aber nicht
> *was* wiederverwendet wird. Wenn ich einen neuen Control anlege, sehe
> ich das hier?"*

> *„Transitive-Compliance-Seite hat Tabs **Overview · Matrix · Details ·
> Explanation**. Info-Tab ganz rechts — übersehe ich beim ersten Durchgang.
> Die Matrix zeigt Zahlen ohne Tool-Tip."*

> *„Für mich als 9001-Mensch fehlt der Satz *'Eine Maßnahme erfüllt
> mehrere Normpunkte gleichzeitig — analog zu einer CAPA die mehrere
> Nichtkonformitäten abdeckt.'*"*

### CM-Stimme

> *„Data-Reuse-Seite ist **framework-zentrisch**. Mein Alltag ist umgekehrt:
> ich starte bei einem Artefakt (Policy, Control, Supplier) und frage
> *wo gilt das?*. Die `InverseCoverage`-Widgets leisten das — sind aber
> tief auf Show-Pages versteckt. Kein Menüpunkt `/reuse/documents/top-leverage`."*

> *„`effortSavedHours` auf Insights-Seite sind Stunden. Im Portfolio-
> Report heißt es FTE-Tage. **Zwei unterschiedliche Metriken für
> dasselbe Thema.** Das verunsichert CISO und Board."*

> *„Transitive-Leverage-Rate 47 % ist ein KPI — aber **keine Trendlinie**.
> `PortfolioSnapshot`-Cron speichert täglich, der Reuse-Trend wird aber
> nirgendwo gerendert. Board-Frage *'wird's besser?'* unbeantwortbar."*

> *„`app:import-cross-framework-mappings` (A3) ist CLI-only. Mein
> Controller-Team findet die nie. Ich brauche eine **Upload-UI**."*

### Consultant-Kommentar (Reuse)

> *„Zwei strukturelle Probleme: (1) **Reuse-Evidenz ist versteckt** in
> Show-Pages statt als eigenständiger Einstiegspunkt. (2) **Metrik-
> Sprache ist inkonsistent** — Stunden, FTE-Tage, Data Points,
> Anforderungen alle parallel. Vanta/Drata lösen das durch einen
> `/data-reuse`-Hub als First-Class-Route mit einer **einzigen
> Euro-Zahl**. Technisch habt ihr alles — ihr verkauft's nicht."*

---

## Modul 2: Framework-Mapping

### Bestand

| Einstieg | Template | Status |
|----------|----------|--------|
| `/compliance/mapping/` (Index, Tabelle) | `compliance/mapping/index.html.twig` | Produktiv |
| `/compliance/mapping/new` (Create-Form) | `compliance/mapping/new.html.twig` | Produktiv |
| `/compliance/mapping/{id}` (Show + Analyze-Button) | `compliance/mapping/show.html.twig` | Produktiv |
| `/compliance/compare` (Framework × Framework Matrix) | `compliance/compare.html.twig` | Produktiv |
| Auto-Mapping-Suggestions-Widget auf SoA-Control-Show | Sprint 1 / A2 | Produktiv |
| `app:seed-bsi-iso27001-mappings` (42), SOC2 (38), C5 (16) | CLI | Produktiv (B2/B5) |
| `app:import-cross-framework-mappings` | CLI | Produktiv (A3) |
| `app:migrate-framework-version` | CLI | Produktiv (B6) |

### Junior-Stimme

> *„`/compliance/mapping/new` hat zwei Dropdowns `Source Requirement` und
> `Target Requirement`. Beide mit 1 868+ Einträgen aus allen Frameworks
> zusammen. **Ich weiß nicht mal, welche zwei Frameworks ich verknüpfe,
> bevor ich die Requirements suche.** Typischer Junior-Move: Dropdown
> öffnen → erschrecken → Tab schließen."*

> *„Rechte Spalte erklärt `weak / partial / full / exceeds` mit
> Prozent-Bereichen. Gut. Aber das Tool **verlangt** exakte Prozent-
> Eingabe. Wo ist das 'Ich schätze nach Bauchgefühl'?"*

> *„`Confidence: low / medium / high` — 3 Radio-Buttons, kein Hinweis
> wann welches. Consultant wäre 'high', ich als Ersteller 'medium' —
> aber das sagt das Tool nicht."*

> *„Das schlimmste: Ich habe Control A.5.15 angelegt. Auf der SoA-
> Show-Page sehe ich unten den Auto-Mapping-Vorschläge-Widget mit 12
> Vorschlägen. Super. ABER: **Nichts verlinkt von der Mapping-New-Page
> zurück zu diesem Widget.** Ich finde als Junior nie das Widget."*

> *„`/compliance/compare` — Framework-Dropdown × 2 dann Matrix. Das Wort
> *Vergleich* ist klar, aber ohne den Satz *'Wie zwei Prüfungskataloge
> nebeneinander gelegt'* fehlt mir die Analogie zum 9001-Alltag."*

### CM-Stimme

> *„Mapping-Index-Seite listet Mappings als flache Tabelle, **keine
> Gruppierung nach Framework-Paar**. 400 Zeilen scrollen um zu sehen wie
> viele pro Paar existieren. Compare-View zeigt sowas als Matrix —
> inkonsistent."*

> *„Alle Seed-Commands (`app:seed-bsi-iso27001-mappings`, SOC2, C5) sind
> CLI-only. **Keine UI zum Triggern.** Controller-Team braucht Tickets
> oder SSH. Gleiche Analyse für XML-Importer."*

> *„`mapping_percentage` als Pflicht-Int, wo 90 % Standard-Werte sind
> (Full=100, Partial=70, Weak=30). Unnötige Eingabe. Feld sollte
> beim Type-Wechsel **auto-ausfüllen** mit Override-Option."*

> *„Version-Migration (`app:migrate-framework-version`) hat kein UI-
> Wizard. Wenn 27001:2026 kommt, wird der ISB den CLI nicht finden."*

> *„Auto-Mapping-Confidence als 87 % Jaccard-Score anzeigen — kein
> Auditor versteht Jaccard. Lieber *'Sehr hohe Text-Ähnlichkeit'* +
> Score als Tooltip."*

### Consultant-Kommentar (Mapping)

> *„Klassische Legacy-CRUD-UX: Dropdown-driven, keine Guided Flows. Die
> guten Stücke (Auto-Mapping-Suggestions, Seed-Commands, Version-
> Migration) sind **gebaut, aber nicht beworben**. Drei
> Konsolidierungs-Gelegenheiten:*
> 1. *Einheitlicher **Mapping-Hub** `/compliance/mappings` mit Tabs:
>    Matrix · Seeds · Import · Migration · Alle.*
> 2. *Kontextuelle Einstiegs-Buttons von Framework-Show direkt zu
>    *'Mappings gegen Framework X'*.*
> 3. *Confidence + Percentage als **Auto-Default mit Override** statt
>    Pflicht-Eingabe.*"

---

## Konsolidierte Action-Items (gesamt 15 FTE-Tage)

Alle Vorschläge, die aus dem Walkthrough sinnvoll hervorgegangen sind.
Priorisierung = Business-Impact × Nähe-zum-Onboarding-Pain.

### 🟥 HIGH — Sprint 4 (8 FTE-Tage)

Diese 6 Items lösen die **dringendsten Friction-Punkte** für Neulinge und
liefern CM-Board-Story. Ohne sie bleibt das Tool *„markt-spitze, aber
steile Lernkurve"*.

| # | Item | FTE | Nutzen für |
|---|------|-----|------------|
| **R1** | **Data-Reuse-Hub** `/reuse` als First-Class-Route mit Tabs *Dokumente · Assets · Lieferanten · Framework-Overlap*. Zieht `InverseCoverage`-Widgets zentral zusammen + Hot-List *„Top-10 wiederverwendete Artefakte"*. | 2,5 | Junior, CM |
| **R2** | **Einheitliche Ein-Zahl-KPI** *„Euro/FTE-Tage eingespart"* als globales Board-Widget auf Home + Portfolio. Eine Metrik aus `InheritanceMetricsService.fteSavedTotal`, kein Stunden-vs-Tage-Mix mehr. | 1 | CM, CISO, Board |
| **M1** | **Mapping-New-Wizard** ersetzt Dropdown-Hölle: Schritt 1 Framework-Paar · Schritt 2 gefilterte Requirement-Dropdowns · Schritt 3 Type-Auswahl füllt Prozent + Confidence automatisch vor · Schritt 4 Rationale. Junior-tauglich. | 2 | Junior, CM |
| **M2** | **Mapping-Seed-UI** `/compliance/mappings/seeds` mit Cards *„BSI ↔ ISO 27001 (42)"*, *„SOC 2 ↔ ISO 27001 (38)"* etc. Ein-Klick-Load statt CLI. | 1 | CM |
| **R3** | **Reuse-Trend-Chart** 12 Monate, gespeist aus `PortfolioSnapshot.fteSavedTotal`. Board-Frage *„wird's besser?"* beantwortet. | 1 | CM, Board |
| **M3** | **Confidence als Klartext**: `Jaccard 0.87` → *„Sehr hohe Text-Ähnlichkeit"*. Score als Tooltip. Audit-defensible. | 0,5 | Junior, Auditor |

**Zielbild Sprint 4:** Junior-Einarbeitung für Reuse + Mapping von
geschätzt 4 Wochen auf 1 Woche. CM hat eine Euro-Zahl fürs Board.

### 🟨 MEDIUM — Sprint 5 (5 FTE-Tage)

Hebel-Erweiterungen, die Konsolidierung und Bequemlichkeit auf das
Niveau moderner GRC-Tools bringen.

| # | Item | FTE | Nutzen |
|---|------|-----|--------|
| **M4** | **Mapping-Hub** `/compliance/mappings` konsolidiert Tabelle + Framework-Matrix + Seeds + Import + Migration in Tabs auf einer Seite. Ersetzt verstreute Einstiege. | 2 | CM, Consultant |
| **M5** | **Versions-Migrations-UI** `/compliance/framework/{id}/migrate` mit Preview-Liste (matched/unmatched aus `FrameworkVersionMigrator`) + Bulk-Accept-Checkboxen + Audit-Trail. Ersetzt CLI-Only. | 1,5 | CM, ISB |
| **R4** | **9001-Analogie-Tooltips** auf Reuse-KPIs: dezente *„≈ wie in 9001"*-Hinweise (z. B. *„Eine Maßnahme deckt mehrere Normpunkte — wie CAPA mehrere Nichtkonformitäten"*). | 0,5 | Junior |
| **R5** | **CSV-Import-UI** `/compliance/mappings/import` für `CrossFrameworkMappingImporter` — Upload-Form mit Dry-Run-Preview + Konflikt-Report statt CLI. | 1 | CM, Consultant |

### 🟩 LOW — Sprint 6 / Backlog (2 FTE-Tage)

Bequemlichkeits-Features ohne Onboarding-Wert, aber mit klarer Nutzer-
Story. Nur wenn Sprint 4+5 durch sind.

| # | Item | FTE | Trigger |
|---|------|-----|---------|
| **M6** | **Mapping-Review-Queue**: Seeds kommen mit `verified_by='seed'`, Junior sollte einmal Augen drauf haben. Queue-Page mit Accept/Reject-Buttons + Kommentar. Optional. | 1 | Wenn Auditor nachfragt *„wer hat das Mapping gemacht?"* |
| **R6** | **Per-Entity-Reuse-Heatmap** — Asset mit meisten Reuse-Referenzen rot, niedrigste grau. Visualization-Spielerei, kein Kernfeature. | 1 | Bei Management-Review-Bedarf *„welche Assets tragen das ISMS?"* |

### 📊 Gesamt-Budget

| Priorität | Items | FTE-Tage | ROI |
|-----------|-------|----------|-----|
| HIGH | 6 | 8 | Junior-Einarbeitung −75 %, Board-Metrik, Hauptfriktion weg |
| MEDIUM | 4 | 5 | Konsolidierung + CLI → UI Migration, ISB-Onboarding |
| LOW | 2 | 2 | Audit-Trail-Schliff, Visualization |
| **Summe** | **12** | **15** | |

**Jährliche Ersparnis CM+Junior-Alltag nach Sprint 4+5:**
- Junior-Einarbeitung pro neuem Implementer: 3 Wochen × 800 €/Tag = ~12 k€
- CM-FTE-Saved durch konsolidierte Hubs + UI statt CLI: ~5 FTE-d/Jahr = ~4 k€
- Consultant-Tage bei Neukunden-Onboarding: −2 Tage = ~2,5 k€/Kunde
- **Gesamt: ≥ 20 k€/Jahr direkter Effekt** + Markt-Differenzierung gegen HiScout/Verinice.

---

## Dokumentierte Ausschlüsse (bewusst **nicht** im Plan)

- **AI-generierte Mapping-Vorschläge über LLM.** Audit-Risiko zu hoch
  ohne nachvollziehbare Begründung. Jaccard reicht, Consultant stimmt zu.
- **Matrix-Organisation (mehrere Source-Frameworks)**. Nur M&A-Spezialfälle.
- **Realtime-Mapping-Kollaboration** mit Presence/Cursor. Compliance-Arbeit
  ist asynchron.
- **Externe Benchmark-Freigabe** (anonyme Vergleiche mit anderen Mandanten).
  Datenschutzrechtlich heikel, niedriger Nutzen.

---

## Voten der drei Personas

**Junior:** *„R1 + M1 machen meinen Tag. Von **'wo fang ich an?'** zu
**'ich weiß was zu tun ist'**. Danach verstehe ich auch Matrix und
Compare — das Wortschatz-Problem löst sich mit Analogien (R4)."*

**CM:** *„R2 + R3 + M2 + M4 machen's dem Board verkaufbar. Die messbare
Euro-Zahl plus Trend-Chart plus 1-Klick-Seed-Aktivierung — das lasse
ich mir vom CISO budgetieren. M5 ist mein Jahres-2026-Thema, wenn
27001:2026 kommt."*

**Consultant:** *„Mit HIGH-Block allein lagert ihr Mittelstands-
Onboarding-Zeit um 75 % aus. Das hebt das Tool aus der Kategorie
*Markt-Spitze-mit-steiler-Lernkurve* in *Junior-tauglich-mit-Führung*.
Der MEDIUM-Block schließt die Consultant-Ergonomie-Lücken (Hub,
Import-UI, Version-Wizard). Mit LOW sind wir dann auf Niveau von
Tools, die drei Stufen drüber teurer sind."*

**Empfehlung:** Sprint 4 (HIGH, ~8 d) direkt anstoßen. Sprint 5+6 nach
Feedback aus Sprint-4-Deployment auf ersten Test-Mandanten.

---

## Umsetzungs-Status (2026-04-21)

Alle 12 Items committet. Audit post-Sprint-6 fand einen Runtime-Bug
in M1 (private-Property-Zugriff), der separat gefixt wurde.

### Sprint 4 — HIGH ✅

| Item | Commit | Route / Artefakt |
|------|--------|-------------------|
| M3 Klartext-Confidence | `83017e1b` | SoA-Show (`mapping_suggestions_widget`) |
| R3 Reuse-Trend-Chart | `52c54eda` | `ReuseTrendSnapshot` + `/reports/management/portfolio` |
| R2 Home-Dashboard-FTE-KPI | `aa1e1b8d` | `/dashboard` (Card über Management-KPIs) |
| M2 Seed-1-Klick-UI | `68f33377` | `/compliance/mappings/seeds` |
| R1 Data-Reuse-Hub | `00c8f28a` | `/reuse` + Mega-Menü |
| M1 4-Step-Wizard | `04d7cee5`, fix `328c93eb` | `/compliance/mapping/wizard` |

### Sprint 5 — MEDIUM ✅

| Item | Commit | Route / Artefakt |
|------|--------|-------------------|
| R4 ISO-9001-Analogien | `053aeea8` | `_components/_iso9001_analogy.html.twig` (4 Seiten) |
| M4 Mapping-Hub | `ce6d39fd` | `/compliance/mapping/hub` + Mega-Menü |
| R5 CSV-Import-UI | `686d88af` | `/compliance/mapping/import` |
| M5 Version-Migrations-UI | `2bb93bb0` | `/compliance/framework/{id}/migrate` |

### Sprint 6 — LOW ✅

| Item | Commit | Route / Artefakt |
|------|--------|-------------------|
| M6 Seed-Review-Queue | `f5118e39` | `/compliance/mapping/seed-review` |
| R6 Reuse-Heatmap | `d9603404` | `/reuse/heatmap` |

### Post-Sprint-Audit

- `328c93eb` — M1-Wizard Zugriff auf private `ComplianceFramework::$id`
  (Nebeneffekt von `2a2bc51f`, das die Property-Hooks entfernte).
  Umgestellt auf `getId()`. Nur der GET-Render-Pfad war betroffen.

### Abweichungen vom Ursprungsplan

- **M4 Mapping-Hub** wurde nicht als Tabbed-Page auf `/compliance/mappings`
  umgesetzt, sondern als **separate Hub-Route** `/compliance/mapping/hub`
  mit Einstiegs-Karten. Die vorhandene Liste unter
  `/compliance/mapping/` bleibt bestehen (für gezielte Suche) und wird
  vom Hub aus verlinkt. Grund: bestehende Tabelle hat direkte Links aus
  anderen Features (Mapping-Show, Seeds, Quality), die nicht alle
  gleichzeitig refaktoriert werden sollten.
- **M5 Version-Migrations-UI** kam ohne **Bulk-Accept-Checkboxen pro
  Row** — der zugrunde liegende `FrameworkVersionMigrator` ist
  all-or-nothing. Dry-Run + Commit deckt 95 % der Anwendungsfälle.
  Per-Row-Rejection kann bei Bedarf nachgerüstet werden.
- **M6 Seed-Review-Queue** ergänzt die bestehende
  `MappingQualityController::reviewQueue`, ersetzt sie nicht. Unterschied:
  Quality-Queue filtert auf `requiresReview=true`, Seed-Queue auf
  maschinelle Herkunft via `verifiedBy`-Prefix-Match.
- **R6 Reuse-Heatmap** rendert **Dokumente + Lieferanten** statt der im
  Plan vorgesehenen Assets. Grund: `InverseCoverageService` liefert nur
  `forDocument()` + `forSupplier()`. Eine Asset-Coverage-Erweiterung
  wäre ein eigenes Sprint-Item (~1 FTE-d).