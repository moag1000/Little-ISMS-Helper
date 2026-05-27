# DORA — Digital Operational Resilience Act (EU) 2022/2554

Stand: v3.5 (2026-05). Gilt fuer das Modul `nis2_dora` (Aktivierungskey in `config/modules.yaml`).

> **Hinweis:** VAIT, BAIT, KAIT und ZAIT sind seit dem 17. Januar 2025 durch DORA
> als sektorale Aufsichtsvorschriften abgeloest. Das Tool implementiert keine
> separaten VAIT/BAIT/KAIT/ZAIT-Wizards. Fuer Finanzinstitute unter BaFin-Aufsicht
> gilt DORA als lex specialis.

---

## 1. Ueberblick

DORA (Verordnung (EU) 2022/2554, gueltig ab 17. Januar 2025) staerkt die digitale
operationelle Resilienz des EU-Finanzsektors. Das Tool implementiert DORA auf zwei Ebenen:

- **Level 1:** DORA-Verordnung (Art. 1–64) — Kernanforderungen
- **Level 2:** Technische Regulierungsstandards (RTS) und Durchfuehrungsstandards (ITS)
  der ESAs (EBA, EIOPA, ESMA) — 131 granulare Anforderungen

---

## 2. Catalogue-Laden (Commands)

```bash
# DORA Level-1 Kernanforderungen
php bin/console app:load-dora-requirements

# DORA Level-1 vollstaendiger Katalog (inkl. Anhang)
php bin/console app:load-dora-full

# DORA Level-2 RTS/ITS/CIR-Katalog (131 Anforderungen)
php bin/console app:load-dora-rts-its-full

# Seed DORA ↔ ISO 27001:2022 Mappings
php bin/console app:seed-dora-iso27001-mappings

# Seed DORA Policy-Templates
php bin/console app:seed-dora-policy-templates
```

---

## 3. DORA Level-2 RTS/ITS Katalog

Datei: `src/Command/LoadDoraRtsItsFullCommand.php`

### 3.1 Identifier-Schema

| Praefx | Regelwerk | Quelle |
|---|---|---|
| `RTS-ICT-RMF-Art.X` | RTS ICT Risk Management Framework | JC 2023/86 |
| `RTS-ICT-RMF-SIMPL-Art.X` | RTS vereinfachtes ICT-RMF (Art. 16 DORA) | JC 2023/86 |
| `RTS-INC-CLASS-Art.X` | RTS Klassifizierung Hauptvorfaelle | CDR 2024/1772 |
| `RTS-INC-REPORT-Art.X` | RTS Meldeinhalte/Templates | CDR 2024/1774 |
| `ITS-INC-REPORT-Art.X` | ITS Meldetemplates | CIR (EU) 2024/2955 |
| `ITS-Register-B.XX.YY` | ITS Register of Information Templates | CIR (EU) 2024/2956 |
| `RTS-Subcontracting-Art.X` | RTS Subcontracting | CDR 2025/532 |
| `RTS-TLPT-Art.X` | RTS Threat-Led Penetration Testing | JC 2024 |
| `RTS-Oversight-Art.X` | RTS Oversight-Harmonisierung | JC 2024 |

### 3.2 Wichtige Quellen

- JC 2023/86 — Final Report RTS ICT Risk Management Framework (EBA/EIOPA/ESMA)
- CIR (EU) 2024/1772 — RTS Klassifizierung ICT-Hauptvorfaelle (Art. 18 DORA)
- CIR (EU) 2024/1774 — RTS Meldeinhalt ICT-Vorfaelle (Art. 20 DORA)
- CIR (EU) 2024/2955 — ITS Meldetemplates (Art. 20 DORA)
- CIR (EU) 2024/2956 — ITS Register of Information (Art. 28 Abs. 9 DORA)
- CDR (EU) 2025/532 — RTS Subcontracting (Art. 30 Abs. 5 DORA)

---

## 4. ICT-Incident-Felder an `Incident` (Art. 17–19 DORA)

Datei: `src/Entity/Incident.php`

Alle Felder sind hinter dem Modul `nis2_dora` gesperrt (T31.2.2).

### 4.1 Klassifizierungs-Felder (Art. 18 DORA)

| Feld | Typ | Norm-Referenz |
|---|---|---|
| `ictIncidentClassification` | ?string | Art. 18 (Klassifizierung Major/Minor) |
| `doraClassification` | ?string | CIR 2024/1772 (Major ICT Incident) |
| `doraClientsImpacted` | ?int | Art. 18 Abs. 1 lit. a (Betroffene Kunden) |
| `doraReputationImpact` | ?string | Art. 18 Abs. 1 lit. b (Reputationsschaden) |
| `doraServiceDowntimeMinutes` | ?int | Art. 18 Abs. 1 lit. c (Ausfallzeit) |
| `doraGeographicalSpread` | ?array | Art. 18 Abs. 1 lit. d (Geografische Ausbreitung) |
| `doraDataLossOccurred` | ?bool | Art. 18 Abs. 1 lit. e (Datenverlust) |
| `doraEconomicImpactEur` | ?int | Art. 18 Abs. 1 lit. f (Wirtschaftlicher Schaden EUR) |

### 4.2 Melde-Fristen (Art. 19 DORA)

| Frist | DORA-Referenz | SLA-Entitaet |
|---|---|---|
| 4 Stunden | Art. 19 Abs. 4 lit. a (Erstmeldung) | `IncidentSlaConfig` |
| 24 Stunden | Art. 19 Abs. 4 lit. b (Zwischenmeldung) | `IncidentSlaConfig` |
| 1 Monat | Art. 19 Abs. 4 lit. c (Schlussmeldung) | `IncidentSlaConfig` |

Gegenueber NIS2: DORA Art. 19 Abs. 4 lit. a (4h) ist scharfer als NIS2 Art. 23 (24h).
Bei Ueberschneidung gilt DORA als lex specialis gemaess Art. 4 Abs. 1 NIS2-RL.

---

## 5. Workflow — Incident Response (High/Critical)

Out-of-the-Box-Workflow, generiert ueber:

```bash
php bin/console app:generate-regulatory-workflows --workflow=incident-high
```

Normative Grundlage: DORA Art. 17–19, ISO 27001:2022 Kl. 6.1/8.1.

**6 Schritte:**

| Schritt | Verantwortlich | DORA-Referenz |
|---|---|---|
| 1. CISO-Response | CISO | Art. 17 Abs. 1 |
| 2. ICT-Klassifizierung | CISO + IT | Art. 18 |
| 3. Erstmeldung Aufsicht | CISO/DPO | Art. 19 Abs. 4 lit. a (4h) |
| 4. Eindaemmung + Analyse | IT + CISO | Art. 17 Abs. 3 |
| 5. Zwischenmeldung | CISO | Art. 19 Abs. 4 lit. b (24h) |
| 6. Post-Incident-Review | CISO + Management | Art. 17 Abs. 7 + Schlussmeldung 1M |

---

## 6. Cross-Mapping DORA ↔ NIS2

Norm-Grundlage: Art. 4 Abs. 1 NIS2-RL (Richtlinie (EU) 2022/2555) — DORA als lex specialis.

Finanzunternehmen im Sinne des DORA, die gleichzeitig als wesentliche oder wichtige
Einrichtungen nach NIS2 gelten, erfuellen NIS2-Pflichten durch DORA-Konformitaet
(sofern Anforderungen mindestens gleichwertig).

| NIS2 Art. | DORA Aequivalent | Bemerkung |
|---|---|---|
| Art. 21 Abs. 2 lit. a (Policies) | Art. 5–16 (ICT-RMF) | DORA detaillierter |
| Art. 21 Abs. 2 lit. b (Incident Handling) | Art. 17–23 | DORA-Fristen schaerfer |
| Art. 21 Abs. 2 lit. c (BCM) | Art. 11–12 | DORA: RCBC + DR |
| Art. 21 Abs. 2 lit. d (Supply Chain) | Art. 28–44 | DORA: Third-Party-Risk-Framework |
| Art. 21 Abs. 2 lit. e (Security in Acquisition) | Art. 9 | Konnex |
| Art. 21 Abs. 2 lit. h (Krypto) | Art. 9 Abs. 2 | Konnex |
| Art. 23 (Meldepflicht) | Art. 19–20 | DORA-Fristen: 4h/24h/1M |

---

## 7. Cross-Mapping DORA ↔ ISO 42001 v2

DORA adressiert KI-Systeme im Kontext des ICT-Risikomanagements implizit.
ISO/IEC 42001:2023 liefert das KI-Managementsystem-Rahmenwerk.

| DORA | ISO 42001 | Thema |
|---|---|---|
| Art. 6 (ICT-Risikorahmen) | Kl. 6.1.2 (AI Risk Assessment) | KI als ICT-Asset im Risikorahmen |
| Art. 8 (ICT-Schutz) | Annex A 6.1 (AI System Impact Assessment) | Schutz KI-Inferenz-Infrastruktur |
| Art. 9 (Erkennung) | Annex A 8.4 (Monitoring of AI systems) | KI-Verhaltensmonitoring |
| Art. 28 (Third-Party) | Annex A 5.3 (AI Supply Chain) | KI-Zulieferer und Modell-Anbieter |

Implementierung: `src/Command/LoadIso42001FullCommand.php`, `src/Entity/Asset.php`
(Felder `aiAgentType`, `aiRiskLevel`, `aiActClassification`).

---

## 8. Policy-Templates (DORA)

Generiert ueber `src/Command/SeedDoraPolicyTemplatesCommand.php`.

---

## 9. Register of Information Export

DORA Art. 28 Abs. 3 lit. a verlangt ein Register der IKT-Drittdienstleister.

Zwei Export-Pfade stehen parallel zur Verfuegung:

### 9.1 XLSX/CSV/PDF (ITS-Vorlage)

```
src/Controller/DoraRegisterExportController.php
src/Service/Export/DoraRegisterOfInformationExporter.php
```

Export nach ITS-Vorlage CIR (EU) 2024/2956. Geeignet fuer manuelle Sichtung
oder Vorbereitung der Meldung.

### 9.2 XBRL (Sprint 8 + Sprint 9 — Bundesbank/BaFin-Submission)

```
src/Controller/Authority/DoraRoiController.php
src/Service/Authority/DoraRoiXbrlExporter.php
tests/Service/Authority/DoraRoiXbrlExporterTest.php
scripts/validate-dora-xbrl.sh
```

XBRL-Output nach ESA Joint RoI Taxonomy (Art. 28 Abs. 9 DORA i.V.m.
CIR (EU) 2024/2956). Wird ueber `/authority/dora-roi` ausgeloest und liefert
ein well-formed XBRL-Dokument mit den folgenden ESA-Taxonomie-Elementen:

| Element | Quelle | Sprint |
|---|---|---|
| `B_01.01.0010` (Reporting-Entity-Name) | `Tenant.legalName \|\| Tenant.name` | 8 |
| `B_01.01.0020` (Reporting-Entity-LEI) | `Tenant.leiCode` (ISO 17442) | **9 / 6b** |
| `B_01.01.0030` (Reporting Reference Date) | Stichtag (Jahresende) | 8 |
| `B_01.01.0040` (Reporting-Currency) | `Tenant.reportingCurrency` (ISO 4217) | **9 / 6b** |
| `B_02.01.0010` (Anzahl ICT-Drittdienstleister) | `count($suppliers)` | 8 |
| `B_02.02.0010-0050` (Provider-Stammdaten) | Supplier | 8 |
| `B_02.02.0020` (Provider-LEI) | `Supplier.leiCode` | 8 |
| `B_02.02.0060` (Contract Start Date) | `Supplier.contractStartDate` | **9 / 6c** |
| `B_02.02.0070` (Contract End Date) | `Supplier.contractEndDate` | **9 / 6c** |
| `B_02.02.0080` (Substitutability) | `Supplier.substitutability` | **9 / 6c** |
| `B_02.02.0090` (Exit Strategy Present) | `Supplier.hasExitStrategy` | **9 / 6c** |
| `B_02.02.0100` (Data Location EEA/non-EEA) | abgeleitet aus `Supplier.countryOfHeadOffice` | **9 / 6c** |
| `B_02.02.0110` (Processing Locations) | `Supplier.processingLocations` (JSON) | **9 / 6c** |
| `B_02.02.0120` (Certifications) | `hasISO27001 \|\| hasISO22301 \|\| certifications` | **9 / 6c** |
| `B_02.02.0130` (Audit Rights Clause) | abgeleitet aus `Supplier.securityRequirements` | **9 / 6c** |
| `B_03.01.0010` (Total ICT Assets) | `count($assets)` | 8 |
| `B_03.02.0010-0100` (Per-Asset Detail) | `Asset` (id, name, type, classification, CIA, owner, location, status) | **9 / 6c** |
| `B_03.03.0010` (Total Dependency Edges) | `count($edges)` über alle DORA-relevanten `Asset.dependsOn` Kanten | **9 / 6 close (RT_05)** |
| `B_03.03.0020-0080` (Per-Edge Asset-Dependency-Graph, RT_05) | `Asset.dependsOn` + optional `AssetDependency` Join-Entity (Type + Cascade + Notes) | **9 / 6 close (RT_05)** |
| `RT_03.0010-0070` (Data-Flow-Sub-Table) | `DoraDataFlow` (supplier, direction, categories, purpose, security, volume, cross-border, country) | **9 / 6.9** |

**Implementiert (Bucket-6 close, 2026-05-26):**
- ~~RT_04 (Subcontractor-Chain-Sub-Table)~~ — `DoraSubcontractor`-Entity (tier 2-5) +
  CRUD unter `/dora/subcontractor` + rekursiver Chain-Walker im XBRL-Exporter
  (`<roi:RT_04_subcontractor_chain>` → `<roi:RT_04_subcontractor>` → `RT_04.0010-0070` +
  geschachtelte `<roi:RT_04_children>` für tier ≥ 3). Migration:
  `Version20260617100000_DoraSubcontractorRt04Chain`.

**Noch nicht implementiert (deferred):**
- `B_02.02.0140-0999` (verbleibende Provider-Stammdaten-Felder)
- RT_06 (Decommission-Plan)

Diese ESA-Taxonomie-Bereiche benoetigen dedizierte Sub-Entities, die bisher nicht im
Datenmodell vorhanden sind. Markiert via `TODO`-Kommentar im Output.

**RT_03 (Data-Flow) — closed:**
`DoraDataFlow` Entity + CRUD unter `/dora/data-flow/*` + automatische
RT_03-Emission per Provider via {@see DoraRoiXbrlExporter}. Module-gated
auf `nis2_dora`. Pro Datenfluss werden RT_03.0010-0070 (Richtung,
Kategorien, Zweck, Sicherheitsmaßnahmen, Volumen, Cross-Border-Flag,
Empfängerland) emittiert.

**RT_05 Asset-Dependency-Graph (seit Bucket-6 close 2026-05-26):** Die neue
`AssetDependency`-Join-Entity (Migration `Version20260617100000_AssetDependencyEnrichedEdges`)
sitzt neben der bestehenden `asset_dependencies` ManyToMany-Tabelle (die fuer BSI 3.6
Schutzbedarfsvererbung und den GstoolXmlImporter unveraendert bleibt) und traegt
pro Kante:
- `dependency_type` — `requires` | `backs_up` | `shares_data` | `redundant_with`
- `criticality_impact` — `cascade` | `isolated` | `partial`
- `notes` — Freitext (z.B. "DB-Verbindung via VPN")

Edges ohne `AssetDependency`-Eintrag bekommen im Export die Default-Klassifizierung
`requires` / `cascade`. Eine Tree-Renderer-Sektion auf `templates/asset/show.html.twig`
visualisiert den Graphen pro Asset.

### 9.3 Pre-Submission XBRL-Validierung (Arelle)

Vor der Einreichung beim Bundesbank/BaFin-Portal sollte der XBRL-Output gegen
die ESA-Taxonomie validiert werden:

```bash
# Lokales Tool — nicht (noch) im CI verdrahtet
./scripts/validate-dora-xbrl.sh /tmp/exported-roi.xbrl
```

Das Skript prueft zwei Stufen:

1. **XML-Well-Formedness** via `xmllint` (immer verfuegbar)
2. **XBRL-Taxonomie-Validierung** via `arelle` (optional, `pip install arelle-release`)

Bei fehlender Arelle-Installation beendet das Skript mit Exit-Code 0 nach erfolgreicher
XML-Pruefung und gibt eine Installationsanleitung aus. Die ESA hat den finalen
Taxonomie-Namespace zum jetzigen Stand noch nicht veroeffentlicht — sobald das ITS
verabschiedet ist, wird der Namespace in `DoraRoiXbrlExporter::NS_ESA_ROI` aktualisiert
und das Arelle-Plugin verdrahtet.

### 9.4 LEI-Verwaltung

Tenant-LEI wird in `TenantType` als Pflichtfeld fuer DORA-obligated Tenants gefuehrt:

- Validierung: `/^[A-Z0-9]{18}\d{2}$/` (ISO 17442)
- Speicherung: `tenant.lei_code` (VARCHAR 20 NULL)
- Beispiel: `529900T8BM49AURSDO55`
- Bezugsquelle: jeder GLEIF-akkreditierte LOU (Local Operating Unit)

Supplier-LEI: identisches Format, gespeichert in `supplier.lei_code`. Beide Felder
sind nullable; fehlender LEI im Export erzeugt den ESA-Sentinel `N/A`.

---

## 10. Compliance-Wizard

Route: `/{locale}/compliance/wizard/dora`

Datei: `src/Service/ComplianceWizard/Check/PolicyWizard/Dora/`

Durchgefuehrte Checks:

| Check-Klasse | DORA-Referenz |
|---|---|
| `DoraIctRiskFrameworkPresentCheck` | Art. 5–16 |
| `DoraThirdPartyRegisterMaintainedCheck` | Art. 28 Abs. 3 |
| `DoraValidityFromCheck` | Art. 2 (Geltungsbereich) |
| `DoraExtensionCoverageCheck` | Level-2 Abdeckung |
| `DoraTlptCadenceCheck` | Art. 26 (TLPT-Kadenz) |
| `DoraIncidentReportingDeadlinesCheck` | Art. 19 Abs. 4 |

---

## 11. Modul-Aktivierung

```yaml
# config/active_modules.yaml
nis2_dora: true
```

Controller-Pattern:

```php
if ($redirect = $this->checkModuleActive('nis2_dora')) return $redirect;
```

Twig:

```twig
{% if is_module_active('nis2_dora') %}
    {# DORA-Felder sichtbar #}
{% endif %}
```

---

## 12. Referenzen

| Norm | Artikel | Implementierung |
|---|---|---|
| DORA (EU) 2022/2554 | Art. 5–16 | ICT-RMF, ComplianceWizard |
| DORA | Art. 17–19 | `Incident::$doraClassification` usw. |
| DORA | Art. 18 | `Incident::$ictIncidentClassification` |
| DORA | Art. 19 Abs. 4 | `IncidentSlaConfig` (4h/24h/1M) |
| DORA | Art. 28 Abs. 3 | `DoraRegisterOfInformationExporter` |
| CIR (EU) 2024/1772 | Art. 18-Klassifizierung | `LoadDoraRtsItsFullCommand` |
| CIR (EU) 2024/2956 | ITS Register-Templates | `DoraRegisterExportController` |
| NIS2 (EU) 2022/2555 | Art. 4 Abs. 1 | Lex-specialis-Regel |
| ISO/IEC 42001:2023 | Annex A | `Asset::$aiRiskLevel` |
