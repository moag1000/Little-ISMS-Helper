# ADR-0010: TISAX — BYO-Import Wizard Instead of Pre-Seeded Controls

**Status:** Accepted  
**Date:** 2026-02-15  
**Deciders:** moag1000  
**Tags:** tisax, licensing, enx, vda-isa, compliance-import, legal

---

## Context

TISAX (Trusted Information Security Assessment Exchange) is the automotive-sector information
security standard governed by the ENX Association. It is based on the VDA-ISA (Verband der
Automobilindustrie Information Security Assessment) workbook, which defines assessment questions
and control areas.

The core legal constraint is:

> **The VDA-ISA workbook (Excel / PDF) is copyrighted and distributed exclusively through the ENX
> Association portal under a member-only licence.** Redistributing the workbook content — including
> embedding the assessment questions, control texts, or requirement numbers in open-source software
> as hard-coded data — constitutes copyright infringement of ENX/VDA material.

This is not a theoretical concern: ENX explicitly prohibits redistribution in their member portal
terms. An open-source project that ships VDA-ISA content as pre-seeded database fixtures or as
PHP array constants would expose users to a copyright claim from ENX/VDA and would violate the
AGPL-3.0 notice requirements (the AGPL grant cannot override a third-party copyright claim on
embedded data).

Compared to ISO 27001 (whose Annex A control names are published in ISO public drafts and
frequently cited in publicly available implementation guides), VDA-ISA question text has no
published open-access version.

### Options evaluated

| Option | Verdict |
|---|---|
| Pre-seed VDA-ISA controls as fixtures in `src/DataFixtures/` | **Rejected** — copyright violation |
| Ship VDA-ISA content under a "download on first run" mechanism | **Rejected** — redistribution at different granularity, same legal risk |
| Partner with ENX for a royalty-bearing licence | **Deferred** — requires commercial entity, legal agreement, fee structure |
| BYO (Bring Your Own) import wizard: operator uploads their licensed workbook | **Chosen** |

---

## Decision

**Implement a TISAX Bring-Your-Own import wizard at `/compliance/import/tisax` that accepts an
uploaded VDA-ISA Excel workbook (downloaded by the operator from the ENX portal) and parses it
into the application's `ComplianceControl` model.**

The wizard:
1. Accepts `.xlsx` upload (operator's own licensed copy of VDA-ISA).
2. Parses question IDs, control areas, maturity levels, and applicability flags using PhpSpreadsheet.
3. Presents a column-mapping preview screen (`fa-diff-row` macro) so the operator can confirm the
   parsed structure before committing.
4. Imports into `compliance_control` table tagged `framework: tisax`, `tenant_id: <current>`.
5. Links imported controls to the operator's existing assets and risk register.

The application ships with zero VDA-ISA content. No VDA-ISA text appears in any fixture, migration,
seed, or template. The import wizard code itself does not contain VDA-ISA question text.

**Module gate:** The TISAX import wizard and all TISAX-related routes are gated behind the `tisax`
module key. Tenants not subscribed to TISAX do not see any TISAX UI.

**Legal footer:** The wizard's upload step displays a notice: *"TISAX and VDA-ISA are trademarks
of the Verband der Automobilindustrie (VDA). The VDA-ISA workbook is available exclusively from
the ENX Association portal (enx.com). You must hold a valid ENX member licence to use this content."*

---

## Consequences

### Positive

- **No copyright exposure:** The repository contains zero third-party copyrighted compliance
  content. AGPL-3.0 licence is clean.
- **Operator autonomy:** Operators who hold ENX licences can import the exact workbook version
  they are assessed against (VDA-ISA 6.0, 6.1, etc.). The wizard handles version differences via
  configurable column mapping.
- **Extensible pattern:** The same BYO-import wizard pattern is used for other frameworks where
  redistributing content is legally ambiguous (ISO 27002:2022 full control text, NIST CSF 2.0
  subcategories beyond the publicly available titles).

### Negative

- **Operator friction:** TISAX-enabled tenants must download the workbook from ENX themselves and
  upload it manually. This is a one-time setup step but it is a barrier compared to "click to
  activate TISAX controls".
- **Parse fragility:** If ENX restructures the Excel columns between VDA-ISA versions, the parser
  breaks. The column-mapping preview step mitigates this — operators can re-map on upload. A
  "known-versions" lookup table in `config/tisax_column_maps.yaml` handles common versions.
- **No content updates:** When VDA-ISA publishes an update, operators must re-download and re-import.
  The wizard supports incremental import with delta preview.

---

## TISAX Navigation Notes for Maintainers

VDA-ISA workbook structure (as of v6.x):
- Column A: Question ID (e.g., `IS-08-01-01`)
- Column B: Control area name
- Column C: Question text
- Column D: Maturity level (1/2/3)
- Column E: Applicability condition

ENX portal: [https://enx.com](https://enx.com) (member login required for workbook download).
Assessment exchange platform: ENX portal → TISAX → Download Assessment Workbook.

---

## References

- `src/Controller/Compliance/TisaxImportController.php`
- `src/Service/Compliance/TisaxWorkbookParser.php`
- `config/tisax_column_maps.yaml` — known version column mappings
- `templates/compliance/import/tisax/` — import wizard templates
- `templates/_components/_fa_diff_row.html.twig` — delta preview macro
- `config/modules.yaml` — `tisax` module key definition
- CLAUDE.md §"Module-Awareness"
- [ENX Association](https://enx.com)
