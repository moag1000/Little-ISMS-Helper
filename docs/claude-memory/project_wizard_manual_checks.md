---
name: project-wizard-manual-checks
description: "Compliance-wizard checks of type 'manual' always score 0 — use entity_presence or WizardManualConfirmation instead."
metadata: 
  node_type: memory
  type: project
  originSessionId: 8524ab1e-7912-42ad-be81-bb1c9410a3e9
---

ComplianceWizardService::runCheck() type `manual` is a permanent score-0 + gap — it never reads tenant data. Symptom: wizard reports filled-in clauses (Kontext/Interessierte Parteien/Scope) as false critical gaps.

Fixed 2026-06-04 (PR #850, commit a22772dde):
- **entity_presence** check type → `CoverageCheckService::checkEntityPresence()` counts tenant rows for a configured `entity` key (isms_context, interested_party, isms_objective, management_review, processing_activity, supplier, asset, document). Wire a clause by setting `'type' => 'entity_presence', 'entity' => '<key>'` in the CategoryProvider.
- **WizardManualConfirmation** entity + `WizardManualConfirmationService` + ROLE_MANAGER CSRF endpoint `app_compliance_wizard_confirm_manual` + toggle in category.html.twig — for genuinely entity-less clauses (Cl. 5.1 leadership etc.). runManualCheck() scores 100 once confirmed.

When adding a new wizard clause: if it maps to an entity → entity_presence; else it's manual and auto-sign-off-able. Never leave a clause that can only ever score 0.

Related: policy-wizard had a parallel i18n bug — `operational_baselines` template rendered an access/identity section whose dynamic (concatenated) `|trans` keys had no yaml entries; orphan-gate can't see concatenated keys. Fixed PR #851. Watch for dynamic-key i18n gaps — the gate won't catch them. [[feedback_gate26_line_baseline_shift]]
