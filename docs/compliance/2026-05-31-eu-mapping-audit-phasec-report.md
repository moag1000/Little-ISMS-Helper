# EU Framework Mapping Audit — Phase C (Library YAML)

**Date:** 2026-05-31
**Scope:** The EU-relevant **library YAML** crosswalks (`fixtures/library/mappings/*.yaml`) — a richer, relationship-typed format the first audit (public CSVs) did not reach.
**Toolchain:** new stdlib-only `library_reader` (relationship enum → percentage; rationale-presence capture) + `audit_eu_mappings.py --library-dir` mode.

---

## Mechanical finding: the library set is the *good* set

Unlike the public CSVs (which had **0 % provenance** and forced mappings), all 8 audited EU library crosswalks are **clean**:

| Library mapping | rows | provenance | suspects | relationship mix |
|---|---|---|---|---|
| EU-CRA → NIS2 | 23 | 100 % | 0 | 7 equiv / 12 partial / 3 related / 1 subset |
| NIS2 → EU-CRA | 22 | 100 % | 0 | mixed |
| **EU-AI-Act → ISO 42001** | 47 | 100 % | 0 | **42 subset** / 3 equiv / 2 related |
| EU-AI-Act → GDPR | 22 | 100 % | 0 | 15 related / 6 partial / 1 subset |
| EU-AI-Act → NIS2 | 26 | 100 % | 0 | 14 partial / 10 related / 2 equiv |
| ENISA-EUCS → ISO 27001 | 20 | 100 % | 0 | 14 equiv / 5 related / 1 subset |
| ISO 27001 → ENISA-EUCS | 59 | 100 % | 0 | mixed |
| ENISA-EUCS → BSI-C5 | 29 | 100 % | 0 | 20 equiv / 6 partial / 3 superset |

Every entry carries `provenance.primary_source_url`, a `rationale`, a `gap_warning`, and an `audit_evidence_hint` — curator-grade. The library format also encodes addressee-differentiation notes (e.g. CRA-manufacturer vs NIS2-operator) the flat CSVs cannot.

---

## Strategic confirmation: ISO 42001 is the right home for the AI Act

Wave 4 found AI-Act requirements **force-fitted onto ISO 27001**. Phase C confirms the alternative is already shipped and strong: **`eu-ai-act_to_iso42001` maps 47 entries, 42 as `subset`** at high confidence — AI-Act articles land naturally on ISO 42001 controls:
- Art.14 human oversight → A.9.2 (equivalent)
- Art.10 data governance → A.7.2-7.6 (data-for-AI controls)
- Art.12 logging → A.6.2.8 (equivalent)
- Art.11 technical documentation → 7.5 + A.6.2.7

**Recommendation:** surface ISO 42001 as the *primary* AI-Act crosswalk in the product; keep the ISO-27001 AI-Act rows only for the genuinely-security legs (already trimmed in Wave 5).

---

## Layer-2 (in progress)

Focused specialist correctness spot-check on the two most strategically/structurally complex: `eu-ai-act_to_iso42001` (validate the 42001 home) and `cra_to_nis2-art21` (manufacturer-vs-operator addressee gap — watch for overstated `equivalent`). Findings + any relationship-label fixes will be appended.

---

## Notes

- The `--library-dir` mode is operator-run (not a CI gate); stdlib-only, no PyYAML dependency.
- Library mappings are NOT auto-imported here — this is an audit pass. Relationship-label fixes (if any from Layer-2) apply to the YAML `relationship:` field.
