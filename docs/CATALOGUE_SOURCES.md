# Framework Catalogue Sources — Single Source of Truth

This is the canonical map of **which console command seeds which compliance
framework** into `ComplianceRequirement` rows. For each framework there is
exactly one canonical loader (or a small set of complementary canonical
loaders); any other `Load*Command` for the same framework is a **legacy /
partial** seeder and is marked `@deprecated`.

## Why this matters

Several frameworks historically had two loaders: a richer fixture- or
array-based `*Full` / `*Catalogue` loader **and** an older hardcoded partial
`*Requirements` loader. Running both for the same framework code risks
ambiguous source-of-truth and duplicate-/diverging `ComplianceRequirement`
rows. This document pins down the one authoritative source per framework.

## The runtime "canonical path" — `FrameworkLoaderRegistry`

The app does **not** call these console commands directly at runtime. The
admin "(re)load framework" path and the setup wizard go through
`App\Service\ComplianceFrameworkLoaderService::loadFramework($code)` →
`App\Service\Compliance\FrameworkLoaderRegistry::load($code)`. The registry
keys loaders by `FrameworkLoaderInterface::getFrameworkCode()`.

> **Important:** For a number of frameworks (ISO27001, GDPR, NIS2, DORA,
> BSI-C5, BSI-C5-2026, NIST-CSF, ISO27701) the loader **registered with the
> registry** is the `*Requirements` command, not the `*Full` command. Those
> `*Requirements` commands are therefore **deprecated as direct CLI
> entrypoints** but are **kept** because the registry — the actual runtime
> path — routes the framework code through them. Their `loadRequirements()`
> method (the registry entrypoint) is intentionally left **without** a runtime
> deprecation warning; only the human-facing `execute()` (direct CLI) emits a
> warning. Do **not** delete these.

## Canonical map

| Framework | DB code(s) | Canonical loader(s) | Source location | Legacy / deprecated loader(s) |
|---|---|---|---|---|
| BSI IT-Grundschutz | `BSI_GRUNDSCHUTZ` | `app:load-bsi-grundschutz-catalogue` (`LoadBsiItGrundschutzCatalogueCommand`, registry loader) | `fixtures/library/catalogues/bsi-it-grundschutz-2023/` | `app:load-bsi-grundschutz-requirements` (`LoadBsiItGrundschutzRequirementsCommand`), `app:load-bsi-requirements` (`LoadBsiRequirementsCommand`) |
| BSI C5:2020 | `BSI-C5` | `app:load-c5-2020-full-catalogue` (`LoadC52020FullCatalogueCommand`) | `fixtures/library/catalogues/bsi-c5-2020-en/inventory.json` | `app:load-c5-requirements` (`LoadC5RequirementsCommand`, **registry loader for BSI-C5** — kept) |
| BSI C5:2026 | `BSI-C5-2026` | `app:load-c5-2026-full-catalogue` (`LoadC52026FullCatalogueCommand`) | `fixtures/library/catalogues/bsi-c5-2026-en/` | `app:load-c5-2026-requirements` (`LoadC52026RequirementsCommand`, **registry loader for BSI-C5-2026** — kept) |
| NIS2 (EU 2022/2555) | `NIS2` | `app:load-nis2-full` (`LoadNis2FullCommand`) | PHP array | `app:load-nis2-requirements` (`LoadNis2RequirementsCommand`, **registry loader for NIS2** — kept) |
| NIS2 Art. 21(2)(a)-(j) sub-catalogue | `NIS2` (requirementIds `NIS2-ART21-*`) | `app:load-nis2art21-requirements` (`LoadNis2Art21RequirementsCommand`) — **CANONICAL, not legacy** | `fixtures/library/frameworks/nis2-art21_v1.0.yaml` | — |
| NIS2-UmsuCG (DE) | `NIS2UMSUCG` | `app:load-nis2-umsucg-full` (`LoadNis2UmsuCGFullCommand`, registry loader) | PHP array | `app:load-nis2-umsucg-requirements` (`LoadNis2UmsuCGRequirementsCommand` — registry impl; not in scope of this sweep) |
| DORA (EU 2022/2554) | `DORA` | `app:load-dora-full` (`LoadDoraFullCommand`, L1) **+** `app:load-dora-rts-its-full` (`LoadDoraRtsItsFullCommand`, L2 RTS/ITS) | PHP array | `app:load-dora-requirements` (`LoadDoraRequirementsCommand`, **registry loader for DORA** — kept) |
| ISO/IEC 27001:2022 | `ISO27001` | `app:load-iso27001-annexa-full` (`LoadIso27001AnnexAFullCommand`, Annex A) **+** `app:load-iso27001-clauses` (`LoadIso27001ClausesCommand`, Clauses 4-10) | PHP array | `app:load-iso27001-requirements` (`LoadIso27001RequirementsCommand`, **registry loader for ISO27001**; also seeds Clauses 4-10 — kept) |
| EU AI Act (EU 2024/1689) | `EU-AI-ACT` | `app:load-eu-ai-act-full` (`LoadEuAiActFullCommand`, registry loader) | PHP array | `app:load-eu-ai-act-requirements` (`LoadEuAiActRequirementsCommand`) |
| GDPR (EU 2016/679) | `GDPR` | `app:load-gdpr-full` (`LoadGdprFullCommand`) | PHP array | `app:load-gdpr-requirements` (`LoadGdprRequirementsCommand`, **registry loader for GDPR** — kept) |
| NIST CSF 2.0 | `NIST-CSF-2.0` (Full) / `NIST-CSF` (legacy) | `app:load-nist-csf-2-0-full-catalogue` (`LoadNistCsf2FullCatalogueCommand`) | `fixtures/library/catalogues/nist-csf-2-0/csf2_subcategories.json` | `app:load-nist-csf-requirements` (`LoadNistCsfRequirementsCommand`, **registry loader for code NIST-CSF** — kept; note the Full loader uses a *separate* code `NIST-CSF-2.0`) |
| EUCS | `EUCS` | `app:load-eucs` (`LoadEucsFullCommand`, registry loader) | PHP array | — |
| ISO/IEC 27017 | `ISO27017` | `app:load-iso27017-full` (`LoadIso27017FullCommand`, registry loader) | PHP array | — |
| ISO/IEC 27018 | `ISO27018` | `app:load-iso27018-full` (`LoadIso27018FullCommand`, registry loader) | PHP array | — |
| ISO/IEC 27701:2025 (current) | `ISO27701_2025` | `app:load-iso27701v2025-requirements` (`LoadIso27701v2025RequirementsCommand`, registry loader) | PHP array | `app:load-iso27701-full` (`LoadIso27701FullCommand`, older loader writing code `ISO27701`), `app:load-iso27701-requirements` (`LoadIso27701RequirementsCommand`, **registry loader for legacy code ISO27701** — kept) |
| ISO/IEC 42001 | `ISO42001` | `app:load-iso42001-full` (`LoadIso42001FullCommand`, registry loader) | PHP array | — |
| EU CRA | `EU-CRA` | `app:load-eu-cra-full` (`LoadEuCraFullCommand`, registry loader) | PHP array | — |

## Deprecated loaders — status

All loaders marked `[DEPRECATED — use app:<x>]` in their command description
carry an `@deprecated` PHPDoc line naming the canonical replacement and emit a
non-blocking `$io->warning(...)` when run **directly via the CLI**. They are
**not deleted** because:

- several are still the loader the `FrameworkLoaderRegistry` routes a framework
  code through (the actual runtime path) — these are annotated "registry
  loader … — kept" above; their registry entrypoint (`loadRequirements()`)
  does not warn, only the direct CLI `execute()` does;
- some are referenced by tests (e.g. `LoadNis2RequirementsCommandTest`,
  `ComplianceReuseJourneyTest`) and by mapping-code alignment tests.

`LoadNis2Art21RequirementsCommand` is **NOT** deprecated: the
`nis2-art21` cross-framework mappings depend on the `NIS2-ART21-*`
requirementIds it seeds (see `tests/Service/Library/Nis2BsiMappingCodesTest`,
`Nis2IsoMappingCodesAlignmentTest`, `CraNis2MappingCodesAlignmentTest`). It is
the canonical source for the NIS2 Art. 21 sub-catalogue.

## Adding a new framework

1. Build the catalogue as a fixture (`fixtures/library/...`) where practical,
   or as a self-contained array loader.
2. Implement a single canonical `Load<Framework>FullCommand` (or
   `...CatalogueCommand`) implementing `FrameworkLoaderInterface` so the
   `FrameworkLoaderRegistry` can re-seed it.
3. Add a row to the table above.
4. Do **not** ship a second partial loader for the same framework code.
