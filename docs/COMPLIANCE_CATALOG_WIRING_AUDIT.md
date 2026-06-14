# Compliance-Katalog Verdrahtung & Chaos-Audit (2026-06-13)

Vollständige Karte: welcher Framework-Katalog wo verdrahtet ist, wie er eingeführt
wird, welche Loader tot/orphan sind, und wo Code-Kollisionen + Coverage-Lücken
liegen. Erstellt nach Audit aller ~90 Katalog-Commands.

> **Status:** Bestandsaufnahme/Diagnose. Noch keine Code-Änderung. Fixes siehe §7.

---

## 1. Architektur — 3 Schichten

```
ComplianceFrameworkLoaderService          ← zentrale Registry (SoT)
  ├─ getAvailableFrameworks()  : hardcoded Array 29 Frameworks (UI-Liste)
  └─ loadFramework(code)       : match(code) → EIN Loader-Command
        ↓
  Load<X>Command (src/Command/)            ← schreibt ComplianceRequirement-Rows
        ↓
  ComplianceFramework + ComplianceRequirement (GLOBAL, nicht tenant-scoped!)
```

**Kritisch:** Pro Framework-Code läuft über die UI **genau EIN** Loader (das
`match`-Statement, `ComplianceFrameworkLoaderService.php:505-545`). Alle anderen
Loader desselben Frameworks (`*Full`, `*Catalogue`, `Supplement*`, Varianten)
sind **CLI-only** und werden im Normalbetrieb nie ausgeführt → Orphans.

**Tenant-Scope:** `ComplianceFramework` hat KEIN `tenant_id`. Kataloge sind
**global** — einmal geladen, für alle Tenants sichtbar. `ComplianceRequirement`
nur `uploadTenant` für user-eigene Custom-Reqs (NULL = global system row).
2. Tenant der dasselbe Framework lädt → `UniqueConstraintViolationException`
→ verwirrende Fehlermeldung statt "schon geladen".

---

## 2. Einführungs-Flow (nonexistent → installed → mappable)

1. **DB-Migration** (automatisch beim Deploy): legt LEERE Framework-Rows an für
   die 5 „Full"-Frameworks die pre-exist brauchen — `ISO42001` (Migration
   `Version20260531120000`), `ISO27017`/`ISO27018`/`EU-CRA`/`PCI-DSS-4.0.1`
   (`Version20260506213310:39-60`). Andere (ISO27001, GDPR…) NICHT pre-created.
2. **Setup-Wizard Step 8** (`DeploymentWizardController.php:1382`): User wählt
   Frameworks (3 Buckets mandatory/recommended/optional). **Speichert nur in
   Session + `Tenant.settings`. Lädt NICHTS.**
3. **Admin-Panel** `/admin/compliance` (`AdminComplianceController.php:45`):
   listet 29 Frameworks mit „Load"-Button. POST
   `/admin/compliance/frameworks/load/{code}` (`:65`) →
   `loadFramework(code)` → Command läuft → Rows entstehen → global mappbar.
4. **Industry-Baselines** (`fixtures/baselines/*.yaml`): referenzieren nur
   Framework-CODES (z.B. finance→DORA), **triggern kein Laden** — Codes müssen
   vorher geladen sein.

**Framework-Laden ist also reine manuelle Admin-Aktion NACH Setup.** Wizard-Auswahl
ist nur Notiz, kein Trigger.

---

## 3. MASTER-TABELLE — was die UI tatsächlich lädt

| Code (Registry) | UI-Loader (match) | ID-Schema | ~Count | Orphan-Loader desselben Frameworks (CLI-only, nie installiert) |
|---|---|---|---|---|
| TISAX | LoadTisaxRequirements | TISAX-* | ~40 | LoadTisaxAl3Requirements, SupplementTisax |
| **DORA** | **LoadDoraRequirements** | **DORA-N.M** | **93** | **LoadDoraFull (Art.N), LoadDoraRtsItsFull (RTS-*/ITS-* granular), SupplementDoraRts** |
| NIS2 | LoadNis2Requirements | NIS2-21.x | 48 | LoadNis2Full (Art.N), LoadNis2Art21Requirements |
| BSI_GRUNDSCHUTZ | LoadBsiItGrundschutzCatalogue ✅canonical | APP.x/SYS.x | ~360 | LoadBsiRequirements, LoadBsiItGrundschutzRequirements, LoadBsiKompendium2023Delta/Extended, SupplementBsiGrundschutz (alle @deprecated) |
| GDPR | LoadGdprRequirements | GDPR-5.1.a | 48 | LoadGdprFull (Art.1-99, 99 Artikel) |
| ISO27001 | LoadIso27001Requirements | A.5.1 | 93 | LoadIso27001AnnexAFull, **LoadIso27001Clauses (Klauseln 4-10!) NICHT geladen**, LoadAnnexAControls |
| ISO27701 | LoadIso27701Requirements | 27701-5.2.1 | ~95 | LoadIso27701Full (A.7.2.1, anderes Schema) |
| ISO27701_2025 | LoadIso27701v2025Requirements | 27701:2025-* | ~164 | — |
| BSI-C5 | LoadC5Requirements | ORP-M.x | ~30 | **LoadC52020FullCatalogue (121 Kriterien!)** |
| BSI-C5-2026 | LoadC52026Requirements | C5.x | ~50 | **LoadC52026FullCatalogue (168 Kriterien!)** |
| KRITIS | LoadKritisRequirements | KRITIS-8a-* | 150+ | — |
| KRITIS-HEALTH | LoadKritisHealthRequirements | — | 37 | — |
| DIGAV | LoadDigavRequirements | DIGAV-x | 40 | — |
| TKG-2024 | LoadTkgRequirements | TKG-164.x | 44 | — |
| GXP | LoadGxpRequirements | ANNEX11-x | 60 | — |
| SOC2 | LoadSoc2Requirements | CC1.1 | ~50 | — |
| NIST-CSF | LoadNistCsfRequirements | GV.OC-01 | ~105 | **LoadNistCsf2FullCatalogue (Code NIST-CSF-2.0 — komplett orphan, andere Codeschreibweise)** |
| CIS-CONTROLS | LoadCisControlsRequirements | CIS-1.1 | ~45 | — |
| ISO-22301 | LoadIso22301Requirements | ISO22301-4.1 | 33 | — (aber Code-Kollision, §4) |
| ISO27005 | LoadIso27005Requirements | 6.1 | 23 | — |
| BDSG | LoadBdsgRequirements | BDSG-1 | 12 | — |
| EU-AI-ACT | LoadEuAiActFull ✅canonical | Art.N | 113+13 | LoadEuAiActRequirements (AIACT-1..10, alt) |
| NIS2UMSUCG | LoadNis2UmsuCGRequirements | NIS2UMSUCG-x | 15 | LoadNis2UmsuCGFull (§-Schema, 41) |
| MRIS-v1.5 | LoadMrisRequirements | MHC-01..13 | 13 | — (Code ok) |
| ISO42001 | LoadIso42001Full ⚠️pre-exist | A.x + 4-10 | 63 | — |
| ISO27017 | LoadIso27017Full ⚠️pre-exist | CLD.x | 24 | LoadIso27002Iso27017Iso27018Expansion |
| ISO27018 | LoadIso27018Full ⚠️pre-exist | A.x | 46 | LoadIso27002Iso27017Iso27018Expansion |
| EU-CRA | LoadEuCraFull ⚠️pre-exist | CRA-Annex-I-x | 54 | — |
| PCI-DSS-4.0.1 | LoadPciDss401Full ⚠️pre-exist | 1.1 | 64 | — |

✅ = UI lädt den guten/vollen Katalog. ⚠️pre-exist = Loader braucht vorab per
Migration angelegte Framework-Row, sonst `Command::FAILURE`.

---

## 4. CHAOS-KLASSEN

### A. UI lädt den SCHWACHEN Katalog, der volle ist Orphan
Bei mehreren Frameworks zeigt das `match` auf den alten/dünnen `*Requirements`-Loader
während der vollständige `*Full`/`*Catalogue` ungenutzt herumliegt:
- **DORA:** UI = 93 thematische `DORA-N.M`. Voller Art.N-Katalog + granularer
  **RTS/ITS-Katalog (LoadDoraRtsItsFull, ~110 RTS-*/ITS-* Artikel)** = Orphan.
  → Der RTS/ITS-Katalog wird im Normalbetrieb NIE installiert.
- **BSI-C5:** UI = ~30. Voller Katalog 121 (2020) / 168 (2026) = Orphan.
- **GDPR:** UI = 48 thematische. Voller 99-Artikel-Katalog = Orphan.
- **ISO27701:** UI = altes `27701-*`-Schema. `*Full` (A.7.2.1) = Orphan.
- **NIS2:** UI = 48 thematische. Voller Art.N + Art.21-Detail = Orphan.

### B. Framework-Code-Kollisionen (gleiche Norm, verschiedene Strings)
Verstreut über Loader/Fixtures/Migrations — bricht Mappings + Industry-Baselines
die per String referenzieren:
- ISO 22301: `ISO-22301` (Registry+Loader) vs `ISO22301` vs `ISO_22301` (Fixtures/Baselines)
- BSI Grundschutz: `BSI_GRUNDSCHUTZ` (underscore, Registry) vs `BSI-GRUNDSCHUTZ`
  vs `-KERN`/`-STANDARD` (Varianten-Loader, Hyphen)
- NIST: `NIST-CSF` (Registry+UI) vs `NIST-CSF-2.0` (FullCatalogue-Orphan)
- BSI-C5: `BSI-C5` vs `BSI-C5-2025` vs `BSI-C5-2026`
- KRITIS: `KRITIS` vs `KRITIS-DE` vs `KRITIS-HEALTH`
- SOC2: `SOC2` vs `SOC2-TYPE-II` (Fixtures)
- `ENISA-EUCS` in Fixtures referenziert, aber KEIN Loader.

### C. ISO 27001 ohne Management-Klauseln 4-10
UI lädt nur Annex-A-Controls (93). `LoadIso27001ClausesCommand` (Klauseln 4-10,
ISMS-Kern) ist nicht im `match` → ein über UI installiertes ISO 27001 hat **keine
Klauseln 4-10**. Audit-relevante Lücke.

### D. pre-exist-Abhängigkeit (5 Frameworks)
ISO42001 / ISO27017 / ISO27018 / EU-CRA / PCI-DSS-4.0.1: Loader brechen mit
`Command::FAILURE` ab wenn die Framework-Row nicht per Migration existiert.
Funktioniert nur weil Migrationen vorher laufen. Kein Guard/Fallback im Service.

### E. Idempotenz-Asymmetrie / Re-Run-Blocker
- `LoadGdprRequirementsCommand:53-66` — early-return wenn Framework schon
  Requirements hat → kann partielle Loads NICHT reparieren (DB-Wipe nötig).
- `LoadIso27701Requirements`, `LoadIso27701v2025Requirements` — Blind-Insert
  (kein findOneBy) → Duplikate bei Re-Run.
- `*Full`-Loader erwarten pre-exist, erstellen Framework nicht → harter Fail
  wenn vor dem zugehörigen `*Requirements` gelaufen.

### F. Tote/deprecated Loader ohne Removal-Plan
5 BSI-Grundschutz-Legacy-Loader @deprecated seit 3.5.0, weiter aktiv als
„Compat-Layer", kein 4.0-Removal-Timeline. Plus `LoadAnnexAControlsCommand`
(falscher `isms:`-Prefix, schreibt Control statt ComplianceRequirement, kein
Idempotenz-Check) — wahrscheinlich tot.

---

## 5. DORA — direkter Bezug zur Ausgangsfrage

Ursprüngliche Frage war RTS/ITS-Vollständigkeit. Verdrahtungs-Realität:
- UI/Sample-Data installieren **`LoadDoraRequirements`** (93 × `DORA-N.M`).
- `SeedDoraIso27001MappingsCommand` mappt genau diese `DORA-N.M`-IDs → konsistent
  mit dem was die UI lädt, ABER deckt nur die thematische Ebene ab.
- **`LoadDoraRtsItsFull` (der granulare RTS/ITS-Katalog) ist nirgends referenziert**
  (`grep` außerhalb eigener Datei + Tests = leer) → wird nur installiert wenn
  jemand manuell `php bin/console app:load-dora-rts-its-full` ausführt.
- Selbst dann: die RTS-*/ITS-*-Rows hätten **null vorgefertigte Mappings** (Seed
  kennt nur `DORA-N.M`).

→ „Sind alle RTS/ITS-Bedingungen mappbar verfügbar?" Im Normalbetrieb: **nein,
gar nicht geladen.** Manuell geladen: vorhanden + technisch mappbar, aber
ungemappt + mit den in der DORA-Vollständigkeits-Analyse genannten Lücken
(CDR 2024/1502, 2024/1505 fehlen; Incident-Reporting falsche Nummern; etc.).

---

## 6. Mapping-Verfügbarkeit (gilt für ALLE Frameworks)

`ComplianceMappingAdminController` zieht `findBy(['framework' => X])` — **keine
Filter** auf requirementType/category/priority/lifecycle, keine Pagination-Cap.
`ComplianceMapping`-Entity hat keine Typ-Beschränkung. → **Jede geladene Row ist
mappbar.** Die Frage ist nie „mappbar?", sondern „überhaupt geladen?" (siehe
Orphan-Spalte) und „vorgemappt?" (nur die `Seed<X>Iso27001Mappings`-Commands).

---

## 7. Empfohlene Fixes (priorisiert)

**P1 — UI auf die vollen Kataloge umstellen (match-Statement):**
- `DORA` → Entscheidung: voller Art.N (`LoadDoraFull`) ODER granularer RTS/ITS.
  Wenn RTS/ITS gewünscht: konsolidierten DORA-Loader bauen (Art.N + RTS/ITS) +
  Seed-Mappings auf die neuen IDs erweitern.
- `BSI-C5` → `LoadC52020FullCatalogue`; `BSI-C5-2026` → `LoadC52026FullCatalogue`.
- `GDPR` → `LoadGdprFull` (99 Artikel).
- `ISO27001` → Klauseln 4-10 zusätzlich laden (Annex A + Clauses).

**P2 — Code-Kollisionen vereinheitlichen:** kanonische Code-Strings festlegen
(ein SoT-Enum/Konstanten), Fixtures + Baselines + Migrations darauf ziehen.
Besonders ISO-22301, BSI_GRUNDSCHUTZ, NIST-CSF, BSI-C5.

**P3 — Idempotenz:** GDPR early-return entfernen; ISO27701-Loader auf
findOneBy-before-insert; `*Full`-Loader: Framework anlegen statt fehlschlagen
(oder Service legt Row vor dem Run an).

**P4 — Aufräumen:** deprecated BSI-Legacy + `LoadAnnexAControls` mit 4.0-Removal
markieren; `LoadNistCsf2FullCatalogue` entweder einhängen oder löschen;
`ENISA-EUCS`-Referenz klären.

**P5 — Guard:** Loader-Registry-Test der prüft: jeder Registry-Code hat eine
match-Arm UND einen lauffähigen Loader, jeder ID-Schema-Wechsel hat Migration,
keine doppelten Codes. Plus Collision-Test (keine zwei Loader schreiben gleichen
Code mit verschiedenen Schemata).

---

## 8. Schlüssel-Dateien

- `src/Service/ComplianceFrameworkLoaderService.php` — Registry + match (SoT)
- `src/Controller/AdminComplianceController.php:45,65` — UI-Route + Load-Action
- `src/Controller/DeploymentWizardController.php:1382` — Wizard-Auswahl (lädt nicht)
- `migrations/Version20260506213310.php:39-60`, `Version20260531120000` — Framework-Row pre-create
- `src/Controller/ComplianceMappingAdminController.php` — Mapping-Picker (filterlos)
- `src/Command/Seed*Iso27001MappingsCommand.php` — vorgefertigte Cross-Mappings
- `fixtures/baselines/*.yaml` — Industry-Baselines (referenzieren Codes, laden nicht)
</content>
</invoke>
