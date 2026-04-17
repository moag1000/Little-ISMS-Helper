# Verbesserungsprojekte — Standards Compliance

> Erstellt: 2026-04-17 | Status: Planung
> Jeder Specialist hat seine hinterlegten Standards gegen die Codebase geprueft.
> Ergebnis: 8 Verbesserungsprojekte mit priorisierten Massnahmen.

---

## Vorhandene Compliance-Kataloge (25 Seeder-Commands)

### Kern-Standards

| Katalog | Command | ~Eintraege | Specialist | Status |
|---------|---------|-----------|------------|--------|
| **ISO 27001:2022 Annex A** | `LoadAnnexAControlsCommand` | 93 Controls | ISMS | OK — alle 93 korrekt |
| **ISO 27001:2022 Requirements** | `LoadIso27001RequirementsCommand` | 93 | ISMS | OK — Mapping-Basis |
| **ISO 27701:2019 PIMS** | `LoadIso27701RequirementsCommand` | ~83 | DPO | OK |
| **ISO 27701:2025 PIMS** | `LoadIso27701v2025RequirementsCommand` | ~92 | DPO | Clause-Nummern verifizieren gegen DIN-Fassung |
| **ISO 22301:2019 BCM** | `LoadIso22301RequirementsCommand` | ~26 | BCM | OK |

### EU/DE Regulierung

| Katalog | Command | ~Eintraege | Specialist | Status |
|---------|---------|-----------|------------|--------|
| **EU-DORA** | `LoadDoraRequirementsCommand` | ~94 | ISMS | OK — nicht idempotent |
| **DORA RTS Supplement** | `SupplementDoraRtsRequirementsCommand` | ~34 | ISMS | OK — ID-Schema abweichend |
| **EU-NIS2** | `LoadNis2RequirementsCommand` | ~46 | ISMS | Art. 21.2 Title-Fehler |
| **NIS2UmsuCG (deutsch)** | — | FEHLT | ISMS | **FEHLT — muss erstellt werden** |
| **GDPR/DSGVO** | `LoadGdprRequirementsCommand` | 28 | DPO | Idempotent, aber nur ~60% der Key-Artikel. Art. 6, 9, 21, 22 FEHLEN |
| **TKG (Telekommunikation)** | `LoadTkgRequirementsCommand` | ~44 | — | Nicht geprueft |
| **KRITIS** | `LoadKritisRequirementsCommand` | 106 | BSI | NICHT idempotent. Basiert auf altem BSIG §8a — muss auf NIS2UmsuCG aktualisiert werden |
| **KRITIS Gesundheit** | `LoadKritisHealthRequirementsCommand` | 38 | — | NICHT idempotent. B3S Gesundheit nicht explizit referenziert. BSI-Orientierungshilfe fehlt |

### BSI-Familie

| Katalog | Command | ~Eintraege | Specialist | Status |
|---------|---------|-----------|------------|--------|
| **BSI IT-Grundschutz (DE)** | `LoadBsiItGrundschutzRequirementsCommand` | ~33 | BSI | Unvollstaendig — nur Subset |
| **BSI IT-Grundschutz Supplement** | `SupplementBsiGrundschutzRequirementsCommand` | ~71 | BSI | Erweitert, aber Kompendium nicht vollstaendig |
| **BSI IT-Grundschutz (EN, alt)** | `LoadBsiRequirementsCommand` | ~83 | BSI | **Duplikat-Framework — konsolidieren** |
| **BSI C5:2020** | `LoadC5RequirementsCommand` | ~122 | BSI | OK — alle 17 Domains |
| **BSI C5:2025** | `LoadC52025RequirementsCommand` | ~44 | BSI | OK — neue Domains (CNT, SCS, PQC, etc.) |

### Internationale/Branchenstandards

| Katalog | Command | ~Eintraege | Specialist | Status |
|---------|---------|-----------|------------|--------|
| **NIST CSF** | `LoadNistCsfRequirementsCommand` | 67 | ISMS | OK — CSF 2.0 korrekt (6 Funktionen inkl. GOVERN). ~63% Subcategory-Abdeckung. Best-Practice-Implementierung |
| **CIS Controls** | `LoadCisControlsRequirementsCommand` | 47 | ISMS | v8 korrekt (18 Controls). Kategorie-Labels nutzen v7-Bezeichnungen statt v8 IG1/IG2/IG3. ~31% Safeguard-Abdeckung |
| **SOC 2** | `LoadSoc2RequirementsCommand` | 52 | ISMS | TSC 2017 — akzeptabel. Alle 5 Trust Service Criteria abgedeckt |
| **TISAX** | `LoadTisaxRequirementsCommand` | 100 | ISMS | VDA ISA 6.0.2 — sollte 6.0.3 sein. NICHT idempotent. Kein AL-Level im Base-Command |
| **TISAX AL3** | `LoadTisaxAl3RequirementsCommand` | 40 | ISMS | VDA ISA 6.0.3 korrekt. Idempotent. Gute GDPR-Integration. Best Practice |
| **TISAX Supplement** | `SupplementTisaxRequirementsCommand` | ~36 | ISMS | Nicht im Detail geprueft |
| **DiGAV (Gesundheit)** | `LoadDigavRequirementsCommand` | 39 | DPO | Version 2020 VERALTET (2023/2024 Amendments fehlen). BSI TR-03161 FEHLT komplett. NICHT idempotent |
| **GxP (Pharma)** | `LoadGxpRequirementsCommand` | 56 | ISMS | EU GMP Annex 11 + FDA 21 CFR Part 11. Part 11 Subpart C (E-Signatures) FEHLT. NICHT idempotent |

### Cross-Framework Mapping
| Tool | Command | Status |
|------|---------|--------|
| **Cross-Mapping Generator** | `CreateCrossFrameworkMappingsCommand` | OK — transitiv via ISO 27001 |
| **Mapping Quality Check** | `MappingQualitySanityCheckCommand` | OK |
| **ISO 27701 Version Mapping** | `MapIso27701VersionsCommand` | OK — 2019↔2025 |

### Audit-Ergebnisse der nicht-geprueften Kataloge (abgeschlossen 2026-04-17)

**Idempotenz-Muster im Projekt (3 Stufen):**

| Muster | Commands | Qualitaet |
|--------|----------|-----------|
| **Best Practice** | NIST CSF, SOC 2, CIS, TISAX AL3 | Einzelpruefung pro Requirement + `--update` Flag + Transaction |
| **Akzeptabel** | GDPR, ISO 27001, ISO 22301 | Prueft ob Framework Requirements hat, skippt alle wenn ja |
| **Fehlend** | KRITIS, KRITIS-Health, TISAX, DiGAV, GxP, DORA, NIS2 | Kein Duplikat-Check — erzeugt Duplikate bei Mehrfachausfuehrung |

**Versions-Status aller Kataloge:**

| Katalog | Ist-Version | Soll-Version | Delta |
|---------|------------|-------------|-------|
| NIST CSF | 2.0 | 2.0 | OK |
| SOC 2 | TSC 2017 | TSC 2017 | OK (Core-Criteria stabil) |
| CIS Controls | v8 | v8.1 | Kategorie-Labels v7-Stil statt IG1/IG2/IG3 |
| TISAX Base | VDA ISA 6.0.2 | 6.0.3 | 1 Minor hinter aktuell |
| TISAX AL3 | VDA ISA 6.0.3 | 6.0.3 | OK |
| DiGAV | 2020 | 2023/2024 | **VERALTET — 2 Major-Amendments fehlen** |
| GxP | 2024 | 2024 | OK, aber Subpart C fehlt |
| KRITIS | BSIG §8a | NIS2UmsuCG | **VERALTET — Rechtsgrundlage geaendert** |
| GDPR | 2016/679 | 2016/679 | OK, aber inhaltlich unvollstaendig |

---

## Projekt 1: DPO-Specialist — Betroffenenrechte & Privacy Compliance

### Kritisch: Data Subject Rights (Art. 15-22 GDPR) FEHLEN KOMPLETT

**Problem:** Kein Entity, kein Service, kein Controller fuer Betroffenenanfragen. Das ist eine Pflichtanforderung der DSGVO.

**Massnahmen:**

| # | Massnahme | Prioritaet | Aufwand |
|---|-----------|-----------|---------|
| 1.1 | `DataSubjectRequest` Entity erstellen (Typ, Betroffener, Eingangsdatum, Frist 30 Tage Art. 12(3), Status, Identitaetspruefung, Verlaengerungsbegruendung) | KRITISCH | Mittel |
| 1.2 | `DataSubjectRequestService` mit Fristberechnung, Statusuebergaenge, Benachrichtigungen | KRITISCH | Mittel |
| 1.3 | Controller + Templates (Index, New, Show, Process) | KRITISCH | Mittel |
| 1.4 | Workflow fuer Betroffenenanfragen (Eingang → Pruefung → Bearbeitung → Antwort) | HOCH | Mittel |
| 1.5 | KPIs: Durchschnittliche Bearbeitungszeit, Fristverstoesse, Anfragen nach Typ | HOCH | Klein |

### Hoch: ProcessingActivity Completeness

| # | Massnahme | Prioritaet | Aufwand |
|---|-----------|-----------|---------|
| 1.6 | `getCompletenessPercentage()` erweitern: `recipientCategories` (Art. 30(1)(d)), Drittlandtransfer-Details bedingt pruefen | HOCH | Klein |
| 1.7 | `controllerName`/`controllerContactDetails` Felder hinzufuegen (Art. 30(1)(a)) | MITTEL | Klein |

### Mittel: BDSG-Spezifika

| # | Massnahme | Prioritaet | Aufwand |
|---|-----------|-----------|---------|
| 1.8 | BDSG §26 Beschaeftigtendatenschutz als Processing-Activity-Template | MITTEL | Klein |
| 1.9 | BDSG §38 DSB-Bestellungsschwelle (>=20 Personen) als Compliance-Check | MITTEL | Klein |

---

## Projekt 2: ISMS-Specialist — Framework Compliance & Datenqualitaet

### Hoch: NIS2UmsuCG als eigenes Framework

**Problem:** Nur EU-NIS2-Richtlinie geladen. Das deutsche NIS2UmsuCG (in Kraft seit 05.12.2025) hat zusaetzliche Anforderungen (BSI-Registrierung, Sektoraufsicht, Bussgeldrahmen).

| # | Massnahme | Prioritaet | Aufwand |
|---|-----------|-----------|---------|
| 2.1 | `LoadNis2UmsuCGRequirementsCommand` erstellen mit deutschen Spezifika | HOCH | Mittel |
| 2.2 | NIS2 Art. 21.2.b Title-Fix: "Multi-Factor Authentication" → "Incident handling" | HOCH | Klein |
| 2.3 | NIS2 Art. 21.2.f Title-Fix: "Security in Acquisition" → "Basic cyber hygiene and training" | HOCH | Klein |

### Hoch: Seeder Idempotenz

**Problem:** `LoadDoraRequirementsCommand` und `LoadNis2RequirementsCommand` pruefen nicht auf existierende Eintraege. Doppelausfuehrung erzeugt Duplikate.

| # | Massnahme | Prioritaet | Aufwand |
|---|-----------|-----------|---------|
| 2.4 | Alle Load-Commands idempotent machen (check-before-insert oder `--update` Flag) | HOCH | Mittel |

### Mittel: ComplianceMapping Tenant-Scoping

| # | Massnahme | Prioritaet | Aufwand |
|---|-----------|-----------|---------|
| 2.5 | Pruefen ob `ComplianceMapping` Tenant-Scoping braucht fuer manuelle Bewertungen | MITTEL | Klein |

---

## Projekt 3: BSI-Specialist — IT-Grundschutz & C5 Vollstaendigkeit

### Kritisch: Absicherungsstufen fehlen

**Problem:** Basis-/Standard-/Kern-Absicherung nicht unterstuetzt. Essentiell fuer BSI-Zertifizierung "ISO 27001 auf Basis IT-Grundschutz".

| # | Massnahme | Prioritaet | Aufwand |
|---|-----------|-----------|---------|
| 3.1 | Feld `absicherungsStufe` (basis/standard/kern) auf `ComplianceRequirement` oder als Tenant-Setting | KRITISCH | Mittel |
| 3.2 | Anforderungstypen (MUSS/SOLLTE/KANN) als Feld auf BSI-Requirements | KRITISCH | Mittel |
| 3.3 | Filter: Zeige nur relevante Anforderungen basierend auf gewaehlter Absicherungsstufe | HOCH | Mittel |

### Hoch: Framework-Konsolidierung

**Problem:** Zwei BSI-Grundschutz-Frameworks (`BSI-Grundschutz` englisch, `BSI_GRUNDSCHUTZ` deutsch) existieren parallel.

| # | Massnahme | Prioritaet | Aufwand |
|---|-----------|-----------|---------|
| 3.4 | Frameworks konsolidieren zu einem einzigen `BSI_GRUNDSCHUTZ` mit deutschen Titeln | HOCH | Mittel |
| 3.5 | Kompendium-Abdeckung erweitern: Fehlende Bausteine aus den 111 des Kompendiums 2023 | HOCH | Gross |

### Hoch: Schutzbedarfsvererbung

| # | Massnahme | Prioritaet | Aufwand |
|---|-----------|-----------|---------|
| 3.6 | Explizites Maximumprinzip: Hoechster Schutzbedarf propagiert automatisch entlang Asset-Abhaengigkeitsketten | HOCH | Mittel |

### Mittel: IT-Grundschutz-Check

| # | Massnahme | Prioritaet | Aufwand |
|---|-----------|-----------|---------|
| 3.7 | Dedizierter Grundschutz-Check View: Baustein-Level Soll/Ist mit MUSS/SOLLTE/KANN-Klassifizierung | MITTEL | Gross |
| 3.8 | BSI 200-1/200-2 Prozess-Checklisten als Workflow-Templates | MITTEL | Mittel |

---

## Projekt 4: Risk-Specialist — Risikoanalyse & BSI 200-3

### Kritisch: Threshold-Inkonsistenz

**Problem:** `RiskMatrixService` nutzt Schwellwerte 20/12/6, `RiskController` nutzt 15/8/4. Risiken werden je nach View unterschiedlich klassifiziert.

| # | Massnahme | Prioritaet | Aufwand |
|---|-----------|-----------|---------|
| 4.1 | Schwellwerte vereinheitlichen (eine zentrale Konfiguration) | KRITISCH | Klein |

### Hoch: BSI 200-3 Elementare Gefaehrdungen

**Problem:** Kein Katalog der 47 BSI Elementaren Gefaehrdungen (G 0.1 - G 0.47). `$threat` auf Risk ist Freitext statt Auswahl.

| # | Massnahme | Prioritaet | Aufwand |
|---|-----------|-----------|---------|
| 4.2 | Entity oder Enum fuer BSI Elementare Gefaehrdungen (G 0.1-G 0.47) | HOCH | Mittel |
| 4.3 | Risk.threat mit ThreatIntelligence-Entity verlinken (existiert, aber nicht verbunden) | HOCH | Klein |
| 4.4 | Risk.vulnerability mit Vulnerability-Entity verlinken (existiert, aber nicht verbunden) | HOCH | Klein |

### Mittel: Erweiterte Risiko-Features

| # | Massnahme | Prioritaet | Aufwand |
|---|-----------|-----------|---------|
| 4.5 | Review-Intervall pro Risk konfigurierbar (`$reviewInterval` Feld) | MITTEL | Klein |
| 4.6 | Risk-Aggregation/-Korrelation Service (Portfolio-Level Risikoview, ISO 31000 6.4.4) | MITTEL | Gross |
| 4.7 | Risk Communication Plan Entity (ISO 31000 6.2 Stakeholder-Kommunikation) | MITTEL | Mittel |
| 4.8 | BSI 200-3 Risikomatrix-Modus (4-stufig statt 5-stufig) als Alternative | NIEDRIG | Mittel |

---

## Projekt 5: BCM-Specialist — Business Continuity Erweiterungen

### Hoch: MBL/MBCO Feld

**Problem:** ISO 22301:2019 Clause 8.2.2 fordert "Minimum Business Continuity Objective" — fehlt auf BusinessProcess.

| # | Massnahme | Prioritaet | Aufwand |
|---|-----------|-----------|---------|
| 5.1 | `$mbco` / `$minimumBusinessLevel` Feld auf BusinessProcess Entity | HOCH | Klein |

### Mittel: Strukturverbesserungen

| # | Massnahme | Prioritaet | Aufwand |
|---|-----------|-----------|---------|
| 5.2 | Dependencies strukturiert: `$dependenciesUpstream`/`$dependenciesDownstream` als ManyToMany Self-Reference statt Freitext | MITTEL | Mittel |
| 5.3 | BSI 200-4 Phasenmodell: Tracking welche Phase (Initiierung/Analyse/Konzeption/Umsetzung) ein BCM-Element hat | MITTEL | Mittel |
| 5.4 | Supplier BCM-Felder: Recovery-Faehigkeit, Supplier-RTO, Alternativ-Lieferant auf Supplier-Entity | MITTEL | Klein |
| 5.5 | Dedizierter `BCMService` fuer BIA-Berechnungen, Plan-Readiness-Aggregation, Uebungs-Scheduling | NIEDRIG | Mittel |

---

## Projekt 6: UX-Specialist — WCAG 2.2 Level AA Compliance

### Kritisch: Screen Reader Accessibility

| # | Massnahme | Prioritaet | Aufwand |
|---|-----------|-----------|---------|
| 6.1 | `aria-live="polite"` auf Flash-Messages-Container (`base.html.twig` Z.263) | KRITISCH | Klein |
| 6.2 | `role="status"` oder `aria-live="polite"` auf Toast-Container (`base.html.twig` Z.320) | KRITISCH | Klein |
| 6.3 | `role="dialog"` + `aria-modal="true"` + `aria-labelledby` auf Quick-View-Modal | KRITISCH | Klein |
| 6.4 | Dialog-Semantik fuer Notification-Center Panel | KRITISCH | Klein |

### Hoch: Tabellen & Navigation

| # | Massnahme | Prioritaet | Aufwand |
|---|-----------|-----------|---------|
| 6.5 | `scope="col"` auf alle 48 `<th>` Elemente in 11 Templates | HOCH | Mittel |
| 6.6 | Accessible Labels fuer Search-Inputs (Command Palette + Global Search) | HOCH | Klein |
| 6.7 | `scroll-padding-top: 80px` fuer Sticky Header (Focus Not Obscured, WCAG 2.4.11) | HOCH | Klein |
| 6.8 | `base_auth.html.twig` Landmark-Rollen ergaenzen (`<main>`, `<header>`) | HOCH | Klein |
| 6.9 | Sidebar: `role="complementary"` durch `role="navigation"` ersetzen | HOCH | Klein |

### Mittel: Konsistenz & i18n

| # | Massnahme | Prioritaet | Aufwand |
|---|-----------|-----------|---------|
| 6.10 | Hardcoded German in Components durch Translation-Keys ersetzen (Quick-View-Modal, Form-Field, Preferences) | MITTEL | Klein |
| 6.11 | Duplizierten Skip-Link entfernen (`base.html.twig` Z.142 vs Z.236) | MITTEL | Klein |
| 6.12 | Badge-Component: `aria-label` Parameter hinzufuegen | MITTEL | Klein |
| 6.13 | Language-Switcher Target-Size auf min. 24x24px erhoehen (WCAG 2.5.8) | MITTEL | Klein |
| 6.14 | Duplizierten `aria-label` auf Close-Button in Bulk-Delete-Modal fixen | NIEDRIG | Klein |

---

## Projekt 7: Katalog-Qualitaet — Seeder Idempotenz & Versionen

### Kritisch: 7 Seeder nicht idempotent

**Problem:** KRITIS, KRITIS-Health, TISAX, DiGAV, GxP, DORA, NIS2 erzeugen Duplikate bei Mehrfachausfuehrung. Best-Practice-Muster existiert bereits (NIST CSF, SOC 2, CIS, TISAX AL3).

| # | Massnahme | Prioritaet | Aufwand |
|---|-----------|-----------|---------|
| 7.1 | `LoadDoraRequirementsCommand` idempotent machen (Best-Practice-Muster von NIST CSF uebernehmen) | HOCH | Klein |
| 7.2 | `LoadNis2RequirementsCommand` idempotent machen | HOCH | Klein |
| 7.3 | `LoadKritisRequirementsCommand` idempotent machen | HOCH | Klein |
| 7.4 | `LoadKritisHealthRequirementsCommand` idempotent machen | HOCH | Klein |
| 7.5 | `LoadTisaxRequirementsCommand` idempotent machen + Version auf 6.0.3 | HOCH | Klein |
| 7.6 | `LoadDigavRequirementsCommand` idempotent machen | HOCH | Klein |
| 7.7 | `LoadGxpRequirementsCommand` idempotent machen | HOCH | Klein |

### Hoch: Veraltete Katalog-Versionen

| # | Massnahme | Prioritaet | Aufwand |
|---|-----------|-----------|---------|
| 7.8 | `DiGAV` von 2020 auf 2023/2024 aktualisieren + BSI TR-03161 Anforderungen ergaenzen | HOCH | Gross |
| 7.9 | `KRITIS` Rechtsgrundlage von BSIG §8a auf NIS2UmsuCG aktualisieren | HOCH | Gross |
| 7.10 | `CIS Controls` Kategorie-Labels von v7-Stil (Basic/Foundational/Organizational) auf v8 IG1/IG2/IG3 aendern | MITTEL | Klein |
| 7.11 | `TISAX Base` Version von 6.0.2 auf 6.0.3 aktualisieren | MITTEL | Klein |

### Mittel: Inhaltliche Luecken in Katalogen

| # | Massnahme | Prioritaet | Aufwand |
|---|-----------|-----------|---------|
| 7.12 | `GDPR`: Fehlende Artikel ergaenzen (Art. 6 Rechtsgrundlagen, Art. 9 Besondere Kategorien, Art. 21 Widerspruch, Art. 22 Automatisierte Entscheidungen, Art. 13/14 Informationspflichten, Art. 26 Joint Controller, Art. 36 Vorabkonsultation) | HOCH | Mittel |
| 7.13 | `GxP`: 21 CFR Part 11 Subpart C (Electronic Signatures §11.50-11.300) ergaenzen | MITTEL | Mittel |
| 7.14 | `NIST CSF`: Fehlende ~39 Subcategories ergaenzen (aktuell 67 von 106) | MITTEL | Mittel |
| 7.15 | `CIS Controls`: Fehlende ~106 Safeguards ergaenzen (aktuell 47 von 153) + IG-Level tagging | MITTEL | Gross |
| 7.16 | `KRITIS-Health`: B3S Gesundheit explizit referenzieren, gematik-Anforderungen ergaenzen | MITTEL | Mittel |
| 7.17 | `GxP`: GAMP 5 Second Edition (2022) Spezifika, ICH Q9/Q10 Referenzen, PIC/S Data Integrity | NIEDRIG | Mittel |

---

## Projekt 8: Katalog-Abdeckung — Fehlende Frameworks

### Hoch: Identifizierte Luecken

| # | Massnahme | Prioritaet | Aufwand |
|---|-----------|-----------|---------|
| 8.1 | `LoadNis2UmsuCGRequirementsCommand` erstellen — Deutsches NIS2-Umsetzungsgesetz (in Kraft seit 05.12.2025) mit BSI-Registrierung, Sektoraufsicht, Bussgeldrahmen | HOCH | Gross |
| 8.2 | `LoadBdsgRequirementsCommand` erstellen — BDSG-spezifische Anforderungen (§26 Beschaeftigtendatenschutz, §38 DSB-Pflicht, §42 Strafvorschriften) als eigenes Framework oder GDPR-Supplement | MITTEL | Mittel |
| 8.3 | `LoadEuAiActRequirementsCommand` erwägen — EU AI Act (in Kraft seit Aug 2024, Fristen bis 2027) fuer Organisationen die KI einsetzen | NIEDRIG | Gross |

---

## Priorisierungs-Matrix

| Prioritaet | Massnahmen | Betroffene Projekte |
|-----------|-----------|-------------------|
| **KRITISCH** | 1.1-1.3, 3.1-3.2, 4.1, 6.1-6.4 | DPO, BSI, Risk, UX |
| **HOCH** | 1.4-1.5, 2.1-2.4, 3.3-3.6, 4.2-4.4, 5.1, 6.5-6.9, 7.1-7.9, 7.12, 8.1 | Alle |
| **MITTEL** | 1.6-1.9, 2.5, 3.7-3.8, 4.5-4.7, 5.2-5.4, 6.10-6.13, 7.10-7.11, 7.13-7.16, 8.2 | Alle |
| **NIEDRIG** | 4.8, 5.5, 6.14, 7.17, 8.3 | Risk, BCM, UX, Kataloge |

## Empfohlene Reihenfolge

1. **Sprint 1 (Kritisch):** Data Subject Rights Entity + UX Screen Reader Fixes + Risk Threshold-Fix + BSI Absicherungsstufen
2. **Sprint 2 (Hoch — Seeder):** Alle 7 Seeder idempotent machen (7.1-7.7) + GDPR Artikel ergaenzen (7.12) + NIS2UmsuCG Framework (8.1)
3. **Sprint 3 (Hoch — Standards):** NIS2 Title-Fixes + BSI Konsolidierung + Elementare Gefaehrdungen + Threat/Vulnerability Verlinkung
4. **Sprint 4 (Hoch — Kataloge):** DiGAV 2023/2024 + BSI TR-03161 + KRITIS NIS2UmsuCG-Update + BCM MBL
5. **Sprint 5 (Mittel):** WCAG Tabellen/Navigation + Schutzbedarfsvererbung + CIS IG-Labels + TISAX 6.0.3
6. **Sprint 6 (Mittel):** Grundschutz-Check View + NIST CSF Subcategories + GxP Subpart C + Risk Aggregation
7. **Sprint 7 (Niedrig):** EU AI Act Framework + GAMP 5 + Dependencies strukturiert + i18n Fixes

## Gesamt-Statistik

| Metrik | Wert |
|--------|------|
| **Projekte** | 8 |
| **Massnahmen gesamt** | 67 |
| **Kritisch** | 11 |
| **Hoch** | 30 |
| **Mittel** | 21 |
| **Niedrig** | 5 |
| **Compliance-Kataloge** | 25 Commands |
| **Davon geprueft** | 24/25 (TKG ausstehend) |
| **Davon veraltet** | 3 (DiGAV, KRITIS, TISAX Base) |
| **Nicht idempotent** | 7 |