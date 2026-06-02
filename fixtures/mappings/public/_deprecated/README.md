# Deprecated legacy TISAX mapping CSVs

These CSVs key the TISAX side by the legacy domain-prefixed id scheme
(`ACC-`, `INF-`, `BCM-`, …) from the pre-consolidation pseudo-catalogue
("model B"). That scheme no longer exists: the canonical TISAX catalogue is the
80 VDA-ISA 6.0 control NUMBERS (`1.1.1` … `9.8.1`). Loaded as-is they would
create dangling ComplianceMapping rows that resolve to nothing.

- `tisax_iso27001_v1.csv` — superseded by the canonical
  `fixtures/library/mappings/tisax_to_iso27001-2022_v1.0.yaml` (80 entries,
  number-keyed, resolvability-gate green).
- `tisax_iso22301_v1.csv` / `tisax_iso27005_v1.csv` — no canonical equivalent
  yet. The mapping content (CC-BY 4.0) is kept here for re-authoring as
  number-keyed YAML library mappings when needed. Do NOT load these directly.
