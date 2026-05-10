# Security Policy

Little ISMS Helper is itself an ISMS-/compliance-tool — it stores risk
registers, audit findings, control implementations, incident timelines
and (depending on tenant configuration) personal data. We treat
vulnerability reports accordingly.

## Supported Versions

The latest stable release receives security fixes. Older minor versions
are not supported. The release cadence is documented in
[`CONTRIBUTING.md`](CONTRIBUTING.md) §"Release Cadence".

| Version | Supported          |
| ------- | ------------------ |
| latest  | :white_check_mark: |
| older   | :x:                |

## Reporting a Vulnerability

**Please do not open public issues for security findings.**

Use one of the two private channels:

1. **GitHub Private Vulnerability Reporting**
   <https://github.com/moag1000/Little-ISMS-Helper/security/advisories/new>

2. **Email** — `moag2000s@gmail.com`
   Subject prefix: `[security] Little-ISMS-Helper`

Please include:

- Affected version / commit SHA
- Reproduction steps or proof-of-concept
- Expected vs. observed behaviour
- Impact assessment (data exposure, privilege escalation, tenant
  isolation break, etc.)
- Optional: a suggested fix or mitigation

### Response Timeline

- **Acknowledgement** — within 72 hours
- **Triage + severity assessment** — within 7 days (CVSS 4.0)
- **Fix or mitigation plan** — communicated within 14 days
- **Coordinated disclosure** — public advisory once a patched release is
  available, normally within 30–90 days depending on severity

### Severity Classification

We follow the CVSS 4.0 base score, contextualised for a multi-tenant
ISMS application:

| Rating       | CVSS      | Examples                                                                 |
| ------------ | --------- | ------------------------------------------------------------------------ |
| Critical     | 9.0–10.0  | Unauthenticated RCE, full DB dump, tenant-isolation break               |
| High         | 7.0–8.9   | Authenticated privilege-escalation, mass data exposure                  |
| Medium       | 4.0–6.9   | Self-XSS, info disclosure, missing security headers                     |
| Low          | 0.1–3.9   | Defence-in-depth gaps, theoretical vectors                              |
| Informational| 0.0       | Hardening recommendations                                               |

## Out-of-Scope

- Issues on a fork or local installation that do not affect upstream
- Vulnerabilities requiring physical access or compromised admin
  credentials
- Theoretical findings without a viable attack path
- Volume-based DoS without amplification

## Recognition

Reporters who responsibly disclose are credited in the corresponding
release notes (unless they request anonymity).

## Bug Bounty

This is a community-driven open-source project. There is no monetary
bounty programme. We are happy to provide written acknowledgement and
list reporters in the project's `Hall of Fame` once one exists.

---

## Security Hardening — v3.5 Audit Notes

### CSRF Hardening (v3.5)

16 form endpoints and 6 controller actions were hardened in the v3.5 cycle:

- All state-changing form endpoints now use Symfony 7.1+
  `#[IsCsrfTokenValid('token_id')]` attribute.
- Legacy `$this->isCsrfTokenValid()` calls were replaced with the attribute
  pattern across the affected controllers.
- WebTestCase coverage was added for each hardened endpoint; CSRF token
  generation requires an active session (`GET` before `getToken()`).

### Cross-Tenant Validation — CommentController (V4-LB-9)

`CommentController` performs two checks before accepting a comment submission:

1. **Entity existence check:** The referenced parent entity (Risk, Incident,
   Asset, etc.) must exist in the database.
2. **Tenant scope check:** The parent entity's `tenant_id` must match the
   authenticated user's active tenant context.

This prevents cross-tenant comment injection where an attacker holding a valid
session in Tenant A could reference an entity ID from Tenant B.

Implementation: `src/Controller/CommentController.php` via
`TenantContext::assertEntityBelongsToCurrentTenant()`.

### AuditLogger::logBulk() — ISO 27001 Clause 7.5.3

Bulk operations (mass-delete, bulk-import, batch-status-change) must use
`AuditLogger::logBulk()`. This method:

- Writes one batch-level audit entry with a UUIDv4 `batch_id`.
- Writes one per-entity audit entry for each affected record.
- Returns the `batch_id` for downstream correlation.

Direct `EntityManager::executeStatement()` calls that bypass Doctrine lifecycle
events are prohibited unless an explicit `AuditLogger::logBulk()` call follows.

```php
$batchId = $this->auditLogger->logBulk(
    action: 'bulk_delete',
    entityType: 'Risk',
    entityIds: $deletedIds,
    context: ['reason' => 'user-initiated bulk delete']
);
```

### QuickFix-Guard Security Options

The `/quick-fix` operator UI is protected by `QuickFixGuard`. Available
hardening options (via environment variables):

| Variable | Purpose |
|---|---|
| `QUICK_FIX_TOKEN` | Bearer token required in `Authorization` header |
| `QUICK_FIX_IP_ALLOWLIST` | CIDR-based IP allowlist (e.g. `10.0.0.0/8`) |
| `QUICK_FIX_DEV_ONLY` | Restrict to `APP_ENV=dev` only |

Recommended for production: set both `QUICK_FIX_TOKEN` and
`QUICK_FIX_IP_ALLOWLIST`. See `docs/user-guide/QUICK_FIX.md` for full
configuration reference.

### Tenant Isolation — Doctrine SQLFilter

All tenant-scoped entities are filtered at the Doctrine DBAL layer via
`TenantFilter` (a Doctrine `SQLFilter`). This filter appends
`AND tenant_id = :tenant_id` to every SELECT query for entities that
implement `TenantAwareInterface`.

The filter is activated automatically in `TenantContext::setCurrentTenant()`
and cannot be bypassed within normal application code. Administrative
operations that require cross-tenant access must explicitly disable the filter
via `TenantContext::runWithoutTenantFilter(callable $callback)`, which logs
the access to the audit trail.
