# NIST Cybersecurity Framework (CSF) 2.0 Subcategories

## Source

National Institute of Standards and Technology (NIST). The NIST Cybersecurity
Framework (CSF) 2.0. Final version published 2024-02-26 (NIST.CSWP.29).

- Framework page: https://www.nist.gov/cyberframework
- Reference Tool: https://csrc.nist.gov/projects/cybersecurity-framework/filters
- PDF: https://nvlpubs.nist.gov/nistpubs/CSWP/NIST.CSWP.29.pdf

## License

NIST publications are works of the U.S. Government and are in the **public
domain** in the United States. Copyright protection is not available under
17 U.S.C. § 105. International users may apply local rules.

## Structure

106 active subcategories across 6 functions, formatted as
`{Function}.{Category}-{Subcategory#}`:

| Function | # |
|----------|---|
| GV (Govern) | 31 |
| ID (Identify) | 21 |
| PR (Protect) | 22 |
| DE (Detect) | 11 |
| RS (Respond) | 13 |
| RC (Recover) | 8 |

The Excel/JSON download from the CSF 2.0 Reference Tool also lists 79
withdrawn subcategories (carryover IDs from CSF 1.1 used for migration
documentation). They are excluded from this inventory.

## Why bundle this catalogue

`fixtures/library/mappings/iso27001-2022_to_nist-csf-2-0_v1.0.yaml` and any
other CSF-bound mappings reference these IDs as the source of truth.
Bundling avoids drift if NIST issues an update.
