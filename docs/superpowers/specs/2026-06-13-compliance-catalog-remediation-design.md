# Compliance-Katalog-Sanierung — Design-Spec

**Datum:** 2026-06-13
**Status:** Approved (brainstorming) → bereit für writing-plans
**Begleitdokumente:**
- `docs/COMPLIANCE_CATALOG_ARCHITECTURE.md` — vollständiges Architektur-Bild (Datenmodell, 4 Phasen, Chaos-Register C1–C12)
- `docs/COMPLIANCE_CATALOG_WIRING_AUDIT.md` — Loader-Detailtabelle (welcher Loader pro Code, ID-Schemata, Orphans)

---

## 1. Problem (Kurzfassung)

Audit aller ~90 Katalog-Commands + Wiring zeigte 12 strukturelle Defekte
(C1–C12, siehe Architektur-Doc §5). Kern:

- UI installiert via `match`-Statement (`ComplianceFrameworkLoaderService`) pro
  Framework genau EINEN Loader — bei DORA/GDPR/BSI-C5/ISO27701/NIS2 den **dünnen
  Legacy**, der volle Katalog liegt als CLI-Orphan brach (C1). ISO27001 ohne
  Klauseln 4-10 (C2). DORA-RTS/ITS gar nicht geladen + Lücken (C3).
- Framework-Code-Kollisionen (`ISO-22301`/`ISO22301`/`ISO_22301`, `BSI_GRUNDSCHUTZ`
  vs `BSI-GRUNDSCHUTZ`, …) → dangling Mappings + tote Baseline-Referenzen (C4).
- ID-Schema-Drift Loader↔Mapping (C5); Mapping-Daten dreifach (8 Seeds + 57 YAML +
  22 CSV) ohne SoT/Dedup (C6); Idempotenz-Bugs (C7); Mapping-Verwaisung bei Delete
  (C8); Korrektur-Pfade zersplittert (C9); tote Loader (C10); Multi-Tenant-Falle
  (C11); kein Konsistenz-Gate (C12).

## 2. Getroffene Entscheidungen

| Frage | Entscheidung |
|---|---|
| Ambition | **Phasiert** — Phase 1 Korrektheit + Gate, Phase 2 deklarativer SoT |
| DORA-Tiefe (Ziel) | **Art.N + RTS/ITS granular** (RTS/ITS-Lücken vorher fixen, neue Mappings) |
| Mapping-SoT | **Konsolidieren → YAML-Library** als alleinige Quelle; Seeds + CSV deprecaten |
| Tenant-Modell | **Global lassen**, nur UX fixen (Unique-Violation → „bereits installiert") |

## 3. Nicht-Ziele (YAGNI / Don't-touch)

- Kein per-Tenant-Katalog-Scope (Phase-2-Option, hier bewusst raus).
- MRIS-v1.5 (13 MHC-Reqs) ist **by-design** vollständig — nicht als „unvollständig" anfassen.
- Kein Symfony-Major-Bump (LTS-Pin). Keine VAIT/BAIT-Wizards (DORA löst ab).
- Kein unrelated Refactoring außerhalb der Katalog-Schicht.

---

## 4. Verbindliche Projekt-Constraints (geprüft, in Design eingebaut)

Diese Regeln (CLAUDE.md + Team-Feedback) sind Teil der Akzeptanzkriterien:

- **Keine Konkurrenz-Produkte**: Bestand verletzt (Vanta/Drata) → WS-1.7.
- **TISAX-Copyright**: voller VDA-ISA-Text nicht shippable → SoT-Format text-optional, TISAX-Texte nur tenant-lokal `var/` (WS-2.1).
- **Migration-Konsolidierung**: gleiches Muster = EINE Migration (WS-1.2); frameworkspezifische Re-Keys im Final-Task bündeln (WS-1.1).
- **DDL-Migrationen**: `isTransactional()=false`; plain SQL, KEIN PREPARE/EXECUTE (CLAUDE.md Pitfall 6).
- **Bulk-Daten-Move auditieren**: Re-Key/Merge nicht via raw `executeStatement` ohne Audit-Spur (Security-Checklist `logBulk`).
- **Async-Admin-Jobs**: Katalog-Loads >30s über `AsyncJobDispatcher` (WS-2.4).
- **Schema-driven-Import wiederverwenden**: vorhandenes `ImportSchemaProvider`-Muster, nicht neu erfinden (WS-2.4).
- **UI-Konventionen**: Term „Organisation" (nicht Tenant/Mandant); neue Routes `/{_locale}/…`; keine nativen Dialoge (`window.faConfirm`/fa-modal).
- **Prozess**: lokale CI-Gates vor Push; nur betroffene Tests je Task; per-Framework-PRs squash-merge on green; Release via release-please/Mo-Cycle.

---

## 5. Architektur des Zielzustands (Phase-2-Endbild)

```
config/catalogs/<CODE>/
  framework.yaml      ← Metadaten (name, version, successor, required_modules,
                         requirementId-Schema-Vertrag, text_shippable: bool)
  requirements.yaml   ← Requirements (id, type, parent, category, priority,
                         title?, description?)   [text optional → TISAX-Constraint]

FrameworkCode  (Konstanten/Enum, EINZIGE Quelle gültiger Codes)

CatalogLoader (generisch)   ← liest config/catalogs/, ersetzt ~90 Load-Commands
ComplianceFrameworkLoaderService.getAvailableFrameworks()  ← aus SoT generiert

fixtures/library/mappings/*.yaml  (SoT für Mappings; gegen Katalog-Schlüsselraum validiert)
MappingLibraryLoader + MappingValidatorService  ← dangling = HARTES FAIL (kein Skip)

check_compliance_catalog.py (CI-Gate)  ← hält Registry↔Loader↔Schema↔Mapping konsistent
```

Datenmodell unverändert (Architektur-Doc §1): `ComplianceFramework` (global) →
`ComplianceRequirement` (global, `uploadTenant` für custom) → `ComplianceMapping`;
tenant-scoped: `ComplianceRequirementFulfillment`, `FulfillmentInheritanceLog`.

---

## 6. Workstreams

### Phase 0 — Inventar & Gate (Voraussetzung)

- **WS-0.1 Konsistenz-Gate** `scripts/quality/check_compliance_catalog.py` (CI):
  prüft (1) jeder Registry-Code hat genau 1 lauffähigen match-Loader; (2) keine
  doppelten/kollidierenden Codes; (3) jedes Mapping zeigt auf requirementIds die
  der **verdrahtete** Loader real erzeugt (dangling-Report); (4) kein Loader-Paar
  schreibt denselben Code mit verschiedenem ID-Schema. Baseline-Muster wie
  bestehende `check_*.py` (bestehende Verstöße einfrieren, dann runterarbeiten).
- **WS-0.2 Dangling-Inventar**: Einmal-Report — welche der 57 YAML / 22 CSV / 8
  Seeds ins Leere zeigen; welche Paare doppelt/widersprüchlich über die 3 Quellen.

**Exit:** Gate grün-mit-Baseline; vollständige Ist-Liste. Jeder folgende Fix senkt Baseline.

### Phase 1 — Korrektheit (Loader-Bestand bleibt)

Reihenfolge: WS-1.2 (Codes) **vor** WS-1.1 (Loader), sonst Doppelarbeit.

- **WS-1.2 Code-Kollisionen mergen** (C4): kanonisches `FrameworkCode`-Set;
  EINE konsolidierte Merge-Migration (Muster `Version20260507212829` existiert) für
  ISO-22301-Trio, BSI_GRUNDSCHUTZ-Varianten, NIST-CSF, SOC2, KRITIS; `ENISA-EUCS`
  klären (Loader bauen ODER Referenz entfernen). Fixtures/Baselines auf kanonische
  Codes ziehen.
- **WS-1.1 Match auf volle Kataloge** (C1/C2): BSI-C5→FullCatalogue(121),
  BSI-C5-2026→FullCatalogue(168), GDPR→Full(99), NIS2→Full, ISO27701→Full,
  ISO27001→Annex A **+ Klauseln 4-10**, DORA→Full Art.N (Zwischenstand). Pro
  Umstellung: Re-Key-Migration alt→neu **inkl. Fulfillment-Mitzug** (siehe WS-1.4
  Risiko) + Seed-Mappings nachziehen.
- **WS-1.3 Referenz-Integrität** (C8): `ComplianceMapping`-FK auf framework/
  requirement mit definierter onDelete-Strategie; Delete-UI warnt + räumt Mappings
  (faConfirm); `ensure-requirements` wird Gate-Stufe statt Reparatur-Workaround.
- **WS-1.4 DORA RTS/ITS** (C3): fehlende Instrumente ergänzen (CDR 2024/1502
  CTPP-Designation, CDR 2024/1505 Oversight-Fees); falsche OJ-Nummern fixen
  (Incident-Reporting → CDR 2025/301 + CIR 2025/302); TLPT → CDR 2025/1190;
  Oversight aufsplitten (CDR 2025/295 Bedingungen + CDR 2025/420 JET); ICT-RMF →
  CDR 2024/1774; Lifecycle-Flags entfernen wo in Kraft. Granularen RTS/ITS-Katalog
  **additiv in match** + neue RTS/ITS↔ISO-Mappings.
- **WS-1.5 Idempotenz** (C7): GDPR early-return raus; ISO27701 findOneBy-before-
  insert; Full-Loader legen Framework an statt abzubrechen (oder Service pre-created Row).
- **WS-1.6 Tenant-UX** (C11): Unique-Violation → „bereits installiert"-Meldung
  (Term „Organisation"), kein Fehler.
- **WS-1.7 Konkurrenz-Namen scrubben** (Constraint): `SeedSoc2Iso27001MappingsCommand.php:27`
  (Kommentar) + `ComplianceMappingSeedController.php:68` (UI `rationale_source`) +
  grep-Sweep über alle Seed/Mapping/Lib-Files → neutralisieren auf
  Standard-Quellen (AICPA/ISO/ENISA). Gate-Regel ergänzen die das künftig blockt.

### Phase 2 — Deklarativer SoT (framework-für-framework, durch Gate gesichert)

- **WS-2.1 Katalog-SoT-Format**: `config/catalogs/<code>/` (s. §5); generischer
  `CatalogLoader` ersetzt schrittweise ~90 Load-Commands. Pro migriertem Framework:
  alten Loader löschen, Gate beweist Äquivalenz. **TISAX:** `text_shippable:false`
  → nur Nummern + Mappings im Repo, Texte tenant-lokal `var/`.
- **WS-2.2 Mapping-SoT konsolidieren** (C5/C6): Inventar/Dedup über Seeds+YAML+CSV
  (aus WS-0.2) → Zusammenführen auf **YAML-Library**; Seeds + CSV deprecaten; ein
  Import-Pfad; Validierung gegen Katalog-Schlüsselraum (dangling = hartes Fail).
- **WS-2.3 Aufräumen** (C10): tote BSI-Legacy×5, `LoadAnnexAControls`,
  NIST-CSF-2.0-Orphan, EU-AI-ACT-alt entfernen (nach SoT redundant).
- **WS-2.4 Pflege-Oberfläche** (C9): EINE Admin-Sicht (laden/edit/versionieren/
  re-key/löschen) + Version-Migrate/ensure als UI-Aktion. Nutzt `ImportSchemaProvider`-
  Muster + `AsyncJobDispatcher` (>30s); Routes `/{_locale}/…`; faConfirm; „Organisation".

---

## 7. Risiken & Mitigation

| Risiko | Schwere | Mitigation |
|---|---|---|
| **Fulfillment-Datenverlust** beim Re-Key — tenant-`Fulfillment`/`InheritanceLog` hängen an `(framework, requirementId)` | **KRITISCH** | Re-Key-Migration zieht Fulfillment + InheritanceLog mit; dry-run-Pflicht; Audit-Spur; Backup-Verweis vor Lauf |
| SAVEPOINT-Fehler bei DDL | hoch | `isTransactional()=false` je Migration; plain SQL, kein PREPARE/EXECUTE |
| Gate bricht bestehende Baselines | mittel | Baseline beim Einführen einfrieren, nicht 0-fordern |
| Mapping-Dedup verliert kuratierte Metadaten | mittel | YAML-Library (reichste Metadaten) als Merge-Ziel; Inventar zuerst (WS-0.2) |
| Scope-Creep Phase 2 | mittel | Pro Framework abgeschlossen mergebar; kein Big-Bang |
| Konkurrenz-Name übersehen | niedrig | grep-Sweep + Gate-Regel als Dauergate |

---

## 8. Akzeptanzkriterien

1. `check_compliance_catalog.py` grün (Baseline = 0 am Ende von Phase 1 für C1/C2/C4/C5/C8; C3 nach WS-1.4).
2. UI lädt für alle Frameworks den vollständigen Katalog; ISO27001 inkl. Klauseln 4-10; DORA inkl. RTS/ITS.
3. Keine doppelten Framework-Codes; alle Fixtures/Baselines referenzieren kanonische Codes.
4. Kein Mapping (Seed/YAML/CSV) zeigt ins Leere.
5. Keine Konkurrenz-Produktnamen in Code/UI/Docs/Mappings.
6. Re-Key-Migrationen erhalten tenant-Fulfillment nachweislich (Test).
7. Phase 2: ≥1 Framework vollständig auf `config/catalogs/`-SoT migriert als Referenz-Muster; Mapping-SoT = YAML-Library, Seeds/CSV deprecated.

---

## 9. Offene Verifikationspunkte (in Phase 0 zu klären)

- Exakte Seed-Mapping-Counts + Überschneidung der 3 Mapping-Quellen (Dedup-Umfang).
- ISO27701 Seed-Mismatch (`27701-GDPR-*`/`27701-5.*`) — Anzahl real skippender Mappings.
- Ob `app:mappings:import-csv` im Deploy/CI automatisch läuft (dann faktisch SoT, nicht Seeds).
- Vollständiges dangling-Inventar (welche YAML/CSV-Paare auf nicht-erzeugte Schemata zeigen).
</content>
