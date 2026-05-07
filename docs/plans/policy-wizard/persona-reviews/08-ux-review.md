# UX-Specialist Review — Policy-Wizard Plan

## My profile

12 years SaaS/B2B UX in enterprise IT (GRC, SIEM, asset-mgmt). Shipped
7 multi-step wizards; three got refactored to long-forms inside year one
because users abandoned mid-flow. Figma, WCAG 2.2 AA-fluent, walked the
FairyAurora v4 macros + `/dev/design-system`. Worry: one-off chrome.

## Pattern verdict

The architecture (§6) proposes a **7-step linear wizard with side-bar
progress**. I would NOT ship that as the only mode. My
recommendation: **Hybrid — long-form sectioned page with a thin
"wizard guide" overlay**.

Comparison:

| Pattern | Pro | Con | Verdict |
|---|---|---|---|
| Multi-step linear wizard (§6 status quo) | Forced focus, simple progress | Hides cross-step dependencies; users in Step 5 want to recheck Step 2; abandonment in Step 4-5 typical | OK for first-timers, painful for re-runs |
| Single long-form with anchored ToC | Power-users finish in 5min flat; CISO can scroll-read whole thing before committing | First-timers get overwhelmed by 7 sections worth of fields | OK for re-runs, painful for first-timers |
| **Hybrid: long-form + collapsible sections + optional "Guide me" mode** | Power-users free-scroll, first-timers click "Guide me" and sections auto-collapse to one open at a time with prev/next | Two modes to design + test | **RECOMMENDED** |
| Progressive disclosure (ISO-only by default, reveal more on toggle) | Lowest cognitive load | Hides scope changes; user adds DORA in Step 5 and Step 2 fields they skipped become required → bad backtrack UX | Use INSIDE the hybrid, not as the top pattern |

Concrete proposal: render all 7 sections as `_fa_section` macros on
ONE page with anchored ToC sidebar (sticky on >=lg). Add a "Guide me"
toggle (default ON for first wizard run, default OFF for re-runs) that
collapses all but the active section and shows prev/next chrome. The
sticky sidebar uses `_fa_filter_chip`-styled section nav with state
icons (✓ done · ● current · ○ unfilled · ⚠ blocked-by-conflict).
Re-runs (§10) almost always touch 2-3 sections — forcing a 7-step
linear flow there is hostile.

## Step-by-step UX critique

### Step 1 · Welcome + Standards (§6)

- **Well-designed:** Live document-count preview, inheritance preview
  ("Konzern enforces N values; you can tighten 5"). This is the
  single best decision in §6 — sets expectations.
- **Anxiety:** Standard-checkboxes without consequence-preview hover.
  Toggling DORA from off→on should NOT silently inflate the doc-count
  from 25 to 43 — the user wants to know what the +18 are.
- **Improvement:** `_fa_filter_chip` per standard with on-hover
  popover listing the 6 NEW + 18 EXTENDS docs. Above the count
  preview render an `_fa_kpi_card` row: "Documents: 25", "Approvals:
  25", "Estimated reading-time-for-GF: 4h" so the user understands
  what they are committing the GF to.

### Step 2 · Organisation & Scope

- **Well-designed:** Multi-pick from existing Location entity (data-
  reuse principle).
- **Anxiety:** "Scope statement" is a dreaded textarea — users stare
  at it. No examples, no industry-prefill.
- **Improvement:** Provide 3 industry-preset templates (manufacturing
  / financial-services / SaaS) as one-click chips that fill the
  textarea, user edits. Use `_fa_alva_hint` Tier-2 hint linking to
  "what makes a good scope statement". Climate-change toggle needs a
  one-line explainer — most users will not know it is ISO Amd.1:2024
  driven.

### Step 3 · Roles & Responsibilities

- **Well-designed:** Crisis-team only appears if BCM picked.
- **Anxiety:** "Approval chain: top-management designation" — what
  does that mean to an ISB Practitioner? They map roles, not
  designations.
- **Improvement:** Show user-picker with TomSelect (existing pattern,
  see Memory `project_form_pattern_b_deferred`) filtered by role-
  capability. Render an `_fa_alert variant=info` summarising the
  approval chain in plain language: "Anna L. (CISO) → Bernd K. (DPO,
  privacy policies only) → Carla G. (Geschäftsführung)". Pull from
  the existing User entity, do NOT ask user to type names.

### Step 4 · Risk & Classification

- **Well-designed:** SoA pre-fill from existing baselines.
- **Anxiety:** Annex A applicability with 93 controls is the §13 W2
  abandonment cliff. Junior-Implementers will close the tab here.
- **Improvement:** Default "Apply ISO 27001:2022 baseline (all 93
  controls applicable)" with a single chip; a secondary collapsible
  "Customise applicability" disclosure for power-users. Show
  `_fa_kpi_card` "Controls applicable: 93 / 93" so users know what
  they accepted. Schutzbedarf scheme picker needs a `_fa_alert
  variant=info` showing "BSI default: 3 levels (normal / hoch / sehr
  hoch)" with "Why?" link. Use `_fa_filter_chip` for the 3 vs 4-level
  classification toggle.

### Step 5 · Operational Baselines

- **Well-designed:** DORA fields hidden unless DORA selected.
- **Anxiety:** Crypto-policy + RPO + patch-SLA + RTO in one step is
  4 unrelated topics. Cognitive overload. The DORA-block adds
  entity-type / significance / competent-authority / concentration-
  thresholds — that is a wizard-within-the-wizard.
- **Improvement:** Split into 5a (Tech defaults) + 5b (DORA scope) as
  collapsible sub-sections inside the hybrid long-form. Pre-fill from
  Konzern-Defaults wherever possible (§7). Concentration-threshold is
  a percentage — needs unit-suffixed input + `_fa_alva_hint` Tier-1
  with "DORA Art. 28 default = 10% / 25% / 50%". RTO targets must
  reuse existing BIA-tier output, not re-ask.

### Step 6 · Lifecycle & Cadence

- **Well-designed:** Default 12mo with per-policy override hidden in
  collapsible.
- **Anxiety:** "Auto-publish: NEVER (forced false)" rendered as a
  disabled checkbox is confusing — users wonder if it's broken.
- **Improvement:** Render as a static `_fa_alert variant=info` row:
  "Generated documents always require human approval (ISO 5.2). No
  toggle." Approver designation should reuse Step 3's user pickers,
  not re-ask. Add a `_fa_kpi_card`: "Next-review-due dates will be
  spread across the year to avoid Bulk-Spike."

### Step 7 · Review & Generate

- **Well-designed:** Atomic transaction, hierarchy-conflict warnings
  blocking.
- **Anxiety:** "Generate button" is the moment of commitment. If
  there are 27 settings and 3 conflicts, where ARE the conflicts on
  the screen? Will the button be disabled-with-no-explanation?
- **Improvement:** Use `_fa_confirm` for the irreversible "Generate
  N documents" click. Render conflicts as a top-pinned `_fa_alert
  variant=danger` with a "Jump to conflict" link per row (anchor-
  scroll into the offending section). The summary itself should be
  an `_fa_section` collapsible per step with edit-pencil to that
  step. After generate, navigate to the result page (see Feedback
  Loops below).

## Aurora-V4 component fit

| Use case | Component |
|---|---|
| Wizard chrome top of page | `_fa_page_header` with badge "Policy-Wizard", title, subtitle = current section name, actions = Save & Resume / Cancel |
| Each of the 7 sections | `_fa_section` with title + tools (edit-pencil/jump) + footer (prev/next when "Guide me" mode active) |
| Override-conflict / missing-prereq warnings (§7.3) | `_fa_alert variant=danger` (blocking) and `variant=warning` (informational) |
| "No wizard runs yet" / "No previous runs to resume" | `_fa_empty_state` with Alva mood = encouraging, CTA = "Start your first wizard run" |
| Document count / approvals count / estimated time | `_fa_kpi_card` (3 cards in a row at top of Step 1, again on Step 7 review) |
| Standards toggles (ISO / BSI / DORA / BCM / GDPR / 27701) | `_fa_filter_chip` group, multi-select |
| "Discard wizard run?" / "Generate N docs?" / "Publish bulk?" | `_fa_confirm` (Sprint 3 macro per CLAUDE.md) — perfect fit for irreversibles |
| Server-side variable substitution preview loading | `_fa_skel` skeleton rows |
| Tier-1/2 contextual hints in Steps 2/4/5 | `_fa_alva_hint` (existing foundation per Memory) |
| Per-policy entity card on result page | `_fa_entity_card` with entity-type=policy, status badge |
| Policy/finding/etc. type badges in approval inbox | `_fa_entity_badge` |
| Bulk-approval inbox row | `_fa_audit_row` adapted, plus tickbox |
| Approval-trail widget (§9.6) | `_audit_timeline.html.twig` (existing) — perfect, no new component |

**New components to consider** (and why I would push back on most):

- "Wizard sidebar" with section nav + state icons. **NOT a new
  macro** — compose `_fa_section` + a sticky `<nav>` of
  `_fa_filter_chip`-styled buttons.
- "Standards-comparison preview drawer" (Step 1 hover detail). Could
  reuse `_dropdown_panel.html.twig`.
- "Document-diff viewer" for §10 re-run diff. THIS one needs a new
  macro `_fa_doc_diff`. Defer to Sprint W6 per §13. Two-column
  before/after, highlight-changed sections, no character-level diff
  for v1.

## Mobile / responsive concerns

- **Bulk-approval-inbox (§9.2) on phone:** Approving 25 policies via
  thumb-checkboxes with shared-rationale-textarea is technically
  doable but practically the GF will not do it. The 4-eye dual-
  signoff flag (§9.3) makes it worse. Reality check: GF will do this
  on desktop in 2/3 cases. My recommendation: ship a mobile-friendly
  read-only summary with "Approve via desktop required" CTA + email-
  link-back-to-desktop for any tenant with `bulkApprovalDualSignoff
  =true`. For single-policy approval, mobile fine.
- **Step 4 Annex A applicability on mobile:** A 93-row toggle list is
  abandonment-territory on a 375px viewport. Default "Apply baseline"
  one-tap chip solves this.
- **Approval-trail widget (§9.6) on 320px:** Existing
  `_audit_timeline.html.twig` is already responsive — confirmed.
- **The hybrid pattern recommended above** scales: long-form on
  desktop, sections collapse to accordion on `<md` automatically.

## Accessibility (WCAG 2.1 AA / 2.2 AA)

- **Keyboard navigation across steps:** Tab order must walk header →
  sidebar nav → current section content → prev/next footer.
  `tabindex="0"` on the sidebar nav `<a>` elements; arrow-key
  navigation within the sidebar via Stimulus controller (similar
  pattern to existing `command_palette` controller).
- **Screen-reader step transitions:** Need `aria-live="polite"`
  region announcing "Step 4 of 7: Risk and Classification" on
  navigation. Section heading is `<h2>`, and `aria-current="step"`
  on the active sidebar nav item.
- **Bulk-approval tick-box semantics:** Each row needs `<label
  for="approve-doc-N">` with hidden text "Approve policy: <title>"
  for SR users. Header tickbox needs role="checkbox" aria-checked
  with indeterminate state when partial selection. The shared
  rationale textarea needs `aria-describedby` pointing at the count-
  of-selected indicator.
- **Approval-trail timestamps:** Use `<time datetime="2026-09-30T14:22:00Z">`
  semantic element so SRs read both relative ("3 weeks ago") and
  absolute time. Existing `_audit_timeline` already does this —
  confirm in code review.
- **Variable-substitution markers (§11.2):** "MyCompany GmbH" visible
  with the `{{ tenant.legal_name }}` in a footer comment is
  problematic for SRs because the rendered prose reads "MyCompany
  GmbH" twice (once in body, once in comment) and confuses the user.
  Recommendation: footer markers wrapped in `<aside aria-label="
  Variable-Substitutionen für Auditoren">`, with the prose itself
  having `aria-describedby` pointing to the aside. SRs reach footer
  on demand, not in main reading flow. Consider hiding behind a
  collapsed `<details>` element so visually-impaired users do not
  hear the meta-text every time. Discuss with auditor persona — this
  is the load-bearing decision.
- **Color-only conflict indication is forbidden.** Conflict alerts
  must use `_fa_alert variant=danger` which has icon + color + label.

## Microcopy / wording

These are the worst offenders (architecture jargon hitting users):

| Plan wording | User-facing copy proposal |
|---|---|
| "Hierarchy override matrix conflicts" (§7.3) | "Konzern-CISO has set this stricter — you can't relax it. Ask them to adjust the Konzern-Defaults if you need it." |
| "DocumentControlLink" (§8.1) | Never shown. Users see "Linked to control A.5.15 — Identity Management". |
| "TenantPolicySetting overrideMode = forbidden" (§4.1) | "Locked at Konzern level — contact your group CISO." |
| "WizardRun.status = failed" (§4.1) | "Generation failed. Your settings are saved — re-try from Step 7 or contact support." |
| "Auto-publish: NEVER (forced false)" (§6) | "Generated documents always go to draft — your team approves before publishing." |
| "Mandatory tailoring fields" (§11.1) | "Add at least one tenant-specific paragraph here. Auditors will ask why your policy looks generic if you skip this." |
| "isImmutable=true" (§10) | "Approved — to change, create a new version." (button label "New version" not "Edit") |
| "ApprovalKickoff dispatch confirmation" (§6 Step 7) | "Approval workflows started for 25 documents — your CISO and GF will see them in their inbox." |
| "Variable-substitution markers stay visible" (§11.2) | Footer label: "Auditor reference — variables auto-substituted from tenant data on YYYY-MM-DD". |
| "Top-management batch-approval" (§9.5) | "Geschäftsführung hat 25 Policies gemeinsam freigegeben am …" (DE), "Top-management approved 25 policies at once on …" (EN). |
| "Climate-change wording toggle" (§6 Step 2) | "Include climate-change considerations (ISO 27001:2022 Amd 1:2024 — recommended)" with `_fa_alva_hint` linking to explanation. |
| "review_no_change log entry" (§9.4) | UI label: "Confirmed annual review — no changes needed." |

Every translation key needs a domain. Recommend new domain
`policy_wizard` (and `policy_approval` for the inbox).

## Wizard-abandonment risk

Drop-off heat-map (where users will leave):

1. **Step 4 (Annex A applicability)** — highest abandonment risk.
   Mitigation: baseline-default chip, see Step 4 critique above.
2. **Step 5 (Operational Baselines)** — second-highest, especially
   when DORA is selected. Mitigation: split into 5a/5b, pre-fill from
   existing modules.
3. **Step 7 (conflict-blocked Generate button)** — users hit a wall
   they cannot clear without contacting Konzern-CISO. Mitigation:
   one-click "Send override request to Konzern-CISO" with templated
   email + tracking — turns blocker into a workflow.
4. **First-time first-step paralysis** — "what should I pick?".
   Mitigation: an industry-preset row at top of Step 1: "Most
   manufacturing tenants pick: ISO + BSI + BCM. Apply preset?" with
   one-click `_fa_filter_chip` selection.

Recovery UX (none of this is in §6 yet):

- **Resume after browser close (§6 mentions state persistence):**
  Render a "Resume your last wizard run from 2 days ago — you were on
  Step 5 (3/7 sections complete)" `_fa_alert variant=info` on the
  wizard landing page.
- **Accidental cancel:** Cancel-button must trigger `_fa_confirm`
  ("Discard 4 sections of progress? You can resume any time within
  30 days from the wizard list."). Soft-delete WizardRun for 30 days
  before hard-delete.
- **Session expired in Step 5:** Auto-save on every field-change (
  Stimulus debounce 500ms) — when re-login redirects, deep-link back
  to the same step. Add "last saved 3 minutes ago" indicator in
  header.
- **Email-based wizard-resume-link:** YES, recommend. After Step 1
  completion, send "We saved your wizard run — resume here:
  [secure-link]" email. Link expires in 14 days. Drives recovery for
  users who close the tab on day 1 and forget for a week.

## Feedback loops

After Generate (Step 7):

1. Immediate redirect to a result page (do NOT just show a toast).
2. Top of page: `_fa_page_header` with success badge "25 documents
   generated".
3. `_fa_kpi_card` row: "Documents created: 25 · Awaiting CISO review:
   25 · Awaiting DPO cross-check: 5 · Awaiting top-mgmt: 0".
4. Animation: subtle confetti ONCE (not on every page-load). Use
   `prefers-reduced-motion` to suppress.
5. List of generated documents as `_fa_entity_card` grid, grouped by
   topic, with "Open document", "View approval trail", "Download as
   PDF" actions per card.
6. Sticky bottom CTA-bar: "Send approval-summary to CISO" (one
   click → templated email with the list). This is the biggest win
   for momentum: the wizard hands off, the next role takes over,
   user feels finished.
7. After 5 seconds, render an `_fa_alva_hint` Tier-2 below the list:
   "5 of these touch personal data and need DPO sign-off. We notified
   Bernd K. already." — turns a worry into a closed loop.

For ERROR (atomic rollback per §6 Step 7): `_fa_alert variant=danger`
with full conflict-list and "Jump to conflicting field" anchors.
Never show a generic "something went wrong".

## Demo / Sandbox-mode

§6 does not support a "preview without saving" mode. **It must.**

Proposal: a "Preview run" toggle on Step 1. When enabled:

- WizardRun is marked `status='preview'`.
- All steps 1-6 work identically.
- Step 7 "Generate" button changes to "Show preview" — generates
  documents in-memory or in a temp-table, displays them as
  read-only HTML in a modal/drawer, but DOES NOT persist anything to
  the Document table, NOT to TenantPolicySetting, NOT to SoA.
- A "Convert to real run" button at the end converts the preview to
  a real run if the user is satisfied.
- This unlocks the "what does my GF actually see" demo for the
  CISO before committing.

Implementation cost: probably 2-3 days. Value: prevents the "I
created 25 garbage policies and now have to delete them" support-
ticket which absolutely will happen otherwise.

## What I love

1. **Live document-count preview in Step 1** (§6) — sets expectation
   honestly, fights commitment-fear.
2. **Reusing existing `Workflow` machinery** (§9) — no parallel
   approval state-machine, less divergence.
3. **Bulk-Approval-Inbox grouped by wizard_run** (§9.2) — matches
   how the GF actually thinks ("the docs from THIS exercise").
4. **Review-without-changes fast-path** (§9.4) — recognises that 80%
   of annual reviews require no edits. This single decision saves
   the GF hundreds of clicks per year.
5. **Atomic transaction at generate-time** (§8 + §6 Step 7) — failure
   semantics are predictable, no half-baked partial state.

## What I worry about

1. **§6 — 7-step linear flow as the only mode.** Painful for re-runs.
   Hybrid long-form mandatory. See Pattern verdict.
2. **§6 Step 4 — Annex A applicability with 93 controls.** Will be
   abandonment cliff #1. Need baseline-default chip + collapsible
   power-user-mode.
3. **§6 Step 5 — DORA-block inside step 5.** Wizard-within-wizard.
   Split visually.
4. **§7.3 conflict-blocking with no escape valve.** Users hit a wall;
   they need a one-click "request override from Konzern-CISO" path,
   otherwise they re-open support tickets.
5. **§9.2 Bulk-approval-inbox on mobile.** Practically unusable; need
   "approve via desktop" graceful fallback when 4-eye is on.
6. **§11.2 Variable-substitution markers visible in policy footer.**
   Accessibility risk for SRs (reads the variable name AND the
   substituted value); auditor benefit unclear vs. an "evidence
   appendix" approach. Discuss with auditor persona.
7. **§13 W3 7000 translation keys.** Translation-quality and
   versioning across DE+EN is a real risk. Need professional legal
   text review (already flagged in §15). UX-side: provide a
   translation-coverage `_fa_kpi_card` in admin to detect missing
   keys before user-facing rendering.
8. **No preview/sandbox mode in §6.** Add it. Section above.

## Sprint priority (UX-led)

Reordering §13 by user-experience risk:

| Original order | UX-priority | Why |
|---|---|---|
| W1 — Domain | **W1 (unchanged)** | Need entities before anything renders |
| W2 — ISO 27001 wizard core | **W2 (unchanged)** | But MUST include hybrid long-form pattern + state persistence + email-resume-link from day 1 — not deferred |
| W3 — Document generation + SoA | **W3 (unchanged)** | But split: W3a generation, W3b post-generate result page + bulk-approval-inbox skeleton |
| W4 — BSI extension | Move to W6 | BSI is additive on top of working ISO. Get UX feedback on ISO-flow first. |
| W5 — DORA + BCM | Move to W5 | DORA is the highest-revenue pull (regulated tenants want it now). BCM has its own existing module — generation can wait. |
| W6 — Polish + Persona-recommended UX | Move forward to **W4** | Konzern-Defaults wizard variant + re-run diff + Alva-Hints are critical for re-run UX, which §10 hand-waves. Polish is not optional, it's load-bearing for retention. |

Revised: **W1 Domain → W2 ISO wizard (with hybrid + persistence) →
W3 Generation + Result page + Approval inbox → W4 Polish (re-run
diff, Konzern-Defaults, Alva-Hints, sandbox mode) → W5 DORA addon →
W6 BSI + BCM**.

Single biggest UX-win moved earlier: **sandbox/preview mode in W4**,
not buried in §6 absent.

## Open questions for Phase 4

1. **DPO-Specialist:** §11.2 variable-substitution-markers visible in
   footer — does the auditor benefit outweigh the SR/cognitive cost?
   Could we surface this in an "Evidence appendix" view instead of
   the rendered policy itself?
2. **Senior-Consultant:** Industry-presets in Step 1 — what 4-6
   industry-categories cover ~80% of expected tenants? (Manufacturing
   / FinServ / SaaS / Healthcare / Public-sector / Energy?) Drives
   the preset-chip set in Step 1.
3. **External-Auditor:** Is the bulk-approval-inbox audit-log entry
   ("25 policies approved together by GF, rationale: …") accepted as
   evidence, or does each policy need an individual signed approval?
   Drives whether bulk is mode-toggle or default.
4. **Junior-Implementer:** Of the 6 Step-1 standards toggles, how
   many do they understand without a tooltip? Drives how aggressive
   the inline `_fa_alva_hint` Tier-1 hints need to be in Step 1.
5. **CISO-Executive:** Sandbox/preview mode — would they actually use
   it, or just trust the doc-count preview? Drives whether sandbox
   is W4 priority or W6 polish.
