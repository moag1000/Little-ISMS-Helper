# TISAX / VDA-ISA — Reference Document

**TISAX®** (Trusted Information Security Assessment Exchange) is the automotive
industry's assessment and exchange mechanism, governed by **ENX Association** on
behalf of the **VDA**. The underlying catalogue is the **VDA Information Security
Assessment (VDA-ISA)**.

TISAX is not a certification in the ISO sense: an accredited audit provider
performs an assessment, and the **assessment result** (label) is exchanged over
the ENX portal with partners who require it. Talk about "labels", not
"certificates", when advising users.

## Two catalogue generations run in parallel

This is the single most important fact for advice today:

| | VDA-ISA 6 | VDA-ISA 2027 |
|---|---|---|
| Framework code in this app | `TISAX` | `TISAX-2027` |
| Catalogue version | `6.0` | `2027` |
| Requirements shipped | 80 | 78 |
| Fixture | `fixtures/library/frameworks/vda-isa-tisax-v6.yaml` | `fixtures/library/frameworks/vda-isa-tisax-2027.yaml` |
| Loader command | `app:load-tisax-requirements` | `app:load-tisax-2027-requirements` |

**Both are currently assessable.** Never tell a user that ISA 6 is obsolete or
that they must migrate immediately — during the transition window an
organisation may hold or seek a label under either generation. Ask which
catalogue their assessment scope names before giving requirement-level advice,
or derive it (see *Version detection* below).

### Structural differences that matter in practice

ISA 2027 adds requirements that have no ISA 6 counterpart, and drops several
ISA 6 ones. The app encodes the discriminating ids in
`src/Service/Tisax/TisaxCatalogueVersionDetector.php`:

- **ISA 2027 only**: `6.1.3`, `8.1.9`, `8.1.10`, `8.1.11`, `8.1.12`, `8.1.13`
- **ISA 6 only**: `1.2.4`, `8.3.1`, `8.3.2`, `8.4.1`, `8.4.2`, `8.4.3`, `8.5.1`, `8.5.2`

Use these as the fingerprint when a user pastes requirement numbers without
naming a version.

### Maturity model

Both generations score per requirement on the same 0–5 maturity scale
(`incomplete`, `performed`, `managed`, `established`, `predictable`,
`optimizing`). Assessment objectives distinguish *must* and *should*
requirements; the target maturity level is normally 3 for the standard
information-security objective. Additional modules (prototype protection, data
protection) are scoped separately from the information-security module.

## Licensing — why full requirement text is NOT shipped

The VDA-ISA workbook is copyrighted material distributed by ENX/VDA.

- **ISA 6**: requirement text must not be redistributed. The app ships
  **numbers, structure and mappings only**.
- **ISA 2027**: published under **CC BY-ND 4.0** with an additional Section 9
  restriction. No-derivatives means the app must not ship a modified,
  translated or re-worded catalogue either.

**Consequence — bring your own workbook.** Both fixtures carry
`requiresUpload: true`. Users upload their own licensed workbook through the
import wizard (`/tisax-import/…`: disclaimer → upload → validate → preview →
commit → assess), and requirement prose lives only in that tenant's own data,
never in the repository.

When a user asks for the wording of a requirement, do **not** reproduce it from
memory. Point them at their workbook or at their imported catalogue in the app.
Discussing the *intent* of a requirement in your own words is fine; quoting the
catalogue is not.

There is currently **no official German edition** of ISA 2027 — do not invent
German requirement titles.

## Cross-framework mappings shipped

Pair counts as shipped in `fixtures/library/mappings/`:

**ISA 6 (`TISAX`) →**
`iso27001-2022` (70), `iso27002` (2), `iso27017` (5), `bsi-grundschutz` (112),
`nis2` (79), `nist-csf-2.0` (92), `nist-sp800-53r5` (329), `iec-isa-62443` (47).

**ISA 2027 (`TISAX-2027`) →**
`iso27001-2022` (65), `iso27017` (3), `nist-csf-2.0` (120).

**Generation crosswalk:** `tisax_to_tisax-2027_v1.0.yaml` (80 pairs) maps ISA 6
ids onto their ISA 2027 successors — the basis for advising a user who already
holds an ISA 6 assessment and wants to know what changes for ISA 2027. Legacy
id handling lives in `tisax-legacy-id-crosswalk.yaml` and
`tisax-legacy-iso-anchors.yaml`.

Practical consequence: a tenant that already runs ISO 27001 in the app can
inherit evidence into TISAX requirements through the ISO mapping rather than
re-collecting it. That reuse path is the main argument for doing TISAX inside
the tool instead of in a spreadsheet.

## Advising rules

1. **Establish the generation first** (ISA 6 vs ISA 2027) — requirement numbers
   alone are ambiguous except for the fingerprint ids above.
2. **Never reproduce requirement text.** Numbers, structure, mappings, and your
   own explanation of intent only.
3. **Say "label" / "assessment result"**, not "certificate".
4. **Do not push migration.** Both generations are assessable right now.
5. **Prefer reuse**: check whether the tenant's ISO 27001 / BSI evidence already
   satisfies the requirement via the shipped mappings before proposing new work.
6. **Scope matters**: prototype protection and data protection are separate
   assessment objectives — do not fold them into the information-security scope
   unless the user's scope says so.

## Key files in this application

| Concern | File |
|---|---|
| Catalogue versions, fixture map, sizes | `src/Service/Tisax/TisaxCatalogueProvider.php` |
| ISA 6 vs 2027 detection from ids | `src/Service/Tisax/TisaxCatalogueVersionDetector.php` |
| Import wizard (upload → assess → ENX export) | `src/Controller/Tisax/TisaxImportWizardController.php` |
| ISA 6 catalogue loader | `src/Command/LoadTisaxRequirementsCommand.php` |
| ISA 2027 catalogue loader | `src/Command/LoadTisax2027RequirementsCommand.php` |
| Catalogues | `fixtures/library/frameworks/vda-isa-tisax-{v6,2027}.yaml` |
| Mappings | `fixtures/library/mappings/tisax*_to_*.yaml` |

## Official sources

- ENX Association (TISAX): https://enx.com/tisax/
- VDA-ISA catalogue: https://portal.enx.com/
- TISAX participant handbook: published by ENX, version-specific
