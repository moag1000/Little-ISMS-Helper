# Risk-Owner Business Review — Policy-Wizard Plan

> Phase 3 persona review. Reviewer: Head-of-Operations of a mid-size
> manufacturer (~600 FTE, two plants, B2B). I own operational risk for
> the Ops function (production, logistics, supplier-onboarding). I am
> NOT a security specialist. I sign risk-acceptances; I do not write
> policies.

## My profile

I run Operations: ~250 people across two sites, plus the supplier-management
desk. The CISO drags me into a meeting roughly once a quarter — usually
when an asset I "own" has an open risk over appetite, or when a supplier-audit
finding lands on my desk. I want to spend my time on plant uptime,
supplier reliability, and our quarterly OEE numbers — not on reading
30-page policies. I am happy to sign things, but I want to know what
I am signing and what it costs my function.

## Top question

**"Will I be in this wizard at all?"**

**Answer: No, not in v1 — and that is a problem.**

Reading §6 (Wizard Flow):
- Step 1 picks standards (CISO/ISB job).
- Step 3 "Roles & Responsibilities" names CISO, DPO, BCM-Officer,
  IT-Operations-Lead, Crisis-Team — no slot for "Head of Business
  Function as policy-affected stakeholder".
- Step 5 "Operational Baselines" sets backup-RPO, patch SLAs,
  continuity-RTO targets per criticality tier — values that directly
  bind my function's downtime tolerance, with no consultation step.
- Step 7 "Review & Generate" goes straight to atomic transaction +
  ApprovalKickoff. No "function-owner sign-off" gate.

§14 even names me as an open question: *"Risk-Owner-Business: Are they
ever in the wizard, or is this ISMS-team only?"* — meaning the
architecture team has not decided. Today's draft says no.

§9.1 approval pipeline: `prepared → ciso_review → dpo_cross_check →
top_mgmt_signoff → published`. I am not a step. Top-Mgmt signs FOR me
— and §9.2 lets them bulk-approve 25 documents in one click without me
ever seeing the three that affect my function.

**Verdict: I am invisible to the wizard, but the wizard's outputs bind
my operations.** That is the risk-owner version of "policy by stealth".

## If yes, where and how often?

N/A — see above. (Phase 4 question to resolve.)

## If no (current state), what's my interface?

Reading the architecture as drafted:

- **Do I see the resulting policies?** Only if someone tells me to.
  §6 Step 3 collects an `owner` per topic-policy (`DocumentGenerator`
  sets `Document.owner`, §8.6) — but the field is "CISO / DPO /
  BCM-Officer per topic", not function-owner. Nothing automatically
  names me on a policy that constrains my plant.

- **Do I get notified when a policy I "own" changes?** No mechanism
  shown. §9.4 says the annual-review CRON marks Documents `review_due`
  T-30d and notifies the *reviewer* (default CISO). Function-owners
  are not in the notification graph.

- **How do I push back on something the CISO drafted?** Today only by
  catching it in the Top-Mgmt batch (§9.2) — i.e. via my
  Geschäftsführer in a meeting where 25 documents are on the slide.
  Not a workable channel.

- **Approval-trail (§9.6):** The trail logs `Wizard prepared → CISO
  approved → DPO approved → Top-Mgmt batch-approved → Published`.
  I am not a row. A future auditor will not be able to evidence that
  the function actually agreed to the operational baseline — they will
  see only that the GF rubber-stamped it.

## Risk-acceptance flow concern

The architecture mentions "risk_owner" once, implicitly, via
`Document.owner`. There is no flow for the case where a generated
policy creates a NEW risk-acceptance demand on my function. Walking
the three scenarios:

### Scenario 1 — Backup Policy says max-tolerable-data-loss = 4h, my plant says 24h is fine

Path through current draft: §6 Step 5 collects "Backup: RPO target tier"
from whoever runs the wizard (CISO). §7.3 hierarchy matrix says Backup
RPO is `parent_min / stricter_only` — i.e. the wizard ENFORCES tighter,
not relaxes. So my plant cannot cheaply opt for 24h.

- **Who notifies me?** Nobody. The RPO becomes a SoA implementation
  status (§8.2) and a backup-policy-document tagged `policy-wizard-generated`.
- **Where do I disagree?** Only via a separately-raised
  Risk-Acceptance ticket AFTER the policy is published. By then the
  evidence trail says I am bound.
- **How does the wizard handle disagreement?** It does not. The CISO
  picks the tier in Step 5, GF approves at Step 7, and my plant inherits
  a 4h RPO with infrastructure costs I now have to fund.

**Required:** §6 Step 5 must trigger a function-owner consultation
when the chosen tier is *stricter than current operational reality*.
At minimum: fire a Notification + create a draft Risk-Acceptance for
the affected function-owner to either accept the cost or formally
deviate.

### Scenario 2 — International Transfer Policy adds safeguards I have to fund

GDPR international-transfer safeguards (SCCs, TIA, supplemental
measures) imply real budget for my supplier-management desk. The DPO
(§6 not yet decided) checks the box; my desk pays.

- **Who notifies me?** Privacy-cross-check is between CISO and DPO
  (§9.1 step `dpo_cross_check`). I am out of the loop.
- **Where do I disagree?** Same as above: nowhere in the wizard.
- **What's missing:** A "budget-impacting policy" flag on
  `PolicyTemplate` that forces a cost-bearing-function ack BEFORE
  Top-Mgmt sign-off.

### Scenario 3 — Logging Policy says we monitor employee email; HR/Works-Council route

§9.1 acknowledges this: *"DE-specific: works-council consultation gate
for HR / Logging / Physical-Security policies before `top_mgmt_signoff`
can fire."* This is the ONE place the architecture flags business-side
involvement. Good.

- **Who notifies me?** The works-council gate fires. But the gate is a
  binary block — it does not open a structured dialogue.
- **Where do I disagree?** Outside the system, in works-council
  consultation. The wizard records "gate cleared" or "gate blocked".
- **Gap:** Other categories (Backup, RTO, Crypto-key-rotation that
  breaks integrations) get NO equivalent gate. The architecture gives
  works-council a seat but not function-heads.

## Bulk-approval (§9.2) seen from my desk

§9.2 is operationally efficient for the GF and operationally dangerous
for me.

- **If GF batch-approves 25 policies including 3 in my domain, did I
  get a chance to disagree?** No. There is no pre-batch notification to
  function-owners. The Bulk-Approval-Inbox is filtered by `wizard_run`
  or `standard`, not by `affected_function`.
- **Is there a "raise-objection" mechanism before GF clicks?** Not
  drafted. I would need: (a) email when a policy enters
  `top_mgmt_signoff` and lists my function as affected; (b) a 5-day
  comment window; (c) a "block batch" toggle that drops the policy
  from the GF batch back to CISO.
- **How do I sanity-check 3 of 25?** I cannot. The architecture lists
  no view filtered to my function. I would have to open all 25 from
  my GF's screen-share. Realistically I will not.

The 4-eye dual-signoff option in §9.2 (`bulk_approval_dual_signoff`)
is a SECOND top-mgmt role, not a function-owner. It does not solve
this gap.

## What I love

1. **§5 `VariableCollector` pulls existing tenant data.** I do not
   want to re-enter org info I already gave HR. Good.
2. **§9.4 "Confirm review — no change" fast-path.** Annual-review
   without dragging the GF in for unchanged content is a huge time-saver.
   By extension I will not be dragged in either.
3. **§11 Auditor-trap prevention forces tailoring.** Good — it means
   policies cannot ship as anonymous template-text that auditors find
   later and pin on me.
4. **§5 `HierarchyOverrideValidator`.** Konzern-floor + subsidiary
   stricter-only is a clean model. As a subsidiary head I cannot
   accidentally relax a Konzern requirement.

## What worries me

1. **§6 Step 3 (Roles) has no function-owner slot.** Only CISO/DPO/
   BCM-Officer/IT-Ops/Crisis-Team. Policies that constrain Sales /
   Ops / R&D / HR get drafted without naming the affected business head.
   Result: the policy lands on me by inheritance, not by agreement.

2. **§6 Step 5 (Operational Baselines) sets values that bind my
   function** (RPO, RTO, patch SLAs, crypto-rotation cadence) without
   any consultation step. Risk-tier defaults chosen by a junior
   implementer who does not understand my plant's downtime cost.

3. **§9.2 Bulk-Approval hides function-affecting policies in a 25-doc
   batch.** GF clicks once; I am bound. No per-function pre-batch
   surfacing.

4. **§9.6 Approval-trail does not show function-owner agreement.** A
   future external auditor sees only CISO + DPO + Top-Mgmt rows.
   Functionally I am the risk-owner, but the evidence trail makes me
   invisible. When the auditor asks "did Operations agree to this 4h
   RPO?", we cannot answer with evidence.

5. **§8.2 SoA auto-bumps `partial_documented`** the moment a policy
   ships. From my desk that means: an auditor can later challenge me
   on whether the control is actually operating at my plant, even
   though we never resourced it. The wizard makes a paperwork claim
   that my plant has not verified.

6. **§14 explicitly leaves "Risk-Owner-Business: are they in the
   wizard?" open.** That this is even an open question after Phase 2
   tells me the design has not internalised the function-owner role.
   I want a definitive "yes, here is the touchpoint".

## What's missing

1. **A "Heads-of-Function review-batch" between `ciso_review` and
   `top_mgmt_signoff`.** Default ON for any policy whose template
   carries a `functionOwnerImpact: true` flag. Default OFF for purely
   ISMS-internal docs (e.g. Internal-Audit-Programme).

2. **`PolicyTemplate.affectedFunctions` field** + per-policy
   ownership claim. Each topic policy declares "this binds Ops /
   HR / Sales / Finance / R&D" so the wizard can route consultation.

3. **Notification when a policy in my domain enters `review_due`,
   `top_mgmt_signoff`, or supersedes-a-prior-version.** Wired through
   the existing notifications module — no new infrastructure.

4. **Risk-acceptance escalation path** when a generated policy
   IMPLIES a new risk-acceptance for my function (RPO-tighter-than-
   reality, monitoring-on-employees, supplier-safeguard-funding).
   Wizard creates a draft `RiskAcceptance` row pointing at the
   function-owner; policy cannot reach `top_mgmt_signoff` until that
   acceptance is `pending` (created — not yet decided).

5. **Dashboard widget "Policies you own and their status"** — function-
   owner sees: drafts in my domain (count), reviews due in 30d (list),
   open risk-acceptances tied to a policy (list). One screen, no need
   to learn the document module.

## Sprint priority (business-side)

§13's order optimises for standards-coverage breadth. From my desk the
right order optimises for *function-owner protection first, breadth
second*:

| New order | What | Why (business-side) |
|---|---|---|
| **W1** | Domain (unchanged) | Foundation. |
| **W2** | Wizard core (ISO 27001 only) **+ function-owner role-slot in Step 3 + `affectedFunctions` field on PolicyTemplate** | Bake the function-owner concept in from day one — retro-fitting it across BSI/DORA/BCM later is 4x the cost. |
| **W3** | Document generation + SoA link **+ Heads-of-Function review-batch step in approval workflow + notifications** | Before bulk-approval ships, the consultation channel exists. |
| **W4** | BSI extension (unchanged) | |
| **W5** | DORA + BCM (unchanged) | |
| **W6** | Polish **+ function-owner dashboard widget + risk-acceptance auto-draft on policy-implies-new-risk** | Last sprint is fine for the dashboard; the protective plumbing is in W2/W3. |

Net effect: **W2 and W3 each grow by ~15–20% scope** to bake function-
owner protection into the foundation. W6 absorbs the visible UX. The
total sprint count stays 6.

## Open questions for Phase 4

1. **For ISO 27001 specialist:** Cl. 5.3 (Roles, responsibilities, and
   authorities) — does the standard let us record function-owner
   acknowledgement as evidence, distinct from top-management approval?
   If yes, we have norm-anchor for the new step.

2. **For BCM specialist:** RTO targets per criticality tier (§6 Step 5)
   — should these be inputs from BIA-already-run (function-owners
   already consulted there) rather than fresh wizard inputs? Avoids
   duplicate consultation.

3. **For DPO specialist:** International-Transfer / Employee-Monitoring
   policies routinely create cost or works-council impact. Should the
   `dpo_cross_check` step automatically loop in HR-head and
   supplier-management-head, or is that out of scope for Privacy?

4. **For UX specialist:** Function-owner dashboard widget — reuse the
   existing "my open tasks" pattern, or a dedicated card on the
   landing page? I want one number on first login: "3 policies need
   your attention" — not a navigation hunt.

5. **For Compliance-Manager:** Today's risk-acceptance module — is it
   wired to documents at all, or only to risks/assets/controls? If
   not yet, we need a `RiskAcceptance.relatedPolicy` link before W3
   can ship the auto-draft path.

---

**Bottom line:** As drafted, the wizard treats me as a downstream
recipient of decisions made by the CISO and rubber-stamped by the GF.
For an ISMS this is acceptable for paperwork; for actual risk
ownership it is not. Three modest additions — function-owner role-slot
in Step 3, Heads-of-Function review-batch in §9.1, and a notifications
+ dashboard widget — flip the wizard from "policy by stealth" to
"policy with informed consent". Without them I will object to every
bulk-approval round on principle.
