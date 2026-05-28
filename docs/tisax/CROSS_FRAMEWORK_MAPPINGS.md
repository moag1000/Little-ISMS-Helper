# VDA-ISA 6 Cross-Framework Mappings

Extracted from ENX VDA-ISA 6 workbook.
Two reference columns contain anchor-style control IDs:

- **Col P** — "Verweisung auf andere Normen" / "Reference to other standards"
  (IS sheet only) -> ISO 27001, ISO 27002, ISO 27017, ISA/IEC 62443, NIST CSF 1.1
- **Col Q** — "Verweisung auf Implementierungsanleitung" / "Reference to Implementation Guidance"
  (IS sheet only) -> BSI IT-Grundschutz-Kompendium, NIST SP 800-53r5

PP (Prototypenschutz) and DP (Datenschutz) sheets have these column headers
but **no data rows populated** in the downloaded workbook.

## Extracted Mapping Files

| Source col | Framework | File | Controls | Anchors |
|---|---|---|---|---|
| Col P | ISA/IEC 62443 | `tisax-vda-isa-6_to_iec-isa-62443_v1.0.yaml` | 25 | 47 |
| Col P | NIST CSF v1.1 | `tisax-vda-isa-6_to_nist-csf-1.1_v1.0.yaml` | 33 | 110 |
| Col P | ISO/IEC 27017 | `tisax-vda-isa-6_to_iso27017_v1.0.yaml` | 4 | 5 |
| Col P | ISO/IEC 27002 | `tisax-vda-isa-6_to_iso27002_v1.0.yaml` | 1 | 2 |
| Col P | ISO/IEC 27001:2022 | `tisax-vda-isa-6_to_iso27001-2022_v1.0.yaml` | 41 | 70 |
| Col Q | BSI IT-Grundschutz | `tisax-vda-isa-6_to_bsi-grundschutz_v1.0.yaml` | 42 | 111 |
| Col Q | NIST SP 800-53r5 | `tisax-vda-isa-6_to_nist-sp800-53r5_v1.0.yaml` | 42 | 329 |

All files in `fixtures/library/mappings/`.

## Skipped (ENX-licensed content — free-text, not anchor-IDs)

| Column | Header | Sheet | Reason |
|---|---|---|---|
| IS col E | Beschreibung der Umsetzung | IS | Full implementation description prose |
| IS col H | Kontrollfrage | IS | Audit question text |
| IS col I | Ziel | IS | Objective/goal description |
| IS col J/K | Anforderungen (muss/sollte) | IS | Requirement narrative |
| IS col W | Weitere Informationen | IS | Free-text guidance notes |
| IS col X/Y/Z | Hilfestellung: Normal/Hoch/Sehr hoch | IS | Implementation examples per protection level |
| IS col AA | Mogliche Fragestellungen | IS | Example audit questions |
| IS col AB | Mogliche Nachweise | IS | Example evidence suggestions |
| IS col Q (BSI 200-2 section) | BSI-Standard 200-2: N.N refs | IS | Numeric format identical to VDA-ISA IDs, not extractable |
| PP col H-L | Kontrollfrage/Ziel/Anforderungen | PP | Same as IS equivalents |
| DP col J | Anforderungen (muss) | DP | GDPR Art. refs embedded in requirement prose |

Customers who need the implementation guidance text should consult their
own ENX-licensed workbook copy from `portal.enx.com`.

## Anchor Formats

- **ISA/IEC 62443**: `part.chapter.section` (e.g. `3.1.7`, `7.1.9`)
- **NIST CSF 1.1**: `FUNCTION.CATEGORY-N` (e.g. `ID.AM-1`, `PR.AC-3`)
- **ISO 27017**: `CLD.x.y.z` (e.g. `CLD.6.3.1`, `CLD.8.1.5`)
- **ISO 27002**: Annex A notation (e.g. `A.7.2.1`)
- **ISO 27001:2022**: Annex A or clause (e.g. `A.5.1`, `4`)
- **BSI IT-Grundschutz**: Building block ID `FAMILY.N[.N]` (e.g. `ISMS.1`, `ORP.4`, `NET.1.1`, `SYS.3.2.1`)
- **NIST SP 800-53r5**: Control ID `XX-N[(N)]` (e.g. `AC-1`, `IR-4`, `CM-8`, `PE-13(2)`)

## ENX Licensing Note

Only control-ID to framework-anchor pairs extracted (factual data, not copyrightable).
See `docs/tisax/ENX_VDA_ISA_LICENSING_ANALYSIS.md`.

## Technical Note: Non-Breaking Space

ENX workbook uses U+00A0 (NBSP, 0xC2 0xA0) between "ISO" and "27001" throughout.
Extraction script normalises to ASCII space. Without this, zero matches are found.

## DE/EN Consistency

German and English workbooks produce identical cross-framework anchor sets (verified for col P).
Col Q has not been independently verified against the EN workbook but is expected
to be identical given col P parity.

## Gaps: Frameworks NOT in ENX ISA 6 Workbook

| Framework | Status | Recommended Path |
|---|---|---|
| BSI C5:2026 | Not in workbook | Transitive via bsi-c5-2026_to_iso27001-2022_v1.0.yaml |
| NIS2 Art. 21 | Not in workbook | Transitive via ISO 27001 bridge |
| TISAX AL cross-refs | AL cols L-N are binary flags, not anchors | N/A |
| VDA-ISA v5 -> v6 | ISA 5 workbook not downloaded | portal.enx.com/isa5-de.xlsx |
| GDPR Art. refs (DP sheet) | Embedded in requirement narrative col J, not anchor column | Manual extraction or transitive via ISO 27701 |

## Unexpected Discoveries

- **BSI IT-Grundschutz** IS present in col Q (guidance column) with 111 building
  block anchors across 42 controls. Corrects the earlier note that BSI GS was absent.
  The inverse mapping `bsi-grundschutz-2024_to_tisax-vda-isa-6_v1.0.yaml` remains
  a hand-crafted complement.
- **NIST SP 800-53r5** (329 anchors, 42 controls) is present alongside NIST CSF 1.1
  (110 anchors, 33 controls). These are complementary: CSF gives function-category
  mapping; SP 800-53r5 gives granular individual-control mapping.
- **BSI-Standard 200-2** numeric section refs (e.g. `3.2`, `10.1.4`) also appear in
  col Q but are NOT extracted — format is ambiguous with VDA-ISA control IDs.
- **ISO/IEC 27002** referenced for ISA 2.1.3 only (all others use ISO 27001).
  Control 2.1.3 has no Annex A equivalent; authors cited ISO 27002 directly.
- **ISA/IEC 62443** covers 25/80 VDA-ISA controls (31%) reflecting significant
  OT/ICS security coverage in automotive manufacturing.
- **PP and DP sheets** both have "Verweisung auf andere Normen" column headers
  but zero data rows populated in the downloaded workbook.
- **DP sheet** GDPR article references (`Art. 37 DSGVO`, `Art. 30 Abs. 1`) appear
  only in free-text "Anforderungen" column J prose, not in a dedicated anchor column.

## Per-Sheet Anchor Inventory

| Sheet | Anchor columns (with data) | Anchor columns (empty) | Free-text columns |
|---|---|---|---|
| Informationssicherheit (IS) | Col P (Verweisung auf andere Normen), Col Q (Implementierungsanleitung) | none | E, H, I, J, K, W, X, Y, Z, AA, AB |
| Prototypenschutz (PP) | none | Col N (Verweisung auf andere Normen) | H, I, J, K, L |
| Datenschutz (DP) | none | Col L (Verweisung auf andere Normen) | J, E, F, G, I |

## Inter-Mapping Consistency

Hand-crafted ISO 27001 mapping (15 entries) is simplified vs workbook col P (41 controls).
No conflicting category claims found between ISO 27001 and BSI bridges.

## How to Re-run

```bash
# Col P extraction (ISO 27001, ISA/IEC 62443, NIST CSF 1.1, ISO 27017, ISO 27002)
php scripts/import/extract_vda_isa_all_mappings.php \
    tests/Fixtures/vda_isa_6_de_official.xlsx \
    fixtures/library/mappings/

# Col Q extraction (BSI IT-Grundschutz + NIST SP800-53r5)
php scripts/import/extract_vda_isa_col_q_mappings.php \
    tests/Fixtures/vda_isa_6_de_official.xlsx \
    fixtures/library/mappings/
```

## Related Files

- `scripts/import/extract_vda_isa_all_mappings.php` — col P extraction script
- `scripts/import/extract_vda_isa_col_q_mappings.php` — col Q extraction script
- `tests/Service/Library/VdaIsaCrossFrameworkMappingTest.php` — validation tests
- `docs/tisax/ENX_VDA_ISA_LICENSING_ANALYSIS.md` — licensing analysis
- `docs/tisax/VDA_ISA_6_COLUMN_INVENTORY.md` — full workbook column inventory
