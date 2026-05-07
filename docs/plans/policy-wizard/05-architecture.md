# Policy-Wizard — Architectural Synthesis (Phase 2)

Consolidates the four specialist reports (`01-iso27001-input.md`,
`02-bsi-input.md`, `03-dora-input.md`, `04-bcm-input.md`) into a
single implementation-ready blueprint. Decisions resolved here are
binding for Phase 3 (persona review) and the eventual code work.

> **Status:** Draft — pending Phase 3 (persona) + Phase 4 (specialist
> refinement) reviews. Sprint breakdown in §13 is indicative.

---

## 1. Goals

1. A tenant Manager / ISB / CISO can run a wizard that produces the
   mandatory governance-level policy / programme document set for the
   chosen standard mix (ISO 27001 baseline + optional BSI / DORA / BCM
   addons) in <30 minutes total elapsed time, all interactive.
2. Generated documents land in the existing `Document` module as
   `status=draft`, owner-assigned, never auto-published.
3. Each document auto-links to its target controls in the SoA and to
   the wizard-recorded settings, so future changes propagate.
4. Tenant settings collected by the wizard become persistent
   `TenantPolicySetting` records, hierarchy-aware (Konzern → Tochter
   override matrix per setting).
5. Re-running the wizard does not duplicate published documents — it
   creates a NEW version of unchanged-since-publish drafts and skips
   anything that is already approved.

## 2. Non-Goals (v1)

- Per-process `BCPlan` content generation — handled by the existing
  `BCPlan` form, the wizard only produces the BCM Programme + Crisis
  Management Plan + Exercise Programme (governance-level).
- DORA CTPP-mode (Critical Third-Party Provider). Different policy
  set entirely; flagged for a future v2.
- Real BIA execution — wizard only produces the BIA Methodology
  document and prompts the user to run BIA in the existing module.
- Free-form LLM generation. We use approved templates with
  variable-substitution. (Auditor pushback risk on "template feel"
  is addressed via mandatory tailoring fields, see §11.)

## 3. Standards Coverage Matrix

| Tenant choice | Top-level policy | Topic policies | Methode-Doc | Special |
|---|---|---|---|---|
| ISO 27001 only | 1 (Cl. 5.2) | 24 (A.5–A.8 family) | 0 | — |
| BSI Grundschutz only | 1 (ISMS.1.A4) | 28 (ISMS+ORP+CON+OPS+DER) | 1 (Schutzbedarf) | Notfallhandbuch if BCM |
| ISO + BSI dual | 1 (Cl. 5.2 EN + ISMS.1.A4 DE) | 24 ISO + 8 BSI-only deltas | 1 | — |
| ISO + DORA addon | 1 (Cl. 5.2) | 24 ISO + 6 DORA-NEW + 18 DORA-EXTENDS-ISO | 0 | DORA validity_from = 2025-01-17 |
| BSI + DORA addon | 1 + DORA mappings | 28 BSI + 6 DORA-NEW | 1 | DORA tagging |
| ISO + GDPR-scope | 1 | 24 (with 10 privacy-sections injected) | 0 | +5 standalone privacy docs (DPO Charter, RoPA, DPIA, DSR, Retention Schedule) |
| ISO + DORA + GDPR | 1 | 24 + 6 DORA-NEW + 18 DORA-EXTENDS + 10 privacy-sections | 0 | +5 standalone privacy docs |
| Any + ISO 27701 PIMS | (no extra top) | (no change) | 0 | +2-4 PIMS-clause docs (parallel addon) |
| Any + BCM | + BCM Policy | + 12 BCM docs (ISO) or 13 (BSI) | + (incl. Notfallhandbuch for BSI) | Auto BCExercise records |

Total document count range: **25** (ISO solo) → **52** (Quintuple ISO
+ BSI + DORA + GDPR + BCM). The privacy-as-sections pattern (Phase
1-E rework, see `06-dpo-input.md` §0) drops the previous upper bound
of 63 to 52 by collapsing 10 of the original 16 privacy documents
into sections of existing ISO/DORA topic policies. ISO 27701 PIMS
remains a separate addon (parallel to DORA, +2-4 docs).

## 4. Domain Model

### 4.1 Entities (new)

```
PolicyAcknowledgement                    [P1 — Auditor: A.6.3 evidence]
- id (PK)
- tenant_id                 (FK Tenant, NOT NULL)
- document_id               (FK Document, NOT NULL)
- user_id                   (FK User, NOT NULL)
- acknowledgedAt            (datetime_immutable)
- acknowledgementMethod     (enum: 'web_click'|'email_token'|'training_pass'|'signed_pdf')
- documentVersion           (string — captured at acknowledgement time so re-versions don't void)
- ipAddress                 (string, nullable — audit trail)
- UNIQUE (tenant_id, document_id, user_id, documentVersion)

# Closes the auditor's predicted A.6.3 NC ("policy must be communicated
# and acknowledged"). Wizard generates a CRON to push acknowledgement
# requests for any Document with status=published whose required-
# audience users haven't yet acknowledged.

PolicyTemplate
- id (PK)
- key                       (string, unique, e.g. 'iso27001.access_control')
- standard                  (enum: 'iso27001'|'bsi'|'dora'|'bcm22301'|'bsi200-4')
- topic                     (string, e.g. 'access_control')
- documentType              (enum: 'policy'|'programme'|'plan'|'procedure'|'methodology')
- affectedFunctions         (json list — P1 Risk-Owner)
                              # e.g. ['IT_OPERATIONS','HR'] for HR-Security policy.
                              # drives Heads-of-Function review-batch step in §9.
- normRef                   (string, e.g. 'A.5.15' / 'OPS.1.1.3' / 'Art. 9.4')
- titleTranslationKey       (string)
- bodyTranslationKey        (string, points at versioned key
                              policy.iso27001.access_control.v1.body)
- requiredVariables         (json: list of {key, type, label_t_key, required})
- linkedAnnexAControls      (json list, e.g. ['A.5.15','A.5.18'])
- linkedBausteine           (json list, e.g. ['ORP.4'])
- linkedDoraArticles        (json list, e.g. ['Art. 9.4'])
- reviewIntervalMonths      (int, default 12)
- approvalChain             (json: ordered list of role keys, e.g. ['ROLE_CISO','ROLE_TOP_MGMT'])
- climateChangeWording      (bool, default false — auto-included when iso27001 + Amd. 1:2024)
- supersededBy              (FK self, null when current)
- isActive                  (bool, default true)
- version                   (int, default 1 — bump when canonical wording changes)

WizardRun
- id (PK)
- tenant_id                 (FK Tenant, NOT NULL)
- standardsAdopted          (json: ['iso27001','dora'] etc — drives template selection)
- mode                      (enum: 'full'|'targeted'|'sandbox' — P1 ISB+UX)
                              # 'targeted' = re-run subset; 'sandbox' = preview only, no persistence
- targetedTopics            (json list, e.g. ['access_control','backup'] — P1 ISB)
                              # null on full / sandbox runs
- findingReference          (string, nullable — P1 ISB: link to Audit-Finding that triggered this run)
- affectedFunctions         (json list of business-functions touched — P1 Risk-Owner)
                              # populated from PolicyTemplate.affectedFunctions on every emitted doc
- startedAt                 (datetime_immutable)
- completedAt               (datetime_immutable, nullable)
- startedByUser_id          (FK User)
- step                      (string — current step key, e.g. 'organisation_scope')
- inputs                    (json — full settings snapshot)
- status                    (enum: 'in_progress'|'completed'|'cancelled'|'failed'|'sandbox')
- generatedDocumentIds      (json list of Document IDs created — empty on sandbox)
- errorMessage              (text, nullable)

TenantPolicySetting
- id (PK)
- tenant_id                 (FK Tenant)
- key                       (string — namespaced, e.g. 'isms.scope_statement', 'risk.appetite_tier')
- value                     (json — typed value)
- inheritedFromTenant_id    (FK Tenant, null when own)
- overrideMode              (enum: 'forbidden'|'stricter_only'|'broader_only'|'free')
                              — applied at WRITE time so subsidiary cannot relax parent value
- updatedAt                 (datetime_immutable)
- updatedByUser_id          (FK User)
- UNIQUE (tenant_id, key)

GeneratedPolicyDocument
- (No new entity — uses existing Document with new fields:)
- generatedFromTemplate_id  (FK PolicyTemplate, nullable)
- generatedFromWizardRun_id (FK WizardRun, nullable)
- substitutionVariables     (json — snapshot at generation time)
- isImmutable               (bool — true once status='approved'; blocks edits, forces new version)
```

### 4.2 Existing entities reused

- `Document` — every generated artefact is a Document. New fields
  added as listed above.
- `Tenant` — settings hierarchy uses existing `parent` + ancestor walk.
- `Control` (ISO 27001 Annex A / BSI-Anforderungen) — auto-linked via
  existing `ComplianceMapping` infrastructure. SoA derives applicability
  from existing entities; we just write the policy → control link.
- `BCPlan`, `BusinessProcess`, `CrisisTeam`, `BCExercise` — BCM wizard
  reuses these; never duplicates.
- `ProcessingActivity`, `DataSubjectRequest`, `DataBreach` — Privacy
  Policy cross-references these existing modules instead of duplicating.
- `Incident` — DORA Incident-Mgmt Policy auto-binds DORA classification
  thresholds to the existing Incident-SLA-Config.

### 4.3 No new entities for BCM

Per Phase 1-D: 13 BCM documents fit into existing `Document` rows.
The new `PolicyTemplate` table holds the recipe, not the output.

## 5. Service Layer

```
App\Service\PolicyWizard\
├── WizardOrchestrator        — public façade; takes WizardRun, runs steps
├── StepEvaluator             — picks next step based on standardsAdopted + previous answers
├── TemplateResolver          — given (tenant, standard, topic) returns the right PolicyTemplate row
├── VariableCollector         — pulls existing tenant data (DPO name, scope, BIA-tiers) so users don't re-type
├── DocumentGenerator         — substitutes variables, creates Document rows, applies tags + control links
├── HierarchyOverrideValidator — enforces overrideMode rules against parent settings
├── ApprovalKickoff           — dispatches WorkflowInstance for top-management sign-off
└── ReGenerationDetector      — diffs current vs last-published version on subsequent runs
```

Each service has a unit test (mock TemplateResolver / DocumentGenerator)
plus a kernel test exercising the public entry point.

## 6. Wizard Flow

7 steps in default mode plus targeted-re-run + sandbox modes (per
Phase 3 ISB / UX feedback).

### 6.1 Mode selector (gate before step 1)

```
Mode 1 · Full wizard          — first-time setup or major standards-change
Mode 2 · Targeted re-run      — pick specific topics (e.g. 3 policies after
                                a mid-year audit finding); skips most steps
Mode 3 · Sandbox preview      — render generated docs without persisting, for
                                CISO walkthrough or pre-audit dry-run.
                                WizardRun.status='sandbox', no Documents created.
```

### 6.2 Default 7-step flow (Mode 1)

```
Step 1 · Welcome + Standards
        - Pick: ISO27001 / BSI / DORA-addon / GDPR-scope / BCM-coverage
        - Show preview: "this run will generate N documents"
        - Inheritance preview: "Konzern enforces these N values; you can tighten 5."
        - Targeted re-run jump-off: "fix only specific policies?"

Step 2 · Organisation & Scope
        - Legal name, scope statement, address(es)
        - Sites in scope (multi-pick from existing Location entity)
        - Climate-change wording — HARDCODED ON for ISO 27001 selection
          (Amd. 1:2024 in force since Feb 2024). NOT a toggle. P1 — Auditor.

Step 3 · Roles & Responsibilities
        - CISO / ISB, DPO, BCM-Officer, IT-Operations-Lead
        - Crisis-Team composition (only if BCM selected) — 5-7 roles
        - Function-Owner designation per business-function (P1 Risk-Owner)
          # Heads of Sales / Operations / R&D / HR with sign-off path
          # in §9. PolicyTemplate.affectedFunctions drives the matrix.
        - Approval chain: top-management designation
        - Self-approval guard: wizard refuses author and approver to be
          the same user (P1 Junior — guard rail).

Step 4 · Risk & Classification
        - Risk appetite tier with EXPLICIT direction:
          1 = very conservative (lowest tolerated risk),
          5 = aggressive (highest tolerated risk).
          Default 2 for KRITIS / regulated; 3 for Mittelstand-default.
          P1 Junior — eliminates the "is 1 or 5 the safe end?" confusion.
        - Data classification scheme (3 vs 4 levels)
        - Schutzbedarf scheme (BSI-default 3 levels — only if BSI selected)
        - Annex A applicability (re-uses existing SoA; pre-fills from
          baselines if loaded). Industry-preset bundles surface here as
          one-click defaults (Phase 4-C output IndustryPresetBundle).
        - Review-interval HARD CAP at 24 months. Wizard refuses higher.
          P1 Junior — guard rail.

Step 5 · Operational Baselines
        - Crypto policy: allowed algorithms + key strengths
        - Backup: RPO target tier
        - Patch / vulnerability cadence (critical / high / medium SLA-h)
        - Continuity RTO targets per criticality tier (only if BCM)
        - DORA-specific block (only if DORA): entity type, significance,
          competent authority, ICT-third-party concentration thresholds.
          UX recommendation: split as Step 5a (general) + Step 5b (DORA)
          when DORA is enabled to avoid the "wizard within a wizard"
          feel.

Step 6 · Lifecycle & Cadence
        - Default review interval (12 mo recommended; max 24 mo)
        - Per-policy override (advanced, collapsible)
        - Approver designation per document
        - Auto-publish: NEVER (forced false)
        - Trigger Alva-Hint on next-due review (T-30d)

Step 7 · Review & Generate
        - Read-only summary of all settings
        - Hierarchy-conflict warnings (if any) blocking, with
          jump-to-conflict anchor + "request override from
          Konzern-CISO" escape valve (P1 UX)
        - Generate button — atomic transaction, all-or-nothing
        - Result page with document list, "Open Document" links, and
          ApprovalKickoff dispatch confirmation
        - Sandbox mode: render preview docs, list at top "this run
          was a sandbox; nothing was saved"
```

### 6.3 Targeted-re-run flow (Mode 2 — P1 ISB)

```
Step 1 · Pick topics                 — checklist of existing PolicyTemplate
                                       topics for this tenant. Up to 10.
Step 2 · Optional finding reference  — paste Audit-Finding ID; surfaces in
                                       audit log (P1 ISB: who triggered).
Step 3 · Diff preview                — show what changes vs current approved
                                       documents.
Step 4 · Generate                    — only the picked topics regenerate.

Skips Steps 2-6 of the default flow because settings are unchanged.
Only the picked subset of documents is touched. Approval cascade
applies only to changed sections.
```

### 6.4 Sandbox preview (Mode 3 — P1 UX)

Same UI as full wizard but `WizardRun.status='sandbox'`. The
DocumentGenerator runs in dry-mode: emits the rendered Markdown
into the WizardRun's `inputs.preview` JSON field, NEVER persists
Documents, NEVER updates SoA. Result page shows the would-be docs
with a banner "Sandbox preview — nothing saved. Re-run in Full mode
to commit." Sandbox runs are auto-purged after 7 days.

**State persistence:** every step writes to `WizardRun.inputs` so a
user can close the browser and resume. Cancel deletes the WizardRun
without touching `Document` or `TenantPolicySetting`.

## 7. Hierarchy Logic — mirrors Norm-Inheritance

The Konzern-Tochter pattern for tenant settings mirrors the existing
norm/framework-inheritance machinery so operators see the same model
for "this is set at Konzern level" / "Tochter override allowed".

### 7.1 Three-tier hierarchy (matches existing CorporateStructure)

```
Tier 1 — Konzern (parent tenant, ROLE_GROUP_CISO sets baseline)
Tier 2 — Tochter (sub-tenant, inherits + may override per setting)
Tier 3 — Tochter-of-Tochter (multi-level Konzern, walks ancestor chain)
```

Resolution order on read (`TenantPolicySettingResolver::resolve(key)`):

1. Walk `tenant.allAncestors()` from root downwards.
2. For each ancestor, take its setting if `overrideMode != 'free'`.
3. Apply own override if `overrideMode` allows.
4. Final value = max-strict of all tiers (matches the
   `PasswordPolicyResolver` floor-pattern already in the codebase —
   reuse the same floor-pattern code style).

### 7.2 Mirror to existing norm-inheritance

Where the existing `ComplianceFramework`/`ComplianceMapping` modules
already inherit from Konzern → Tochter (frameworks loaded at Konzern
level apply to all subsidiaries unless explicitly opted-out), the
PolicyTemplate selection follows the same rule:

- A `PolicyTemplate` linked to a framework that's loaded at Konzern
  level is automatically eligible for all subsidiaries.
- A subsidiary can mark a template as "skipped" (e.g. tochter does
  not process personal data → skip Privacy Policy) — but ONLY if the
  underlying framework's applicability scope at Konzern level allows
  it. Forced-applicable controls cannot be skipped.

This means: load ISO 27001 at Konzern, every subsidiary gets the 24
topic policies offered in their wizard. Konzern can pre-set 5 of
them via Konzern-Defaults; the remaining 19 are tochter-specific.

### 7.3 Override-mode matrix per setting key (sample — full list in code)

Mode names per ISB-Practitioner review (clearer than v1):

- `forbidden_to_change` — child cannot modify in any direction
- `forbidden_to_relax` — child can tighten, never loosen (most settings)
- `floor_only` — child value must be ≥ parent (e.g. crypto-key-length)
- `ceiling_only` — child value must be ≤ parent (e.g. review-interval-months)
- `free` — child fully autonomous

| Setting | Konzern level | Subsidiary override |
|---|---|---|
| Risk appetite tier | parent_max | ceiling_only (numerically lower = more conservative) |
| Backup RPO (hours) | parent_min | ceiling_only (smaller = stricter) |
| Review interval months | parent_max | ceiling_only (smaller = more frequent) |
| Cryptography minimum-key | parent_min | floor_only (longer key OK) |
| Crisis-team size | — | free (subsidiary may differ) |
| Approval-chain top-mgmt | parent_value | forbidden_to_change (one source of truth) |
| GDPR-scope flag | parent_value | forbidden_to_relax (child must keep, may extend) |

`HierarchyOverrideValidator` runs at every settings save and on
wizard's Step 7 review pass. Conflicts surface as blocking errors
with a copy-pasteable "ask Konzern-CISO to relax X" message.

### 7.4 Konzern-Defaults wizard variant + push-down trigger (P1 ISB+CISO)

Konzern-CISO has a separate "Konzern-Defaults" wizard variant that
sets baseline values; reads the existing tenant-tree and prompts
which subsidiaries to push the baseline down to.

**New: push-down trigger.** When the Konzern-Defaults wizard updates
a `TenantPolicySetting` value at parent level:

1. Resolver walks the descendant tenants.
2. For each descendant whose own setting is now ineffective per the
   override-mode matrix (e.g. parent raised crypto floor from 128 to
   256 — descendant's 192 violates `floor_only`), emit:
   - A blocking-state badge `settings_drift_detected` on that
     subsidiary's wizard landing page.
   - An Alva-Hint Tier-1 in the descendant's CISO inbox: "Konzern-
     CISO raised the crypto floor from 128 to 256 bit on
     <date>. Affected policies: 3. Re-run wizard now?"
   - A `KonzernPushDown` event in the audit log.
3. Descendant tenant runs Wizard Mode 2 (targeted re-run) on the
   affected policies; the Alva-Hint click pre-selects the affected
   topic list.

This closes the ISB-Practitioner gap "CISO raised crypto from
128→256 bit, propagate to 4 subsidiaries". Without push-down,
Konzern-Defaults is just a settings UI; with it, the cascade
becomes operational.

## 8. SoA + Control Integration — bidirectional

When the wizard generates a policy document, three things happen
synchronously inside the same DB transaction (atomic — either all
succeed or none persist):

### 8.1 Link policy → control entries (DocumentControlLink)

For each control listed in the template's `linkedAnnexAControls` /
`linkedBausteine` / `linkedDoraArticles`:

```
foreach (template.linkedAnnexAControls as controlRef) {
    $control = $controlRepository->findOneBy([
        'standard' => 'iso27001',
        'normRef' => $controlRef,
        'tenant' => $tenant,
    ]);
    if (!$control) continue;
    $existingLink = $documentControlLinkRepository->findOneBy([
        'document' => $document, 'control' => $control,
    ]);
    if (!$existingLink) {
        $entityManager->persist(new DocumentControlLink(
            $document, $control,
            source: 'policy_wizard',
            evidenceType: 'policy_document',
        ));
    }
}
```

Same logic applies to BSI Bausteine, DORA Articles, BCM clauses —
three more repository lookups, identical link-creation pattern.

### 8.2 Update the SoA — applicability + implementation status

The Statement of Applicability is the operative side of the same coin.
When a policy is generated, every control it links to gets its SoA
record updated:

```
foreach (template.linkedAnnexAControls as controlRef) {
    $control = $controlRepository->findOneBy([...]);
    if (!$control) continue;

    $soaEntry = $statementOfApplicabilityRepository->findOneBy([
        'tenant' => $tenant, 'control' => $control,
    ]);
    if (!$soaEntry) {
        // Policy presence implies the control is in scope.
        $soaEntry = new StatementOfApplicabilityEntry($tenant, $control);
        $soaEntry->setApplicable(true);
        $soaEntry->setApplicabilityReason('policy_wizard_generated_policy');
    }
    // Implementation status: bump only if currently lower.
    if ($soaEntry->getImplementationStatus() === null
        || $soaEntry->getImplementationStatus() === 'not_implemented'
        || $soaEntry->getImplementationStatus() === 'planned') {
        // Policy generation = "documented but not yet operating".
        $soaEntry->setImplementationStatus('partial_documented');
    }
    // Evidence link — always add.
    $soaEntry->addEvidenceDocument($document);
    // Justification snapshot — for audit trail.
    $soaEntry->setJustificationSnapshot(
        sprintf('Policy "%s" generated by Policy-Wizard run %d on %s',
            $document->getTitle(),
            $wizardRun->getId(),
            (new \DateTimeImmutable())->format('Y-m-d')
        )
    );
    $entityManager->persist($soaEntry);
}
```

Critical rules:

- **Never DOWNGRADE.** If a control was already `fully_implemented`
  (operative evidence + tested), the wizard does NOT push it back to
  `partial_documented`. Use a max-comparator.
- **Inheritance-aware.** When a Konzern-policy is generated and the
  same control is a Konzern-level applicability decision, the SoA
  update propagates to all in-scope subsidiaries' SoA entries (mirrors
  the existing `compliance_inheritance` propagation pattern).
- **Evidence multiplicity.** A control may have multiple policy
  documents linked (e.g. A.5.15 Access Control covered by both an
  Identity-Mgmt policy and an Access-Control policy). The SoA shows
  all of them.

### 8.3 Update Control entries — policy reference

The Control entity itself records which policies cover it (so the
inverse query "show me all policies for control X" is fast):

```
$control->addCoveringPolicyDocument($document);
```

This is a `ManyToMany` already implied by `DocumentControlLink`; no
new column needed. Read-side projections in
`ControlRepository::findWithCoveringPolicies()`.

### 8.4 Cascade through framework-inheritance

If the tenant inherits a control from a parent framework (existing
`ComplianceFramework` parent-link), the policy → control link is also
recorded against the inherited control instance. So a subsidiary
running the wizard sees the policy linked AT the Konzern-loaded
framework level too — driving Konzern-CISO's roll-up SoA dashboard.

### 8.5 Tagging for Audit-Trail

Every generated document gets these tags (existing `Tag` entity):

- `policy-wizard-generated` (audit-trail)
- `standard:<standard-code>` (e.g. `standard:iso27001`)
- `topic:<topic-key>` (e.g. `topic:access_control`)
- `version:<n>` (template version snapshot)
- (DORA only) `dora-validity:2025-01-17`
- `wizard-run:<run-id>` — links back to WizardRun for evidence

These drive the Audit-Auditor view "show me all DORA-touched
documents and the SoA controls they cover".

### 8.6 Document fields filled by generator

- `title` — substituted from template.titleTranslationKey
- `description` — first paragraph of body
- `content` — full body Markdown with all variables substituted
- `documentType` — from template.documentType
- `owner` — wizard-collected (CISO / DPO / BCM-Officer per topic)
- `approver` — wizard-collected
- `reviewIntervalMonths` — from template, may be overridden in Step 6
- `nextReviewDate` — generation date + reviewIntervalMonths
- `status` — `draft` (NEVER auto-publish, see §10)
- `tenant` — current tenant
- `language` — both DE and EN bodies generated; UI-language picks one
- `generatedFromTemplate` — FK to PolicyTemplate
- `generatedFromWizardRun` — FK to WizardRun
- `substitutionVariables` — snapshot

### 8.7 Translation strategy

Each PolicyTemplate body lives in versioned translation keys:

```
policy.iso27001.access_control.v1.title
policy.iso27001.access_control.v1.body
policy.iso27001.access_control.v1.section.purpose
policy.iso27001.access_control.v1.section.scope
...
```

When `PolicyTemplate.version` increments to `v2`, the wizard generator
uses `v2`-prefixed keys. Documents already approved keep referencing
`v1` keys forever (immutability — see §10).

## 9. Approval Workflow

Reuses the existing `Workflow` + `WorkflowInstance` machinery (no new
state machine). The flow is **per-tenant configurable** so a Konzern
can demand different approval chains than a small subsidiary.

### 9.1 Default pipeline

- Workflow type: `policy-approval`
- Steps (override-able per tenant):
  1. `prepared` (auto, by wizard)
  2. `ciso_review` (CISO / ISB)
  3. `dpo_cross_check` (only for Privacy / Personal-Data policies)
  4. `function_owner_review` (only when `PolicyTemplate.affectedFunctions`
     is non-empty — P1 Risk-Owner)
     # Heads-of-Function review-batch step. Each named function-owner
     # gets the policies in their domain queued in their own inbox.
     # They can ack, raise objection (sends back to CISO), or delegate.
  5. `top_mgmt_signoff` (ISO Cl. 5.2 mandatory for the top-level
     Information Security Policy; configurable for topic-specific
     policies — see §9.3)
  6. `published` (sets Document.status, isImmutable=true)

- Auto-progression triggers exist via the existing
  `WorkflowAutoProgressionService` — when an approver clicks "Approve"
  in the Document edit view, the next workflow step fires.

- Rejection sends Document back to `draft` and notifies the wizard-
  initiator user.

- DE-specific: works-council consultation gate for HR / Logging /
  Physical-Security policies before `top_mgmt_signoff` can fire.
  Configurable per tenant; default ON in DE locale.

### 9.2 Bulk-Approval for top-management

Top-management does NOT want to click "Approve" 25 times when a fresh
wizard run produces the full ISO+BSI+BCM+DORA bundle. Solution: a
**Bulk-Approval-Inbox** view filtered by `pending: top_mgmt_signoff`.

- Route: `/admin/policy-approval-inbox` (admin/manager-scoped).
- Lists every Document currently in step `top_mgmt_signoff` for the
  tenant, grouped by:
  - `wizard_run` (so the GF sees "the 25 docs from the
    Q3-2026 ISO-update wizard run")
  - or `standard` (so the GF sees "all 12 BCM docs together")
  - or `affected_function` (P1 Risk-Owner — so a Function-Owner can
    filter the inbox to "policies that touch HR")
- Per-row checkbox + "Approve selected" + "Reject selected" + a
  shared "rationale" textarea.
- Workflow-instance transition fires PER document but ONE audit-log
  group entry per batch ("Top-management approved 25 policies in
  bulk on 2026-09-30; rationale: …") AND per-document log entries
  guaranteed in addition to the batch reference (P1 Auditor + ISB
  granularity guarantee).
- Detail-view link per row for cases where the GF wants to dive in.

### 9.2.1 Audit-defangs (P1 Auditor — hardcoded defaults)

Four mandatory bulk-approval restrictions (cannot be turned off via
TenantApprovalConfig — they are hardcoded floor):

1. **Top-level Information Security Policy is EXCLUDED from bulk
   batches.** ISO Cl. 5.1 leadership-commitment evidence demands
   ceremonial individual sign-off. The Cl. 5.2 top-level policy
   always lands in its own approval flow.
2. **Dual-signoff DEFAULT-ON for regulated scope.** Tenants whose
   `tenant.regulated_scope` includes any of {DORA, NIS2, KRITIS,
   BaFin-supervised} get `bulk_approval_dual_signoff=true` enforced.
   Override requires SUPER_ADMIN + audit-log entry.
3. **Batch cap ≤10 documents.** Larger wizard-runs split into
   multiple batches, each individually approved. Prevents auditor
   challenge "GF rubber-stamped 47 docs in 3 minutes".
4. **Mandatory rationale ≥200 characters.** Empty / trivial rationales
   block submit. Encourages real engagement.

### 9.2.2 Function-Owner ack before bulk-approval (P1 Risk-Owner)

When the bulk batch contains policies whose `affectedFunctions` lists
business-functions, the affected Function-Owners must complete their
`function_owner_review` step BEFORE the batch can advance to
`top_mgmt_signoff`. The bulk inbox shows "5 of 25 policies await
Function-Owner sign-off — Sales / Operations / HR" as a blocking
warning. GF cannot batch-approve those 5 until the Function-Owners
have weighed in.

### 9.3 Configurability per tenant

A new `TenantApprovalConfig` (single row per tenant or stored as a
TenantPolicySetting under `policy.approval_config` key) carries:

| Setting | Default | Meaning |
|---|---|---|
| `topLevelPolicyApprovers` | `['ROLE_TOP_MGMT']` | ISO Cl. 5.2 mandates top-mgmt; override only via SUPER_ADMIN |
| `topicPolicyApprovers` | `['ROLE_CISO']` | most tenants: CISO sign-off enough for topic-policies |
| `topicPolicyEscalationToTopMgmt` | `false` | bigger Konzerne flip this to `true` |
| `dpoCrossCheckRequired` | `auto` (`true` for Privacy/PII templates) | force-on for healthcare etc. |
| `worksCouncilGate` | `auto` (DE locale) | DE-specific |
| `reviewWithoutChangesAutoCompletes` | `true` | see §9.4 |
| `bulkApprovalDualSignoff` | `false` | 4-eye for bulk |

Konzern-CISO can pre-set these at parent-level; subsidiaries inherit
and can only TIGHTEN (e.g. parent says CISO-only, subsidiary may
escalate to Top-Mgmt; subsidiary may NOT relax to "auto-publish").

### 9.4 Review without changes — fast-path

Annual-review CRON marks each Document as `review_due` 30d before the
next-review-date. The reviewer (default: CISO) opens the Document and
either:

- **No change needed** → click "Confirm review — no change required".
  System:
  1. Increments `Document.lastReviewedAt`.
  2. Recomputes `nextReviewDate` from cadence.
  3. Records a `review_no_change` log entry (signed by CISO).
  4. **Does NOT trigger the full approval pipeline.** GF stays out
     of the loop. Document remains `approved` and `isImmutable=true`.
  This matches §9.3 setting `reviewWithoutChangesAutoCompletes=true`.

- **Changes needed** → click "Edit". Document is cloned to a new
  draft (existing supersedes-link), the original stays approved and
  read-only, and the new draft enters the FULL approval pipeline
  (CISO → DPO-cross-check if applicable → Top-Mgmt → published).

If a tenant flips `reviewWithoutChangesAutoCompletes=false`, even
no-change reviews force a top-mgmt re-acknowledgement (some
heavily regulated sectors may want this).

### 9.5 Multi-document review batch

If 10 documents come due in the same week, the reviewer sees a
batched "Review-Inbox" mirroring the Bulk-Approval-Inbox: tick boxes,
"Confirm review — no change for selected" or "Mark as needs-update".
Audit-log entry batched but per-document state advances individually.

### 9.6 Approval-history surface

Document edit-view shows a vertical "Approval-Trail" widget:

```
2026-09-30 14:22  Wizard-Run #42 prepared draft v3
2026-09-30 14:30  CISO Anna L. approved
2026-09-30 15:01  DPO Bernd K. approved (Privacy cross-check)
2026-10-01 09:11  Top-Mgmt batch-approval (25 docs) — Carla G., signed
2026-10-01 09:11  Published v3 — supersedes v2
2027-09-15 06:00  Annual-review reminder fired (Alva-Hint)
2027-09-22 11:08  CISO Anna L. confirmed review — no change required
```

Reuses the existing `AuditLogger` infrastructure; just a templated
view over the existing log rows tagged `policy-approval`.

## 10. Versioning + Immutability

- `Document.isImmutable=true` once `status='approved'`. UI hides edit
  button. Force-edit requires SUPER_ADMIN + audit-log entry.
- Re-running the wizard after settings change:
  1. For each existing approved document of matching template+topic:
     - Compute hash of substitution-variables-now vs.
       Document.substitutionVariables.
     - If unchanged → skip.
     - If changed → create NEW Document, link to OLD via
       `Document.supersedes` field (existing). OLD stays approved
       and read-only.
  2. For each draft document: replace content, bump version metadata.
- Audit log entry per change.

## 11. Auditor-Trap Prevention (cross-cutting)

Every specialist flagged the same risk: auto-generated policies feel
"templated" and auditors push back. Mitigations:

1. **3 mandatory tailoring fields** per topic policy (BSI requirement,
   ISO best practice). Wizard refuses to mark `status=ready_for_review`
   until each field has tenant-specific text.
2. **Variable-substitution markers HIDDEN by default** (P1 Auditor
   override of v1): `{{ tenant.legal_name }}` substitutes to
   `MyCompany GmbH` and the original variable name does NOT appear in
   the rendered document. Auditors read leftover `{{ }}` markers as
   amateurish; visible variable names suggest "this came from a
   template machine". A separate machine-readable manifest
   (`document.substitutionVariables` JSON) records the source of each
   substitution for the audit trail. Optionally, an admin-only
   "show variable map" view annotates the rendered policy in-place
   for internal QA — but this view never reaches the auditor.
3. **No silent template updates**: when PolicyTemplate.version
   increments, all draft documents flagged for re-review explicitly.
4. **Exercise / evidence prompts**: BCM-Wizard auto-creates 12 months
   of `BCExercise` records (closes ISO 22301 Cl. 8.6 audit-trap).
   ISMS-Wizard auto-creates an Internal-Audit-Programme schedule.
5. **No auto-publish, ever.** Hard-coded.
6. **Generation-to-approval minimum elapsed time** (P1 Auditor): the
   earliest a Document can transition to `top_mgmt_signoff` is
   `generated_at + 24 hours` (configurable per tenant; default 24h
   for regulated scope, 4h otherwise). Prevents the "approved within
   seconds of generation" tell.
7. **Random sampling on render** (P1 Auditor): 1-in-10 generated
   documents get a post-substitution validator pass that checks for
   any leftover `{{ }}` markers. Validator failure blocks publish
   and emits an Alva-Hint to the wizard-initiator.
8. **PolicyAcknowledgement-coverage Alva-Hint** (P1 Auditor — closes
   A.6.3 NC): for every published policy with a non-empty
   `requiredAudience` list, an Alva-Hint surfaces when the
   acknowledgement-coverage drops below configurable threshold
   (default 95%).

## 12. Sector-Overlay & Edge Cases

- **Microenterprise (DORA Art. 16)** — simplified RMF; wizard forks at
  Step 1 if the user's entity-type indicates microenterprise scope.
  Generates 1 consolidated Risk-Mgmt-Framework document instead of 6.
- **Public-sector / KRITIS (BSI)** — BSIG §8a 2-year audit cadence;
  wizard sets `reviewIntervalMonths=12` (defensive default ≤ legal max
  of 24).
- **Cross-border subsidiaries** — `tenant.jurisdiction` field consulted;
  wizard generates EN version + locale-specific version. Konzern keeps
  master.
- **GDPR-only-tenant** — Privacy Policy + DPO Programme generated;
  rest of ISMS skipped. ISMS Top-Level Policy is OPTIONAL in this case.

## 13. Sprint Breakdown (indicative — refined in Phase 5)

```
Sprint W1 — Domain
  Entities: PolicyTemplate, WizardRun, TenantPolicySetting (no entity for
            GeneratedPolicyDocument — extend existing Document).
  Migrations (idempotent, isTransactional=false).
  Repositories + base service skeleton.
  Seed: ISO 27001 templates v1 (24 topics) only.

Sprint W2 — Wizard core (ISO 27001 only)
  WizardOrchestrator + 7 steps.
  HierarchyOverrideValidator with full matrix.
  Step persistence (resume after browser close).
  Tests: kernel + form + voter.

Sprint W3 — Document generation + SoA link
  DocumentGenerator service + variable substitution.
  Translation keys: policy.iso27001.*.v1.* for all 24 topics
                    (DE + EN, ~7000 keys total).
  ApprovalKickoff into existing Workflow.
  Re-generation detector + immutability enforcement.

Sprint W4 — BSI Grundschutz extension
  BSI templates v1 (28 + Schutzbedarf-Methodik).
  Schicht-aware step variants in Wizard (Step 4 + Step 5 add Schutzbedarf-
  scheme + Branche).
  Cross-mapping ISO ↔ BSI for dual-compliance tenants.

Sprint W5 — DORA addon + BCM
  DORA templates: 6 NEW + 18 EXTENDS (extends merge into existing
  ISO templates' "DORA-section" block).
  BCM templates: 12 ISO-based + 1 BSI-Notfallhandbuch.
  Auto-create 12 months of BCExercise records.
  Microenterprise fork in Step 1.

Sprint W6 — Polish + Persona-recommended UX
  Konzern-Defaults wizard variant.
  Re-run flow + diff UI.
  Compliance-Wizard check-types (BCM coverage).
  Alva-Hints (5 Tier-1/2 hints from BCM-Specialist's recommendation).
  Translation-quality sweep.
  Documentation + screenshots.
```

Total: **6 sprints**, ~3-4 weeks per sprint depending on team velocity.
The biggest risk is Sprint W3 (translation-key authoring of full DE+EN
policy bodies) — would benefit from professional legal-text review.

## 14. Open Questions for Phase 3 (Persona Review)

- **CISO-Executive:** Is the 7-step flow too long? Would they want to
  delegate Steps 4-5 to ISB Practitioner with sign-off only at Step 7?
- **Compliance-Manager:** Acceptable to start with ISO-only in Sprint
  W2 and add BSI/DORA/BCM later, or must all four ship together?
- **Senior-Consultant:** What customer-facing content (videos /
  examples / industry-presets) needed for usability?
- **Junior-Implementer:** What complexity barrier in Step 4 (Annex A
  applicability) — pre-filled baselines enough?
- **ISB-Practitioner:** How often do they re-run the wizard? Daily?
  Quarterly? Drives caching strategy.
- **External-Auditor:** What evidence trail is needed beyond what we
  capture (WizardRun + tags + tag-history + workflow-history)?
- **Risk-Owner-Business:** Are they ever in the wizard, or is this
  ISMS-team only?
- **UX-Specialist:** Is multi-step wizard pattern with side-bar
  preview right? Or should we use a single long-form with anchored
  ToC?
- **DPO-Specialist:** Privacy-Policy generation interplay with the
  existing ISO 27701 wizard — single source of truth or twin
  generation?

## 15. Open Risks

- **Translation authoring effort.** ~7000 DE+EN keys for ISO alone.
  Adding BSI/DORA/BCM doubles. Recommend: outsource to a legal-text
  agency for the canonical EN+DE versions of v1, then maintain
  internally.
- **Approval-chain modelling.** Some tenants want 4-eye top-management;
  workflow needs configurable parallel-approver pattern (existing
  WorkflowStep supports this — confirm at implementation).
- **Re-generation diff UX.** Showing what changed in a 30-page policy
  is non-trivial. Recommend: document-level diff (which sections
  changed) rather than character diff. Defer to Sprint W6.
- **DORA RTS-on-subcontracting** — final adoption pending. Templates
  marked "provisional"; need re-trigger after EU final approval.
- **BSI Edition drift** — BSI Grundschutz-Kompendium 2024 already
  hints at changes for 2025 (KI-spezifische Bausteine). Template
  versioning must track edition.

---

**Next step:** Phase 3 — six personas review this document in
parallel, raising specific objections / additions. Phase 4 lets the
four specialists rebut + refine.
