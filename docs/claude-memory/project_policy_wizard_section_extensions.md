---
name: project_policy_wizard_section_extensions
description: "Policy-Wizard saniert: Re-run-Bug gefixt + canonical Topic-Policies mit Per-Standard-Section-Extensions (9 Catalogues) + Fulfillment-Sync. 2026-06-20."
metadata:
  node_type: memory
  type: project
  originSessionId: 967ef94c-1958-4208-8742-d32c1c5a3832
---

Policy-Wizard-Arbeit 2026-06-20 abgeschlossen (PRs #988, #990, #992, alle grün gemerged).

**Architektur (die User-validierte „beste Lösung", 4 Verifikations-Runden):** EINE
kanonische Menge **Topic-Policies** (Access Control, Crypto, Supplier …), default
ISO 27001 + Datenschutz. Aktiviert der User eine weitere Norm (baseline-getrieben, z.B.
EU-DORA), **erweitern sich die SECTIONS** der Topic-Policies — nicht eine Policy pro Norm.
Cross-Framework-Mappings grounden, welcher Topic die Controls einer Norm erweitern.

**Bausteine (60% war vorgebaut — erweitert, nicht neu):**
- `StandardSectionCatalogueInterface` (`getStandard()`, `sectionsForTopic(isoTopic)`) +
  `SectionExtension`-DTO (`sectionKey, standard, controlRefs, approvalRole, bodyTranslationKey,
  renderMode∈document_section|body_extension`) + `SectionExtensionRegistry` (auto-tag
  `app.policy_section_catalogue` via `_instanceof`) — alle in `src/Service/PolicyWizard/SectionExtension/`.
- 9 Catalogues: Gdpr/Dora (vorhanden) + Nis2/Bsi/C5/Iso27701/Tisax/Soc2/Bcm (neu),
  ISO-topic-keyed, mapping-grounded. TISAX license-safe (nur Control-Nummern, Original-Prosa).
- `DocumentGenerator` dispatcht über `run.standardsAdopted`; `CrossCoverageCalculator`
  zählt **Topic-Abdeckung** (nicht Control) als Nenner.
- `PolicyFulfillmentSyncService` (#992): generierte Policy bumpt verlinkte
  `ComplianceRequirementFulfillment` only-upward auf `in_progress`/25% + hängt Doc als
  Evidence an + audit-log `policy_wizard.fulfillment_auto_updated`. Gewired in
  `DocumentGenerator::updateSoa()` neben `SoaAutoUpdateService::propagateForDocument()`.
- 6 Silo-`Seed<Std>PolicyTemplatesCommand` `@deprecated` (nicht gelöscht — Daten-Safety).

**Der echte Bug (tiefer als gemeldet):** „Gezielter Re-run zeigt nichts" — `WizardOrchestrator::start()`
rief nie `ModeRegistry->forRun($run)->onStart($run)` → `TargetedRerunModeHandler::onStart()` war
Dead Code → Re-run nie auf `STEP_TARGETED_PICK` positioniert. Fix = nullable ModeRegistry
injizieren + onStart nach `firstStepFor()` feuern.

**Lehren:** (1) Domain-Experte-User ernst nehmen — sein „ist das ideal?" (3×) fing je einen
fundamentalen Design-Fehler (zirkuläre Verifikation, 12-Seeder-Silo, non-uniforme Coverage).
(2) DATE_MUTABLE-Spalte braucht `\DateTime` nicht `DateTimeImmutable` im Fixture.
(3) Tenant-Code-Kollision: `uniqid()` trunkiert → `bin2hex(random_bytes(8))`.

Spec/Plan: `docs/superpowers/specs/2026-06-20-policy-wizard-rerun-and-topic-coverage-design.md`.
Offen (deferred, nicht gestartet): EN 50600, DACH Wave B. FU-B (KRITIS wizard-selectable)
bewusst geskippt (schlechter Framework-Fit: KRITIS-DachG→NIS2-UmsuCG, nicht ISO-Topics).
Siehe [[project_dach_eu_mapping_program]], [[feedback_json_fields_use_builder]].
