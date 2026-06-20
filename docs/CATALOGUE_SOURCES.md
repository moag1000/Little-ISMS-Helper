# Catalogue Sources — Single Source of Truth

This document maps every compliance-framework code to its **canonical
registry loader** (the command bound to the `app.framework_loader` tag via
`FrameworkLoaderInterface`). The framework-loader registry resolves **exactly
one loader per framework code** — that loader is the authoritative seed used
by the cross-framework mapping resolution.

> **Ground truth:** the canonical-loader column below is derived directly from
> `php bin/console debug:container --tag=app.framework_loader` plus each
> loader's `getFrameworkCode()` return value. If you change the registry, run
> that command and update this table.

`lint:container` enforces the one-loader-per-code invariant — if two loaders
claim the same code the container fails to compile.

## Canonical registry loaders (30)

| Framework code   | Canonical registry loader (command)        | Loader class                              |
|------------------|--------------------------------------------|-------------------------------------------|
| BDSG             | `app:load-bdsg-requirements`               | `LoadBdsgRequirementsCommand`             |
| BSI-C5           | `app:load-c5-2020-full-catalogue`          | `LoadC52020FullCatalogueCommand`          |
| BSI-C5-2026      | `app:load-c5-2026-full-catalogue`          | `LoadC52026FullCatalogueCommand`          |
| BSI_GRUNDSCHUTZ  | `app:load-bsi-grundschutz-catalogue`       | `LoadBsiItGrundschutzCatalogueCommand`    |
| CIS-CONTROLS     | `app:load-cis-controls-requirements`       | `LoadCisControlsRequirementsCommand`      |
| DIGAV            | `app:load-digav-requirements`              | `LoadDigavRequirementsCommand`            |
| DORA             | `app:load-dora-requirements`               | `LoadDoraRequirementsCommand`             |
| EU-AI-ACT        | `app:load-eu-ai-act-full`                  | `LoadEuAiActFullCommand`                  |
| EU-CRA           | `app:load-eu-cra-full`                      | `LoadEuCraFullCommand`                    |
| EUCS             | `app:load-eucs-full`                       | `LoadEucsFullCommand`                     |
| GDPR             | `app:load-gdpr-requirements`               | `LoadGdprRequirementsCommand`             |
| GXP              | `app:load-gxp-requirements`                | `LoadGxpRequirementsCommand`              |
| ISO-22301        | `app:load-iso22301-requirements`           | `LoadIso22301RequirementsCommand`         |
| ISO27001         | `app:load-iso27001-requirements`           | `LoadIso27001RequirementsCommand`         |
| ISO27005         | `app:load-iso27005-requirements`           | `LoadIso27005RequirementsCommand`         |
| ISO27017         | `app:load-iso27017-full`                   | `LoadIso27017FullCommand`                 |
| ISO27018         | `app:load-iso27018-full`                   | `LoadIso27018FullCommand`                 |
| ISO27701         | `app:load-iso27701-requirements`           | `LoadIso27701RequirementsCommand`         |
| ISO27701_2025    | `app:load-iso27701v2025-requirements`      | `LoadIso27701v2025RequirementsCommand`    |
| ISO42001         | `app:load-iso42001-full`                   | `LoadIso42001FullCommand`                 |
| KRITIS           | `app:load-kritis-requirements`             | `LoadKritisRequirementsCommand`           |
| KRITIS-HEALTH    | `app:load-kritis-health-requirements`      | `LoadKritisHealthRequirementsCommand`     |
| MRIS-v1.5        | `app:load-mris-requirements`               | `LoadMrisRequirementsCommand`             |
| NIS2             | `app:load-nis2-requirements`               | `LoadNis2RequirementsCommand`             |
| NIS2UMSUCG       | `app:load-nis2umsucg-requirements`         | `LoadNis2UmsuCGRequirementsCommand`       |
| NIST-CSF-2.0     | `app:load-nist-csf-2-0-full-catalogue`     | `LoadNistCsf2FullCatalogueCommand`        |
| PCI-DSS-4.0.1    | `app:load-pci-dss-401-full`                | `LoadPciDss401FullCommand`                |
| SOC2             | `app:load-soc2-requirements`               | `LoadSoc2RequirementsCommand`             |
| TISAX            | `app:load-tisax-requirements`              | `LoadTisaxRequirementsCommand`            |
| TKG-2024         | `app:load-tkg-requirements`                | `LoadTkgRequirementsCommand`              |

## Complementary non-overlapping loaders (keep — NOT duplicates)

Several non-registry loaders write to the **same framework code** as a registry
loader but under a **different identifier scheme**, so they add depth rather
than duplicate rows. These are intentionally kept and are **not** deprecated.
They are plain CLI commands (not registry-bound) so the one-loader-per-code
invariant is preserved.

| Framework code | Registry loader writes…          | Complementary loader writes…                              | Complementary command                  |
|----------------|----------------------------------|------------------------------------------------------------|----------------------------------------|
| ISO27001       | curated requirements             | Annex A controls (`A.x.y`)                                 | `app:load-iso27001-annexa-full`        |
| ISO27001       | curated requirements             | Clauses 4–10 (`ISO27001-4.1` …)                            | `app:load-iso27001-clauses`            |
| GDPR           | curated `GDPR-5.1.a` + ISO maps  | full Art.1–99 legal catalogue (`Art.N`)                    | `app:load-gdpr-full`                   |
| NIS2           | curated `NIS2-21.x` + ISO maps   | full Directive Art.1–46 (`Art.N`)                          | `app:load-nis2-full`                   |
| NIS2           | curated `NIS2-21.x` + ISO maps   | Art. 21(2)(a)–(j) sub-point depth                          | `app:load-nis2art21-requirements`      |
| NIS2UMSUCG     | curated `NIS2UMSUCG-N`           | full §§ catalogue (`§N`, BGBl. 2025 I Nr. 301)             | `app:load-nis2-umsucg-full`            |
| ISO27701       | curated `27701-5.x` clauses      | Annex A + B controls + Clauses 5–9 (PIMS)                  | `app:load-iso27701-full`               |
| DORA           | curated `DORA-6.x` + ISO maps    | full Regulation Art.1–64 legal catalogue (`Art.N`)         | `app:load-dora-full`                   |
| DORA           | L1 + **L2** RTS/ITS (shared svc) | Level-2 depth only, on existing framework (re-load helper) | `app:load-dora-rts-its-full`           |

### Notable non-overlaps documented elsewhere

- **ISO27001**: Annex A controls (`LoadIso27001AnnexAFullCommand`) **and**
  Clauses 4–10 (`LoadIso27001ClausesCommand`) complement the curated registry
  requirements — three distinct identifier spaces, no overlap.
- **NIS2 (EU) vs NIS2-UmsuCG (DE)**: separate framework codes (`NIS2` /
  `NIS2UMSUCG`) — the EU Directive and the German implementation law are
  distinct frameworks, each with its own registry loader.
- **C5-2020 vs C5-2026**: separate codes (`BSI-C5` / `BSI-C5-2026`) — two
  catalogue editions, each with its own registry full-catalogue loader.
- **DORA L1 + L2 in one loader**: the registry loader
  `app:load-dora-requirements` ALSO pulls the DORA Level-2 RTS/ITS catalogue via
  the shared `DoraRtsItsCatalogueLoader` service, so it is canonical-complete.
  `app:load-dora-rts-its-full` is kept only as an operator helper to re-load
  the Level-2 depth on its own; it is **not** deprecated.

## Legacy / manual-only loaders (de-registered, superseded)

These are **not** the registry loader for their code. They seed a curated or
otherwise narrower subset that the cross-framework mappings do **not** resolve
against. They are marked `@deprecated` (PHPDoc) and carry a
`[DEPRECATED — canonical: app:<x>]` description prefix. They remain runnable as
plain CLI commands (no runtime warning) but should not be used to seed a
framework — use the canonical registry loader instead.

| Legacy command                          | Framework code  | Canonical replacement                   | Deprecation status         |
|-----------------------------------------|-----------------|-----------------------------------------|----------------------------|
| `app:load-c5-requirements`              | BSI-C5          | `app:load-c5-2020-full-catalogue`       | deprecated (de-registered) |
| `app:load-c5-2026-requirements`         | BSI-C5-2026     | `app:load-c5-2026-full-catalogue`       | deprecated (de-registered) |
| `app:load-nist-csf-requirements`        | NIST-CSF-2.0    | `app:load-nist-csf-2-0-full-catalogue`  | deprecated (de-registered) |
| `app:load-eu-ai-act-requirements`       | EU-AI-ACT       | `app:load-eu-ai-act-full`               | deprecated (this PR)       |
| `app:load-bsi-requirements`             | BSI_GRUNDSCHUTZ | `app:load-bsi-grundschutz-catalogue`    | deprecated (pre-existing)  |
| `app:load-bsi-grundschutz-requirements` | BSI_GRUNDSCHUTZ | `app:load-bsi-grundschutz-catalogue`    | deprecated (pre-existing)  |
| `app:load-bsi-kompendium-delta`         | BSI_GRUNDSCHUTZ | `app:load-bsi-grundschutz-catalogue`    | deprecated (pre-existing)  |
| `app:load-bsi-kompendium-extended`      | BSI_GRUNDSCHUTZ | `app:load-bsi-grundschutz-catalogue`    | deprecated (pre-existing)  |

## Maintenance

- **Add a framework:** implement `FrameworkLoaderInterface`, tag is applied by
  autoconfiguration, then update the table above from `debug:container`.
- **Never** let two loaders share a `getFrameworkCode()` value — `lint:container`
  will fail.
- A curated subset that loses registry status to a full-catalogue loader should
  be de-registered (drop `FrameworkLoaderInterface`) and marked `@deprecated`
  with a `[DEPRECATED — canonical: app:<x>]` description prefix — see the
  C5 / NIST / EU-AI-Act entries as the reference pattern.
