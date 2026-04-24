# Backup Architecture — Technical Reference

**Version:** 1.0  
**Audience:** Developers  
**Language:** English  
**Updated:** 2026-04-24  
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
    "app_version":      "1.0.0",
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
├── backup.json          # full JSON as above
└── files/
    ├── documents/       # Document.filePath uploads
    │   └── <uuid>.pdf
    └── tenant_logos/    # Tenant.logoPath uploads
        └── logo.png
```

When no files exist on disk, the service falls back to a legacy `.json.gz` (or `.json` when ext-zlib is unavailable).

### Format Detection

ZIP archives are detected by magic bytes (`PK\x03\x04`), not by file extension. This makes detection robust for files renamed or uploaded without extension.

---

## 2. Tenant Scope Modes

Introduced in commit `2fe2e938` (Prio C — Multi-Tenant-Isolation).

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

**Entities without tenant association** (e.g., `Role`, `Permission`, `SystemSettings`) are silently skipped in scoped backups and listed in `metadata.skipped_global_entities`. They are only included in global backups.

**Restore scope filtering** is performed in `RestoreService::filterEntitiesByScope()`: each serialized entity's `tenant_id` key (stored as `{"id": X}`) is checked against `$targetScopeIds`. Entities with no `tenant_id` key (global entities) are always included.

---

## 3. Entity Coverage (PRODUCTIVE_ENTITIES)

`BackupService::PRODUCTIVE_ENTITIES` defines which entities are backed up and in which order. This order is also used as the basis for `RestoreService::orderEntitiesByDependency()`.

### Complete Entity List (as of 2026-04-24)

| Priority Group | Entity | Notes |
|---|---|---|
| Core (no deps) | `Tenant` | Root entity |
| | `Role` | RBAC |
| | `Permission` | RBAC |
| | `User` | References Tenant |
| | `Person` | |
| | `Location` | |
| | `Supplier` | |
| | `SystemSettings` | Sensitive values AES-encrypted |
| Config | `RiskApprovalConfig` | Phase 8L.F1 |
| | `IncidentSlaConfig` | Phase 8L.F2 |
| | `SupplierCriticalityLevel` | Phase 8 QW-5 |
| | `KpiThresholdConfig` | |
| | `Tag` | FK: Tenant |
| | `EntityTag` | FK: Tag, User |
| ISMS Core | `Asset` | |
| | `Control` | ISO 27001 Annex A (93 controls) |
| | `Risk` | FK: Asset |
| | `RiskAppetite` | |
| | `RiskTreatmentPlan` | |
| | `Incident` | |
| | `Vulnerability` | |
| | `Patch` | |
| | `ThreatIntelligence` | |
| BCM | `BusinessProcess` | |
| | `BusinessContinuityPlan` | |
| | `BCExercise` | |
| | `CrisisTeam` | |
| Compliance | `ComplianceFramework` | |
| | `ComplianceRequirement` | |
| | `ComplianceMapping` | |
| | `ComplianceRequirementFulfillment` | |
| | `MappingGapItem` | |
| GDPR/Privacy | `ProcessingActivity` | |
| | `DataProtectionImpactAssessment` | |
| | `DataBreach` | GDPR Art. 33/34 |
| | `Consent` | |
| | `DataSubjectRequest` | GDPR Art. 15-22; FK: Tenant, User, ProcessingActivity |
| Docs & Training | `Document` | file path stored; file in ZIP |
| | `Training` | |
| Audits & Reviews | `InternalAudit` | |
| | `AuditChecklist` | |
| | `AuditFinding` | H-01; FK: Tenant, InternalAudit, Control, User |
| | `CorrectiveAction` | FK: Tenant, AuditFinding, User |
| | `AuditFreeze` | Tamper-evident (H-01) |
| | `ManagementReview` | |
| DORA | `ThreatLedPenetrationTest` | DORA Art. 26; ManyToMany: AuditFinding |
| Context | `ISMSContext` | |
| | `ISMSObjective` | |
| | `InterestedParty` | |
| | `CorporateGovernance` | |
| Operations | `ChangeRequest` | |
| | `CryptographicOperation` | |
| | `PhysicalAccessLog` | |
| | `FourEyesApprovalRequest` | FK: Tenant, User (requester/approver/reviewer) |
| Workflows | `Workflow` | |
| | `WorkflowStep` | |
| | `WorkflowInstance` | |
| Reports | `ScheduledReport` | |
| | `CustomReport` | |
| | `AppliedBaseline` | FK: Tenant, User |
| | `KpiSnapshot` | FK: Tenant |
| User Prefs | `DashboardLayout` | |
| | `MfaToken` | |
| | `ScheduledTask` | |
| Optional | `AuditLog` | Only if `--include-audit-log` |
| | `UserSession` | Only if `--include-user-sessions` |

**Total entities in a full backup:** up to 54 entity types (+ AuditLog + UserSession).

---

## 4. Dependency-Order Restore

`RestoreService::orderEntitiesByDependency()` sorts entities by a numeric priority before restore. Lower number = restored first.

This ensures that when entity A has a ManyToOne reference to entity B, entity B's rows are already in the database when entity A is inserted.

### Priority Table

| Priority | Entities |
|---|---|
| 1 | Tenant |
| 2 | Role |
| 3 | Permission |
| 4 | User |
| 5–8 | Person, Location, Supplier, SystemSettings |
| 9 | RiskApprovalConfig, IncidentSlaConfig, SupplierCriticalityLevel, KpiThresholdConfig, Tag, EntityTag |
| 10–11 | ComplianceFramework, Control |
| 15–16 | Asset, InterestedParty |
| 20–21 | ComplianceRequirement, ComplianceRequirementFulfillment |
| 25–31 | Risk, RiskAppetite, RiskTreatmentPlan, Incident, Vulnerability, Patch, ThreatIntelligence |
| 35–38 | BusinessProcess, BusinessContinuityPlan, BCExercise, CrisisTeam |
| 40–44 | ProcessingActivity, DPIA, DataBreach, Consent, DataSubjectRequest |
| 45–46 | Document, Training |
| 50–55 | InternalAudit, AuditChecklist, AuditFinding, CorrectiveAction, AuditFreeze, ManagementReview |
| 56 | ThreatLedPenetrationTest |
| 58–60 | ISMSContext, ISMSObjective, CorporateGovernance |
| 62–65 | ChangeRequest, CryptographicOperation, PhysicalAccessLog, FourEyesApprovalRequest |
| 67–68 | ComplianceMapping, MappingGapItem |
| 70–72 | Workflow, WorkflowStep, WorkflowInstance |
| 75–78 | ScheduledReport, CustomReport, AppliedBaseline, KpiSnapshot |
| 80–82 | DashboardLayout, MfaToken, ScheduledTask |
| 90–91 | AuditLog, UserSession (last — logs reference everything) |

**Unknown entities** (not in the priority map) default to priority 50.

**Clear-before-restore order:** `array_reverse($orderedEntities)` — entities with the fewest dependants are deleted first to avoid FK violations.

---

## 5. ManyToMany Second-Pass Restore

Introduced and stabilized in commit `f977043a`.

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

**Note:** The application is MySQL-only by design (platform guard added in commit `f9ddfe33`).

---

## 7. SHA-256 Integrity Seal

Added in commit `03d83abb` (Prio A).

```php
// In BackupService::createBackup(), computed AFTER all data is serialized:
$backup['metadata']['sha256'] = hash('sha256', (string) json_encode($backup['data']));
```

The hash covers only the `data` section (not `metadata`) to avoid a chicken-and-egg problem where the hash itself would be part of the hashed content.

**Verification** is not performed automatically by `RestoreService`. The SHA-256 is intended as a manual integrity check that ops can run before initiating a restore (see [DISASTER_RECOVERY.md §5](DISASTER_RECOVERY.md#5-integritätsprüfung-sha-256)).

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

`RestoreService` does not currently automatically decrypt encrypted envelopes — this is a planned enhancement. Until then, encrypted values stored as `{"__encrypted": true, ...}` are treated as opaque objects. Operators must re-enter sensitive SystemSettings manually after cross-host restore.

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

This is a security feature — credentials must be re-set after restore via the CLI:

```bash
php bin/console app:setup-permissions \
  --admin-email=admin@example.com \
  --admin-password=NewSecurePassword!
```

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

Only two entities carry file paths that are packaged into ZIP backups:

| Entity | Field | ZIP subfolder |
|---|---|---|
| `Document` | `filePath` (e.g., `/uploads/documents/uuid.pdf`) | `files/documents/` |
| `Tenant` | `logoPath` (e.g., `uploads/tenants/logo.png`) | `files/tenant_logos/` |

### Packaging (saveBackupToFile)

1. `collectFileReferences()` scans the serialized data for the above fields
2. `filterExistingFiles()` keeps only paths that actually exist on disk
3. If at least one file exists → `saveAsZip()` is called; otherwise → `saveAsJson()` (legacy .json.gz)
4. ZIP entry paths are sanitized by `sanitizeZipEntryPath()` — rejects paths with `..`, absolute segments, or non-alphanumeric characters

### Extraction (loadFromZip)

1. `backup.json` is extracted and parsed
2. All entries under `files/` are extracted to `public/uploads/` with path-traversal protection:
   - `realpath(public/uploads) + "/" + relativePath` must stay inside `public/uploads/`
3. `metadata._extracted_file_count` is set for callers to read
4. File extraction happens **before** entity restore so that restored `Document.filePath` values immediately resolve to real files

---

## 12. Key Classes and Methods

| Class | Location | Responsibility |
|---|---|---|
| `BackupService` | `src/Service/BackupService.php` | Create, save, load, list backups |
| `RestoreService` | `src/Service/RestoreService.php` | Validate, preview, restore backups |
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

| Route | Method | Auth | Description |
|---|---|---|---|
| `GET /admin/data/backup` | GET | SUPER_ADMIN | Backup list UI |
| `POST /admin/data/backup/create` | POST | ADMIN | Create backup (CSRF protected) |
| `GET /admin/data/backup/download/{filename}` | GET | SUPER_ADMIN | Download backup file |
| `POST /admin/data/backup/upload` | POST | SUPER_ADMIN | Upload external backup (CSRF protected) |
| `POST /admin/data/backup/validate/{filename}` | POST | SUPER_ADMIN | Validate backup structure |
| `GET /admin/data/backup/preview/{filename}` | GET | SUPER_ADMIN | Preview restore statistics |
| `POST /admin/data/backup/restore/{filename}` | POST | ADMIN | Execute restore (CSRF protected) |
| `POST /admin/data/backup/delete/{filename}` | POST | SUPER_ADMIN | Delete backup file |
