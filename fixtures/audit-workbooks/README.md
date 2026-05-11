# fixtures/audit-workbooks

This directory contains reference XLSX workbooks produced by audit-export generators.

## sample-soa.xlsx

**Purpose:** Reference layout for the `SoaWorkbookGenerator` output.

This file is **NOT** production audit data and must **NOT** be handed to auditors
as an actual Statement of Applicability. It exists to:

- Document the expected sheet structure for developers building consuming templates
- Serve as a regression baseline for future `SoaWorkbookGenerator` changes
- Provide an onboarding artefact showing what the XLSX export looks like

**Sheet layout (ISO/IEC 27001:2022 Annex A SoA):**

| Sheet | Purpose |
|-------|---------|
| Cover | Organisation info, framework reference, generated timestamp, classification label |
| Controls | All Annex-A controls: ID, title, domain, applicability, justification, implementation status |
| Implementation-Status | Per-control: completeness %, last review date, evidence count, effectiveness |
| Evidence-Links | Linked evidence documents per control |
| Auditor-Notes | Reserved columns for the external auditor's observations and findings |

**Regression test:** `tests/Fixtures/SampleSoaWorkbookTest.php`

**Generator:** `src/Service/Audit/Generator/SoaWorkbookGenerator.php`
