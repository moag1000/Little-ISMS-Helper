# Junior-Implementer Review — Policy-Wizard Plan

## My profile
I came from the IT-helpdesk 14 months ago — I can reset Active-Directory passwords in my sleep but the words "Annex A applicability" make my palms sweat. I have read ISO 27001 once cover-to-cover but only really understand the chapters I had to write a procedure for. Daily I am overwhelmed by acronyms in meetings, the difference between a "policy" and a "procedure" and a "methodology", and the fear of approving something my manager will quietly fix later.

## Comprehension verdict
First-pass comprehension: roughly **55%**. I followed §1-3 fine ("here is what gets generated"), partly understood §4-5 (entities + services — my dev background helped), and got increasingly lost from §7 (hierarchy/inheritance) onward. I bailed out mentally at §8.4 "Cascade through framework-inheritance" — the words made sense individually but I could not picture what actually changes on screen. §9 (approval) was readable but felt like five different concepts stacked. §10-12 I skim-read because I was already tired.

## Jargon I needed to look up
1. **SoA** (Statement of Applicability) — the master list saying "yes/no this control applies to us, here is why".
2. **Annex A** — the 93-control catalogue at the back of ISO 27001 you pick from.
3. **RoPA** (Records of Processing Activities) — the GDPR list of "what personal data we touch and why".
4. **DPIA** (Data Protection Impact Assessment) — the deep-dive risk study you do before processing risky personal data.
5. **DSR** (Data Subject Request) — when someone asks "what data do you have on me / delete it".
6. **CTPP** (Critical Third-Party Provider) — a DORA term for very-important external IT suppliers.
7. **RTS / ITS** — Regulatory / Implementing Technical Standards, the EU rulebook that sits under DORA. I thought RTS meant "real-time something".
8. **BIA** (Business Impact Analysis) — figuring out which processes hurt most when they break.
9. **MTPD / RTO / RPO** — Maximum Tolerable Period of Disruption / Recovery Time / Recovery Point Objective. Continuity timing knobs. I always forget which is which.
10. **Schutzbedarf** — BSI-speak for "how badly we need to protect this thing" (low/normal/high/very-high).
11. **Baustein** — BSI building-block, basically a mini-policy area like "ORP.4 Identity Management".
12. **PIMS** (Privacy Information Management System) — ISO 27701's name for an ISMS-but-for-privacy.
13. **ISO Cl. 5.2** — the clause that forces top-management to sign the top-level Information Security Policy.
14. **Konzern / Tochter** — German for parent-group / subsidiary tenant in the multi-tenant hierarchy.
15. **KRITIS** — German "critical infrastructure" sector designation under BSIG §8a.

## Wizard steps that scare me

- Step 1 (Welcome + Standards) — confidence **4** — checkboxes I can do, the document-count preview is reassuring.
- Step 2 (Organisation + Scope) — confidence **3** — names and addresses fine, but "scope statement" I have never written from scratch.
- Step 3 (Roles) — confidence **3** — I can pick people, but I do not know who the "BCM-Officer" is in our company; we may not have one.
- Step 4 (Risk + Classification) — confidence **1** ← lowest.
- Step 5 (Operational baselines) — confidence **2** — crypto algorithms and RPO tiers are senior-engineer territory.
- Step 6 (Lifecycle + Cadence) — confidence **3** — defaults probably fine, but "approver designation per document" is a lot of dropdowns.
- Step 7 (Review + Generate) — confidence **4** — read-only summary I can handle; "atomic transaction" sounds reassuring.

### Step 4 details (lowest)
**Inputs I cannot answer:**
- "Risk appetite tier 1-5" — I have no idea what number management would pick. Is 1 conservative or aggressive?
- "Data classification scheme — 3 vs 4 levels" — what is the trade-off?
- "Schutzbedarf scheme" — only relevant if BSI; I would not know whether to pick 3-tier default or change it.
- "Annex A applicability" — even with baseline pre-fill I would just click "next" without reading.

**Terms not explained at this step:** "applicability", "baseline confidence", "Schutzbedarf" itself, "appetite tier".

**Defaults / smart fallbacks I want:**
- A clearly-marked **"use safe defaults"** button that sets conservative values (tier 2 appetite, 4-level classification, all Annex A applicable unless baseline says otherwise) and lets management adjust later in re-run.
- A "skip — I will fill in with my CISO afterwards" button that flags the step yellow on the summary page so I cannot accidentally generate without addressing it.

### Step 5 details (second-lowest)
- "Allowed crypto algorithms + key strengths" — I would not know whether "AES-256, RSA-3072" is current best practice or 2018 advice. Wizard should ship with **a vetted "current best practice" preset (timestamped)** I just confirm.
- "Patch SLA hours per severity" — I genuinely do not know what we do today. Wizard should pull our actual numbers from existing Patch-Management module if any, or show **regulator-derived defaults** (DORA = 24h critical etc.).

## Tenant-settings that need pre-fill
For each step, please pre-fill from existing data so I am not guessing:

**Step 2 — Organisation & Scope**
- Legal name → from `Tenant.legalName`
- Addresses → from `Location` entity (multi-pick already; pre-tick "all in-scope locations")
- Scope statement → carry from previous `WizardRun.inputs` if exists, else from `TenantPolicySetting('isms.scope_statement')`

**Step 3 — Roles**
- CISO/ISB → from `User` entity filtered by `ROLE_CISO` (single hit = pre-select)
- DPO → from User entity by `ROLE_DPO`, or from existing DPO-Charter document if loaded
- BCM-Officer → existing `CrisisTeam` lead if present
- Approval chain → from existing `Workflow.approvalChain` of the most recent approved Document

**Step 4 — Risk & Classification**
- Risk appetite tier → from `RiskAppetite` entity if any record exists
- Data classification scheme → from `Asset.classificationLevels` count if assets already classified
- Schutzbedarf scheme → from `IndustryBaseline` if BSI baseline loaded
- Annex A applicability → from existing `StatementOfApplicabilityEntry` rows; mark each one with its source ("from Industry-Baseline 80%", "manually decided 2025-11-04")

**Step 5 — Operational baselines**
- Backup RPO → from `BackupService` config or last `BCPlan.rpoMinutes`
- Crypto policy → from `Crypto`-module current config if module enabled
- Patch SLA → from `Patches` module current SLA settings
- DORA entity-type / authority → from `Tenant.regulatoryProfile`

**Step 6 — Lifecycle & Cadence**
- Default review interval → existing `Document` rows' `reviewIntervalMonths` average
- Per-document approver → previous Document.approver per template-key
- Next-due Alva-Hint → already-implemented foundation; just confirm enabled

## Help-text wishes
Concrete tooltip/help-modal text I want to actually see:

- **Step 1 standard checkbox "DORA":** "Tick this only if your company is a financial entity — bank, insurer, payment service, crypto. If unsure: leave unticked, you can add later."
- **Step 2 scope statement:** "Two sentences: (1) Which sites/products/services are protected? (2) What is excluded? Example: 'All IT services run from Berlin and Hamburg HQ for our SaaS product XY. Our subsidiary in Poland is excluded.'"
- **Step 3 Top-management:** "Pick the person/role who legally signs corporate policies — typically CEO, Geschäftsführer, or Board. Required by ISO 27001 Clause 5.2."
- **Step 4 risk appetite:** "1 = very conservative (banks, healthcare). 5 = high tolerance (early-stage startup). Most established SMEs land at 2 or 3. Pick 2 if unsure — your CISO can change this in re-run."
- **Step 4 Annex A applicability:** "These are the 93 ISO 27001 security controls. 'Applicable = yes' means the control is relevant to you and you commit to meeting it. We pre-filled from your baseline; review the highlighted rows."
- **Step 5 crypto:** "We pre-loaded BSI TR-02102-1 (2026) recommendations. Click 'why this list?' to see the source. Change only if your CISO has explicit reasons."
- **Step 6 review interval:** "12 months is the ISO recommended default and what auditors expect. Longer than 24 months will fail most audits."
- **Step 7 generate button:** "This creates X documents as DRAFT — nothing is published yet. Your CISO + top-management still need to approve each. You can re-run this wizard anytime."

## Annex A applicability terror (Step 4)
What the wizard should show in tricky cases:

- **Control with 0% baseline confidence:** show a yellow "We could not pre-decide this for you — please discuss with your CISO" badge. Default value is `applicable=true` (defensive — auditors prefer over-inclusion). A "Defer to CISO" button parks the row without blocking generation.
- **I do not know if applicable:** show the control title + a one-sentence plain-language description ("this is about checking who is allowed to access what") + a "Show me what would happen if I tick yes / tick no" expandable preview. Plus a third option **"Mark for CISO review"** that pre-selects `applicable=true` AND adds the row to a follow-up TODO list.
- **Wizard says applicable, I disagree:** clicking "Not applicable" pops a small justification box ("ISO requires you state WHY you exclude a control"). Pre-filled radio choices: "We do not have this technology", "Outsourced fully to provider X", "Not relevant to our scope", "Other (write text)". Auditors specifically look for these justifications; the radio prevents me writing junk.
- **Industry-specific nuance:** tag the control row with an icon "Healthcare-specific" / "Finance-specific" if my industry-baseline hits it; tooltip explains the nuance in 1 sentence and links to the source standard clause. If no baseline match, no extra tag — clean view.

## Approval-workflow confusion (§9)
Bulk-approval (§9.2), per-tenant config (§9.3), no-change-review fast-path (§9.4) is too much for a 90-day-tenant. **One simplification I want by default:**

> **First-90-days mode**: hide §9.3 entirely (use sensible defaults), hide §9.2 bulk-approval until at least 5 documents are pending, hide §9.4 fast-path until first review-cycle is reached. Show a single linear chain: `Wizard prepares draft → CISO reviews → Top-Mgmt signs → Published`. Provide a "Show advanced approval options" link for when management asks. The system already has all the machinery; just gate the UI complexity until the tenant has lived with the simple flow once.

## Re-generation confusion (§10)
"Hash-compare substitution variables" in plain language: the wizard remembers what answers I gave last time. When I re-run it and an answer changes (e.g. I picked a new DPO), the wizard notices and offers to re-generate the affected documents.

**What the UI must show me:**
1. A list "These N documents will get a NEW version because you changed X, Y, Z" — with the changed variable name in plain language ("DPO name was 'Alice Müller', now 'Bob Schmidt'").
2. A list "These M documents stay unchanged — we will skip them" — collapsed by default.
3. A list "These K documents are still draft — content will be replaced (no version bump)".
4. Per-row "Preview the new version" link (side-by-side or just-the-changed-section diff is fine).
5. A big "Confirm — generate the N changed versions" button. With my name + timestamp on the audit log.

Anything more granular than that loses me.

## Mistakes I'd make if no guard rails
1. **Approving as my own user when I'm both wizard-runner AND listed approver.** Wizard must block and say "Pick a different approver — you cannot sign your own work" (4-eye principle).
2. **Setting review interval to 60 months** so I am not bothered. Wizard must hard-cap at 24 months and warn at >12 ("auditors expect 12; >24 fails most audits").
3. **Skipping Step 4 entirely** by clicking next-next-next. Wizard must require at least one explicit click on each scary step (no pure pass-through).
4. **Ticking ISO + BSI + DORA + GDPR + BCM** because all sound important. Wizard preview should warn: "This will generate 52 documents and require ~6 approval cycles. Most companies start with ISO + GDPR. Are you sure?" with a "start small, add later" alternative.
5. **Risk appetite tier 5** without knowing what 5 means. Wizard must show plain-language consequence: "Tier 5 = aggressive — auditors will challenge this. Most SMEs use 2-3."
6. **Naming DPO as "TBD" or my own name** to bypass the field. Wizard must require a real `User`-entity pick (not free-text), and warn if the same person is named for CISO + DPO + BCM-Officer (independence concern).
7. **Microenterprise fork**: I might tick "microenterprise" because the simpler path looks attractive even if our company is too big. Wizard must cross-check against `Tenant.headcount` / `Tenant.balanceSheetTotal` if known and show a warning.
8. **Generating in the middle of an existing approval cycle.** Wizard must detect "you have N pending approvals from a previous run" and offer to either wait or supersede explicitly.

## What I love
1. **Auto pre-fill from existing tenant data (§5 VariableCollector).** Means I do not invent answers.
2. **Never auto-publish (§11.5).** I will not accidentally make a half-baked policy live.
3. **State persistence — resume after browser close (§6).** I can ask my CISO between Step 4 and Step 5 without losing work.
4. **Hierarchy preview at Step 1 ("Konzern enforces these N values").** I see upfront what I cannot change, instead of hitting validation errors at Step 7.
5. **Tagging every generated doc with `wizard-run:<id>` (§8.5).** If I mess up I can find all 25 docs and the CISO can roll them back as a batch.

## Open questions for Phase 4 (plain English)
1. **Is RTS the same thing as a regulation?** When the plan says "DORA RTS-on-subcontracting pending" — is that a separate document I need to read, or just a footnote about DORA itself?
2. **What is the practical difference between "policy", "procedure", "programme", and "methodology"?** The §3 matrix mixes all four — I want one paragraph explaining when to use which (or whether the wizard fully decides this for me).
3. **For ISO 27701 (PIMS) — if my company already has a Privacy Policy from the GDPR-section approach (§3 row 7), do we need the +2-4 PIMS docs too, or are they redundant?** I cannot tell from the table.
4. **In §8.2 the wizard sets implementation-status to `partial_documented` — what does the auditor expect to see at `fully_implemented`?** Documented + tested + evidence? A 1-line example would help me set expectations.
5. **Step 4 risk-appetite tier 1-5: is 1 conservative or 5 conservative?** The plan does not spell out the direction. I will get this wrong unless someone writes it down.
