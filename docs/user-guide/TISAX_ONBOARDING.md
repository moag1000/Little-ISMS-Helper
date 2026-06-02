# TISAX Onboarding & Operations Runbook

How to bring a tenant from "TISAX module on" to "TISAX assessment counts everywhere"
(coverage, SoA, cross-framework reuse). Most steps are the in-app wizard; a few admin
operations are CLI (an operator UI is on the backlog — until then this runbook is the
canonical sequence).

> **Licensing:** the VDA-ISA catalogue text is ENX-copyrighted and is NOT shipped. The
> tenant uploads their own licensed workbook; canonical control numbering lives only in
> the tenant's DB.

---

## 1. Normal onboarding (fresh tenant, no legacy data) — UI only

1. **Activate** the `tisax` module (`config/active_modules.yaml` / admin module page).
2. **Import wizard** — `/{locale}/tisax-import/disclaimer` → Upload → Validate → Preview →
   Commit → Assess. The commit step automatically:
   - creates the requirements under the canonical `TISAX` framework,
   - materialises `ComplianceRequirementFulfillment` from the imported Reifegrad
     (`TisaxFulfillmentSync`), so catalogue coverage / SoA / inheritance reflect it,
   - links evidence citations to `Document`s where the filename matches.
3. **Reconcile** the cross-framework reuse once (creates transitive NIS2/DORA edges):
   ```bash
   php bin/console app:tisax:reconcile
   ```
   Idempotent, no-op for tenants without TISAX. Run after the first import and after any
   bulk change.
4. The user lands on **`/compliance/framework/{id}`** (the "Zur TISAX-Übersicht" CTA) —
   coverage %, the three dimensions (Informationssicherheit / Prototypenschutz /
   Datenschutz), and the gap-to-target.

That is the whole happy path for a fresh tenant. No `consolidate`, no crosswalk.

---

## 2. Migrating an OLD install (legacy `TISAX-VDA-ISA-6` framework / `INF-`/`ACC-` ids)

Only needed for installs that ran the old seed loaders (legacy framework 132 +
`INF-`/`ACC-`/`ISA x.y.z` requirement ids). Skip entirely for fresh tenants.

1. **Dry-run first — always.** Writes nothing; prints a per-tenant plan + a snapshot:
   ```bash
   php bin/console app:tisax:consolidate
   ```
   Review the "Move / Re-home / Unmapped" counts and the seed-junk list.
2. **Derive + persist the legacy-id crosswalk** (resolves `ACC-`/`INF-` → `1.1.1` via the
   shared ISO anchor; copyright-safe, stored per-tenant in `tisax_crosswalk_entry`):
   ```bash
   php bin/console app:tisax:derive-crosswalk --persist --tenant=<ID>
   ```
   ~46/98 resolve uniquely; the rest stay `needs_human_review` (the licensed catalogue is
   required to confirm them). A review proposal is written to
   `var/tisax-crosswalk-proposal.yaml` (`derived` / `ambiguous` / `needs_human_review`).
3. **Apply** (writes; audited via `AuditLogger::logBulk`; retires the legacy framework
   `active=0 + successor`, never hard-delete):
   ```bash
   php bin/console app:tisax:consolidate --force
   ```
   This also normalises dimension categories, parks unmapped legacy ids under
   `legacy_unmapped` (never dropped), and re-syncs fulfilment.
4. **Reconcile** (transitive NIS2/DORA edges):
   ```bash
   php bin/console app:tisax:reconcile
   ```
5. **Rebuild to the single canonical catalogue** — collapses everything to ONE
   clean 80-control VDA-ISA 6.0 catalogue (numbers only) and removes the legacy
   pollution. Dry-run first (default); it prints the Phase B blast radius:
   ```bash
   php bin/console app:tisax:rebuild-catalogue            # dry-run report
   php bin/console app:tisax:rebuild-catalogue --force    # Phase A: reseed 80 + flatten + drop FW132
   php bin/console app:tisax:rebuild-catalogue --force --purge-legacy   # + Phase B: purge legacy rows/mappings/fulfilments
   ```
   Phase A is always safe (snapshot + reseed + flatten + drop the empty legacy
   framework). Phase B deletes the 182 legacy shared rows, the ~1517 legacy-id
   ComplianceMapping rows and the 128 superseded pre-BYO fulfilments; the
   canonical number-keyed reuse graph (~270 edges on the tenant rows) survives.
   Every run snapshots to `var/backups/tisax_rebuild_snapshot_*.json` first.
   **Run on deploy with the matching code** — not ad-hoc against a shared dev DB.

### Rollback
Every `--force` run snapshots the prior state to `var/backups/tisax_consolidate_snapshot_*.json`.
To roll a tenant back (audited):
```bash
php bin/console app:tisax:restore-snapshot var/backups/tisax_consolidate_snapshot_<ts>.json --force
```

---

## 3. Fleet (consultant managing many tenants)

- `app:tisax:reconcile` iterates **all** TISAX tenants (sync + transitive), no-op for the rest.
- `app:tisax:consolidate` / `derive-crosswalk` are per-tenant where it matters (`--tenant`),
  global where the catalogue is shared.
- Per-tenant confirmed crosswalk survives re-runs and the next ISA revision
  (`tisax_crosswalk_entry`), so you confirm the `ACC-`/`INF-` mappings once per client.

---

## 4. Coverage semantics (what the numbers mean)

- **Denominator** = the tenant's in-scope uploaded controls (~80), not the library skeleton.
- **Reifegrad → fulfilment:** RG≥3 ("established", the VDA-ISA target) → `implemented`
  (counts as covered). RG1-2 → `in_progress` (not counted). An imported self-assessment is
  never `verified` (that needs AL2/AL3). **Show the per-dimension Reifegrad gap-to-3
  alongside the headline %**, not the bare % — a wall of RG2 contributes 0 to coverage.
- **Data Protection** is a tristate (OK / Nicht OK / NA), assessed separately from the IS
  Reifegrad; it does not enter the IS maturity average.

---

## 5. Versions
ISA 6.x → full import. ISA 5.x → partial (subset of 6; `ISA New` ids + 5.x-DP `9.x`→`9.x.1`
normalisation). ISA 4.x → rejected (different catalogue). After an ISA minor bump
(6.0.x → 6.1) re-run `derive-crosswalk` + `reconcile`; verify no dangling mappings (see
`app:tisax:verify-mappings`).
