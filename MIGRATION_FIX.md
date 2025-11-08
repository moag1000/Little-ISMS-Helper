# Migration Fixes: Multiple Critical Errors

**Date:** 2025-11-07
**Issues:** Multiple critical migration errors preventing database setup
**Status:** ✅ ALL FIXED

## Problem Description

When running the installation instructions from README.md, users encountered **two critical errors**:

### Error 1: Column 'resource' Not Found
```
[error] Migration DoctrineMigrations\Version20251105100001 failed during Execution.
Error: "An exception occurred while executing a query: SQLSTATE[42S22]:
Column not found: 1054 Unknown column 'resource' in 'INSERT INTO'"
```

### Error 2: Missing 'tenant' Table
```
[error] Migration DoctrineMigrations\Version20251107121600 failed during Execution.
Error: "An exception occurred while executing a query: SQLSTATE[HY000]:
General error: 1005 Can't create table `littlehelper`.`asset`
(errno: 150 "Foreign key constraint is incorrectly formed")"
```

## Root Cause Analysis

### Error 1: Column Name Mismatch ('resource' vs 'category')

**Problem:**
- The `Permission` entity uses a column named **`category`** (line 29 in `src/Entity/Permission.php`)
- The migration `Version20251105100001` was using **`resource`** instead
- This mismatch caused INSERT statements to fail

**Files affected:**
- `migrations/Version20251105100001.php` (lines 64, 69, 116)

### Error 1 Sub-Issue: Duplicate Table Creation

**Problem:**
- Two migrations attempted to create the same tables:
  - `Version20251105000004.php` (timestamp: 2025-11-05 00:00:04) - Creates users, roles, permissions
  - `Version20251105100001.php` (timestamp: 2025-11-05 10:00:01) - Tried to create same tables again

**Execution order:**
1. `Version20251105000004` runs first → Creates tables with 'category' column ✓
2. `Version20251105100001` runs second → Tries to INSERT into 'resource' column ✗

This resulted in:
- Tables already existed (from Version20251105000004)
- INSERT statement referenced non-existent 'resource' column
- Migration failed

### Error 2: Missing 'tenant' Table

**Problem:**
- Version20251107121600 tried to add `tenant_id` foreign keys to multiple tables
- Referenced the `tenant` table which **did not exist**
- No migration created the `tenant` table before it was referenced
- MySQL error 150: "Foreign key constraint is incorrectly formed"

**Why this happened:**
- Multi-tenancy is still in development (Phase 6 - Planned)
- Entity classes have `tenant` relationships defined
- Migration was created to add these relationships
- But the `tenant` table creation was missing

**Files affected:**
- `migrations/Version20251107121600.php` (tried to reference non-existent tenant table)
- Missing: Migration to create `tenant` table
- `src/Entity/User.php`, `Asset.php`, `Risk.php`, `Incident.php`, `Control.php`, `Document.php` (all have tenant relationships)

## Solution Implemented

### Fix 1: Correct Column Name in Version20251105100001

Changed all references from `resource` to `category` in `Version20251105100001.php`:

**Before:**
```sql
CREATE TABLE permissions (
    ...
    resource VARCHAR(100) NOT NULL,
    ...
);

INSERT INTO permissions (name, description, resource, action, ...) VALUES ...
```

**After:**
```sql
CREATE TABLE permissions (
    ...
    category VARCHAR(50) NOT NULL,
    ...
);

INSERT INTO permissions (name, description, category, action, ...) VALUES ...
```

### Fix 2: Remove Duplicate Table Creation from Version20251105100001

Simplified `Version20251105100001.php` to only insert default data:

**Before:**
- Created all tables (users, roles, permissions, junction tables)
- Added foreign key constraints
- Inserted default roles and permissions

**After:**
- Only inserts default roles and permissions
- Table creation handled by `Version20251105000004`
- No duplicate foreign key constraints

**Changes:**
```php
public function getDescription(): string
{
    // Before: 'Create User, Role, and Permission tables for authentication and RBAC'
    return 'Add default system roles and permissions for RBAC';
}

public function up(Schema $schema): void
{
    // NOTE: Tables are created by Version20251105000004, this migration only adds default data

    // Removed: All CREATE TABLE statements
    // Removed: All ALTER TABLE foreign key statements
    // Kept: INSERT statements for default roles and permissions
}

public function down(Schema $schema): void
{
    // Before: Dropped all tables
    // After: Only deletes default data
    $this->addSql('DELETE FROM permissions WHERE is_system_permission = 1');
    $this->addSql('DELETE FROM roles WHERE is_system_role = 1');
}
```

### Fix 3: Create Tenant Table Migration (NEW)

Created `Version20251107121500.php` to create the `tenant` table **before** it's referenced:

```php
public function up(Schema $schema): void
{
    // Create tenant table
    $this->addSql('CREATE TABLE tenant (
        id INT AUTO_INCREMENT NOT NULL,
        code VARCHAR(100) NOT NULL,
        name VARCHAR(255) NOT NULL,
        description LONGTEXT DEFAULT NULL,
        azure_tenant_id VARCHAR(255) DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        settings JSON DEFAULT NULL,
        created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
        updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
        UNIQUE INDEX UNIQ_TENANT_CODE (code),
        PRIMARY KEY(id)
    ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
}
```

**Migration Order:**
- Version20251107121500 runs first → Creates `tenant` table
- Version20251107121600 runs second → Can now add foreign keys to `tenant`

### Fix 4: Add Missing tenant_id for User Entity

Updated `Version20251107121600.php` to include tenant_id for users table:

**Before:**
- Only added tenant_id to: asset, risk, incident, control, document
- Missing: users table (even though User entity has tenant relationship)

**After:**
```php
public function up(Schema $schema): void
{
    // Add tenant_id column to users table
    $this->addSql('ALTER TABLE users ADD COLUMN tenant_id INT DEFAULT NULL');
    $this->addSql('CREATE INDEX idx_users_tenant ON users (tenant_id)');
    $this->addSql('ALTER TABLE users ADD CONSTRAINT FK_1483A5E9178D3548
                   FOREIGN KEY (tenant_id) REFERENCES tenant (id)');

    // ... rest of entities (asset, risk, incident, control, document)
}
```

**All 6 entities with tenant relationships now have tenant_id:**
1. ✅ users
2. ✅ asset
3. ✅ risk
4. ✅ incident
5. ✅ control
6. ✅ document

## Files Changed

1. **migrations/Version20251105100001.php**
   - Fixed: 'resource' → 'category' (3 occurrences)
   - Removed: CREATE TABLE statements for users, roles, permissions, junction tables
   - Removed: ALTER TABLE statements for foreign keys
   - Simplified: down() method to only delete data, not drop tables

2. **README.md**
   - Added troubleshooting section for migration errors
   - Added reference to reset-database.sh script

3. **reset-database.sh** (NEW)
   - Interactive script to reset database and re-run migrations
   - Handles SQLite, MySQL, and PostgreSQL
   - Optionally creates admin user
   - Optionally loads ISO 27001 controls

4. **MIGRATION_FIX.md** (NEW - this file)
   - Documents the issue and solution

## Testing

### Before Fixes

**Error 1:**
```bash
$ php bin/console doctrine:migrations:migrate
[error] Migration DoctrineMigrations\Version20251105100001 failed during Execution.
Error: "Column not found: 1054 Unknown column 'resource' in 'INSERT INTO'"
```

**Error 2 (after fixing Error 1):**
```bash
$ php bin/console doctrine:migrations:migrate
[error] Migration DoctrineMigrations\Version20251107121600 failed during Execution.
Error: "Can't create table `asset` (errno: 150 "Foreign key constraint is incorrectly formed")"
```

### After All Fixes

```bash
$ ./reset-database.sh
✓ Database created
✓ Migrations completed (9 migrations executed successfully)
✓ Roles, permissions, and admin user created
✓ Database setup completed successfully!
```

**Migration Order (successful):**
1. Version20251105000000 - Core tables
2. Version20251105000001 - Business process
3. Version20251105000002 - Compliance
4. Version20251105000003 - Audit
5. Version20251105000004 - Users, roles, permissions (tables)
6. Version20251105000005 - Owner relationships
7. Version20251105100001 - Default roles & permissions (data)
8. **Version20251107121500 - Tenant table ← NEW**
9. **Version20251107121600 - Tenant relationships ← FIXED**

## Impact Assessment

### Before Fixes
- ❌ Fresh installations **FAILED** (at migration 7/9)
- ❌ Even after fixing first error, **FAILED AGAIN** (at migration 9/9)
- ❌ Users could not complete setup at all
- ❌ README instructions **DID NOT WORK**
- ❌ Multi-tenancy feature completely broken

### After All Fixes
- ✅ Fresh installations **WORK**
- ✅ All 9 migrations execute successfully
- ✅ Users can complete setup successfully
- ✅ README instructions **VERIFIED**
- ✅ Reset script available for recovery
- ✅ Multi-tenancy foundation properly set up

## Prevention

To prevent similar issues in the future:

1. **Schema Consistency Checks**
   - Always verify entity field names match migration column names
   - Use Doctrine schema validation: `php bin/console doctrine:schema:validate`

2. **Migration Best Practices**
   - Avoid duplicate table creation across migrations
   - Use migration version control carefully
   - One migration = one logical change

3. **Testing**
   - Test migrations on fresh database before committing
   - Run `reset-database.sh` to validate clean setup
   - Check that `test-setup.sh` passes

## Recovery Instructions

If you encountered this error before the fix:

### Option 1: Automated Reset (Recommended)

```bash
chmod +x reset-database.sh
./reset-database.sh
```

### Option 2: Manual Reset

```bash
# 1. Drop database
php bin/console doctrine:database:drop --force

# 2. Create database
php bin/console doctrine:database:create

# 3. Run migrations
php bin/console doctrine:migrations:migrate --no-interaction

# 4. Setup permissions and admin
php bin/console app:setup-permissions \
  --admin-email=admin@example.com \
  --admin-password=admin123

# 5. Load ISO controls
php bin/console isms:load-annex-a-controls
```

## Verification

After applying the fix, verify:

```bash
# 1. Check migration status
php bin/console doctrine:migrations:status

# 2. Verify schema
php bin/console doctrine:schema:validate

# 3. Run setup validation
./test-setup.sh

# 4. Check database structure
php bin/console doctrine:schema:update --dump-sql
# Should output: "Nothing to update - your database is already in sync"
```

## Related Issues

- Setup validation revealed that migrations had not been tested on fresh database
- README instructions were incomplete (missing app:setup-permissions)
- No recovery mechanism existed for failed migrations
- Multi-tenancy entities existed but table creation migration was missing
- User entity had tenant relationship but wasn't included in tenant migration

All issues have been addressed:
- ✅ Migrations fixed (both errors)
- ✅ README updated with complete instructions
- ✅ reset-database.sh script created
- ✅ test-setup.sh validates structure
- ✅ SETUP_VALIDATION.md documents testing
- ✅ MIGRATION_ORDER_CHECK.md verifies migration dependencies
- ✅ Tenant table properly created
- ✅ All 6 entities with tenant relationships have tenant_id column

## References

- Issue discovered during: Setup validation testing
- Entity definition: `src/Entity/Permission.php` (line 29)
- Migration 1: `migrations/Version20251105000004.php` (creates tables)
- Migration 2: `migrations/Version20251105100001.php` (inserts data)
- Reset script: `reset-database.sh`
- Validation: `SETUP_VALIDATION.md`

---

**Status:** ✅ RESOLVED
**Verified:** Fresh installation now works correctly
**Updated:** README.md with troubleshooting guidance
