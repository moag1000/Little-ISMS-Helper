# BSI C5:2026 Cloud Computing Compliance Criteria Catalogue (Machine-Readable)

## Source

Bundesamt fuer Sicherheit in der Informationstechnik (BSI). Cloud Computing
Compliance Criteria Catalogue (C5:2026). Final version published 2026-03-26.

- Catalogue page: https://www.bsi.bund.de/EN/Themen/Unternehmen-und-Organisationen/Informationen-und-Empfehlungen/Empfehlungen-nach-Angriffszielen/Cloud-Computing/Kriterienkatalog-C5/C5_2025/C5_2025_node.html
- Machine-readable ZIP (English): https://www.bsi.bund.de/SharedDocs/Downloads/DE/BSI/CloudComputing/Anforderungskatalog/2026/C5_2026_machine_readable_en.zip

## License

**Creative Commons Attribution-NoDerivatives 4.0 International (CC BY-ND 4.0)**.

- Attribution: BSI, Cloud Computing Compliance Criteria Catalogue C5:2026, March 2026.
- These files are distributed verbatim. They MUST NOT be modified.
- Mappings, translations, derived data, and tooling around these files belong
  to the Little ISMS Helper project under its own licensing terms (AGPL-3.0).
- See https://creativecommons.org/licenses/by-nd/4.0/ for full license text.

## Structure

18 YAML files, one per subject area. Roughly 168 criteria total split across:

| Area | Description | Criteria |
|------|-------------|----------|
| AM   | Asset Management | 12 |
| BCM  | Business Continuity Management | 4 |
| COM  | Compliance | 4 |
| COS  | Communication Security | 8 |
| CRY  | Cryptography (was KRY in C5:2020) | 19 |
| DEV  | Development | 15 |
| GC   | Generic Client information | 6 |
| HR   | Human Resources | 8 |
| IAM  | Identity and Access Management (was IDM in C5:2020) | 9 |
| INQ  | Inquiries from government agencies | 4 |
| OIS  | Organisation of Information Security | 10 |
| OPS  | Operations | 35 |
| PI   | Portability and Interoperability | 3 |
| PS   | Physical Security | 8 |
| PSS  | Product Safety and Security | 12 |
| SIM  | Security Incident Management | 6 |
| SP   | Security Policies | 3 |
| SSO  | Sub-Service Organisations | 8 |

## Why bundle the catalogue

The Little ISMS Helper uses these IDs as the source of truth for cross-framework
mappings (`fixtures/library/mappings/`). Bundling avoids version drift between
mapping fixtures and the official catalogue, and lets users review the full
criterion text inline.

## Updates

The German source PDF and Excel are also published alongside the YAML. If BSI
issues an update, replace the contents of this directory verbatim and bump the
mapping fixtures' `effective_from`.
