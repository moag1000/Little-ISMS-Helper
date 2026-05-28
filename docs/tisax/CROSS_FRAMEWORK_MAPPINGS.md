# VDA-ISA 6 Cross-Framework Mappings

Extracted from ENX VDA-ISA 6 workbook column "Reference to other standards" /
"Verweisung auf andere Normen" (col P, Information Security sheet).

## Extracted Mapping Files

| Framework | File | Controls | Anchors |
|---|---|---|---|
| ISA/IEC 62443 | `tisax-vda-isa-6_to_iec-isa-62443_v1.0.yaml` | 25 | 47 |
| NIST CSF v1.1 | `tisax-vda-isa-6_to_nist-csf-1.1_v1.0.yaml` | 33 | 110 |
| ISO/IEC 27017 | `tisax-vda-isa-6_to_iso27017_v1.0.yaml` | 4 | 5 |
| ISO/IEC 27002 | `tisax-vda-isa-6_to_iso27002_v1.0.yaml` | 1 | 2 |
| ISO/IEC 27001:2022 | `tisax-vda-isa-6_to_iso27001-2022_v1.0.yaml` | 41 | 70 |

All files in `fixtures/library/mappings/`.

## Anchor Formats

- **ISA/IEC 62443**: `part.chapter.section` (e.g. `3.1.7`, `7.1.9`)
- **NIST CSF 1.1**: `FUNCTION.CATEGORY-N` (e.g. `ID.AM-1`, `PR.AC-3`)
- **ISO 27017**: `CLD.x.y.z` (e.g. `CLD.6.3.1`, `CLD.8.1.5`)
- **ISO 27002**: Annex A notation (e.g. `A.7.2.1`)
- **ISO 27001:2022**: Annex A or clause (e.g. `A.5.1`, `4`)

## ENX Licensing Note

Only control-ID to framework-anchor pairs extracted (factual data, not copyrightable).
See `docs/tisax/ENX_VDA_ISA_LICENSING_ANALYSIS.md`.

## Technical Note: Non-Breaking Space

ENX workbook uses U+00A0 (NBSP, 0xC2 0xA0) between "ISO" and "27001" throughout.
Extraction script normalises to ASCII space. Without this, zero matches are found.

## DE/EN Consistency

German and English workbooks produce identical cross-framework anchor sets (verified).

## Gaps: Frameworks NOT in ENX ISA 6 Workbook

| Framework | Status | Recommended Path |
|---|---|---|
| BSI IT-Grundschutz | Inverse mapping exists | Transitive: VDA-ISA -> ISO 27001 -> BSI |
| BSI C5:2026 | Not in workbook | Transitive via bsi-c5-2026_to_iso27001-2022_v1.0.yaml |
| NIST SP 800-53 | Not in workbook (only NIST CSF 1.1) | Derive via NIST CSF -> SP 800-53 bridge |
| NIS2 Art. 21 | Not in workbook | Transitive via ISO 27001 bridge |
| TISAX AL cross-refs | AL cols L-N are binary flags, not anchors | N/A |
| VDA-ISA v5 -> v6 | ISA 5 workbook not downloaded | portal.enx.com/isa5-de.xlsx |

## Unexpected Discoveries

- **ISO/IEC 27002** referenced for ISA 2.1.3 only (all others use ISO 27001).
  Control 2.1.3 has no Annex A equivalent; authors cited ISO 27002 directly.
- **ISA/IEC 62443** covers 25/80 VDA-ISA controls (31%) reflecting significant
  OT/ICS security coverage in automotive manufacturing.
- **BSI IT-Grundschutz** absent despite German automotive context.

## Inter-Mapping Consistency

Hand-crafted ISO 27001 mapping (15 entries) is simplified vs workbook (41 controls).
No conflicting category claims found between ISO 27001 and BSI bridges.

## How to Re-run

```bash
php scripts/import/extract_vda_isa_all_mappings.php \
    tests/Fixtures/vda_isa_6_de_official.xlsx \
    fixtures/library/mappings/
```

## Related Files

- `scripts/import/extract_vda_isa_all_mappings.php` — extraction script
- `tests/Service/Library/VdaIsaCrossFrameworkMappingTest.php` — validation tests
- `docs/tisax/ENX_VDA_ISA_LICENSING_ANALYSIS.md` — licensing analysis
