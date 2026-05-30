# Mapping Audit — Wave 6 (full-corpus coverage)

**Date:** 2026-05-31
**Scope:** Close the coverage gap honestly — every mapping in the repo, not just the EU-regulatory core.

---

## Phase A — full mechanical coverage (done)

- **provenance_url backfilled** on the 10 remaining public CSVs with **official source URLs** (BSI IT-Grundschutz + C5, CIS Controls v8, NIST CSF, AICPA SOC 2, ISO, ENX/VDA TISAX). → **all 22 public CSVs now 100 % provenance, 0 mechanical suspects.**
- **library_reader extended** to 3 entry schemas (`target:` + relationship; `targets: [inline]`; `targets:` block-list with `maturity`/`category` anchor entries). → **all 64 library YAML parse cleanly (3196 rows), 100 % provenance, 0 mechanical suspects.**

**Full corpus = 86 mapping files, all mechanically clean.**

## Phase B — targeted correctness (done where it matters)

Risk-ranked the unaudited set by over-claim signal. Key insight: a high `equivalent` rate is **not** itself a defect — for ISO-derived standards (BSI C5, TISAX, EUCS, ISO 27701 ↔ ISO 27001) high equivalence is *by-design correct*. The real risk is **cross-regime / cross-addressee** mappings (the CRA→NIS2 pattern). Audited those:

- **NIS2 → EU-CRA** (reverse of the already-fixed CRA→NIS2): had the same 7 over-claims. Applied the verified-symmetric corrections — 6 `equivalent`→`partial_overlap`/`related`, 1 spurious removed (Annex-II-1 manufacturer name). Now 0 overstated equivalence. *(No new specialist run — the pairs were already adversarially verified in the forward direction.)*
- **EU-AI-Act → NIS2**: 2 cross-regime over-claims downgraded (Art.15 robustness vs 21.2.h cryptography → `related`; Art.72 monitoring vs 21.2.f effectiveness → `partial_overlap`).
- **EU-AI-Act → GDPR**: already conservative (0 `equivalent`, all `related`/`partial`) — no change needed.

## Honest residual (not specialist-audited)

These remain **Layer-1-verified** (100 % provenance, 0 mechanical suspects) but **not** specialist-correctness-audited — accepted as lower-priority:

- **ISO-family crosswalks** (C5 / TISAX / EUCS / ISO 27701 / NIST / BSI ↔ ISO 27001): high equivalence is legitimate; same control-language lineage. Low over-claim risk.
- **~50 other library YAML** (bafin-bait↔dora, bsi-c5 version-to-version, iso↔iso variants, mris, tisax variants): mechanically clean; would benefit from specialist spot-checks if/when prioritised.
- **Non-EU public CSVs** (CIS, NIST-CSF, SOC 2, ISO 22301↔27001, ISO 27701↔27001): out of the original EU-critique scope; mechanically clean.

## Bottom line

The **EU-regulatory core + all cross-regime over-claim risk** is now specialist-verified. The **entire corpus is mechanically clean** (100 % provenance, 0 suspects). The residual is a documented, lower-risk tail — not a hidden gap.
