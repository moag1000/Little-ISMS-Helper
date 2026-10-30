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

# Deprecated BSI mapping CSVs (2026-10)

- `c5_iso27001_v1.csv` — keyed by invented C5:2026 IDs (`C5-2026-ORP-1.1`,
  `C5-2026-CNT-1`, …) that do not exist in the BSI C5:2026 catalogue; all 32
  rows were dangling. Superseded by
  `fixtures/library/mappings/bsi-c5-2026_to_iso27001-2022_v1.0.yaml`.
- `bsi_grundschutz_iso27001_v1.csv` — 30 anchor rows whose rationale often
  describes a different Baustein than the cited Anforderung (e.g. OPS.1.2.5 is
  Fernwartung, not incident handling; OPS.2.4 does not exist in Edition 2023).
  Superseded by the official BSI crosswalk
  `fixtures/library/mappings/iso27001-2022_to_bsi-grundschutz_official-crt_v1.yaml`
  and `bsi-it-grundschutz_to_iso27001-2022_v1.0.yaml`. Do NOT load these directly.
