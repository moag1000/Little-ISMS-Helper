# Migration Fix: Column 'resource' Not Found

**Date:** 2025-11-07
**Issue:** Critical migration error preventing database setup
**Status:** ✅ FIXED

## Problem Description

When running the installation instructions from README.md, users encountered the following error:

```
[error] Migration DoctrineMigrations\Version20251105100001 failed during Execution.
Error: "An exception occurred while executing a query: SQLSTATE[42S22]:
Column not found: 1054 Unknown column 'resource' in 'INSERT INTO'"
```

## Root Cause Analysis

### Issue 1: Column Name Mismatch

**Problem:**
- The `Permission` entity uses a column named **`category`** (line 29 in `src/Entity/Permission.php`)
- The migration `Version20251105100001` was using **`resource`** instead
- This mismatch caused INSERT statements to fail

**Files affected:**
- `migrations/Version20251105100001.php` (lines 64, 69, 116)

### Issue 2: Duplicate Table Creation

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

## Solution Implemented

### Fix 1: Correct Column Name in Migration

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

### Fix 2: Remove Duplicate Table Creation

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

### Before Fix

```bash
$ php bin/console doctrine:migrations:migrate
[error] Migration DoctrineMigrations\Version20251105100001 failed during Execution.
Error: "Column not found: 1054 Unknown column 'resource' in 'INSERT INTO'"
```

### After Fix

```bash
$ ./reset-database.sh
✓ Database created
✓ Migrations completed
✓ Roles, permissions, and admin user created
✓ Database setup completed successfully!
```

## Impact Assessment

### Before Fix
- ❌ Fresh installations **FAILED**
- ❌ Users could not complete setup
- ❌ README instructions **DID NOT WORK**

### After Fix
- ✅ Fresh installations **WORK**
- ✅ Users can complete setup successfully
- ✅ README instructions **VERIFIED**
- ✅ Reset script available for recovery

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

All issues have been addressed:
- ✅ Migrations fixed
- ✅ README updated with complete instructions
- ✅ reset-database.sh script created
- ✅ test-setup.sh validates structure
- ✅ SETUP_VALIDATION.md documents testing

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
