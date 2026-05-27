# Terminology: Tenant (System) vs. Organisation (UI)

- **Date:** 2026-05-27
- **Status:** Accepted
- **Scope:** Glossary / naming convention across code, routes, DB, translations

## Context

The codebase historically used three competing terms for the same concept (the data-isolation unit that maps 1:1 to a row in the `tenant` DB table):

- `Tenant` — technical multi-tenancy term (SaaS jargon). Used in 36 PHP classes, all routes (`/admin/tenants/*`), all DB columns (`tenant_id`), Doctrine entity `App\Entity\Tenant`.
- `Mandant` — German legal/commercial term (Anwaltsmandant, Steuerberater). Lingered in 19 legacy translation keys.
- `Organisation` (DE) / `Organization` (EN) — ISO 27001 Cl. 4.1 normative term ("the organization"). Recently used in 2 form classes (`OrganisationInfoType`, `OrganisationScopeStep`) and the `/admin/tenants/{id}/organisation-context` sub-route.

This inconsistency surfaced in user-facing flows (DE users saw "Mandant" + "Organisation" + "Tenant" mixed in the same screen) and in audit conversations (auditors expected ISO 27001 "the organization" wording).

## Decision

System-side keeps the **`Tenant`** name. User-facing labels are unified to **`Organisation`** (German) / **`Organization`** (English, US ISO 27001 spelling).

| Layer | Term | Rationale |
|---|---|---|
| Database tables/columns (`tenant`, `tenant_id`) | `Tenant` | Existing schema — no migration churn justifiable. |
| Doctrine entities + repositories (`App\Entity\Tenant`, `TenantRepository`) | `Tenant` | Code-side convention; same as routes and DB. |
| HTTP routes (`/admin/tenants`, `/admin/tenants/{id}/...`) | `tenants` | Backwards compatibility — bookmarks, docs, customer-shared URLs. |
| Form types (`TenantType`, `TenantComplianceSettingsType`, …) | `Tenant` | Code-side convention. |
| Edge-case form types that describe ISO Cl. 4.1 profile (`OrganisationInfoType`, `OrganisationScopeStep`) | `Organisation` | Semantically distinct — these forms capture the ISO 27001 "context of the organization" not the tenant-row metadata. |
| User-facing UI labels (DE) | `Organisation` | German preference (Org / Organisation); compatible with ISO terminology. |
| User-facing UI labels (EN) | `Organization` | US-ISO English (ISO 27001:2022 original). |
| `Mandant` | (banned in new code) | Legacy German legal term — alienates EN-market customers, contradicts ISO. |

### Exempted terms (keep as-is)

These technical/product references stay with their original term because they reference a specific external product or domain concept:

- `Azure Tenant ID` (Microsoft Entra ID product name)
- `Mandantenfähigkeit` / `Mandantentrennung` (BSI C5 / ISO 27017 multi-tenancy isolation — technical cloud-security control, distinct from "the customer's organization")
- `Tenant.settings`, `TenantPolicySetting`, `TenantBranding` (PHP class names referenced in admin debug labels)
- `Tenant-Isolation`, `Cross-Tenant` (cloud-security architecture terms in C5 wizard)
- `tenant_id` column references in JSON-validation help text (debug-context for admins)
- `{{ tenant.legal_name }}` Twig variables in policy templates (Symfony property access)

## Consequences

### Positive

- DE users see consistent "Organisation" terminology aligned with ISO 27001 Cl. 4.1.
- EN users see "Organization" matching the ISO 27001:2022 English original.
- No route changes → no bookmark / customer-link breakage.
- No DB migration → zero downtime.
- Code-side `Tenant` stays — minimal churn (~3 hours of translation sweep).

### Trade-off

- Developers must remember the rule: code/DB/routes = `Tenant`, UI labels = `Organisation`/`Organization`. The split is intentional and documented (this ADR), but it does create a context-switch overhead.
- Twig templates referencing `tenant.X` variables continue to use `Tenant` as the property holder. This is acceptable because Twig property paths are not user-visible.

### Migration

- Translation values updated: `translations/*.de.yaml` (≈110 labels) and `translations/*.en.yaml` (≈45 labels) swept on 2026-05-27 (commit on `main`, local-only).
- Translation **keys** unchanged (e.g. `nav.tenants` still resolves) — only the human-readable values shift.
- Form/Step classes `OrganisationInfoType` + `OrganisationScopeStep` retained intentionally; they map to the ISO Cl. 4.1 "context of the organization" surface, not to tenant administration.

## References

- ISO/IEC 27001:2022 Clause 4.1 "Understanding the organization and its context"
- Memory note: `feedback-tenant-organisation-term` (local memory in `.claude/projects/.../memory/`)
- B1 backlog item in `var/junior-isb-audit/BACKLOG_2026-05-25.md` (resolved)
