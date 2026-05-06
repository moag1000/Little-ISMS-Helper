# BSI C5:2020 Cloud Computing Compliance Criteria Catalogue

## Source

Bundesamt fuer Sicherheit in der Informationstechnik (BSI). Cloud Computing
Compliance Criteria Catalogue (C5:2020). Final version, editable Excel
distribution (`C5_2020_Editierbar.xlsx`, version 6).

- Catalogue page: https://www.bsi.bund.de/DE/Themen/Unternehmen-und-Organisationen/Informationen-und-Empfehlungen/Empfehlungen-nach-Angriffszielen/Cloud-Computing/Kriterienkatalog-C5/

## License

The C5:2020 catalogue is published by BSI for general use; the editable
table is freely downloadable. Verbatim distribution with attribution is the
expected use case for the editable version.

## Structure

121 criteria across 17 subject areas:

| Area | Criteria |
|------|----------|
| AM (Asset Management) | 6 |
| BCM (Business Continuity) | 4 |
| COM (Compliance) | 4 |
| COS (Communication Security) | 8 |
| CRY (Cryptography) | 4 |
| DEV (Development) | 10 |
| HR (Human Resources) | 6 |
| IDM (Identity and Access Management) | 9 |
| INQ (Investigative Requests) | 4 |
| OIS (Organisation of Information Security) | 7 |
| OPS (Operations) | 24 |
| PI (Portability and Interoperability) | 3 |
| PS (Physical Security) | 7 |
| PSS (Product Safety) | 12 |
| SIM (Security Incident Management) | 5 |
| SP (Security Policies) | 3 |
| SSO (Sub-Service Organisations) | 5 |

## Why bundle the catalogue

`fixtures/library/mappings/*.yaml` reference these IDs as the source of
truth. Bundling avoids version drift between mappings and the official
catalogue.
