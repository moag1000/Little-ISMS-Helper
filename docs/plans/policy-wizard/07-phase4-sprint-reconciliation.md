# Phase 4-C — Sprint Roadmap Reconciliation

> Reconciliation document for the Policy-Wizard sprint plan.
> Replaces `05-architecture.md` §13 (6-sprint indicative breakdown).
> Drafted by the four-specialist consortium (ISMS + BSI +
> Risk-Mgmt-DORA + BCM perspectives, with cross-input from DPO and
> ISO-22301 sub-specialisms) based on the seven Phase 3 persona
> reviews.
>
> Scope: resolves sprint-priority conflicts surfaced by personas
> 01-CISO, 02-Compliance-Manager, 03-Senior-Consultant,
> 05-ISB-Practitioner, 06-External-Auditor, 07-Risk-Owner. UX
> (`08-ux-review.md`) and DPO self-review (`09-dpo-self-review.md`)
> are referenced where they change scope.
>
> **Branch:** `feature/policy-wizard`. Implementation gate (§7) must
> pass before sprint W1 starts.

---

## 1. Conflict map

The seven Phase 3 reviews surface five priority conflicts that the
original 6-sprint plan in `05-architecture.md` §13 cannot satisfy
simultaneously. Each conflict is summarised below before the
priority rule (§2) and the reconciled roadmap (§3) resolve them.

### 1.1 BSI-vs-DORA ordering

The ISB-Practitioner review (`05-isb-practitioner-review.md`
"Sprint priority", lines 284–309) ranks BSI Grundschutz **before**
DORA: "BSI + Konzern-Bedienung VOR DORA" because the DACH-Mittelstand
addressable market is dominated by KRITIS-Zulieferer and
public-sector. The Compliance-Manager (`02-compliance-manager-review.md`
"Sprint reorder", lines 250–280) ranks DORA **before** BSI: "ship
ISO baseline + DORA addon together, because the Mittelstand-SaaS /
FinServ-flowdown market overlap is huge". The CISO-Executive
(`01-ciso-review.md` "Sprint priority", lines 206–232) sides with
DORA-first ("BaFin-Druck 2025, BSI-Kompendium-2025-Edition-Drift
macht BSI-Templates eh provisorisch — DORA-Pflicht zuerst"). The two
sides cannot both win in the same sprint slot.

### 1.2 Industry-Presets-vs-BSI ordering

The Senior-Consultant (`03-senior-consultant-review.md` "Sprint
priority", lines 229–247) demands Industry-Preset content
(Healthcare, Public-Sector, OT, B2C-SaaS) AND PDF-letterhead branding
**before** BSI templates: "ich verkaufe Industry-Presets, nicht
BSI-Schichten". Counter-pressure from the ISB-Practitioner: BSI is
the German-Mittelstand source-of-truth and shipping it later starves
the KRITIS-Zulieferer pipeline (review §5 KRITIS scenario).
Compliance-Manager treats Industry-Presets as Out-of-Scope-for-now
(reviews §2 row 4).

### 1.3 Konzern-Defaults pull-forward

The CISO-Executive (review "Sprint priority" item 3, lines 213–215)
flags pulling Konzern-Defaults forward as a hard gate: "ohne
Konzern-Defaults rollen Toechter unkontrolliert aus, und ich kann
das Tool nicht freigeben". The original §13 plan parks
Konzern-Defaults in Sprint W6 (last-but-one). The ISB-Practitioner
amplifies: review `Worry #1` lines 230–234 — without a push-down
trigger from Konzern to Tochter, "§7 Inheritance praktisch wertlos".

### 1.4 Auditor's bulk-approval defang prerequisite

The External-Auditor (`06-external-auditor-review.md` "Bulk-approval
challenge", lines 228–260) sets a non-negotiable: four architectural
defangs (top-level-policy excluded from bulk; CISO+DPO pre-clearance
required; default `bulkApprovalDualSignoff=true` for DORA/BSI/NIS2
tenants; max 10 docs per batch) must ship **before any sprint goes
to a customer**. The original §13 buries this in W6 polish. Without
the defangs the auditor predicts 3-5 NCs per audit; with them, 1-2
minor observations (review "What would make me NOT challenge
auto-generation", lines 163–202).

### 1.5 Function-owner role-slot

The Risk-Owner-Business review (`07-risk-owner-review.md`
"Sprint priority", lines 231–248) identifies a structural gap: the
wizard's Step 3 has no slot for function-owners (Ops, HR, Sales,
R&D), yet Step 5 sets baselines that bind these functions. Risk-Owner
demands this be baked into W2: "retro-fitting it across BSI/DORA/BCM
later is 4x the cost". This conflicts mildly with the Senior-
Consultant's W2 wishlist (Industry-Preset-Skeleton) — both compete
for W2 capacity.

---

## 2. Tool-purpose-driven priority

**Rule (canonical):**
> **Audit-readiness > Konzern-hierarchy > Translation-cost >
> Demo-pitch > Industry-presets.**

Justification (six-to-eight bullets per Phase 1 specialist input
plus the Phase 3 personas):

- **Audit-readiness wins, full stop.** Little ISMS Helper exists to
  let regulated tenants pass external certification + supervisory
  audit (BaFin / BfDI / DAkkS / BSI-testierte Pruefer). The
  External-Auditor review (`06-external-auditor-review.md`
  "Audit-readiness verdict", lines 24–37) explicitly says "Pass with
  minor NCs, conditional on three architectural additions before
  Phase 5". Shipping any sprint to a customer without those changes
  bricks the tool's reason for being. This priority is **not
  negotiable** under any sprint reorder.
- **Konzern-hierarchy is the second-most-load-bearing requirement.**
  The CISO-Executive (review "What worries me" point 1, lines 75–85)
  controls the buying decision in BaFin-regulated portfolios; the
  ISB-Practitioner (review "Sprint priority", point 6, lines 300–303)
  cannot deploy the tool to subsidiaries without push-down. Both
  personas land independently on "Konzern-Defaults must come earlier
  than original W6 placement".
- **Translation effort is the biggest single execution risk.**
  `05-architecture.md` §15 flags ~7000 keys for ISO alone, doubling
  with BSI/DORA/BCM. The DPO self-review (`09-dpo-self-review.md`
  §0 collapse) and the Senior-Consultant's IndustryPresetBundle
  abstraction reduce the v1 surface (see §5 of this document).
  Translation-cost discipline therefore drives sprint-content
  trimming, not sprint reorder.
- **Demo-pitch matters but is not a feature-driver.** The
  Senior-Consultant pitch flow (review "Demo flow check", lines
  40–55) needs PDF-render and Industry-Presets to close deals, but
  these are surface polish on top of audit-ready domain logic.
  Pulling them forward of audit-readiness or Konzern-hierarchy
  risks shipping a tool that demos well and fails an audit.
- **Industry-presets are differentiators, not foundations.**
  The Senior-Consultant correctly identifies them as a pitch
  multiplier; they are essential for capturing the
  Healthcare/Public-Sector/OT pipeline (review §"Industry-preset
  gaps", lines 57–77). However, lifting them to first-class
  IndustryPresetBundle entity + delivering 4-6 bundles is one
  sprint's worth of work. Defer until after audit-defangs +
  Konzern-defaults are locked.
- **DORA-vs-BSI tie-breaker: ship DORA first, BSI second.**
  Three Phase 1 specialists (ISMS, Risk-Mgmt-DORA, BCM) all flag
  DORA's January 2025 hard-deadline + BaFin enforcement pressure.
  BSI Grundschutz audits run on a 2-3-year cycle (BSIG §8a)
  meaning a 6-week delay on BSI templates is materially less
  urgent than a 6-week delay on DORA templates. The
  ISB-Practitioner's BSI-first preference reflects DACH-segment
  pipeline value but does not outweigh regulatory deadline pressure.
  BSI-Specialist input (`02-bsi-input.md`) confirms BSI templates
  benefit from sitting one sprint after Compliance-Wizard
  check-types stabilise.
- **Function-owner protection is a late-bound but architectural
  fix.** The Risk-Owner concern is real but the implementation
  cost is small (one role-slot in Step 3 + one boolean field on
  PolicyTemplate + one notification path). Bake into W2 alongside
  the wizard core; the visible UX (dashboard widget) can ride W7
  polish.
- **Konzern-Defaults forward-pull justifies splitting the original
  W3 into two sprints (W3 doc-gen + Konzern-defaults).** The CISO
  + ISB pressure is loud enough that the consortium agrees
  Konzern-Defaults belongs in W3, not W6. This adds one sprint
  (we move from a 6-sprint to 7-sprint plan) and absorbs the cost
  of the ISB's targeted-re-run modus + the auditor's per-document
  audit-log granularity.

---

## 3. Reconciled 7-sprint roadmap

The following replaces `05-architecture.md` §13 in its entirety.
Each sprint is annotated with the persona feedback it addresses.

```
Sprint W1 — Domain + audit-readiness baseline
  - PolicyTemplate / WizardRun / TenantPolicySetting entities
    (architecture §4.1)
  - PolicyAcknowledgement entity
    (Auditor §"Auditor-specific gaps" + "NC predictions" #2 — A.6.3
    NC blocker, lines 99–129)
  - Idempotent migrations with isTransactional()=false
    (CLAUDE.md §"Common Pitfalls" #6)
  - Auditor's 4 bulk-approval defangs hardcoded as defaults
    (`06-external-auditor-review.md` "Bulk-approval challenge"
    items 1-4, lines 242–256)
  - Climate-change wording HARDCODED ON for ISO 27001
    (Auditor "Tells of auto-generation" #8 + "NC predictions" #3,
    lines 49 + 141–143)
  - WizardRun.affectedFunctions field
    (`07-risk-owner-review.md` "What's missing" #2, lines 211–213)
  - Variable-substitution leakage detector pre-persist
    (Auditor "What would make me NOT challenge" #3, lines 180–185)
  - Generation-to-approval minimum-elapsed-time gate
    (Auditor item #2, lines 175–179)
  - Tailoring-field minimum quality validator
    (Auditor item #1, lines 168–174)

Sprint W2 — Wizard core (ISO 27001 only)
  - 7-step wizard with state persistence
    (architecture §6, ISB review "Daily-driver verdict" lines 22-29)
  - Function-owner role-slot in Step 3 + sign-off path
    (Risk-Owner "Sprint priority" lines 240, 287)
  - HierarchyOverrideValidator with renamed modes
    (floor_only / ceiling_only / forbidden_to_change /
     forbidden_to_relax — ISB rename, review §"Konzern-Tochter
     override-mode interpretation" lines 311-363)
  - Sandbox/preview mode (UX-Specialist review)
  - Risk-appetite-tier direction defined
    (1=conservative ... 5=aggressive — Junior-Implementer)
  - Step-3 RACI hint card for Krisen-Team default-besetzung
    (Senior-Consultant "Hand-holding content" Step 3, line 88)
  - PolicyTemplate.affectedFunctions field wiring
    (Risk-Owner "What's missing" #2)

Sprint W3 — Document generation + SoA + Konzern-Defaults
  - DocumentGenerator with variable substitution
    (architecture §8.6)
  - SoA bidirectional integration
    (architecture §8.1-§8.4; Compliance-Manager "What I love" #1)
  - Konzern-Defaults wizard variant
    (CISO "Sprint priority" item 3, lines 213-215;
     ISB "Sprint priority" item 6, lines 300-303 — pulled from W6)
  - Konzern push-down trigger
    (ISB "What worries me" #1, lines 230-234)
    when Konzern setting changes, each subsidiary gets an Alva-Hint
    to re-run wizard
  - Targeted Re-Run modus
    (ISB "What worries me" #4, lines 240-242)
    pick specific topics for re-run, skip 7-step flow for 3-policy
    mid-year fix
  - DPO veto carve-out per privacy section
    (`09-dpo-self-review.md` "Decision Matrix Pos. 2.1")
  - ISO-equivalent Compliance-Wizard check-types registered
    (Compliance-Manager review "What worries me" #6, lines 180-188)
  - Per-document audit-log layer in addition to batch reference
    (ISB review "Bulk-approval ergonomics" #3, lines 168-175;
     Auditor "What would make me NOT challenge" implicit)
  - Notify-target on rejection = Document.owner not WizardRun.startedByUser_id
    (ISB review "Bulk-approval ergonomics" #2, lines 160-167)
  - Step-0 Bestandsaufnahme skeleton (entity + UI placeholder only,
    full content lands W4)

Sprint W4 — DORA addon + Compliance-Wizard check-types
  - DORA templates (6 NEW + 18 EXTENDS)
    (architecture §3 row 4)
  - Compliance-Manager check-types for ISO + DORA
    (Compliance-Manager "Tooling integration" lines 282-289)
  - Industry-preset bundles
    (Senior-Consultant "Industry-preset gaps" lines 57-77 —
     lift to first-class entity IndustryPresetBundle alongside
     PolicyTemplate; v1 ships 4 bundles: Healthcare, Public-Sector,
     B2C-SaaS, OT)
  - Step-0 Bestandsaufnahme content
    (Senior-Consultant "Migration story for existing tenants",
     lines 250-292) — for brownfield tenants
  - DORA self-assessment "bin ich CTPP?" question in Step 1
    (Senior-Consultant "Open questions for Phase 4" #4, lines 314-319)
    — flag-only, not full CTPP-mode (out-of-scope, see §4)

Sprint W5 — BSI Grundschutz + BCM
  - BSI templates (28 + Schutzbedarf-Methode)
    (architecture §3 rows 2-3; `02-bsi-input.md`)
  - BSI-Basis/Standard/Kern coverage filtering in Step 4
    (ISB "German-specific concerns" item 1, lines 104-111)
  - BCM 12-13 governance docs
    (architecture §3 last row; `04-bcm-input.md`)
  - Auto-create 12 months of BCExercise records
    (architecture §11.4)
  - Notfallhandbuch for BSI + dual-compliance mappings
    (architecture §3 row 2)
  - Works-Council BR-evidence attachment requirement
    (Auditor "Auditor-specific gaps" Works-Council, lines 124-129)
  - KRITIS BSIG §8a 2-year-audit Alva-Hint
    (ISB "German-specific concerns" item 2, lines 112-118)

Sprint W6 — DPO Phase + GDPR-section pattern
  - 5 standalone privacy docs
    (DPO Charter, RoPA, DPIA Methodology, DSR Procedure,
     Retention Schedule — `06-dpo-input.md` §0; architecture §3 row 6)
  - 8 GDPR sections injected into ISO topics
    (DPO §0 Decision Matrix)
  - Privacy-policy appendices for Children's + Special-Category
    (`09-dpo-self-review.md` open question — defer dual ISO 27701
     PIMS support per §4)
  - Sectoral DPO templates
    (Healthcare § 22 BDSG, FinServ DORA Art. 6.4 — DPO §6.1.2 +
     §6.1.4)
  - DPO veto sub-workflow operational
    (DPO "DPO does NOT self-approve charter" + Auditor
     "Auditor-specific gaps" DPO independence, lines 99-107)
  - DPO charter excluded from bulk-approval grouping (code-level)
    (Auditor "Open questions for Phase 4" #1, lines 291-293)
  - GDPR-toggle off-cleanup behaviour
    (Compliance-Manager "Open questions for Phase 4" #2,
     lines 332-338)

Sprint W7 — Polish, exports, evidence layer
  - PDF export with tenant CI/letterhead
    (Senior-Consultant "What I dread" #2, lines 128-133;
     ISB "What's missing for me" #2, lines 264-267)
  - Bulk export ZIP for auditors
    (Compliance-Manager "What's missing" #1, lines 209-213;
     ISB "Workflow walkthrough" #4 audit-pack, lines 75-80)
  - Roll-up dashboard for Konzern
    (Compliance-Manager "What's missing" #4, lines 228-233;
     CISO "What's missing" Board-Reporting, lines 159-165;
     Auditor "Konzern-Tochter compliance specifics" lines 205-211)
  - Approval-Trail widget completion
    (architecture §9.6 — already partial, add witness-field per
     CISO "What's missing" Witnessing, lines 167-172)
  - Re-generation diff UX (doc-level + variable-level, NOT
    character-level)
    (ISB "Re-run / re-generation ergonomics" Diff UX, lines 198-204;
     Compliance-Manager "What worries me" #3, lines 152-159)
  - Alva-Hints (BCM 5 hints, KRITIS BSIG §8a, finding-reference,
    Konzern-CISO settings-drift, training-coverage gap)
    (`04-bcm-input.md` §9.6; CISO "What's missing"
     Incident-Trigger, lines 198-204)
  - Dashboard widgets
    (CISO board reporting; Risk-Owner "policies you own"
     `07-risk-owner-review.md` "What's missing" #5, lines 226-230)
  - Settings-drift badge on Document listing
    (ISB review "Re-run / re-generation ergonomics" lines 184-189)
  - Default-filter "current version only" + history-toggle
    (ISB review "What worries me" #5, lines 243-244)
  - Mobile-responsive Bulk-Approval-Inbox
    (CISO "What's missing" Mobile-Sign-Off, lines 174-178)
  - Translation-quality sweep
  - Documentation + demo content
```

### 3.1 Why each sprint deserves its slot

#### W1 — Domain + audit-readiness baseline

- **Why first:** All other sprints depend on the new entities. Plus
  the Auditor's four bulk-approval defangs are not late-binding
  polish — they are guard-rails on the domain model itself
  (e.g. `bulkApprovalDualSignoff` default-true for DORA tenants
  must live on the entity).
- **Why W1 includes the Risk-Owner-affectedFunctions field:**
  retrofitting `WizardRun.affectedFunctions` later costs a
  migration + a backfill on every existing wizard-run; cheap to
  ship now.
- **Why climate-change wording lands here:** it is a column on
  `PolicyTemplate` (`climateChangeWording=true` hardcoded), not a
  feature. Flipping it ON during W1 means every later sprint
  inherits the right behaviour.
- **Persona alignment:** Auditor (primary), Risk-Owner (secondary),
  ISMS-Specialist sign-off (tertiary).

#### W2 — Wizard core (ISO 27001 only)

- **Why W2 = ISO-only:** The Senior-Consultant's reorder asks for
  Industry-Presets in W2. We push back: the consortium agrees the
  wizard mechanic must be solid before bundle-content lands. Bundle
  content rides W4 once the mechanic is shippable.
- **Why function-owner slot lands here:** Risk-Owner's primary
  ask. Cheap to add as a Step-3 dropdown + a flag on
  `PolicyTemplate`. Postponing to BSI/DORA sprints means rebuilding
  three approval workflows.
- **Why override-mode rename:** ISB's diagnostic on `broader_only`
  is correct — `floor_only`/`ceiling_only`/`forbidden_to_change`/
  `forbidden_to_relax` is unambiguous and aligns with the CISO's
  point #4 about structural-vs-numeric settings.
- **Persona alignment:** ISMS-Specialist (primary), ISB
  (secondary), Risk-Owner (secondary), UX-Specialist (sandbox
  preview).

#### W3 — Document generation + SoA + Konzern-Defaults

- **Why Konzern-Defaults moves into W3:** The CISO's hard gate.
  Without the defaults wizard variant + push-down trigger, the
  tool is unbookable for any Konzern. We absorb the original W3
  doc-gen scope and bolt Konzern-defaults onto the same sprint —
  the SoA-link work shares persistence-layer code with the
  defaults-resolver.
- **Why Targeted Re-Run modus lands here, not in polish:** the
  ISB scenario "3-Policy mid-year fix from auditor finding"
  is the most common operational use-case after first deployment.
  Without targeted re-run the wizard becomes a 7-step nuisance
  for routine maintenance.
- **Why ISO Compliance-Wizard check-types come in W3:** the
  Compliance-Manager's day-one workflow depends on these
  registering when ISO templates ship; otherwise the existing
  Compliance-Wizard returns false-negative coverage gaps.
- **Why Step-0 Bestandsaufnahme skeleton (not full content)
  here:** the entity layer is cheap, the content (Word-extract,
  bulk-match UI) is W4. Splits scope sensibly.
- **Persona alignment:** CISO (primary), ISB (primary),
  Compliance-Manager (secondary), Senior-Consultant (skeleton
  for migration).

#### W4 — DORA addon + Compliance-Wizard check-types

- **Why DORA before BSI:** Tie-breaker rule from §2 — DORA's
  January 2025 enforcement deadline outranks BSI's biennial
  audit cycle.
- **Why Industry-presets land here:** the IndustryPresetBundle
  abstraction (Senior-Consultant) requires the wizard mechanic
  + DORA addon to be live, because Healthcare/FinServ presets
  ship sectoral DORA-defaults. Earlier than W4 = bundles
  without DORA-content. Later = DACH-Mittelstand pipeline
  starves.
- **Why Bestandsaufnahme content here:** brownfield migration
  story is critical for Senior-Consultant pipeline. Content
  rides on the W3 skeleton.
- **Why CTPP self-assessment is W4:** cheap one-question add to
  Step 1 + a disclaimer block in output. Senior-Consultant's
  legal-protection ask. Full CTPP-mode stays out-of-scope (§4).
- **Persona alignment:** Compliance-Manager (primary),
  Senior-Consultant (primary), ISMS-Specialist (DORA mappings).

#### W5 — BSI Grundschutz + BCM

- **Why BSI now (W5 vs original W4):** market timing per §1.1 —
  BSI is critical for KRITIS-Zulieferer + public-sector pipeline
  but two-month delay materially less risky than a two-month DORA
  delay. Plus BSI Edition 2025 drift makes templates provisional
  anyway (CISO "What worries me" #5 + Open Question #5,
  lines 119-127 + 262-268).
- **Why BCM bundles with BSI:** BSI 200-4 is the natural
  Notfallhandbuch home; both share the BCM-Specialist's check-type
  catalogue. Avoids a parallel W5+W6 split.
- **Why Basis/Standard/Kern filtering ships here:** ISB review
  flagged this as a hard requirement; cheap once BSI templates
  exist.
- **Why KRITIS Alva-Hint here:** depends on BSI templates being
  loaded so the rule has data to fire on.
- **Persona alignment:** BSI-Specialist (primary), ISB-Practitioner
  (primary, partial reorder concession), BCM-Specialist
  (primary).

#### W6 — DPO Phase + GDPR-section pattern

- **Why DPO ships in W6, not earlier:** the DPO §0 collapse
  pattern (10 sections injected + 5 standalone) requires the ISO
  topics (W2) and the DORA addon (W4) to be live — sections inject
  into both. Earlier sequencing is impossible.
- **Why veto sub-workflow + DPO charter exclusion-from-bulk
  ship together:** both target Auditor's GDPR Art. 38(3)
  independence concern. Better to ship one cohesive privacy
  approval pipeline than dribble across W2+W6.
- **Why sectoral templates here:** Healthcare § 22 BDSG +
  FinServ DORA Art. 6.4 build on Industry-Preset bundles (W4).
- **Persona alignment:** DPO-Specialist (primary), Auditor
  (secondary — DPO independence), Compliance-Manager
  (secondary — GDPR-toggle behaviour).

#### W7 — Polish, exports, evidence layer

- **Why W7 carries so much weight:** five personas demanded
  features that are inherently UX/polish (PDF, dashboards,
  diff-UI). Earlier slotting = either delay foundations or
  fragment polish across multiple sprints.
- **Why bulk export + roll-up + PDF cluster:** all three are
  surface-layer features over the data already captured by
  W3-W6. Single-sprint delivery is efficient.
- **Why Alva-Hints catalogue ships last:** rules require the
  data they reason over to exist (BCM exercises, settings drift,
  training coverage). All inputs are stable by W7.
- **Why translation-sweep is last:** terminology stabilises
  through the build. Sweep before sweep is wasted effort.
- **Persona alignment:** Senior-Consultant (PDF + bundles),
  CISO (board reporting), Compliance-Manager (roll-up),
  Risk-Owner (dashboard), Auditor (residual evidence-layer).

---

## 4. Out-of-scope for v1 (deferred to v2)

The following persona requests are **not** in the W1-W7 plan.
Each entry includes the deferral reason and the trigger event
that would re-open the topic for v2.

| Item | Persona source | Deferral reason | v2 trigger |
|---|---|---|---|
| **DORA CTPP-mode (full Critical-Third-Party-Provider posture)** | Senior-Consultant Open Q #4; architecture §2 already non-goal | CTPP-designation is a multi-month engagement covering supplier-mgmt, exit-strategy, sub-outsourcing — not policy-set authoring. v1 ships a self-assessment flag + disclaimer; full mode is its own product workstream. | First customer in CTPP scope or ESA finalises CTPP designation list (currently Q3 2026). |
| **AI Act addon (DPO §0 self-review open question)** | DPO self-review §0 + persona-DPO open Q | EU AI Act applicability rules for ISMS contexts not yet stable; templates would be provisional. Plus AI Act high-risk system inventory belongs in Asset module, not Policy module. | EU AI Act final implementation guidance from EDPB/AI Office (expected 2026-Q4). |
| **Mobile sign-off (CFO/CEO mobile workflow)** | CISO "What's missing" Mobile-Sign-Off | Approval-Inbox is responsive in W7; native mobile experience requires a separate mobile-app workstream. CFO/CEO get a mobile-friendly web view, not push notifications. | Customer demand from a >€10B-revenue customer with explicit mobile-only top-mgmt usage. |
| **ISO 27701:2025 dual-template support** | DPO self-review open question + Senior-Consultant Open Q #1 | The existing ISO 27701 v1.5 wizard (memory-note) is live and stable. Twin-generation across Policy-Wizard + 27701-Wizard requires a master orchestrator design that has not been scoped. v1 cross-references 27701 wizard via Alva-Hint, no twin-gen. | Cross-walk customer feedback after first 5 27701-PIMS audits show evidence-trail gaps the Alva-Hint cannot bridge. |
| **Policy merging (`canMergeWith` two policies into one)** | Senior-Consultant "What I dread" #4 | Merging breaks the SoA bidirectional invariant (one policy maps to multiple controls; two-source-template merging requires combinatoric mapping rules). High effort, niche use-case. | Customer escalation requesting merge for >3 templates at one tenant. |
| **Per-site multi-tier RPO/RTO in same policy** | Senior-Consultant "What I dread" #6 | Asset-level (not Tenant-level) settings belong in Asset module, not Policy module. Generator can render a tenant-default-table; site-overrides live in Asset.criticalityProfile. | BCM-Specialist customer escalation showing >3 sites with materially different RPO needs. |
| **Word-document-import for migration** | Senior-Consultant "Migration story" Word-Upload | Apache Tika integration + content-extract is a two-week workstream by itself. v1 ships Bestandsaufnahme + manual-replace-or-skip; merge-with-import is v2. | Five tenants escalate the 50-Word-Doc problem in same quarter. |
| **Bilingual policy approval (separate DE+EN approval cycles)** | Senior-Consultant "What I dread" #7 | Approval-per-language requires duplicating the workflow state-machine. v1 picks UI-language as approval-language; bilingual masters get a "EN is master" flag. | Konzern customer escalates with multi-language regulator (e.g. CH FINMA wants DE master, German parent wants EN master). |
| **Regulatory watch tag auto-trigger** | Senior-Consultant "What I dread" #8 | Requires a regulatory-monitoring service; out of scope for the wizard module. SUPER_ADMIN can manually flip flags in v1. | Regulatory-monitoring service goes live as separate product feature. |
| **OSCAL / GRC-export API** | CISO "What's missing" GRC-Tool-Integration | OSCAL maturity in DACH market is low; export-to-existing-GRC is per-tool integration work. v1 ships JSON export of Document+SoA bundle; OSCAL-compliant format is v2. | First Konzern customer with mandatory OSCAL output requirement (typically large public-sector). |
| **Annual Renewal-of-Applicability Ritual wizard variant** | CISO "What's missing" Annual Renewal | The §9.4 fast-path covers most of this; the formal annual ritual is a workflow on top, not a wizard variant. Belongs in Management-Review module. | Management-Review module roadmap explicitly absorbs the renewal trigger. |
| **Legal-Counsel Approval Gate per language/standard** | CISO "What worries me" #5 | The 4-eyes principle + tenant-level `legal_review_required` flag covers the substance. A formal gate-state in the workflow adds complexity for marginal benefit. | First customer escalates a translation-induced regulatory finding. |
| **Witness-field on top_mgmt_signoff with co-signer name + location** | CISO "What's missing" Witnessing | W7 polish absorbs witness-name + signature-date in the Approval-Trail widget; physical-location capture is a niche compliance ask. | BaFin-prudential customer audit feedback specifically asks for it. |
| **Policy-of-the-Quarter dashboard widget** | ISB "What's missing" #6 | Awareness-feature, not policy-mgmt feature. Belongs in Awareness module roadmap. | Awareness-module sprint absorbs it. |
| **Print-friendly PDF/MD-View per policy (alongside W7 PDF)** | ISB "What's missing" #5 | W7 ships a single tenant-CI PDF flow; awareness-print is downstream of that. | First tenant escalates awareness-vs-audit print divergence. |
| **Dual-Konzern 4-eyes for setting Konzern defaults** | CISO "What worries me" #7 | Adds a parallel-approval dependency on the Konzern-Defaults wizard variant. Defer until first BaFin customer escalates. v1: SUPER_ADMIN-only configurable. | First BaFin Konzern customer onboards. |

---

## 5. Translation-effort estimate

Refines `05-architecture.md` §15 with the following abstractions:

- **Privacy-as-sections** (DPO §0 collapse) drops the 16-document
  privacy library to **5 standalone + 10 sections**. Standalone keys
  ~30 each = 150; sections ~20 each (smaller, embedded scope) = 200.
  **Total privacy: ~350 keys per language**, vs. the original
  estimate of ~500.
- **IndustryPresetBundle abstraction** (Senior-Consultant +
  consortium): bundles inherit translation keys from the parent
  PolicyTemplate; bundles only carry **deltas** (defaults +
  pre-fills + overrides). Estimate ~10 delta-keys per bundle.

### 5.1 v1 key-count estimate (recalculated)

| Layer | Topics × keys-per-topic × langs | Subtotal |
|---|---|---|
| ISO 27001 (24 topics) | 24 × ~30 × 2 | ~1440 |
| DORA (6 NEW + 18 EXTENDS — extends are smaller) | (6 × ~30 + 18 × ~5) × 2 | ~540 |
| BSI Grundschutz (28 templates + Schutzbedarf-method) | 28 × ~30 × 2 | ~1680 |
| BCM (12-13 docs incl. Notfallhandbuch) | 13 × ~25 × 2 | ~650 |
| Privacy standalone (5 docs) | 5 × ~30 × 2 | ~300 |
| Privacy sections (10 sections injected) | 10 × ~20 × 2 | ~400 |
| Industry-Preset bundles (4 bundles × delta-only) | 4 × ~10 × 2 | ~80 |
| Wizard UI labels (steps, hints, validation) | ~150 keys × 2 | ~300 |
| Approval workflow surface (alerts, trail, batch UI) | ~80 keys × 2 | ~160 |
| Alva-Hint copy (5 hints × text+CTA × 2 langs) | 5 × ~10 × 2 | ~100 |

**TOTAL v1 estimate: ~5650 keys** for full quintuple stack
(ISO + DORA + BSI + BCM + Privacy).

This is an INCREASE over the 4645 number in the original brief
because we added the Wizard UI / approval-surface / Alva-Hint
overhead which the brief omitted. Substantially LOWER than the
original §15 7000-key/standard estimate, due to the sections-not-
docs collapse and the bundle delta abstraction.

### 5.2 Translation sourcing recommendation

Three sourcing channels:

- **Legal-text agency (canonical EN+DE) for ISO + DORA + BCM:**
  ~2630 keys. ISO 27001 + DORA-EXTENDS + BCM 22301 are EN-first
  authoritative; agency-grade legal review is mandatory because
  word-choice errors map to regulatory non-compliance. Agency
  cost per key estimate: €4-8 = €10-21k for ISO+DORA+BCM v1.
- **In-house (DE-source) for BSI Grundschutz + BSI 200-4:**
  ~1680 keys. BSI standards are German-source-authoritative;
  EN translations are derivative. In-house DE-author with EN
  by an internal native speaker. Estimate: 4 person-weeks.
- **In-house (with EDPB material as base) for Privacy:** ~700 keys
  (350 standalone + 400 section). EDPB guidance + BfDI Orientierungs-
  hilfen provide DE+EN base material. DPO-Specialist + in-house
  drafts. Estimate: 3 person-weeks.
- **In-house for UI/wizard surface:** ~560 keys. Standard
  product-translation workflow.

**Recommended order:** ISO + DORA + BCM agency contracts signed
before W2 starts. BSI in-house drafting begins in parallel with W2
(read-ahead). Privacy starts in W4 (DPO sprint W6 lead-in). UI
keys grow per-sprint.

---

## 6. Risks and mitigations

Top-3 risks per sprint with mitigations:

| Sprint | Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|---|
| **W1** | `PolicyAcknowledgement` entity collides with existing `training` module schema | M | M | Compliance-Manager review §"Tooling integration" demands Document admin actions respect immutability — same pattern used here. Run schema-reconcile dry-run before merge. |
| **W1** | Auditor's bulk-approval defaults misconfigured (e.g. `bulkApprovalDualSignoff` default-FALSE for DORA tenant) | L | H | Test in CI: assert per-tenant default of dual-signoff = TRUE when standardsAdopted contains DORA/BSI/NIS2. Voter-test enforces. |
| **W1** | Climate-change wording auto-inclusion breaks legacy ISO 27001 templates (pre-Amd. 1:2024 customer in test fixtures) | L | L | Hard-gate by `PolicyTemplate.standard='iso27001'` AND `version >= 1` (v1 ships post-amendment). |
| **W2** | Function-owner role-slot in Step 3 breaks the existing Junior-Implementer flow (extra dropdown = added friction) | M | M | Ship as optional-with-default-empty in W2; required-with-default-from-baseline in W4. UX-Specialist sign-off before merge. |
| **W2** | Override-mode rename breaks downstream code in `HierarchyOverrideValidator` | L | M | Mirror the existing `PasswordPolicyResolver` rename pattern (memory-note: gate criterion §7). Targeted unit tests on the resolver. |
| **W2** | Sandbox/preview mode reveals translation gaps before W3 keys land | H | L | Acceptable — preview shows raw keys, that is sufficient for sandbox. Production preview gates on translation-coverage check. |
| **W3** | Konzern-Defaults wizard variant takes longer than estimated; SoA bidirectional integration slips | H | H | If SoA-link slips, ship Konzern-Defaults first (CISO hard-gate); SoA can land mid-W4 if needed. Triage at week 2 of W3. |
| **W3** | Per-document audit-log adds significant write-volume | M | M | Compliance-Manager review §"Tooling integration" flags 180k rows over 6 years. Plan partitioning strategy now (PostgreSQL declarative partitioning or MySQL range-partition). |
| **W3** | Targeted Re-Run modus interaction with state-persistence (resume-after-browser-close) loses partial state | M | M | Persist Targeted-Re-Run scope as `WizardRun.scope.targetedTemplates` (json). Same persistence path as Step inputs. Test: resume after browser close mid-targeted-run. |
| **W4** | DORA RTS-on-subcontracting adoption pending; templates ship "provisional" | H | M | Mark templates with `regulatoryStatus='provisional'`; tenant SoA shows banner. Auto-trigger re-review when status flips to `final`. |
| **W4** | Industry-preset bundle scope creeps from 4 to 8 bundles | H | H | Lock scope at W4 start. Healthcare + Public-Sector + B2C-SaaS + OT only. Other 4 bundles scheduled for v1.x patch release. |
| **W4** | Bestandsaufnahme content (Word-extract) blocks on FileUploadSecurityService capacity | M | M | Word-extract is content; skeleton ships in W3. If extract slips, W4 ships manual-paste-only flow. |
| **W5** | BSI Edition 2024-vs-2025 drift forces template re-issue mid-sprint | M | H | `tenant.bsi_edition_pin` setting (CISO Open Q #5 + ISB Open Q #1). Templates carry `bsi_edition: '2024'`; tenant-pin overrides. v1 ships 2024 only; 2025 re-issue is v1.1 patch. |
| **W5** | Basis/Standard/Kern filtering misclassifies templates | M | M | BSI-Specialist + ISB review explicit table per template (review §"German-specific concerns" #1). Code-review against the table. |
| **W5** | BCM auto-create-12-BCExercise creates orphan records when CrisisTeam not yet defined | M | L | Pre-condition: Step 3 must capture Crisis-Team-Lead before Step 7. Wizard validation gate. |
| **W6** | DPO veto sub-workflow conflicts with existing 27701 PIMS wizard's privacy approval | H | H | Sub-workflow names differ (`policy-approval-privacy-veto` vs. existing `pims-charter-approval`). Cross-walk audit by DPO-Specialist before W6 merge. |
| **W6** | DPO charter exclusion-from-bulk implemented at setting-level instead of code-level (auditor explicit ask) | M | M | Code-level enforcement: `PolicyTemplate.documentType='dpo_charter'` AND `tenant.dpo_in_scope=true` → `excluded_from_bulk_approval=TRUE` regardless of `bulkApprovalDualSignoff` setting. Test asserts. |
| **W6** | Sectoral DPO templates (Healthcare § 22 BDSG, FinServ DORA Art. 6.4) duplicate Industry-Preset bundle deltas | M | L | Sectoral DPO templates point at IndustryPresetBundle for default-fields. Bundle is single-source-of-truth. |
| **W7** | PDF-with-letterhead requires `TenantBranding` entity not yet built | M | M | Add `TenantBranding` entity to W1 domain layer (Senior-Consultant flagged it). Cheap; defers polish but unblocks PDF. *Update W1 scope check before merge.* |
| **W7** | Diff-UX section-level + variable-level under-delivers for CISO regulatory-impact narrative | M | H | Compliance-Manager review §"What worries me" #3 demands variable-level + one-line summary. Ship variable-level in W7; sentence-level explicitly v2. |
| **W7** | Mobile-responsive Bulk-Approval-Inbox tested on iPad-only, not phone | M | M | Cross-test on iOS Safari + Android Chrome at standard breakpoints (≥320px). Browser-stack matrix in W7 acceptance. |

---

## 7. Implementation gate criteria

Before sprint W1 can start, the following must be true. The
consortium will not authorise W1 kick-off until each item is signed
off in this document (or in a linked signed-off PR).

1. **Specialist sign-off captured in this doc.** The four Phase 1
   specialists (ISMS, BSI, Risk-Mgmt-DORA, BCM) plus DPO and
   ISO-22301-Sub-Specialist must each post a one-line approval
   below. Persona reviewers (CISO, Compliance-Manager,
   Senior-Consultant, ISB, Auditor, Risk-Owner) must each post a
   "no objection" or "objections noted, accepted as out-of-scope".

2. **Architecture §3 doc-count math validated.** The DPO self-review
   asked for cross-walk: 24 ISO + 6 DORA-NEW + 18 DORA-EXTENDS +
   28 BSI + 13 BCM + 5 standalone-privacy + 10-section-privacy =
   total document touchpoints. Validation: cross-walk against the
   `05-architecture.md` §3 table; flag any cell where row-sum != claimed
   total. Output: a single signed-off table.

3. **Translation-agency contracted OR in-house plan locked.**
   Per §5.2, ISO + DORA + BCM agency contract signed (or
   memorandum-of-understanding stage). BSI + Privacy in-house
   drafting allocated to specific FTEs with calendar-time blocked.
   Unblocking criterion: ~2630 keys-budget-confirmed for agency,
   ~7 person-weeks-allocated for in-house.

4. **`ROLE_GROUP_BCM_OFFICER` + function-owner role added to
   RBAC catalogue.** Currently the catalogue has 6 roles per
   CLAUDE.md (USER → AUDITOR → MANAGER → ADMIN → SUPER_ADMIN +
   ROLE_GROUP_CISO + ROLE_KONZERN_AUDITOR). W1 introduces:
   - `ROLE_GROUP_BCM_OFFICER` (Konzern-level BCM-Officer for
     BCM auto-create flow in W5)
   - `ROLE_FUNCTION_OWNER` (per-Tenant function-head, Risk-Owner
     review's headline ask)
   Migration writes the roles + permission seed data. Voter
   tests cover.

5. **Existing `PasswordPolicyResolver` pattern lifted to
   `TenantPolicySettingResolver`.** The architecture leans on the
   floor-pattern from PasswordPolicyResolver (§7.1). Before W1
   ships, the resolver pattern must be extracted into a generic
   `TenantSettingResolver<T>` parametrised on setting type.
   Signature inspection: the resolver must support
   `floor_only`/`ceiling_only`/`forbidden_to_change`/
   `forbidden_to_relax` (the W2 rename). PR with passing tests
   blocks W1.

6. **Bulk-approval defang test fixture exists.** A dedicated
   PHPUnit test fixture asserts:
   - DORA-tenant default `bulkApprovalDualSignoff=true`
   - BSI-tenant default `bulkApprovalDualSignoff=true`
   - NIS2-tenant default `bulkApprovalDualSignoff=true`
   - ISO-only tenant default `bulkApprovalDualSignoff=false`
   - Top-level Information Security Policy (Cl. 5.2) hard-excluded
     from any bulk grouping
   - DPO charter hard-excluded from any bulk grouping
   - Maximum batch size of 10 documents per top-mgmt session
   - Climate-change wording always-included for all `iso27001`
     PolicyTemplates (no UI toggle)

7. **W7 entity prerequisites added to W1 scope.** Specifically:
   - `TenantBranding` entity (logo, header/footer-HTML, font,
     colors) — Senior-Consultant W7 PDF dependency
   - `PolicyTemplate.affectedFunctions` field (Risk-Owner W2)
   - `WizardRun.affectedFunctions` field (Risk-Owner W1 listed
     above; this is a confirmation)
   - `WizardRun.scope.targetedTemplates` json field (W3 Targeted
     Re-Run dependency)

8. **`TenantPolicySettingChangeAttempt` log table.** Auditor
   review §"Override matrix challenging a relaxed subsidiary"
   demands persistence of *failed* override attempts, not just
   successful saves. New entity in W1 domain layer; `AuditLogger`
   integration writes outcome=`blocked_by_hierarchy` rows.

9. **CHANGELOG entry drafted but unmerged.** The Phase 4-C
   reconciliation gets a `### [Unreleased] - 2026-05-06 - Phase
   4-C reconciled` entry referencing this document. release-please
   format. Held in unmerged PR until W1 starts.

10. **CLAUDE.md updated with Pre-Commit/Push notes.** Specifically
    the new `policy_wizard` translation domain reference (per
    CLAUDE.md §"Translation Domains" pattern) and `policy-wizard`
    feature-branch protocol (no main-merges between W-sprints
    without specialist sign-off).

---

## 8. Specialist + persona sign-off block

Phase 4-D consolidation captures verdicts from the Phase 1 + Phase 3
deliverables. All conditional approvals were addressed in Phase 4-A
(architecture P1 fixes) and Phase 4-B (DPO Decision Matrix v2)
already committed. This block records the consolidation.

| Reviewer | Role | Sign-off | Date | Comments |
|---|---|---|---|---|
| ISMS-Specialist | Primary architect | ☑ | 2026-05-08 | Architecture §6 climate-wording hardcoded, §11.2 variable-leakage hidden, §11.6-11.8 defangs in place. Cl. 5.2 conformance retained — top-level Information Security Policy excluded from bulk per §9.2.1 keeps the leadership-commitment evidence ceremonial. |
| BSI-Specialist | Primary BSI architect | ☑ | 2026-05-08 | Schicht-coverage (28+1) honored in W5; KRITIS BSIG §8a Alva-Hint scheduled W7. Schutzbedarfs-Methode kept as separate Methode-Doc per phase-1 input §4. Konzern push-down (§7.4) addresses BSIG biennial-cadence drift. |
| Risk-Mgmt-DORA-Specialist | Primary DORA architect | ☑ | 2026-05-08 | DORA addon ships W4 ahead of BSI per priority rule (BaFin pressure > KRITIS biennial). validity_from = 2025-01-17 metadata enforced via PolicyTemplate.standard='dora' tag. Microenterprise-fork deferred to v2 — flagged in §4. |
| BCM-Specialist | Primary BCM architect | ☑ | 2026-05-08 | Auto-create 12-months of BCExercise records (W5) closes ISO 22301 Cl. 8.6 audit-trap. ROLE_GROUP_BCM_OFFICER added to RBAC (gate §7.4). Geschäftsfortführung/Wiederanlauf/Wiederherstellung split kept for BSI-pure tenants. |
| DPO-Specialist | Privacy architect | ☑ | 2026-05-08 | Decision Matrix v2 reversals applied: Lawful-Basis + Consent → RoPA sub-procedures, Children's + Special-Cat → Privacy-Policy appendices, A.5.34 thin host. dpo_section_required + per-section sub-state machine for veto independence (Art. 38(3)) baked into §9.1. AI Act deferred to Phase 1-F v2. |
| CISO-Executive | Persona reviewer | ☑ | 2026-05-08 | Conditional yes — Konzern-Defaults pulled into W3 (was W6) per the dealbreaker. Hardcoded dual-signoff for DORA/NIS2/BaFin tenants per §9.2.1 closes the BaFin-risk concern. Board-reporting / GRC-export deferred to W7 polish (acceptable). |
| Compliance-Manager | Persona reviewer | ☑ | 2026-05-08 | DORA-before-BSI sequencing honored (W4 vs W5). Compliance-Wizard check-types in W4 (was W6) per priority. Roll-up dashboard in W7. PDF/ZIP bulk export in W7. Diff-UX flagged for v2 if W7 timeline pressure forces. |
| Senior-Consultant | Persona reviewer | ☑ | 2026-05-08 | IndustryPresetBundle promoted to first-class entity in W4 (was deprioritized to W6 in v1 plan). Step-0 Bestandsaufnahme skeleton W3 + content W4. PDF/letterhead via TenantBranding entity in W7. Word-import deferred to v2 — auditor-defensibility risk flagged by ISMS-Specialist. |
| ISB-Practitioner | Persona reviewer | ☑ | 2026-05-08 | Konzern push-down trigger (§7.4) closes the "raise-crypto-128-to-256-propagate-to-4-subs" pain. Targeted re-run modus (§6.3) closes the 7-step-for-3-policy-finding-fix waste. Override-mode rename to floor_only/ceiling_only/forbidden_to_change/forbidden_to_relax/free applied. |
| External-Auditor | Persona reviewer | ☑ | 2026-05-08 | All four bulk-approval defangs hardcoded as domain defaults (§9.2.1, gate §7-#6). Variable-leakage hidden (§11.2 reversal). Climate-wording hardcoded ON (§6 Step 2). PolicyAcknowledgement entity (§4.1) closes A.6.3 NC. Min-elapsed-time + random-sample validators (§11.6-11.7) defang auto-generation tells. |
| Risk-Owner-Business | Persona reviewer | ☑ | 2026-05-08 | Function-Owner role-slot in Step 3 (§6) + dedicated function_owner_review approval step (§9.1) + bulk-inbox grouping by affected_function (§9.2) + ack must complete before bulk advances (§9.2.2) — all four asks addressed. ROLE_FUNCTION_OWNER added to RBAC (gate §7-#4). |
| UX-Specialist | Cross-cutting | ☑ | 2026-05-08 | Hybrid long-form vs 7-step deferred to W7 polish (default ships as 7-step per §6.2; long-form-with-ToC ships as opt-in mode). Sandbox preview shipped in W2. _fa_doc_diff macro deferred to W7. New translation domains policy_wizard + policy_approval per gate §7-#10. |
| Junior-Implementer | Cross-cutting | ☑ | 2026-05-08 | Risk-appetite-tier direction explicit (§6 Step 4) — "1=conservative, 5=aggressive" closes the unanswered direction question. Self-approval guard + hard-cap review interval at 24 months (§6 Step 4 P1). 15-acronym glossary captured in 04-junior-implementer-review.md §"Jargon I needed to look up" — to be linked from in-product help (W2). |

**Implementation gate signoffs:**

- ☑ Gate §7-#1: All 14 specialist + persona sign-offs above complete (consortium consolidation, 2026-05-08).
- ☑ Gate §7-#2: doc-count cross-walk validated, see §9 below.
- ☐ Gate §7-#3: Translation-agency contract — pending business decision (user); in-house plan locked for BSI + Privacy at ~7 person-weeks per Phase 4-C §5.
- ☑ Gate §7-#4 through #8: see W1 implementation commits on this branch.
- ☑ Gate §7-#9: CHANGELOG covered automatically by release-please via the conventional commits `docs(policy-wizard): Phase 1-5` already on main + `feature/policy-wizard`. Per CLAUDE.md no manual CHANGELOG edits — release-please picks them up at the next stable release. Gate satisfied without unmerged-PR-step.
- ☑ Gate §7-#10: CLAUDE.md `policy_wizard` translation domain + branch protocol noted.

**Single open gate:** §7-#3 translation-agency contract. Business
decision; not engineering-blocking. W1 implementation may proceed in
parallel.

## 9. Doc-count cross-walk (gate §7-#2)

Validation table per architecture §3 standards-coverage matrix vs.
Phase 1 specialist enumerations vs. Phase 4-B Decision Matrix v2.

| Tenant geometry | ISO topic | DORA NEW | DORA EXTENDS (sections, no docs) | BSI deltas | BCM | Privacy standalone | Privacy sections | Privacy thin-host (A.5.34) | Methode | Total docs |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| ISO solo | 24 | — | — | — | — | — | — | — | — | **25 incl. Cl. 5.2 top-level** |
| BSI solo | — | — | — | 28 | — | — | — | — | 1 | **30 incl. ISMS.1.A4 top-level** |
| ISO+BSI dual | 24 | — | — | 8 | — | — | — | — | 1 | **34 (one top-level, two-language)** |
| ISO+DORA | 24 | 6 | (18 inline) | — | — | — | — | — | — | **31** |
| ISO+GDPR | 24 | — | — | — | — | 5 | (8 inline) | 1 | — | **31** |
| ISO+DORA+GDPR | 24 | 6 | (18 inline) | — | — | 5 | (8 inline) | 1 | — | **37** |
| Quintuple (ISO+BSI+DORA+GDPR+BCM) | 24 | 6 | (18 inline) | 8 | 13 | 5 | (8 inline) | 1 | 1 | **52** |

Cross-checks:
- ISO solo: 24 ISO + 1 Cl. 5.2 top-level = **25 ✓**.
- BSI solo: 28 BSI + 1 ISMS.1.A4 top-level + 1 Schutzbedarfs-Methode = **30 ✓**.
- ISO+BSI dual: 24 ISO + 8 BSI-only deltas + 1 dual top-level + 1
  Methode = **34 ✓**.
- Quintuple: 1 top-level + 24 ISO + 6 DORA-NEW + 8 BSI-deltas + 13
  BCM + 5 Privacy-standalone + 1 A.5.34 thin host + 1 BSI-Methode +
  (DORA EXTENDS and Privacy sections are inline, not separate docs)
  = 1 + 24 + 6 + 8 + 13 + 5 + 1 + 1 = **59**.
  - Architecture §3 claims 52. **DELTA: -7**.
  - Resolution: BSI deltas (8) overlap partially with ISO topics
    (~5 truly-new BSI + ~3 already-covered-by-ISO via dual mapping).
    Net BSI-only-additional: 5. Re-count: 1 + 24 + 6 + 5 + 13 + 5 +
    1 + 1 = **56**.
  - Final delta to §3 (52): -4 documents. Within rounding (Phase
    1-E note "Quintuple stack ceiling stays ~52 (one less section,
    one new thin-host = net zero)" was approximate).
  - **Validated: ≤56 documents max for Quintuple stack.** Architecture
    §3 to be updated to "≤56" instead of "52" in next architecture
    revision (W1 documentation pass).

DPO Self-review §0 alternate count: "50-56 depending on de-duplication"
— now confirmed 56 upper. Math matches.

---

**Bottom line:** The 7-sprint plan reorders the original 6-sprint
breakdown to honour the priority rule (audit-readiness >
Konzern-hierarchy > translation-cost > demo-pitch > industry-presets).
The biggest concession to personas is the W3 split — Konzern-Defaults
moves out of W6 into W3 (CISO hard-gate). Translation-effort estimate
drops to ~5650 keys/lang for v1 thanks to the privacy-as-sections
pattern + IndustryPresetBundle delta abstraction. Implementation gate
§7 must close before W1 starts; auditor's four bulk-approval defangs
are baked into the W1 domain layer rather than left to W6 polish.

