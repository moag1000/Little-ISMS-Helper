# ADR-0002: Multi-Tenancy via `tenant_id` Column + Doctrine Filter

**Status:** Accepted  
**Date:** 2025-10-01 (retroactive documentation)  
**Deciders:** moag1000  
**Tags:** multi-tenancy, doctrine, security, database

---

## Context

Little ISMS Helper serves multiple distinct organisations from a single database instance. Each
organisation ("tenant") requires complete data isolation: a user in Org A must never see, modify,
or delete data belonging to Org B — even if they share the same database.

Common multi-tenancy strategies considered:

| Strategy | Isolation | Operational cost | Adopted? |
|---|---|---|---|
| Separate database per tenant | Hard (DB-level) | High (N connection pools, N migration runs) | No |
| Separate schema per tenant (PostgreSQL) | Hard (schema-level) | Medium | No |
| Shared schema, `tenant_id` discriminator column | Soft (application-enforced) | Low | **Yes** |
| Row-Level Security (PostgreSQL RLS) | Hard (DB-level) | Low, but PostgreSQL-only | Deferred |

The decision was driven by three constraints:
1. **Shared-hosting compatibility** — many target customers run MySQL 8 on cPanel-style hosting
   without multiple databases per virtual host.
2. **Migration simplicity** — `doctrine:migrations:migrate` runs once against a single schema,
   applying to all tenants without per-tenant orchestration.
3. **Audit traceability** — ISO 27001 Cl. 8.1 requires that processing activities be attributed to
   a specific organisation. Embedding `tenant_id` in every row makes audit queries straightforward.

---

## Decision

**Every Doctrine entity carries a non-nullable `tenant_id` (INT) foreign key referencing the
`tenant` table.** A global Doctrine SQL filter (`TenantFilter`) is activated at runtime and
automatically appends `AND e.tenant_id = :tenantId` to every query when a tenant context is active.

`TenantContext` service manages the active tenant, resolved via:
1. The authenticated user's `$user->getTenant()->getId()`.
2. For CLI commands: an explicit `--tenant-id` option or `TENANT_ID` env-var.
3. For setup/bootstrap flows: the filter is temporarily disabled via `TenantContext::disable()`.

Key implementation artefacts:

- `src/Entity/Trait/TenantAwareTrait.php` — reusable trait that adds the `tenant_id` field,
  `getTenant()`, and `setTenant()` methods.
- `src/Service/TenantContext.php` — singleton service; exposes `setTenant()`, `getTenant()`,
  `disable()`, `enable()`.
- `src/Doctrine/TenantFilter.php` — Doctrine SQL filter registered in `config/packages/doctrine.yaml`.
- `src/Security/Voter/TenantIsolationVoter.php` — secondary guard: any `EDIT`/`DELETE` voter
  call verifies `entity->getTenant() === currentTenant` even if the filter is momentarily disabled.

---

## Consequences

### Positive

- **Zero accidental leaks:** Doctrine filter applies globally. Forgetting `WHERE tenant_id = ?`
  in a custom query still falls back to the voter check.
- **Single migration run:** `doctrine:migrations:migrate` handles all tenants simultaneously.
- **Cross-tenant analytics (SUPER_ADMIN):** Disabling the filter via
  `TenantContext::disable()` gives SUPER_ADMIN users a cross-tenant view for group-reporting
  features — no separate query layer needed.
- **Portable:** Works on MySQL 8, MariaDB 10.11, PostgreSQL 16.

### Negative

- **No DB-level hard isolation:** A SQL injection attack or application bug that bypasses the
  Doctrine layer (e.g., a raw `executeQuery()` call that omits `tenant_id`) can leak data. Mitigated
  by the voter layer and a static analysis rule flagging raw queries without tenant scoping.
- **Bloated schema:** Every table carries an extra INT column and index. For 78 entities this adds
  ~150 index pages to the B-tree. Acceptable at current scale (< 100 tenants per instance).
- **Cascade complexity:** Deleting a tenant requires cascading deletes across all 78 entity tables
  in dependency order — see `src/Service/TenantDeletionService.php`.
- **Testing discipline required:** Tests that create entities must call `setTenant()` or use the
  `TenantAwareTestTrait` helper fixture; otherwise PHPUnit fails with a NOT NULL constraint.

---

## Tenant Naming Convention

The database column and PHP property are named `tenant_id` (system term). User-facing labels use
"Organisation" (DE) / "Organization" (EN). The word "Mandant" is banned in UI strings. This is
documented in ADR `docs/decisions/2026-05-27-tenant-organization-terminology.md`.

---

## Security Checklist (from CLAUDE.md)

Every new entity MUST satisfy:
- [ ] `use TenantAwareTrait;` in entity class
- [ ] `tenant_id` NOT NULL constraint in migration
- [ ] Foreign key `REFERENCES tenant(id) ON DELETE CASCADE`
- [ ] `isTransactional(): false` in migration (DDL implicit commit)

---

## References

- `src/Entity/Trait/TenantAwareTrait.php`
- `src/Service/TenantContext.php`
- `src/Doctrine/TenantFilter.php`
- `config/packages/doctrine.yaml` — filter registration
- CLAUDE.md §"Multi-tenancy" and §"Security Checklist"
- `docs/decisions/2026-05-27-tenant-organization-terminology.md`
