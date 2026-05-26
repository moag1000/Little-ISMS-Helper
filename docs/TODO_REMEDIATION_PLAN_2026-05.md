# TODO-Remediation-Plan (Mai 2026)

Stand: **42 offene TODOs** in `src/` + `templates/` + `config/` + `scripts/`
(Markdown-Dokumentation NICHT enthalten). Aufgenommen via `grep -rE
"\b(TODO|FIXME|HACK)\b"`. Fokus: gesamten Backlog ordnen, bewerten und in
6 Buckets über 3 Sprints abarbeiten.

## Übersicht nach Tag

| Tag | Count | Charakter |
|---|---|---|
| `TODO:` (untagged) | 20 | Gemischt — von 5-Minuten-Refactors bis Sprint-Features |
| `TODO(s5-json-objects)` | 5 | Sprint-5-Roadmap — JsonStructuredType → CollectionType |
| `TODO(aurora-migration)` | 4 | Bi-Icons → Aurora-Icon-Migration |
| `TODO(aurora-v4-icons)` | 3 | Dynamic-Icon-Switches (Twig-Limit) |
| `TODO(aurora-v4-h)` | 1 | dev/design-system Wiring |
| `FIXME` / `HACK` | 0 | – |

## Buckets

### Bucket 1 — Trivial Cleanup (1 Tag, ~1 PR)

| # | Ort | Aktion |
|---|---|---|
| 1.1 | `templates/training/new.html.twig:8` | Re-add `location` zu schedule-section (SOLUTIONS_P0 P0-11) — Feld schon im Entity, Form-Field re-aktivieren |
| 1.2 | `templates/policy_wizard/step/_review_generate.html.twig:76` | Alert-link-pattern refactor zu `_fa_alert` macro |
| 1.3 | `templates/processing_activity/index.html.twig:18` | Export-Actions `data-turbo="false"` via custom-block oder `fa-cyber-btn` mit `attr.data-turbo` |
| 1.4 | `templates/soa/index.html.twig:42` | Gleicher Fix wie 1.3 (export/preview Turbo-handling) |
| 1.5 | `templates/audit/workbook/index.html.twig:178` | F40.4 — `alvaBus.dispatchEvent('celebrate')` an Save-Button binden |
| 1.6 | `src/Service/PolicyWizard/DocumentGenerator.php:302` | Alva-Hint-Rule für "manual-split needed" Tag aufgreifen — neue `Rule/PolicyWizard/DocumentSplitNeededRule.php` |

**Risk**: Niedrig — alles isoliert, kein DB-Schema, keine Migrations.
**Effort**: 1 Tag.
**Deliverable**: PR `chore(todos): bucket-1-trivial-cleanup`.

---

### Bucket 2 — Aurora-Icon-Migration Sweep (1-2 Tage, ~1 PR)

Bi-Icons (`bi-check-circle`, `bi-exclamation-triangle` etc.) → Aurora `_fa_icon`
macro. Anti-Pattern bei dynamischen Icons in JS-strings + Twig-dicts.

| # | Ort | Aktion |
|---|---|---|
| 2.1 | `templates/home/_management_kpis_widget.html.twig:28` | `kpi_sections` dict → Aurora mask-icons extrahieren |
| 2.2 | `templates/admin/compliance/index.html.twig:169` | JS-string-`bi-check-circle` → Stimulus-controller mit `_fa_icon` slot |
| 2.3 | `templates/profile/mfa/index.html.twig:22` + `show.html.twig:38` | Dynamic MFA-status/token-icon — Aurora mood-aware variants |
| 2.4 | `templates/incident/show.html.twig:104` | `isDataBreach ? exclamation-triangle : gear` → Aurora `_fa_entity_badge` mit type-prop |
| 2.5 | `templates/risk/index.html.twig:173` | Treatment-icon-dict → Aurora `_fa_status_chip` mit treatment-type Variants |
| 2.6 | `templates/compliance/framework_dashboard.html.twig:231` | Dynamic icon im dict — Aurora mask-icon |
| 2.7 | `templates/home/welcome.html.twig:161` | `module.color` dynamic → `fa-cyber-btn` variant API (wenn Aurora die liefert) — sonst defer |
| 2.8 | `templates/dev/design_system.html.twig:1` | Wire dev/design-system zu `docs/design_system/sections/*.html` |

**Risk**: Niedrig-mittel — visuelle Regression möglich, Visual-Smoke-Test fängt das. **Effort**: 1-2 Tage.
**Deliverable**: PR `feat(aurora): icon-migration sweep — close 8 TODO(aurora-*)`.

---

### Bucket 3 — fa-table / fa-matrix-table Migration (2-3 Tage, ~2 PRs)

Raw `<table>` → Aurora-`_fa_table` Macros. Komplexer als Bucket 2 weil
inline-form-widgets + multi-axis headers + JS-template-literals involved.

| # | Ort | Aktion |
|---|---|---|
| 3.1 | `templates/compliance/_heatmap.html.twig:2,43` | Neues `_fa_matrix_table` Macro mit `<caption>`, `colspan`, `<th scope="row">` — Framework × Cluster |
| 3.2 | `templates/import/wizard/map.html.twig:6,68` | Neues `_fa_settings_table` Macro mit inline `form_widget(<select>)` per row via FormView |
| 3.3 | `templates/data_management/backup.html.twig:655` | JS innerHTML template-literal → Stimulus-controller mit `_fa_table` als Template |
| 3.4 | `templates/compliance/compare.html.twig:14` | `fa-action-bar` API: `disabled` state per action + JS-button conditional |

**Risk**: Mittel — neue Macros, breite Coverage-Anforderung.
**Effort**: 2-3 Tage. PR 3a (matrix + settings macros), PR 3b (backup + action-bar enhancements).
**Deliverable**: 2 PRs — neue Macros + Sweep.

---

### Bucket 4 — Entity drop-column Migrations (1 Tag, ~1 PR)

3× "drop column post-v4" — alle Felder seit v3.8 deprecated, post-Migration-Window
abgewartet. Jetzt: confirm-unused + drop.

| # | Ort | Aktion |
|---|---|---|
| 4.1 | `src/Entity/Document.php:92` | Field identifizieren (Read mit context), `grep -rn "FIELD_NAME"` confirm unused, drop column |
| 4.2 | `src/Entity/ComplianceRequirement.php:432` | Same |
| 4.3 | `src/Entity/BusinessProcess.php:150` | Same |

**Pro Feld**:
1. `Read` Entity bei der TODO-Zeile → Feld-Name extrahieren
2. `grep -rn "field_name\|getFieldName\|setFieldName" src/ templates/` — confirm 0 refs
3. Entity-Property + Getter/Setter entfernen
4. `php bin/console doctrine:migrations:diff` → DROP COLUMN-Migration
5. `isTransactional()=false` im Migration-File

**Risk**: Mittel — Schema-Drift möglich wenn Feld noch via DQL referenced. PR-Reviewer muss confirmen.
**Effort**: 1 Tag (3 Felder, je 20min check + migration).
**Deliverable**: PR `refactor(entity): drop 3 deprecated-since-v4 columns`.

---

### Bucket 5 — JSON-Field-Refactor (s5-json-objects) (3-5 Tage, ~5 PRs einzeln)

`JsonStructuredType` (textarea mit JSON-blob) → dedizierte `CollectionType` mit
`EntryType` pro Eintrag. Markt-Standard für strukturierte Sub-Forms.

> **Status update 2026-05-26:** Items 5.3 (BCExercise.successCriteria) and
> 5.5 (Control.frameworkReferences) closed as proper FormTypes (not
> CollectionType) because their backing-data shapes (heterogeneous shape
> variance / variable-key associative map) genuinely require shape-
> normalising DataTransformers behind a custom widget — see closure notes
> per item below.

| # | Form | Json-Feld | Neue EntryType |
|---|---|---|---|
| 5.1 | `ThreatIntelligenceType:251` | indicators-of-compromise (IoC) | `IocEntryType` mit type + value + confidence |
| 5.2 | `UserType:245` | competencies | `CompetencyEntryType` mit framework + level + cert |
| 5.3 | ~~`BCExerciseType:238`~~ | ~~success-criteria~~ | ~~`SuccessCriterionEntryType` mit measurable + actual + met-flag~~ — **CLOSED in PR #723**: `BcExerciseSuccessCriteriaType` wraps the existing Stimulus `_fa_success_criteria.html.twig` builder behind a proper FormType. `SuccessCriteriaShapeTransformer` auto-normalises Shape A (rich list of objects) and Shape B (legacy flat bool map) without requiring a data migration. CollectionType would have been a UX regression (loses prefill + raw-toggle escape hatch). |
| 5.4 | `BCExerciseType:273` | evidence-artifacts | `EvidenceArtifactEntryType` mit type + name + url |
| 5.5 | ~~`ControlType:275`~~ | ~~implementation-map~~ | ~~strukturierter map-editor (key=phase, value=narrative)~~ — **CLOSED in PR #723**: `ControlFrameworkReferencesType` renders one labelled chip-row per known framework slug (13 canonical). Legacy custom slugs surfaced dynamically via PRE_SET_DATA/PRE_SUBMIT so tenant-specific keys round-trip. `FrameworkReferencesTransformer` handles the `array<slug, list<ref>>` ↔ CSV-per-slug shape conversion. Custom form-theme template at `templates/form/control_framework_references.html.twig`. |

**Pro Refactor**:
1. Neuer EntryType in `src/Form/Entry/`
2. CollectionType mit `entry_type` + `allow_add` + `allow_delete`
3. Twig-Render mit `_fa_repeater` Macro (gibt's schon? sonst neu)
4. Migration nicht nötig (JSON-Column bleibt, nur Form-Layer ändert sich)
5. Daten-Migration nur falls neue Schema-Validierung enger als alt — Backfill-Command bei Bedarf
6. PHPUnit `FormType-Functional` Test

**Risk**: Mittel — UX-Verbesserung, aber bestehende Daten müssen kompatibel bleiben.
**Effort**: 3-5 Tage. Eine PR pro Feld parallel-mergebar.
**Deliverable**: 5 PRs `feat(form): structured ${field}-entry-type (S5 P-X)`.

---

### Bucket 6 — DORA RoI XBRL Sprint 9 (5-8 Tage, ~3 PRs)

`src/Service/Authority/DoraRoiXbrlExporter.php` hat 8 TODOs für ESA-Taxonomie
B_01.01.0020/0040, B_02.02.0060-9999, B_03.02 + Tenant.leiCode + Supplier.leiCode.
Aktuelle Implementation = nur Skelett für Sprint 8 prototype.

| # | Aufgabe |
|---|---|
| 6.1 | `Tenant.leiCode` Field — Migration + FormType + Validation (ISO 17442) |
| 6.2 | `Supplier.leiCode` Field — Migration + FormType + Validation |
| 6.3 | ESA Taxonomie B_01.01.0020 (Reporting-Entity-LEI) — wire via `Tenant.leiCode` |
| 6.4 | ESA Taxonomie B_01.01.0040 (Reporting-Currency) — EUR default + Tenant-config |
| 6.5 | ESA Taxonomie B_02.02.0020 (Provider-LEI) — wire via `Supplier.leiCode` |
| 6.6 | ESA Taxonomie B_02.02.0060-9999 (Provider-Details: addresses, registration, certifications) |
| 6.7 | ESA Taxonomie B_03.02 (ICT-Asset-Detail-Table) — Asset.doraRelevant entities aggregieren |
| 6.8 | XBRL-Validierung — Arelle-CLI in CI integrieren (verify against ESA taxonomy schema) |

**Risk**: Hoch — regulatorisch verbindlich, ESA-Taxonomie strikt validiert.
**Effort**: 5-8 Tage. PR 6a (LEI-Felder), PR 6b (B_01/B_02 wiring), PR 6c (B_02.0060+ + B_03.02 + Arelle).
**Deliverable**: DORA Art. 28 ROI-Reporting production-ready.

---

### Bucket 7 — Misc Defer / Document-only (1 Tag, ~1 PR)

Verbleibende TODOs die entweder als Tag aufgenommen oder dokumentiert werden:

| # | Ort | Aktion |
|---|---|---|
| 7.1 | `src/Service/Export/CertificationBundleExporter.php:827` | TODO bleibt als ABSICHTLICHER auditor-gap-marker — re-tag als `TODO(audit-gap)` mit Doku in `docs/CERTIFICATION_BUNDLE.md` |
| 7.2 | `src/Controller/Dashboard/ComplianceManagerDashboardController.php:146` | "gap requirements (TODO)" — entweder implementieren ODER aus Doc-Comment löschen, da Feature schon live |

**Risk**: Niedrig.
**Effort**: 1 Tag.
**Deliverable**: PR `chore(todos): document/retag intentional-defer TODOs`.

---

## Gesamtplan

| Sprint | Buckets | Tage | PRs |
|---|---|---|---|
| **Sprint A** (Quick wins) | 1 + 4 + 7 | ~3 | 3 |
| **Sprint B** (Aurora/UI) | 2 + 3 | ~5 | 3 |
| **Sprint C** (Strukturelle Refactors) | 5 + 6 | ~10 | 8 |
| **Total** | 7 Buckets | ~18 Tage | ~14 PRs |

## Sequenzierung + Abhängigkeiten

```
Sprint A (parallel)         Sprint B               Sprint C
─────────────────           ─────────              ────────────────
Bucket 1 ──┐                Bucket 2 ──┐           Bucket 5 (forms)
Bucket 4 ──┤── A done       Bucket 3 ──┤── B done  Bucket 6 (DORA)
Bucket 7 ──┘                          done         needs LEI from 6a
```

**Kritische Pfade**:
- Bucket 4 (drop-columns) sollte VOR Bucket 5 (Form-refactor) laufen — falls neue FormType die alten Felder wegabstrahieren würde.
- Bucket 6a (LEI-Felder) blockiert 6b/6c.

## CI-Gate

Neuer optionaler Gate `scripts/quality/check_todo_growth.py`:
- Baseline `scripts/quality/baselines/todos.txt` mit aktueller Liste
- CI failed wenn neue TODOs ohne Tag oder Tag fehlt im erlaubten-Set
- Lässt bestehende TODOs durch, verhindert nur Wachstum

## Aufwand-Schätzung

| Bucket | LOC-Touched | Test-Aufwand | Migration nötig |
|---|---|---|---|
| 1 | ~150 | smoke | nein |
| 2 | ~200 | visual + smoke | nein |
| 3 | ~400 (3 neue Macros) | smoke + unit für Macros | nein |
| 4 | ~50 + 3 Migrations | unit | **ja** (3 DROP COLUMN) |
| 5 | ~1500 (5 EntryTypes + Tests) | unit + functional | nein |
| 6 | ~800 (LEI + Taxonomie) | unit + XBRL-Schema-validierung | **ja** (2 ADD COLUMN) |
| 7 | ~30 | docs only | nein |

**Total**: ~3100 LOC + 5 Migrations.

## Empfehlung

Sequenz:
1. **Sofort**: Sprint A (3 Tage, 3 PRs) — niedriges Risiko, viel Aufräumen
2. **Folgewoche**: Sprint B (5 Tage, 3 PRs) — UI-Konsistenz
3. **Sprint 8 / 9 backlog**: Sprint C (10 Tage, 8 PRs) — größte Investition, kritischste Compliance-Features (DORA)
