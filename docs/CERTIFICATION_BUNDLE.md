# Certification Bundle Exporter

`src/Service/Export/CertificationBundleExporter.php` produces the ZIP bundle
handed to an external ISO 27001 / Konzern (group) auditor. It stitches
per-framework coverage CSVs, policy snapshots, evidence indexes and a
holding-level RACI summary into a single archive.

## Intentional auditor-gap markers

Some fields in the bundle are deliberately rendered as explicit gap markers
(`TODO(audit-gap): ...`) rather than silently omitted or backfilled with a
default. The rationale is **auditor-transparency over polished appearance**:
when a regulatorily relevant field has no source-of-truth in the tenant data
(e.g. `Tenant.holdingRaci` does not yet exist as a column, so the Konzern
RACI markdown cannot be rendered from real data), the bundle emits a visible
`TODO(audit-gap)` marker. The auditor immediately sees that the tenant has
not populated this metadata, instead of consuming a default value that
masks the gap and triggers a finding only after follow-up questions.

These markers carry the `TODO(audit-gap)` tag (distinct from a regular
`TODO:`) so the planned CI gate `scripts/quality/check_todo_growth.py` can
whitelist them: the tag signals "intentional output, not technical debt".
Do not silence or rewrite these markers without first implementing the
backing data field (e.g. `Tenant.holdingRaci`) and switching the renderer
to consume it.

Current markers:

| Location | Marker | Resolves when |
|---|---|---|
| `buildKonzernRaciMarkdown()` | `TODO(audit-gap): Holding-RACI nicht konfiguriert.` | `Tenant.holdingRaci` (or equivalent metadata field) is added and populated. |
