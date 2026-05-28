# 04 — Hot Files (God-Classes and Most-Edited)

The following files are the largest in the codebase as tracked by
`scripts/quality/check_god_class_size.py`. Each entry in
`scripts/quality/baselines/god_class_size.txt` is a CI baseline: the file must
not grow beyond its recorded LOC without a deliberate baseline bump (which
requires a justification comment in the PR).

Do not add new features to any file on this list without first discussing a
split strategy.

---

## God-Class Registry

### 1. `src/Controller/DeploymentWizardController.php` — 2694 LOC

- **What it does:** Drives the multi-step initial deployment wizard that
  configures the database, admin user, tenant, module selection, and
  integrations (SSO, mail, S3 backup) in a single guided flow.
- **Why it is large:** The wizard has 20+ steps, each with its own POST
  handler, validation branch, and progress-state serialisation. The wizard
  state is kept in session, not in a dedicated entity, which forces all step
  logic into one controller.
- **Refactor caution:** The session state schema is not versioned; changes to
  step order or field names silently break in-progress wizards. Any split
  requires a step-state migration strategy. Do not add new wizard steps here —
  raise an issue first.

### 2. `src/Controller/ComplianceController.php` — 2629 LOC

- **What it does:** Manages the full compliance framework lifecycle: framework
  activation, requirement listing, gap analysis, evidence linking, mapping
  import, compliance scoring, and SoA generation — for every supported
  framework (ISO 27001, NIS2, DORA, BSI, TISAX, SOC2, GDPR, etc.).
- **Why it is large:** Each compliance standard has subtly different field sets
  and workflows but shares the same controller routes; the controller branches
  on `$framework->getType()` rather than using sub-controllers.
- **Refactor caution:** Framework-specific logic is deeply entangled. A per-
  framework sub-controller split is the long-term plan (tracked in backlog).
  Before touching this file, run `php bin/phpunit tests/Controller/Compliance*`
  to establish a passing baseline.

### 3. `src/Service/RestoreService.php` — 2285 LOC

- **What it does:** Deserialises a backup archive (JSON + attachments) and
  restores all 114 entities in dependency-safe insertion order, handling
  conflict resolution, tenant re-mapping, and orphan detection.
- **Why it is large:** Each entity type requires a custom restoration strategy
  (upsert vs replace, FK order, unique-field deduplication). Foreign-key
  ordering is hand-coded for MySQL's FK constraint enforcement.
- **Refactor caution:** The insertion order at lines 400-600 is critical — any
  reordering causes FK constraint violations. If you add a new entity that has
  FKs to existing entities, find the correct insertion slot and add a
  `// FK: EntityA → EntityB` comment. Run the full restore integration test
  after any change.

### 4. `src/Service/PolicyWizard/DocumentGenerator.php` — 1869 LOC

- **What it does:** Generates Word/PDF policy documents from Twig templates,
  injecting tenant variables, control references, approval signatures, and
  version metadata. Handles 50+ policy template types.
- **Why it is large:** Each template type requires a bespoke variable-injection
  strategy; the generator also manages header/footer branding, watermarks, and
  multi-language rendering.
- **Refactor caution:** Template binding is implicit (template name → generator
  method name via convention). Breaking the naming convention breaks
  generation silently. New policy types must follow the naming contract;
  document it in the method docblock.

### 5. `src/Service/ComplianceWizard/CategoryProvider/EuRegulatoryFrameworkCategoryProvider.php` — 1837 LOC

- **What it does:** Provides the category tree and requirement list for all EU
  regulatory frameworks (NIS2, DORA, GDPR, eIDAS) used in the compliance
  wizard. Each framework has its own category hierarchy embedded as arrays.
- **Why it is large:** The category data is inlined rather than loaded from
  YAML/JSON, because the wizard needs it resolved at PHP compile-time for
  performance reasons.
- **Refactor caution:** Moving the data to YAML requires a cache-warm step
  and a version-aware loader. Do not inline more frameworks here — create a
  new `CategoryProvider` implementation and register it as a tagged service.

### 6. `src/Service/DashboardStatisticsService.php` — 1808 LOC

- **What it does:** Aggregates KPIs for all persona dashboards (CISO, DPO,
  Risk Manager, Compliance Manager, ISB, BCM Officer) plus the main dashboard.
  Issues 25+ DQL queries per page load; results are cached per tenant.
- **Why it is large:** Every persona requires a different set of metrics, and
  the metrics are tenant-scoped and module-gated, making shared query helpers
  difficult without over-abstraction.
- **Refactor caution:** Query performance is highly sensitive. Before changing
  a DQL query, check `EXPLAIN` output on a dataset with 1000+ entities. All
  queries must filter by `tenant_id` — missing this filter is a security defect.

### 7. `src/Service/DataIntegrityService.php` — 1687 LOC

- **What it does:** Detects and repairs data integrity issues: orphaned records,
  tenant mismatches, duplicate entities, missing required fields, and FK
  violations. Feeds the `/quick-fix` operator UI.
- **Why it is large:** Each of the 114 entities needs individual repair logic;
  the service also generates a structured repair report with severity levels.
- **Refactor caution:** Repair operations are destructive. New repair methods
  must be non-destructive by default (dry-run first) and must log every
  change via `AuditLogger`. The god-class baseline is currently 1863 — CI will
  fail if you push this above that without bumping the baseline.

### 8. `src/Controller/RiskController.php` — 1610 LOC

- **What it does:** Full CRUD for risks including: risk matrix, treatment plan,
  approval workflow, bulk operations, risk appetite configuration, risk
  inheritance from assets, DORA/NIS2-specific fields, and export.
- **Why it is large:** Risk management is the most feature-dense module; the
  controller handles 30+ routes, each with its own guard, form, and template.
- **Refactor caution:** The risk treatment approval flow is coupled to the
  `WorkflowService` at specific method call sites. Do not move or rename these
  methods without updating the regulatory workflow YAML references.

### 9. `src/Service/Export/CertificationBundleExporter.php` — 1586 LOC

- **What it does:** Assembles the certification bundle export (ZIP archive
  containing all SoA evidence, audit records, policy documents, and gap
  analysis) required for ISO 27001 certification audits.
- **Why it is large:** Each bundle section has its own formatter and evidence
  collection logic; the exporter also generates an index PDF and a manifest.
- **Refactor caution:** The exporter is invoked as an async job — it runs after
  `fastcgi_finish_request()`. It must not depend on session or request-bound
  services. Pass everything via `$jobArgs`.

### 10. `src/Service/Search/SearchService.php` — 1410 LOC

- **What it does:** Full-text search across 33+ entity types with faceted
  filtering, relevance scoring, tenant isolation, module-gating per entity
  type, and result-type grouping.
- **Why it is large:** Each entity type requires a custom search query with
  entity-specific relevance fields; results are normalised to a common
  `SearchResult` value object.
- **Refactor caution:** Adding a new entity to search requires: (a) a new
  `search<Entity>` private method, (b) a new result-type constant, (c) a
  module-gate check, and (d) a test in `tests/Service/Search/`. Missing any
  one of these causes the entity to silently not appear in results.

---

## Most-Edited Supporting Files

These files are not god-classes by size but are changed in almost every
feature PR:

| File | Change frequency reason |
|---|---|
| `config/modules.yaml` | Every new optional feature adds a module key |
| `config/services.yaml` | New tagged services, parameter bindings |
| `translations/messages.de.yaml` | Common cross-domain translation keys |
| `templates/base.html.twig` | Global layout changes, new Stimulus wiring |
| `src/Service/DashboardStatisticsService.php` | New KPI per feature |
| `src/Security/Voter/EntityVoter.php` | New entity types needing generic vote |

---

## Checking the Baseline Locally

```bash
python3 scripts/quality/check_god_class_size.py
```

Output format: `PASS` or `FAIL <file>:<LOC> exceeds baseline <baseline>`.
To update a baseline after a justified growth, edit the corresponding line in
`scripts/quality/baselines/god_class_size.txt` and include a reason comment
in your PR description.
