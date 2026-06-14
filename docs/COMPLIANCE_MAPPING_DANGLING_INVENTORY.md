# Compliance Mapping — Dangling-ID Inventory

> **Status:** Decision-ready analysis  
> **Generated:** 2026-06-14 against `origin/main` (HEAD `bdebe6dfa`)  
> **Data source:** `php bin/console app:audit-catalog-mappings` → `var/audit/catalog_mappings_inventory.json`  
> **Action required:** Human decision per Category-A entry; no code changes in this file.

---

## Summary

| Metric | YAML library (75 files) | CSV public (19 files) | **Total** |
|---|---|---|---|
| Mapping rows | 4 013 | 395 | **4 408** |
| Dangling source-IDs | 972 | 206 | **1 178** |
| Dangling target-IDs | 1 278 | 66 | **1 344** |
| Total dangling | **2 250** | **272** | **2 522** |
| Unresolved framework refs | 6 | 1 | **7 unique** |

Loaded frameworks (32): BDSG, BSI_GRUNDSCHUTZ, BSI-C5, BSI-C5-2026, CIS-CONTROLS, DIGAV, DORA, EU-AI-ACT, EU-CRA, GDPR, GXP, IKT-MINSTD-CH, ISO-22301, ISO27001, ISO27005, ISO27017, ISO27018, ISO27701, ISO27701_2025, ISO42001, KRITIS, KRITIS-HEALTH, MRIS-v1.5, NIS2, NIS2UMSUCG, NISG-AT, NIST-CSF-2.0, PCI-DSS-4.0.1, REVDSG-CH, SOC2, TISAX, TKG-2024.

Produced (framework, requirementId) catalog pairs: **4 264**.

---

## Category A — Unresolved Framework References

These framework identifiers appear in mapping files but have **no wired loader** — the entire framework is missing from the catalog. All mappings involving the unresolved side are 100 % dangling.

### A.1 YAML library — 6 unresolved frameworks

| Framework ID | Files referencing it | Affected mappings | Classification | Recommended action |
|---|---|---|---|---|
| `BAIT` | `bafin-bait_to_dora_v1.0.yaml` (src, 32 rows)<br>`dora_to_bafin-bait_v1.0.yaml` (tgt, 32 rows) | 64 | **obsolete** — BAIT superseded by DORA (Regulation (EU) 2022/2554, mandatory from Jan 2025; BaFin confirmed BAIT withdrawal) | Deprecate both files; add tombstone comment. No loader needed. |
| `EUCS` | `bsi-c5-2020_to_eucs_v1.0.yaml` (tgt, 24 rows)<br>`eucs_to_bsi-c5-2020_v1.0.yaml` (src, 29 rows)<br>`eucs_to_iso27001-2022_v1.0.yaml` (src, 54 rows)<br>`iso27001-2022_to_eucs_v1.0.yaml` (tgt, 59 rows) | 166 | **no-loader** — EU Cloud Services Scheme is a real published ENISA standard (2023); no EUCS loader exists yet | Build `EucsLoader` (ENISA EUCS v1.0 requirements are public); canonicalize IDs to ENISA scheme (e.g. `OIS-01`). Four EUCS files use two different ID schemes (`OIS-01…` and `EUCS-A1…`); normalise to one during loader build. |
| `iec-isa-62443` | `tisax_to_iec-isa-62443_v1.0.yaml` (tgt, 25 rows) | 25 | **no-loader** — IEC/ISA 62443 (OT/ICS security) is a real published standard; no loader exists | Build `IecIsa62443Loader` or scope out as out-of-ISMS roadmap. License note: full control text is not freely redistributable — map control numbers only. |
| `ISO27002` | `tisax_to_iso27002_v1.0.yaml` (tgt, 1 row) | 1 | **sub-concept** — ISO 27002:2022 is the control catalogue that underpins ISO 27001 Annex A; ISO 27001 controls already loaded via `ISO27001` | Remap the single row to the `ISO27001` target ID (Annex A control IDs are identical in loaded catalog) and delete the file; or add `ISO27002` as an alias for `ISO27001` in the loader. |
| `nist-csf-1.1` | `tisax_to_nist-csf-1.1_v1.0.yaml` (tgt, 33 rows) | 33 | **alias** — NIST CSF 1.1 is a prior major version. NIST-CSF-2.0 is already loaded. CSF 1.1 subcategory IDs (e.g. `ID.AM-1`) differ structurally from CSF 2.0 GV/ID/PR/DE/RS/RC hierarchy | Decision required: (a) add `nist-csf-1.1` loader for the legacy version, (b) migrate the 33 TISAX→CSF-1.1 mappings to CSF-2.0 equivalents and rename the file, or (c) deprecate as superseded. Recommended: migrate to CSF-2.0 (NIST published official 1.1→2.0 mapping). |
| `nist-sp800-53r5` | `tisax_to_nist-sp800-53r5_v1.0.yaml` (tgt, 42 rows) | 42 | **no-loader** — NIST SP 800-53 Rev.5 is a real published standard (US federal controls catalogue, 1 000+ controls); no loader exists | Build `NistSp80053r5Loader` if US-federal compliance is in scope; otherwise scope out. Control texts not freely licensable without attribution — load control numbers and references only. |

**YAML Category A total affected mappings: 330 across 9 files.**

### A.2 CSV public — 1 unresolved framework

| Framework ID | Files referencing it | Affected mappings | Classification | Recommended action |
|---|---|---|---|---|
| `NIST-CSF` | `nist_csf_iso27001_v1.csv` (src, 25 rows) | 25 | **alias** — `NIST-CSF` is used as a bare alias for NIST CSF 2.0. The loaded framework ID is `NIST-CSF-2.0`. Sample IDs (`GV.OC-01`, `GV.RM-01`) match CSF 2.0 subcategory format | Rename framework column in CSV from `NIST-CSF` to `NIST-CSF-2.0`; if the file actually targets CSF 1.1 subcategories (different ID pattern), treat as per YAML `nist-csf-1.1` above. |

---

## Category B — RequirementId-Level Dangling Within Resolved Framework Pairs

Both frameworks load successfully, but the requirement IDs used in the mapping file do not match any ID in the catalog. Sorted by total dangling count descending.

### Root-cause clusters identified

| Cluster label | Symptom | Affected files / frameworks |
|---|---|---|
| **BSI-Grundschutz module-ID scheme** | Mapping uses short module IDs (`ISMS.1`, `ORP.1`, `DER.1`) — these are BSI IT-Grundschutz *building-block* codes, not the `APP.X.Y.Z.AX`-style requirement IDs loaded by the catalog loader | BSI_GRUNDSCHUTZ source/target in 6 files |
| **NIS2 Art. 21 sub-clause scheme** | Two competing ID formats in use: `21.2.a`…`21.2.j` (raw directive notation) vs `NIS2-ART21-A`…`NIS2-ART21-J` (catalog internal notation) — only one is registered in the NIS2 loader | NIS2 source/target in 10+ files |
| **NIS2UMSUCG § scheme** | Mapping uses German statutory paragraph notation (`§1`, `§28`, `§ 30`) — catalog uses a different internal code | NIS2UMSUCG source in 4 files |
| **DORA Art. / DORA-N scheme** | Two formats: `Art.1`/`Art. 12` (raw regulation notation) vs `DORA-5.4`/`DORA-12` (catalog internal) — files use different conventions | DORA source/target in 7 files |
| **BSI-C5 ↔ ISO27017 subset mismatch** | ISO 27017 catalog loads only cloud-specific clauses; mapping files reference ISO 27002 control numbers in `5.x`/`6.x` range that overlap but are not exclusively cloud | ISO27017 source/target in 2 files |
| **KRITIS § scheme** | KRITIS-BSIG requirement IDs use `§ 5` / `§ 6` notation — catalog uses different IDs | KRITIS source in 2 files |
| **TISAX empty/null target IDs** | TISAX files have blank target IDs in mapping rows (empty string `''`) — data quality issue in the mapping source | TISAX target in 3 files |
| **CSV framework-prefix scheme** | CSV files prefix the requirementId with `FRAMEWORK_CODE:id` (e.g. `DORA:Art.5`, `ISO27001:4.1`) but catalog IDs are stored without prefix | DORA, ISO27001, ISO-22301, ISO27701, BDSG, EU-AI-ACT, CIS-CONTROLS in CSV files |
| **v1.0 superseded by v2.0** | `eu-ai-act_to_iso42001_v1.0.yaml` and `iso42001_to_eu-ai-act_v1.0.yaml` have 100 % dangling; the v2.0 pair has 0 dangling — v1.0 used non-canonical IDs since fixed in v2.0 | 2 files |
| **Official-CRT multi-range IDs** | `bsi-c5-2020_to_iso27001-2022_official-crt_v1.yaml` contains compound ISO 27001 targets like `4.1 - 10.2`, `6.2` that do not match leaf clause IDs | 1 file |

### Full table (resolved pairs with any dangling, sorted by total dangling)

| File | Src framework | Tgt framework | Mappings | Dangling src | Dangling tgt | Total | Root cause cluster |
|---|---|---|---|---|---|---|---|
| `bsi-it-grundschutz_to_iso27001-2022_v1.0.yaml` | BSI_GRUNDSCHUTZ | ISO27001 | 182 | 182 | 0 | **182** | BSI-Grundschutz module-ID scheme |
| `iso27001-2022_to_bsi-it-grundschutz_v1.0.yaml` | ISO27001 | BSI_GRUNDSCHUTZ | 182 | 0 | 182 | **182** | BSI-Grundschutz module-ID scheme |
| `bsi-c5-2026_to_iso27017_v1.0.yaml` | BSI-C5-2026 | ISO27017 | 168 | 0 | 120 | **120** | BSI-C5 ↔ ISO27017 subset mismatch |
| `bsi-it-grundschutz_to_nis2-art21_v1.0.yaml` | BSI_GRUNDSCHUTZ | NIS2 | 52 | 52 | 52 | **104** | BSI module-ID scheme + NIS2 Art.21 sub-clause scheme |
| `nis2-art21_to_bsi-it-grundschutz_v1.0.yaml` | NIS2 | BSI_GRUNDSCHUTZ | 52 | 52 | 52 | **104** | NIS2 Art.21 sub-clause scheme + BSI module-ID scheme |
| `iso27017_to_bsi-c5-2026_v1.0.yaml` | ISO27017 | BSI-C5-2026 | 121 | 90 | 0 | **90** | BSI-C5 ↔ ISO27017 subset mismatch |
| `iso27001-2022_to_bsi-grundschutz_official-crt_v1.yaml` | ISO27001 | BSI_GRUNDSCHUTZ | 119 | 26 | 60 | **86** | BSI module-ID scheme + Official-CRT multi-range IDs (src) |
| `bsi-c5-2026_to_nis2-art21_v1.0.yaml` | BSI-C5-2026 | NIS2 | 83 | 0 | 83 | **83** | NIS2 Art.21 sub-clause scheme |
| `nis2-umsucg_to_dora_v1.0.yaml` | NIS2UMSUCG | DORA | 60 | 60 | 21 | **81** | NIS2UMSUCG § scheme + DORA Art./DORA-N scheme |
| `tisax_to_iso27001-2022_v1.0.yaml` | TISAX | ISO27001 | 80 | 0 | 80 | **80** | TISAX empty/null target IDs |
| `nis2-art21_to_bsi-c5-2026_v1.0.yaml` | NIS2 | BSI-C5-2026 | 72 | 72 | 0 | **72** | NIS2 Art.21 sub-clause scheme |
| `dora_to_nis2-umsucg_v1.0.yaml` | DORA | NIS2UMSUCG | 49 | 14 | 49 | **63** | DORA Art./DORA-N scheme + NIS2UMSUCG § scheme |
| `nis2-art21_to_iso27001-2022_v1.0.yaml` | NIS2 | ISO27001 | 55 | 55 | 3 | **58** | NIS2 Art.21 sub-clause scheme |
| `nis2-umsucg_to_nis2_v1.0.yaml` | NIS2UMSUCG | NIS2 | 51 | 51 | 0 | **51** | NIS2UMSUCG § scheme |
| `nis2_to_nis2-umsucg_v1.0.yaml` | NIS2 | NIS2UMSUCG | 51 | 0 | 51 | **51** | NIS2UMSUCG § scheme |
| `tisax_to_bsi-grundschutz_v1.0.yaml` | TISAX | BSI_GRUNDSCHUTZ | 42 | 0 | 42 | **42** | TISAX empty/null target IDs |
| `bsi-it-grundschutz_to_bsi-c5-2020_v1.0.yaml` | BSI_GRUNDSCHUTZ | BSI-C5 | 38 | 37 | 1 | **38** | BSI-Grundschutz module-ID scheme |
| `dora_to_nis2-art21_v1.0.yaml` | DORA | NIS2 | 37 | 1 | 37 | **38** | NIS2 Art.21 sub-clause scheme + DORA Art./DORA-N scheme (`Art.12`) |
| `nis2-art21_to_dora_v1.0.yaml` | NIS2 | DORA | 37 | 37 | 1 | **38** | NIS2 Art.21 sub-clause scheme + DORA Art./DORA-N scheme |
| `bsi-c5-2020_to_bsi-it-grundschutz_v1.0.yaml` | BSI-C5 | BSI_GRUNDSCHUTZ | 37 | 0 | 36 | **36** | BSI-Grundschutz module-ID scheme |
| `cra_to_nis2-art21_v1.0.yaml` | EU-CRA | NIS2 | 30 | 6 | 30 | **36** | NIS2 Art.21 sub-clause scheme |
| `nis2_to_eu-ai-act_v1.0.yaml` | NIS2 | EU-AI-ACT | 29 | 28 | 0 | **28** | NIS2 Art.21 sub-clause scheme |
| `gdpr_to_iso27018_v1.0.yaml` | GDPR | ISO27018 | 60 | 0 | 27 | **27** | ISO27018 subset (cloud-specific clauses only loaded; mapping references broader control numbers) |
| `nis2-art21_to_cra_v1.0.yaml` | NIS2 | EU-CRA | 21 | 21 | 6 | **27** | NIS2 Art.21 sub-clause scheme |
| `eu-ai-act_to_nis2_v1.0.yaml` | EU-AI-ACT | NIS2 | 27 | 0 | 26 | **26** | NIS2 Art.21 sub-clause scheme |
| `eu-ai-act_to_iso42001_v1.0.yaml` | EU-AI-ACT | ISO42001 | 10 | 10 | 10 | **20** | v1.0 superseded by v2.0; non-canonical IDs (`Art. 9`, `Annex A.7.2`) |
| `iso42001_to_eu-ai-act_v1.0.yaml` | ISO42001 | EU-AI-ACT | 10 | 10 | 10 | **20** | v1.0 superseded by v2.0; non-canonical IDs |
| `iso27018_to_gdpr_v1.0.yaml` | ISO27018 | GDPR | 58 | 18 | 0 | **18** | ISO27018 subset mismatch (dangling src IDs: `7.1.1`, `8.2.1`, `8.3.2` — not in cloud-specific loader) |
| `bsi-c5-2020_to_iso27001-2022_official-crt_v1.yaml` | BSI-C5 | ISO27001 | 205 | 0 | 17 | **17** | Official-CRT multi-range ISO 27001 target IDs (`4.1 - 10.2`, `6.2`, `4.3`) |
| `kritis-dachgesetz_to_nis2-umsucg_v1.0.yaml` | KRITIS | NIS2UMSUCG | 8 | 8 | 8 | **16** | KRITIS § scheme + NIS2UMSUCG § scheme |
| `nis2-umsucg_to_kritis-dachgesetz_v1.0.yaml` | NIS2UMSUCG | KRITIS | 8 | 8 | 8 | **16** | NIS2UMSUCG § scheme + KRITIS § scheme |
| `iso27001-2022_to_nis2-art21_v1.0.yaml` | ISO27001 | NIS2 | 12 | 0 | 12 | **12** | NIS2 Art.21 sub-clause scheme |
| `dora_to_nis2_lex-specialis_v2.0.yaml` | DORA | NIS2 | 111 | 11 | 0 | **11** | DORA Art./DORA-N scheme (`DORA-5.4`, `DORA-6.5`, `DORA-7`) |
| `bsi-c5-2026_to_bsi-c5-2020_v1.0.yaml` | BSI-C5-2026 | BSI-C5 | 47 | 0 | 10 | **10** | BSI-C5 cross-version IDs not present in older catalog snapshot |
| `bsi-c5-2026_to_iso27001-2022_v1.0.yaml` | BSI-C5-2026 | ISO27001 | 163 | 0 | 9 | **9** | Empty/blank ISO 27001 target IDs in mapping rows |
| `dora_to_iso27001-2022_v1.0.yaml` | DORA | ISO27001 | 49 | 6 | 0 | **6** | DORA Art./DORA-N scheme (`DORA-7`, `DORA-12`) |
| `nis2_to_dora_lex-specialis_v2.0.yaml` | NIS2 | DORA | 57 | 0 | 5 | **5** | DORA Art./DORA-N scheme (`DORA-5.4`, `DORA-12`, `DORA-7`) |
| `tisax_to_iso27017_v1.0.yaml` | TISAX | ISO27017 | 4 | 0 | 4 | **4** | TISAX empty/null target IDs |
| `bsi-grundschutz-2024_to_tisax_v1.0.yaml` | BSI_GRUNDSCHUTZ | TISAX | 15 | 0 | 2 | **2** | Single TISAX target ID `2.2.1` not in catalog |
| `iso27001-2022_to_dora_v1.0.yaml` | ISO27001 | DORA | 32 | 0 | 2 | **2** | DORA Art./DORA-N scheme (`DORA-7`, `DORA-12`) |
| `iso27001-2022_to_bsi-grundschutz-2024_v2.0.yaml` | ISO27001 | BSI_GRUNDSCHUTZ | 39 | 0 | 1 | **1** | Single BSI target ID `CON.1.A3` not in catalog |

**CSV resolved pairs with dangling (selected):**

| File | Likely src/tgt pair | Mappings | Dangling src | Dangling tgt | Total | Root cause |
|---|---|---|---|---|---|---|
| `dora_iso27001_v1.csv` | DORA / ISO27001 | 35 | 35 | 2 | **37** | CSV framework-prefix scheme (`DORA:Art.5`) |
| `c5_iso27001_v1.csv` | BSI-C5-2026 / ISO27001 | 32 | 32 | 0 | **32** | CSV prefix scheme (`BSI-C5-2026:C5-2026-ORP-1.1`) |
| `iso22301_iso27001_v1.csv` | ISO-22301 / ISO27001 | 20 | 20 | 11 | **31** | CSV prefix scheme (`ISO-22301:4.1`, `ISO27001:4.1`) |
| `dora_iso22301_v1.csv` | DORA / ISO-22301 | 14 | 14 | 14 | **28** | CSV prefix scheme |
| `nist_csf_iso27001_v1.csv` | NIST-CSF / ISO27001 | 25 | 25 | 3 | **28** | Unresolved NIST-CSF alias (Cat A) + CSV prefix scheme |
| `iso27701_iso27001_v1.csv` | ISO27701 / ISO27001 | 30 | 15 | 11 | **26** | CSV prefix scheme |
| `cis_v8_iso27001_v1.csv` | CIS-CONTROLS / ISO27001 | 20 | 20 | 0 | **20** | CSV prefix scheme (`CIS-CONTROLS:1`) |
| `eu_ai_act_iso27001_v1.csv` | EU-AI-ACT / ISO27001 | 14 | 14 | 4 | **18** | CSV prefix scheme (`EU-AI-ACT:AIACT-1`) |
| `dora_iso27005_v1.csv` | DORA / ISO27005 | 14 | 14 | 0 | **14** | CSV prefix scheme |
| `bdsg_gdpr_v1.csv` | BDSG / GDPR | 13 | 13 | 0 | **13** | CSV prefix scheme (`BDSG:§1`) |
| `nis2_iso22301_v1.csv` | NIS2 / ISO-22301 | 10 | 0 | 10 | **10** | CSV prefix scheme (ISO-22301 target) |

> **Note:** The CSV public files all use a `FRAMEWORK_CODE:requirementId` prefixed scheme (e.g. `DORA:Art.5`). The catalog stores IDs *without* prefix. This is a **systemic schema mismatch** affecting all CSV files — fix the importer or strip the prefix in the audit command. This single fix would resolve ~200 of the 206 CSV dangling source-IDs.

---

## Top-10 Worst Files by Total Dangling

| Rank | File | Type | Mappings | Dangling src | Dangling tgt | **Total** |
|---|---|---|---|---|---|---|
| 1 | `bsi-it-grundschutz_to_iso27001-2022_v1.0.yaml` | YAML | 182 | 182 | 0 | **182** |
| 2 | `iso27001-2022_to_bsi-it-grundschutz_v1.0.yaml` | YAML | 182 | 0 | 182 | **182** |
| 3 | `bsi-c5-2026_to_iso27017_v1.0.yaml` | YAML | 168 | 0 | 120 | **120** |
| 4 | `bsi-it-grundschutz_to_nis2-art21_v1.0.yaml` | YAML | 52 | 52 | 52 | **104** |
| 5 | `nis2-art21_to_bsi-it-grundschutz_v1.0.yaml` | YAML | 52 | 52 | 52 | **104** |
| 6 | `iso27017_to_bsi-c5-2026_v1.0.yaml` | YAML | 121 | 90 | 0 | **90** |
| 7 | `iso27001-2022_to_bsi-grundschutz_official-crt_v1.yaml` | YAML | 119 | 26 | 60 | **86** |
| 8 | `bsi-c5-2026_to_nis2-art21_v1.0.yaml` | YAML | 83 | 0 | 83 | **83** |
| 9 | `nis2-umsucg_to_dora_v1.0.yaml` | YAML | 60 | 60 | 21 | **81** |
| 10 | `tisax_to_iso27001-2022_v1.0.yaml` | YAML | 80 | 0 | 80 | **80** |

---

## Decision Quick-Reference

### Actionable wins (low effort, high impact)

1. **Fix the CSV prefix scheme** (one importer change): strips `FRAMEWORK_CODE:` prefix on load → fixes ~200 dangling source-IDs across all 19 CSV files. Effort: XS.
2. **Alias `NIST-CSF` → `NIST-CSF-2.0`** in the CSV importer or add it as a secondary framework code: fixes `nist_csf_iso27001_v1.csv` 25 dangling source-IDs. Effort: XS.
3. **Deprecate BAIT files** (`bafin-bait_to_dora_v1.0.yaml`, `dora_to_bafin-bait_v1.0.yaml`): BAIT is withdrawn, DORA is loaded. Add tombstone comment and remove from active scanning. Effort: XS.
4. **Delete or deprecate v1.0 AI Act ↔ ISO 42001 files**: v2.0 replacements exist with 0 dangling. Effort: XS.
5. **Canonicalise DORA IDs** in mapping files to loader scheme (`DORA-7` not `Art.7`, `DORA-12` not `Art. 12`): fixes dangling in 7 files (≈55 rows). Need to confirm canonical scheme used by `DoraLoader`. Effort: S.
6. **Canonicalise NIS2 Art. 21 IDs** in mapping files (`NIS2-ART21-A` vs `21.2.a`): determine which is the loader scheme, rewrite the other group. Affects ≈10 files (≈450 dangling rows). Effort: M.

### Decisions required

| Decision | Options | Notes |
|---|---|---|
| **BSI-Grundschutz ID scheme** | (a) update loader to accept module-code IDs; (b) rewrite 6 mapping files to use requirement-level IDs | Module-IDs (`ISMS.1`) are building-block names, not granular requirements — option (a) blurs granularity. Recommend option (b) for precision. |
| **NIS2UMSUCG § scheme** | (a) update loader to accept `§1`/`§ 30` notation; (b) rewrite 4 mapping files | § notation is the statutory text; internal IDs may differ. Verify what `NIS2UMSUCGLoader` actually registers. |
| **KRITIS § scheme** | (a) update loader; (b) rewrite 2 files | Same situation as NIS2UMSUCG. |
| **ISO27017 / ISO27018 subset scope** | (a) extend loaders to include all ISO 27002 control numbers as pass-through; (b) restrict mapping files to only cloud-specific controls | Both standards extend ISO 27002; loaders currently only include cloud-specific additions. |
| **EUCS loader** | (a) build loader (in scope for EU cloud customers); (b) scope out | 4 files, 166 affected mappings. ENISA EUCS v1.0 published. |
| **NIST SP 800-53 Rev. 5 loader** | (a) build loader; (b) scope out as US-federal | 1 file, 42 mappings. Not core DACH/EU scope. |
| **IEC/ISA 62443 loader** | (a) build loader; (b) scope out as OT/ICS niche | 1 file, 25 mappings. Relevant for KRITIS-OT customers. |
| **NIST CSF 1.1 vs 2.0** | (a) add CSF 1.1 loader; (b) migrate 33 TISAX mappings to CSF 2.0; (c) deprecate | NIST published official 1.1→2.0 crosswalk. Option (b) recommended. |
| **TISAX empty target IDs** | Fix data quality in 3 TISAX mapping files (null/empty target ID strings) | ≈126 rows affected across tisax→iso27001, tisax→bsi-grundschutz, tisax→iso27017. Data entry defect. |
