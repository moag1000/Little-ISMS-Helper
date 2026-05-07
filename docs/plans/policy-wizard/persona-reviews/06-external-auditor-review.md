# External-Auditor Review — Policy-Wizard Plan

> Review of `05-architecture.md` from the perspective of an external
> certification auditor. Reviewer profile: ISO 19011 lead auditor,
> accredited DAkkS for ISO 27001 / 22301 / 27701, BSI-testierter
> Grundschutz-Auditor, ~40 audit-days/year. Distanced, evidence-driven,
> paid to find Nonconformities.

---

## My profile

I run roughly 28 certification + surveillance audits per year, primarily
ISO 27001:2022, ISO 22301, ISO 27701:2025, and BSI IT-Grundschutz
Testierung. I have seen every wizard-generated policy set produced by
the dominant GRC suites in the DACH market and can usually identify the
generator from the first three documents I open. My tells: identical
section headings across unrelated tenants, "Purpose / Scope / Roles /
References" boilerplate that never names a specific business process,
approval timestamps within seconds of generation timestamps, and DPO
charters that quote Art. 38(3) GDPR verbatim but list the same person
who signs the ISMS policy as CISO.

## Audit-readiness verdict

**Pass with minor NCs, conditional on three architectural additions
before Phase 5.** A tenant who ran this wizard three months before
audit would survive Stage 1 (documentation review) cleanly because the
plan addresses the obvious failure modes (immutability §10, supersedes
chains §10, evidence tags §8.5, mandatory tailoring §11.1). I would
challenge the tenant on Stage 2 (effectiveness): show me the policy was
*lived*, not just *written*. The architecture is strong on document
existence and approval recording, weak on demonstrating engagement
between approval and the next surveillance audit. Specifically I would
push back on §9.2 bulk-approval and §11.1 tailoring quality. See NCs
below.

## Tells of auto-generation that auditors look for

| # | Tell | Architecture handling |
|---|---|---|
| 1 | Identical wording across multiple unrelated tenants | **PARTIAL** — §11.1 mandates 3 tailoring fields per topic, but no minimum-quality check. A user can type "n/a" three times and ship. |
| 2 | Variable substitution leftovers (`{{ tenant.legal_name }}`) | **WEAK** — §11.2 leaves variable name in a footer comment "for transparency". I will flag this as unprofessional output the *first* time I see it; recommend automated `{{ }}` regex scan in `DocumentGenerator` before persist. |
| 3 | Approval timestamp within seconds of generation timestamp | **NOT HANDLED** — §9.5 wizard runs and immediately dispatches `ApprovalKickoff`; nothing prevents a top-mgmt user from approving the same minute. Add a configurable minimum-elapsed-time gate (recommend ≥3 business days). |
| 4 | Policy dated AFTER claimed effective date of certification | **PARTIAL** — `Document.supersedes` chain (§10) preserves history, but no validation that the new draft's effective date is ≥ generation date. Trivial to fake a backdated policy. |
| 5 | Boilerplate "Purpose / Scope / Roles" with no concrete process names | **WEAK** — depends entirely on tailoring-field quality (§11.1). |
| 6 | Approver = author = reviewer (single-person sign-off) | **PARTIAL** — §9.1 has separate `ciso_review` and `top_mgmt_signoff` steps but does not enforce different *people*. A small tenant may legitimately combine roles; a large one must not. |
| 7 | Identical review-interval (12 months) on every document | **HANDLED** — §6 Step 6 allows per-policy override; §11 mentions audit-trap. Default 12 mo is auditor-acceptable. |
| 8 | Climate-change wording (ISO 27001 Amd. 1:2024) absent | **WEAK** — §6 Step 2 has it as a *toggle* defaulting ON only "from 2026". Should be ALWAYS-ON for ISO 27001 generation; the amendment is mandatory since 2024-02. See gaps §below. |
| 9 | DPO charter approved by the DPO themselves | **HANDLED** — DPO input §2.13 explicitly: "DPO does NOT self-approve their own charter; Top-Mgmt directly". Good. |
| 10 | No record of who *read* the policy after approval | **NOT HANDLED** — no readership/acknowledgement entity in §4. Asking the architecture to add `PolicyAcknowledgement` (user × document × timestamp) closes A.6.3 (training awareness). |
| 11 | Bulk-approval same-second across 25 documents | **PARTIAL** — §9.2 has dual-signoff toggle but defaults OFF. See bulk-approval challenge below. |
| 12 | "Last reviewed" entries that are clearly one-click rubber stamps | **WEAK** — §9.4 records the click but no engagement evidence (time-on-page, change-marker, reviewer comment optional). |

## Evidence trail strength (§9 + §11)

A 90-min on-site walk-through against the architecture:

1. **"Show me the policy for control A.5.15."**
   §8.1 `DocumentControlLink` with `source='policy_wizard'` →
   `ControlRepository::findWithCoveringPolicies()` (§8.3). Strong.
   Auditor-pleasing: I get a click-through from SoA to the document
   without manual mapping.

2. **"When was it approved and by whom?"**
   §9.6 Approval-Trail widget on Document edit-view, sourced from
   existing `AuditLogger`. Strong. Concern: §9.2 bulk-approval shows
   "batch-approval (25 docs) — Carla G." — I will ask whether Carla
   actually opened all 25; the architecture cannot tell me.

3. **"When was it last reviewed and what changed?"**
   §10 supersedes-chain plus §9.4 review-no-change recording with
   timestamp + signer. Strong on *that something happened*; weak on
   *what the reviewer engaged with*. Recommend mandatory rationale
   textarea (≥50 chars) on both no-change confirmations and bulk
   approvals — currently only optional in §9.2.

4. **"Show me how the responsible person was trained on this policy."**
   **GAP.** No `PolicyAcknowledgement` entity. Existing `training`
   module is referenced in DPO input §2.6 but the architecture does
   not link wizard-generated policies to mandatory acknowledgement on
   first publish. ISO 27001 A.6.3 NC waiting to happen.

5. **"Show me a sample where the policy has been tested in practice."**
   §11.4 BCM-wizard auto-creates 12 months of BCExercise records —
   that closes ISO 22301 Cl. 8.5/8.6 elegantly. ISMS-side: §11.4
   mentions "auto-creates an Internal-Audit-Programme schedule" but
   the architecture lacks detail. Verify in Phase 4 that the schedule
   actually creates `Audit` entities, not just calendar reminders.

## Auditor-specific gaps

- **4-eyes-principle on policy approval.** §9.1 records separate steps
  but does not enforce distinct user identity. Add a hard constraint:
  approver ≠ author AND approver ≠ previous-step-actor on the same
  document. §9.2 dual-signoff toggle defaults OFF — that is the wrong
  default for any tenant under DORA / NIS2 / regulated sector.

- **DPO independence (Art. 38(3) GDPR).** DPO input §2.13 sets the
  rule clearly. §9.1 step 3 `dpo_cross_check` is gated `auto` for
  Privacy/PII templates (§9.3). Concern: DPO charter approval bypasses
  the bulk-approval inbox (§9.2) — please *confirm* in code that
  `topLevelPolicyApprovers=['ROLE_TOP_MGMT']` is enforced for the DPO
  charter and the charter is not eligible for bulk grouping. The
  current §9.2 grouping is by `wizard_run` or `standard`; the DPO
  charter must be *explicitly excluded*.

- **Top-management commitment evidence (ISO 27001 Cl. 5.1).**
  Bulk-approval of 25 documents in one session does NOT satisfy Cl. 5.1
  evidence of leadership commitment, regardless of audit log batching.
  I would push for ceremonial individual sign-off on the **top-level
  Information Security Policy** (Cl. 5.2) — i.e. exclude it from any
  bulk-approval grouping by hard rule, not by setting. Topic-policies
  may be bulk-approved; the top-level policy may not.

- **Climate-change wording (ISO 27001 Amd. 1:2024).** §6 Step 2 lists
  it as a toggle "default ON for iso27001 from 2026". Wrong: the
  amendment is in force since Feb 2024 (Cl. 4.1, 4.2 mention "climate
  change"). Should be ALWAYS-INCLUDED for ISO 27001 generation, no
  toggle. Make it `climateChangeWording=true` hardcoded on every
  iso27001 PolicyTemplate.v1, no UI option to remove.

- **Works-council gate (DE).** §9.1 lists it for HR / Logging /
  Physical-Security policies, defaulted ON in DE locale. Good. Missing:
  evidence that consultation actually *happened*. Recording "gate
  passed" without an upload of the BR-resolution or BR-meeting-minutes
  is a tell. Add a mandatory `worksCouncilEvidence` Document attachment
  before the gate may be marked passed.

## NC predictions

Likely NCs / Observations on a fresh wizard-run installation:

1. **Minor NC against ISO 27001 Cl. 5.1** — top-management commitment
   not demonstrably individual; bulk-approval of 25 documents in 4
   seconds. Cited clause: 5.1 a-h.

2. **Minor NC against ISO 27001 A.6.3** — no record that personnel
   were trained on the new policies. Cited: A.6.3 / Cl. 7.3 awareness.

3. **Minor NC against ISO 27001 Cl. 4.1, 4.2** — climate-change
   consideration not addressed because tenant disabled the §6 Step 2
   toggle. Cited: Amd. 1:2024.

4. **Observation against Cl. 7.5.3** — variable-substitution placeholder
   visible in published document footer ("for transparency"). Looks
   unprofessional; document control of approved content compromised.

5. **Minor NC against GDPR Art. 38(3)** — DPO charter approved as part
   of bulk-approval batch (if §9.2 grouping does not exclude it).

6. **Observation against ISO 27001 Cl. 9.3** — review-no-change
   confirmations (§9.4) lack reviewer rationale; rubber-stamp risk.

7. **Minor NC against ISO 27001 Cl. 7.5.2** — approval timestamp is
   within seconds of generation timestamp on multiple documents;
   indicates no actual review took place.

8. **Observation against BSI ISMS.1.A4** — Sicherheitsleitlinie is in
   place but no evidence of cross-functional Awareness-Mitarbeit
   (Art. of involving the Leitungsebene) beyond a single click.

## What would make me NOT challenge auto-generation

Concrete features that, if added, would make me sign off without
pushback on auto-generation:

1. **Tailoring-field minimum quality.** Per §11.1, enforce minimum
   length (≥120 chars per field), reject obvious placeholders ("n/a",
   "tbd", "to be defined"), and require at least one tenant-specific
   noun (legal name OR a process name from the existing
   `BusinessProcess` repository). Block `status=ready_for_review` until
   passed.

2. **Generation-to-approval minimum elapsed time.** Configurable per
   document type, defaults: top-level policy ≥5 business days, topic
   policies ≥2 business days, methodology ≥1 business day. Prevents
   the same-second timestamp tell.

3. **Variable-substitution leakage detector.** Pre-persist regex scan
   for `{{ ... }}` and `[[ ... ]]` patterns; raise a hard error. Do
   NOT preserve variable names in the rendered document (§11.2 is the
   wrong direction — auditors want clean prose, not generator
   transparency). Add a separate machine-readable `Document.metadata`
   JSON for the substitution audit trail.

4. **Canonical template hash on each document.** Add a footer line:
   "Generated from canonical template `iso27001.access_control.v1`,
   hash `sha256:abc123...`". Then I can verify across tenants whether
   they are running the same v1 template, and the tailoring fields
   are visible deltas.

5. **`PolicyAcknowledgement` entity + bulk-acknowledgement gate.** On
   first publish, target audience users (per `User.roles`) get an
   inbox item; acknowledgement timestamp recorded. SoA evidence on
   A.6.3 falls out automatically. Tie into the existing training
   module.

6. **Reviewer-engagement metric on no-change confirmations.** Track
   time-on-page (Stimulus controller) or require a ≥50-char rationale.
   I want to see that the reviewer actually *read* the policy.

## Konzern-Tochter compliance specifics

- **Multi-tenant audit — confirming parent baseline applies to
  subsidiaries:** §7.2 mirror-norm-inheritance is correct in concept.
  Audit ask: show me the `TenantPolicySetting.inheritedFromTenant_id`
  graph for risk-appetite-tier across all 12 subsidiaries. The
  architecture supports the query; I would need a UI surface — recommend
  a "Konzern-Compliance-Roll-up" view (already mentioned in CISO review
  §8.4). Currently implicit, make it explicit deliverable.

- **Override matrix (§7.3) — challenging a relaxed subsidiary:**
  `overrideMode='stricter_only'` enforces at write-time; good. I would
  ask: when did the subsidiary attempt a relaxation, who blocked it?
  Architecture must persist *failed* override attempts, not just
  successful saves. Add `TenantPolicySettingChangeAttempt` log (or
  reuse `AuditLogger` with `outcome='blocked_by_hierarchy'`).

- **Inherited applicability vs explicit opt-out:** §7.2 says a
  subsidiary may "skip" a template. Audit ask: show me the rationale
  text + approver for the skip. The architecture mentions "subsidiary
  marks template as skipped" but does not specify who approves the
  skip. Add: skipping a Konzern-pushed template requires Konzern-CISO
  counter-signature (or GROUP_CISO override) and a stored rationale
  ≥200 chars.

## Bulk-approval challenge

**My argument against §9.2 bulk-approval:**
A top-management bulk approval of 25 policies is an act of *signing*,
not *reviewing*. ISO 27001 Cl. 5.1(a) requires top management to
demonstrate leadership and commitment by *ensuring* the ISMS achieves
its intended outcomes — not by clicking 25 checkboxes after a 4-second
review. The audit-log batching ("approved 25 in one session") is the
opposite of what I want to see; it is dispositive evidence that no
individual review occurred. For DORA Art. 5 (governance & internal
control), this is a finding. For BaFin VAIT/MaRisk legacy lineage now
under DORA, the management board must "actively engage" with the
ICT-risk strategy. A bulk button kills that engagement.

**Minimum architecture change that defangs my argument:**

1. Hard rule: **top-level Information Security Policy (ISO Cl. 5.2)
   is excluded from bulk-approval.** Always individual, ceremonial
   sign-off, with a mandatory ≥200-char top-management rationale.
2. Bulk-approval scope is limited to **topic-policies that already
   have CISO + DPO sign-off** in the prior workflow steps. Top-mgmt
   bulk-approval is a *ratification* of a properly-reviewed pipeline,
   not a stand-alone act.
3. **Default `bulkApprovalDualSignoff=true`** for any tenant whose
   `standardsAdopted` includes DORA, BSI, or NIS2-scope. CISO review
   already flagged this; I second it strongly.
4. Bulk batch size cap: ≤10 documents per top-mgmt session.
   Architecturally: split a wizard-run of 25 documents into 3 batches.

With those four changes, I would not raise an NC against the bulk
mechanism. Without them, I write up an Observation at minimum, a Minor
NC if I can show the same person bulk-approved >20 documents in <2
minutes.

## What I love (yes, even auditors love some things)

1. **§8.1-§8.4 SoA bidirectional link in same DB transaction.** The
   "show me the policy for control X" question normally takes 20 min
   of digging through SharePoint. Here it is a single SQL join. Saves
   me time, gives me confidence.

2. **§10 immutability + supersedes-chain.** Standard versioning that
   actually preserves history. The 2027 question "what did your
   backup policy say on 2026-03-01" is answerable in seconds.

3. **§8.5 tagging schema with `wizard-run:<id>` and
   `dora-validity:2025-01-17`.** A DORA-specific Stichtag-tag is
   thoughtful — surveillance auditors care about effective-from-dates,
   and the tag drives evidence filtering without manual queries.

4. **§11.4 BCM-wizard auto-creating 12 months of BCExercise.** The
   most common ISO 22301 NC I issue is "Cl. 8.5/8.6 exercise programme
   exists on paper, no exercises actually run". Pre-creating the
   placeholder records forces the conversation.

5. **§9.4 review-no-change fast-path with explicit signed log.** The
   alternative (full pipeline annually for unchanged policies) leads
   to rubber-stamp fatigue and degrades the meaningful approvals. The
   fast-path *as designed* (CISO-only, signed log entry, no
   auto-publish) is exactly what a mature ISMS looks like.

## Open questions for Phase 4

1. **DPO/Compliance/ISO specialists:** confirm the DPO charter is
   architecturally excluded from §9.2 bulk-approval grouping — code-
   level enforcement, not setting toggle.

2. **ISO/BSI specialists:** confirm climate-change wording (Amd.
   1:2024) is hardcoded ON for all `iso27001` templates, not toggleable.
   Also: BSI Edition tracking on `PolicyTemplate.version` (§15 risk).

3. **DORA specialist:** does the Art. 5 governance requirement compel
   us to default `bulkApprovalDualSignoff=true` for DORA-scoped
   tenants, and is bulk-approval of the ICT-Risk-Mgmt-Framework
   document acceptable at all, or must it be individual sign-off?

4. **BCM specialist:** for the auto-created 12-month BCExercise
   records — what is the default exercise type per slot
   (table-top / live / desktop-walkthrough)? Auditor-pushback risk
   if all 12 are placeholder type "tbd".

5. **DPO specialist + ISO specialist:** §11.2 keeps variable-name
   leftovers in document footer "for transparency". My recommendation
   is to remove this and store substitution audit-trail in a separate
   `Document.metadata` JSON. Confirm or push back.

---

**Bottom line:** the architecture is more thoughtful than the average
GRC-suite wizard I see in the field. With the four bulk-approval
defang changes, the climate-change hardcoding, the variable-leakage
detector, and the `PolicyAcknowledgement` entity, I would let a
wizard-run installation through Stage 2 with at most 1-2 minor
observations. Without them, I will find 3-5 NCs in any audit.
