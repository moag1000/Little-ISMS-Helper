# TISAX Framework Consolidation — Design

**Status:** Draft for review
**Date:** 2026-06-01
**Author:** Claude (with moag1000)
**Related:** PR #824 (import-logic fix — prerequisite, already merged/open)

---

## 1. Problem

TISAX is currently represented by **two parallel ComplianceFramework rows wired to
disjoint feature sets**, and the requirement-id scheme is fragmented **three ways**.
Imported assessment data is therefore invisible to the mapping / dashboard /
reporting layer, and cross-framework mappings cannot resolve.

### 1.1 Two frameworks

| | `TISAX` (id 114) | `TISAX-VDA-ISA-6` (id 132) |
|---|---|---|
| Used by (code refs) | Dashboards (DPO/BCM/ISB), Compliance-Wizard, KPI-Snapshot, Management-Reports, **ISO-mapping seed** (`SeedTisaxIso27001MappingsCommand`, source=`TISAX`), Sub-Requirement decomposition (`decomp_tisax_iso27001`), `LoadTisaxRequirements`, `SupplementTisaxRequirements`, `LoadTisaxAl3Requirements` — **~12 sites** | **BYO VDA-ISA import** (`TisaxRequirementMapper`), Library-Importer, AlvaHint `LibraryUpdatedRule` — **~4 sites** |
| Requirement rows | 248 | 96 (after a real import) |

**The BYO import writes to 132, but mappings + dashboards + reports + wizard read
from 114.** They never reconcile.

### 1.2 Three incompatible id schemes

Measured in the live dev DB:

| Scheme | Example | 114 | 132 |
|---|---|---|---|
| Official VDA-ISA control number | `1.1.1` | 134 | 80 |
| Ad-hoc domain prefix | `INF-1.1`, `ACC-2.1` | 99 | 0 |
| Stub prefix | `ISA 1.1.1` | 15 | 10 |
| Chapter stub | `ISA-KAP-3` | 0 | 6 |

The **ISO 27001 ↔ TISAX mappings** (`SeedTisaxIso27001MappingsCommand`) reference the
**`ACC-x.x` / `INF-x.x` scheme** (e.g. `ACC-1.1 → A.5.15`). The **BYO import** produces
the **official `1.1.1` scheme**. So even within framework 114 the mapping seed and the
real control numbers do not line up.

### 1.3 Already-imported user data ("kacke importiert")

Existing tenants who used the (now-fixed) importer already have `requirement_source =
'tenant_upload'` rows sitting in **132** under the official `1.1.1` scheme, plus the
pre-fix runs that imported only Information-Security. Any consolidation **must migrate
that real tenant data** into the canonical framework without loss — this is the most
sensitive part.

### 1.4 Seed junk

Both frameworks carry non-control skeleton rows that pollute counts and the assess UI:
`ISA-KAP-1..6` (chapter stubs) and `ISA 1.1.1`-style placeholder stubs.

---

## 1.6 The full per-control model (the "Gesamtbild")

A TISAX control is **not** a single maturity value. The canonical framework + assess UI
must represent the complete VDA-ISA structure, which is **dimension-specific**:

| Axis | Information Security | Prototype Protection | Data Protection |
|---|---|---|---|
| **Assessment** (per control) | Reifegrad 0–5 (`na`/0–5) | Reifegrad 0–5 | tristate `OK`/`Nicht OK`/`na` |
| **Requirement tiers** | must · should · **high** · **very-high** · **SGA** | must · should · **protection-classified vehicles** | must |
| Target Reifegrad | 3 (established), uniform | 3 | n/a (tristate) |

Plus three orthogonal facets:
- **Schutzbedarf** (protection need): normal / high / very-high — selects which tier applies.
- **Assessment level** AL1 / AL2 / AL3 — audit method/depth (§8.1a).
- **SGA** (Simplified Group Assessment) — a scope variant with its own additional
  requirements (IS only).

PR #824 now imports all of this: Reifegrad + tristate into `maturityCurrent`/`maturityRaw`,
and every tier into `dataSourceMapping.tisax_{must,should,high,veryHigh,sga}`. The canonical
model (post-consolidation) should surface the tiers in the assess UI rather than burying
them in JSON — a follow-up once the framework is unified.

## 2. Goals / Non-goals

**Goals**
- One canonical TISAX framework.
- One canonical requirement-id scheme: the **official VDA-ISA control number** (`1.1.1`).
- ISO↔TISAX (and NIS2/DORA) mappings resolve against real controls.
- Existing tenant_upload data preserved and migrated, idempotently.
- Seed junk removed; library re-seed stays idempotent.

**Non-goals**
- Re-authoring the ISO mapping *content* (only the id crosswalk changes).
- Changing the import *logic* (done in PR #824).
- Supporting ISA 4.x (rejected by design).

---

## 3. Decision required (the open question)

**Which framework becomes canonical?**

- **Option A — `TISAX` (114) canonical (recommended).** Repoint the import + library +
  AlvaHint from code `TISAX-VDA-ISA-6` → `TISAX`. Dashboards / mappings / reports /
  wizard / seed-commands (~12 sites) stay untouched. Retire 132.
  *Lowest churn; keeps the side the rest of the app already reads.*
- **Option B — `TISAX-VDA-ISA-6` (132) canonical.** Repoint ~12 feature sites + seed
  commands to `TISAX-VDA-ISA-6`; delete 114. *Larger code change, more risk.*

> **Recommendation: Option A.** The remainder of this document assumes A. If B is
> chosen, sections 4–6 invert the framework target but keep the same id-unification and
> migration mechanics.

**Id scheme:** canonical = official VDA-ISA control number `1.1.1` (delegated decision —
it is the authoritative scheme, matches the workbook, matches the import, and is the
only scheme common to both frameworks). The `INF-/ACC-`, `ISA x.y.z`, `ISA-KAP`, and
`TISAX-CONF-SC` schemes are retired.

---

## 4. Design (Option A)

### 4.1 Code repoint
- `TisaxRequirementMapper::FRAMEWORK_CODE`: `TISAX-VDA-ISA-6` → `TISAX`.
  `findOrCreateFramework()` returns the existing 114; creation path updated.
- `VdaIsaImporter` (`fixtures/library/frameworks/vda-isa-tisax-v6.yaml` meta `code`),
  `LibraryImporterController`, AlvaHint `LibraryUpdatedRule` map key: point to `TISAX`.
- Keep a code alias so old references / saved sessions resolve (`TISAX-VDA-ISA-6` →
  `TISAX`) during a deprecation window.

### 4.2 Id unification (crosswalk)
The hard sub-problem. We need a deterministic map from each legacy scheme to the
official `1.1.1`:
- `ISA 1.1.1` → `1.1.1` (strip `ISA ` prefix).
- `ISA-KAP-n` → **delete** (chapter skeleton, not a control).
- `INF-x.y` / `ACC-x.y` / `TISAX-CONF-SC-x.y` → **explicit crosswalk table** to VDA-ISA
  numbers. This must be authored from the VDA-ISA catalogue (e.g. `ACC-1.1` = control
  "1.x.x access-control policy"). Ships as a versioned fixture
  (`fixtures/library/mappings/tisax-legacy-id-crosswalk.yaml`) so it is reviewable.
  *Unmapped legacy ids are reported, never silently dropped (no-silent-cap rule).*

The ISO-mapping seed (`SeedTisaxIso27001MappingsCommand`) is rewritten to use `1.1.1`
ids (its `source` column), or run through the crosswalk at seed time.

### 4.3 Data migration (Doctrine migration + command)
`isTransactional() = false` (DDL-free, data-only — but large; run as a guarded data
migration or an idempotent console command `app:tisax:consolidate`). Steps, per tenant:

1. **Backup guard**: abort unless `--force`; emit a count summary first (dry-run default).
2. **Migrate tenant_upload from 132 → 114**: for each `requirement_source='tenant_upload'`
   row in 132, find the 114 row with the same *normalised* control number and **move the
   assessment** (maturityCurrent/Target, dataSourceMapping.implementation /
   referenceDocumentation / maturityRaw / iso27001, uploadTenant). If no 114 row exists
   (a 6.x-only control 114 lacks), **re-home the row** to 114 (reassign framework_id +
   normalise id). Audit via `AuditLogger::logBulk`.
3. **Crosswalk-normalise 114 ids** to `1.1.1` (ISA-prefix strip + INF/ACC crosswalk).
   Update dependent rows: `compliance_mapping.source/target_requirement_id` already FK by
   surrogate id, so renaming `requirement_id` is safe; only string-id seeds need the
   crosswalk.
4. **Delete seed junk**: `ISA-KAP-*` and superseded `ISA x.y.z` stub rows in both
   frameworks (only rows with `requirement_source='system'` AND no tenant assessment).
5. **Retire 132**: set `successor_id = 114`, `active = 0`; keep the row (FK safety) but
   hide from pickers; or hard-delete after confirming zero remaining FK refs.
6. Re-run the (rewritten) ISO-mapping seed so mappings attach to canonical `1.1.1` rows.

### 4.4 Re-seed safety
`VdaIsaImporter` must upsert by canonical `1.1.1` id so a future library re-import does
**not** recreate `ISA-KAP-*` / `ISA x.y.z` stubs. The library YAML skeleton is changed to
emit `1.1.1` ids (or none, leaving control numbers to the BYO import).

---

## 5. Risks & rollback
- **FK breakage** on framework delete → mitigated by *retire (active=0 + successor_id)*
  rather than hard-delete in phase 1; hard-delete only after a follow-up FK-zero audit.
- **Crosswalk gaps** (INF/ACC ids with no VDA-ISA equivalent) → reported, parked under a
  `legacy_unmapped` category, never dropped. Manual review list produced.
- **Tenant assessment loss** → migration is dry-run-by-default, idempotent, audited; a
  pre-migration snapshot of `compliance_requirement` (TISAX rows) is exported to
  `var/backups/` first.
- **Saved wizard sessions** referencing 132/legacy ids → code alias (4.1) + session
  re-resolve.

## 6. Verification
- Unit: crosswalk completeness test (every seed `source` id resolves); mapper targets
  `TISAX`.
- Integration: import the CANCOM 6.0.2 workbook → assert 80 controls land in **114**
  with measures/docs/maturity; assert ISO-mapping seed rows resolve to those controls.
- Data: dry-run consolidation on a DB snapshot → assert 0 tenant rows lost, 132 emptied
  of tenant_upload, junk removed, mapping resolution count > 0.
- Manual: `/de/compliance/framework/114` shows the imported assessment; a TISAX↔ISO
  mapping view resolves.

## 8. User-facing surface unification

The data/framework split (§1) leaks into the UI: TISAX is surfaced through **84 src
files + 33 templates**, and almost all of them already read code `TISAX` (114) — which
*reinforces Option A*. Only the BYO-import / library / AlvaHint use 132. The goal: every
TISAX entry point leads into **one** coherent area backed by the canonical framework.

### 8.1 Navigation / menu
- `_mega_menu.html.twig` registers **two** sibling compliance entries — `app_tisax_`
  (import wizard) **and** `app_prototype_protection_`. Prototype Protection is a
  **legitimate TISAX special sub-area** (VDA-ISA prototype-protection module) — it stays,
  but **nested under** a single "TISAX" mega-menu section rather than as a top-level peer.
  **Action:** one "TISAX" section header containing: VDA-ISA import, assessment, **and**
  Prototype Protection as a labelled sub-area; command-palette keyword stays `tisax`.
- Gate the whole section on the single `tisax` module.

### 8.1a Assessment levels (AL1 / AL2 / AL3) — legitimate, keep
TISAX defines three **assessment levels** — AL1 (self-assessment), AL2 (plausibility /
remote), AL3 (on-site audit) — with increasing assurance. These are **first-class TISAX
structure, not junk**:
- The VDA-ISA workbook already encodes the assurance tiers per control via the
  must / should / **high** / **very-high** protection-need columns (captured by the import
  into `dataSourceMapping.tisax_{must,should,high,veryHigh}`). AL3/high-protection scope
  scrutinises the higher tiers.
- `LoadTisaxAl3RequirementsCommand` (`TISAX-CONF-SC-*`) expresses AL3-specific content.
  **Action:** preserve the AL concept on the canonical model — either (a) an
  `assessmentLevel` facet on the requirement / a per-tenant scope selector (AL1/2/3), or
  (b) derive AL applicability from the protection-need tiers already imported. The AL3
  loader is **re-homed onto canonical `1.1.1` controls** (mapping its `TISAX-CONF-SC-*`
  content to the relevant control + tier), **not retired**. No separate id scheme.

### 8.1b Data Protection dimension — bundled with the privacy module
The TISAX **Data Protection** dimension (workbook chapter 9.x — "additional questions for
Art. 28 GDPR processor suitability") is **bundled with the app's existing `privacy` /
Datenschutz module**, exactly as Prototype Protection is a TISAX sub-area and AL1–3 are
levels. It is *not* a standalone silo:
- DP controls (category `data_protection`) surface in the **DPO dashboard** and the
  privacy area, and link to existing GDPR artefacts (processing activities / Art. 28
  DPAs, DPIA) as evidence — reusing the privacy module rather than re-capturing.
- Visibility/gating: DP dimension shows when **both** `tisax` **and** `privacy` modules are
  active (module-awareness rule). When `privacy` is off, DP controls still import but the
  cross-links degrade gracefully.
- The DP tristate assessment ("OK"/"Nicht OK", imported in PR #824) feeds the DPO
  dashboard's TISAX-DP tile and the privacy coverage view from the **one** canonical
  framework.

### 8.2 Modules
- One module key `tisax` (the vaporware `tisax_isa` was already removed 2026-05-25). Its
  description bundles "VDA-ISA Assessment + Prototype Protection" and owns
  `PrototypeProtectionAssessment` + `/prototype-protection`. **Action:** keep one module;
  ensure import wizard, assessment, prototype protection, dashboards, and loaders all gate
  on `tisax` consistently (audit `checkModuleActive('tisax')` / `is_module_active`).
- The `compliance` module description also name-drops TISAX — leave (cross-framework
  umbrella), but the dedicated surface lives under `tisax`.

### 8.3 Dashboards
Six dashboards read `findOneBy(['code' => 'TISAX'])`: DPO, ISB, BCM-Officer,
Compliance-Manager, CISO (+ `RoleDashboardService`). Under Option A these need **no code
change** (114 stays canonical) — but they must benefit from the migration: once imported
assessment data lands in 114, the dashboard tiles (coverage, maturity, open gaps) populate
for the first time. **Verification target:** each dashboard's TISAX tile shows non-zero
after a real import.

### 8.4 Reports / evaluations / KPI
`ManagementReportService` + KPI snapshots read `TISAX` (114). Same as dashboards: no code
change under A, but the report's TISAX coverage/maturity figures become meaningful only
post-migration. Add a smoke assertion: management report TISAX section renders with the
imported control counts.

### 8.5 Setup / onboarding wizard
- `ComplianceWizardService`, `OtherFrameworkCategoryProvider`, `SetupRecommendationEngine`,
  and the policy wizard (`WelcomeStandardsStep`, `DocumentGenerator`) reference TISAX.
- **Action:** the setup/compliance wizard's "activate TISAX" step must (a) activate the
  `tisax` module, (b) point at the canonical framework `TISAX`, and (c) route the user to
  the **BYO VDA-ISA import** as the way to populate controls (licensing: catalogue text
  only via the user's workbook). No more ad-hoc seeded INF-/ACC- requirements as the
  "TISAX content".

### 8.6 Sample-data / loader commands (the id-scheme root cause)
Seven commands target `TISAX` (114) with **incompatible ad-hoc id schemes**:
`LoadTisaxRequirements` (`INF-x.y`), `LoadTisaxAl3Requirements` (`TISAX-CONF-SC-x.y`),
`SeedTisaxIso27001MappingsCommand` (`ACC-x.y` ↔ ISO), `SupplementTisaxRequirements`,
`SeedTisaxPolicyTemplates`, `PurgeTisaxIpAddresses`, plus `SampleDataController`.
- **Action:** these ad-hoc loaders are the source of the `INF-/ACC-/TISAX-CONF-SC` schemes
  and the seed junk. Decision per command:
  - `LoadTisaxRequirements` / `…Al3` / `Supplement`: **demote to sample/demo-only** (clearly
    labelled non-authoritative), OR retire — the authoritative catalogue comes from the BYO
    import. If kept for demo, re-emit canonical `1.1.1` ids.
  - `SeedTisaxIso27001MappingsCommand`: rewrite `source` ids to `1.1.1` (via §4.2 crosswalk)
    so mappings attach to real controls.
  - `SeedTisaxPolicyTemplates` / `PurgeTisaxIpAddresses`: framework-code-agnostic, keep;
    just repoint any framework lookup to canonical.

### 8.7 Acceptance — "one coherent TISAX"
A user enabling TISAX sees: one menu section → one module → one framework (`TISAX`) →
populated via one import path → reflected in the same dashboards, reports and wizard.
No second framework, no parallel id schemes, no empty mapping views.

## 7. Phasing
1. **P1** Crosswalk fixture + completeness test (no DB change).
2. **P2** Repoint code (import/library/AlvaHint) → `TISAX` + alias.
3. **P3** `app:tisax:consolidate` (dry-run) + migration; ISO-seed rewrite.
4. **P4** Re-seed safety + junk cleanup + 132 retire.
5. **P5** Hard-delete 132 after FK-zero audit (optional, later).

Each phase is independently shippable and verifiable.

---

## 9. Review findings incorporated (Consultant + ISB, 2026-06-01)

Two persona reviews (Senior GRC Consultant; ISB/audit practitioner) found the skeleton
sound but **not sign-off-ready**. Both surfaced a critical blocker the original draft
missed. All findings are folded in below; §3–§8 are amended accordingly.

### 9.1 Blockers (must fix in this design)

- **B1 — Library cross-framework mapping graph (Consultant G1).** Beyond the 7 PHP
  loaders (§8.6), the repo ships **~12 versioned YAML library mappings** keyed to
  `tisax-vda-isa-6` (→ ISO 27001/27002/27017/27701, BSI-Grundschutz, NIST-CSF,
  NIST-800-53, IEC-62443) **+ 3 decomposition fixtures** (`decomp_tisax_iso27001`,
  `decomp_tisax-dp_iso27701`, `decomp_tisax-dp_bdsg`) + public CSVs. Retiring 132 without
  re-keying these makes the **entire pre-built mapping library go dark** — the tool's main
  GRC selling point. **Action:** new **Phase P1.5** — re-key every
  `tisax-vda-isa-6_to_*` / `*_to_tisax-vda-isa-6` YAML mapping + decomposition onto
  canonical `TISAX`/`1.1.1`, with a resolution test asserting **zero dangling** ids.
- **B2 — TISAX assessment not freezeable (ISB G1).** `AuditFreezeSnapshotBuilder`
  (`buildRequirementFulfillments`) snapshots only `ComplianceRequirementFulfillment` —
  NOT `ComplianceRequirement.maturityCurrent/Target/ReviewedAt` nor `dataSourceMapping`.
  → no frozen, signed Reifegrad-Stand at the certification cut-off. **Action:** extend the
  freeze snapshot to capture, per requirement (keyed by canonical `1.1.1`): Reifegrad
  current/target, reviewedAt, dimension, applicable tier, DP tristate. Verification:
  freeze → snapshot contains non-zero TISAX Reifegrad rows.
- **B3 — Evidence linkage missing (ISB G4).** Imported "Referenz Dokumentation" is stored
  as free-text in `dataSourceMapping.referenceDocumentation`; the entity's
  `evidenceDocuments` M2M (`Document`, ISO 27001 Cl. 7.5.3, "M-05") is unused. **Action:**
  resolve citations to `Document` by title/version match; unmatched → typed
  "unlinked-citation" review queue. Never leave evidence as an unverifiable string.

### 9.2 High-value (strongly recommended before P3)

- **Tiers first-class (ISB G2).** Promote must/should/high/very-high/SGA (+ PP vehicle)
  from JSON to queryable/filterable fields (or a typed `requirement_tier` child) and add
  to the freeze snapshot — *before* declaring "one coherent TISAX", not as a follow-up.
- **DP OK→established conflation (ISB G3).** Document the decision (ADR); **exclude DP from
  IS maturity averages**; compute DP coverage from the tristate, not the synthesised level.
- **ENX round-trip export (Consultant G4).** Put `EnxScheduleExporter` in scope +
  acceptance: import → assess → export ENX-conformant XLSX reading canonical `1.1.1`.
- **Domain/chapter facet survives crosswalk (Consultant G3).** The 14 prefixes
  (ACC/BCM/CMP/COM/CRY/DEV/HRS/INC/INF/MOB/OPS/PHY/PROT/SUP) are VDA-ISA domain codes —
  keep as a first-class control attribute. Crosswalk is **non-1:1**, catalogue-authored
  (≈3–5 FTE), not a regex strip.
- **Migration audit granularity + rollback (ISB G6/G7).** Mandate `old_values`/`new_values`
  + crosswalk-version + reason in every `logBulk` per-entity row; write a tenant-scoped
  **rollback runbook** (idempotent-forward ≠ reversible).
- **`maturityReviewedAt` (ISB G8).** Set from the workbook assessment date (or import date
  with a flag distinguishing the two) — a Reifegrad without a Stichtag is not auditable.
- **AL1/2/3 + SGA scope (both, G6/G9).** Pick **option (a)**: a per-tenant (per-assessment-
  scope) selector recording AL-level + SGA-in-scope as an explicit scope statement that
  drives which tiers/evidence the report demands. Distinguish system-defaulted target-3
  from owner-confirmed.
- **Multi-client ergonomics (Consultant G7).** Per-tenant dry-run report; per-tenant,
  exportable `legacy_unmapped` artifact; no-op guarantee for tenants that never used TISAX.
- **Versioning contract (both, G8).** Crosswalk carries `version` + `appliesToWorkbook/ISA
  version`; define re-seed reconciliation for an already-migrated tenant at the next ISA
  revision (6.0.x → 6.1) so the fork does not recur.
- **NIS2/DORA claim (Consultant G2).** No NIS2↔/DORA↔TISAX seed exists. Either drop the
  claim from §2 or specify **transitive resolution via 27001** + ship the reuse view.
- **Mapping resolvability as a gate (ISB).** §6 "resolution count > 0" is too weak —
  require **every** mapped source/target id to resolve to a live canonical control (zero
  dangling), failing the build otherwise.

### 9.3 Revised effort & phasing
Consultant FTE read: **≈14–21 FTE-days** (was ~8–10). Insert **P1.5 (library re-key)**
between P2 and P3, gate P3 on it. Make the gap/maturity report shape (current vs target=3,
per domain, per tier, AL-scoped) + ENX round-trip explicit P4 companions, not implied
side-effects. The original P1→P5 spine stays.

### 9.5 SGA & multi-tenant scope — scoped DOWN, deferred to a later feature (decision 2026-06-01)

A second consultant review pushed for a full **assessment-scope model**: new
`TisaxAssessmentScope` + `TisaxSite` entities, a scope-keyed assessment join (so one
tenant holds N scopes), site-sampling with rationale, a scope-aware `EnxScheduleExporter`,
and a consultant-level scope-preset library — a separate **≈12–17 FTE-day** workstream.

**Decision: do NOT build this now.** It would bend the tool's current one-assessment-per-
(framework, tenant) shape out of proportion to present demand. The full SGA reality
(group-of-locations + sampling + multiple concurrent scopes) is a **deferred feature**,
documented here so the rationale survives.

**What stays in scope now (fits the tool, cheap):**
- SGA requirement-tier **text** is already imported (`dataSourceMapping.tisax_sga`,
  PR #824). Surface it as a column alongside the other tiers (folds into §9.2
  "Tiers first-class" — no separate machinery).
- **AL-level + SGA-in-scope** captured as **lightweight assessment metadata** (a small
  enum/flag on the existing structures, e.g. `dataSourceMapping` or a tenant-level TISAX
  setting) — NOT a new entity graph. Enough to label a report and gate the SGA column.

**Known limitation (the deferral trigger):** today a tenant cannot represent **two
concurrent TISAX scopes** (e.g. AL3/high/IS+PP for OEM-A *and* AL2/normal/IS for OEM-B).
The current model is one assessment bag per (framework, tenant). When a real tenant needs
multiple scopes, that is the signal to build the deferred `TisaxAssessmentScope` /
`TisaxSite` feature (consultant write-up retained for that future spec). Until then,
multi-scope is handled out-of-band (separate tenant or narrative).

**Future-feature backlog (not this design):** `TisaxAssessmentScope` (AL/SGA/protection-
need/dimensions/enxScopeId) · `TisaxSite` (address/ENX-reg/sample+rationale; NOT an
overload of the physical-room `Location` entity) · scope-keyed assessment + per-scope
freeze · scope-aware ENX export (SGA sheet + site dimension) · consultant scope-preset
library with audited downward-only (library→tenant) clone · opt-in k-anonymised
cross-tenant maturity benchmark.

### 9.4 The auditor's question this design must answer
> "Show me the TISAX Reifegrad-Stand exactly as it was on the certification cut-off date,
> with the document evidence for control 1.1.1 — and prove it has not been altered since."

Answerable only after **B2 (freeze) + B3 (evidence link) + migration old/new audit** land.
