# Compliance-Katalog-Sanierung — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Compliance-Kataloge von „Chaos" (12 Defekte C1–C12) in einen konsistenten, gegateten Zustand bringen — phasiert: erst Korrektheit + CI-Gate, dann deklarativer Single-Source-of-Truth.

**Architecture:** Phase 0 baut ein CI-Gate + Inventar (Sicherheitsnetz). Phase 1 stellt die UI-Verdrahtung auf die vollständigen Kataloge um, merged Code-Kollisionen, fixt DORA-RTS/ITS und scrubbt Constraint-Verstöße — Loader-Bestand bleibt. Phase 2 migriert framework-für-framework auf `config/catalogs/<code>/`-SoT + YAML-Mapping-SoT, abgesichert durch das Gate.

**Tech Stack:** PHP 8.4 / Symfony 7.4, Doctrine ORM 3, Python 3 (Quality-Gates analog `scripts/quality/check_*.py`), Doctrine-Migrations.

**Spec:** `docs/superpowers/specs/2026-06-13-compliance-catalog-remediation-design.md`
**Audit-Docs:** `docs/COMPLIANCE_CATALOG_ARCHITECTURE.md`, `docs/COMPLIANCE_CATALOG_WIRING_AUDIT.md`

**Ausführungsmodell:** Massenarbeit (Phase 1 WS-1.1 per-Framework-Swaps, WS-1.7 Scrub, Phase 2) an **Sonnet-Subagenten**; Opus baut das Gate + Re-Key-Migrationen (Fulfillment-Erhalt) selbst und reviewt jeden Workstream (`verification-before-completion` vor Merge).

---

## File Structure

- `scripts/quality/check_compliance_catalog.py` — **NEU** statischer Gate (Registry↔match-Parität, Code-Kollision, Konkurrenz-Namen). Eine Verantwortung: statische Konsistenz.
- `scripts/quality/baselines/compliance_catalog.txt` — **NEU** Baseline.
- `src/Command/AuditCatalogMappingsCommand.php` — **NEU** DB-basiertes dangling-Mapping-Inventar (lädt alle Frameworks in Test-DB, prüft Seed/YAML/CSV-IDs gegen real erzeugte requirementIds).
- `src/Service/Catalog/FrameworkCode.php` — **NEU (Phase 1)** kanonische Code-Konstanten (SoT für gültige Codes).
- `src/Service/ComplianceFrameworkLoaderService.php` — **MODIFY** match auf volle Kataloge (Phase 1).
- `migrations/Version2026*` — **NEU** Merge-Migrationen (Codes) + Re-Key-Migrationen (Loader-Swap, Fulfillment-Mitzug).
- `.github/workflows/ci.yml` — **MODIFY** Gate einhängen.
- `config/catalogs/<code>/{framework,requirements}.yaml` — **NEU (Phase 2)** deklarativer SoT.

---

## PHASE 0 — Inventar & Gate (Opus, Voraussetzung)

### Task 0.1: Statischer Konsistenz-Gate (Parität + Kollision + Konkurrenz)

**Files:**
- Create: `scripts/quality/check_compliance_catalog.py`
- Create: `scripts/quality/baselines/compliance_catalog.txt`

- [ ] **Step 1: Gate-Script schreiben** (analog `check_ddl_transactional.py`-Struktur: `--baseline/--write-baseline/--quiet`, `_rel()`, `load_baseline()`). Drei Checks:
  - **PARITY**: aus `src/Service/ComplianceFrameworkLoaderService.php` die Codes in `getAvailableFrameworks()` (`'code' => '...'`) und die match-Arme in `loadFramework()` (`'...' => $this->`) extrahieren; FAIL bei Code ohne match-Arm oder match-Arm ohne Registry-Eintrag. Violation-ID: `parity:<CODE>`.
  - **COLLISION**: repo-weit `'code' => '...'`, `setCode('...')`, `findOneBy(['code' => '...'])` sammeln; nach Normalisierung (uppercase, `-`/`_`/space entfernt) gruppieren; FAIL bei Gruppe mit >1 distinktem Rohwert. Violation-ID: `collision:<normalized>:<rawA|rawB>`.
  - **COMPETITOR**: Regex `vanta|drata|secureframe|verinice|hiscout|secfix|tenfold` (case-insensitive) in `src/Command/*Mapping*.php`, `src/Controller/ComplianceMapping*.php`, `fixtures/library/mappings/`, `fixtures/mappings/`. Violation-ID: `competitor:<relpath>:<line>`.
- [ ] **Step 2: Trockenlauf** — `python3 scripts/quality/check_compliance_catalog.py` → erwartet Violations (Parity-Lücken? nein laut Audit; Kollisionen ISO-22301/BSI_GRUNDSCHUTZ; 2 Competitor-Treffer).
- [ ] **Step 3: Baseline schreiben** — `python3 scripts/quality/check_compliance_catalog.py --write-baseline scripts/quality/baselines/compliance_catalog.txt`.
- [ ] **Step 4: Verify grün-mit-Baseline** — `python3 scripts/quality/check_compliance_catalog.py --baseline scripts/quality/baselines/compliance_catalog.txt --quiet` → exit 0.
- [ ] **Step 5: Commit** — `git add -f scripts/quality/check_compliance_catalog.py scripts/quality/baselines/compliance_catalog.txt && git commit -m "feat(quality): compliance-catalog consistency gate (parity/collision/competitor)"`.

### Task 0.2: Gate in CI einhängen

**Files:** Modify: `.github/workflows/ci.yml`

- [ ] **Step 1:** Im Quality-Block (nach `check_ddl_transactional`) Zeile ergänzen: `run: python3 scripts/quality/check_compliance_catalog.py --baseline scripts/quality/baselines/compliance_catalog.txt --quiet`.
- [ ] **Step 2: Commit** — `git commit -am "ci: wire compliance-catalog gate"`.

### Task 0.3: Dangling-Mapping-Inventar (DB-basiert)

**Files:** Create: `src/Command/AuditCatalogMappingsCommand.php` (`app:audit-catalog-mappings`)

- [ ] **Step 1:** Command schreiben: lädt in Test-Env alle Frameworks via `ComplianceFrameworkLoaderService::loadFramework(code)` für jeden Registry-Code; sammelt real erzeugte `(framework.code, requirementId)`-Menge; liest dann alle Seed-Mapping-IDs (aus `Seed*MappingsCommand` — via Reflection/Quelle), alle 57 `fixtures/library/mappings/*.yaml` und 22 `fixtures/mappings/public/*.csv`; reportet je Quelle: wieviele source/target-IDs NICHT in der erzeugten Menge (dangling), gruppiert nach Framework-Paar.
- [ ] **Step 2: Lauf** gegen Test-DB → Report nach `var/audit/catalog_mappings_inventory.json` + Konsolen-Summary.
- [ ] **Step 3:** Report-Befunde in Spec §9 als geklärt eintragen (Dedup-Umfang, ISO27701-Skip-Zahl, dangling-Liste).
- [ ] **Step 4: Commit** — `git commit`.

**Exit Phase 0:** Gate grün-mit-Baseline in CI; dangling-Inventar liegt vor. Jeder folgende Fix senkt die Baseline.

---

## PHASE 1 — Korrektheit (Sonnet-Bulk, Opus-Gates)

> Reihenfolge zwingend: 1.2 (Codes) → 1.1 (Loader) → 1.4 (DORA) parallel zu 1.3/1.5/1.6/1.7.
> Jeder Framework-Swap: TDD (Test erzeugt Framework via Service, assert erwartete requirementId-Menge + Count), Re-Key-Migration mit Fulfillment-Mitzug, Baseline senken.

### Task 1.2: Kanonisches Code-Register + Merge-Migration (C4) — Opus

**Files:** Create `src/Service/Catalog/FrameworkCode.php`; Create EINE Migration `migrations/Version<ts>_merge_framework_codes.php`; Modify Fixtures/Baselines die Alt-Codes nutzen.

- [ ] **Step 1:** `FrameworkCode`-Klasse mit `public const` je kanonischem Code + `CANONICAL` Liste + `ALIASES` map (`'ISO22301'=>'ISO-22301'`, `'ISO_22301'=>'ISO-22301'`, `'BSI-GRUNDSCHUTZ'=>'BSI_GRUNDSCHUTZ'`, `BSI-GRUNDSCHUTZ-KERN/-STANDARD`, `'NIST-CSF-2.0'`-Klärung, `'SOC2-TYPE-II'=>'SOC2'`, `'KRITIS-DE'=>'KRITIS'`). `ENISA-EUCS`: entscheiden — Loader bauen oder Referenz killen (Default: Referenz entfernen, da kein Loader).
- [ ] **Step 2:** Test: `FrameworkCodeTest` assert keine zwei kanonischen Codes normalisieren gleich; jeder Alias zeigt auf kanonischen.
- [ ] **Step 3:** Merge-Migration (`isTransactional()=false`, plain SQL): pro Alias `UPDATE compliance_requirement SET framework_id = <canonical> WHERE framework_id = <alias>` + `UPDATE compliance_requirement_fulfillment`/`fulfillment_inheritance_log` falls sie framework referenzieren + `DELETE` Alias-Framework. Audit-Note (kein silent raw — Migration-Kommentar + ggf. logBulk-Äquivalent).
- [ ] **Step 4:** Fixtures/Baselines auf kanonische Codes ziehen (grep `ISO22301`/`ISO_22301`/`BSI-GRUNDSCHUTZ`).
- [ ] **Step 5:** Gate-Baseline `collision:*` Einträge entfernen → Gate beweist 0 Kollisionen. Commit.

### Task 1.1: Match auf volle Kataloge (C1/C2) — Sonnet je Framework, Opus reviewt

Pro Framework (BSI-C5, BSI-C5-2026, GDPR, NIS2, ISO27701, ISO27001+Clauses) ein Sub-Task:

- [ ] **Step 1:** Test: lade Framework via Service in Test-DB, assert requirementId-Schema + Mindest-Count des vollen Katalogs (z.B. BSI-C5 ≥121, GDPR ≥99, ISO27001 inkl. `ISO27001-4.x`..`10.x`).
- [ ] **Step 2:** match-Arm in `ComplianceFrameworkLoaderService` auf `*Full`/`*Catalogue` umstellen; ISO27001 zusätzlich Clauses laden (Loader-Komposition oder kombinierter Loader).
- [ ] **Step 3:** Re-Key-Migration (`isTransactional()=false`): alte dünne Rows → neues Schema; **tenant-Fulfillment + InheritanceLog auf neue requirementIds mappen** (Mapping-Tabelle alt→neu im Migration-Code; nicht gematchte = Report, nicht löschen). Dry-run-Test.
- [ ] **Step 4:** zugehörige Seed-Mappings auf neues Schema nachziehen.
- [ ] **Step 5:** Gate-Baseline senken; betroffene Tests grün; Commit; PR per Framework.

### Task 1.3: Referenz-Integrität (C8) — Opus

- [ ] FK `ComplianceMapping`→framework/requirement onDelete definieren (Migration); Delete-UI (`ComplianceFrameworkController`, `AdminComplianceController`) warnt + räumt Mappings (faConfirm); `ensure-requirements` als Gate-Stufe statt Reparatur. Tests + Commit.

### Task 1.4: DORA RTS/ITS (C3) — Opus/Sonnet

- [ ] In `LoadDoraRtsItsFullCommand`: fehlende Blöcke CDR 2024/1502 + 2024/1505; Nummern-Fix Incident-Reporting → CDR 2025/301 + CIR 2025/302; TLPT → CDR 2025/1190; Oversight split 2025/295 + JET 2025/420; ICT-RMF → CDR 2024/1774; Lifecycle-Flags. DORA-match additiv: Art.N (`LoadDoraFull`) + RTS/ITS. Neue RTS/ITS↔ISO-Mappings. Tests + Commit.

### Task 1.5: Idempotenz (C7) — Sonnet

- [ ] GDPR early-return entfernen; ISO27701 findOneBy-before-insert; `*Full`-Loader legen Framework an statt FAILURE. Re-Run-Tests + Commit.

### Task 1.6: Tenant-UX (C11) — Sonnet

- [ ] `loadFramework` UniqueConstraintViolation → „bereits installiert"-Result (Term „Organisation"). Test + Commit.

### Task 1.7: Konkurrenz-Namen scrubben (Constraint) — Sonnet

- [ ] `SeedSoc2Iso27001MappingsCommand.php:27` Kommentar + `ComplianceMappingSeedController.php:68` `rationale_source` + grep-Sweep neutralisieren (AICPA/ISO/ENISA). Gate-Baseline `competitor:*` leeren → Gate beweist 0. Commit.

**Exit Phase 1:** Gate-Baseline für C1/C2/C4/C5/C8/C7-Klassen = 0; C3 nach 1.4. UI lädt überall volle Kataloge. Akzeptanzkriterien §8.1–8.6.

---

## PHASE 2 — Deklarativer SoT (Sonnet-Bulk, Opus-Gates) — nach Phase-0-Inventar verfeinern

> Tasks hier auf Cluster-Ebene; vollständige bite-sized-Aufschlüsselung erfolgt nach Phase-0-Inventar (dangling-Liste + Dedup-Umfang bestimmen Reihenfolge).

### Task 2.1: Katalog-SoT-Format + generischer Reader
- [ ] `config/catalogs/<code>/{framework,requirements}.yaml`-Schema (inkl. `text_shippable:bool` → TISAX nur Nummern, Texte tenant-lokal `var/`). `CatalogLoader`-Service liest SoT. Pilot: 1 einfaches Framework (z.B. BDSG) vollständig migrieren, alten Loader löschen, Gate beweist Äquivalenz. Dann je Framework via Sonnet.

### Task 2.2: Mapping-SoT konsolidieren (C5/C6)
- [ ] Aus Phase-0-Inventar Dedup über Seeds+YAML+CSV → Merge auf YAML-Library; Seeds + CSV deprecaten; ein Import-Pfad; `MappingValidatorService`: dangling = hartes FAIL (kein Skip). Gate-Regel ergänzen.

### Task 2.3: Aufräumen (C10)
- [ ] Tote Loader entfernen: BSI-Legacy×5, `LoadAnnexAControls`, NIST-CSF-2.0-Orphan, EU-AI-ACT-alt (nach SoT redundant). Gate beweist keine Referenz verwaist.

### Task 2.4: Pflege-Oberfläche (C9)
- [ ] EINE Admin-Sicht: laden/edit/versionieren/re-key/löschen + Version-Migrate/ensure als UI-Aktion. Reuse `ImportSchemaProvider`-Muster + `AsyncJobDispatcher` (>30s); Routes `/{_locale}/…`; faConfirm; „Organisation".

**Exit Phase 2:** ≥1 Framework auf SoT als Referenz; Mapping-SoT = YAML-Library; Seeds/CSV deprecated; Akzeptanzkriterium §8.7.

---

## Self-Review (durchgeführt)

- **Spec-Coverage:** C1→1.1, C2→1.1, C3→1.4, C4→1.2, C5→1.4/2.2, C6→2.2, C7→1.5, C8→1.3, C9→2.4, C10→2.3, C11→1.6, C12→0.1. Constraints: Competitor→1.7+Gate, TISAX→2.1, Fulfillment-Erhalt→1.1/1.2 Migrationen. ✓
- **Platzhalter:** Phase 0/1 konkret; Phase 2 bewusst Cluster-Ebene (hängt an Phase-0-Inventar) — beim Erreichen aufschlüsseln. Kein „TBD" in 0/1.
- **Typ-Konsistenz:** `FrameworkCode` (1.2) wird in 1.1-Tests referenziert; `check_compliance_catalog.py` Violation-IDs (`parity:`/`collision:`/`competitor:`) konsistent zwischen 0.1, 1.2, 1.7.
</content>
