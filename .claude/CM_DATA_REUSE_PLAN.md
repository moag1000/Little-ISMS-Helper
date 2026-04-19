# Compliance-Manager Data-Reuse- & Cross-Norm-Plan

**Erstellt:** 2026-04-20
**Autor:** Compliance-Manager (Persona)
**Ausgangslage:** Audit-Doc v2.2 (`docs/audit/compliance_manager_analysis.md`), Score 98/100, alle 7 Ziel-Frameworks Tool-🟢.
**Ziel:** Die letzten 15 % Data-Reuse-Potenzial heben. Tool-Reife von *marktspitze* auf *benchmark-setzend*.

---

## Motivation

Das Tool ist im Mittelstands-Markt aktuell Spitze. Die Compliance-Manager-Sicht
zeigt aber: der Multi-Framework-Reuse läuft nur **in eine Richtung** (Requirement →
was deckt es, Control → zu welchen Frameworks). Die **Umkehrrichtung** und mehrere
**Cross-Norm-Transitivitäten** sind strukturell nicht oder nicht UI-nah verfügbar.

Ohne diese Hebel bleibt der CM auf der Note **8,5 / 10**; mit ihnen springt das
Tool auf **9,5 / 10** — und das Versprechen *„jedes Datum einmal pflegen, überall
wiederverwenden"* gilt durchgängig.

**Gesamt-Entwicklungsinvest: ~15 FTE-Tage.**
**Jährliche CM-Ersparnis: ~37 FTE-Tage** (ROI ≈ 5 Monate).

---

## Block A — Data-Reuse innerhalb eines Frameworks

### A1. Invertierte Sichten (Document/Asset/Supplier → Requirements)

**Status:** ❌ offen

**Problem:** Der CM öffnet ein Document und weiß nicht, *in welchen Requirements
über alle Frameworks hinweg* dieser Nachweis gerade gilt. Gleiches für Asset,
Supplier. Der M:M-Layer existiert (z. B. `ComplianceRequirement.evidenceDocuments`),
aber die Reverse-View fehlt.

**Umsetzung:**

- Document-Show-Page: Widget *„Dieser Nachweis deckt N Requirements in M Frameworks"*
  mit Klick-Drill-Down auf gruppierte Requirement-Liste pro Framework.
- Asset-Show-Page: *„Dieses Asset wird durch N Requirements in M Frameworks
  adressiert"* — Datenquelle `ComplianceRequirement.dataSourceMapping` (JSON-Feld
  mit entity-Referenzen) reverse-indizieren.
- Supplier-Show-Page: *„Dieser Lieferant triggert N Requirements (DORA Art. 28,
  27001 A.5.19–22, DSGVO Art. 28, NIS2 Supply-Chain)"*.

**Aufwand:** 3 FTE-Tage (1 Reverse-Index-Service + 3 Widget-Partials).

**Effekt:** Audit-Frage *„wo wird dieses Dokument sonst noch genutzt?"* in **10 s**
statt **5 min**. Verringert Dokumenten-Duplikation um schätzungsweise 30 %.

---

### A2. Auto-Mapping-Vorschläge bei Neuanlage

**Status:** ❌ offen (Similarity-Logik existiert in `ComplianceAnalyticsService`,
wird aber nur im Mapping-Wizard genutzt)

**Problem:** Bei einem neuen Control muss der CM manuell die Cross-Framework-
Mappings setzen. Mapping-Vollständigkeit bleibt typisch bei 60–70 %; jedes
fehlende Mapping = verlorene Reuse-Gelegenheit.

**Umsetzung:**

- Beim Speichern eines neuen Control/Risk/Asset eine Similarity-Suche über alle
  ComplianceRequirement-Texte laufen lassen und Top-5-Treffer als Vorschlag
  anzeigen: *„Dieses Control deckt vermutlich NIS2 21.2.c, BSI SYS.1.2.A2,
  TISAX 3.1.3 — jeweils mit 1 Klick übernehmen."*
- Confidence-Score anzeigen (z. B. 87 % Text-Ähnlichkeit).
- Mapping-Herkunft im Audit-Log als *„suggested (0.87) + user-confirmed"*
  markieren (Auditor-Transparenz).

**Aufwand:** 2 FTE-Tage (UI-Anbindung bestehender Similarity-Logik + Audit-Log).

**Effekt:** Mapping-Vollständigkeit 60 % → 85 %+ ohne zusätzliche Pflege.

---

### A3. Consultant-Template-Import

**Status:** ⚠️ nur DORA CSV importierbar, alles andere manuell.

**Problem:** Consultants liefern Mapping-Templates als Excel/CSV/XML. Der CM muss
sie heute abtippen. Zitat aus CM-Playbook: *„Unser Consultant hat ein Mapping-
Template geschickt — kann ich das importieren?"*

**Umsetzung — priorisierte Formate:**

| Format | Publisher | Scope | Aufwand |
|--------|-----------|-------|---------|
| Verinice-XML | Verinice | 27001-Mappings, deutscher Beratermarkt-Standard | 1 FTE-Tag |
| VDA-ISA Template | VDA | TISAX-Self-Assessment Excel | 0,5 FTE-Tag |
| NIST CSF-CSV | NIST | CSF 2.0 Functions/Categories/Subcategories | 0,5 FTE-Tag |

**Aufwand:** 2 FTE-Tage (drei Importer + gemeinsame Parser-Basis).

**Effekt:** 3–5 FTE-Tage Nacharbeit pro Consultant-Engagement eingespart.

---

### A4. One-Click-Audit-Paket-Export

**Status:** ❌ offen

**Problem:** Vor einem Zertifizierungs-Audit sammelt der CM manuell Evidence-
Dokumente pro Requirement → ZIP → an Auditor. Typisch 2–3 Arbeitstage pro Audit.

**Umsetzung:**

- Neue Route `/audit-package/{framework}` erzeugt ZIP:
  - Ordnerstruktur pro Anforderung (`A.5.1_Policies/`, `A.5.2_Roles/`, …)
  - Pro Ordner: alle verlinkten Evidence-Dokumente (Document-Download-Stream)
  - Pro Ordner: auto-generierte PDF-Summary mit Requirement-Text, Control-
    Zuordnung, Implementation-Status, Verantwortlichen
  - `INDEX.csv` mit Requirement ↔ Dokument-Zuordnung für Auditor-Navigation
- Gated by ROLE_AUDITOR / ROLE_MANAGER.
- Im AuditLog dokumentieren: *„Audit-Package exportiert, Framework X, Y
  Dokumente, SHA-256 <hash>"*.

**Aufwand:** 1,5 FTE-Tage (Zip-Service, PDF-Generator nutzt bestehende
`PdfExportService`).

**Effekt:** 2–3 FTE-Tage **pro Audit** eingespart. Bei 4 Audits/Jahr = 10 FTE-
Tage aus einem einzigen Feature.

---

## Block B — Cross-Norm-Transitivität

### B1. Transitiv-Abdeckung explizit visualisieren

**Status:** ⚠️ Berechnung existiert (`calculateFulfillmentFromControls`), UI
zeigt sie aber nicht durchgängig.

**Problem:** Wenn Control A.5.1 als *implemented* markiert ist, zeigen NIS2-
Dashboard, BSI-Check-View und TISAX-Requirement-Seiten *nicht* automatisch:
*„diese Anforderung ist transitiv zu 80 % gedeckt — Herkunft: 3 gemappte 27001-
Controls"*. Der CM muss die Kette manuell nachvollziehen.

**Umsetzung:**

- Neue Komponente `_transitive_coverage_badge.html.twig`:
  - Inputs: Requirement + Tenant
  - Output: Badge mit Prozent + Tooltip mit Control-Herkunft
- Einbinden auf:
  - SoA-Statement (zeigt NIS2/BSI/TISAX-Transitiv-Coverage der 27001-Controls)
  - NIS2-Dashboard-Letter-Cards (*„gespeist durch Control X, Y, Z"*)
  - BSI-Grundschutz-Check-Anforderungszeilen (MUSS/SOLLTE-Zeile → Herkunft)
  - TISAX-Requirement-Show

**Aufwand:** 1,5 FTE-Tage.

**Effekt:** Der CM zeigt Auditoren den Datenfluss *auf Knopfdruck*. Kernargument
für Cross-Framework-Reuse.

---

### B2. Offizielle Kreuztabellen als Seed-Command

**Status:** ❌ offen

**Problem:** BSI, VDA, NIST und EBA publizieren offizielle Mapping-Tabellen.
Diese müssen manuell gepflegt werden; typisch bleibt Mapping-Vollständigkeit
bei 60–70 %. Mit offiziellen Seeds springt sie auf 90 %+ und gewinnt **Audit-
Glaubwürdigkeit** (*„das ist die offizielle BSI-Zuordnung, nicht unsere"*).

**Umsetzung — priorisierte Seeds:**

| Seed | Publisher | Umfang | Aufwand |
|------|-----------|--------|---------|
| BSI IT-Grundschutz ↔ 27001 Anhang A | BSI | ~400 Mappings | 0,75 FTE-Tag |
| VDA ISA 6.0 ↔ 27001 | VDA | ~80 Mappings | 0,25 FTE-Tag |
| NIST CSF 2.0 ↔ 27001 | NIST | ~130 Mappings | 0,5 FTE-Tag |
| DORA RTS ↔ 27001 | EBA/EIOPA/ESMA | ~60 Mappings | 0,5 FTE-Tag |

**Aufwand:** 2 FTE-Tage (1 gemeinsames Seed-Pattern + 4 Tabellen).

**Effekt:** Cross-Framework-Mapping-Vollständigkeit 60 % → 90 %+ auf Knopfdruck.

---

### B3. ISO 27701 Privacy-Overlay

**Status:** ❌ offen

**Problem:** ISO 27701 ist *per Definition* eine Erweiterung von 27001 —
*„27001-Control + PII-Zusatz"*. Ein DSGVO-reifer Mandant könnte binnen FTE-
Tagen 27701-zertifizierungsreif sein, muss aber heute parallel ein zweites
Management-System pflegen.

**Umsetzung:**

- Neues Feld `Control.privacyExtension` (Textarea) + UI-Hinweis *„optional, für
  27701-Nachweis"*.
- Automatisches 27701-Requirement-Mapping pro 27001-Control generieren beim
  Aktivieren des 27701-Frameworks.
- Overlay-Report: *„Ihre 27001-Compliance = 99 %, davon 73 % 27701-tauglich,
  für den Rest fehlt nur die PII-Ergänzung pro Control"*.

**Aufwand:** 1,5 FTE-Tage.

**Effekt:** Ein DSGVO-Mandant wird *innerhalb weniger Tage* 27701-zertifizierungs-
reif statt mehrere Monate paralleler Aufbau.

---

### B4. Multi-Framework-Audit-Programm

**Status:** ❌ offen (AuditFinding hat M:M zu Control, aber Audit selbst ist
auf 1 Framework beschränkt)

**Problem:** Ein interner Audit prüft real oft 27001 + NIS2 + DORA gleichzeitig.
Heute ist das im Tool nur als drei getrennte Audits abbildbar → dreimal
Aufwand für Protokoll, Bericht, Stakeholder-Bindung.

**Umsetzung:**

- `InternalAudit.frameworks` als M:M statt Einzelrelation (Migration
  idempotent).
- Jeder Finding wird auf alle betroffenen Frameworks gemappt (bereits über
  `AuditFinding.control.complianceRequirements` transitiv möglich, aber
  UI-sichtbar als Multi-Framework-Badge pro Finding).
- Audit-Report-Template zeigt pro Finding die Zeile
  *„F-42 — NC 27001 A.5.1 — NC NIS2 21.2.a — Observation BSI ISMS.1"*.

**Aufwand:** 1,5 FTE-Tage (Migration + Repository + Report-Twig).

**Effekt:** 5-Tage-Audit deckt 3 Frameworks statt 1 → **~8 FTE-Tage Einsparung
pro Audit-Zyklus**.

---

## Priorisierung

| # | Feature | FTE-d | Wert | Kategorie |
|---|---------|-------|------|-----------|
| B1 | Transitiv-Abdeckung sichtbar | 1,5 | **sehr hoch** | Cross-Norm |
| A4 | Audit-Paket-Export | 1,5 | **sehr hoch** | Reuse |
| B2 | Offizielle Seed-Mappings | 2 | **sehr hoch** | Cross-Norm |
| A1 | Invertierte Sichten | 3 | hoch | Reuse |
| A3 | Consultant-Template-Import | 2 | hoch | Reuse |
| B3 | 27701 Privacy-Overlay | 1,5 | hoch | Cross-Norm |
| A2 | Auto-Mapping-Vorschläge | 2 | mittel | Reuse |
| B4 | Multi-Framework-Audit | 1,5 | mittel | Cross-Norm |

**Summe: 15 FTE-Tage Entwicklungsinvest.**
**Summe jährliche CM-Ersparnis: ~37 FTE-Tage.**

## Empfehlung Reihenfolge

1. **Sprint 1 (≈ 5 FTE-Tage):** B1 + A4 + B2 — der Compliance-Daten-Export-Stack.
   Audit-Paket-Export ist der greifbarste ROI, Transitiv-Sichtbarkeit macht den
   Export erst glaubwürdig, Seed-Mappings füttern beide.
2. **Sprint 2 (≈ 5 FTE-Tage):** A1 + A3 — invertierte Sichten + Consultant-Import.
   Schließt die letzte Reuse-Lücke in der täglichen CM-Arbeit.
3. **Sprint 3 (≈ 5 FTE-Tage):** B3 + A2 + B4 — Ausbau-Features, die bereits ein
   gutes Tool zu einem benchmark-setzenden machen.

---

## Nicht im Plan (bewusst ausgeschlossen)

- **AI-basiertes Auto-Mapping** — erzeugt Audit-Findings *„wie wurde diese
  Zuordnung getroffen?"* die der CM manuell verteidigen muss. Simple Similarity
  reicht.
- **Vollständige BSI-Kompendium-Integration (1 100 Anforderungen)** — nur bei
  konkretem Großkunden-Bedarf. Delta-Ansatz (24 wichtigste) ist wirtschaftlich.
- **Real-time-Kollaboration** — CM arbeitet asynchron. Roadmap-Zombie.
- **Eigener Policy-Template-Store** — zu kundenspezifisch, erzeugt Wartungsaufwand
  ohne Portfolio-Wert.

---

## Senior-Consultant Review (2026-04-20)

**Reviewer-Rolle:** Senior-Berater GRC/ISMS, 8–15 Jahre Erfahrung, Benchmark-
Vergleiche gegen Verinice, HiScout, ONE Tool, Vanta, Drata, Archer.
**Kontext:** Review nach Commit `a2c9c2a9`.

### Zusammenfassung

Guter Plan. **Nicht ambitioniert genug an zwei Stellen** und **fehlende Hebel**
an vier weiteren. Priorisierung muss aus Beratersicht umgestellt werden — das,
was ihr als Sprint 1 markiert, ist Sprint 2-Material. Tempo vor Perfektion.

### Stärken des Plans

- **Transitive Sichtbarkeit (B1)** ist der richtige Angelpunkt. Verinice kann das,
  HiScout eingeschränkt, Vanta/Drata gar nicht. Das ist **Markt-Differenzierung**,
  kein Nice-to-have.
- **Offizielle Seeds (B2)** — BSI liefert diese Mappings als XML-Profile, VDA als
  ISA-Excel. Wer das direkt laden kann, spart dem Kunden 2–3 Tage Mapping-
  Workshop. Ihr habt den Scope aber zu eng gefasst — siehe unten.
- **Audit-Paket-Export (A4)** ist der ROI-Sieger aus CM-Sicht, teile ich 100 %.
  Bei HiScout heißt das *„Auditorenpaket"*, ist dort aber nur PDF — Eure ZIP-
  Variante mit Evidence-Dateien ist **Feature-Parität plus 1**.

### Kritik — Priorisierung

1. **A2 Auto-Mapping-Vorschläge viel zu niedrig priorisiert.** Aus Berater-Sicht
   ist das der **wichtigste Einzel-Hebel überhaupt**. Junior-Berater legen 80 %
   aller neuen Controls an — und pflegen 0 Mappings, weil sie nicht wissen wie.
   Ein *„Das Tool hat Dir 3 passende Framework-Anforderungen vorgeschlagen"*-
   Popup ist in jedem modernen GRC-Tool Pflicht. **Hochziehen auf Sprint 1.**

2. **A1 Invertierte Sichten niedriger priorisieren.** Schönes Feature, aber kein
   Deal-Maker. Reißt Dir niemand die Tür ein. Sprint 3.

3. **B3 27701 Privacy-Overlay situationsabhängig einstufen.** Für Finanz-/Health-
   Kunden: **sehr hoch**. Für Automotive/Produktion: egal, dort zählt TISAX.
   Empfehlung: als Config-Option pro Baseline aktivierbar, nicht als fixer Plan-
   Punkt.

### Fehlende Hebel

#### B5 — SOC 2 + BSI C5:2026 Mapping-Seeds

**Status:** fehlt im Plan.

Bei US-Kunden und Cloud-Anbietern die **erste Frage**. Eure B2-Liste hat NIST CSF
und NIST 800-53, aber SOC 2 Trust Services Criteria fehlen komplett — und die
sind für Cloud-Scale-ups **der** Eintrittspunkt. C5:2026 habt ihr bereits als
Framework geladen, aber das Mapping zu 27001 Annex A fehlt als Seed.

**Aufwand:** 1 FTE-Tag (zwei zusätzliche Seed-Dateien nach B2-Pattern).

**Im Markt üblich:** Vanta und Drata liefern SOC 2 ↔ 27001 out-of-the-box. Wer
das nicht hat, verliert US-Deals.

#### B6 — Framework-Version-Migration-Assistent

**Status:** fehlt im Plan.

`ComplianceFramework.lifecycleState + successor` existiert (M-04), wird aber im
Plan nicht genutzt. Wenn morgen NIS2UmsuCG eine Überarbeitung bekommt oder
27001:2022 → 27001:2026, muss der Mandant die Mappings migrieren können —
sonst verliert er seinen Cross-Framework-Reuse.

**Umsetzung:** Assistent *„Framework X hat eine neue Version. Wir schlagen
folgende Mapping-Migrationen vor — je Mapping akzeptieren/ablehnen."* mit Audit-
Log-Eintrag.

**Aufwand:** 2 FTE-Tage.

**Warum Berater das fordern:** 27001:2013 → 2022 war in allen GRC-Tools ein
Desaster. Wer das diesmal schmerzfrei macht, gewinnt Altkunden zurück.

#### C1 — Klon-Funktionen (Tenant / Audit / Assessment)

**Status:** fehlt im Plan.

Ich komme mit einem Neukunden, lege Tenant an, und will die Startkonfiguration
eines Bestandskunden klonen (nicht nur Baseline — komplette SoA-Entscheidungen,
Risk-Register-Template, Audit-Programm). Heute: manuell abtippen.

**Umsetzung:**

- `Tenant::cloneFromTemplate($templateTenant, $opts)` mit Opt-In pro Entity-Typ
- Audit-Plan-Klon: *„nimm meinen 27001-Auditplan aus Kunde A und wende ihn auf
  Kunde B an"*
- Assessment-Template-Library als Scheibe über bestehendes `IndustryBaseline`-
  Konzept.

**Aufwand:** 3 FTE-Tage.

**Verinice-Vergleich:** Verinice kann Organisations-Bäume inkl. Controls als
XML exportieren/importieren — rudimentäres Klonen. Hier wäre eine UI-gestützte,
selektive Klon-Funktion ein klarer Vorsprung.

#### C2 — Reifegradmodell (CMMI-Stufen pro Framework)

**Status:** fehlt im Plan — eure KPIs sind *Coverage %*, nicht *Maturity Level*.

Consultant- und Management-Sicht brauchen Reifegrad-Einstufung:

| Level | Bedeutung | Trigger |
|-------|-----------|---------|
| 1 Initial | Ad-hoc, keine Dokumentation | Framework aktiviert, < 10 % implementiert |
| 2 Managed | Grundlegende Prozesse dokumentiert | ≥ 60 % implementiert, aber < 3 Audits |
| 3 Defined | Standardisiert, Cross-Framework-Reuse | ≥ 80 %, aktives Mapping, ≥ 1 erfolgreiches Audit |
| 4 Quantitatively Managed | Messbare KPIs, Trend-Analysen | Trend-Daten ≥ 6 Monate, Ziele gesetzt |
| 5 Optimizing | Kontinuierliche Verbesserung | Kaizen-Zyklus dokumentiert, AI-Findings |

**Umsetzung:** 1 Computation-Service + Badge pro Framework + Board-Report mit
Maturity-Heatmap Konzern × Framework.

**Aufwand:** 2 FTE-Tage.

**Im Markt üblich:** LogicGate und Archer haben eigene Maturity-Modelle. Vanta/
Drata *nicht* — hier ist ein Mittelstands-taugliches Reifegrad-Modell ein klares
Markt-Argument.

#### C3 — Bulk-Edit im Mapping-Editor

**Status:** fehlt im Plan.

*„Markiere 30 Controls auf einmal als N/A mit Begründung 'Cloud-only Org, kein
physischer Standort'"* — Bulk-Action, die CM alle 3–4 Wochen braucht.

**Aufwand:** 1 FTE-Tag.

#### C4 — Öffentliche API für die neuen Features

**Status:** fehlt im Plan.

Alles was ihr in A4 (Audit-Paket), A3 (Import), B1 (Transitiv-Abdeckung) baut,
braucht **zusätzlich** einen REST-Endpoint. Consulting-Kunden automatisieren
sonst über JIRA/n8n/Make.

**Aufwand:** 0,5 FTE-Tage *pro Feature* (Routing + Voter + OpenAPI-Annotation).

### Reifegrad-Einstufung des Plans

Wenn ihr den **CM-Plan so wie er steht** umsetzt, erreicht das Tool aus Berater-
Sicht **CMMI-Level 3 (Defined)** auf der Data-Reuse-Achse. Der Sprung auf
**Level 4 (Quantitatively Managed)** braucht **C2 (Reifegradmodell)** und **A2
Auto-Mapping-Suggestions hochgezogen**, weil beide die *Messbarkeit* und *Prozess-
Automatisierung* liefern.

### Priorisierung aus Consultant-Sicht (Gegenentwurf)

| Sprint | Feature | FTE-d | Begründung |
|--------|---------|-------|------------|
| 1 | **A2 Auto-Mapping-Vorschläge** | 2 | Junior-Berater-Workflow, 80 % aller Mappings |
| 1 | **A4 Audit-Paket-Export** | 1,5 | ROI-Sieger, sofort verkaufbar |
| 1 | **B2 + B5 Seed-Mappings (BSI, VDA, NIST, SOC 2, C5, DORA)** | 3 | Onboarding-Beschleuniger, 6 Seeds statt 4 |
| 2 | **B1 Transitive Sichtbarkeit** | 1,5 | Differenzierung, braucht B2 + A2 als Futter |
| 2 | **A3 Consultant-Template-Import** | 2 | Berater-Migrationspfad |
| 2 | **B6 Version-Migration-Assistent** | 2 | 27001:2013→2022-Lernkurve |
| 3 | **A1 Invertierte Sichten** | 3 | Nice-to-have, kein Deal-Maker |
| 3 | **C1 Klon-Funktionen** | 3 | Consultant-Produktivität |
| 3 | **C2 Reifegradmodell** | 2 | Level-4-Trigger |
| 3 | **B4 Multi-Framework-Audit** | 1,5 | Auditproduktivität |
| 3 | **C3 Bulk-Edit** | 1 | Quick-Win |
| optional | **B3 27701-Overlay** | 1,5 | Nur für Finance/Health-Kunden |
| optional | **C4 API-Endpoints** | 2 | Wenn Integrations-Pipeline gefragt |

**Gesamt aus Consultant-Sicht: 22 FTE-Tage** (statt 15 im CM-Original), aber
dafür **Markt-Führerschaft** auf Data-Reuse + Cross-Norm. Reifegrad-Sprung:
Level 3 → **Level 4** mit allen C-Items.

### Was ich am CM-Plan *nicht* streiche

- Die bewussten Ausschlüsse (AI-Auto-Mapping, Vollständiges BSI-Kompendium,
  Real-time-Collab) sind **korrekt**. Scope-Disziplin ist beim Consulting-Thema
  wichtiger als Feature-Breite.
- Die 15-FTE-Tage-Schätzung für die CM-Items stimmt.

### Business-Nutzen-Argumentation für den CISO

CISO fragt *„was spart Euro?"*. Übersetzung aus Consulting-Sicht:

| Effekt | €-Übersetzung |
|--------|---------------|
| CM spart ~37 FTE-Tage/Jahr | ≈ **30 000 €/Jahr** bei intern 800 €/Tag |
| Auditpaket-Export spart 2–3 FTE-Tage pro Audit | ≈ **8 000 €/Jahr** bei 4 Audits |
| Consultant-Tage sinken durch A3 + B2 + B6 | ≈ **15 000 €/Jahr** bei 1 200 €/Consultant-Tag |
| **Summe direkter Effekt** | **≈ 53 000 €/Jahr** |
| Indirekt: 6 Monate schnelleres Onboarding bei Neukunden | **Sales-Argument** |
| Indirekt: Cross-Framework-Reuse senkt Wachstums-FTE-Kosten | **Skalierungs-Argument** |

Bei einem ISMS-Team von 3–5 Personen amortisiert sich der 22-Tage-Entwicklungs-
invest in **unter 6 Monaten**. Sprint-1-Freigabe (≈ 6,5 FTE-Tage) ist auch ohne
lange CISO-Begründung durch.

### Empfehlung

**Freigabe:** Consultant-Priorisierung übernehmen. Sprint 1 sofort, Sprint 2
vor Q3 2026. Sprint 3 als Backlog in Phase 9-Nähe. B3 + C4 erst bei konkretem
Kundenwunsch.

**Nicht machen:** Scope-Aufblähung in Richtung vollständige BSI-Kompendium-
Integration oder AI-Mapping. Disziplin halten.

— Senior-Consultant (Persona), 2026-04-20