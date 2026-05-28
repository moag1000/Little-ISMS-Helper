# ADR-0004: YAML as Source of Truth for Regulatory Workflows

**Status:** Accepted  
**Date:** 2026-05-17  
**Deciders:** moag1000  
**Tags:** workflows, yaml, deprecation, doctrine, regulatory

---

## Context

The original workflow system stored workflow definitions entirely in the database:
`App\Entity\Workflow` + `App\Entity\WorkflowStep` entities held names, step sequences, role
assignments, SLA timers, and auto-progression conditions as DB rows. This served well for a
single-instance MVP, but by Sprint Y.0 the following problems were accumulating:

1. **Dev/prod parity gap:** Workflow definitions lived in the production database. A new developer
   cloning the repo for the first time had no workflows. Data fixtures were incomplete and diverged
   from production over time. The authoritative state was a production database dump — not the
   codebase.
2. **Migration conflicts:** Two parallel regulatory workflow PRs (GDPR breach + DPIA) produced
   conflicting DB seeds. Merging them required manual row deduplication. PHPUnit fixtures diverged
   from migration seeds.
3. **No diff-ability:** "What changed in the GDPR breach workflow between v3.2 and v3.4?" required
   DB row comparison, not `git diff`. ISO 27001 Cl. 7.5.3 documentation-control requirements push
   towards version-controlled definitions.
4. **No static validation:** A typo in a step name only surfaced at runtime. YAML + Symfony config
   component supports structural validation at `debug:config` time.
5. **Regulatory workflows are stable:** The 15 canonical workflows (GDPR Art. 33/34, DPIA Art. 35,
   NIS2 incident, DORA ICT incident, Risk Treatment ISO 27001 Cl. 6.1.3, …) are defined by
   regulation. They change only when the underlying law changes. DB mutability is unnecessary
   overhead for stable regulatory definitions.

---

## Decision

**YAML files in `config/workflows/regulatory/*.yaml` are the source of truth for all 15 canonical
regulatory workflow definitions.** The `App\Entity\Workflow` and `App\Entity\WorkflowStep` Doctrine
entities are deprecated (`@deprecated since Y.4`).

Key mechanics:
- A PHPStan custom rule (`tools/phpstan/Rule/NoNewWorkflowOrWorkflowStep.php`) prevents new
  instantiations of `Workflow` or `WorkflowStep` in `src/` outside Repository/Command namespaces.
- `WorkflowService` (the public facade) is stable and reads YAML definitions via a loader service.
- Legacy `WorkflowAutoProgressionService` is deprecated; auto-progression fires through
  `FieldCompletionAutoTransition` Doctrine `postUpdate` listener (no explicit service calls in
  controllers or entity services).
- `php bin/console app:migrate-legacy-workflows` verifies all 15 DB rows have YAML equivalents
  (report-only, safe). `--archive` archives orphan DB rows.
- Tenant-specific overrides (SLA, role, notification) are stored in the `lifecycle_config` table
  (a separate concern from the workflow definition itself).

**Naming and file layout:**
```
config/workflows/regulatory/
    gdpr_data_breach.yaml          # Art. 33/34, 6 steps, 72h SLA
    incident_high_severity.yaml    # ISO 27001, 6 steps
    incident_low_severity.yaml     # ISO 27001, 4 steps
    risk_treatment.yaml            # Cl. 6.1.3, 6 steps
    dpia.yaml                      # Art. 35/36, 6 steps
    data_subject_request.yaml
    capa.yaml
    change_request.yaml
    management_review.yaml
    control_verification.yaml
    supplier_assessment.yaml
    training_verification.yaml
    bc_plan_activation.yaml
    document_review.yaml
    incident_post_mortem.yaml
```

---

## Consequences

### Positive

- **Git is the audit log:** Every workflow change is a reviewed PR with a commit message, diff, and
  review comments. ISO 27001 Cl. 7.5.3 "documented information" is satisfied by the repo history.
- **Dev/prod parity:** `git clone` + `composer install` gives a developer all 15 workflows
  immediately — no DB import required.
- **Static validation:** `php bin/console debug:config app workflows` catches structural YAML
  errors before deployment.
- **Testability:** PHPUnit tests load YAML definitions via the Symfony Kernel; no DB seeding
  required for workflow step assertions.

### Negative

- **Two-phase deprecation:** Until all DB `workflow_steps` rows are archived, the system must
  reconcile YAML and DB on startup. `app:migrate-legacy-workflows` is the reconciliation tool.
  This deprecation debt should be cleared before v4.0.
- **YAML verbosity for complex conditions:** AND/OR auto-progression conditions are expressible in
  YAML but verbose. Complex rules benefit from a visual builder (deferred UI feature).
- **Tenant customisation boundary:** Tenants can override SLA/roles via `lifecycle_config`, but
  cannot add steps or change step order — that requires a YAML PR. This is by design (regulatory
  workflows are defined by law) but may surprise operators who expect full DB-side customisation.

---

## Migration Path for Deprecation Debt

```bash
# Step 1: Identify orphan DB rows (safe, no changes)
php bin/console app:migrate-legacy-workflows

# Step 2: Archive DB rows that have YAML equivalents (irreversible)
php bin/console app:migrate-legacy-workflows --archive

# Step 3: Remove WorkflowAutoProgressionService injections (PHPStan will flag remaining ones)
php bin/phpstan analyse src/ --level 8
```

---

## References

- `config/workflows/regulatory/` — all 15 YAML definitions
- `src/Service/WorkflowService.php` — stable public facade
- `src/EventListener/FieldCompletionAutoTransition.php` — canonical auto-progression listener
- `tools/phpstan/Rule/NoNewWorkflowOrWorkflowStep.php` — static guard
- `docs/WORKFLOW_AUTO_PROGRESSION.md` — complete auto-progression guide
- `docs/decisions/2026-05-17-workflow-yaml-unification.md` — original ADR (detailed)
- CLAUDE.md §"Workflow System (Event-Driven Approvals)"
