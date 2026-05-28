# ENX VDA-ISA Licensing Analysis

**Status:** Internal analysis document — NOT legal advice. Obtain ENX legal confirmation before building any feature that goes beyond the BYO-Wizard pattern.  
**Date:** 2026-05-28  
**Author:** Little ISMS Helper Maintainers  
**Review required before:** any cross-tenant catalogue or shared-seed implementation  

---

## TL;DR

1. **BYO-Upload (per-tenant, per-licensee) is legally sound** — the current wizard correctly
   implements the only safe pattern: each ENX member uploads their own licensed copy into their
   own tenant scope. No VDA-ISA content ever lives in the repository or crosses tenant boundaries.

2. **Cross-tenant seeding from a single upload is high-risk without explicit ENX consent** —
   sharing one licensee's workbook parse across tenants controlled by different legal entities
   almost certainly constitutes redistribution to non-licensees, which ENX prohibits.

3. **Referencing VDA-ISA control IDs (without descriptive text) in our own mapping YAMLs is
   defensible under standard copyright doctrine** — numeric identifiers and structural metadata
   are facts, not creative expression, and are not copyrightable in most jurisdictions; pending
   ENX legal confirmation this interpretation should be documented explicitly.

---

## 1. Sources Consulted

| Source | URL | Key finding |
|--------|-----|-------------|
| ENX TISAX main page | https://enx.com/en-US/TISAX/ | Copyright © ENX Association; assessment results exclusive to registered participants; no granular workbook licence terms on page |
| ENX portal downloads | https://portal.enx.com/en-US/TISAX/downloads/ | ISA 6.0.3 "officially published by VDA"; participation requires acceptance of TISAX GTCs; full terms in separate PDF |
| ENX legal notice / imprint | https://enx.com/imprint/ | Broad copyright assertion over site content; reproduction requires advance written consent from ENX; no workbook-specific terms |
| ENX Participant Handbook (HTML) | https://portal.enx.com/handbook/tisax-participant-handbook.html | "All rights reserved by ENX Association"; TISAX® is a registered trademark; GTCs govern relationship; ISA licence terms not detailed inline |
| ADR-0010 (project internal) | docs/adr/0010-tisax-byo-vs-pre-seeded.md | ENX explicitly prohibits redistribution in member portal terms (first-hand observation from maintainer who read portal terms at time of ADR authoring, 2026-02-15) |
| VDA website (DE) | https://www.vda.de/de/themen/sicherheit-und-standards/informationssicherheit/vda-isa | HTTP 404 — page not found; VDA does not publish ISA terms publicly on this URL |

**Gaps:** The actual TISAX Participation General Terms and Conditions (GTC) PDF and the specific
VDA-ISA workbook download licence terms are behind ENX member authentication and could not be
fetched programmatically. All claims below are based on publicly visible ENX pages plus the
maintainer's direct reading of portal terms documented in ADR-0010.

**Key confirmed facts (best-effort, pending ENX legal contact):**

- TISAX® is a **registered trademark** of ENX Association.
- ENX asserts full copyright over portal content and downloadable materials.
- Participation requires acceptance of the TISAX GTCs — a contractual gate.
- VDA-ISA workbook is published by VDA, distributed via ENX member portal (member login required).
- ADR-0010 records that ENX "explicitly prohibits redistribution" in portal terms (2026-02-15 reading).

---

## 2. Use-Case Classification

### A — BYO-Upload: Customer uploads their own licensed copy → tenant-scoped DB rows

**Classification: ALLOWED (current implementation)**

Each ENX member downloads the workbook under their own ENX membership and uploads it to their
own tenant. The parsed data never leaves that tenant's DB scope. No VDA-ISA content appears in
the repository. This is the safest conceivable use pattern — the member is exercising their own
licence for their own purposes with their own data.

Implementation reference: `src/Controller/Compliance/TisaxImportController.php`,
`src/Form/Tisax/TisaxLegalConfirmationType.php`, `src/Entity/TisaxLicenseConfirmation.php`.

Risk mitigation already in place:
- Step-0 licence confirmation (stored with IP + session token).
- Wizard disclaimer: *"You must hold a valid ENX member licence to use this content."*
- Zero VDA-ISA content in fixtures, migrations, or templates.

### B — Cross-tenant catalogue: One customer uploads → ALL tenants benefit (shared seed)

**Classification: FORBIDDEN / HIGH LEGAL RISK — do not implement without explicit ENX consent**

When tenant A (an ENX member) uploads their workbook, the parsed questions, control texts, and
requirement descriptions become a structured copy of ENX/VDA's copyrighted catalogue. Storing
this as a shared, cross-tenant resource that tenant B (a different legal entity) can read
effectively distributes the workbook content to tenant B without tenant B holding an ENX licence.

This is redistribution by any reasonable interpretation of copyright law. ENX's explicit
prohibition on redistribution (ADR-0010) directly covers this scenario.

**The user's suggestion "Ggfs. können wir den Seed ja mit Upload durch den Nutzer machen :)"**
is legally safe ONLY as **Interpretation 1** (customer uploads → their own tenant gets seed,
i.e., the current BYO-Wizard). It is NOT safe as "one upload seeds all tenants of the instance."

See also Section 4 for the three interpretations.

### C — Ship workbook content in codebase (AGPL-distributed binary)

**Classification: FORBIDDEN — already correctly rejected in ADR-0010**

Pre-seeding the workbook as fixtures, PHP arrays, or database migrations would embed copyrighted
ENX/VDA content in AGPL-distributed code. The AGPL licence grant cannot override a third-party
copyright on embedded data. Do not revisit without a commercial licence agreement with ENX/VDA.

### D — Extract only control IDs → shared metadata skeleton (no question text)

**Classification: AMBIGUOUS — likely allowed under copyright doctrine, but confirm with ENX**

In most jurisdictions (DE, EU), numeric identifiers and structural codes (e.g., `INF-1.1`,
`IS-08-01-01`) are classified as **facts** rather than creative expression and are therefore
not subject to copyright protection. The creative work in a VDA-ISA workbook is the assessment
question text, objectives, and explanatory notes — not the control number scheme itself.

**Best-guess interpretation:** A skeleton of category names, control IDs, and hierarchical
structure (without any descriptive text from the workbook) extracted from a member-uploaded
workbook and shared as a cross-tenant "TISAX structure" metadata would likely survive a copyright
challenge in German/EU law (§87a UrhG — database rights require substantial investment, but
individual facts within are not protected).

**However:** ENX/VDA may assert database rights (sui generis database right under EU Directive
96/9/EC) over the structured catalogue as a whole, separate from individual element copyright.
Extraction of a substantial part of a database can infringe database rights even without copying
creative expression.

**Action required:** Legal opinion from an IP/copyright lawyer OR explicit ENX permission before
building a cross-tenant ID-only skeleton seeded from member uploads.

**Existing codebase note:** `fixtures/library/frameworks/vda-isa-tisax-v6.yaml` contains
10 representative ISA controls INCLUDING descriptive text (description, requirement text,
prueffragen). This file has not been distributed to end users (it is a fixtures file, not a
loaded fixture). However, it should be evaluated: if it contains verbatim VDA-ISA text, it
constitutes pre-seeded copyrighted content even at fixture level. **Recommend auditing this file
and stripping to ID-only metadata before next release** — or deleting it entirely and relying
solely on the BYO import wizard. See Section 5 (Open Questions) item OQ-1.

### E — Display compilation metadata (control counts, category names) in dashboards

**Classification: ALLOWED (probably) — low copyright risk for factual/statistical metadata**

Displaying "Your TISAX assessment covers 89 controls across 6 domains" is factual metadata
derived from the customer's own licensed data. This is analogous to displaying the number of
pages in a licensed book — not a reproduction of creative content.

Category names ("Informationssicherheits-Management", "Human Resources Security") are structural
labels that appear in ISO 27001, BSI, and other public standards alongside VDA-ISA. Their
copyright protection is minimal to none as technical taxonomy terms.

**Do not display question text, requirement descriptions, or objective texts in shared/public
dashboards** (e.g., in holding-level overview views that aggregate across tenants with different
licence statuses).

### F — Export to PDF/Excel of customer's assessment with VDA-ISA control-text inside

**Classification: ALLOWED**

A licensed ENX member generating a PDF/Excel of their own assessment is exercising their licence
for their own compliance documentation purposes. This is the intended use case of the workbook.
The export is customer-private, not redistributed to third parties.

Implementation note: Ensure exports are tenant-scoped and cannot be downloaded by other tenants
(standard RBAC gate applies). Do not send exports to external systems without the user's explicit
consent.

### G — Sharing assessment results between linked tenants in a holding/group structure

**Classification: AMBIGUOUS — depends on ENX membership structure**

**Sub-case G1 — Both sub-tenants are ENX members of the same holding group:**  
Probably allowed. ENX's result-sharing mechanism is designed for B2B sharing between participants.
An ENX member group where all legal entities are independently registered should be able to share
results by the ENX result-sharing flow (explicit release by assessed company).

**Sub-case G2 — Only the parent holding is an ENX member, subsidiaries are not:**  
High risk. The sub-tenant that is not an ENX member would be accessing VDA-ISA content it has
not licensed. Even within a corporate group, ENX membership is entity-specific.

**Implementation recommendation:** Gate group-level TISAX result sharing behind a per-sub-tenant
confirmation that each entity holds an ENX licence (same confirmation pattern as BYO wizard).
The `TisaxLicenseConfirmation` entity already exists for this purpose.

### H — OpenAPI/REST endpoint exposing control-text to authenticated tenant users

**Classification: ALLOWED (for the tenant that uploaded the content)**

An API endpoint that returns a tenant's own VDA-ISA controls (parsed from their own upload) to
authenticated users of that tenant is a functional interface over the tenant's own licensed data.
This is no different from rendering the data in a Twig template.

**Guard requirements:**
- Standard `tenant_id` filter applies (Doctrine filter).
- Authentication and authorisation required (ROLE_USER minimum, ROLE_MANAGER for sensitive fields).
- The API must NOT expose one tenant's TISAX data to another tenant's users.
- Document in OpenAPI spec that TISAX endpoints require the `tisax` module to be active.

### I — AGPL §13 implication (SaaS network use → source code disclosure)

**Classification: NON-ISSUE — these are orthogonal concerns**

AGPL §13 requires that users who interact with the software over a network can receive a copy
of the source code. This applies to **our PHP/Twig/JS source code** — not to the customer's data.

VDA-ISA content stored in a customer's database is:
1. Not part of the software's source code.
2. The customer's own licensed data.
3. Not redistributed to other users of the software.

AGPL source disclosure and ENX VDA-ISA workbook licensing operate on completely separate layers.
There is no conflict. Our obligation is to provide source code; ENX's restriction is on the
workbook content. These do not intersect.

### J — Cross-framework mapping YAMLs referencing VDA-ISA control IDs

**Classification: ALLOWED (with caveats on text content)**

Files such as `fixtures/library/mappings/tisax-vda-isa-6_to_iso27001-2022_v1.0.yaml` and
`fixtures/mappings/public/tisax_iso27001_v1.csv` reference VDA-ISA control identifiers
(`ISA 1.1.1`, `INF-1.1`, etc.) alongside ISO 27001 control IDs.

**Copyright analysis:**
- Control ID references (`INF-1.1`) are factual identifiers, not creative expression.
- Short structural labels ("Information security policy") are technical taxonomy terms that
  appear in multiple standards simultaneously and carry minimal copyright protection.
- Our mapping work (confidence ratings, rationale, audit hints) is entirely our own original
  expression.

**Verdict:** Mapping YAMLs that contain only our own mapping rationale + control ID cross-refs
are original works whose references to VDA-ISA IDs do not constitute reproduction of copyrighted
content. This is equivalent to a bibliography citing a book by its chapter numbers.

**Caution:** Review mapping YAMLs for any verbatim VDA-ISA question text in the `rationale`
fields. Any verbatim text from the workbook (questions, objectives, explanatory paragraphs)
should be removed and replaced with paraphrase + source attribution.

Current mapping files reviewed: the rationale fields in `tisax-vda-isa-6_to_iso27001-2022_v1.0.yaml`
contain original German analysis text written by the maintainer — these appear to be original
expression, not VDA-ISA verbatim text.

### K — Sector presets naming "TISAX" / "VDA-ISA" as framework names

**Classification: ALLOWED — trademark nominative use**

Referencing "TISAX" and "VDA-ISA" as names of compliance frameworks in sector presets, module
configuration, and UI labels is **nominative trademark use** — you cannot describe or name a
framework without using its name. Trademark law in DE/EU permits nominative use for informational,
comparative, or descriptive purposes, as long as:

1. You do not imply affiliation, endorsement, or partnership with ENX/VDA.
2. You do not use the mark as your own brand.
3. You do not modify the mark in a way that creates confusion.

**Best practice already in place (ADR-0010):** The wizard legal footer correctly states:
*"TISAX and VDA-ISA are trademarks of the Verband der Automobilindustrie (VDA)."*

**Extend this attribution** to any marketing text, README sections, or documentation that
prominently references TISAX, to maintain the nominative-use defence.

---

## 3. Recommendation Matrix

| Use case | Legal? | Impl. status | Action needed |
|----------|--------|--------------|---------------|
| A — BYO-Upload + tenant-scoped seed | **Allowed** | Implemented | None; maintain licence-confirmation flow |
| B — Cross-tenant catalogue from one upload | **Forbidden** | NOT built | Do not build without explicit ENX licence agreement |
| C — Ship workbook in repo/fixtures | **Forbidden** | Not done (correct) | See OQ-1 re: vda-isa-tisax-v6.yaml |
| D — ID-only shared metadata skeleton | **Ambiguous** | NOT built | Legal review before building; see OQ-2 |
| E — Metadata stats (counts, category names) | **Allowed (probably)** | NOT built | Safe to build; no verbatim text required |
| F — PDF/Excel export for licensed tenant | **Allowed** | Partially implemented | Ensure tenant-scope guard on export download |
| G1 — Result sharing (all entities licensed) | **Allowed (probably)** | NOT built | Add per-entity ENX-licence confirmation gate |
| G2 — Result sharing (non-licensed sub-tenant) | **Forbidden** | NOT built | Block; require each entity to confirm ENX licence |
| H — REST API for own tenant's TISAX data | **Allowed** | NOT built | Build with standard tenant_id filter + module gate |
| I — AGPL §13 SaaS source disclosure | **Non-issue** | N/A | No action needed |
| J — Mapping YAMLs referencing control IDs | **Allowed** | Implemented | Audit rationale text for verbatim VDA-ISA quotes |
| K — Naming "TISAX"/"VDA-ISA" in UI/docs | **Allowed (nominative)** | Implemented | Maintain trademark attribution footer |

---

## 4. Concrete Proposal for "Shared Seed" Idea

The user's suggestion: *"Ggfs. können wir den Seed ja mit Upload durch den Nutzer machen :)"*

### Interpretation 1 — Customer uploads → their tenant is seeded (CURRENT IMPLEMENTATION)

This is exactly what the BYO-Wizard does today. No changes needed. Each customer who activates
the `tisax` module uploads their ENX-licensed workbook once, and the import wizard seeds their
tenant's `compliance_control` records. This is **legally safe and already implemented**.

### Interpretation 2 — One customer uploads → all tenants on the instance benefit

**Do not build.** This is redistribution. Even if framed as "first upload triggers installation-
wide seeding," the legal effect is that tenants who never held an ENX licence gain access to
VDA-ISA content. ENX would have a plausible copyright infringement claim against the installation
operator.

### Interpretation 3 — Installation-admin uploads once → all tenants on that installation share

This is a nuanced middle ground with a specific use case: a **consultancy or MSSP** that holds
one ENX member licence and operates Little ISMS Helper as a multi-tenant platform for their
clients, where all clients are also ENX members or are served as sub-entities of the consultancy's
own ENX scope.

**This pattern requires:**
1. The installation operator is an ENX member (confirmed at install time).
2. Every tenant on the installation is either (a) an ENX member themselves, or (b) explicitly
   covered by the operator's ENX membership scope (e.g., operating as a single legal entity's
   departments).
3. The installation-level upload is still per-licensee — just at the installation level rather
   than per-tenant.

**If these conditions hold:** This is legally equivalent to Interpretation 1 (one licensee seeding
their own scope). If the conditions don't hold (clients are independent legal entities without
ENX licences), it is legally equivalent to Interpretation 2 (forbidden).

**Recommended implementation if building Interpretation 3:**
- Installation-admin upload flow at `/admin/tisax/installation-seed`.
- Force the admin to confirm: "I am an ENX member and all tenants on this installation are
  covered under my ENX membership scope."
- Store an `InstallationTisaxLicenceConfirmation` record (parallel to `TisaxLicenseConfirmation`
  but at installation level).
- Gate the per-tenant TISAX activation on the existence of a valid installation-level confirmation
  OR a per-tenant BYO upload.
- This gives the consultancy MSSP their desired UX (upload once, activate per tenant) while
  maintaining audit-trail evidence of the licence confirmation.

**Do not build Interpretation 3 without first consulting ENX.** Send the template email in
Section 6.

---

## 5. Open Questions for ENX Legal Contact

### OQ-1 — Framework skeleton file with representative control descriptions

`fixtures/library/frameworks/vda-isa-tisax-v6.yaml` in the Little ISMS Helper repository
contains 10 representative VDA-ISA v6.0 controls including description text, requirement text,
and sample assessment questions ("prueffragen"). This file is used as a developer reference and
was never shipped as a loaded fixture to end users.

**Question for ENX:** Does the presence of representative VDA-ISA control descriptions in an
AGPL-licensed software repository (even as a developer reference file, not loaded into user
installations) constitute redistribution of copyrighted VDA-ISA content? What is ENX's
position on this?

**Interim action (conservative):** Recommend removing or replacing the descriptive text in
`fixtures/library/frameworks/vda-isa-tisax-v6.yaml` with placeholder text before the next
public release, retaining only IDs, category structure, and metadata. See below.

### OQ-2 — ID-only skeleton seeded across tenants

**Question for ENX:** If a software installation operator (ENX member) uploads their licensed
VDA-ISA workbook, may we extract only the control ID scheme, category structure, and maturity
level numbers (without any descriptive text, questions, or objectives) and store this as a
shared structural template available to all tenants on the installation? This would function
as a "scaffold" that tenants then annotate with their own assessment answers.

### OQ-3 — Third-party software integration clause

**Question for ENX:** The TISAX GTCs govern participation in the TISAX exchange programme.
Is there a separate licence or approval process for third-party software vendors who build
TISAX self-assessment support tooling that processes the VDA-ISA workbook at customer request?
Does ENX offer a "software partner" or "integration" programme?

### OQ-4 — Trademark usage in open-source software

**Question for ENX:** We reference "TISAX®" and "VDA-ISA" as framework names in our AGPL-
licensed software. We include a trademark attribution notice in the relevant UI. Is this
nominative use acceptable under ENX's trademark policy, or do we need a formal trademark
licence?

---

## 6. Template Email to ENX Legal

```
To: legal@enx.com  (or tisax@enx.com if no legal contact found)
Subject: VDA-ISA Workbook Licensing — Third-Party Open-Source Software Integration

Dear ENX Association Legal Team,

We develop "Little ISMS Helper", an open-source (AGPL-3.0) Information Security Management
System available at [repository URL]. We have implemented a "Bring Your Own Workbook" import
wizard for TISAX self-assessment support, where ENX member customers upload their own licensed
VDA-ISA workbook for use within their tenant scope.

We are writing to request clarification on the following specific questions:

1. DEVELOPER REFERENCE FIXTURE: Our repository contains a YAML file with 10 representative
   VDA-ISA v6.0 control descriptions intended as a developer reference (not loaded into user
   installations). Does this constitute redistribution of copyrighted VDA-ISA content under
   your terms?

2. ID-ONLY CROSS-TENANT SKELETON: If an ENX-member installation operator uploads their
   licensed workbook, may we extract only control IDs and category names (no descriptive text)
   and share this structural template across tenants on their installation?

3. SOFTWARE PARTNER PROGRAMME: Is there a formal integration/partner programme for software
   vendors building TISAX-assessment tooling?

4. TRADEMARK USAGE: Our software references "TISAX®" and "VDA-ISA" as framework names with
   trademark attribution. Is nominative use in AGPL open-source software acceptable?

We are committed to full compliance with ENX's intellectual property rights and would welcome
guidance on the appropriate usage boundaries.

Kind regards,
[Maintainer name]
Little ISMS Helper — [repository URL]
```

ENX contact: tisax@enx.com / +49 69 986 69 27-77 / https://enx.com/contact/

---

## 7. Immediate Codebase Actions (Conservative, No ENX Contact Required)

### Action 1 — Audit `fixtures/library/frameworks/vda-isa-tisax-v6.yaml`

The file contains descriptive text that may constitute partial reproduction of VDA-ISA content.
Conservative approach: replace `description`, `anforderungen.text`, and `prueffragen` fields
with `[PLACEHOLDER — install via BYO wizard]` strings. Retain only IDs, category structure,
maturity level numbers, and `iso27001Ref` cross-references (which are our own mapping work).

### Action 2 — Audit mapping YAML `rationale` fields for verbatim VDA-ISA text

Review all `rationale` entries in `fixtures/library/mappings/tisax-*.yaml` and
`fixtures/mappings/public/tisax_*.csv` for any verbatim VDA-ISA question text. Replace with
paraphrase + source citation if found.

### Action 3 — Block Interpretation 2/3 at code level

Until ENX legal confirmation, add a code-level guard that prevents cross-tenant TISAX control
sharing: ensure `ComplianceRequirement` records with `framework = tisax` are always filtered by
`tenant_id` even in admin views and holding-group dashboards.

### Action 4 — Add ENX legal contact to `12-maintainer-handoff.md`

Already present: `docs/onboarding/12-maintainer-handoff.md` already lists ENX as emergency
contact for licensing questions. No action needed.

---

## 8. References

| Document | URL / Path |
|----------|-----------|
| ENX TISAX main | https://enx.com/en-US/TISAX/ |
| ENX portal downloads | https://portal.enx.com/en-US/TISAX/downloads/ |
| ENX legal notice | https://enx.com/imprint/ |
| ENX Participant Handbook | https://portal.enx.com/handbook/tisax-participant-handbook.html |
| ENX contact | https://enx.com/contact/ — tisax@enx.com |
| ADR-0010 (internal) | docs/adr/0010-tisax-byo-vs-pre-seeded.md |
| EU Database Directive | https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX:31996L0009 |
| German Copyright Act (UrhG) §87a | https://www.gesetze-im-internet.de/urhg/__87a.html |
| BYO import wizard implementation | src/Controller/Compliance/TisaxImportController.php |
| Licence confirmation entity | src/Entity/TisaxLicenseConfirmation.php |
| Framework skeleton (needs audit) | fixtures/library/frameworks/vda-isa-tisax-v6.yaml |
| Mapping YAML (review rationale) | fixtures/library/mappings/tisax-vda-isa-6_to_iso27001-2022_v1.0.yaml |

---

*This document is an internal analysis prepared for maintainer decision-making. It does not
constitute legal advice. For binding determinations, consult a licensed IP/copyright attorney
in Germany/EU and/or contact ENX Association directly at the address above.*
