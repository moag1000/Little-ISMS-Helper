# DPO Addon — Privacy Policies and Programmes

> **Phase 1-E specialist input — DPO / Datenschutzbeauftragter view.**
> Builds on `01-iso27001-input.md`, `02-bsi-input.md`, `03-dora-input.md`,
> `04-bcm-input.md` and the architectural synthesis in `05-architecture.md`.
>
> Position in the wizard: this addon attaches to the ISO 27001 baseline
> (and equally to BSI Grundschutz — `CON.2` is the BSI anchor) the same
> way DORA does — opt-in, parallel, never duplicates the others.
>
> **Existing modules to REUSE (never duplicate):** `ProcessingActivity`,
> `DataProtectionImpactAssessment` (DPIA), `DataSubjectRequest` (DSR),
> `DataBreach`, `Consent`. The wizard generates the **frameworks /
> methodologies / policies** that govern how these modules are used —
> the records inside them stay artefacts of operational execution.

---

## 0. Decision Matrix v2 — Section vs Standalone (post-self-review)

User feedback (2026-05-07): the DPO contribution must MERGE into the
existing ISO 27001 / BSI / DORA / BCM policies wherever possible to
avoid duplicate-document sprawl. Only the genuinely standalone
privacy documents that have no ISMS twin remain on their own.

This v2 matrix supersedes the v1 table after the self-review in
`persona-reviews/09-dpo-self-review.md`. v1 mis-routed three rows
(Lawful-Basis, Consent, Children's, Special-Category) into ISO host
policies that don't fit either by audience or by self-contradiction
with §8.1's A.5.34-suppression rule. v2 reverses those rows, adds
five new rows (Cookie/ePrivacy, AI Act, Sectoral DPO mandate, DPO
section-veto mechanic, Lead-DPA logic), and replaces "A.5.34
suppressed" with "A.5.34 thin cross-reference host kept" so the ISO
"shall maintain a topic-specific policy" wording (Cl. 5.2 +
A.5.1) is satisfied without content duplication.

The 16 documents enumerated in §2 plus 5 newly surfaced concerns are
classified accordingly:

| # | Privacy concern | v1 verdict | v2 verdict | Host / target |
|---|---|---|---|---|
| 1 (§2.1) | Privacy / Data-Protection Policy (top-level) | section in ISO Cl. 5.2 | **section in ISO Top-Level Policy (Cl. 5.2)** — kept | Cl. 5.2 / ISMS.1.A4. Auto-fallback to standalone when `dpo.is_group_dpo = true` OR `lead_supervisory_authority` crosses ≥ 2 jurisdictions (per self-review item 1) |
| 2 (§2.5) | Data Breach Notification Procedure | section in ISO Incident Mgmt + DORA Art. 19 | **section in ISO Incident Mgmt + DORA Art. 19** — kept | A.5.24-A.5.28 / DORA Art. 19 (Reg. 2022/2554) / NIS2 Art. 23. Three notification-tracks as sub-sections under one host. Per-section ownership tag mandatory (CISO / DPO / DORA-officer) |
| 3 (§2.6) | Lawful-Basis Determination Methodology | section in A.5.10 + A.5.12 | **STANDALONE — sub-procedure of RoPA Methodology** (REVERSED) | None. Lawful-basis attaches to processing activities (GDPR Art. 6/9/10), not to acceptable-use or classification. Bundled with §2.2 RoPA so the artefact authors (product owners, marketing, HR) read it where they decide on a new processing activity, not in an asset-classification policy |
| 4 (§2.7) | Consent Management Policy | section in A.5.12 | **STANDALONE — sub-procedure of RoPA Methodology** (REVERSED) | None. Consent (GDPR Art. 7 + EDPB Guidelines 05/2020) is recorded against `ProcessingActivity` and against the existing `Consent` entity, not against information-classification labels. Co-located with §2.6 in the RoPA Methodology so authors see lawful-basis + consent decision-tree as one chapter |
| 5 (§2.8) | Joint-Controller Agreements Methodology | section in A.5.19-A.5.22 | **section in ISO Supplier Relationships Policy** — kept | A.5.19-A.5.22. Art. 26 GDPR joint-controllership is structurally identical to A.5.19. Sub-section header must disambiguate "joint-controller (Art. 26)" vs. "processor (Art. 28)" |
| 6 (§2.9) | DPA Template | OUT-OF-SCOPE | **OUT-OF-SCOPE** — kept | A.5.20 (cross-ref only). Ships as Document-Library file (RTF/DOCX), not as a generated policy. Phase 2 candidate for live-generator with `Supplier`-reuse |
| 7 (§2.10) | International Transfers | section in A.5.14 | **section in ISO Information Transfer Policy** — kept (with scope-flag header) | A.5.14. Section pre-pended with header "Applies only when personal data crosses non-adequate jurisdictions" so non-EU auditors (US SOC 2, SG PDPA) can skip cleanly. Rendered conditionally on `privacy.international_transfers = true` |
| 8 (§2.11 schedule) | Retention Schedule (matrix) | standalone appendix | **STANDALONE — Retention Schedule** kept | None. Operational matrix per data category. `retention_authority: 'schedule'` template flag enforces single-source-of-truth: A.8.13 + A.8.15 sections may *reference* the schedule but MUST NOT restate retention durations (CI lint gate per self-review concern 6) |
| 9 (§2.11 mechanics) | Retention & Deletion mechanics | section in A.8.13 + A.8.15 | **section in A.8.13 (Backup) + A.8.15 (Logging) + A.8.10 (Deletion)** — kept | A.8.10, A.8.13, A.8.15. Sections describe mechanics (logical/physical/anonymisation). Durations live in §8 above. BSI `CON.6 Löschen und Vernichten` cross-references this row |
| 10 (§2.12) | Privacy-by-Design Methodology | section in A.5.8 + A.8.27 | **section in ISO PM/SDLC** — kept | A.5.8, A.8.27, A.8.28. Project gate-entry + secure design-pattern selection. Most elegant fit in v1; no reversal needed |
| 11 (§2.14) | Privacy Training & Awareness | appendix to A.6.3 | **appendix to A.6.3 ISO Awareness Programme** — kept (with bloat-watch) | A.6.3. Privacy-specific audience segments (HR, marketing, sub-processor-managers) appended to the parent. Phase 5 W6 polish: if appendix exceeds ~30% of parent length, promote to standalone per self-review item 9 |
| 12 (§2.13) | DPO Charter / Appointment | STANDALONE | **STANDALONE** — kept | None (role-charter, GDPR Art. 37/38/39). Self-approval prohibition at §9.5 stays |
| 13 (§2.2 + §2.6 + §2.7) | RoPA Methodology (now bundles Lawful-Basis + Consent) | STANDALONE | **STANDALONE — expanded to host §2.6 + §2.7 as sub-procedures** | None (governs `ProcessingActivity` + `Consent` modules). Document layout: §1 RoPA core (Art. 30) / §2 Lawful-Basis Determination (Art. 6/9/10 decision tree, BDSG §22/§24/§26 overlays) / §3 Consent Management (Art. 7 + 8, EDPB 05/2020, withdrawal procedure) |
| 14 (§2.3) | DPIA Methodology | STANDALONE | **STANDALONE** — kept | None (governs `DataProtectionImpactAssessment` module). §1 introduction must explicitly delimit "DPIA = privacy-risk *to data subjects*; ISO 27005 = security risk *to organisation*" so it doesn't read as a duplicate-method per self-review item-2 critique |
| 15 (§2.4) | Data-Subject-Rights Procedure | STANDALONE | **STANDALONE** — kept | None (governs `DataSubjectRequest` SLA, Art. 12(3) 1-month clock). No ISO equivalent |
| 16 (§2.15) | Children's Data Policy *(conditional)* | section in A.5.34 | **APPENDIX to §2.1 Privacy Policy** (REVERSED) | Inside §2.1, which is itself a section in ISO Cl. 5.2. Resolves the v1 self-contradiction: §8.1 said A.5.34 is suppressed, so a "section in A.5.34" had no host. Conditional on `tenant.children_data = true`. Cross-tagged `iso27701:7.2.4`, `gdpr-art:8`, `bdsg-§:8` |
| 17 (§2.16) | Special-Category-Data Handling *(conditional)* | section in A.5.34 | **APPENDIX to §2.1 Privacy Policy** (REVERSED) | Inside §2.1. Same fix as row 16. Conditional on `tenant.special_category_data = true` OR `tenant.criminal_data = true`. Cross-tagged `iso27701:7.2.2`, `gdpr-art:9,10`, `bdsg-§:22,24,26` |
| 18 (host) | A.5.34 Privacy/PII Policy | suppressed entirely | **THIN 1-2 page cross-reference document** (REVERSED) | ISO 27001 A.5.34. ISO "shall maintain a topic-specific policy" wording demands a document at A.5.34. v2 keeps a thin host that lists where each privacy aspect lives (in Cl. 5.2 sections + 5 standalones). Resolves Conflict 1 in self-review (ISMS-Specialist wins on "policy must exist"; DPO wins on "content lives elsewhere via cross-reference"). BSI 200-2 "Verweis auf andere Dokumente" pattern legitimises this for BSI tenants too |
| 19 (NEW) | Cookie / ePrivacy / TTDSG | not addressed | **OUT-OF-SCOPE — explicit row** | None. ePrivacy (Dir. 2002/58/EC, DE-TTDSG §§ 25-26) is a SEPARATE regulator (BNetzA in DE, not the DPA) with a SEPARATE consent model (cookie consent ≠ Art. 7 GDPR consent in scope). Wizard does NOT generate cookie-banner content, cookie-consent flows, or tracking-technology policy. Privacy Policy §2.1 carries a single disclaimer paragraph pointing to the tenant's separate cookie module |
| 20 (NEW) | EU AI Act overlap | single paragraph in §2.12 PbD | **OUT-OF-SCOPE for v1 — defer to Phase 1-F separate addon** | None. EU AI Act (Reg. 2024/1689) Art. 26+27 deployer obligations + FRIA for high-risk systems have non-trivial documentary requirements. v1 keeps the §2.12 PbD cross-reference paragraph; Phase 1-F ships a parallel "AI Act Addon" wizard mirroring DORA's pattern. Decision matches self-review Phase-4 item 4 |
| 21 (NEW) | Sectoral DPO mandate (Healthcare § 22 BDSG, FinServ DORA Art. 6.4 ICT-Risk-Mgmt-Function, Public-sector BDSG § 5/§ 6) | not addressed | **section in ISO HR Security A.6.3 + sectoral appendix in §2.13 DPO Charter** | A.6.3 (HR security) for the role-mandate; appendix in §2.13 Charter for the sector-specific tasks (§ 203 StGB medical-confidentiality extension; MaRisk AT 7.2 + DORA Art. 6(4) ICT-risk-management-function for FS; § 6(4) BDSG dismissal-protection for DE public bodies). Conditional on `tenant.sector ∈ {healthcare, financial, public_sector}`. Sectoral templates ship in Phase 5 sprint W6 polish |
| 22 (NEW) | DPO independence carve-out at SECTION level | only at workflow level (§9) | **NEW MECHANIC — `dpo_section_required: true` flag** | All hosts that carry privacy-sections. See new §0.A below. Closes the self-review item-4 gap: when a privacy-section sits inside a CISO-owned host policy, Art. 38(3) is breached at the document level if the CISO can edit the section without DPO consent. v2 introduces a per-section approval gate so DPO can REJECT a privacy-section without forcing the entire host doc back to draft |
| 23 (NEW) | Lead Supervisory Authority logic for multi-EU-state Konzern | implicit (one-stop-shop assumed) | **section in §2.13 DPO Charter (standalone) + tenant-setting `tenant.lead_supervisory_authority`** | None (charter section). Konzern with main establishment in DE (Art. 4(16)(a)) → BfDI or competent state-DPA as lead per Art. 56. Subsidiaries with independent decision-making in IE / FR / etc. carry their own leads; one-stop-shop applies per main-establishment, not per Konzern. `HierarchyOverrideValidator` resolves via `stricter_only`: subsidiary may register a SECOND lead DPA; cannot REMOVE the inherited one. Closes self-review missing-item 5 |

### §0 Implementation Impact (v2)

The 21 privacy concerns (16 from §2 + 5 new) collapse into:

- **5 STANDALONE documents** (effective):
  1. **DPO Charter** (§2.13) — standalone, with Lead-DPA logic section + sectoral mandate appendix
  2. **RoPA Methodology** (§2.2) — standalone, hosting Lawful-Basis (§2.6) and Consent (§2.7) as sub-procedures (NEW: methodology now carries 2 extra sub-procedures vs. v1)
  3. **DPIA Methodology** (§2.3) — standalone
  4. **DSR Procedure** (§2.4) — standalone
  5. **Retention Schedule** (§2.11 matrix) — standalone with `retention_authority: 'schedule'` SoT-flag
  6. **Privacy Policy** (§2.1) — standalone-fallback when `dpo.is_group_dpo = true` OR multi-jurisdiction; otherwise section in ISO Cl. 5.2. Carries Children's (§2.15) and Special-Category (§2.16) as conditional appendices (NEW: 2 extra appendices vs. v1)

  *Net standalone count: still 5 effective in single-jurisdiction case;
  6 in multi-jurisdiction Konzern case (Privacy Policy promotes from
  section to standalone). v1 was a flat 5; v2 is 5-or-6 depending on
  tenant geometry. The "+1 sub-procedure-pair embedded in RoPA" + "+2
  appendices in Privacy Policy" stay inside the existing 5 hosts —
  they do NOT create new top-level documents.*

- **8 SECTIONS** added to existing ISO/DORA hosts (was 10 in v1; -2 because Lawful-Basis + Consent moved out to RoPA standalone; -2 because Children's + Special-Category moved out to Privacy-Policy appendices; +2 because Sectoral DPO mandate added a section to A.6.3 + Lead-DPA section in DPO Charter is *internal* to a standalone, not a section in an ISO host):
  - Privacy section in ISO Top-Level Policy (Cl. 5.2) — §2.1 (when not standalone)
  - Breach-notification track in ISO Incident Mgmt Policy — §2.5
  - Joint-Controllership in ISO Supplier Relationships Policy — §2.8
  - International Transfers in ISO Information Transfer Policy — §2.10
  - Retention mechanics in ISO Backup Policy (A.8.13) — §2.11
  - Retention mechanics in ISO Logging Policy (A.8.15) — §2.11
  - Privacy-by-Design in ISO PM/SDLC (A.5.8 + A.8.27) — §2.12
  - Sectoral DPO mandate in ISO HR Security (A.6.3) — row 21

- **1 APPENDIX** to ISO Awareness Programme — §2.14 Privacy Training (with promote-to-standalone trigger if appendix exceeds 30% of parent)
- **1 THIN HOST** kept for ISO A.5.34 — 1-2 page cross-reference document (NEW vs. v1 which suppressed it entirely)
- **2 OUT-OF-SCOPE rows** — §2.9 DPA Template (Document-Library file) + row 19 Cookie/ePrivacy (BNetzA-regulated)
- **1 DEFERRED row** — row 20 AI Act overlap (Phase 1-F separate addon)

The PolicyTemplate's `requiredVariables` list gets a
`dpo_section_required: true` flag for any template that has a
privacy-section. Body translation keys carry a
`policy.<standard>.<topic>.v<n>.section.privacy_addendum` slot that
the generator activates conditionally. NEW: any host with this flag
gets a per-section approval state — see §0.A below.

ISO 27701 PIMS (§3 below) remains a SEPARATE opt-in addon (parallel
to DORA), generating PIMS-specific clause-coverage. PIMS adds 0
additional Documents (clause-mapping metadata only); v2 keeps that
unchanged.

---

## 0.A DPO Veto Mechanic — section-level approval gate

> NEW in v2 to close self-review item 4 ("DPO independence carve-out at
> the policy approval level"). Closes the regulatory gap where a
> CISO-owned host policy could publish a privacy-section without DPO
> consent, breaching Art. 38(3) GDPR at the document level.

### 0.A.1 Template flag

PolicyTemplate metadata gains a per-section flag:

```yaml
sections:
  - key: privacy_addendum_<topic>
    dpo_section_required: true     # NEW
    owner_role: ROLE_DPO           # NEW (overrides host's owner_role for this section only)
    approval_gate: dpo_sign_off    # NEW (sub-state in DocumentApprovalWorkflow)
```

A host PolicyTemplate may carry 0..n sections with the flag. If 0 →
host workflow is unchanged. If ≥ 1 → host enters split-state mode.

### 0.A.2 DocumentApprovalWorkflow split-state

The existing `DocumentApprovalWorkflow` (per arch §9.1) gains a
**per-section sub-state machine** when any section in the host
carries `dpo_section_required: true`:

```
host states:    draft → ciso_review → privacy_section_gate → top_mgmt_signoff → published
section states (per gated section):  draft → dpo_sign_off → approved
```

Transitions:

1. `draft → ciso_review`: host prepared; CISO reviews non-privacy
   content. Privacy-sections remain editable by their `owner_role`
   (DPO) in parallel; CISO MAY NOT edit them.
2. `ciso_review → privacy_section_gate`: CISO completes review.
   Workflow waits for ALL gated sections to reach `approved`. If any
   gated section is still in `draft`, host blocks at this state and
   surfaces "awaiting DPO sign-off on §<key>" in the inbox.
3. Per-section `draft → dpo_sign_off`: DPO opens the section editor;
   sub-state advances on save. NO bulk button (per §9.3 carve-out).
4. Per-section `dpo_sign_off → approved`: DPO signs off the section
   individually. Other gated sections stay independent.
5. `privacy_section_gate → top_mgmt_signoff`: triggered when ALL
   gated sections in the host are `approved`. Top-Mgmt may then
   bulk-approve from the inbox (§9.2 OK at host level).
6. `top_mgmt_signoff → published`: as today.

### 0.A.3 DPO rejection behaviour

If DPO rejects a gated section at step 4:

- **The privacy-section reverts to `draft`** (section sub-state only).
- **The host document is marked `approved with privacy-section pending`** —
  CISO-reviewed content stays approved, but host CANNOT reach
  `published` until ALL gated sections re-reach `approved`.
- **Other approved sections in the host are NOT invalidated.** If
  the same host has 2 gated sections and DPO rejects only section A,
  section B (already `approved`) stays `approved`.
- **Top-Mgmt UI hides the host from the bulk-approve inbox** until
  all gated sections re-reach `approved`. Surfaces in a separate
  "Blocked by DPO" panel with rationale text.
- **Audit trail:** `policy-approval` workflow gets a sub-workflow
  `privacy-section-approval` per gated section. Each section
  transition emits a `WorkflowStepCompletedEvent` with
  `section_key` payload, so the audit-log shows per-section history
  separate from host-level history.

### 0.A.4 CISO edit-protection on gated sections

CISO (and Top-Mgmt) accounts have **read-only access** to any section
where `owner_role: ROLE_DPO`. Implementation: `DocumentSectionVoter`
checks the section's `owner_role` against the user's roles at edit
time. Even Top-Mgmt cannot bypass this — it's enforced at the
authorization layer, not the workflow layer. Closes self-review
concern 5 ("DPO veto travels with sections, or only with standalone?").

### 0.A.5 Self-approval prohibition (preserves §9.5)

The DPO Charter (§2.13, standalone) is the ONLY privacy doc where DPO
is NOT in the approval chain. The Lead-DPA section + sectoral mandate
appendix inside the Charter inherit this rule (sub-sections cannot
self-approve their own role-charter). Pipeline stays:
`prepared → top_mgmt_signoff → published`, with DPO listed only as
the SUBJECT of the document. Art. 38(3) + 38(6) preserved.

### 0.A.6 Migration & version-bump safety

Per self-review concern 2 ("privacy section deletion on ISO template
version bump"):

- Migration script enforces `v(n).requiredVariables ⊇ v(n-1).requiredVariables`
  for any flag ending in `_required` (including `dpo_section_required`).
- Translation-key parity check at deploy: every
  `policy.<standard>.<topic>.v<n>.section.privacy_addendum` key in
  v(n-1) must exist in v(n) for both DE and EN locales.
- CI gate: `php bin/console app:check-template-section-keys` runs
  before any template version-bump migration. Fails if a
  `dpo_section_required: true` flag is dropped or its translation
  key vanishes.

### 0.A.7 BSI tenant graceful degradation

Per self-review concern 3 ("BSI-pure tenant — silent section vanish"):
when `tenant.bsi_enabled = true` but `tenant.gdpr_scope = false`, all
gated sections are suppressed AND the BSI CON.2 cross-reference page
gracefully degrades to a "Datenschutz nicht im aktuellen
Geltungsbereich" notice instead of broken links. The thin A.5.34 host
(row 18) is still rendered but reads "GDPR-Anwendungsbereich nicht
gesetzt — Aspekte werden bei Aktivierung ergaenzt" in BSI-pure
tenants. Validation lives in `BsiPrivacyCrossReferenceValidator`.

---

### Document-count revision (replaces v1 estimate; informs `05-architecture.md` §3)

> NOTE TO ORCHESTRATOR: this section describes the change for
> `05-architecture.md` §3 but DOES NOT EDIT that file directly. The
> Architect-Specialist applies the math during Phase 2-extension.

v2 per-tenant doc-count formula:

```
total_docs = iso_topics
           + bsi_deltas
           + dora_new
           + privacy_standalone(5 effective; +1 if Privacy Policy promotes)
           + privacy_thin_host(1 if iso_enabled)
           + bcm_governance
           + methode_doc(1 if bsi_enabled)
```

Privacy contribution stays size-neutral vs. v1: -1 section (Lawful +
Consent moved to standalone-RoPA-sub) -2 sections (Children + Special
moved to Privacy-Policy appendices) +1 section (Sectoral DPO mandate
in A.6.3) +1 section (Lead-DPA logic, but inside standalone Charter
so doesn't count as ISO-host section) = -1 section. +1 thin host
(A.5.34 was suppressed → now 1-2 page cross-reference). NET ZERO at
the document-count level.

| Tenant choice | v1 estimate | v2 estimate | Delta |
|---|---|---|---|
| ISO + GDPR-scope | 29 (24 ISO + 5 standalone) | 30 (24 ISO + 5 standalone + 1 thin A.5.34 host) | +1 |
| ISO + DORA + GDPR | 35 (29 + 6 DORA-NEW) | 36 (30 + 6 DORA-NEW) | +1 |
| ISO + BSI + DORA + GDPR + BCM | 52 (quintuple ceiling) | 51-52 (one less section embedded in ISO host vs v1; one new thin A.5.34 host = net zero or -1; ±1 tolerance per self-review math note that quintuple is "approximately right, off by ±2-4") | ~0 |

The self-review's note that "v1 said 29, architecture said 30 — I
forgot to count the top-level Cl. 5.2 policy" is now resolved: v2
explicitly adds the thin-host row to the count.

Translation-key authoring effort drops by ~30-35% for GDPR-scope
tenants (unchanged from v1). Auditor experience improves: privacy is
embedded in security where the relationships are structural;
GDPR-distinct artefacts (RoPA, DPIA, DSR, Charter, Retention
Schedule) stay standalone where regulators expect them; A.5.34 is
satisfied by a thin cross-reference document instead of a phantom
suppression.

The rest of this report (§§ 1-15 below) is the original DPO
specialist enumeration. Sections marked "[SECTION]" or "[STANDALONE]"
in the per-doc detail tables refer to the v2 matrix above. Where v2
reverses a v1 verdict (rows 3, 4, 16, 17, 18), the §2.x detail table
should be read with the v2 routing in mind; the body content stays
unchanged because the cross-mapping tags translate identically into
either host.

---

## 1. Scope

### 1.1 Regulatory anchors covered by this addon

- **GDPR (Regulation (EU) 2016/679)** — applies whenever a tenant
  processes personal data of natural persons in the EU/EEA, regardless
  of where the controller is established (Art. 3 territorial scope).
- **BDSG-neu (Bundesdatenschutzgesetz, 2018 amended)** — German national
  implementation. Adds national specifics: § 26 employee data, § 38
  designation thresholds, § 22 special categories, § 8 minors-age,
  § 32 information obligations, § 35 right to erasure exceptions.
- **ISO/IEC 27701:2019** — PIMS extension to ISO 27001/27002. Two
  controller-extensions (Cl. 7.x) and processor-extensions (Cl. 8.x).
- **ISO/IEC 27701:2025** — REVISED edition published 2025-09. Most
  visible changes: control-set restructuring to align with the new
  ISO 27002:2022, dropping the old Annex A/B numbering, integrating
  the 2018 → 2024 GDPR-guidance-shifts (Schrems II additional measures,
  Art. 22 automated-decision clarifications).
- **EU AI Act (Reg. 2024/1689)** — out-of-scope, but Art. 26 + 27
  (deployer obligations) intersect GDPR Art. 22; single cross-reference
  paragraph in § 2.12 PbD.
- **ePrivacy / TTDSG (2002/58/EC, DE-TTDSG)** — cookies + direct
  marketing. Out-of-scope (separate regulator BNetzA in DE).

### 1.2 Sector overlays (handled in §7)

- Healthcare (BDSG § 22 + sectoral medical-device regs).
- Financial services (DORA + GDPR overlap, biometric KYC).
- Schools / minors (Art. 8 GDPR, § 8 BDSG-neu, age 16 default in DE).
- HR / employee data (§ 26 BDSG, Art. 88 GDPR, works-council rules).
- B2C e-commerce (cookie + ePrivacy overlap — handled by separate
  consent module).

### 1.3 Relationship to the ISO 27001 baseline

ISO 27001:2022 covers privacy thinly via **A.5.34** (Privacy / PII) and
**A.5.31** (Legal/Regulatory). The ISO specialist (`01-iso27001-input.md`
§ 2.17) noted A.5.34 collapses to a 2-page reference if a full GDPR /
ISO 27701 programme exists.

**This DPO addon expands A.5.34 into a 14–16 document set** (§ 2). When
the addon is enabled:

- A.5.34 placeholder is **suppressed**; full Privacy Policy generated.
- Every privacy doc cross-tagged `iso27001:A.5.34` + `iso27001:A.5.31`
  so the SoA shows the programme as evidence.
- BSI `CON.2 Datenschutz-Richtlinie` becomes a **cross-reference** to
  § 2.1 + § 2.6 (per `02-bsi-input.md` line 268 recommendation).

### 1.4 Relationship to BSI / DORA / BCM addons

| Other addon | Privacy intersection | Resolution |
|---|---|---|
| BSI `CON.2` Datenschutz | Same regulatory base (GDPR/BDSG) | DPO-addon takes precedence; BSI-CON.2 becomes cross-reference (see `02-bsi-input.md` line 268) |
| BSI `OPS.1.1.5` Protokollierung | § 26 BDSG + works-council | Privacy addon adds employee-monitoring-DPIA prompt; BSI doc keeps its scope |
| DORA Art. 28(1) ICT-third-party register | Overlaps Art. 28 GDPR DPA register | Cross-reference; DPA template (§ 2.8) flags DORA-relevant suppliers |
| DORA Art. 19 (notifications) | GDPR Art. 33/34 (72h) | Both procedures live in the same DataBreach module; doc explains overlap & double-notification rule |
| BCM ISO 22301 Cl. 8.4 | Crisis communication | Privacy crisis-comms template appendix added to BC Plan |

---

## 2. Mandatory Privacy Documents

> Numbered list of documents the wizard generates. Each document is a
> `Document` record (per § 4.2 of architecture, no new entity). Owner
> defaults to `ROLE_DPO`; CISO and Top-Mgmt approval as configured.

### 2.1 Privacy / Data-Protection Policy (top-level)

| Field | Value |
|---|---|
| **Standard ref** | GDPR Art. 5 (principles), Art. 24 (controller responsibility), ISO 27701 Cl. 5.2 (extends ISO 27001 Cl. 5.2), BDSG § 1 |
| **Doc name (EN)** | Data Protection Policy |
| **Doc name (DE)** | Datenschutz-Leitlinie |
| **Type** | Policy (top-level — counterpart to ISO 27001 Information Security Policy) |
| **Required sections** | (1) Purpose & scope, (2) the seven Art. 5 principles verbatim, (3) commitment to data-subject rights, (4) lawful-basis-discipline statement, (5) accountability framework (link to RoPA, DPIA, DSR procedures), (6) DPO designation, (7) supervisory-authority lead, (8) breach-response commitment, (9) review cadence, (10) sign-off by top-management |
| **Tenant inputs** | Legal entity name, scope statement, DPO designation Y/N, DPO contact, lead supervisory authority, special-category-data Y/N, international-transfer Y/N (drives later sections) |
| **Linked entities** | None (top-level — references all others) |
| **Approval** | DPO sign-off → CISO cross-check → Top-Mgmt (Art. 24 GDPR demands controller-level commitment) |
| **Review cadence** | 12 months. Out-of-cycle re-review on: new processing activity flagged "high risk", new sub-processor in non-adequate country, supervisory-authority guidance change, sectoral law update |
| **Cross-mapping** | EXTENDS A.5.34, A.5.31 / EXTENDS ISO 27701 Cl. 5.2 / EXTENDS BSI `CON.2.A1` / NEW relative to DORA & BCM |

### 2.2 RoPA Methodology (Records of Processing Activities — how-to)

| Field | Value |
|---|---|
| **Standard ref** | GDPR Art. 30(1) (controller) + Art. 30(2) (processor), ISO 27701 Cl. 7.2.8 / 8.2.6, BDSG § 70 (public bodies) |
| **Doc name (EN)** | Records of Processing Activities — Methodology |
| **Doc name (DE)** | Verfahrensverzeichnis-Methodik |
| **Type** | Methodology (the *how-to-maintain* document; the records themselves live in the existing `ProcessingActivity` entity) |
| **Required sections** | (1) Trigger events for new RoPA entry (new vendor, new product, HR change, marketing campaign), (2) Mandatory fields per Art. 30(1)(a)–(g) for controller and Art. 30(2)(a)–(d) for processor, (3) Roles: who collects, who reviews, who approves, (4) Review cadence per entry, (5) Retention of historical entries, (6) Format & supervisory-authority disclosure procedure (Art. 30(4)), (7) Joint-controller & sub-processor handling |
| **Tenant inputs** | Controller-only / Processor-only / Both, RoPA review interval (default 12 mo), RoPA owner per business unit, supervisory-authority disclosure SLA |
| **Linked entities** | `ProcessingActivity` (existing — methodology references entity fields) |
| **Approval** | DPO → CISO |
| **Review cadence** | 24 months (methodology stable; underlying records change often) |
| **Cross-mapping** | EXTENDS A.5.34 / EXTENDS ISO 27701 Cl. 7.2.8 + 8.2.6 / NEW |

> **Wizard does NOT generate the actual RoPA register.** It generates
> the methodology that governs the existing `ProcessingActivity` module.
> See § 4 below.

### 2.3 DPIA Methodology

| Field | Value |
|---|---|
| **Standard ref** | GDPR Art. 35 (DPIA) + Art. 36 (prior consultation), ISO 27701 Cl. 7.2.5 / 8.2.1, EDPB WP248 rev.01 (criteria), BfDI Schwarze Liste (DE-specific high-risk list) |
| **Doc name (EN)** | DPIA Methodology |
| **Doc name (DE)** | DSFA-Methodik (Datenschutz-Folgenabschätzung) |
| **Type** | Methodology |
| **Required sections** | (1) DPIA trigger criteria (Art. 35(3) cases + EDPB nine criteria + BfDI Schwarze Liste reference), (2) Threshold-assessment process (use existing DPIA-screening), (3) Methodology (description of processing, necessity, risks to rights & freedoms, mitigations), (4) Stakeholder consultation rules (Art. 35(9)), (5) Prior-consultation trigger (residual high risk → Art. 36), (6) Review cadence per DPIA, (7) DPIA-as-evidence retention rule, (8) link to ISO 27005 risk-method for technical-risk overlap |
| **Tenant inputs** | DPIA-screening checklist preference (use EDPB WP248 / BfDI / both — DE default: both), DPIA review cadence (default 24 mo or trigger-based) |
| **Linked entities** | `DataProtectionImpactAssessment` (existing) |
| **Approval** | DPO → CISO (CISO cross-checks technical-risk alignment) |
| **Review cadence** | 24 months |
| **Cross-mapping** | EXTENDS A.5.34, A.8.27 (secure system architecture) / EXTENDS ISO 27701 Cl. 7.2.5, 8.2.1 / NEW |

### 2.4 Data-Subject-Rights Procedure (DSR)

| Field | Value |
|---|---|
| **Standard ref** | GDPR Art. 12–22 (procedure for rights), Art. 12(3)+(4) (response SLA), Art. 23 (restrictions), BDSG § 32–§ 37 (German modifications & restrictions), ISO 27701 Cl. 7.3 / 8.3 |
| **Doc name (EN)** | Data Subject Rights Procedure |
| **Doc name (DE)** | Verfahren Betroffenenrechte |
| **Type** | Procedure |
| **Required sections** | (1) Rights covered (Art. 15 access, 16 rectification, 17 erasure, 18 restriction, 19 notification, 20 portability, 21 objection, 22 automated decisions), (2) Intake channels (web form, email, postal, phone), (3) Identity-verification procedure (Art. 12(6) doubts), (4) 1-month SLA + 2-month extension rules (Art. 12(3)), (5) Rejection grounds + manifestly-unfounded handling (Art. 12(5)), (6) Coordination with operative teams (HR, IT, Marketing), (7) Logging & evidence (link to `DataSubjectRequest` entity), (8) Tenant-interaction with sub-processors (Art. 28(3)(e) requires processor support) |
| **Tenant inputs** | DSR intake email/URL, response language(s), identity-verification-method default (ID copy / video-ident / 2FA), DSR-response SLA (default 30 days, BDSG § 34 override), automated-decision-making used Y/N (drives Art. 22 section) |
| **Linked entities** | `DataSubjectRequest` (existing) |
| **Approval** | DPO → CISO |
| **Review cadence** | 24 months |
| **Cross-mapping** | EXTENDS A.5.34 / EXTENDS ISO 27701 Cl. 7.3.1–7.3.10, 8.3 / NEW |

### 2.5 Data Breach Notification Procedure

| Field | Value |
|---|---|
| **Standard ref** | GDPR Art. 33 (notification to authority within 72h), Art. 34 (communication to data subject), Art. 4(12) (definition), ISO 27701 Cl. 7.2.7 / 6.13 (incident-response extension), BDSG § 65 (public bodies). DORA Art. 19 (ICT major incidents) coupling for financials. |
| **Doc name (EN)** | Personal Data Breach Notification Procedure |
| **Doc name (DE)** | Verfahren zur Meldung von Datenschutzverletzungen |
| **Type** | Procedure |
| **Required sections** | (1) Definition of personal data breach (Art. 4(12)), (2) Detection channels & internal-notification SLA, (3) Severity / risk-to-rights assessment (link to `GdprBreachAssessmentService`), (4) 72h-clock starts at "becoming aware", clock-stop conditions, (5) Authority-notification template & content (Art. 33(3)), (6) Subject-communication template & threshold (Art. 34(1) high risk), (7) Documentation duty for ALL breaches (Art. 33(5)) — even non-notifiable ones, (8) Cross-regulatory matrix: GDPR-72h / DORA-Art.19 / NIS2-24h+72h+1m / sectoral, (9) Joint-controller coordination (Art. 26), (10) Sub-processor notification chain (Art. 33(2)) |
| **Tenant inputs** | Lead supervisory authority, secondary authorities (multi-jurisdiction), authority-portal URLs (DE: BfDI / state-DPAs have specific portals), public-comms approver, joint-controller arrangements Y/N, sub-processor notification SLA (default 24h after they detect) |
| **Linked entities** | `DataBreach` (existing) — procedure governs the entity workflow & 72h-CRON (already implemented per architecture § 8.1 of `03-dora-input.md`) |
| **Approval** | DPO → CISO → Top-Mgmt (high-stakes, Top-Mgmt sign-off mandatory) |
| **Review cadence** | 12 months (regulatory-guidance volatility) |
| **Cross-mapping** | EXTENDS A.5.34, A.5.24 (incident planning), A.5.25 (assessment), A.5.26 (response) / EXTENDS ISO 27701 Cl. 6.13, 7.2.7 / EXTENDS BSI `DER.2.1` (Behandlung von Sicherheitsvorfällen) / EXTENDS DORA Art. 19 |

### 2.6 Lawful-Basis Determination Methodology

| Field | Value |
|---|---|
| **Standard ref** | GDPR Art. 6 (general lawful bases), Art. 9 (special categories), Art. 10 (criminal-conviction data), Art. 7 (consent conditions when consent is the basis), ISO 27701 Cl. 7.2.2 / 8.2.2, BDSG § 22 (special categories), § 24 (criminal data), § 26 (employment) |
| **Doc name (EN)** | Lawful Basis Determination Methodology |
| **Doc name (DE)** | Methodik zur Festlegung der Rechtsgrundlage |
| **Type** | Methodology |
| **Required sections** | (1) Decision tree for the six bases of Art. 6(1)(a)–(f), (2) Special-category overlay (Art. 9(2)(a)–(j)), (3) Criminal-data rules (Art. 10), (4) Legitimate-interest balancing test template (LIA — Art. 6(1)(f) recital 47), (5) Consent-as-basis cautions (link to § 2.7 Consent Policy), (6) Documentation duty per `ProcessingActivity` (link to RoPA methodology), (7) Re-assessment triggers (purpose change, scope expansion), (8) Refusal rules (no basis = no processing) |
| **Tenant inputs** | Common processing categories (HR, customer, marketing) and their default basis, automated-decision-making Y/N, profiling Y/N, special-category-data Y/N |
| **Linked entities** | `ProcessingActivity` (each entry has a lawful-basis field — methodology drives data quality there) |
| **Approval** | DPO → CISO |
| **Review cadence** | 24 months (or on regulatory-guidance change) |
| **Cross-mapping** | EXTENDS A.5.34, A.5.31 / EXTENDS ISO 27701 Cl. 7.2.2, 8.2.2 / NEW |

### 2.7 Consent Management Policy

| Field | Value |
|---|---|
| **Standard ref** | GDPR Art. 7 (conditions), Art. 8 (children), Recital 32 (clear affirmative action), EDPB Guidelines 05/2020 on consent, ISO 27701 Cl. 7.2.3 / 7.3.2 |
| **Doc name (EN)** | Consent Management Policy |
| **Doc name (DE)** | Einwilligungs-Management-Richtlinie |
| **Type** | Policy |
| **Required sections** | (1) When consent is needed vs. when another basis applies, (2) Conditions for valid consent (freely given, specific, informed, unambiguous + Recital 42 demonstrability), (3) Granularity rules (separate consent per purpose), (4) Withdrawal mechanism (Art. 7(3) "as easy to withdraw as to give"), (5) Children-specific rules (link to § 2.15), (6) Consent records & evidence (link to `Consent` entity), (7) Renewal cadence per consent type, (8) Cross-link to ePrivacy / TTDSG cookie-consent (out-of-scope of this wizard but referenced) |
| **Tenant inputs** | Consent-renewal cadence per type (marketing default 24 mo, research default 12 mo), language(s), withdrawal channel, default age threshold for parental consent (DE: 16 per BDSG § 8) |
| **Linked entities** | `Consent` (existing) |
| **Approval** | DPO → CISO |
| **Review cadence** | 12 months |
| **Cross-mapping** | EXTENDS A.5.34 / EXTENDS ISO 27701 Cl. 7.2.3, 7.3.2 / NEW |

### 2.8 Joint-Controller Agreements Methodology

| Field | Value |
|---|---|
| **Standard ref** | GDPR Art. 26 (joint controllers), EDPB Guidelines 07/2020 controllers/processors, ISO 27701 Cl. 7.2.7 |
| **Doc name (EN)** | Joint Controllership Methodology |
| **Doc name (DE)** | Methodik zur gemeinsamen Verantwortlichkeit |
| **Type** | Methodology |
| **Required sections** | (1) Determination of joint-controllership vs. controller-processor (Fashion ID / Wirtschaftsakademie SH case-law summary), (2) Mandatory content of Art. 26 arrangement, (3) Transparency duty (Art. 26(2) essence shall be made available to data subjects), (4) Allocation of duties (DSR responses, breach notification, RoPA segments), (5) Liability matrix (Art. 26(3) — joint-and-several towards data subjects), (6) Annual review of all joint-controller arrangements, (7) Termination / hand-over rules |
| **Tenant inputs** | Joint-controllership Y/N, partner organisations, primary point-of-contact for data subjects |
| **Linked entities** | None directly — could link to `Supplier` entity (joint-controller flag) in v2 |
| **Approval** | DPO → Legal → Top-Mgmt |
| **Review cadence** | 24 months |
| **Cross-mapping** | EXTENDS A.5.34, A.5.20 (supplier agreements) / EXTENDS ISO 27701 Cl. 7.2.7 / NEW |

### 2.9 Data Processing Agreement (DPA) — *Template, not Policy*

> **FLAGGED SEPARATELY** (per request). DPAs are *contracts*, not
> internal policies. The wizard ships a DPA *template* in DE + EN,
> but the artefact is not a Document under SoA — it lives in
> `templates/contracts/` and is offered as a download from the
> Privacy Policy detail-view. NOT counted in the document set total.

| Field | Value |
|---|---|
| **Standard ref** | GDPR Art. 28 (processor), Art. 28(3) mandatory clauses (a)–(h), EU SCC for controller-processor (Decision 2021/915), ISO 27701 Cl. 7.2.6 (processor instructions) / 8.2.4 |
| **Doc name (EN)** | Data Processing Agreement (template) |
| **Doc name (DE)** | Auftragsverarbeitungsvertrag (Vorlage) |
| **Type** | Contract template (not a Policy / Document) |
| **Wizard treatment** | Generated as a downloadable RTF/DOCX in tenant locale, with the eight Art. 28(3) topics pre-filled where deterministic. Variable fields: parties, services, sub-processor list (auto-populated from `Supplier` entity if processor-flag set). |
| **Cross-mapping** | EXTENDS A.5.20 / EXTENDS ISO 27701 Cl. 7.2.6, 8.2.4 / NEW (template-only artefact) |

### 2.10 International Transfers Policy

| Field | Value |
|---|---|
| **Standard ref** | GDPR Chapter V (Art. 44–50): Art. 45 adequacy, Art. 46 SCCs / BCRs / approved-codes, Art. 47 BCR specifics, Art. 49 derogations. Schrems II (C-311/18) additional measures. EDPB Recommendations 01/2020 supplementary measures. ISO 27701 Cl. 7.5 |
| **Doc name (EN)** | International Data Transfers Policy |
| **Doc name (DE)** | Richtlinie zur Übermittlung in Drittländer |
| **Type** | Policy |
| **Required sections** | (1) Scope: definition of "transfer" per EDPB Guidelines 05/2021, (2) Adequacy-decision-list (referenced live, not hardcoded — e.g. UK, JP, KR, US-DPF), (3) Default mechanism: 2021/914 SCCs (controller-processor module 2; controller-controller module 1), (4) Schrems II Transfer-Impact-Assessment (TIA) procedure, (5) Supplementary measures catalogue (encryption, pseudonymisation, contractual, organisational), (6) Derogations (Art. 49) — narrow, documented case-by-case, (7) BCRs for intra-group (Art. 47), (8) US Data Privacy Framework specifics, (9) UK / Swiss IDTA addendum handling, (10) Sub-processor chain in non-adequate countries |
| **Tenant inputs** | International-transfer Y/N, target countries (multi-pick), BCRs in place Y/N, US-DPF self-certified suppliers list, transfer-impact-assessment template selection |
| **Linked entities** | `ProcessingActivity` (transfer-target field) — methodology cross-refs |
| **Approval** | DPO → Legal → CISO → Top-Mgmt |
| **Review cadence** | 6 months (high-volatility — adequacy lists & TIA guidance change often) |
| **Cross-mapping** | EXTENDS A.5.34, A.5.31, A.5.20 / EXTENDS ISO 27701 Cl. 7.5.1–7.5.4 / NEW |

### 2.11 Retention & Deletion Policy

| Field | Value |
|---|---|
| **Standard ref** | GDPR Art. 5(1)(e) (storage limitation), Art. 17 (erasure), Art. 89(1) (research-archiving exceptions), BDSG § 35 (erasure exceptions for specific cases), ISO 27701 Cl. 7.4.7 / 8.4.2. German commercial law: HGB § 257 (6 / 10 years), AO § 147 (tax 6 / 10 years), § 26 BDSG (employment data) |
| **Doc name (EN)** | Data Retention and Deletion Policy |
| **Doc name (DE)** | Aufbewahrungs- und Löschrichtlinie |
| **Type** | Policy |
| **Required sections** | (1) Principle: data deleted as soon as purpose-fulfilled unless retention duty applies, (2) Retention matrix per data category (employee, customer, log, marketing, etc.), (3) Retention durations cited per legal basis (HGB / AO / sectoral), (4) Triggers (purpose-achieved, contract-end, consent-withdrawal, objection), (5) Deletion procedure (logical / physical / pseudo-anonymisation), (6) Backup-data deletion timing (out-of-band — regulatory grace), (7) Archiving exception (research, statistical — Art. 89), (8) Erasure-exception cases (BDSG § 35 — when erasure is replaced by restriction), (9) Deletion-evidence (log / certificate), (10) Yearly retention-matrix audit |
| **Tenant inputs** | Retention defaults per category (employee 10y after exit, customer 6y after last contract, log 6 mo, marketing-consent revoked → immediate, etc.), backup retention, archive-storage location |
| **Linked entities** | `ProcessingActivity` (retention field per activity) |
| **Approval** | DPO → CISO → Top-Mgmt |
| **Review cadence** | 12 months |
| **Cross-mapping** | EXTENDS A.5.34, A.5.33 (records protection), A.8.10 (information deletion), A.8.13 (information backup) / EXTENDS ISO 27701 Cl. 7.4.7, 8.4.2 / EXTENDS BSI `CON.6` (Löschen und Vernichten) / NEW |

### 2.12 Privacy-by-Design and Default Methodology

| Field | Value |
|---|---|
| **Standard ref** | GDPR Art. 25(1) DPbD + Art. 25(2) DPbDefault, EDPB Guidelines 04/2019, ISO 27701 Cl. 7.4.2, ISO 31700 Privacy-by-Design (referenced) |
| **Doc name (EN)** | Privacy by Design and by Default Methodology |
| **Doc name (DE)** | Methodik Privacy by Design und Default |
| **Type** | Methodology |
| **Required sections** | (1) Seven foundational principles (Cavoukian) reframed for Art. 25, (2) Trigger: any new system / change involving personal data → DPbD assessment mandatory, (3) Pre-DPIA gate (lightweight DPbD checklist), (4) Default settings rules (data minimisation, purpose limitation enforced by config), (5) Integration with secure-development-lifecycle (link to A.8.25 SDLC policy), (6) Vendor-procurement gate (DPbD evaluation criteria), (7) AI-system specifics: Art. 22 GDPR + AI Act intersection cross-reference, (8) Documentation & evidence per project |
| **Tenant inputs** | SDLC ownership (in-house / outsourced), DPbD checklist owner per BU, AI-system usage Y/N |
| **Linked entities** | None directly; cross-refs `DataProtectionImpactAssessment` (DPbD feeds DPIAs) |
| **Approval** | DPO → CISO |
| **Review cadence** | 24 months |
| **Cross-mapping** | EXTENDS A.5.34, A.8.25, A.8.27, A.8.28 / EXTENDS ISO 27701 Cl. 7.4.2 / NEW |

### 2.13 DPO Charter / Appointment

| Field | Value |
|---|---|
| **Standard ref** | GDPR Art. 37 (designation), Art. 38 (position), Art. 39 (tasks), BDSG § 38 (German thresholds — designation mandatory at ≥ 20 persons regularly handling personal data automated, OR for any processor / public body / Art. 35 DPIA-prone processing), ISO 27701 Cl. 5.3, BSI Empfehlung "DSB ungleich ISB" |
| **Doc name (EN)** | Data Protection Officer Charter |
| **Doc name (DE)** | Bestellung und Aufgabenbeschreibung des Datenschutzbeauftragten |
| **Type** | Charter / Appointment letter (formally a single Document; combines designation + role description) |
| **Required sections** | (1) Designation rationale (GDPR Art. 37(1) + BDSG § 38 trigger), (2) DPO identity + contact (Art. 37(7) — must be published, link to internal page), (3) Reporting line: directly to top-management (Art. 38(3)), (4) Resources & access (Art. 38(2)), (5) No conflict-of-interest (Art. 38(6) — DPO may not also decide purposes/means), (6) Independence & non-instruction guarantee (Art. 38(3)), (7) Tasks per Art. 39 (informing, monitoring, training, DPIA cooperation, supervisory cooperation, contact-point), (8) Term, termination protections (BDSG § 6(4) special-protection mirror), (9) BSI separation rule: DSB ≠ ISB (cited from `02-bsi-input.md` line 108) |
| **Tenant inputs** | DPO designation Y/N (auto-determined: ≥20 employees + automated PD processing OR processor-status OR DPIA-prone activities OR public body), DPO name + email + postal address (Art. 37(7) all three required), internal vs. external DPO, group-DPO Y/N (Art. 37(2)), DPO-conflict-of-interest declaration Y/N |
| **Linked entities** | `User` (DPO role assignment); `Tenant` (settings) |
| **Approval** | Top-Mgmt directly (Art. 37 designation is a top-mgmt act); DPO does NOT self-approve their own charter |
| **Review cadence** | At appointment + on DPO change + every 24 months |
| **Cross-mapping** | EXTENDS ISO 27701 Cl. 5.3 / NEW (no ISO 27001 control directly addresses DPO designation; A.5.2 *roles* is the closest) |

### 2.14 Privacy Training & Awareness Programme

| Field | Value |
|---|---|
| **Standard ref** | GDPR Art. 39(1)(b) (DPO training task), Art. 47(2)(n) (BCR training duty), ISO 27701 Cl. 6.4.2 (extends ISO 27001 A.6.3) |
| **Doc name (EN)** | Privacy Training and Awareness Programme |
| **Doc name (DE)** | Datenschutz-Schulungs- und Awareness-Programm |
| **Type** | Programme (governance doc; the actual training records live in the existing Training module) |
| **Required sections** | (1) Audience segmentation (all-staff baseline, role-specific deep-dives for HR, marketing, dev, support), (2) Curriculum per audience, (3) Cadence (mandatory annual + on-onboarding, refresher on regulatory change), (4) Effectiveness measurement (test-pass-rate target, phishing-style privacy-quiz), (5) DPO consultation-channel awareness, (6) Sectoral overlays (medical-confidentiality for healthcare, banking-secrecy for FS) |
| **Tenant inputs** | Training-cadence default (annual / biennial), language(s), audience segments, sector |
| **Linked entities** | Existing Training module (records side); new Programme is a Document |
| **Approval** | DPO → CISO |
| **Review cadence** | 12 months |
| **Cross-mapping** | EXTENDS A.6.3 / EXTENDS ISO 27701 Cl. 6.4.2 / OVERLAPS ISO 27001 Awareness Policy in `01-iso27001-input.md` § 2.5 → resolution: privacy programme is an APPENDIX to the main awareness programme, never a duplicate doc; wizard inserts as section if Awareness Policy already exists |

### 2.15 Children's Data Policy *(conditional)*

| Field | Value |
|---|---|
| **Standard ref** | GDPR Art. 8 (information-society services to children), BDSG § 8 (no German lower than 16 — actually GDPR's default; some other MS lower to 13), ISO 27701 Cl. 7.2.4 |
| **Doc name (EN)** | Children's Personal Data Policy |
| **Doc name (DE)** | Richtlinie zum Umgang mit Daten Minderjähriger |
| **Type** | Policy |
| **Required sections** | (1) Trigger: information-society service offered directly to children (Art. 8(1)), (2) Age-threshold per jurisdiction (default DE/EU = 16), (3) Parental-consent verification mechanisms (Art. 8(2)), (4) Age-screening at signup, (5) Special transparency obligations (Art. 12(1) "clear and plain language" — adapted), (6) Marketing-prohibition rules, (7) DPIA mandatory for children's data (EDPB criterion), (8) Erasure facilitation (Recital 65 — easier erasure for data collected as child) |
| **Tenant inputs** | Children's data Y/N (drives whether this doc is generated at all), age threshold, parental-consent verification mechanism |
| **Linked entities** | `Consent` (parental-consent records — extends entity field set) |
| **Approval** | DPO → Legal → CISO |
| **Review cadence** | 24 months |
| **Cross-mapping** | EXTENDS A.5.34 / EXTENDS ISO 27701 Cl. 7.2.4 / NEW |
| **Conditional** | Generated only if `tenant.children_data = true` |

### 2.16 Special-Category-Data Handling Policy *(conditional)*

| Field | Value |
|---|---|
| **Standard ref** | GDPR Art. 9 (special categories), Art. 10 (criminal-conviction data), BDSG § 22 (special-category exceptions), § 24 (criminal data exceptions), § 26(3) (special-category employee data with works-council role), ISO 27701 Cl. 7.2.2 |
| **Doc name (EN)** | Special Categories and Criminal Data Policy |
| **Doc name (DE)** | Richtlinie für besondere Kategorien personenbezogener Daten |
| **Type** | Policy |
| **Required sections** | (1) Definition: race/ethnic origin, political opinion, religious beliefs, trade-union membership, genetic, biometric (when used for ID), health, sex life, sexual orientation, criminal records, (2) Per-category lawful-basis matrix (Art. 9(2)(a)–(j) + Art. 10), (3) BDSG-DE specifics (§ 22 listed exceptions, § 26(3) employment), (4) Mandatory technical-organisational measures (encryption-at-rest, role-based access, DLP, audit-logging baseline) — Art. 9 + Recital 51, (5) DPIA mandatory, (6) Restricted processing locations (no transfer to non-adequate without supplementary measures and explicit Art. 49(1)(a) consent), (7) Sectoral overlays (medical confidentiality, religious-organisation Art. 91) |
| **Tenant inputs** | Special-category data processed Y/N + categories list, criminal-data Y/N, sector |
| **Linked entities** | `ProcessingActivity` (special-category-flag), `DataProtectionImpactAssessment` (mandatory linkage) |
| **Approval** | DPO → CISO → Top-Mgmt |
| **Review cadence** | 12 months |
| **Cross-mapping** | EXTENDS A.5.34, A.8.11 (data masking), A.8.12 (DLP), A.5.10 (acceptable use) / EXTENDS ISO 27701 Cl. 7.2.2 / NEW |
| **Conditional** | Generated only if `tenant.special_category_data = true` OR `tenant.criminal_data = true` |

---

## 3. ISO 27701 Pflicht-Set (PIMS)

ISO 27701 is offered as a parallel addon (analog to DORA). When enabled,
the addon does NOT generate *more* policies (§ 2 covers them) — it adds
**clause-level mapping metadata** so an ISO 27701 audit can trace the
Privacy programme to each clause.

ISO 27701:2025 superseded :2019 in 2025-09. Wizard defaults to **:2025**;
tenant-setting `iso27701_version` switches to `:2019` for active legacy
audit cycles.

### 3.1 ISO 27701:2025 mandatory documented information

> Numbering follows the 2025 edition. Where the 2019 clause number
> differs, it's noted as `(2019: x.y.z)`.

| Clause | Topic | Document covering it (see § 2) | Notes |
|---|---|---|---|
| 5.1 | PIMS leadership commitment | § 2.1 Privacy Policy | Cl. 5.1 in 2019 unchanged |
| 5.2 | Privacy policy declaration | § 2.1 Privacy Policy | Cl. 5.2 in 2019 unchanged |
| 5.3 | Roles & responsibilities (DPO etc.) | § 2.13 DPO Charter | (2019: 5.3) |
| 6.1 | Risk assessment integrated with ISMS | ISO 27001 risk-method (existing) | Reuses ISO 27005 method |
| 6.2 | Privacy risk treatment | DPIA Methodology § 2.3 | (2019: 6.2) |
| 7.2.1 | Identify legal basis | § 2.6 Lawful-Basis Methodology | (2019: 7.2.1) |
| 7.2.2 | Special categories / lawful-basis exceptions | § 2.16 Special Cat. Policy | (2019: 7.2.2) |
| 7.2.3 | Determine when consent is the basis | § 2.7 Consent Policy | (2019: 7.2.3) |
| 7.2.4 | Children-related processing | § 2.15 Children's Policy | (2019: 7.2.4) |
| 7.2.5 | DPIA | § 2.3 DPIA Methodology | (2019: 7.2.5) |
| 7.2.6 | Processor instructions / DPA | § 2.9 DPA Template | (2019: 7.2.6) |
| 7.2.7 | Joint controllers | § 2.8 Joint Controller Methodology | (2019: 7.2.7) |
| 7.2.8 | RoPA | § 2.2 RoPA Methodology | (2019: 7.2.8) |
| 7.3.1–7.3.10 | Data-subject obligations (information, access, rectif, erasure, restriction, portability, objection, automated decisions, withdrawal of consent) | § 2.4 DSR Procedure | (2019 same range; 2025 clarifies Art. 22 automated-decisions) |
| 7.4.x | Privacy-by-design implementation | § 2.12 PbD Methodology | (2019: 7.4.x) |
| 7.4.7 | Retention | § 2.11 Retention Policy | (2019: 7.4.7) |
| 7.5.1–7.5.4 | International transfers (basis, country list, transfer documentation, supplementary measures) | § 2.10 International Transfers | **2025 change:** explicit Schrems II "supplementary measures" clause added |
| 8.x (processor) | All controller-extensions mirrored for processor role | All § 2 docs cover both roles where applicable | Tenants flagged as processors get role-specific phrasing |
| 6.4.2 | PIMS awareness | § 2.14 Training Programme | (2019: 6.4.2) |
| 6.13 (2025) / 6.13.1.5 (2019) | Personal data breach response | § 2.5 Breach Procedure | (2019 was a sub-clause of 6.13.1; 2025 promoted it) |

### 3.2 Notable 2019 → 2025 changes

1. Restructured to ISO 27002:2022 alignment; old Annex A/B split
   dropped, controls integrated into 6.x with role-applicability flags.
   `template.iso27701_clause` stores BOTH 2019 + 2025 refs.
2. Schrems II supplementary measures explicit in 7.5.x.
3. Automated decisions (GDPR Art. 22) clarified in 7.3.x.
4. 6.13 breach-response explicitly coupled to ISO 27001:2022 5.24–5.30.
5. AI-system paragraph in 7.4.x cross-references EU AI Act. Wizard
   inserts paragraph in § 2.12 PbD if `tenant.ai_system = true`.

### 3.3 Wizard handling

- Tenant-setting `iso27701_enabled = bool` (default false) drives
  whether tags `iso27701:7.x.y` get appended to the documents.
- Tenant-setting `iso27701_version = '2019' | '2025'` (default '2025').
- No additional Documents are created; the addon adds **mapping
  metadata** to the documents already produced by § 2.

---

## 4. Records / Outputs — NOT generated by this wizard

> Mark explicit out-of-scope. Existing modules own these artefacts.

| Output | Owning module / entity | Why out-of-scope |
|---|---|---|
| Actual RoPA register | `ProcessingActivity` | Live records, not documentation; methodology in § 2.2 governs them |
| Concrete DPIAs per processing | `DataProtectionImpactAssessment` | Per-processing artefact; methodology in § 2.3 governs them |
| DSR response logs | `DataSubjectRequest` | Per-request workflow & audit trail |
| Personal-data breach logs | `DataBreach` (+ 72h CRON) | Per-incident artefact; procedure in § 2.5 governs them |
| Consent records | `Consent` | Per-data-subject artefact; policy in § 2.7 governs them |
| DPO annual activity report | Generated separately by DPO from existing module data | Reporting artefact, not a policy. Suggested as Phase-2 wizard add-on (auto-compile from above modules — pure reuse) |
| Cookie-banner records | Out-of-scope module (TTDSG / ePrivacy) | Different regulator (BNetzA in DE, not DPA) |
| Sub-processor list per supplier | `Supplier` (existing) | Live register; § 2.10 + § 2.9 reference it |

**The wizard generates the *frameworks* governing how those modules
are USED, never the records themselves.** This is the same principle
applied to the BCM addon (`04-bcm-input.md`): the wizard generates the
BCM Programme + BIA Methodology + Crisis-Mgmt Plan, not the per-process
BCPlan content.

---

## 5. Tenant-Settings Inputs (privacy-specific)

> Slot into the wizard step structure of architecture § 6. Privacy
> uses Steps 2 + 3 + a new conditional **Step 3a "Privacy & DPO"**
> that activates only if the DPO addon is enabled.

### 5.1 Step 3a — Privacy & DPO

Conditionally inserted between Step 3 (Roles) and Step 4 (Risk).

| Setting key | Type | Default | Purpose |
|---|---|---|---|
| `dpo.designation_status` | enum: `mandatory` / `voluntary` / `not_required` | auto-determined | Drives § 2.13 generation. Auto-rule: `mandatory` if any of: (a) ≥ 20 employees regularly automated PD per BDSG § 38, (b) processor status, (c) public body, (d) Art. 35-prone activities, (e) ≥ 10 in special-category processing |
| `dpo.name` | string | — | Art. 37(7) — must be published |
| `dpo.email` | email | — | Art. 37(7) |
| `dpo.postal_address` | string | — | Art. 37(7) — postal required, not email-only |
| `dpo.is_external` | bool | false | Affects independence treatment |
| `dpo.is_group_dpo` | bool | false | Art. 37(2) — single DPO for the Konzern allowed if "easily accessible" |
| `dpo.conflict_of_interest_declared` | bool | true | Art. 38(6) — DPO must not also decide purposes/means |
| `dpo.bsi_separation_acknowledged` | bool | true | BSI requires DSB ≠ ISB; flagged in `02-bsi-input.md` line 108 |
| `privacy.lead_supervisory_authority` | enum (BfDI / state list / EU-other) | tenant-jurisdiction-default | Art. 56 — main establishment determines lead DPA |
| `privacy.secondary_authorities` | multi-select | empty | Cross-border processing |
| `privacy.data_subject_categories` | multi-select (employees / customers / patients / students / minors / suppliers / others) | tenant-derived | Drives DPIA-prone-flag, Art. 8 / § 8 BDSG flags, sector-overlay rules |
| `privacy.special_category_data` | bool | false | Drives § 2.16 generation; sets DPIA-required |
| `privacy.criminal_data` | bool | false | Drives § 2.16 |
| `privacy.children_data` | bool | false | Drives § 2.15 |
| `privacy.children_age_threshold` | int | 16 (DE/EU default) | § 8 BDSG-neu |
| `privacy.international_transfers` | bool | false | Drives § 2.10; if true → triggers country list |
| `privacy.transfer_target_countries` | multi-select | — | Adequacy / SCC / TIA logic |
| `privacy.bcrs_in_place` | bool | false | Art. 47 |
| `privacy.us_dpf_used` | bool | false | US-specific transfer mechanism |
| `privacy.joint_controllers` | bool | false | Drives § 2.8 |
| `privacy.sub_processors` | bool | true | Drives § 2.9 DPA template + sub-processor matrix; tied to existing Supplier module |
| `privacy.consent_renewal_marketing` | int (months) | 24 | § 2.7 |
| `privacy.consent_renewal_research` | int (months) | 12 | § 2.7 |
| `privacy.privacy_training_cadence` | enum: annual / biennial / quarterly | annual | § 2.14 |
| `privacy.dsr_response_sla_days` | int | 30 | Art. 12(3) default; BDSG § 34 may override |
| `privacy.breach_internal_notify_sla_h` | int | 24 | Internal SLA before 72h-clock-target |
| `privacy.auto_include_processing_in_appendix` | bool | true | If true, the rendered Privacy Policy auto-appendices the live `ProcessingActivity` register summary (see § 11 recommendation) |
| `privacy.iso27701_enabled` | bool | false | Adds ISO 27701 mapping metadata |
| `privacy.iso27701_version` | enum: `2019` / `2025` | `2025` | § 3 |
| `privacy.ai_system_used` | bool | false | Cross-reference paragraph in § 2.12 PbD |
| `privacy.sector` | enum: healthcare / financial / education / hr_intensive / b2c_ecom / public_sector / generic | generic | Drives § 7 sector overlays |

### 5.2 Settings inherited from other steps

> Re-used, never re-asked. Per architecture § 6 design rule.

- Tenant legal name, address — Step 2.
- ISO 27001 selected Y/N — Step 1 (drives whether A.5.34 placeholder
  is replaced).
- BSI selected Y/N — Step 1 (drives whether `CON.2` becomes a
  cross-reference).
- DORA selected Y/N — Step 1 (drives breach-notification matrix
  expansion).
- Suppliers / sub-processors list — pulled from existing `Supplier`
  module, not re-asked.

---

## 6. Hierarchy Considerations

### 6.1 Konzern-DPO vs subsidiary-DPO (Art. 37(2))

Art. 37(2) allows a single DPO for a group of undertakings if "easily
accessible from each establishment". The wizard handles three patterns:

| Pattern | `dpo.designation` | `dpo.is_group_dpo` | Subsidiary override |
|---|---|---|---|
| Konzern-DPO only | `mandatory` at Konzern level | `true` | Subsidiaries inherit the same DPO; cannot designate own (would create coordination chaos). Override mode: `forbidden` |
| Subsidiary-only DPOs | empty at Konzern; each Tochter has own | n/a | Each Tochter independent; Konzern doesn't carry a Charter |
| Hybrid (Konzern + sectoral subsidiary-DPO) | both | partial | Konzern-DPO is fallback / coordinator; sectoral subsidiary (e.g. healthcare BU) may have additional DPO. Override mode: `stricter_only` (Tochter may add a DPO; cannot drop the group one) |

### 6.2 Lead Supervisory Authority "main establishment" (Art. 56)

- Konzern HQ jurisdiction → Konzern-level `lead_supervisory_authority`.
- Subsidiaries default to SAME DPA unless they have independent
  decision-making authority (Art. 4(16)(a)).
- Override mode `stricter_only`: subsidiary may register a SECOND lead
  DPA; cannot REMOVE the inherited one (one-stop-shop).
- Non-EU subsidiaries: own DPA + Art. 27 EU representative required.

### 6.3 Override matrix per privacy setting

Mirroring the architecture § 7.3 matrix:

| Setting | Konzern level | Subsidiary override |
|---|---|---|
| `dpo.designation_status` | parent_value | forbidden_to_override (group-DPO arrangement requires consistency) |
| `privacy.lead_supervisory_authority` | parent_value | stricter_only (can ADD additional, cannot REMOVE) |
| `privacy.special_category_data` | — | free (operational fact; not a policy choice) |
| `privacy.consent_renewal_marketing` | parent_max | stricter_only (shorter cadence allowed; longer not) |
| `privacy.dsr_response_sla_days` | parent_max | stricter_only (faster allowed; slower not) |
| `privacy.breach_internal_notify_sla_h` | parent_max | stricter_only (faster allowed; slower not) |
| `privacy.transfer_target_countries` | parent_value | stricter_only (can REMOVE countries; cannot ADD non-listed) |
| `privacy.privacy_training_cadence` | parent_max | stricter_only (more frequent allowed; less not) |
| `privacy.iso27701_enabled` | parent_value | broader_only (can opt-in if Konzern hasn't; cannot opt-out if Konzern has) |
| `privacy.children_data` | — | free (operational reality) |

`HierarchyOverrideValidator` (architecture § 7.1) enforces these on
every wizard run. Conflict-message uses the same "ask Konzern-CISO to
relax X" copy; replace `Konzern-CISO` with `Konzern-DPO` for privacy
keys.

### 6.4 Konzern-DPO Charter vs subsidiary-DPO Charter

Open Q for Phase 3 (see § 12). Working assumption:

- If `dpo.is_group_dpo = true` → ONE Charter at Konzern level,
  cross-tagged to every subsidiary's SoA (visible at all levels).
- If hybrid → Konzern Charter + subsidiary Charter both exist; the
  subsidiary Charter explicitly references the Konzern Charter as
  parent and clarifies escalation chain.

---

## 7. Sector Overlays

### 7.1 Healthcare

- **Source:** § 22(1)(b) BDSG, § 203 StGB (medical confidentiality),
  PDSG.
- **Impact:** § 2.16 auto-adds § 22 BDSG section; § 2.9 DPA template
  adds professional-secrecy clauses (§ 203 StGB extension to processor);
  § 2.4 DSR adds "patient consent for relatives". DPIA mandatory for
  any health-data processing.

### 7.2 Financial services (DORA + GDPR)

- **Source:** GDPR + DORA (Reg. 2022/2554) + ZAG/KWG/MaRisk.
- **Impact:** § 2.5 Breach Procedure expands to multi-track matrix
  (GDPR-72h-DPA, DORA-Art.19-BaFin/ECB, MaRisk-AT 7.2 internal); § 2.9
  flags DORA-relevant suppliers (Art. 28 GDPR + Art. 30 DORA overlap).
  Biometric KYC → Art. 9 + DPIA mandatory; § 2.16 forced.

### 7.3 Schools / minors

- **Source:** Art. 8 GDPR, § 8 BDSG-neu (DE: 16), state LDSGs.
- **Impact:** `children_data = true` mandatory when sector=education;
  § 2.15 generated; DPIA mandatory.

### 7.4 HR / employee data

- **Source:** § 26 BDSG-neu, Art. 88 GDPR, BetrVG § 87(1) Nr. 6
  (technical monitoring co-determination).
- **Impact:** § 2.16 expands § 26(3) health-at-employer; § 2.4 DSR
  cross-refs § 26 access-restrictions (works-council exception); § 2.12
  PbD inserts works-council gate (aligns with `02-bsi-input.md` line
  690 + arch § 9.1 DE-specific).

### 7.5 B2C e-commerce

- **Source:** GDPR + ePrivacy/TTDSG, UWG.
- **Impact:** § 2.7 Consent Policy expands cookie-vs-Art.6 split.
  Wizard does NOT generate cookie-banner policy (BNetzA-regulated).

### 7.6 Public sector (BSI x DSGVO)

- **Source:** BDSG § 70 ff., state DSGs, BArchG § 5.
- **Impact:** § 2.2 RoPA adds public-body variant; BSI-CON.2
  cross-reference mandatory; § 2.11 adds archival-handover section.

---

## 8. Cross-Mapping to ISO 27001 + BSI + DORA + BCM

> Drives the wizard's "EXTENDS existing doc / NEW standalone" decision.
> Applied during DocumentGenerator (architecture § 5).

| Privacy doc (§ 2) | Relation | Existing-target |
|---|---|---|
| 2.1 Privacy Policy | EXTENDS A.5.34 (replaces 2-page placeholder) | Generates standalone; the ISO A.5.34 short doc is suppressed |
| 2.2 RoPA Methodology | NEW | Standalone |
| 2.3 DPIA Methodology | NEW | Standalone |
| 2.4 DSR Procedure | NEW | Standalone |
| 2.5 Breach Procedure | EXTENDS A.5.24-26 + EXTENDS DORA Art. 19 + EXTENDS BSI DER.2.1 | Standalone (high-stakes); cross-tagged |
| 2.6 Lawful-Basis Methodology | NEW | Standalone |
| 2.7 Consent Policy | NEW | Standalone |
| 2.8 Joint-Controller Methodology | EXTENDS A.5.20 | Standalone |
| 2.9 DPA Template | NOT a Document — contract template | Download artefact |
| 2.10 International Transfers | EXTENDS A.5.34 / A.5.31 / A.5.20 | Standalone (regulatory-volatility justifies separate doc) |
| 2.11 Retention Policy | EXTENDS A.5.33 + A.8.10 + EXTENDS BSI CON.6 | Standalone |
| 2.12 PbD Methodology | EXTENDS A.5.34 + A.8.25 + A.8.27 + A.8.28 | Standalone |
| 2.13 DPO Charter | NEW | Standalone |
| 2.14 Privacy Training Programme | EXTENDS ISO Awareness Programme (`01-iso27001-input.md` § 2.5) | **APPENDIX inserted into existing Awareness Programme**, not standalone — avoids duplication |
| 2.15 Children's Policy | NEW (conditional) | Standalone if generated |
| 2.16 Special-Category Policy | EXTENDS A.5.34 + A.8.11 + A.8.12 | Standalone if generated |

### 8.1 Auto-suppression rules

When the DPO addon is enabled:

1. ISO `A.5.34 Privacy / PII Handling` 2-page reference (per
   `01-iso27001-input.md` § 2.17) is **not generated** — replaced by
   § 2.1 Privacy Policy.
2. BSI `CON.2 Datenschutz-Richtlinie` (per `02-bsi-input.md` § 2.3.2,
   line 268) is **replaced by a cross-reference page** that points to
   § 2.1 + § 2.6.
3. § 2.14 Privacy Training is **inserted as Appendix B** of the ISO
   Awareness Programme rather than a standalone Document. (If ISO
   addon disabled and only BSI selected, § 2.14 becomes standalone.)

### 8.2 Tag-set per privacy doc (extends architecture § 8.5)

Beyond the architecture's standard tags, privacy docs add:

- `gdpr-art:<art-list>` (e.g. `gdpr-art:5,24,30`)
- `bdsg-§:<§-list>` (e.g. `bdsg-§:38`)
- `iso27701:<clause>` (only when ISO 27701 addon enabled; see § 3)
- `dpo-touched` (drives the DPO-cross-check workflow gate per § 9)
- `sector:<sector-key>` (drives sector-overlay filters)

---

## 9. Bulk-Approval Semantic — DPO independence carve-out

Architecture § 9.2 defines a Top-Mgmt bulk-approval inbox. For
DPO-touched documents specifically, **DPO independence (Art. 38(3))
must not be broken by bulk-action**. Rules:

### 9.1 DPO sign-off as separate gate

Privacy documents (`dpo-touched` tag) route as:

1. `prepared` (auto)
2. `dpo_sign_off` — DPO per-document review, **NO bulk button.**
   (Promotes arch § 9.1's `dpo_cross_check` to primary step before
   Top-Mgmt.)
3. `ciso_review` (technical-control alignment)
4. `top_mgmt_signoff` — bulk-approve from inbox (arch § 9.2 OK here).
5. `published`

### 9.2 DPO rejection cannot be bulk-overruled

DPO rejection at step 2 → document goes back to `draft` and is
**excluded from subsequent bulk-batches**. Top-Mgmt UI hides
DPO-rejected docs from bulk-inbox; surfaces them in a "Blocked by DPO"
panel with rationale. Implements Art. 38(3) protections.

### 9.3 DPO cannot bulk-approve their own queue either

Per-document only at step 2. Auditor concern: bulk-tool invites
rubber-stamping; DPO is meant to exercise judgement.

### 9.4 Workflow override matrix

| Step | Bulk-allowed? | Override-able by tenant? |
|---|---|---|
| `dpo_sign_off` | NO | NO (regulatory) |
| `ciso_review` | YES (existing inbox) | YES (per architecture § 9.3 `topicPolicyApprovers` config) |
| `top_mgmt_signoff` | YES (Bulk-Approval-Inbox per architecture § 9.2) | YES |
| `worksCouncilGate` (if active for HR/Logging policies) | NO (per-doc consultation) | Tenant may toggle on/off (DE-locale default ON) |

### 9.5 DPO-Charter self-approval prohibition

The DPO Charter (§ 2.13) is the **only** privacy doc where the DPO is
NOT in the approval chain (Art. 38(3) + 38(6) — DPO cannot approve
their own appointment). Pipeline is `prepared` → `top_mgmt_signoff`
direct, with the DPO listed only as the *subject* of the document.

---

## 10. Risks of Auto-Generated Privacy Policies

### 10.1 Public-facing legal exposure

§ 2.1 + § 2.7 + § 2.4 feed public-facing artefacts; wrong wording
risks Art. 83 fines (€20m / 4% turnover for Art. 5/6/7/9 breaches).
Mitigations:

- "External-publication-checked" gate (Step 6 lifecycle checkbox,
  default OFF, blocks publish).
- DE/EN parity via professionally drafted v1 templates (legal-text
  agency + native-speaker DPO review). NO LLM-only legal translation.
- Diff-highlight on re-generation for legal review.

### 10.2 Lawful-basis cannot be auto-decided

Hard rule: wizard NEVER picks Art. 6 / Art. 9 basis. § 2.6 is guidance
only; per-activity basis is captured in `ProcessingActivity` module.
Footer reads "No processing activities registered" if empty + Alva-Hint
surfaces this.

### 10.3 Cookie-banner / ePrivacy out-of-scope

TTDSG → BNetzA-regulated. § 2.7 disclaims; points to cookie-mgmt
module if active.

### 10.4 AI-system intersection — narrow scope

EU AI Act Art. 26+27 → single paragraph in § 2.12. Wizard does NOT
generate AI-Act conformity-assessment artefacts (separate regime,
separate authority). Open Q § 12.4.

### 10.5 Cross-border one-stop-shop misconfiguration

Wizard validates: lead-DPA jurisdiction must match Konzern HQ (or
Art. 27 representative jurisdiction); mismatch blocks Step 7 review.

### 10.6 Auditor pushback risk (templating)

Privacy-specific mitigations on top of arch § 11:

- Mandatory tailoring in § 2.1 "Why we process your data" (≥200 chars).
- Auto-appendix from RoPA (live `ProcessingActivity` table) so
  auditors see substantive operational tie.

---

## 11. Recommendations

### 11.1 Maximum data reuse from existing modules

NEVER re-ask data already in `ProcessingActivity`, `DataSubjectRequest`,
`DataBreach`, `Consent`, `Supplier`. `VariableCollector` (arch § 5)
reads them first. Examples:

- § 2.9 DPA template sub-processor list ← `Supplier.is_processor`.
- § 2.10 transfer-target list ← `ProcessingActivity.thirdCountries`
  (JSON ISO-3166 list) where `hasThirdCountryTransfer = true`,
  deduplicated.
- § 2.3 DPIA examples ← 3 most-recent `DataProtectionImpactAssessment`.
- § 2.4 DSR stats ← `DataSubjectRequest` annual aggregates.

### 11.2 Auto-appendix from RoPA

The Privacy Policy (§ 2.1) auto-renders an Appendix A "Categories of
processing" derived live from `ProcessingActivity` records. Each
re-render of the document re-pulls this data. Setting toggle:
`privacy.auto_include_processing_in_appendix` (default ON).

### 11.3 Re-generation Alva-Hints

Trigger (per `project_alva_hint_foundation.md` pattern):

- New `ProcessingActivity` → "Privacy Policy Appendix A out-of-date" (Tipp).
- New `Supplier.is_processor=true` without DPA → "Generate DPA?" (Aktion).
- New processing in non-adequate country → "TIA required, update
  International Transfers Policy" (Aktion / Risiko).
- EDPB / regulatory guidance change → "DPO review recommended" (Tipp,
  curated).
- ISO 27701:2019 → :2025 version bump → "Review PIMS mapping" (Tipp).

### 11.4 Footer cross-references (auditor-legible)

Every privacy doc footer-prints article refs:

```
GDPR Art. 5, 24, 30 | ISO 27701 Cl. 5.2, 7.2.8 | ISO 27001 A.5.34 | BDSG § 38
Last reviewed: 2026-04-30 | Next review: 2027-04-30
DPO: Bernd K. (bernd.k@example.com, +49 30 12345678)
```

Driven by template `linked*` fields via Twig macro.

### 11.5 Bilingual rendering with locale-pin

DE + EN bodies always. DPO Charter postal address pinned to legal-
language of appointment country (no translation). Locale-pin field on
the template.

### 11.6 DPO inbox / dashboard

New view `/dpo/inbox` (ROLE_DPO): docs awaiting `dpo_sign_off`, DSR
near-SLA, open DataBreach (privacy-relevant), pending DPIA opinions
(Art. 35(2)), annual-review-due. Not a wizard output — roadmap item;
wizard surfaces it as next-step pointer post-publication.

### 11.7 Annual DPO report — auto-compile

Phase-2 wizard variant: pulls from DSR/DataBreach/Consent/DPIA/
ProcessingActivity → single PDF for top-mgmt + supervisory authority
on request (Art. 39(1)(b)). Pure-reuse, no new data-collection.

### 11.8 No competitor names in templates

Per MEMORY `feedback_no_competitor_names.md`: privacy templates must
NOT name competitor products. Standards refs (GDPR / ISO / BfDI / EDPB)
are fine.

---

## 12. Open Questions for Phase 3

### 12.1 DPO independence — workflow enforcement

Need a dedicated `ROLE_DPO_INDEPENDENT` distinct from `ROLE_DPO`?
Art. 38(3) prohibits dismissal "for performing tasks". Today `ROLE_DPO`
is ADMIN-revocable. Options: (a) extra workflow + cause-doc on
revocation, supervisory-authority-visible; (b) keep single role + rely
on `dpo_sign_off` no-bulk rule (§ 9.2). Consult: ISB-Practitioner +
Compliance-Manager + external Auditor.

### 12.2 Joint-Controllership at Konzern level

When Konzern-DPO is shared (Art. 37(2)) and Konzern + Töchter joint-
control: ONE multi-party Charter (§ 2.8) or one per relationship?
Convention: bilateral per relationship + shared governance. Consult:
Senior-Consultant + Compliance-Manager.

### 12.3 ISO 27701 — ship both 2019 and 2025 templates?

Default :2025. Maintain :2019 too? Recommendation (DPO): ship :2025 +
legacy-mapping appendix per doc. Drop :2019-only templates after
2027-12-31. Consult: ISMS-Specialist + Senior-Consultant.

### 12.4 Privacy-by-Design + AI Act intersection

Single paragraph in § 2.12 (v1) or separate AI-Privacy addendum?
v1: paragraph. v2: dedicated AI-Act-Wizard. Consult: Senior-Consultant
+ ISMS-Specialist.

### 12.5 DPO Charter — immutable post-approval?

Per arch § 10 approved = immutable. DPO email change shouldn't need
full top-mgmt re-approval. Solution: "Charter Addendum" sub-document,
DPO + 1 top-mgmt-rep, no full pipeline. Art. 37(7) external-comm duty
preserved. Consult: Compliance-Manager.

### 12.6 Wizard-prompted Auftragsverarbeitungsregister?

§ 2.9 ships DPA template. Should the wizard also seed a processor-side
RoPA (Art. 30(2))? Schema impact: `role` field on `ProcessingActivity`
(`controller` / `processor` / `joint`). Consult: ISMS-Specialist +
check existing `ProcessingActivity` schema.

### 12.7 Sectoral-DPO within a Konzern

Hybrid (Konzern generic-DPO + subsidiary healthcare-DPO) — § 6
hierarchy treats designation as binary. Workflow impact: § 9.1
`dpo_sign_off` routes to applicable DPO (group OR sectoral) by sector
tag. Consult: Senior-Consultant + Compliance-Manager.

---

## 13. Document-Count Impact on the Standards Coverage Matrix

> Updates architecture § 3 matrix to include the DPO addon.

| Tenant choice | Total docs | Notes |
|---|---|---|
| ISO 27001 only | 25 | Per arch § 3 (1 top + 24 topic) |
| ISO 27001 + DPO addon | 25 − 1 (A.5.34 suppressed) + 14 (or up to 16 conditional) = **38–40** | § 2.1–2.14 always; + 2.15 if `children_data`; + 2.16 if `special_category_data` |
| ISO 27001 + BSI + DPO | — − 1 (CON.2 reference-only) − 1 (A.5.34 suppressed) + 14–16 = **41–47** | Wizard de-duplication kicks in |
| ISO + DORA + DPO | additive on DORA matrix | Multi-track breach matrix in § 2.5 |
| ISO + DORA + BCM + DPO | **52–60** | Ceiling case; still under v1 cap of "≈ 60" implied by arch § 3 |

Document-count is informative; the architecture's 47-cap claim should
be relaxed to "≈ 60 with all addons enabled". Flag to architecture
spec for Phase 4 update.

---

## 14. Sprint-fit (informative — refined Phase 5)

Maps to arch § 13:

- **W1 Domain:** add `tenant.privacy_*` settings + override modes; no
  new entities.
- **W2 Templates:** § 2.1 + § 2.13 + § 2.5 (highest-risk trio).
- **W3 Templates:** § 2.2 + § 2.3 + § 2.4 + § 2.6 + § 2.7 + § 2.10 +
  § 2.11 + § 2.12 + § 2.14.
- **W4 Templates:** § 2.8 + § 2.9 + § 2.15 + § 2.16 + ISO 27701 metadata.
- **W5 Workflow:** `dpo_sign_off` step + bulk carve-out + Charter
  self-approval prohibition.
- **W6 Reuse:** RoPA auto-appendix + Alva-Hints (§ 11.2-11.3).
- **W7 Hardening:** legal-counsel translation review + external-publish
  gate (§ 10.1) + cross-jurisdiction validator (§ 10.5).

---

## 15. Hand-off

Hooks into:

- **ISMS** (`01-…`) — A.5.34 suppression (§ 8.1); Awareness appendix § 2.14.
- **BSI** (`02-…`) — CON.2 cross-reference; CON.6 retention overlap;
  OPS.1.1.5 logging-privacy.
- **DORA** (`03-…`) — Art. 19 + GDPR Art. 33 unified breach proc § 2.5;
  ICT-third-party register overlap.
- **BCM** (`04-…`) — privacy-crisis-comms appendix to BC Plan; DataBreach
  ↔ Crisis Team trigger.
- **Architecture** (`05-…`) — extends § 3 doc-count; inserts Step 3a
  in § 6; refines § 9 with DPO carve-outs.

**Phase 3 personas:** DPO (implicit author) + Compliance-Manager
(data-reuse + Konzern hierarchy) + external Auditor (§ 9 carve-outs vs.
Art. 38(3) NC?) + CISO (conflict-of-interest acknowledgement § 5.1) +
Senior-Consultant (sectoral-DPO § 12.7, ISO 27701 dual-version § 12.3).

---

*End of DPO-Specialist input. Ready for Phase 2-extension architecture
update, then Phase 3 persona reviews.*


---

## Update Log

- 2026-05-07 19:42 — Initial draft (DPO-Specialist agent ac2a8e31).
- 2026-05-07 20:35 — Added §0 Decision Matrix (Section vs Standalone)
  to address user feedback "DPO sollte ISMS-Policies erweitern statt
  duplizieren". 16 documents collapsed to 5 standalone + 10 sections
  + 1 out-of-scope. Document-count drops 35-40% for GDPR-scope
  tenants. Architecture §3 + §13 to be updated accordingly in
  Phase 2-extension.
- 2026-05-07 21:50 — Decision Matrix v2 per self-review (`09-dpo-self-review.md`).
  Lawful-basis + Consent reversed to RoPA-Methodology sub-procedures (standalone).
  Children's + Special-category reversed to Privacy-Policy appendices.
  A.5.34 kept as thin cross-reference host (was suppressed).
  Added: Cookie/ePrivacy out-of-scope, AI Act deferred to Phase 1-F,
  Sectoral DPO mandate, DPO independence carve-out at section level,
  Lead-DPA logic. New §0.A DPO Veto Mechanic specified.
