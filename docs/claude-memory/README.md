# Claude Code working memory (snapshot)

This directory holds a filtered snapshot of the working memory a Claude Code
agent built up while helping develop Little ISMS Helper. The snapshot was taken
on 2026-10-11.

The agent writes these notes for itself, so that later sessions do not repeat
the same mistakes or re-derive the same facts. They cover code conventions,
CI and test traps, framework facts (e.g. DORA, MRIS) and modelling decisions.
Most notes are in German.

**This material is advisory. It is not documentation of record.** Notes can be
stale, describe intermediate states, or reference branches and PRs that have
since changed. When a note and the code disagree, the code (on `main`) wins.
Authoritative docs live elsewhere under `docs/` and in `CLAUDE.md`.

## What was included

Only notes with purely technical or domain content were published. Notes about
personal working preferences, and notes containing names, credentials, hosts,
licensing questions or business information, were left out. The included notes
are copied unchanged. No path rewriting was needed.

## Note format

Each note is a Markdown file with YAML frontmatter:

- `name`: a short identifier (sometimes the file stem, sometimes a title)
- `description`: a one-line summary
- `type` (top-level, or under `metadata:`):
  - `feedback`: a rule or lesson learned (often with **Why** / **How to apply**)
  - `project`: the state of a feature, a design decision or architecture facts
  - `reference`: pointers to external facts or sources
- `originSessionId` / `modified`: bookkeeping fields from the agent's memory tool, with no meaning for readers

`[[name]]` is a cross-reference to another note, by file stem (e.g.
`[[feedback_ci_gate_on_success]]`) or by frontmatter `name` (e.g.
`[[release-version-bump]]`). Some links point to notes that were deliberately
not published. Those links will not resolve.

`INDEX.md` is the agent's own index (derived from its `MEMORY.md`), limited to
the published notes. `project_regression_bug_patterns_skill.md` has no line
there because the original index never listed it.

## Thematic index

### CI, quality gates and testing

- [feedback_ci_gate_on_success](feedback_ci_gate_on_success.md): gate auto-merge on `conclusion==SUCCESS` and run the full CI gates locally
- [feedback_ci_test_discipline](feedback_ci_test_discipline.md): run the full `check_*.py` sweep before every push, keep heavy catalogues out of integration tests, rebase long branches early
- [feedback_pre_pr_local_checks](feedback_pre_pr_local_checks.md): local quality-gate commands to run before opening or updating a PR
- [feedback_targeted_tests](feedback_targeted_tests.md): run only the affected tests while iterating and the full suite at the end
- [feedback_gate26_line_baseline_shift](feedback_gate26_line_baseline_shift.md): adding translation lines shifts the line-anchored Gate 26 baseline
- [feedback_phpstan_baseline_traps](feedback_phpstan_baseline_traps.md): never regenerate the PHPStan baseline wholesale or into `/tmp`, and clear the result cache first
- [feedback_inrequest_runner_failonwarning](feedback_inrequest_runner_failonwarning.md): jobs run synchronously under test, `failOnWarning` catches undefined array keys, and shape changes cascade
- [feedback_local_test_db_setup](feedback_local_test_db_setup.md): local DB tests need `.env.test.local` and a `_test`-suffixed database
- [feedback_local_test_env_modules](feedback_local_test_env_modules.md): diff `active_modules.yaml` against `.dist` before blaming code for mass controller-test failures
- [feedback_csrf_tests_session](feedback_csrf_tests_session.md): WebTestCase CSRF tokens need an active session (GET first)
- [feedback_run_it_dont_assume](feedback_run_it_dont_assume.md): green suites don't prove the app works, so run imports and the UI against a real DB
- [feedback_php_month_arithmetic](feedback_php_month_arithmetic.md): `-N months` overflows at month end, so anchor windows on the first of the month
- [project_regression_bug_patterns_skill](project_regression_bug_patterns_skill.md): the `regression-bug-patterns` skill as a living checklist for PR review

### Releases and dependencies

- [feedback_release_workflow](feedback_release_workflow.md): releases go through release-please, the dev workflow or `-rc.N` tags, never ad-hoc tags
- [feedback_release_cadence_discipline](feedback_release_cadence_discipline.md): manual releases only for urgent fixes, otherwise the weekly cycle
- [feedback_release_version_bump](feedback_release_version_bump.md): `composer.json` `version` drives `app_version` and is managed by release-please
- [project_symfony_lts_pin](project_symfony_lts_pin.md): Symfony stays on 7.4 LTS unless explicitly requested

### Backend: Symfony, Doctrine, multi-tenancy

- [feedback_controller_500_traps](feedback_controller_500_traps.md): protected `getEntityManager()` and `catch (Exception)` missing `\Error` cause hard 500s
- [feedback_backup_enum_roundtrip](feedback_backup_enum_roundtrip.md): enum fields break restore only through a real JSON round-trip
- [feedback_migration_consolidation](feedback_migration_consolidation.md): one migration per multi-entity pattern rollout, not one per entity
- [feedback_tenant_scope_outside_http](feedback_tenant_scope_outside_http.md): the tenant filter is only active on `kernel.request`, so jobs, workers and cron must set the tenant themselves
- [feedback_locale_in_links](feedback_locale_in_links.md): the locale prefix is global, so never add `/{_locale}/` in controller routes
- [project_sprint17_reapply_incident](project_sprint17_reapply_incident.md): Rector/linter once silently removed entity fields, so check entity diffs after such runs
- [project_quickfix_hardening](project_quickfix_hardening.md): the QuickFix schema-repair safety model (destructive gate, verify-before-mark, schema lock)
- [project_schema_driven_import](project_schema_driven_import.md): bulk import is metadata-driven, so a new entity needs one `ImportSchemaProvider`
- [project_role_scope_rollout](project_role_scope_rollout.md): ROLE_ADMIN vs ROLE_SUPER_ADMIN scope model and `TenantScopedAdminVoter` pattern

### UI, design system, frontend

- [feedback_alva_fairy_pattern](feedback_alva_fairy_pattern.md): offer setup shortcuts as an unobtrusive "Alva tip" card, on demand
- [feedback_no_native_dialogs](feedback_no_native_dialogs.md): use `faConfirm` / `faToast` instead of `alert()` / `confirm()` / `prompt()`
- [feedback_no_inline_table_edit](feedback_no_inline_table_edit.md): no inline cell editing; edit through drawer or form
- [feedback_json_fields_use_builder](feedback_json_fields_use_builder.md): JSON entity fields always get a Twig macro and Stimulus builder, never a raw textarea
- [feedback_native_form_silent_block](feedback_native_form_silent_block.md): off-screen invalid `required` fields silently block submit, so use `novalidate` with a JS guard
- [feedback_css_import_barrel_fouc](feedback_css_import_barrel_fouc.md): an `@import` barrel causes FOUC, so load the Aurora layers as parallel `<link>` tags
- [project_admin_aurora_migration](project_admin_aurora_migration.md): Aurora v4 vocabulary for admin templates and the admin hub architecture
- [project_audit_components](project_audit_components.md): `.isms-trail` (timelines) vs `.fa-audit-row` (entity change logs)
- [project_ds_audit_2026_05_30](project_ds_audit_2026_05_30.md): design system vs app comparison, naming drift, and how to detect orphaned CSS
- [project_nav_area_resolver](project_nav_area_resolver.md): menu active area via one ordered resolver; breadcrumbs link only via `url:`
- [project_alva_hint_foundation](project_alva_hint_foundation.md): Alva hint rule architecture, tiers, gating and persistence
- [project_alva_hint_deeplinks](project_alva_hint_deeplinks.md): count hints deep-link to exactly the counted entities (shared finder and `focus` filter)
- [project_form_pattern_a_deferred](project_form_pattern_a_deferred.md): dual-state owner fields (legacy string plus user relation)
- [project_form_pattern_b_deferred](project_form_pattern_b_deferred.md): TomSelect for large multi-selects
- [project_person_picker_gap](project_person_picker_gap.md): remaining free-text person fields moved to the dual-state picker
- [project_clone_rollout](project_clone_rollout.md): the `EntityClonerInterface` pattern and which entities are cloneable

### Domain modelling and terminology

- [feedback_tenant_organisation_term](feedback_tenant_organisation_term.md): "Tenant" in code, "Organisation"/"Organization" in the UI
- [feedback_nonconformity_modeling](feedback_nonconformity_modeling.md): `AuditFinding.type` plus `CorrectiveAction` is sufficient; no separate nonconformity entity
- [project_schutzbedarfsvererbung_deferred](project_schutzbedarfsvererbung_deferred.md): BSI maximum principle along asset dependencies
- [project_certification_blockers](project_certification_blockers.md): ISO 27001 certification readiness on the tool side

### Compliance frameworks and mappings

- [feedback_dora_replaces_vait_bait](feedback_dora_replaces_vait_bait.md): DORA supersedes VAIT/KAIT/ZAIT; BAIT is phased out until 2026-12-31
- [feedback_mris_not_marisk](feedback_mris_not_marisk.md): MRIS is "Mythos-Resistant Informationssicherheit", not BaFin MaRisk
- [feedback_mapping_targets_key](feedback_mapping_targets_key.md): fixture keys must match what the importer actually reads (`target` vs `targets`)
- [project_iso_bsi_gap](project_iso_bsi_gap.md): ISO 27001 to BSI IT-Grundschutz gap feature and mapping trust tiers
- [project_junior_mapping_profit](project_junior_mapping_profit.md): making mapping reuse visible and usable for ROLE_USER
- [project_compliance_certificate](project_compliance_certificate.md): a generic certificate object with bulk fulfilment and optional OCR
- [project_wizard_manual_checks](project_wizard_manual_checks.md): `manual` wizard checks always score 0, so use `entity_presence` or a manual confirmation
- [project_policy_wizard_section_extensions](project_policy_wizard_section_extensions.md): topic policies with per-standard section extensions
- [project_datenschutz_audit_2026_06_04](project_datenschutz_audit_2026_06_04.md): a data-protection review of the tool's own features and the enforcement gates added
- [project_ebios_rm_spec](project_ebios_rm_spec.md): EBIOS RM design decisions (reuse-first, creates normal `Risk` rows)

### Feature modules and roadmap state

- [project_resource_planning_spec](project_resource_planning_spec.md): the `resource_planning` module (action items, capacity roadmap, source adapters)
- [project_phase3_progress](project_phase3_progress.md): phase 3/4 features (API tokens, EUVD, DOCX export, MCP server, supplier questionnaire)
- [project_sprint_roadmap_state](project_sprint_roadmap_state.md): roadmap state as of 2026-05-15
- [project_improvement_plans_open](project_improvement_plans_open.md): persona-driven improvement plans, all high-priority items done
- [project_persona_skills](project_persona_skills.md): persona skills under `.claude/skills/persona-*` for role-based tool feedback
