# Compliance-Katalog — Vollständiges Architektur-Bild (2026-06-13)

Ein Bild über ALLE Katalog-Teile: Datenmodell, Quellen, und die vier
Lebenszyklus-Phasen **Laden · Korrigieren · Mappen · Konsumieren** — inkl. jeder
UI-/CLI-Oberfläche, was verdrahtet ist, und wo Chaos/Risiko sitzt. Zweck: Basis
für eine saubere Re-Architektur, ohne einen Teil zu vergessen.

> **Status:** Diagnose/Bestandsaufnahme nach Audit aller ~90 Katalog-Commands,
> der Wiring-Services, Migrationen und Mapping-Bibliothek. Noch keine Code-Änderung.
> Ergänzt `COMPLIANCE_CATALOG_WIRING_AUDIT.md` (Loader-Detail). Dieses Dokument
> ist die übergeordnete Architektur-Sicht.

---

## 0. TL;DR — die 5 strukturellen Kernprobleme

1. **Ein-Loader-pro-Code-Falle.** UI installiert via `match`-Statement genau EINEN
   Loader pro Framework. Bei DORA/GDPR/BSI-C5/ISO27701/NIS2 zeigt das auf den
   **dünnen Legacy-Loader**, während der vollständige Katalog als CLI-Orphan
   ungenutzt liegt. (§3A)
2. **Drei-Quellen-Spagat ohne SoT.** Katalog-Inhalte kommen aus (a) ~90
   PHP-Loader-Commands, (b) 64 YAML in `fixtures/library/mappings/`, (c) 22 CSV in
   `fixtures/mappings/public/`. Drei Wahrheiten, keine kanonische Quelle. (§2)
3. **Framework-Code-Kollisionen.** Gleiche Norm, mehrere Code-Strings
   (`ISO-22301`/`ISO22301`/`ISO_22301`, `BSI_GRUNDSCHUTZ`/`BSI-GRUNDSCHUTZ`,
   `NIST-CSF`/`NIST-CSF-2.0`…) → dangling Mappings + tote Baseline-Referenzen. (§5)
4. **ID-Schema-Drift Loader↔Mapping.** Mappings (Seeds/Library) referenzieren
   requirementIds, die der UI-Loader gar nicht erzeugt → Mappings hängen ins Leere
   (z.B. DORA RTS/ITS, ISO27701-GDPR-*). (§3C, §5)
5. **Keine Garantie/kein Guard.** Kein Test der Registry↔Loader↔Schema↔Mapping
   konsistent hält; Korrektur-Pfade verstreut (CRUD-UI, CLI-migrate, QuickFix,
   Doctrine-Migrationen) ohne gemeinsames Modell. (§4, §6)

---

## 1. Datenmodell — die Tabellen

```
ComplianceFramework  (GLOBAL, kein tenant_id)
  ├─ code            : eindeutiger String (← Kollisionsquelle, §5)
  ├─ version, name, successor → (Versions-Migration, §3B)
  └─ requirements    : OneToMany, cascade=['remove']
        ↓
ComplianceRequirement  (GLOBAL; uploadTenant nur für custom/BYO)
  ├─ framework, requirementId  : (framework, requirementId) ist der Identity-Key
  ├─ requirementType: core | detailed | sub_requirement
  ├─ parentRequirement, category, priority
  ├─ dataSourceMapping (JSON: iso_controls[], audit_evidence…) ← Auto-Map-Pivot
  └─ title, description
        ↓ referenziert von
ComplianceMapping  (verbindet zwei Requirements)
  ├─ sourceRequirement, targetRequirement (ManyToOne, onDelete CASCADE)
  ├─ mappingPercentage, mappingType/relationship (equivalent/subset/…)
  ├─ lifecycle_state: draft→review→approved→published→deprecated
  ├─ source/provenance/provenanceUrl, version, valid_from/until
  └─ MQS-Felder: calculated_percentage, analysis_confidence,
                 textual_similarity, keyword_overlap, mqsBreakdown

ComplianceRequirementFulfillment  (TENANT-scoped — der eigentliche Ist-Stand)
  └─ requirement, tenant, fulfillmentPercentage, applicable, responsible…

FulfillmentInheritanceLog  (TENANT-scoped — Mapping→Erfüllung-Vererbung, review)
  └─ aus Mapping abgeleiteter Vorschlag, status pending_review→confirm/override
```

**Scope-Asymmetrie (wichtig für Re-Architektur):** Katalog (Framework +
Requirement + Mapping) ist **global/geteilt**; nur Fulfillment + Inheritance sind
**tenant-scoped**. → Ein Tenant der ein Framework lädt, ändert die globale Sicht
aller. 2. Tenant der dasselbe lädt → `UniqueConstraintViolationException`.

---

## 2. Katalog-Quellen (woher Daten kommen) — 3 unkoordinierte Wahrheiten

| Quelle | Ort | Umfang | Lädt was | Verdrahtung |
|---|---|---|---|---|
| **PHP-Loader-Commands** | `src/Command/Load*/Seed*/Supplement*` | ~90 Commands | Requirements (hardcoded Arrays od. fixtures) + manche Framework-Rows | UI nur über `ComplianceFrameworkLoaderService` match (1 pro Code), Rest CLI-Orphan |
| **Mapping-Library YAML** | `fixtures/library/mappings/*.yaml` | **64 Dateien** | Cross-Framework-Mappings + Metadaten (methodology, gap_warning, provenance, confidence) | CLI `app:mapping:library:import` (kein UI) |
| **Mapping-CSV (public)** | `fixtures/mappings/public/*.csv` | **22 Dateien** | Cross-Framework-Mappings (CI/CD-Bootstrap) | CLI `app:mappings:import-csv` (kein UI) |
| **Seed-Mappings (Code)** | `src/Command/Seed*Iso27001Mappings*` | 8 Commands | Vorgefertigte Crosswalks X↔ISO27001/27701 | UI `ComplianceMappingSeedController` |
| **Industry-Baselines** | `fixtures/baselines/*.yaml` | 5+ | referenzieren nur Framework-CODES (laden NICHT) | `app:load-industry-baselines` |
| **Framework-Row-Pre-Create** | Migrationen `Version20260506213310`, `…531120000` | 11+ Rows | leere Framework-Rows für „Full"-Loader | automatisch beim Deploy |

→ Mapping-Daten existieren **dreifach** (Seeds-Code, 64 YAML, 22 CSV) mit
teils überlappenden Paaren und unterschiedlicher ID-Toleranz. Keine
Single-Source-of-Truth, keine Dedup-Regel zwischen den drei.

---

## 3. Lebenszyklus — die 4 Phasen mit allen Oberflächen

### 3A. LADEN (Framework → Requirements in DB)

**Zentrale Registry:** `src/Service/ComplianceFrameworkLoaderService.php`
- `getAvailableFrameworks()` — hardcoded Liste **29 Frameworks** (UI-Katalog)
- `loadFramework(code)` — `match(code)` → genau EIN Loader (`:505-545`)

**UI-Pfad:**
- `/admin/compliance` (`AdminComplianceController:45`) — Framework-Library-Screen
- POST `/admin/compliance/frameworks/load/{code}` (`:65`) → `loadFramework()`
- POST `/admin/compliance/frameworks/delete/{code}` — löschen
- `/compliance/frameworks/manage`, `/compliance/framework/new|edit|toggle|duplicate`
  (`ComplianceFrameworkController`) — Framework-CRUD von Hand

**Setup-Wizard** (`DeploymentWizardController:1382`): wählt Frameworks → speichert
nur Session/`Tenant.settings`. **Lädt NICHTS.** Laden bleibt manuelle Admin-Aktion.

**Chaos hier (Detail in WIRING_AUDIT §3-4):**
- UI lädt schwachen Loader, voller Katalog ist Orphan: **DORA** (93 `DORA-N.M`;
  voller Art.N + **RTS/ITS-Katalog** sind Orphan), **BSI-C5** (30 statt 121/168),
  **GDPR** (48 statt 99 Art.), **ISO27701** (Alt-Schema), **NIS2** (48 statt voll).
- **ISO27001 ohne Klauseln 4-10** (nur Annex A geladen; Clauses-Loader nicht im match).
- **5 pre-exist-Loader** (ISO42001/27017/27018/EU-CRA/PCI-DSS): brechen ab wenn
  Framework-Row fehlt → funktioniert nur durch Pre-Create-Migration.
- **Idempotenz**: GDPR early-return blockt Re-Run; ISO27701 Blind-Insert → Dups.

### 3B. KORRIGIEREN (edit / repair / version-migrate / delete)

| Oberfläche | Route / CLI | Tut was | Verdrahtet | Risiko |
|---|---|---|---|---|
| Requirement anlegen | POST `/compliance/requirement/new` | Einzel-Req erstellen (ROLE_USER) | UI-Form | global, kaum Validierung |
| Requirement editieren | POST `/compliance/requirement/{id}/edit` | title/desc/priority/category/type/parent | UI-Form | bearbeitet auch system rows global |
| Requirement löschen | POST `/compliance/requirement/{id}` | hard delete (ROLE_ADMIN) | UI-Button | **lässt ComplianceMapping verwaisen** |
| MRIS-Reifegrad | POST `/compliance/requirement/{id}/mris-maturity` | current/target (MRIS) | UI-Form | ROLE_MANAGER, audit |
| Framework-Version migrieren | CLI `app:migrate-framework-version --from --to` | erzeugt Mapping-„Bridges" (kein Re-Key); `--dry-run` | **CLI-only** | kein UI-Button |
| Mapping-Reqs sicherstellen | CLI `app:mapping:ensure-requirements` | legt Stub-Requirements an für danglende Mapping-IDs | **CLI-only** | non-destructive |
| Framework löschen | POST `/compliance/framework/{id}/delete` | löscht Framework+Reqs (cascade) | UI-Button | **Mappings verwaisen** (FK ohne onDelete) |
| QuickFix Reconcile | POST `/quick-fix/reconcile`, `/admin/data-repair/schema/reconcile` | Schema-Drift (ALTER/CREATE), saveMode | UI+CLI | berührt compliance_* nur als Schema |
| QuickFix Repair-Duplicates | POST `/quick-fix/repair-duplicates` | dedup | UI | **deckt compliance_* NICHT ab** (allowlist) |
| Re-Key/Merge-Migrationen | `Version20260507212829` (BSI-Code-Merge), `Version20260506213529` (ISO-22301/NIST/SOC2-Merge) | mergen Framework-Codes, verschieben Reqs | Doctrine-Migration | einmaliger Upgrade-Cleanup |
| Custom-Upload | nur TISAX-Import-Wizard (`uploadTenant`) | Tenant-eigene Reqs hochladen | TISAX-only | **kein generischer Bulk-Upload** |

**Lücke:** Es gibt KEINE einheitliche „Katalog pflegen"-Oberfläche. Korrekturen
verteilen sich auf Einzel-CRUD (UI), Version-Migrate (CLI), QuickFix/DataRepair
(Schema/Tenant, nicht Katalog-Inhalt) und Doctrine-Migrationen (Code-Merges).
Re-Keying von requirementIds passiert nur per SQL-Migration, nie per UI.

### 3C. MAPPEN (Cross-Framework-Crosswalks)

**Manuell (UI):** `ComplianceMappingController`
- `/compliance/mapping/hub`, `/compliance/mapping` (Liste + Eval + MQS-Anzeige)
- `/compliance/mapping/wizard` (4-Schritt), `/compliance/mapping/new`,
  `/{id}/edit`, `/{id}` (delete, ROLE_ADMIN), `/{id}/analyze` (MQS berechnen)
- POST `/compliance/mapping/bulk-status-change` — Lifecycle bulk
  (draft→review→approved→published→deprecated; ROLE_MANAGER ab approved), `logBulk`

**Vorgefertigt (Seeds, 8) — UI `ComplianceMappingSeedController`:**
`/compliance/mappings/seeds` (ROLE_MANAGER) → `/{id}/apply` ruft Seed-Command.
Paare (jeweils ↔ ISO27001, außer letzter): BSI~42, SOC2~40, C5-2026~18, NIS2~81,
DORA~70, TISAX~100, GDPR~40, GDPR↔ISO27701~60. *(Counts ca., zu verifizieren.)*

**Datei-Bibliothek (CLI):**
- `app:mapping:library:import` ← 64 YAML (`fixtures/library/mappings/`),
  Validierung via `MappingValidatorService`, MQS post-persist
- `app:mappings:import-csv` ← 22 CSV (`fixtures/mappings/public/`), ID-Varianten-tolerant
- `app:import-cross-framework-mappings` ← Consultant-CSV, **UI**
  `/compliance/mapping/import` (dry-run + commit)
- `CreateCrossFrameworkMappingsCommand`, `ImportSubMappingsCommand` — weitere CLI

**Auto/transitiv:** `ComplianceMappingAdminController::createCrossFrameworkMappings`
(POST `/compliance/frameworks/create-mappings`) — Pivot über ISO27001 via
`dataSourceMapping.iso_controls` (3 Strategien). **Kein UI-Button**, kein AI.

**Qualität:** `MappingQualityController`, MQS-Score (textual_similarity,
keyword_overlap, confidence) — Bewertung, keine Generierung.

**Chaos hier:** ID-Schema-Drift — Mappings referenzieren IDs die der UI-Loader
nicht erzeugt:
- **DORA**: Seed mappt `DORA-N.M` (passt zum UI-Loader). Aber RTS/ITS-Katalog
  (`RTS-*`/`ITS-*`) ist gar nicht geladen → jegliche RTS/ITS-Mappings dangeln.
- **ISO27701**: Seed nutzt `27701-A.*` + `27701-GDPR-*`; Loader erzeugt
  `27701-5.*` + `27701-A.7.2.1` → Teil-Mismatch, Seed skippt fehlende (Warnung).
- Drei Mapping-Quellen (Seeds/YAML/CSV) ohne Dedup → potenziell doppelte/
  widersprüchliche Mappings desselben Paares.

### 3D. KONSUMIEREN (wozu der ganze Katalog da ist)

| Konsument | Service/Controller | Nutzt |
|---|---|---|
| Erfüllungs-Vererbung | `ComplianceInheritanceService` → `FulfillmentInheritanceLog` | Mapping best-by-confidence → Vorschlag, review-gated |
| Data-Reuse-Analyse | `ComplianceMappingService::getDataReuseAnalysis` | Controls/Assets/BCM/Incident/Audit → Fulfillment % |
| Dashboards/Statistik | `AdminComplianceController::statistics`, Inheritance-Metrics | loaded/mandatory %, fill-rate |
| Gap-Reports + Export | `/compliance/framework/{id}/gaps`, `/data-reuse`, transitive/comparison Export (Excel/PDF) | Requirements ↔ Fulfillment ↔ Mappings |
| Wizards | `ComplianceWizardController` (`/compliance/{wizard}/…`) | Assessment; **manual-checks scoren 0** (bekannt) |
| Framework-spez. | `DoraComplianceController`, `Nis2ComplianceController` | normspezifische Sichten |

---

## 4. Komponenten-Matrix (verdrahtet vs. Orphan)

| Schicht | Komponente | Verdrahtung | Anmerkung |
|---|---|---|---|
| Registry | `ComplianceFrameworkLoaderService` | ✅ UI (Admin) | SoT für „was lädt UI" |
| Loader | ~30 Load*Requirements / *Full im match | ✅ je 1/Code | Rest CLI-Orphan |
| Loader | DORA-Full, DORA-RTS-ITS, C5-Full×2, GDPR-Full, NIS2-Full, ISO27001-Clauses, BSI-Legacy×5, NIST-CSF-2.0-Full, EU-AI-ACT-alt | ❌ Orphan | nie über UI; teils der eigentlich gute Katalog |
| Korrektur | Requirement-CRUD | ✅ UI | verwaist Mappings beim Löschen |
| Korrektur | migrate-framework-version, ensure-requirements | ⚠️ CLI-only | kein UI |
| Mapping | Mapping-CRUD/Wizard/Bulk/Eval | ✅ UI | |
| Mapping | 8 Seeds | ✅ UI (SeedController) | |
| Mapping | 64 YAML-Library, 22 CSV | ⚠️ CLI-only | nicht im UI, Bootstrap/CI |
| Mapping | transitiv-auto | ⚠️ POST, kein Button | |
| Konsum | Inheritance/Reuse/Gap/Export | ✅ | |

---

## 5. Konsolidiertes Chaos-Register (alles, nummeriert)

| # | Klasse | Befund | Wirkung |
|---|---|---|---|
| C1 | Loader-Wahl | UI lädt dünnen Legacy statt vollem Katalog (DORA, BSI-C5, GDPR, ISO27701, NIS2) | Nutzer bekommt unvollständigen Katalog, voller liegt brach |
| C2 | Coverage | ISO27001 ohne Klauseln 4-10 | ISMS-Kern fehlt |
| C3 | Coverage | DORA RTS/ITS-Katalog nie geladen + 2 Instrumente fehlen (CDR 2024/1502, 2024/1505) + falsche Nummern (Incident-Reporting 2025/301+302) | Level-2 unvollständig & unmappbar im Normalbetrieb |
| C4 | Code-Kollision | ISO-22301/ISO22301/ISO_22301; BSI_GRUNDSCHUTZ/-/-KERN/-STANDARD; NIST-CSF/-2.0; BSI-C5/-2025/-2026; KRITIS/-DE; SOC2/-TYPE-II; ENISA-EUCS ohne Loader | dangling Mappings, tote Baseline-Referenzen |
| C5 | ID-Drift | Mappings (Seeds/YAML/CSV) ↔ Loader-IDs uneinheitlich (DORA RTS, ISO27701-GDPR-*) | Mappings hängen ins Leere/werden geskippt |
| C6 | Mehr-Quellen | Mapping-Daten dreifach (8 Seeds + 64 YAML + 22 CSV) ohne SoT/Dedup | Doppel/Widerspruch, Pflege unklar |
| C7 | Idempotenz | GDPR early-return; ISO27701 Blind-Insert; *Full pre-exist-Abbruch | Re-Run kaputt / harte Fails |
| C8 | Referenz-Integrität | Framework/Requirement-Delete verwaist Mappings (FK ohne onDelete-Strategie auf framework) | Broken FKs |
| C9 | Korrektur-Zersplitterung | CRUD-UI + CLI-migrate + QuickFix + Doctrine-Migration, kein gemeinsames Modell; Re-Key nur per SQL | schwer wartbar, fehleranfällig |
| C10 | Tote Loader | 5 BSI-Legacy @deprecated ohne Removal; LoadAnnexAControls (falscher Prefix, kein Idempotenz) | Tech-Debt |
| C11 | Scope | Katalog global, Fulfillment tenant — 2. Tenant-Load wirft Unique-Violation mit verwirrender Meldung | UX/Multi-Tenant-Falle |
| C12 | Garantie | kein Test Registry↔Loader↔Schema↔Mapping-Konsistenz | Regressions unbemerkt |

---

## 6. Ziel-Architektur — Leitplanken für den Umbau

1. **Eine deklarative Katalog-Quelle pro Framework (SoT).** Framework-Metadaten +
   Requirements + Versionsschema als versionierte Daten-Dateien (YAML/JSON unter
   `config/catalogs/<code>/`), nicht in hardcoded PHP-Arrays. Loader wird zum
   generischen Reader (ein Command statt 90). Mapping-Library bleibt deklarativ,
   wird aber gegen denselben SoT-Schlüsselraum validiert.
2. **Kanonisches Code-Register.** Ein `FrameworkCode`-Enum/Konstanten als einzige
   Quelle; Migration die alle Alt-Codes konsolidiert; Stylelint-artiger Gate gegen
   neue Roh-Strings. (löst C4)
3. **Voller Katalog ist Default.** Registry verweist immer auf den vollständigen
   Katalog; „simplified/legacy" nur als bewusste, markierte Variante. (löst C1/C2/C3)
4. **ID-Schema-Vertrag.** Pro Framework genau EIN requirementId-Schema, in der
   SoT definiert; Mapping-Import validiert beide Seiten gegen geladene IDs und
   verweigert dangling (statt still skippen). `ensure-requirements` wird zur
   Lint-Stufe, nicht zum Reparatur-Workaround. (löst C5/C6)
5. **Referenz-Integrität.** `ComplianceMapping` FK auf framework/requirement mit
   definierter onDelete-Strategie; Delete-UI warnt + räumt Mappings. (löst C8)
6. **Eine Pflege-Oberfläche.** Katalog-Verwaltung (laden/editieren/versionieren/
   re-keyen/löschen) in einem Admin-Modul mit klarem Modell; Versions-Migrate +
   ensure-requirements als UI-Aktion. (löst C9)
7. **Konsistenz-Gate (CI).** Test: jeder Registry-Code hat genau 1 lauffähigen
   Loader; keine doppelten Codes; jedes Mapping zeigt auf existierende IDs des
   Ziel-Schemas; keine zwei Loader schreiben denselben Code mit verschiedenen
   Schemata. (löst C7/C10/C12)
8. **Tenant-Modell klären.** Entscheidung explizit machen: Katalog global
   (read-only-Stammdaten, ein Install-Schritt) vs. tenant-aktivierbar. Unique-
   Violation-Pfad in „bereits installiert" verwandeln. (löst C11)

---

## 7. Datei-Index (Einstiegspunkte)

**Laden:** `src/Service/ComplianceFrameworkLoaderService.php`,
`src/Controller/AdminComplianceController.php`,
`src/Controller/ComplianceFrameworkController.php`,
`src/Command/Load*Command.php` (~60), `config/modules.yaml` (sample_data),
`migrations/Version20260506213310.php`, `Version20260531120000.php` (Row-Pre-Create)

**Korrigieren:** `src/Controller/ComplianceRequirementController.php`,
`src/Command/MigrateFrameworkVersionCommand.php`,
`src/Command/EnsureMappingRequirementsCommand.php`,
`src/Controller/FrameworkMigrationController.php`,
QuickFix/DataRepair-Controller, Merge-Migrationen `Version20260507212829`,
`Version20260506213529`

**Mappen:** `src/Controller/ComplianceMappingController.php`,
`ComplianceMappingAdminController.php`, `ComplianceMappingSeedController.php`,
`ComplianceMappingImportController.php`, `MappingQualityController.php`,
`src/Command/Seed*MappingsCommand.php` (8), `MappingLibraryImportCommand.php`,
`Import*MappingsCommand.php`, `src/Service/MappingLibraryLoader.php`,
`MappingValidatorService`, `fixtures/library/mappings/` (64 YAML),
`fixtures/mappings/public/` (22 CSV)

**Konsumieren:** `src/Service/ComplianceInheritanceService.php`,
`ComplianceMappingService.php`, `InheritanceMetricsService.php`,
`src/Controller/ComplianceInheritanceController.php`,
`ComplianceExportController.php`, `ComplianceWizardController.php`,
`DoraComplianceController.php`, `Nis2ComplianceController.php`

**Begleitdokument:** `docs/COMPLIANCE_CATALOG_WIRING_AUDIT.md` (Loader-Detailtabelle)

---

## 8. Offene Verifikationspunkte (vor Umbau prüfen)

- Exakte Seed-Mapping-Counts + ob YAML/CSV/Seed-Paare sich überschneiden (Dedup-Bedarf).
- ISO27701 Seed-Mismatch (`27701-GDPR-*`/`27701-5.*`) — wieviele Mappings real skippen.
- Welche der 64 YAML / 22 CSV auf Codes/Schemata zeigen die der UI-Loader NICHT erzeugt (dangling-Inventar).
- Ob `app:mappings:import-csv` im Deploy/CI automatisch läuft (dann sind CSV faktisch SoT, nicht die Seeds).
</content>
