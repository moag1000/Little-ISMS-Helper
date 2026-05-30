# EU Framework Mapping Audit — Wave 4 Report

**Date:** 2026-05-30
**Scope:** The remaining EU / EU-transposition mapping sets not covered by the first audit (NIS2/DORA/GDPR/TISAX). Frameworks: **EU AI Act, NIS2-extra (ISO 22301/27005 targets), BDSG, KRITIS, TKG-2024, NIS2UmsuCG**.
**Method:** Layer-1 mechanik (toolchain extended to all 8 EU frameworks) + Layer-2 specialist audit (56 agents, 3.33M tokens, WebSearch vs EUR-Lex / gesetze-im-internet / ISO).

---

## Headline: EU AI Act is force-fitted onto ISO 27001 — its real home is ISO 42001

The single biggest finding. Many `eu_ai_act_iso27001` rows map AI-Act articles to ISO 27001 controls that share only a generic word, not substance:

| AI Act req | forced ISO 27001 target | reality |
|---|---|---|
| Art.10 data governance / bias (AIACT-3) | A.5.12 *Classification* / **A.8.3 Access restriction** | data-quality/bias ≠ security classification or access control |
| Art.13 transparency (AIACT-5) | A.5.1 *Policies* | user-facing AI transparency ≠ infosec policy set |
| Art.14 human oversight (AIACT-6) | A.5.2 *Roles* | human-in-the-loop interface design ≠ role allocation |
| Art.6 risk tiering (AIACT-1) | **A.5.7 Threat intel** | AI risk-classification ≠ threat intelligence |

**The correct target is ISO/IEC 42001:2023** (AI management system) — and the library already ships `fixtures/library/mappings/eu-ai-act_to_iso42001_v2.0.yaml`. **Recommendation:** treat ISO 42001 as the primary crosswalk for AI-Act governance requirements; keep ISO-27001 mappings only for the genuinely-security legs (Art.15 robustness/cybersecurity → A.8.29/A.8.8, Art.11 technical doc → 7.5, Art.72 monitoring → A.8.16, Art.73 reporting → A.5.24). Those *are* confirmed and defensible.

---

## Per-framework summary

| Framework | Confirmed | Suspect (overstated/forced) | Removed | Importable adds | Notes |
|---|---|---|---|---|---|
| EU-AI-ACT | 7 | 8 | **2** | 1 | force-fit pattern; route governance reqs to ISO 42001 |
| NIS2UmsuCG | 11 | 4 | **1** | 12 | German NIS2 transposition — near-1:1, cleanest set; 2 gap-fills + 2 corrections |
| BDSG | 7 | 5 | 0 | 3 | §-to-GDPR-article; §35→Art.17, §38→Art.37, §33→Art.14 verified |
| KRITIS | 5 | 6 | 0 | 2 | legacy CI regime → NIS2; several overstate equivalence |
| TKG-2024 | 3 | 7 | 0 | 0 | telecom-security → NIS2; most mappings overstated, weakest set |

All 8 EU frameworks now at **100 % provenance** (provenance_url backfilled, Phase A).

---

## Applied in this wave (conservative, autonomous)

- **Toolchain (Phase A):** Layer-1 audit extended to all 8 EU frameworks + national-transposition catalog fallback; NIS2 catalog widened to Art.20/21.1/21.3/23.
- **Provenance:** `provenance_url` + canonical EUR-Lex / gesetze-im-internet URLs on all 7 new EU CSVs → 0 % → 100 %.
- **3 forced mappings removed** (double-verified as not audit-defensible):
  - EU-AI-ACT AIACT-3 → A.8.3 (access restriction ≠ data-quality/bias)
  - EU-AI-ACT AIACT-1 → A.5.7 (threat intel ≠ AI risk-classification)
  - NIS2UmsuCG-15 → NIS2-22.1 (category error — entity-level obligation mis-mapped)

## Queued for user decision (Batches)

- **18 double-verified importable** adds/corrections (esp. NIS2UmsuCG: 2 new gap-fills NIS2UMSUCG-16/17, 2 corrections 12/13; BDSG §33/35/38; KRITIS).
- **27 fix_pct** (overstated %): the EU-AI-Act force-fits (A.5.12/A.5.1/A.5.2/9.2/A.5.20), KRITIS/TKG overstatements.
- **Strategic:** adopt `eu-ai-act_to_iso42001` as the primary AI-Act crosswalk (needs the library-YAML toolchain — Phase C).

## Phase C (not started)

Library-YAML mappings (CRA, EUCS, EU-AI-Act↔GDPR/NIS2/ISO42001) — the audit toolchain reads public CSVs only; auditing these needs a YAML-mapping reader first.

Backlog (machine-readable): `var/audit/eu_mapping_backlog_wave4.csv` (97 rows, gitignored).
