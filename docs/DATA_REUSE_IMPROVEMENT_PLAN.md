# Data-Reuse Improvement Plan

> **Erstellt:** 2026-04-17
> **Status:** 🟢 **Version 1.1 · Sprint 1 + Sprint 2 ausgeliefert (2026-04-17)** — WS-1 Auto-Fulfillment produktiv, 461 Cross-Framework-Mappings (100 % Trefferquote), 18 Frameworks geladen (+ISO 27005 neu). **Sprint 2 komplett (3 parallele Agents):** WS-2 Import-Wizard-UI (Stepper, Preview mit Konflikt-Erkennung), WS-3 Supplier DORA (14 Felder, 4-Tab-Layout, `fk_supplier_exit_strategy_document`, Migration live), WS-4 Cross-Framework-Portfolio-Report (NIST-CSF-Matrix mit Govern/Identify/Protect/Detect/Respond/Recover, PDF + HTML, Stichtag-Parameter). **Admin-Loader-Fixer** ergänzt: idempotentes Nachladen aller 22 Framework-Loader via UI. Offen: Sprint 3 (WS-5 Tagging + WS-6 Gap/FTE), Sprint 4 (WS-7 Compare-PDF + WS-8 Setup-Dialog), E2E gegen Test-Tenant, Locale-Doppel-Präfix-Bereinigung (kosmetisch).
> **Version:** 1.1 (2026-04-17)
> **Autoren:** Compliance-Manager (intern) · Senior-GRC-Consultant (extern) · UX-Specialist (intern)
> **Ausgangsbericht:** Data-Reuse-Kritik Workflow Einrichtung → Erstbefüllung → Reporting
> **Bezug:** `src/Entity/ComplianceMapping.php`, `ComplianceRequirementFulfillment.php`, `ComplianceWizardController.php`, `ManagementReportController.php`, `DeploymentWizardController.php`

---

## Executive Summary

Das Tool hat **architektonisch das Fundament** für Cross-Framework-Data-Reuse (Mapping-Entity mit Confidence & Rationale, saubere Definition-Fulfillment-Trennung, 20+ Framework-Loader, Wizard-Compare-Route). Es **verschwendet dieses Fundament** an drei kritischen Stellen:

1. **Mappings werden im Fulfillment nicht ausgenutzt** → Mapping-basierter Ableitungsvorschlag (mit Review-Pflicht) fehlt.
2. **Externe Artefakte (Consultant-Vorlagen, Verinice-XML) haben keinen Import-Pfad** → Medienbruch beim Onboarding.
3. **Reports sind framework-siloed** → keine Portfolio-Sicht für CISO-Reporting.

**Sekundäre Lücken** (P2–P3): Supplier-Entity ohne DORA-Felder, fehlendes Framework-Tagging auf Assets/Controls, Gap-Report ohne FTE-Schätzung, Setup-Wizard ohne "was hast du schon?"-Dialog.

**Aufwand gesamt:** 36–46 FTE-Tage (inkl. Mapping-Versionierung und Pflicht-Review-Infrastruktur aus ISB-Review). **Break-even:** nach dem ersten Framework-Onboarding nach Umsetzung. **ROI klar positiv ab Zweitnutzung.**

---

## Drei-Stimmen-Prinzip

Jeder Workstream ist aus drei Rollen bewertet. So erkennt man, ob eine Empfehlung fachlich, methodisch und bedienbar zusammenpasst.

| Rolle | Stimme im Plan | Fokus |
|---|---|---|
| 👔 **Compliance-Manager (CM)** | interner Auftraggeber | Data-Reuse, FTE-Einsparung, Framework-Portfolio |
| 🎓 **Senior-Consultant (SC)** | externe Beratung | Markt-Benchmark, Methodik, Templates, Knowledge-Transfer |
| 🎨 **UX-Specialist (UX)** | interne Umsetzung | Component-Reuse, WCAG 2.2 AA, bestehende Pattern-Library |

**Arbeitsteilung:** SC liefert Methodik und Vorlagen. CM entscheidet und priorisiert. UX baut mit bestehender Komponentenbibliothek. Implementer (separat) setzt Backend-Änderungen um.

---

## Ausgangslage (was heute schon gut ist)

> Aus dem Scan am 2026-04-17 — als Baseline festgehalten, damit künftige Plan-Updates den Startpunkt nachvollziehen können.

- `ComplianceMapping` Entity mit bidirektional, `mappingPercentage` (0–150), `calculatedPercentage` + `manualPercentage`, `confidence` (low/medium/high), `mappingRationale`, `verifiedBy` (`src/Entity/ComplianceMapping.php:26–96`).
- `ComplianceRequirementFulfillment` tenant-spezifisch mit Unique-Constraint `(tenant_id, requirement_id)` und `applicabilityJustification` (`src/Entity/ComplianceRequirementFulfillment.php:34–73`).
- Transitive Mapping-Generierung via ISO 27001 als Hub (`src/Command/CreateCrossFrameworkMappingsCommand.php:50`).
- Wizard-Compare-Route für mehrere Frameworks gleichzeitig (`src/Controller/ComplianceWizardController.php:209`).
- `ComplianceMappingService::getCrossFrameworkInsights()` und `calculateDataReuseValue()` bereits vorhanden (`src/Service/ComplianceMappingService.php:251,274`).
- Report-Builder mit Templates, Clone, Widget-Library (`src/Controller/ReportBuilderController.php:104,128`).
- Tenant-Vererbung bei hierarchischem Governance-Modell (`src/Service/ComplianceRequirementFulfillmentService.php:156–189`).

---

## Workstreams

### 🔴 WS-1 · Mapping-basierter Ableitungsvorschlag mit Pflicht-Review (P1)

**Das zentrale Reuse-Fehlen.** Wenn ein 27001-Control erfüllt ist und bidirektional auf NIS2-Requirement gemappt, erzeugt das Tool einen **Ableitungsvorschlag** für das NIS2-Fulfillment — der vor Wirkung als "erfüllt" von Rolle ≥ MANAGER bestätigt werden muss.

> **Wichtig (ISB-Finding MAJOR-1):** Kein automatischer Statuswechsel auf "implemented" ohne menschliche Prüfung. Ableitungen landen im Zustand `inherited_pending_review` und werden erst nach dokumentierter Bestätigung wirksam.

> **Wichtig (ISB-Finding MAJOR-2):** Mapping-Katalog ist versioniert. Änderungen am Mapping erzeugen einen neuen Datensatz; alte Versionen bleiben für Stichtag-Reports erhalten.

#### 👔 CM — Problem
> *"Ich aktiviere NIS2 auf einem 27001-zertifiziertem Tenant. Erwartet: 60–80 % der NIS2-Anforderungen sind mit Ableitungsvorschlag 'teilweise erfüllt durch 27001-Evidenz' — zur Prüfung. Realität: alle auf 0 %, komplette Neuerfassung."*

**Codefund:** `ComplianceRequirementFulfillmentService::getOrCreateFulfillment()` kennt Vererbung **nur** zwischen Tenants (parent→child), **nicht** framework-übergreifend via `ComplianceMapping`.

**Einsparung:** 5–15 FTE-Tage pro neuem Framework-Onboarding (bei 100+ Requirements × 4–8 Minuten manuelle Erstbewertung) — Einsparung bleibt erhalten, da Prüfung einer Ableitung (ca. 2–4 min) weiterhin deutlich schneller ist als Neubewertung (ca. 6–15 min).

#### 🎓 SC — Methodik
- **Vorschlag:** Delta-Assessment statt Voll-Neubewertung — Standard in Verinice, HiScout, Drata.
- **Ableitungsregeln** (Markt-Konvention, liefern alle `status = inherited_pending_review` bis Bestätigung):
  - Mapping `full (100 %)` + Confidence `high` → Vorschlag = Source-Prozent (1:1).
  - Mapping `partial (50–99 %)` + Confidence `medium/high` → Vorschlag = `Source-% × Mapping-%`.
  - Mapping `weak (<50 %)` oder Confidence `low` → Hinweis im Fulfillment, aber kein Vorschlagswert. Nur Link zum Source.
- **Audit-Fähigkeit:** Jede Ableitung ist markiert als Vorschlag und zeigt `via: Mapping #ID v{version} → Source Requirement #ID`. Bestätigung, Ablehnung und manuelle Übersteuerung erzeugen getrennte Audit-Log-Einträge.
- **Branchen-Baseline:** Typische Abdeckung 27001 → NIS2 Art. 21: 60–75 %. Consultant liefert Mapping-Exceldatei mit validierten Prozenten (siehe WS-2).

#### 🎨 UX — Umsetzung
- **Kein neues UI-Paradigma** — bestehende Fulfillment-Detailseite um eine Sektion **"Abgeleitet durch Mapping (Prüfung erforderlich)"** erweitern.
- **Zwei neue Komponenten** (beide A11y-konform):
  - `templates/_components/_fulfillment_inheritance_badge.html.twig` — nutzt `badge bg-warning` mit Icon `bi-hourglass-split` für `inherited_pending_review`, `badge bg-info` mit `bi-link-45deg` für `inherited_confirmed`. Tooltip: "Vorschlag aus Anforderung X (Framework Y) via Mapping v{version}, Z %".
  - `templates/_components/_inheritance_review_panel.html.twig` — Inline-Panel mit Pflichtfeld **Prüferkommentar** (min 20 Zeichen), Aktionen *Bestätigen* / *Ablehnen* / *Override*. Bei Übergang auf Status `implemented` erzwingt das Panel einen **zweiten Nutzer** (4-Augen, Funktionstrennung A.5.3).
- **Navigations-Badge** am Compliance-Trigger (ergänzt `NAVIGATION_IMPROVEMENT_PLAN.md` Phase 5.2): Zahl offener `inherited_pending_review` — so dass Prüfpflicht nicht übersehen wird.
- Liste der Source-Requirements als `<dl>` mit Link, Mapping-Prozent, Mapping-Version, Confidence-Badge.
- **A11y:**
  - `aria-live="polite"` Region nach Framework-Aktivierung: *"42 Anforderungen stehen als Ableitungsvorschlag zur Prüfung bereit"*.
  - Status-Unterschied ist nicht nur Farbe: `bi-hourglass-split` (ungeprüft) vs. `bi-link-45deg` (bestätigt) vs. `bi-pencil-square` (überschrieben).
- **Override-UI:** Manuelles Überschreiben eines bestätigten oder ungeprüften Vorschlags erzeugt Confirm-Dialog mit Pflichtfeld *Begründung*. Ableitungs-Metadaten bleiben erhalten.

#### Umsetzung konkret
- **Entity-Ergänzung `ComplianceMapping` (MAJOR-2):**
  - `version: int` (Default 1, inkrementell bei Änderung).
  - `validFrom: datetime_immutable`.
  - `validUntil: ?datetime_immutable` (NULL = aktuell gültig).
  - Änderung (Prozent/Rationale/Confidence) schreibt neuen Datensatz, alter bekommt `validUntil`.
- **Entity-Ergänzung / neue `FulfillmentInheritanceLog` Entity:**
  - `derivedFromMapping: ComplianceMapping` (inkl. Version via FK auf versionierten Datensatz).
  - `mappingVersionUsed: int` (redundant gespeichert für schnelle Stichtag-Abfragen).
  - `suggestedPercentage: int`.
  - `reviewStatus: enum(pending_review, confirmed, rejected, overridden)`.
  - `reviewedBy: ?User`, `reviewedAt: ?datetime`, `reviewComment: ?text`.
  - `fourEyesApprovedBy: ?User`, `fourEyesApprovedAt: ?datetime` (Pflicht bei Status-Übergang zu `implemented`).
  - `overriddenBy: ?User`, `overriddenAt: ?datetime`, `overrideReason: ?text` (Pflichtfeld bei Override).
- **Neu:** `ComplianceRequirementFulfillmentService::createInheritanceSuggestions(Tenant, ComplianceFramework $targetFramework)` — erzeugt Vorschläge im Status `pending_review`, schreibt **nicht** direkt auf `ComplianceRequirementFulfillment.fulfillmentPercentage`. Erst die Bestätigung überträgt den Wert.
- **Feature-Flag:** `compliance.mapping_inheritance.enabled` (dark-launch, pro Tenant aktivierbar) — adressiert CM-Auflage Nr. 4.
- **Dry-Run:** Command `app:compliance:preview-inheritance --tenant=X --framework=NIS2` zeigt ohne DB-Schreiben, was abgeleitet würde. Ermöglicht Abnahme durch ISB vor Live-Schaltung.
- **Stichtag-Query:** Report-Service (WS-4) lädt Mappings `WHERE valid_from <= :stichtag AND (valid_until IS NULL OR valid_until > :stichtag)`.
- **Rollenschutz** (siehe Anhang "Rollenmatrix", MAJOR-3): Vorschlag-Generierung = MANAGER, Bestätigung = MANAGER, Bestätigung auf `implemented` = 4-Augen-Voter, Override = MANAGER + Begründungspflicht.
- **Events/Trigger:**
  - Framework-Aktivierung → einmaliger Batch-Run `createInheritanceSuggestions`.
  - Source-Fulfillment-Update → Notification an Ziel-Fulfillment-Owner, **keine stille Kaskade**.

#### Akzeptanzkriterien
- [ ] Aktivierung NIS2 auf 27001-Tenant erzeugt mind. 40 Ableitungsvorschläge im Status `inherited_pending_review` (bei Standard-Mapping-Katalog).
- [ ] Vorschlag verändert `ComplianceRequirementFulfillment.fulfillmentPercentage` **nicht** vor Bestätigung.
- [ ] Bestätigung ohne Prüferkommentar (< 20 Zeichen) wird abgelehnt.
- [ ] Statusübergang auf `implemented` ist ohne zweiten Nutzer (4-Augen) technisch nicht möglich.
- [ ] Ableitung ist in Detail-Ansicht erkennbar (Icon + Text + Status-Badge, nicht nur Farbe).
- [ ] Override erzwingt Begründung und bewahrt Ableitungs-Metadaten.
- [ ] Mapping-Änderung nach Stichtag X ändert keinen Report mit Stichtag ≤ X (Mapping-Versionierungs-Test).
- [ ] Audit-Log-Einträge **getrennt** für: Vorschlag erzeugt / bestätigt / abgelehnt / überschrieben. Jeder Eintrag enthält `actor_user_id`, `actor_role`, optional `four_eyes_approver_id`.
- [ ] Dry-Run-Command liefert korrekte Vorschau ohne DB-Schreiben.
- [ ] Navigations-Badge zeigt korrekte Anzahl offener `pending_review`.

#### Aufwand & Abhängigkeiten
- **Backend:** 5 FTE-Tage (Service, Events, Entity-Erweiterungen inkl. Mapping-Versionierung, Voter, Feature-Flag, Dry-Run-Command, Tests).
- **Frontend:** 2 FTE-Tage (2 Komponenten + Detail-Ansicht-Integration + 4-Augen-Modal + Navigations-Badge).
- **Abhängigkeit:** saubere Mapping-Katalogdaten (WS-2), Rollenmatrix freigegeben (Anhang).
- **Gesamt:** 7 FTE-Tage *(vormals 4–6; +1,5 Tage für Mapping-Versionierung und Pflicht-Review-Infrastruktur gem. ISB-Findings).*

---

### 🔴 WS-2 · Import-Wizard für externe Mappings & Vorlagen (P1)

> *Consultant-Zitat (typisch): "Bei Verinice kann ich das per XML-Import in 10 Minuten. Hier tippe ich 150 Requirements ab?"*

#### 👔 CM — Problem
- Consultant liefert Standard-Artefakte. Tool muss sie konsumieren können.
- Heute: `DataImportService` existiert, aber **keine dokumentierten Schemas** für gängige Formate.

#### 🎓 SC — Markt-Benchmark
- **Formate in der Reihenfolge ihres Nutzens:**
  1. **Excel mit Standard-Spalten** (Requirement-ID, Framework, Fulfillment-%, Status, Evidenz-Referenz, Mapping-Ziel). 80 % der Consultant-Lieferungen.
  2. **BSI IT-Grundschutz-Profile** (XML) — Pflicht für BSI-nahe Kunden.
  3. **Verinice-VNA/VNL** (ZIP mit XML) — Marktstandard DE, häufig bei Konzernen.
  4. **VDA-ISA-Katalog** (Excel) — TISAX-Kunden, Automotive.
  5. **NIST-CSF-CSV** — US-affine Kunden, Framework-Mappings.
  6. **JSON-Schema** (eigenes) — für API-Konsumenten und zukünftige Tools.
- **Templates liefern:** Ich (Consultant) stelle initial kuratierte Excel-Vorlagen mit validierten 27001→NIS2- und 27001→DORA-Mappings. Tool liefert sie als Download-Link im Wizard.
- **Knowledge-Transfer:** Sobald Import-Wizard steht, brauche ich nicht mehr beauftragt zu werden für Standard-Onboardings.

#### 🎨 UX — Umsetzung
- **Neue Seite:** `/admin/import/compliance` als **Wizard** (3 Schritte, kein Modal — zu viel Inhalt).
- **Wiederverwendete Komponenten:** `_card`, `_alert`, `_button_group`, `_pagination`, Bootstrap File-Upload. Keine Neuentwicklung außer:
  - `templates/_components/_import_preview_table.html.twig` *(neu)* — Vorschau vor Commit mit Ampel pro Zeile (grün=neu, blau=update, rot=Konflikt).
  - `templates/_components/_mapping_diff.html.twig` *(neu)* — Diff-View bei Mapping-Overlap.
- **Stepper-Pattern** (Bootstrap stepper):
  1. **Format wählen + Datei hochladen** — Dropdown mit Format, Link "Template herunterladen" pro Format.
  2. **Vorschau & Konfliktlösung** — Tabelle mit Ampel, pro Zeile Radio "übernehmen / überspringen / zusammenführen".
  3. **Commit + Report** — Summary "X erstellt, Y aktualisiert, Z übersprungen" + Link zum Audit-Log-Eintrag.
- **A11y:** Stepper mit `aria-current="step"`, Fehler-Liste mit `role="alert"`, Upload-Progress via `role="progressbar"` mit `aria-valuenow`.
- **Error-Recovery:** Atomares Commit — entweder alle Zeilen oder keine. Bei Fehler Rollback und Fehlerliste pro Zeile mit Spring-Link zur Korrektur.

#### Umsetzung konkret
- **Neu:** `App\Service\Import\` Namespace mit `ImportFormatInterface`, Implementierungen `ExcelComplianceImporter`, `VeriniceImporter`, `BsiProfileImporter`, `NistCsfImporter`.
- **Neu:** `App\Controller\Admin\ComplianceImportController` mit 3 Routen (upload / preview / commit).
- **Neu:** `ImportSession` Entity für Preview-State (tenant-scoped, TTL 24h).
- **Ergänzung:** bestehenden `DataImportService` als Orchestrator nutzen, konkrete Format-Implementierungen als Strategien.

#### Akzeptanzkriterien
- [ ] Consultant-Excel-Template (≤5 MB, 500 Zeilen) wird in < 30 Sekunden importiert (Preview + Commit).
- [ ] Konflikt-Erkennung: existierende Fulfillments werden nicht stumm überschrieben.
- [ ] Audit-Log-Eintrag pro Import-Session mit Zusammenfassung.
- [ ] Mindestens zwei Formate (Excel + BSI-Profile-XML) End-to-End getestet mit Beispieldatei im Repository.
- [ ] Exportfunktion spiegelt Importformat — Round-Trip-Fähigkeit.

#### Aufwand
- **Backend (Formate + Orchestrator):** 5–7 FTE-Tage.
- **Frontend (Stepper + Preview-Tabelle):** 2–3 FTE-Tage.
- **Templates (Consultant):** 1 Tag Consultant-Leistung für initiale Vorlagen.
- **Gesamt:** 8–11 FTE-Tage + 1 Consultant-Tag.

---

### 🔴 WS-3 · Supplier um DORA-/Multi-Framework-Felder erweitern (P1)

#### 👔 CM — Problem
- Lieferant einmal gepflegt muss: DORA Art. 28 (Register of Information), 27001 A.5.19–A.5.22, DSGVO Art. 28 (AV-Verträge) **gleichzeitig** abdecken.
- Heute: `Supplier` Entity hat keine DORA-spezifischen Felder → Medienbruch (Excel neben Tool) oder duplizierte Datensätze.

#### 🎓 SC — Methodik & Felder
- **DORA ROI-Pflichtfelder** (aus ITS):
  - ICT-Dienst-Klassifikation (kritisch/wichtig).
  - Funktion (Cloud, SaaS, Outsourcing, Managed Service …).
  - Substitutierbarkeit (leicht/mittel/schwer).
  - Subcontracting-Kette (ja/nein, Liste).
  - Standort Datenverarbeitung (Länder).
  - Datum letzter Audit.
  - Exit-Strategie vorhanden (ja/nein, Dokumentverweis).
- **27001 A.5.19–A.5.22:** Bewertung, Monitoring, Änderungsmanagement, Informationssicherheit in Verträgen — überschneidet sich mit DORA, keine Duplikate nötig.
- **DSGVO Art. 28:** AV-Vertrag vorhanden, Auftragsverarbeiter-Status, Transfer-Mechanismus (SCC, Adequacy Decision) — als Felder oder verknüpfte Dokumente.

#### 🎨 UX — Umsetzung
- **Keine neue Detailseite** — bestehende Supplier-Detail-Ansicht in **Tabs** strukturieren:
  - Tab 1 *Stammdaten* (bestehend).
  - Tab 2 *Verträge & Dokumente* (bestehend erweitert).
  - Tab 3 *DORA-Registrierung* (neu) — nur sichtbar wenn `compliance`-Modul aktiv und DORA-Framework geladen.
  - Tab 4 *Datenschutz* (neu oder erweitert bestehend).
- **Progressive Disclosure:** DORA-Felder kollabiert, öffnen sich wenn "kritisch" gesetzt wird.
- **Wiederverwendbare Komponente:** `_entity_card.html.twig` bereits vorhanden — Supplier-Detail nutzt sie für Stammdaten-Block, keine Änderungen nötig.
- **Cross-Framework-Badge-Leiste** im Header: Badges pro Framework (27001, DORA, DSGVO) mit Status (grün/gelb/rot). Nutzt bestehendes `_badge`-Pattern.
- **A11y:**
  - Tabs mit `role="tablist"`, `aria-selected`, `aria-controls`.
  - Progressive Disclosure: `aria-expanded` auf dem Trigger.
  - Tab-Navigation per Pfeiltasten (Bootstrap-Default).

#### Umsetzung konkret
- **Entity-Migration:** Supplier-Felder ergänzen — nullable, keine Breaking-Changes.
- **Keine neue Entity** — Framework-Relevanz über bestehende Logik (Asset-Tagging siehe WS-5 anwendbar auf Supplier).
- **DORA-Report** `/reports/management/dora` generiert ROI-Export aus Supplier-Daten — kein separates Register pflegen.

#### Akzeptanzkriterien
- [ ] Supplier einmal angelegt erscheint in: 27001-Lieferantenliste, DSGVO-Auftragsverarbeiter-Register, DORA-ROI-Export.
- [ ] Kein Feld wird in zwei Entities gepflegt.
- [ ] Frühere Supplier ohne DORA-Daten erscheinen korrekt als "DORA-Felder nicht ausgefüllt" (nicht als kaputt).
- [ ] DORA-Tab nur sichtbar wenn Framework geladen.

#### Aufwand
- **Backend (Migration + Felder):** 1 FTE-Tag.
- **Frontend (Tab-Struktur + DORA-Tab):** 1–2 FTE-Tage.
- **Report-Integration:** 0,5–1 FTE-Tag.
- **Gesamt:** 2,5–4 FTE-Tage.

---

### 🟠 WS-4 · Cross-Framework-Management-Report (P2)

#### 👔 CM — Problem
- Monatliche CISO-Vorlage: *"Status 27001 / NIS2 / DORA in einer Tabelle"*. Heute drei separate Reports, manuelle Zusammenstellung in Excel.
- Zeiteinsatz Ist: 2 FTE-Tage/Monat. Zeiteinsatz Soll: < 15 Minuten.

#### 🎓 SC — Methodik
- **Marktstandard:** One-Pager mit Ampel pro Anforderungskategorie, Delta zur Vorperiode, Trendlinie über 12 Monate.
- **Typische Zeilen-Achse:** Anforderungsfamilien oder NIST-CSF-Funktionen (Identify, Protect, Detect, Respond, Recover, Govern).
- **Typische Spalten-Achse:** aktivierte Frameworks.
- **Benchmark-Erweiterung** (optional, Phase 2): Branchendurchschnitt aus anonymisierter Vergleichsgruppe.

#### 🎨 UX — Umsetzung
- **Neue Route:** `/reports/management/portfolio` — in Report-Kategorie "Berichte erstellen".
- **Pattern:** Matrix-Tabelle, wiederverwendet `_sortable_table` Komponente.
- **Visualisierung:**
  - Zellen als Ampel-Badge: grün (≥80 %), gelb (50–79 %), rot (<50 %), grau (nicht anwendbar).
  - Drill-down: Klick auf Zelle öffnet Requirement-Liste für (Framework, Kategorie).
  - Trendpfeil ↗ ↘ → neben Ampel bei Delta > 5 % zur Vorperiode.
- **Wiederverwendung:** `_progress` Komponente aus bestehender Dashboard-Statistik-Service-Integration.
- **Export:** PDF und Excel — beide mit Stichtag, Signatur-Feld unten.
- **A11y:**
  - Ampel nicht nur Farbe: Icon + Prozent-Text in jeder Zelle.
  - Tabelle mit `scope="col"` / `scope="row"` korrekt gesetzt.
  - `aria-label` an Drill-down-Links: "Details zu 27001 / Identify-Anforderungen".
- **Responsive:** auf Mobile vertikal gestapelt, ein Framework pro Card.

#### Umsetzung konkret
- **Neu:** `App\Controller\PortfolioReportController` mit Routen `/index`, `/pdf`, `/excel`.
- **Neu:** `App\Service\PortfolioReportService::buildMatrix(Tenant, DateTime $stichtag, DateTime $vorperiode)` — aggregiert Fulfillment-Daten über Frameworks.
- **Wiederverwendung:** `ComplianceWizardService::runAssessment()` als Datenquelle pro Framework (bereits vorhanden!).
- **Template:** `templates/portfolio_report/index.html.twig` + `pdf.html.twig` + `excel.xlsx.twig`.

#### Akzeptanzkriterien
- [ ] 3 Frameworks × 6 Kategorien in < 2 Sekunden gerendert.
- [ ] Stichtag-Parameter: Bericht reproduzierbar zu beliebigem historischen Datum.
- [ ] Excel-Export öffnet sich in Excel 2019+ ohne Warnungen.
- [ ] PDF-Export hat Header mit Mandant, Stichtag, Version.
- [ ] Farbenblinde Nutzer können Status per Icon/Prozent ablesen (verifiziert mit Chrome-DevTools-Simulation).

#### Aufwand
- **Backend (Service + Controller):** 1,5–2 FTE-Tage.
- **Frontend (Template + Matrix + Drill-down):** 1,5–2 FTE-Tage.
- **Export-Templates (PDF + Excel):** 1 FTE-Tag.
- **Gesamt:** 4–5 FTE-Tage.

---

### 🟠 WS-5 · Framework-Tagging & Bulk-Operationen auf Assets/Controls (P2)

#### 👔 CM — Problem
- Szenario: 50 Controls auf "NIS2-relevant" markieren. Heute einzeln klicken.
- Asset-List-View nicht filterbar nach "wirkt auf Framework X".

#### 🎓 SC — Methodik
- **Pattern:** Tag-basierte Klassifikation, nicht harte 1:1-Zuordnung. Ein Control kann 27001+NIS2+DORA gleichzeitig tragen.
- **Markt:** Drata, Vanta, HiScout nutzen Framework-Tags. Verinice nutzt Gruppen — weniger flexibel.

#### 🎨 UX — Umsetzung
- **Bestehende Indexseiten** (Asset, Control, Risk, Supplier) um Framework-Filter erweitern.
- **Wiederverwendung:** bestehendes Filter-Pattern aus `_components/_sortable_table.html.twig` — nur Filter-Leiste ergänzen.
- **Bulk-Actions-UI:**
  - Checkbox-Spalte links in Tabelle (vorhandenes Bootstrap-Pattern).
  - Actions-Dropdown oben bei Auswahl: "Framework-Tag hinzufügen", "Framework-Tag entfernen", "Exportieren", "Löschen".
  - Footer-Toast: "3 Items ausgewählt" mit `aria-live="polite"`.
- **Neue Komponente:** `templates/_components/_bulk_actions_bar.html.twig` — eine für alle Entity-Listen. Nimmt Actions als Parameter.
- **A11y:**
  - Alle Checkboxen mit `aria-label="Auswählen [Entity-Name]"`.
  - Header-Checkbox mit `aria-label="Alle auswählen"`.
  - Keyboard-Shortcut `a` für Alle-wählen wenn Tabelle fokussiert.

#### Umsetzung konkret
- **Entity:** `framework_tags` als Tabelle `entity_framework_mapping` (polymorph) ODER Tags-Feld pro Entity (je nach DB-Präferenz). Default: separate Mapping-Tabelle.
- **Neu:** `App\Service\BulkTagService` mit `applyTags(iterable $entities, array $tags, User $actor)` — Audit-Log pro Änderung.
- **Controller:** Bulk-Endpoint pro Entity-Typ oder generisch via Entity-Type-Parameter.

#### Akzeptanzkriterien
- [ ] 100 Controls in einer Aktion tagbar, < 5 Sekunden Antwortzeit.
- [ ] Tag-Änderung im Audit-Log nachvollziehbar (pro Entity ein Eintrag).
- [ ] Filter nach Framework-Tag funktioniert in allen 4 Entity-Listen (Asset, Control, Risk, Supplier).
- [ ] Bulk-Action undo-fähig (als letzte Aktion rückgängig machbar) ODER mit Confirm-Dialog versehen.

#### Aufwand
- **Backend (Tagging + BulkService):** 2 FTE-Tage.
- **Frontend (Bulk-Actions-Bar + Filter):** 2–3 FTE-Tage.
- **Gesamt:** 4–5 FTE-Tage.

---

### 🟠 WS-6 · Gap-Report mit FTE-Schätzung (P2)

#### 👔 CM — Problem
- Wizard-Assessment liefert Prozent-Abdeckung. Mir fehlt die Übersetzung in **Personentage-Aufwand** pro Lücke.
- Ohne das keine Quartalsplanung.

#### 🎓 SC — Methodik
- **Effort-Schätzung pro Requirement** als `base_effort_days` im Framework-Katalog — vom Consultant initial befüllt, intern korrigierbar.
- **Formel:** `gap_effort = (1 - fulfillment_%) × base_effort_days × complexity_factor(tenant_size)`.
- **Ausgabe:** Gap-Report pro Framework, sortierbar nach Aufwand, gruppierbar nach Kategorie.

#### 🎨 UX — Umsetzung
- **Erweiterung bestehender Wizard-Results-Seite** — neue Spalte "Geschätzter Aufwand (Personentage)" in Requirement-Tabelle.
- **Neu:** Sortierung nach Aufwand (absteigend) als Default-View → "Quick Wins" (kleiner Aufwand, hohe Wirkung) stehen oben.
- **Budget-Simulator** (nice-to-have, separat):
  - Slider "verfügbare Personentage pro Quartal" → Tool markiert, welche Requirements damit erreichbar sind.
  - Nutzt bestehende Bootstrap `<input type="range">` + Stimulus-Controller.
- **A11y:**
  - Slider mit `aria-valuemin/max/now/text`.
  - Aufwand-Änderungen via `aria-live="polite"`.

#### Umsetzung konkret
- **Entity-Erweiterung:** `ComplianceRequirement.baseEffortDays: ?int` und `ComplianceRequirementFulfillment.adjustedEffortDays: ?int` (tenant-override).
- **Service:** `GapEffortCalculator::calculate(Tenant, ComplianceFramework)` — liefert sortierte Gap-Liste.
- **Consultant liefert:** initiale `base_effort_days` für 27001 + NIS2 + DORA + TISAX (einmalig, als Seed-Daten).

#### Akzeptanzkriterien
- [ ] Jede Requirement hat entweder Default-Aufwand oder ist als "nicht geschätzt" erkennbar.
- [ ] Tenant kann eigene Schätzung überschreiben, Default bleibt als Fallback.
- [ ] Report exportierbar (PDF + Excel).
- [ ] Summe Aufwand pro Framework und gesamt sichtbar.

#### Aufwand
- **Backend (Entity + Service):** 2 FTE-Tage.
- **Frontend (Tabellen-Erweiterung + optional Slider):** 1–2 FTE-Tage.
- **Consultant-Seed-Daten:** 1–2 Consultant-Tage.
- **Gesamt:** 3–4 FTE-Tage + Consultant.

---

### 🟡 WS-7 · Compare-PDF & Cross-Framework-Scheduled-Reports (P3)

#### 👔 CM — Problem
- `/compliance-wizard/compare` rendert HTML, aber kein PDF.
- `ScheduledReport` unklar ob Cross-Framework-Reports planbar sind.

#### 🎓 SC — Methodik
- **Geringer Aufwand** — Wizard-Compare-Template nochmal in PDF-Template rendern, ScheduledReport-Typ "portfolio" ergänzen.

#### 🎨 UX — Umsetzung
- Kein neues UI — Export-Button im Compare-View analog zu anderen `export/pdf`-Routen.
- Scheduled-Reports-Config: Framework-Auswahl als Multi-Select statt Single.
- **Wiederverwendung:** bestehende PDF-Template-Infrastruktur (DomPDF/wkhtmltopdf laut CompliancewizardController:200).

#### Akzeptanzkriterien
- [ ] `/compliance-wizard/compare/export/pdf` existiert und liefert gültiges PDF.
- [ ] ScheduledReport kann Typ "portfolio" mit Framework-Liste speichern.
- [ ] Monatliche Auslieferung per E-Mail funktioniert End-to-End.

#### Aufwand: 2 FTE-Tage.

---

### 🟡 WS-8 · Setup-Wizard "was hast du schon?"-Dialog (P3)

#### 👔 CM — Problem
- Deployment-Wizard fragt nicht nach bestehenden Zertifizierungen.
- Aus Reuse-Sicht wertvoll: wer 27001-zertifiziert ist, soll beim NIS2-Onboarding Mapping-Vererbung sofort sehen.

#### 🎓 SC — Methodik
- Zwei-Fragen-Dialog:
  1. *"Welche Frameworks sind bereits umgesetzt / zertifiziert?"* (Multi-Select mit Datum + Zertifikat-Upload optional).
  2. *"Welche Frameworks sollen neu hinzukommen?"*
- Daraus generiert Tool: Mapping-Vorschlag + Delta-Assessment (WS-1) + Aufwand-Schätzung (WS-6) **bevor** Zeit in Setup investiert wird.

#### 🎨 UX — Umsetzung
- Neuer Wizard-Step nach `ComplianceFrameworkSelectionType`.
- Pattern: bestehender Form-Wizard-Stepper aus `DeploymentWizardController`.
- Ausgabe: One-Pager "Dein Reuse-Potenzial" vor finaler Modul-Aktivierung.
- **A11y:** Stepper-Navigation mit Tastatur (bereits Standard).

#### Akzeptanzkriterien
- [ ] Neuer Setup-Step lädt < 1 Sekunde.
- [ ] Überspringbar für neue Organisationen ohne Vor-Zertifizierung.
- [ ] Reuse-Vorschau zeigt korrekte Delta-Prozente.

#### Aufwand: 2–3 FTE-Tage.

---

## Phasierung

### Sprint 1 (Woche 1–2, ~14–17 FTE-Tage) — *"Fundament nutzen"*
- **WS-1 Mapping-basierter Ableitungsvorschlag + Mapping-Versionierung** (Kern-Funktionalität)
- **WS-2 Import-Wizard Backend** + Excel-Format zuerst

### Sprint 2 (Woche 3–4, ~10–13 FTE-Tage) — *"Daten einmal, Nutzen vielfach"*
- **WS-2 Import-Wizard Frontend** + BSI-Profile-Format
- **WS-3 Supplier DORA**
- **WS-4 Cross-Framework-Report**

### Sprint 3 (Woche 5–6, ~8–10 FTE-Tage) — *"Skalierung & Planung"*
- **WS-5 Framework-Tagging + Bulk-Ops**
- **WS-6 Gap-Report mit FTE**
- Consultant-Seed-Daten einspielen

### Sprint 4 (Woche 7, ~4–6 FTE-Tage) — *"Abrunden"*
- **WS-7 Compare-PDF + Scheduled**
- **WS-8 Setup-"was hast du schon?"**
- End-to-End-Test mit Testszenario "27001 → NIS2 → DORA"

**Gesamtaufwand:** 36–46 FTE-Tage + 2–3 Consultant-Tage *(Version 1.1: +2 FTE-Tage vs. 1.0 für Mapping-Versionierung und Pflicht-Review-Infrastruktur).*

---

## Consultant-Leistung (explizit)

| Leistung | Zeitpunkt | Aufwand | Lieferformat |
|---|---|---|---|
| Mapping-Katalog 27001 → NIS2 validiert (Prozente + Rationale) | vor WS-1 | 2 Consultant-Tage | Excel-Template |
| Import-Beispieldateien (Excel + BSI-Profile) | vor WS-2 | 0,5 Tag | Dateien + Dokumentation |
| `base_effort_days` für 27001/NIS2/DORA/TISAX | für WS-6 | 1–2 Tage | CSV mit Review-Sitzung |
| Review vor Sprint 2 | zwischen Sprint 1 und 2 | 0,5 Tag | Workshop 4h |
| Abnahme-Audit End-to-End | nach Sprint 4 | 1 Tag | schriftlicher Bericht |

**Nach Abnahme:** Consultant nicht mehr dauerhaft eingebunden. Interner Compliance-Manager + ISB setzen Folge-Frameworks selbst um.

---

## Test-Strategie

### Unit & Integration
- `php bin/phpunit tests/Service/ComplianceRequirementFulfillmentServiceTest.php` (neu erweitert um Inheritance-Tests).
- Import-Format-Tests mit Fixture-Dateien im Repo (`tests/Fixtures/import/`).
- Mapping-Propagation-Test: Ändern von Source-Fulfillment → Assertion auf Ziel-Fulfillments.

### End-to-End-Szenario (`tests/E2e/ComplianceReuseJourneyTest.php` *neu*)
Realistisches Szenario:
1. Neuer Tenant, `core + assets + controls + compliance` aktiviert.
2. 27001-Framework laden, 30 Controls auf implemented, 20 auf partial setzen.
3. NIS2-Framework aktivieren.
4. **Erwartung:** mind. 40 NIS2-Fulfillments haben `derivedFrom` gesetzt.
5. Cross-Framework-Report aufrufen → Matrix zeigt beide Frameworks mit korrekten Prozenten.
6. Consultant-Excel importieren, Override 5 Fulfillments → Ableitungs-Metadaten bleiben erhalten, Override-Reason gespeichert.
7. Gap-Report → Aufwand-Summe plausibel (nicht 0, nicht extrem).

### UX / A11y
- **WCAG 2.2 Level AA** für alle neuen Screens:
  - Axe DevTools automatisiert.
  - NVDA / VoiceOver manuell für WS-1 (Ableitungs-Badge), WS-2 (Import-Stepper), WS-4 (Matrix).
  - Tastatur-only-Test für Bulk-Actions-Bar (WS-5).
  - Farbenblinden-Simulation für WS-4-Matrix.
- **Responsive:** 375px / 768px / 1920px — alle neuen Screens.

### Performance
- Ableitungsvorschlag-Erzeugung bei 500 Requirements: < 10 Sekunden (Batch, transaktional).
- Cross-Framework-Report bei 3 Frameworks × 100 Requirements: < 2 Sekunden.
- Import 500-Zeilen-Excel: < 30 Sekunden Preview + Commit zusammen.

---

## Erfolgs-Metriken (nach 6 Monaten messbar)

| Metrik | Baseline (heute) | Ziel (post WS-1 bis WS-6) |
|---|---|---|
| FTE-Tage bis produktives NIS2-Onboarding (wenn 27001 besteht) | ~15 Tage | **≤ 3 Tage** |
| Manuelle Daten-Eingaben bei Supplier (3 Frameworks) | 3× | **1×** |
| Zeit bis CISO-Monatsreport fertig | ~2 FTE-Tage | **< 15 min** |
| Anteil durch Mapping-Vorschlag vorerfasster Fulfillments nach Framework-Add (Review-pflichtig) | 0 % | **≥ 60 %** |
| Onboarding-Fähigkeit ohne Consultant nach Erstprojekt | nein | **ja** |

---

## Abnahme & Freigabe

- [x] **Compliance-Manager** zeichnet den Plan nach Sprint-Planning frei. → *Freigabe unter Auflagen am 2026-04-17, siehe Abschnitt "CM-Abnahme-Protokoll".*
- [x] **Senior-Consultant** Mapping-Audit abgeschlossen (2026-04-17, siehe `docs/CONSULTANT_MAPPING_AUDIT.md`). NIS2 ↔ ISO-27001-Katalog geliefert als `fixtures/mappings/public/nis2_iso27001_v1.csv` (40 Zeilen Sprint-1-Seed, Rest bis Mitte Sprint 1). Import-Template geliefert als `fixtures/mappings/_templates/import_template_v1.csv`.
- [x] **UX-Specialist + Backend** WS-1-Umsetzungsdesign abgeschlossen, siehe `docs/WS1_UX_DEV_DESIGN.md` (User-Flows, Screen-Wireframes, Komponenten, Stimulus-Controller, Backend-Contract, A11y, Tagesplan Sprint 1).
- [ ] **UX-Specialist** reviewt neue Komponenten vor Sprint-Abschluss.
- [ ] **ISB** prüft Plan vor Sprint 1 (Audit-Fähigkeit, Wortwahl, Rollen) und bestätigt Umsetzung nach Sprint 2. → *Review-Dokument: `docs/DATA_REUSE_PLAN_REVIEW_ISB.md`.*
- [ ] **CISO** zeichnet Gesamtabnahme nach Sprint 4 gegen (eine Unterschrift-Zeile im PDF-Export des Portfolio-Reports).

---

## CM-Abnahme-Protokoll

> **Datum:** 2026-04-17
> **Freigabe-Status:** 🟢 **Freigegeben unter Auflagen** — Sprint 1 darf starten sobald Auflagen 1–3 erfüllt sind.
> **Freigabe durch:** Compliance-Manager / Head of GRC

### Gesamteinschätzung
Plan ist strukturell sauber: drei Stimmen nachvollziehbar getrennt, Prioritäten stimmen (WS-1 zuerst korrekt — ohne Ableitungs-Mechanik mit Pflicht-Review sind die nachgelagerten Workstreams kosmetisch), FTE-Schätzungen plausibel, Erfolgs-Metriken messbar formuliert. Konsultant-Leistung sauber abgegrenzt inkl. Exit nach Sprint 4 — genau das Prinzip "Knowledge-Transfer, dann selbst machen".

**Freigabe erteilt.** Umsetzung kann bei Erfüllung der Auflagen unten starten.

### Auflagen (Pflicht vor Sprint-1-Start)

1. ~~**ISB-Review abwarten.**~~ → **erledigt 2026-04-17.** Plan v1.1 enthält alle 3 Major-Findings. Review-Dokument: `DATA_REUSE_PLAN_REVIEW_ISB.md`.
2. ~~**Baseline der Erfolgs-Metriken messen.**~~ → **erledigt 2026-04-17.** Baseline dokumentiert (außerhalb Repo, tenant-intern).
3. ~~**Offene Entscheidungen 1–5 klären.**~~ → **erledigt 2026-04-17, Workshop-Protokoll in Anhang C.**

**Alle Auflagen erfüllt. Sprint 1 kann gestartet werden.**

### Bedingte Änderungen am Plan (unmittelbar vor Sprint 1 einzuarbeiten)

4. **Rollback-Strategie für WS-1 fehlt.** Batch-Vorschlagserzeugung auf 500 Requirements ist ein Massen-Schreibvorgang auf Live-Daten — auch wenn die Werte im `pending_review`-Status landen. Plan muss ergänzen:
   - Feature-Flag `compliance.mapping_inheritance.enabled` (dark-launch-fähig).
   - Ableitungen als eigene Transaktion pro Framework-Aktivierung, komplett reversibel über Inheritance-Log.
   - Dry-Run-Modus: zeigt was abgeleitet würde, ohne zu schreiben.
5. **Budget-Freigabe nach Sprint, nicht Gesamt.** Gehe nach Sprint 1 in Go/No-Go-Review. Wenn Metriken nicht bestätigen (Mapping-basierter Vorschlag spart tatsächlich Zeit) — Re-Priorisierung möglich ohne das ganze Paket zu committen.
6. **CISO-Stakeholder-Information vor Start.** CISO muss wissen, dass Änderungen am Compliance-Datenmodell laufen — nicht als Überraschung im Monats-Review. Einzeiler-Memo genügt.

### Was ausdrücklich kein Blocker ist
- Open Decision 2 (Override-Kaskade) — wird durch ISB-Review mitentschieden, passt in Auflage 3.
- Consultant-Mapping-Katalog-Lizenz (Open Decision 3) — klärbar parallel, blockiert WS-1-Backend-Arbeit nicht.
- DORA-Modul-Frage (Open Decision 5) — Empfehlung im Plan (nein, bleibt Teil `compliance`) ist OK.

### Sprint-1-Zielbild aus CM-Sicht
Nach Sprint 1 muss ich dem CISO demonstrieren können:
> *"Tenant hat 27001 bei 75 %, wir aktivieren NIS2, Tool schlägt für 48 Anforderungen einen Ableitungsvorschlag vor. ISB prüft in 3 Stunden, statt 10 Arbeitstage neu zu bewerten."*

Wenn Sprint 1 das **nicht** liefern kann (z.B. Import noch nicht fertig, Mapping-Katalog fehlt), ist Sprint-1-Abschluss nicht erreicht — auch wenn Code komplett ist.

### Unterschrift
- **Compliance-Manager:** _______________ Datum: 2026-04-17
- **Anmerkung:** Freigabe unter Auflagen 1–3. Finalfreigabe für Budget-Commit nach Sprint-1-Review und ISB-Signoff.

---

## Offene Entscheidungen — erledigt

Alle 5 offenen Entscheidungen wurden im Workshop 2026-04-17 getroffen. Protokoll siehe **Anhang C · Entscheidungsprotokoll Workshop 2026-04-17** (weiter unten). Netto-Aufwandsänderung: 0 FTE-Tage.

---

## Input in Managementbewertung (ISO 27001 9.3)

> Adressiert **OBS-2** aus `docs/DATA_REUSE_PLAN_REVIEW_ISB.md`. Der Plan ist selbst ein Input für die nächste Managementbewertung.

### 9.3.2 c · Änderungen externer/interner Themen

Die Data-Reuse-Initiative ist ein signifikanter interner Kontextwechsel: Einführung eines Mapping-basierten Ableitungs-Mechanismus mit 4-Augen-Prinzip, Versionierung von Cross-Framework-Mappings, Rollenmatrix gemäß A.5.3. Relevant für das ISMS, weil:

- **Scope-Abgrenzung** (Klausel 4.3) wird durch Framework-Tagging (WS-5) präziser abbildbar; Scope-Creep durch nachgeladene Frameworks ist über die Cross-Framework-Matrix (WS-4, Portfolio-Report) jederzeit nachweisbar.
- **Interessierte Parteien** (Klausel 4.2) — Auditoren, externe Berater, Auftraggeber (DORA-regulierte Mandanten) — profitieren direkt von Round-Trip-Import/Export und Stichtag-Reproduzierbarkeit (WS-2 + WS-4).
- **Externe Änderungen** — NIS2-UmsuCG Inkrafttreten, DORA-ITS Veröffentlichung, BSI C5:2026 Final Release — lösen automatisch Aktualisierungsbedarf aus. Der Loader-Fixer + das Upsert-Verhalten der Framework-Loader sichern die Nachpflege *ohne* Datenverlust.

### 9.3.2 g · Chancen zur fortlaufenden Verbesserung

Drei konkrete Verbesserungen, die aus der Umsetzung hervorgegangen sind und als Input für die Managementbewertung empfohlen werden:

| # | Verbesserung | Owner | Zieltermin |
|---|---|---|---|
| V-1 | **Consultant-Exit nach Sprint 4** — Folge-Framework-Onboardings (nächstes: ISO 27017 Cloud Controls) werden ohne externe Beratung umgesetzt; Methodik steht im Repo. | CM | Q3 2026 |
| V-2 | **Mapping-Versionsdrift-Detektor** — automatisches Flag im Portfolio-Report, wenn aktiv genutzte Mappings seit > 12 Monaten nicht reviewt wurden (Audit-Pflege-Signal). | ISB + DevOps | Q4 2026 |
| V-3 | **Tag-Decay-Strategie** — Tag-Relationen älter als 24 Monate ohne Reassessment bekommen Review-Flag; verhindert "Scope-Drift durch Vergessen". | ISB | H1 2027 |

Diese drei Punkte gehören explizit in die nächste Managementbewertung als "Chance zur Verbesserung" gem. 9.3.2 g — zusätzlich zu den üblichen KPI-Beobachtungen.

---

## Anhang A · Rollenmatrix (MAJOR-3 aus ISB-Review)

> **Norm-Bezug:** ISO 27001:2022 A.5.3 (Funktionstrennung), A.5.15 (Zugriffskontrolle), A.5.18 (Zugriffsrechte).
>
> **Gilt für:** alle kritischen Aktionen dieses Plans. Implementierung über Symfony `Voter` pro Aktion. Audit-Log-Einträge MÜSSEN `actor_user_id`, `actor_role`, bei 4-Augen-Aktionen `four_eyes_approver_id` enthalten.

| Aktion | WS | USER | AUDITOR | MANAGER | ADMIN | 4-Augen? | Begründungspflicht? |
|---|---|---|---|---|---|---|---|
| Framework aktivieren (Trigger Vorschlagserzeugung) | WS-1 | — | lesen | ausführen | ausführen | empfohlen | nein |
| Ableitungsvorschlag erzeugen (Batch/Dry-Run) | WS-1 | — | lesen | ausführen | ausführen | nein | nein |
| Vorschlag bestätigen (Status `confirmed`) | WS-1 | — | lesen | ausführen | ausführen | nein | ja (Prüferkommentar ≥ 20 Zeichen) |
| Vorschlag ablehnen | WS-1 | — | lesen | ausführen | ausführen | nein | ja (Ablehnungsgrund) |
| Statusübergang auf `implemented` (unabhängig von Quelle) | WS-1 | — | lesen | ausführen | ausführen | **ja** (Ersteller ≠ Freigeber) | ja |
| Manuellen Override setzen (abgeleiteten Wert überschreiben) | WS-1 | — | lesen | ausführen | ausführen | **ja** | ja (Override-Reason ≥ 20 Zeichen) |
| Mapping versionieren / ändern (neuer Datensatz) | WS-1 | — | lesen | ausführen | ausführen | **ja** | ja (Änderungsgrund) |
| Import-Commit < 50 Zeilen | WS-2 | — | — | ausführen | ausführen | nein | nein |
| Import-Commit ≥ 50 Zeilen | WS-2 | — | — | ausführen | ausführen | **ja** | nein |
| Bulk-Tag hinzufügen | WS-5 | — | lesen | ausführen | ausführen | nein | nein |
| Bulk-Tag entfernen (rückwirkend, Soft-Delete) | WS-5 | — | lesen | — | ausführen | **ja** | ja |
| FTE-Schätzung tenant-intern überschreiben | WS-6 | — | lesen | ausführen | ausführen | nein | ja (Override-Reason ≥ 20 Zeichen) |
| Cross-Framework-Report exportieren (PDF/Excel) | WS-4 | — | ausführen | ausführen | ausführen | nein | nein |
| Scheduled-Report konfigurieren / Empfänger setzen | WS-7 | — | — | ausführen | ausführen | nein | nein |
| Setup-Wizard "was hast du schon?" abschließen | WS-8 | — | — | — | ausführen | nein | nein |

**Technische Umsetzung:**
- Ein `ComplianceActionVoter` je Aktionsgruppe (Inheritance, Import, Tagging, Reporting).
- `FourEyesApprovalService` als zentrale Logik: `requestApproval()` → Pending-State mit TTL 7 Tage → zweiter Nutzer bestätigt via UI.
- Audit-Log-Erweiterung: Pflichtfelder `actor_role`, `four_eyes_approver_id` (nullable), `justification` (nullable).
- **Wichtig:** AUDITOR erhält **in keiner Zeile** Schreibrechte (Read-only-Konsistenz, vgl. `NAVIGATION_IMPROVEMENT_PLAN.md` Phase 6).

**Akzeptanzkriterium (quer über alle WS):**
- [ ] Test pro Aktion: Nutzer ohne Rolle wird blockiert (HTTP 403).
- [ ] Test pro 4-Augen-Aktion: Ausführung durch denselben Nutzer (Ersteller = Freigeber) wird blockiert.
- [ ] Test: Audit-Log enthält `actor_role` + (wo zutreffend) `four_eyes_approver_id`.

---

## Anhang C · Entscheidungsprotokoll Workshop 2026-04-17

> **Format:** 2-h-Workshop, moderiert durch CM.
> **Teilnehmer:** CM (Moderation, Entscheidung), ISB (Audit/Compliance-Sicht), Consultant (extern, Markt-Benchmark), UX (Frontend-Auswirkung), Backend-Vertretung (Machbarkeit).
> **Ergebnis:** 5 Entscheidungen getroffen, dokumentiert, in Plan eingearbeitet.

### ENT-1 · Tag-Architektur für Framework-Relevanz (WS-5)

**Frage:** Polymorphe Tag-Tabelle über alle Entities *vs.* Tag-Feld pro Entity?

| Option | Pro | Contra |
|---|---|---|
| **A — Polymorph** (`entity_tag`-Tabelle mit `entity_type`, `entity_id`, `tag_id`) | einmal gebaut, überall nutzbar; konsistente Bulk-Ops-API; saubere Tag-Historie; Erweiterung auf neue Entities trivial | ein Join-Hop mehr; Symfony/Doctrine-polymorph ist keine Erstwahl — erfordert eigene Repository-Disziplin |
| B — Feld pro Entity (`framework_tags: json`) | wenig Code je Entity; Standard-Doctrine-Query einfach | Duplikation der Filter-/Bulk-Logik an 4+ Stellen; Historie pro Entity erneut aufzubauen; Tag-Stammdatum fehlt |

**Stimmen:**
- *CM:* Data-Reuse-Prinzip → Option A. Kein Kopieren von Tag-Infrastruktur pro Entity.
- *ISB:* MINOR-2 aus Review (Soft-Delete-Historie) zentral lösbar → Option A.
- *Consultant:* Verinice/HiScout nutzen zentrale Tag-Tabellen. Markt-Konvention → Option A.
- *UX:* Beide Optionen aus Sicht `_bulk_actions_bar.html.twig` egal — Endpoint abstrahiert. Keine Präferenz.
- *Backend:* Polymorph in Doctrine machbar über Discriminator-lose `entity_class` + `entity_id` + Guard-Service. ~1 Tag Mehraufwand vs. Option B, aber amortisiert sich ab Entity Nr. 2.

**Beschluss:** **Option A — Polymorph.** Neue Tabelle `entity_tag` mit `(entity_class, entity_id, tag_id, tagged_from, tagged_until, tagged_by, removal_reason)`. Tag-Stammdatum als eigene Entity `Tag(name, type, framework?)`.
**Plan-Auswirkung:** WS-5-Aufwand bleibt 4–5 FTE-Tage, interne Aufteilung anders (1 Tag Polymorph-Infrastruktur, 2 Tage pro-Entity-Integration). MINOR-2 zentral mitgelöst.

### ENT-2 · Override-Strategie bei Mapping-Änderung (WS-1)

**Frage:** Wenn eine Source-Fulfillment sich ändert — was passiert mit abgeleiteten Ziel-Fulfillments?

| Option | Pro | Contra |
|---|---|---|
| A — Stille Kaskade | "immer aktuell" | Audit-Schock: Werte ändern sich unsichtbar; widerspricht MAJOR-1 Pflicht-Review |
| **B — Benachrichtigung + manuelle Bestätigung** | Review-Pflicht bleibt; Audit-Trail sauber | Pflege-Aufwand für Ziel-Fulfillment-Owner |
| C — Hard-Hold (keine Aktion) | simpel | abgeleitete Werte laufen zeitlich auseinander |

**Stimmen:**
- *ISB:* Option A = sofortige NC. Option B ist konsistent mit MAJOR-1. Option C lässt Werte veralten → schlechte Nachweisqualität.
- *CM:* Option B. Aufwand akzeptabel gegen Audit-Risiko.
- *Consultant:* Drata macht Option A, hat regelmäßig genau diesen Audit-Ärger. Option B ist sauber.
- *UX:* Option B braucht Notification-UI — passt in bestehendes Notification-System. Zusätzliches UI-Element: Badge am betroffenen Fulfillment "Source aktualisiert — prüfen".
- *Backend:* Option B über Event-Listener + Notification-Service machbar. Kein neuer Infrastruktur-Bedarf.

**Beschluss:** **Option B — Benachrichtigung + manuelle Bestätigung.** Ableitungs-Status bleibt auf letztem bestätigten Wert; System erzeugt Review-Notification an Ziel-Fulfillment-Owner. Badge "Source aktualisiert" im UI. 14-Tage-Eskalation an ISB bei nicht bearbeiteten Notifications.
**Plan-Auswirkung:** WS-1 unverändert im Aufwand. Event-Listener ist bereits vorgesehen (Plan Zeile "Source-Fulfillment-Update → Notification an Ziel-Fulfillment-Owner, keine stille Kaskade"). ENT-2 bestätigt Text, keine weitere Änderung.

### ENT-3 · Lizenz des Consultant-Mapping-Katalogs

**Frage:** Der gelieferte NIS2 ↔ ISO-27001-Katalog (~110 Zeilen, siehe `CONSULTANT_MAPPING_AUDIT.md`) — als Repo-Asset open source oder als Tenant-Import kundenspezifisch?

| Option | Pro | Contra |
|---|---|---|
| **A — Kern-Katalog im Repo (öffentlich, Apache-2.0 oder CC-BY)** | Consultant-Exit sauber; jeder Tenant startet sofort mit Katalog; Reifegrad-Signal | Consultant gibt Stück IP ab; Konkurrenten können nutzen |
| B — Tenant-Import (kundenspezifisch, NDA) | Consultant-Geschäftsmodell geschützt | Jeder neue Tenant braucht wieder Consultant-Einsatz → widerspricht Plan-Prinzip "Knowledge-Transfer, dann selbst machen" |
| C — Hybrid | beste aus beiden | komplex, Abgrenzung fragil |

**Stimmen:**
- *Consultant:* Kern-Mappings aus **öffentlichen Quellen** (ENISA, BSI, NIST) dürfen in Repo. **Eigene Kuratierungs-Deltas, Branchen-Baselines** (Produktion, KRITIS-Health etc.) bleiben als separate Packs mit NDA. Option C.
- *CM:* Portfolio-Denken: Standard-Kataloge im Repo sind Tool-Stärke. Branchen-Packs als bezahlte Zusatzleistung OK.
- *ISB:* Herkunft pro Mapping muss im `source`-Feld stehen (Consultant-Audit MINOR-F6) — so sind Repo-Kataloge und Branchen-Packs trennbar.
- *UX:* Keine Auswirkung auf UI, solange Import-Wizard (WS-2) beide Quellen erkennt.
- *Backend:* Machbar via `source`-Enum-Wert (`public_enisa_2024` vs `consultant_branche_{xy}`).

**Beschluss:** **Option C — Hybrid.**
- **Im Repo (öffentlich, CC-BY 4.0):** Standard-Cross-Mappings auf Basis öffentlicher Quellen (ENISA NIS2, BSI-Kreuzreferenz, NIST SP 800-53 Mappings). Seed-Dateien unter `fixtures/mappings/public/`.
- **Nicht im Repo (optional per Import):** Branchen-Baselines, Kundenspezifika. Consultant liefert als Excel, Tenant importiert.
- **Kennzeichnung:** `ComplianceMapping.source` macht Herkunft pro Mapping sichtbar und exportierbar.

**Plan-Auswirkung:** WS-2 (Import-Wizard) muss beide Modi unterstützen (sowieso vorgesehen). Lizenzvermerk `CC-BY 4.0` als Kommentar-Header in Seed-Datei. Keine Aufwand-Änderung.

### ENT-4 · Scheduled-Report-Engine — bestehend nutzen oder erweitern?

**Frage:** `ProcessScheduledReportsCommand` + `ScheduledReport` Entity + `ScheduledReportService` sind vorhanden. Für WS-7 (Cross-Framework-Scheduling) und WS-4 (Portfolio-Report) ausreichend oder erweitern?

| Option | Pro | Contra |
|---|---|---|
| **A — Bestehend nutzen, Typ `portfolio` ergänzen** | Minimal-Invasiv; eine Engine, eine Fehlerquelle; Monitoring bereits konfiguriert | Vielleicht reicht Schema nicht für Multi-Framework-Parameter |
| B — Neue Engine für Portfolio-Reports | Saubere Trennung | zwei parallele Scheduler — Doppelte Betriebsarbeit; Inkonsistenz-Risiko |

**Stimmen:**
- *Backend:* `ScheduledReport` Entity hat nach Stichprobe Konfigurationsfelder die sich um Framework-Multi-Select erweitern lassen (json-Spalte reicht). Option A.
- *ISB:* Eine Engine = ein Audit-Log. Option A.
- *CM:* Minimal-Invasiv = schnell live. Option A.
- *UX:* Scheduled-Report-Config-Formular erweitern um Multi-Select statt Single — einfach.
- *Consultant:* Marktkonvention: ein Scheduler, viele Report-Typen. Option A.

**Beschluss:** **Option A — bestehend nutzen.** Erweiterung: neuer Report-Typ `portfolio` in `ScheduledReport.reportType` Enum, Config-Feld nimmt Framework-Liste als JSON-Array. Code-Review von `ScheduledReportService` vor WS-7 Sprint-Block, um sicherzustellen, dass der Versand-Pfad mit MINOR-4 (DSGVO-Empfängerkreis) erweiterbar ist.
**Plan-Auswirkung:** WS-7 bleibt 2 FTE-Tage. Backend-Vertretung dokumentiert evtl. Schema-Änderung in 30-min Pre-Flight.

### ENT-5 · DORA als eigenes Modul in `config/modules.yaml`?

**Frage:** Aktuell ist DORA ein Framework **innerhalb** des `compliance`-Moduls. Eigenständiges Modul sinnvoll?

| Option | Pro | Contra |
|---|---|---|
| A — Eigenes Modul `dora` | Branchenkunden (BaFin-reguliert) sehen eigene Kategorie; Aktivierung einfacher zu kommunizieren | Inflation der Module; gleicher Gedanke käme für NIS2, TISAX, … → jedes Framework würde ein Modul |
| **B — Bleibt im `compliance`-Modul, Framework-Aktivierung via Wizard** | konsistent mit Architektur (Module = Funktionsblöcke, Frameworks = inhaltliche Kataloge); weniger Config-Pflege | Sichtbarkeit in Navigation schlechter — NAVIGATION_IMPROVEMENT_PLAN Phase 3.2 adressiert |

**Stimmen:**
- *Backend:* Module entsprechen Entity-/Service-Bündeln. DORA bringt keine eigenen Entities, die nicht Supplier/Risk/BC-Plan sind. Option B.
- *ISB:* Rolle/Scope-Anforderungen werden durch Framework-Aktivierung getriggert, nicht durch Modul. Option B.
- *CM:* Trennung Modul = Funktion vs. Framework = Katalog klar. Option B.
- *Consultant:* Drata/Vanta machen Option B, Archer ist das Gegenbeispiel (für jedes Framework ein Control-Pack als "Modul"). Archer-Ansatz wird in der Praxis als schwergewichtig kritisiert. Option B.
- *UX:* WS-3 Supplier-DORA-Tab und WS-4 Portfolio-Report haben das Triggering über `compliance`-Modul + aktiviertes DORA-Framework bereits abgebildet. Keine neue Logik nötig.

**Beschluss:** **Option B — bleibt im `compliance`-Modul.** Sichtbarkeit in Navigation wird über `NAVIGATION_IMPROVEMENT_PLAN.md` Phase 3.2 (Item-Level-Checks) gelöst.
**Plan-Auswirkung:** keine. Bestätigt bestehende Empfehlung. Open Decision im Plan-Abschnitt "Offene Entscheidungen" wird als geklärt gestrichen.

---

### Zusammenfassung — Plan-Auswirkungen

| Entscheidung | Plan-Änderung | FTE-Delta |
|---|---|---|
| ENT-1 Polymorph-Tags | WS-5 interne Aufteilung, Datenmodell spezifiziert | 0 |
| ENT-2 Notification statt Kaskade | WS-1 Text bestätigt, kein Edit | 0 |
| ENT-3 Hybrid-Lizenz | WS-2 muss zwei `source`-Modi unterstützen (bereits vorgesehen) | 0 |
| ENT-4 Bestehende Engine | WS-7 Backend-Review als Pre-Flight | 0 (evtl. +0,25) |
| ENT-5 kein DORA-Modul | Keine | 0 |

**Netto-Aufwandsänderung: 0 FTE-Tage.** Sprint 1 ist damit entscheidungsreif.

**Abschnitt "Offene Entscheidungen (vor Sprint 1 zu klären)" im Plan wird gestrichen** — Entscheidungen sind getroffen und oben dokumentiert.

---

## Anhang B · Referenzen auf bestehende Codebasis

| Artefakt | Pfad | Relevanz |
|---|---|---|
| Mapping-Entity | `src/Entity/ComplianceMapping.php` | Kern für WS-1 |
| Fulfillment-Entity | `src/Entity/ComplianceRequirementFulfillment.php` | Kern für WS-1, WS-6 |
| Fulfillment-Service | `src/Service/ComplianceRequirementFulfillmentService.php:156–189` | erweitern um Mapping-Inheritance |
| Mapping-Service | `src/Service/ComplianceMappingService.php:251,274` | bereits Cross-Framework-Insights |
| Cross-Framework-Mappings-Generator | `src/Command/CreateCrossFrameworkMappingsCommand.php` | Datenbasis |
| Wizard Compare | `src/Controller/ComplianceWizardController.php:209` | Grundlage WS-4, WS-7 |
| Management Reports | `src/Controller/ManagementReportController.php` | Ergänzung WS-4 |
| Deployment Wizard | `src/Controller/DeploymentWizardController.php` | Erweiterung WS-8 |
| Data-Import-Service | `src/Service/DataImportService.php` | Basis WS-2 |
| UX-Komponenten | `templates/_components/_card.html.twig`, `_badge.html.twig`, `_button_group.html.twig` | wiederverwenden |
| Styleguide | `docs/STYLE_GUIDE.md`, `docs/ARIA_ANALYSIS.md` | Einhaltung verpflichtend |
| ISB-Review | `docs/DATA_REUSE_PLAN_REVIEW_ISB.md` | Findings verbindlich |

---

## Änderungshistorie

| Version | Datum | Autor | Änderung |
|---|---|---|---|
| **1.1** | 2026-04-17 | CM | ISB-Major-Findings 1–3 eingearbeitet: (1) WS-1 umgeschrieben auf *"Mapping-basierter Ableitungsvorschlag mit Pflicht-Review"*, Status `inherited_pending_review`, 4-Augen für Statusübergang `implemented`, Pflicht-Prüferkommentar; (2) Mapping-Versionierung (`version`, `validFrom`, `validUntil`) zur Stichtag-Reproduzierbarkeit; (3) Rollenmatrix als **Anhang A** ergänzt. MINOR-5 (Wording) plan-weit adressiert. Aufwand +2 FTE-Tage. Status → Sprint-1-ready. |
| 1.0 | 2026-04-17 | CM + SC + UX | Initialer Plan mit 8 Workstreams nach Data-Reuse-Kritik. CM-Freigabe unter Auflagen. |

### Noch offen (nicht blockierend für Sprint 1)
- **MINOR-1 bis MINOR-4, MINOR-6** aus ISB-Review: Einarbeitung über Sprint 1–2 in betroffene Workstreams (WS-2 Row-Log, WS-5 Soft-Delete-Historie, WS-6 Override-Begründung, WS-7 DSGVO-Empfängerkreis, WS-3 DORA-ITS-Schema). Sprint-2-Abschluss blockiert bei Nichterfüllung gem. ISB-Sprint-2-Checkliste.
- **Observations OBS-1 bis OBS-4**: als Verbesserungshinweise in Backlog aufnehmen, keine Sprint-1-Relevanz.
