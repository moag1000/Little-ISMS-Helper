# Sprint 1 — F2 Wave 1 (Bulk-Import) + F40 (Audit-XLSX-Generator)

**Branch:** `feature/sprint-1-bulk-import-xlsx-generator` (oder direkt main mit Atomic-Commits)
**Dauer:** 3-5 Tage Solo-Dev / 2-3 Tage mit Subagent-Driven-Dev parallelisiert
**Demo-Ready am Ende:**
- 200 Assets aus Excel < 5 Min importiert + Audit-Trail mit batch_id
- Delta-Re-Upload zeigt 5 Updates / 0 New / 0 Deletes
- SoA-Export als auditor-ready XLSX

## F2 Wave 1 — Bulk-Import (Asset + Supplier + Control)

### Task F2.1 — Entity-Scaffold (~1.5h)

- [ ] `src/Entity/BulkImportBatch.php` mit tenant_id + alle Fields
- [ ] `src/Entity/BulkImportRow.php` (Audit-Trail-Detail)
- [ ] `src/Repository/BulkImportBatchRepository.php`
- [ ] `src/Repository/BulkImportRowRepository.php`
- [ ] Migration `Version{ts}_f2_bulk_import_tables.php` mit `isTransactional()=false`
- [ ] `Document.documentType` Enum erweitert um `import_evidence`
- [ ] Tests: `tests/Entity/BulkImportBatchTest.php` (Smoke)

### Task F2.2 — SpreadsheetParser + HeaderHeuristic (~2h)

- [ ] `src/Service/Import/SpreadsheetParser.php` — XLSX+CSV-Parser via `phpoffice/phpspreadsheet`. Auto-Detect Delimiter, Encoding, Header-Row, Sheet-Name.
- [ ] `src/Service/Import/HeaderHeuristicMapper.php` — Confidence-Score-Match: "Asset Name"/"Bezeichnung"/"name" → `Asset.name` mit Score 0-1.
- [ ] Tests: `tests/Service/Import/SpreadsheetParserTest.php` (Real-XLSX-Fixture), `HeaderHeuristicMapperTest.php`

### Task F2.3 — EntityMapperRegistry + Mapper-Pattern (~3h)

- [ ] `src/Service/Import/EntityMapperRegistry.php` — Registry-Pattern via tagged-services
- [ ] `src/Service/Import/Mapper/EntityMapperInterface.php`
- [ ] `src/Service/Import/Mapper/AbstractEntityMapper.php` — common Validation+Tenant-Scoping
- [ ] `src/Service/Import/Mapper/AssetMapper.php` — Field-Mapping + Validation (CIA-Werte, Type-Cast, Owner-Resolution)
- [ ] `src/Service/Import/Mapper/SupplierMapper.php` — DORA-Drittdienstleister-Felder + Risk-Tier
- [ ] `src/Service/Import/Mapper/ControlMapper.php` — Resolve Annex-A-ID → existing Control / Applicability
- [ ] Tests pro Mapper

### Task F2.4 — DeltaCalculator (~2h)

- [ ] `src/Service/Import/DeltaCalculator.php` — Match via slug/external-id, Diff per Entity, Output: `{updates: [], creates: [], deletes: [], unchanged: []}`
- [ ] Tests: `tests/Service/Import/DeltaCalculatorTest.php` mit Pre/Post-Fixtures

### Task F2.5 — BulkImportOrchestrator + Messenger (~3h)

- [ ] `src/Service/Import/BulkImportOrchestrator.php` — High-Level: upload → parse → map → preview → commit → audit-bulk
- [ ] `src/Message/BulkImportMessage.php` + `src/MessageHandler/BulkImportMessageHandler.php` — Async via Symfony Messenger
- [ ] Integration mit `AuditLogger::logBulk()` (CC3 Sprint 0)
- [ ] Tests: `tests/Service/Import/BulkImportOrchestratorTest.php` E2E mit Fixture-XLSX

### Task F2.6 — Controllers + Routes (~2h)

- [ ] `src/Controller/Import/BulkImportController.php`:
  - `GET /import/{entityType}` Wizard-Index
  - `POST /import/{entityType}/upload`
  - `GET|POST /import/{entityType}/map/{batchId}`
  - `GET /import/{entityType}/preview/{batchId}`
  - `POST /import/{entityType}/commit/{batchId}`
  - `GET /import/{entityType}/diff/{batchId}` (Delta-Mode)
  - `GET /import/{entityType}/error-csv/{batchId}` (Download)
- [ ] `#[IsGranted('ROLE_MANAGER')]` per Action
- [ ] Tests: `tests/Controller/Import/BulkImportControllerTest.php` Full-Wizard-E2E

### Task F2.7 — Forms + Voter (~1.5h)

- [ ] `src/Form/Import/UploadStepType.php`
- [ ] `src/Form/Import/ColumnMappingType.php` (Collection mit Auto-Mapping-Defaults)
- [ ] `src/Form/Import/PreviewConfirmType.php`
- [ ] `src/Security/Voter/BulkImportVoter.php`
- [ ] Tests pro Form + Voter

### Task F2.8 — Aurora-Templates (~3h)

- [ ] `templates/import/index.html.twig` — Entity-Type-Picker via `_fa_feature_card`
- [ ] `templates/import/wizard/upload.html.twig` — Drag-Drop + `_fa_empty_state` (`mood: working`)
- [ ] `templates/import/wizard/map.html.twig` — Column-Mapping mit Auto-Detect-Confidence-Badges
- [ ] `templates/import/wizard/preview.html.twig` — Preview-Tabelle + Skeleton-Loader (`feedback-systems.html#skeletons`)
- [ ] `templates/import/wizard/diff.html.twig` — Delta-Mode mit `isms-diff__line--*` classes (UX-Patch v5: reuse `isms-patterns.html#diff-viewer` classes, NICHT `_fa_diff_row`)
- [ ] `templates/import/wizard/commit.html.twig` — Progress-Bar + Result-Tabelle
- [ ] `templates/import/wizard/_steps_header.html.twig` — composing `fa-step-header` + `fa-setup-phase__bars` (UX-Patch v5: NICHT eigener Stepper, sondern wrap existing)
- [ ] Lint-Gate `lint:twig templates/import/`

### Task F2.9 — Stimulus Controllers (~2h)

- [ ] `assets/controllers/bulk_import_wizard_controller.js` — Step-Navigation, Esc-Cancel-Confirm
- [ ] `assets/controllers/column_mapping_controller.js` — Auto-Map-Confidence-Display + Manual-Override
- [ ] `assets/controllers/import_progress_controller.js` — Async-Job-Polling
- [ ] `assets/controllers/file_drop_controller.js` — Drag-Drop + Client-Side-Validation

### Task F2.10 — Module-Gating + Nav + Translations (~1h)

- [ ] Nav: `templates/_components/_mega_menu_panel_only.html.twig` Operations-Panel → Import-Sub-Items pro Entity
- [ ] `translations/data_import.de.yaml` ergänzen (Domain existiert)
- [ ] `translations/data_import.en.yaml` ergänzen
- [ ] Per-Entity Module-Gate prüfen (Asset = `assets`, Supplier = `assets`, Control = `controls`)

### Task F2.11 — Fixtures + Sample-Files (~1h)

- [ ] `fixtures/sample-imports/assets-sample.xlsx`
- [ ] `fixtures/sample-imports/suppliers-sample.xlsx`
- [ ] `fixtures/sample-imports/controls-sample.xlsx`
- [ ] Download-Button pro Entity-Wizard-Index "Sample-Excel laden"

### Task F2.12 — Alva-Mood-Integration + Power-User (~30min)

- [ ] Commit-Success → `window.alvaBus.emit({mood: 'celebrating'})`
- [ ] Commit mit row-errors → `mood: 'warning'`
- [ ] Hotkey `I` in List-View → Bulk-Import-Trigger (per `power-user.html`)

## F40 — Audit-Workbook-XLSX-Generator (parallel zu F2)

### Task F40.1 — AuditWorkbookGenerator-Service (~2h)

- [ ] `src/Service/Audit/AuditWorkbookGenerator.php` — Generic Generator-Wrapper via `phpoffice/phpspreadsheet`
- [ ] `src/Service/Audit/Generator/AuditWorkbookGeneratorInterface.php`
- [ ] `src/Service/Audit/Generator/SoaWorkbookGenerator.php` — Statement-of-Applicability als XLSX mit Tabs: Cover/Controls/Implementation-Status/Evidence-Links/Auditor-Notes
- [ ] Tests: `tests/Service/Audit/SoaWorkbookGeneratorTest.php` mit Fixture-Tenant

### Task F40.2 — Weitere Generators (~3h)

- [ ] `src/Service/Audit/Generator/ControlImplementationWorkbookGenerator.php` — pro Control: Owner, Status, Completeness, Verification-Date, Evidence-Count
- [ ] `src/Service/Audit/Generator/ComplianceFulfillmentWorkbookGenerator.php` — pro Framework: Requirement → Fulfillment-Status → Evidence
- [ ] `src/Service/Audit/Generator/RiskRegisterWorkbookGenerator.php` — Risk + Treatment-Strategy + Residual-Risk + Acceptance
- [ ] Tests pro Generator

### Task F40.3 — Controller + Routes (~1h)

- [ ] `src/Controller/Audit/AuditWorkbookController.php`:
  - `GET /audit-workbook/soa/{frameworkId}.xlsx`
  - `GET /audit-workbook/controls.xlsx`
  - `GET /audit-workbook/compliance/{frameworkId}.xlsx`
  - `GET /audit-workbook/risk-register.xlsx`
- [ ] Audit-Log via `AuditLogger::logExport()` pro Download
- [ ] Tests: Controller-Tests pro Endpoint

### Task F40.4 — UI-Integration (~1.5h)

- [ ] SoA-View ergänzen mit "Audit-XLSX Export"-Button (`_fa_btn`)
- [ ] Compliance-Framework-View Export-Button
- [ ] Risk-Index Export-Button
- [ ] Templates entsprechend ergänzen — Aurora `_fa_btn` mit `variant: 'success'`
- [ ] Alva-Mood `celebrating` on download-success

### Task F40.5 — Sample-Fixture (~30min)

- [ ] `fixtures/audit-workbooks/sample-soa.xlsx` als Referenz-Layout
- [ ] Test-Vergleich Output-Schema gegen Sample

## Definition-of-Done Sprint 1

- [ ] F2 alle 12 Tasks committed + lint-clean
- [ ] F40 alle 5 Tasks committed + lint-clean
- [ ] `php bin/phpunit` Full-Suite grün
- [ ] `php bin/console lint:twig templates/` clean
- [ ] `php bin/console lint:container` clean
- [ ] CI grün auf main
- [ ] CLAUDE.md Updates:
  - Bulk-Import-Pattern unter "Development Patterns"
  - Audit-XLSX-Pattern unter "Common Pitfalls" oder "Development Patterns"
- [ ] Manual-Demo grün:
  - Asset-Excel mit 200 Rows < 5 Min Commit
  - Delta-Re-Upload Diff zeigt korrekte 5 Updates
  - SoA-XLSX-Download öffnet in Excel mit allen 93 Annex-A-Controls
- [ ] Sprint-Memory `project_sprint1_bulk_import_xlsx.md` mit Findings

## Subagent-Driven-Dev Strategie

Tasks F2.1-F2.11 + F40.1-F40.5 sind largely parallel-safe:

**Wave A (parallel, kein Conflict):**
- Task F2.1 (Entities)
- Task F40.1 (Audit-Workbook-Service-Skelett)

**Wave B (nach A, parallel):**
- Task F2.2 (SpreadsheetParser)
- Task F2.3 (Mappers)
- Task F40.2 (weitere Generators)

**Wave C (nach B, parallel):**
- Task F2.4 (DeltaCalculator)
- Task F2.5 (Orchestrator + Messenger)
- Task F40.3 (Controller + Routes)

**Wave D (nach C, parallel):**
- Task F2.6 (Bulk-Import-Controllers)
- Task F2.7 (Forms + Voter)
- Task F40.4 (UI-Integration)

**Wave E (nach D, parallel):**
- Task F2.8 (Aurora-Templates)
- Task F2.9 (Stimulus)
- Task F2.10 (Nav + Translations)
- Task F40.5 (Sample-Fixture)

**Wave F (Final, sequential):**
- Task F2.11 (Fixtures)
- Task F2.12 (Alva + Power-User)
- DoD-Audit + Demo-Smoke-Test

Implementer-Subagent pro Task. Spec-Reviewer + Code-Quality-Reviewer
nach jedem Task. Branch-Strategie: Atomic-Commits direkt auf main, Lint-
Gate pro Commit. Bei Failure: revert + fix vor nächstem Task.