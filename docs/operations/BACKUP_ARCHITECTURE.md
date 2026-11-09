# Backup Architecture — Technical Reference

**Audience:** Developers  
**Ops runbook:** [DISASTER_RECOVERY.md](DISASTER_RECOVERY.md)

---

## Table of Contents

1. [Backup Format 2.0 Structure](#1-backup-format-20-structure)
2. [Tenant Scope Modes](#2-tenant-scope-modes)
3. [Entity Coverage (PRODUCTIVE_ENTITIES)](#3-entity-coverage-productive_entities)
4. [Dependency-Order Restore](#4-dependency-order-restore)
5. [ManyToMany Second-Pass Restore](#5-manytomany-second-pass-restore)
6. [Foreign Key Check Disable](#6-foreign-key-check-disable)
7. [SHA-256 Integrity Seal](#7-sha-256-integrity-seal)
8. [SystemSettings Encryption](#8-systemsettings-encryption)
9. [Excluded Fields](#9-excluded-fields)
10. [Schema Compatibility Table](#10-schema-compatibility-table)
11. [File Reference Handling (ZIP)](#11-file-reference-handling-zip)
12. [Key Classes and Methods](#12-key-classes-and-methods)

---

## 1. Backup Format 2.0 Structure

Backups are produced by `BackupService::createBackup()` and serialized by `BackupService::saveBackupToFile()`.

### Top-Level JSON Structure

```json
{
  "metadata": {
    "version":          "2.0",
    "app_version":      "3.16.1",
    "schema_version":   "20260420140000",
    "php_version":      "8.4.x",
    "symfony_version":  "7.4.x",
    "doctrine_version": "3.x.x",
    "files_included":   true,
    "file_count":       42,
    "created_at":       "2026-04-24T03:00:00+00:00",
    "scope_type":       "global",
    "tenant_scope":     [],
    "sha256":           "<hex-hash-of-data-section>",
    "skipped_global_entities": ["Role", "Permission"]
  },
  "data": {
    "Tenant":       [ { "id": 1, "name": "ACME GmbH", ... } ],
    "User":         [ { "id": 1, "email": "admin@...", ... } ],
    "Risk":         [ ... ],
    "..."
  },
  "statistics": {
    "Tenant":  1,
    "User":    12,
    "Risk":    47,
    "..."
  },
  "warnings": []
}
```

### Metadata Fields

| Field | Type | Description |
|-------|------|-------------|
| `version` | string | Backup format version (`"1.0"` or `"2.0"`) |
| `app_version` | string | App version from `composer.json` |
| `schema_version` | string | Last applied Doctrine migration timestamp |
| `php_version` | string | PHP version at backup creation time |
| `symfony_version` | string | Symfony version at backup creation time |
| `doctrine_version` | string | Doctrine ORM version |
| `files_included` | bool | `true` when physical files are embedded in the ZIP |
| `file_count` | int | Number of embedded files |
| `created_at` | ISO 8601 | Backup creation timestamp |
| `scope_type` | string | `"global"`, `"single"`, or `"holding"` |
| `tenant_scope` | int[] | List of tenant IDs included; empty = global |
| `sha256` | string | SHA-256 hash of `json_encode($backup['data'])` |
| `skipped_global_entities` | string[] | Only present in scoped backups; entities without tenant field that were omitted |

### ZIP Archive Layout (format 2.0 with files)

```
backup_2026-04-24_03-00-00.zip
├── backup.json             # full JSON as above
└── files/
    ├── documents/          # Document.filePath          (from var/uploads/documents/)
    ├── document_versions/  # DocumentVersion.filePath   (from var/uploads/documents/)
    ├── training_materials/ # Training.materialFiles[]   (from var/uploads/training-materials/)
    ├── tenant_logos/       # Tenant/TenantBranding.logoPath (from public/uploads/tenants/)
    └── user_avatars/       # User.profilePicture            (from public/uploads/users/)
```

Not in the archive: profile pictures (`User.profilePicture`, `public/uploads/`).

When no files exist on disk, the service falls back to a legacy `.json.gz` (or `.json` when ext-zlib is unavailable).

### Format Detection

ZIP archives are detected by magic bytes (`PK\x03\x04`), not by file extension. This makes detection robust for files renamed or uploaded without extension.

---

## 2. Tenant Scope Modes

| `scope_type` | Meaning | Which entities are backed up |
|---|---|---|
| `"global"` | `tenantScope = null` | All entities across all tenants |
| `"single"` | Single tenant, no subsidiaries | Only entities where `tenant_id IN (tenantId)` |
| `"holding"` | Parent tenant with subsidiaries | Entities where `tenant_id IN (parent + all subsidiaries)` |

**Resolution logic** (`BackupService::resolveScopeType()`):

```php
if ($tenantScope === null)                                return 'global';
if ($tenantScope->getAllSubsidiaries() !== [])             return 'holding';
return 'single';
```

**Organisation records** (`Tenant`) of the scope are always included in scoped backups (`id IN scope`). They hold every organisation setting — branding and logo, TLP default, authorities/CSIRT, on-call, DPO data, methodology, maturity target, the `settings` JSON (e.g. location map) and active modules. Organisation backups made before 2026-11 do not contain them.

**Entities without tenant association** (e.g., `Role`, `Permission`, `SystemSettings`) are silently skipped in scoped backups and listed in `metadata.skipped_global_entities`. They are only included in global backups.

**Restore scope filtering** is performed in `RestoreService::filterEntitiesByScope()`: each serialized entity's `tenant_id` key (stored as `{"id": X}`) is checked against `$targetScopeIds`. Entities with no `tenant_id` key (global entities) are always included, except `Tenant` rows, which are kept only when their own `id` is in scope. A scoped restore always updates the organisation record in place (never deletes or replaces it, whatever the existing-data strategy), keeps the live `@ORM\Version` value of existing rows, pins the identity fields of an existing organisation (`code`, `azureTenantId`, `isActive`, `status`, `isCorporateParent`, `parent`) to their current values (the SHA256 seal can be recomputed, so a crafted backup must not rebind SSO or move the organisation into another group), and a scoped `clear_before_restore` never empties a table without tenant association. An organisation backup restored without a target scope (instance-wide restore, setup wizard) uses the backup's recorded `tenant_scope` as scope, so it never clears other organisations' data.

---

## 3. Entity Coverage (PRODUCTIVE_ENTITIES)

`BackupService::PRODUCTIVE_ENTITIES` defines which entities are backed up and in which order. This order is also used as the basis for `RestoreService::orderEntitiesByDependency()`.

### Completeness Promise (Gate 43, since 2026-05)

**Every entity class file under `src/Entity/*.php` MUST be either listed in `BackupService::PRODUCTIVE_ENTITIES` or in `BackupService::EXCLUDED_FROM_BACKUP` (with an inline rationale).** This invariant is enforced by:

- `scripts/quality/check_backup_entity_coverage.py` (Gate 43 in `.github/workflows/ci.yml`)
- `tests/Quality/CheckBackupEntityCoverageTest.php` (PHPUnit smoke test)
- A reflection-based assertion inside `tests/Service/BackupServiceTest.php`

When you add a new entity in `src/Entity/`, the CI gate will fail until you have explicitly classified it. There is no "default include" or "default exclude" — the decision must be explicit, which prevents silent backup gaps.

**EXCLUDED_FROM_BACKUP** (entities deliberately skipped, reason inline in the constant):

| Entity | Reason |
|---|---|
| `UserTenantAssignment` | Stub, schema migration deferred |
| `IndustryBaseline`, `IndustryPresetBundle`, `ElementaryThreat`, `IctProviderLibrary`, `CertificateCoverageRule` | Global seeded catalogues, re-loadable via `Load*` commands / fixtures |
| `ObligationNotice` | Reminder log, rebuilt by the next `app:send-reminders` run |
| `PortfolioSnapshot`, `ReuseTrendSnapshot` | Derived trend caches |
| `ApiToken` | Security credential (token hashes), re-create via `app:api-token:create` |

**Implicit coverage** (outside PRODUCTIVE_ENTITIES, via flags):

- `AuditLog` — `$includeAuditLog` (`--include-audit-log`, default on)
- `UserSession` — `$includeUserSessions` (`--include-user-sessions`, default off)

### Entity list

The list itself (129 entries as of 3.16.1) lives only in `BackupService::PRODUCTIVE_ENTITIES`, grouped
by comment (core, config, ISMS core, BCM, compliance, privacy, documents/training, audits, DORA, context,
operations, workflows, reports, user prefs). Restore writes only names from that list
(`BackupService::restorableEntityNames()`); anything else in a file is dropped with a warning.

---

## 4. Dependency-Order Restore

`RestoreService::orderEntitiesByDependency()` sorts entities by a numeric priority before restore. Lower number = restored first.

This ensures that when entity A has a ManyToOne reference to entity B, entity B's rows are already in the database when entity A is inserted.

The priority map is `$priorityOrder` in `RestoreEntityWriter::orderEntitiesByDependency()`
(`Tenant` 1, `Role` 2, `Permission` 3, `User` 4, then configuration, catalogues, ISMS core, BCM, privacy,
documents, audits, workflows, reports; `AuditLog`/`UserSession` last). A new entity with ManyToOne
references needs an entry there.

**Unknown entities** (not in the priority map) default to priority 50.

**Clear-before-restore order:** `array_reverse($orderedEntities)` — entities with the fewest dependants are deleted first to avoid FK violations.

---

## 5. ManyToMany Second-Pass Restore

### Problem

Doctrine's `EntityManager::flush()` only persists single-valued associations (`ManyToOne`, `OneToOne`). It does not automatically write to ManyToMany pivot tables for existing entities, and DQL DELETE does not touch pivot tables.

### Solution: Two-Pass Restore

**First pass** (inside `restoreEntity()`):
- Restore all scalar fields
- Resolve and set single-valued associations (ManyToOne, OneToOne) by looking up target entities by ID
- Flush after each entity batch

**Second pass** (inside `restoreManyToManyAssociations()`):
- Runs **after all entities are flushed** (all IDs exist in DB)
- Uses direct DBAL inserts into pivot tables (`INSERT IGNORE INTO pivot (owner_col, target_col) VALUES (?, ?)`)
- Batch-inserts in groups of 500 to avoid oversized SQL statements
- Only processes **owning-side** associations (inverse side shares the same physical pivot table)
- Skipped in dry-run mode (transaction is rolled back anyway)

### Pivot Table Cleanup on clear-before-restore

When `clear_before_restore = true`, pivot tables are cleaned **before** entity tables:

1. `clearPivotTables()` runs first — discovers all `MANY_TO_MANY` owning-side associations via `ClassMetadata` and issues `DELETE FROM \`pivot_table\`` for each
2. Then entity rows are deleted
3. This prevents orphan FK rows in pivot tables that would block subsequent INSERTs

For **tenant-scoped clears**, global pivot deletes are skipped (would remove other tenants' rows). Instead, the per-entity scoped DQL DELETE handles cascade cleanup via `SET FOREIGN_KEY_CHECKS = 0`.

---

## 6. Foreign Key Check Disable

During restore, MySQL foreign key checks are temporarily disabled to allow inserting rows in any order without FK constraint errors:

```php
$connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
// ... restore all entities ...
$connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
```

This is applied both for real restores and dry-run mode, and is always re-enabled in `finally`-equivalent blocks (error handlers + rollback path + commit path).

**Platform guard:** `ALTER TABLE ... AUTO_INCREMENT = 1` (reset after clearing) is only executed on MySQL/MariaDB platforms. The platform is detected via:

```php
$platform = $connection->getDatabasePlatform()::class;
$isMysql = stripos($platform, 'MySQL') !== false || stripos($platform, 'MariaDB') !== false;
```

**Note:** The application is MySQL/MariaDB-only by design.

---

## 7. SHA-256 Integrity Seal

```php
// In BackupService::createBackup(), computed AFTER all data is serialized:
$backup['metadata']['sha256'] = hash('sha256', (string) json_encode($backup['data']));
```

The hash covers only the `data` section (not `metadata`) to avoid a chicken-and-egg problem where the hash itself would be part of the hashed content.

**Verification is automatic.** `RestoreService::verifyIntegrity()` runs at the very start of `restoreFromBackup()` (before validation). On mismatch it throws `RuntimeException('Backup integrity check failed: sha256 mismatch (expected …, got …)')`. Legacy backups without `sha256` emit a warning but continue, so old backups remain restorable. Ops can still re-compute the hash manually as a smoke test (see [DISASTER_RECOVERY.md §5](DISASTER_RECOVERY.md#5-integritätsprüfung-sha-256)).

---

## 8. SystemSettings Encryption

When `BackupEncryptionService` is wired (injected as optional dependency into `BackupService`), sensitive `SystemSettings` values are encrypted before being written to the backup.

### Encryption Spec

| Property | Value |
|---|---|
| Cipher | AES-256-GCM |
| Key derivation | `hash('sha256', $APP_SECRET, true)` — 32 raw bytes |
| IV | 96-bit random (`random_bytes(12)`) |
| Auth tag | 128-bit GCM tag |
| Envelope format | JSON array with `__encrypted: true`, `cipher`, `iv` (base64), `tag` (base64), `ciphertext` (base64) |

### Sensitive Key Detection (`BackupEncryptionService::isSensitiveKey()`)

A setting key is considered sensitive when it contains any of these substrings (case-insensitive):
`secret`, `password`, `private_key`, `api_key`, `client_secret`, `smtp_pass`, `oauth`

### Decryption on Restore

`RestoreService` automatically decrypts encrypted envelopes before entity hydration. The envelope is detected via `BackupEncryptionService::isEncrypted()` (checks for the `__encrypted: true` marker), and the original plaintext is restored via `decryptValue()`. If the restoring host's `APP_SECRET` does not match the source host's, decryption fails with a `RuntimeException('Encrypted secret could not be decrypted — ensure APP_SECRET matches the source environment, or replace the secret manually after restore')`. In that case, ops can skip encrypted SystemSettings via `skip_entities` option or copy `APP_SECRET` from the source environment.

---

## 9. Excluded Fields

The following fields are **never serialized** into backups regardless of entity:

```php
private const array EXCLUDED_FIELDS = [
    'password',
    'salt',
    'mfaSecret',
    'resetToken',
    'resetTokenExpiresAt',
];
```

This is a security feature. Users that already exist in the target database keep their password. Users the
restore creates get the password entered in the restore form (`admin_password` option,
`RestoreSecretsHandler::applyAdminPasswordToUser()`); without it they cannot log in until an admin sets one.
The CLI `app:backup:restore` has no such option. MFA secrets (`MfaToken`) are stored encrypted with
`MFA_ENCRYPTION_KEY` (fallback `APP_SECRET`) and need the same key on the target host.

---

## 10. Schema Compatibility Table

| Backup Format Version | Supported by RestoreService | Behavior |
|---|---|---|
| No version field (legacy) | Yes | Treated as `1.0`; warning emitted; files not restored |
| `1.0` | Yes | JSON-only; no file restore; no `schema_version` check |
| `2.0` | Yes | Full ZIP + files; `schema_version` advisory check |
| `3.0+` (hypothetical) | No | Hard reject: `"Unsupported backup version: 3.0"` |

The version check is a **major-version guard** (exact string match against `SUPPORTED_VERSIONS = ['1.0', '2.0']`). Any version not in that array is rejected.

**Schema version** (the Doctrine migration timestamp) is compared non-blockingly:

```
Schema version mismatch: backup was created with schema "20260418000000",
current schema is "20260424120000". Some fields may be missing or incompatible.
```

Missing fields in restored entities are handled by `missing_field_strategy`:
- `use_default` (default): set to `null` or entity-specific default
- `skip_field`: leave unchanged
- `fail`: throw `RuntimeException`

---

## 11. File Reference Handling (ZIP)

### File Entities

`BackupService::FILE_REFERENCE_FIELDS` and `FILE_LIST_FIELDS`:

| Entity | Field | Source on disk | ZIP subfolder |
|---|---|---|---|
| `Document` | `filePath` (`/uploads/documents/<file>`) | `var/uploads/documents/` | `files/documents/` |
| `DocumentVersion` | `filePath` | `var/uploads/documents/` | `files/document_versions/` |
| `Training` | `materialFiles[].filename` | `var/uploads/training-materials/` | `files/training_materials/` |
| `Tenant`, `TenantBranding` | `logoPath` (`uploads/tenants/logo.png`) | `public/uploads/` | `files/tenant_logos/` |
| `User` | `profilePicture` (`uploads/users/avatar_7_x.png`) | `public/uploads/` | `files/user_avatars/` |

Evidence paths are resolved through `App\Service\Upload\ProtectedUploads`, which still finds a file left
in the old `public/uploads/` folder. `tests/Service/BackupArchiveExtractionTest` fails when a `ProtectedUploads`
area has no entry.

### Packaging (saveBackupToFile)

1. `collectFileReferences()` scans the serialized data for the above fields
2. `filterExistingFiles()` keeps only paths that actually exist on disk
3. If at least one file exists → `saveAsZip()` is called; otherwise → `saveAsJson()` (legacy .json.gz)
4. ZIP entry paths are sanitized by `sanitizeZipEntryPath()` — rejects paths with `..`, absolute segments, or non-alphanumeric characters

### Extraction

1. `loadBackupFromFile()` / `loadFromZip()` only read `backup.json`; preview, validation and dry run
   write nothing.
2. After the data has been committed, the restore calls `extractArchiveFiles()`: only the folders in
   `ARCHIVE_FILE_FOLDERS` are written, with path-traversal protection and a size cap
   (`MAX_ARCHIVE_TOTAL_BYTES`, 4 GiB). `documents/` and `document_versions/` go to
   `var/uploads/documents/`, `training_materials/` to `var/uploads/training-materials/`, logos to
   `public/uploads/tenants/`, profile pictures to `public/uploads/users/`. The resolved target must stay inside its base folder.

---

## 12. Key Classes and Methods

| Class | Location | Responsibility |
|---|---|---|
| `BackupService` | `src/Service/BackupService.php` | Create, save, load, list backups |
| `RestoreService` | `src/Service/RestoreService.php` | Facade: validate, preview, restore |
| `Restore\*` | `src/Service/Restore/` | `RestoreValidator` (version, SHA-256), `RestoreEntityWriter` (order, hydration, M2M), `RestoreDataPurger` (scope filter, clear), `RestoreSecretsHandler`, `RestoreTenantGuard` |
| `BackupEncryptionService` | `src/Service/BackupEncryptionService.php` | AES-256-GCM envelope for sensitive fields |
| `AdminBackupController` | `src/Controller/AdminBackupController.php` | HTTP endpoints for backup/restore UI |

### BackupService — Key Methods

| Method | Description |
|---|---|
| `createBackup(bool $includeAuditLog, bool $includeUserSessions, bool $includeFiles, ?Tenant $tenantScope)` | Main backup creation entry point |
| `saveBackupToFile(array $backup, ?string $filename)` | Persist backup to `.zip` or `.json.gz` in `var/backups/` |
| `loadBackupFromFile(string $filepath)` | Load and auto-detect ZIP / .gz / .json format |
| `listBackups()` | List available backups in `var/backups/` |
| `resolveScopeIds(?Tenant $tenantScope)` | Expand holding tenant to list of all subsidiary IDs |

### RestoreService — Key Methods

| Method | Description |
|---|---|
| `validateBackup(array $backup)` | Format version + data structure check; returns `['valid', 'errors', 'warnings']` |
| `restoreFromBackup(array $backup, array $options, ?Tenant $targetTenantScope)` | Full restore with FK disable, two-pass M2M, transaction management |
| `getRestorePreview(array $backup)` | Counts without committing |
| `orderEntitiesByDependency(array $entityNames)` | Sort by FK dependency priority |

### AdminBackupController — HTTP Endpoints

The controller requires `ROLE_ADMIN`; every action checks `TenantScopedAdminVoter::ADMIN_OWN_TENANT`
(`ROLE_SUPER_ADMIN` everywhere, `ROLE_ADMIN` only inside its own organisation tree). Create and restore run
as async jobs.

| Route | Method | Description |
|---|---|---|
| `/admin/data/backup` | GET | Backup list UI |
| `/admin/data/backup/create` | POST | Create backup (CSRF) |
| `/admin/data/backup/progress/{id}` | GET | Job progress |
| `/admin/data/backup/download/{filename}` | GET | Download backup file |
| `/admin/data/backup/upload` | POST | Upload external backup (CSRF) |
| `/admin/data/backup/validate/{filename}` | POST | Validate backup structure |
| `/admin/data/backup/preview/{filename}` | GET | Preview restore statistics |
| `/admin/data/backup/restore/{filename}` | POST | Execute restore (CSRF) |
| `/admin/data/backup/delete/{filename}` | POST/DELETE | Delete backup file |
