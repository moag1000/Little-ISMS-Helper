# ADR-0011: HMAC-Chained Tamper-Evident Audit Log

**Status:** Accepted  
**Date:** 2026-03-15  
**Deciders:** moag1000  
**Tags:** audit-log, security, hmac, iso-27001, tamper-evidence, integrity

---

## Context

ISO 27001:2022 Clause 7.5.3 requires that documented information be protected against loss of
integrity. For an ISMS application this means the audit trail itself must be demonstrably tamper-
evident: if a system administrator (or a compromised account) modifies or deletes an audit log
entry, this must be detectable.

Regulatory requirements that reference audit log integrity:

- **ISO 27001:2022 Cl. 7.5.3(c):** "Protection and control" — information must be protected
  against improper alterations.
- **NIS2 (EU 2022/2555) Art. 21(2)(j):** Incident detection and response requires trustworthy
  event records.
- **GDPR Art. 5(2) accountability:** Processing of personal data must be demonstrably compliant —
  an audit trail that can be silently altered undermines accountability.
- **BSI IT-Grundschutz ORP.4 (A10):** Revision-safe logging of access events.

A simple database table with `INSERT` permissions is insufficient: a database admin with `UPDATE`
or `DELETE` rights can silently alter audit entries. Database-level audit tables (vendor-specific
solutions) were considered but:
1. They are database-vendor specific (MySQL 8 ≠ PostgreSQL 16 ≠ MariaDB 10.11).
2. They require DBA access to configure and verify — not available on shared hosting.
3. They do not survive a database restore that overwrites the audit tables.

### Options evaluated

| Option | Tamper evidence | Portability | Hosting compatible |
|---|---|---|---|
| Plain INSERT-only audit table | None (admin can delete rows) | High | Yes |
| DB vendor audit plugin | Medium (DB-level log) | Low (vendor-specific) | No (shared hosting) |
| Write-once append-only log file | Medium (file ACL) | Medium | No (shared hosting) |
| **HMAC chain in audit table** | High (cryptographic) | High | Yes |
| Blockchain / distributed ledger | Very high | Very low | No |

---

## Decision

**Implement HMAC-SHA256 chaining in the `AuditLog` table.** Each audit entry contains:

- `id` — auto-increment primary key
- `tenant_id` — multi-tenancy scope
- `entity_class`, `entity_id`, `action` — what changed
- `changed_by_user_id`, `changed_at` — who/when
- `before_data`, `after_data` — JSON diffs (before/after field values)
- `batch_id` — groups bulk operations (UUIDv4, returned by `AuditLogger::logBulk()`)
- `hmac` — HMAC-SHA256 of the concatenated fields of this entry + the `hmac` of the previous
  entry for the same `tenant_id`.

The chain is tenant-scoped: each tenant has an independent chain starting from a genesis entry.
The chain key is stored in `APP_AUDIT_HMAC_KEY` environment variable (minimum 32 bytes,
randomly generated per installation).

**Verification:** `php bin/console app:audit:verify-chain [--tenant-id=X]` traverses the chain
and reports any entry where the stored HMAC does not match the recomputed HMAC. This command
is suitable for inclusion in automated nightly health checks.

**`AuditLogger` service** is the exclusive write path. All entity changes MUST go through:
- `AuditLogger::log($entity, $action, $before, $after, $user)` for single-entity changes.
- `AuditLogger::logBulk($entities, $action, $user)` for bulk operations (returns `batch_id`).

Raw `executeStatement()` calls that bypass Doctrine lifecycle events are explicitly prohibited in
the security checklist. `AuditLogger::logBulk()` satisfies ISO 27001 Cl. 7.5.3 for bulk
operations: one batch-entry + N per-entity entries, all linked by `batch_id`.

---

## Consequences

### Positive

- **Cryptographic tamper evidence:** Deletion or modification of any audit entry breaks the HMAC
  chain at that point. The verification command detects it.
- **Hosting portable:** Pure PHP + SQL — no DB vendor plugin, no file system dependency.
- **Audit-log defensibility:** An ISO 27001 auditor can run `app:audit:verify-chain` during an
  internal audit to produce cryptographic evidence that the audit trail has not been tampered with.
- **Batch traceability:** `batch_id` groups bulk operations. A single bulk-delete of 50 risks
  produces 51 audit entries (1 batch + 50 entity), all traceable to the initiating user and
  timestamp.

### Negative

- **Key rotation complexity:** Rotating `APP_AUDIT_HMAC_KEY` requires re-hashing the entire chain
  with the new key (a maintenance operation that has no automated tooling yet). Key rotation
  should be avoided unless the key is compromised.
- **Performance on large chains:** `app:audit:verify-chain` reads all audit entries for a tenant
  sequentially. On installations with millions of entries, this will be slow. An index on
  `(tenant_id, id)` and a `--from-id` pagination option mitigate this.
- **Chain breaks on legitimate bulk operations without batch logging:** Any developer who uses
  raw `executeStatement()` to update entities without calling `AuditLogger` produces a chain
  break. The CI gate `AuditBypassDetectorTest` catches this for new code but cannot retroactively
  catch legacy bypass calls.
- **Key loss is catastrophic:** Without the HMAC key, the chain cannot be verified. Backup the
  `APP_AUDIT_HMAC_KEY` value separately from the database backup — see maintainer handoff doc.

---

## Chain Structure (simplified)

```
Entry 1: hmac = HMAC("genesis|tenant:42|entity:Risk|id:1|action:create|...", key)
Entry 2: hmac = HMAC(entry1.hmac + "|entity:Risk|id:1|action:update|...", key)
Entry 3: hmac = HMAC(entry2.hmac + "|entity:Risk|id:2|action:create|...", key)
```

A gap in the sequence (deleted row) or a modified field value produces a chain break detectable
at the first entry after the tampered one.

---

## References

- `src/Service/AuditLogger.php` — exclusive write path
- `src/Entity/AuditLog.php` — entity definition with `hmac` field
- `src/Command/Audit/VerifyChainCommand.php` — `app:audit:verify-chain`
- `src/Security/AuditBypassDetector.php` (PHPStan rule) — raw query bypass detection
- CLAUDE.md §"Security Checklist" — AuditLogger bulk requirement
- ISO 27001:2022 Cl. 7.5.3 — documented information control
- `docs/onboarding/02-architecture-tour.md` — audit log in architecture overview
