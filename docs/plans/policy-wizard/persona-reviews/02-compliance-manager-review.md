# Compliance-Manager Review — Policy-Wizard Plan

> Reviewer persona: Head-of-GRC / Compliance-Manager, mid-career (4 years
> tenure), reports to CISO, owns SoA + framework activation + audit prep.
> Reviewing `05-architecture.md` (primary) with cross-reference to
> `01-iso27001-input.md`, `02-bsi-input.md`, `03-dora-input.md`,
> `04-bcm-input.md`, `06-dpo-input.md`.

---

## My profile

I'm Head of GRC at a ~600-employee Mittelstand SaaS provider serving
European banks (so DORA hits us via our customers' supplier-management
chains, even though we are not directly in scope as a financial entity).
We carry ISO 27001 since 2022, are spinning up BCM (ISO 22301) for a
top-3 customer's supplier audit, and DPO insists on ISO 27701 within
two years. I currently keep policies in a SharePoint library plus
spreadsheets, run SoA in Excel cross-mapped against Annex A and our
two customer frameworks, and dread the quarterly auditor walkthrough
because every framework expansion means re-templating five policies.
I dread *most* the moment our second-tier subsidiary has to answer the
group auditor about "why your backup policy diverges from group's" and
nobody can find the override decision.

## Practical verdict

**This makes my month-end and audit-prep dramatically easier — my
quarter-end, only mildly so, and my framework-rollout day is my new
best friend.**

Concretely:
- **Audit-prep (annual ISO surveillance):** today I spend ~3 weeks
  collating policy copies, version history, approver evidence, and
  cross-referencing them to SoA controls. With the wizard's tagging
  (`policy-wizard-generated`, `wizard-run:<id>`, §8.5) plus the
  Approval-Trail widget (§9.6) and the auto-link to SoA evidence
  (§8.2), this collapses to maybe 3 days. Big win.
- **DORA rollout to our SaaS:** the addon model (24 ISO + 6 DORA-NEW
  + 18 DORA-EXTENDS, §3) means I don't have to author 24 net-new
  documents; my ISO baseline gets DORA-section blocks. That's ~4 weeks
  saved on day one.
- **Quarter-end SoA review:** the wizard updates SoA at *generation*
  time but doesn't help me with *operating effectiveness* tracking
  (§8.2 only writes `partial_documented`). I still need a separate
  evidence-gathering pass — see "What's missing".
- **GDPR-scope tenant onboarding:** the section-pattern from DPO §0
  (10 sections embedded into ISO templates + 5 standalone) is exactly
  the right call. Two years ago I would have shipped 16 standalone
  privacy docs and the auditor would have asked me where the policy
  was that ties them together.

## Workflow alignment check

### Quarterly SoA review with the auditor
- **Today:** Excel SoA, manually cross-paste evidence-doc links per
  control, manually chase owners for "is this still applicable?"
- **With wizard:** SoA auto-shows policy → control links (§8.2),
  Approval-Trail widget (§9.6) gives auditor exactly what they want.
  **But:** the SoA only knows the wizard set it to `partial_documented`.
  Auditor will ask "where is the operating evidence?" — I still need
  to run the evidence-gathering myself. The wizard punts on this,
  rightly, but I want a hint surfaced (see What's missing).

### A new framework getting loaded (e.g. NIS2 lands at parent tenant)
- **Today:** group-CISO loads NIS2 framework, I get an email, I spend
  4 weeks figuring out which existing policies cover NIS2 Art. X and
  which need new wording, then template, edit, approve, distribute.
- **With wizard:** §7.2 says framework loaded at Konzern → templates
  auto-eligible at all subsidiaries. Konzern-Defaults wizard variant
  (§7.3) lets group-CISO push baseline values down. I run the wizard,
  get DORA/NIS2 deltas merged into existing ISO templates with
  `dora-validity` tags, approval workflow auto-fires. **This is the
  star feature for me.** Dependency: NIS2 templates must actually
  exist (Sprint W6 mentions DORA + BCM but not NIS2 — see Worries).

### A subsidiary asks "what does our backup policy say?"
- **Today:** I dig through SharePoint per-tenant, find the right
  version, send a PDF, hope it's the current one.
- **With wizard:** subsidiary opens their Document module, filters
  by `topic:backup` + `tenant:them`, sees their effective policy
  (with Konzern-floor values clearly marked per §7.1 floor-pattern).
  Approval-Trail (§9.6) shows when it was last reviewed.
  **Concern:** the override matrix (§7.3) needs to *visibly* show in
  the subsidiary UI — "this RPO is set by parent, you can only tighten"
  — see Worries.

### Annual document review cycle (10+ docs all coming due Q4)
- **Today:** Outlook reminders, manually open each, edit/confirm,
  re-route to approver, chase signatures.
- **With wizard:** §9.4 fast-path — "Confirm review — no change
  required" with single click; §9.5 batched Review-Inbox for the 10
  docs at once. CISO handles per-document; works-council gate
  (§9.1) only fires when actual edits happen. **Excellent design.**
  **Concern:** what about cross-tenant rollup? When 5 subsidiaries
  each have their own Backup Policy due in Q4, do I as group-GRC see
  one consolidated review-inbox? See What's missing.

### A finding from external audit triggers a policy change
- **Today:** auditor finding → I edit policy → CISO approve → GF
  approve → re-distribute → tag in compliance tracker manually.
- **With wizard:** finding → click "Edit" on the document → wizard
  clones to new draft via supersedes-link (§9.4), full approval
  pipeline fires, Approval-Trail records who/when/why,
  re-generation diff (§15) shows what changed. **Risk:** the diff UX
  on a 30-page policy is non-trivial (§15 itself flags this);
  document-level "section X changed" is the right call but I will
  push back if it ships character-diff in v1. See Worries.

## What I love

1. **§8.2 SoA auto-update with `policy_wizard_generated_policy` reason
   string and "never DOWNGRADE" rule.** This single sentence saves my
   quarterly review from collapsing to a manual cross-check.
2. **§7.1 floor-pattern reuse from `PasswordPolicyResolver`.** Mirrors
   what already exists; subsidiary CISOs already understand the model.
3. **§8.5 tagging strategy.** `wizard-run:<id>` + `standard:*` +
   `topic:*` lets me filter the Document library exactly the way an
   auditor walks through a sample. Critical for evidence-trail.
4. **§9.4 "review without change" fast-path.** Most of my quarterly
   reviews ARE no-change; the current alternative (full GF
   re-approval annually for unchanged policy) is theatre. The
   `reviewWithoutChangesAutoCompletes` toggle for heavily-regulated
   tenants is a thoughtful escape hatch.
5. **§9.2 Bulk-Approval-Inbox grouped by `wizard_run`.** The GF will
   *actually* sign 25 docs in one sitting if grouped correctly.
6. **DPO §0 collapse from 16→5+10 sections.** Reduces my
   policy-library bloat by ~30% for GDPR-scope tenants and the
   privacy-as-section pattern matches what good auditors expect.

## What worries me

1. **Sprint W2 = ISO-only first (§13).** My framework portfolio is
   *already* mixed (ISO + DORA-supplier-flowdown + emerging NIS2 +
   ISO 27701 next year). Shipping ISO-solo first means I cannot
   adopt this in production until Sprint W5 finishes (DORA + BCM)
   and Sprint W4 (BSI) is *neutral* for me but blocks DORA. **Ask:
   can DORA addon ship alongside ISO in W2/W3, deferring BSI to W4?**
   Mittelstand-SaaS market = no BSI, but plenty of DORA flowdown.

2. **§9.2 Bulk-Approval audit-log entry.** "ONE audit-log group entry
   per batch" sounds clean but an external auditor *will* ask "show
   me top-management's individual deliberation per policy" (ISO Cl.
   5.1 leadership commitment). Need a per-document acknowledgement
   record in the batch entry, not just one line. **Confirm: each
   batched document still gets its own `policy-approval` log row,
   the batch entry is the *summary*?** §9.2 final paragraph hints
   at this ("Single workflow-instance transition per Document") but
   make it explicit in the audit-log spec.

3. **§15 Re-generation diff UX.** I will not compare 30-page policies
   character-by-character, ever. Document-level "which sections
   changed" (§15) is right but I need *more*: which **substitution
   variables** changed, which **template version** bumped (v1→v2),
   and a one-line summary "Crypto policy: AES-128 → AES-256, RPO
   tier 4h → 1h". Without that I will manually re-read every
   re-generated doc, defeating the wizard's value. **Sprint W6 must
   ship variable-level diff, not section-level only.**

4. **§8.7 + §15 Translation quality SLA.** ~7000 keys for ISO + DE/EN
   doubled for BSI/DORA/BCM. If a v1 wording has a legal flaw, I
   need to know: how do I *roll back* a generated document that's
   already been approved? §10 says approved docs are immutable.
   So I either supersede with a corrected v2 (admin overhead × 50
   subsidiaries) or run a one-shot "force-reissue" admin flow that
   is NOT in the architecture. **Add a "template-defect-correction"
   path to §10 with required SUPER_ADMIN + audit-log + auto-notify
   all affected tenants.**

5. **§7.3 Override matrix visibility in subsidiary UI.** "Conflicts
   surface as blocking errors" only triggers at *write* time
   (Step 7). Subsidiary CISO running the wizard at Step 4 (risk
   appetite) needs to *see* "Konzern set this to 3, you can only
   pick 1, 2 or 3" in the input control itself, not bounce off a
   validation error 3 steps later. **UX-Specialist concern, but
   I'll flag it from compliance-management angle: poor inheritance
   surfacing → subsidiary CISOs file change-requests to relax
   parent values → my queue floods.**

6. **Compliance-Wizard check-types from BCM input (§9.5 of
   `04-bcm-input.md`).** The BCM specialist proposes 6 new
   check-types (`bcm_policy_present`, etc.). Architecture §13 buries
   this in Sprint W6 ("Compliance-Wizard check-types (BCM coverage)").
   But I *use* the Compliance-Wizard daily and a wizard-generated
   policy that doesn't tick the existing check-types means I get
   false-negative coverage gaps. **Confirm Sprint W3 already wires
   the ISO-equivalent check-types (`iso_top_policy_present`, etc.)
   so my Compliance-Wizard works on day one of W3 ship.**

7. **DPO section-pattern (§0 of `06-dpo-input.md`) implications for
   my SoA UI.** When ISO Acceptable-Use policy gets a privacy-section
   injected (per §0 table, A.5.10), my SoA shows the policy linked
   to A.5.10 + A.5.34 (privacy). Auditor will ask "is the privacy
   coverage sufficient or partial?" Need the SoA evidence-link to
   distinguish "policy covers this control fully" vs "policy covers
   this control via privacy-addendum-section only". **Architecture
   §8.2 has no place for this granularity.** Either a per-section
   coverage flag on `DocumentControlLink` or a tag like
   `coverage:section_only` vs `coverage:full`.

8. **§13 Sprint W6 Alva-Hints "5 Tier-1/2 hints from BCM-Specialist's
   recommendation".** BCM-input §9.6 lists 5 BCM hints. ISO + DORA +
   GDPR addons need their own hints (e.g. "DORA Art. 19 procedure
   missing", "DPIA methodology >24 months stale"). Sprint W6 only
   mentions BCM. **Need a hint catalogue per addon, not just BCM.**

## What's missing

1. **Bulk PDF/archive export of all generated policies for an audit.**
   Auditor walks in, asks "give me everything that's currently
   approved." Today I run a Symfony command. The wizard creates 50+
   docs but no "export-all-approved-as-zip" exists. **Add to W6:
   `app:export-policy-bundle --tenant=X --format=pdf|zip`.**

2. **"What's overdue this month" dashboard widget.** §9.4
   review-reminder is at T-30d, but I want a *single* compliance-
   manager dashboard tile: "3 policies overdue, 7 due in next 30
   days, 2 in failed approval" — with click-through to the
   bulk-review-inbox. This is the daily-driver UI for me.

3. **Cross-subsidiary comparison view.** "Show me all subsidiaries'
   Access Control policies side-by-side" is the #1 question I get
   from group-CISO during quarterly business reviews. Architecture
   has the data (each tenant has its own Document; they share
   `generatedFromTemplate_id`). **Need a `/admin/policy-portfolio?
   template_id=iso27001.access_control` view.**

4. **Roll-up to Konzern: which sub has approved which standards.**
   Today I ask 12 subsidiaries via email; takes 2 weeks. Architecture
   §8.4 hints at "Konzern-CISO's roll-up SoA dashboard" but it's
   parenthetical. **Make this a deliverable in Sprint W4 or W6 —
   group-rollup view that lists every tenant × every standard ×
   `top_mgmt_signoff` status with last-approved date.**

5. **Pre-audit checklist filter "next-due-review within 30 days of
   audit date".** When I know surveillance audit is 2026-09-15, I
   want to see every policy whose `nextReviewDate` falls between
   now and then so I can pre-run the review. The Document module
   already has `nextReviewDate`; just needs the filter UI.

6. **Evidence-tracking beyond the policy document.** ISO Cl. 7.2
   competence + 7.3 awareness require *training delivered*, not just
   a Training Policy that says we'll train. Architecture §11.4 only
   speaks to BCExercise auto-creation. **Add: when ISO HR Security
   Policy is generated, auto-create a TrainingProgramme stub or
   surface an Alva-Hint "Training Policy generated — schedule first
   training session within 90 days".** Mirrors §11.4 BCExercise
   pattern.

## Sprint reorder

§13 ordering is engineering-convenient (domain → core → docs → BSI
→ DORA+BCM → polish). My compliance-value ordering:

1. **Sprint W1 — Domain.** No change. Foundation must come first.
2. **Sprint W3 (was W3) — Document generation + SoA link.** No change;
   this is the value-delivery sprint. SoA integration (§8.2) is the
   thing that makes the wizard *not* just a templater.
3. **Sprint W2 → goes second-after-W1, but with DORA addon merged.**
   Ship ISO baseline + DORA addon together, because the
   Mittelstand-SaaS / FinServ-flowdown market overlap is huge.
   Defer BSI deltas (which only matter for KRITIS / public sector).
4. **Sprint W6's Compliance-Wizard check-types + Alva-Hints + group-
   rollup view → moves to W4** (combined with the W3 deliverable).
   Without these, the wizard ships but my daily-driver workflow
   isn't actually improved.
5. **Sprint W4 (was W4) — BSI Grundschutz extension.** Drops to W5;
   smaller addressable market for me, but big for public-sector
   prospects.
6. **Sprint W5 — BCM addon.** Stays as a discrete sprint; BCM team
   wants this badly but it's not on my critical path until late 2026.
7. **Sprint W6 — Polish + diff UI + Konzern-Defaults wizard.**
   Konzern-Defaults variant (§7.3) is critical for me; should not
   be last. **Pull Konzern-Defaults variant up to W4.**

**Net effect:** I'd ship ISO + DORA + Compliance-Wizard hooks +
group-rollup + Konzern-Defaults by end of W4 (about 12 weeks).
That's the MVP my role can adopt and roll out. BSI + BCM + diff-UI
in W5/W6 is gravy.

## Tooling integration

- **Compliance-Wizard:** §9.5 of `04-bcm-input.md` proposes new
  check-types. ISO baseline (§13 W3) MUST also register
  `iso_top_policy_present`, `iso_topic_policies_count`,
  `iso_soa_evidence_present` etc. — otherwise my
  Compliance-Wizard returns false-negative gaps. **Architecture
  §13 W3 should explicitly include ISO check-type registration.**
  See `project_alva_hint_foundation` memory — same pattern applies.

- **Document module:** wizard creates Documents with
  `isImmutable=true` once approved (§10). My current bulk-edit
  workflows on the Document module (e.g. "tag all 2024-policies
  with `archive`") need to *respect* immutability without breaking.
  **Confirm Document admin actions check `isImmutable`.**

- **Tag entity:** §8.5 introduces 5+ standardised tags. My
  current tag-cloud has ~80 tags; 25 wizard runs × 5 tags = 125
  new tags. Need taxonomy discipline: pre-create the tags as
  *system tags* (non-deletable, distinguishable from user tags).

- **Workflow:** §9.1 reuses `policy-approval` workflow. My
  existing workflow on `Document` is `document-review` (per
  CLAUDE.md). **Confirm `policy-approval` is genuinely new and
  doesn't collide with `document-review`** — name + state-machine
  separation matters for my approval-routing reports.

- **TenantPolicySetting (NEW, §4.1):** standalone new table. My
  existing tenant-config UI surfaces about 40 settings; this
  adds another 30+. **Need a single tenant-config view with
  *grouped* sections (per §7.3 matrix), not two separate admin
  screens.**

- **AuditLogger:** §9.6 reuses existing logger with
  `policy-approval` tag. My audit-log retention is 6 years (DSGVO
  + commercial law). Each wizard run × 25 docs × ~6 lifecycle
  events = 150 log rows per run. 50 subsidiaries × 4 runs/year ×
  6 years = 180k rows. **Audit-log table partitioning or
  archiving strategy not in architecture.**

## Open questions for Phase 4

1. **For ISMS-Specialist:** ISO 27001 Clause 5.2 demands the
   top-level policy is "*available to interested parties as
   appropriate*" (§1.2 g of `01-iso27001-input.md`). My
   subsidiaries publish to a public website. The wizard generates
   `status=draft` only (§1 goal 2 of architecture); does the
   approval workflow then publish to a *separate* public URL, or
   is "available" satisfied by the internal Document being
   accessible to authorised externals on request?

2. **For DPO-Specialist:** §0 of `06-dpo-input.md` collapses 16
   privacy docs into 5 standalone + 10 sections. When the GDPR-
   scope toggle gets *flipped off* later (e.g. tenant divests its
   B2C arm), are the privacy-sections automatically retracted from
   the existing ISO policies, or do they linger as orphan content
   until the next wizard run? My SoA needs to reflect the current
   state, not historical.

3. **For BCM-Specialist:** §9.5 of `04-bcm-input.md` registers 6
   compliance-check-types. The existing ISO 22301 wizard (per
   recent commits) already exists. Does this create overlap?
   Specifically, does the Policy-Wizard's `bcm_policy_present`
   check shadow / conflict with the ISO 22301 wizard's existing
   policy-coverage check?

4. **For ISMS-Specialist + BSI-Specialist jointly:** the
   ISO-BSI dual-compliance row in §3 generates "1 (Cl. 5.2 EN +
   ISMS.1.A4 DE)" as one top-level policy. Auditors of both
   schemes will read separately. Is this *truly* one bilingual
   document, or two documents linked? Architecture §8.6 says
   "both DE and EN bodies generated" but is the BSI-Pruefer happy
   reading an EN doc with a DE annex, or do they need a stand-
   alone DE Sicherheitsleitlinie? This affects my approval-
   chain count.

5. **For ISMS-Specialist:** §11 lists "3 mandatory tailoring
   fields per topic policy". My experience: auditors push back on
   3 fields if those fields are obviously "fill-in-the-blank"
   (e.g. dropdown selections). What's the *content type* of these
   tailoring fields — free-text essay (~200 words), structured
   selections, or a mix? Drives my onboarding-effort estimate per
   tenant: 24 policies × 3 fields × 200 words = 14k words of
   tenant-specific essay-writing. That's a 2-week project, not a
   30-minute wizard.
