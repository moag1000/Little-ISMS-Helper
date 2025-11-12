# Migration Order Verification

## Purpose
This document verifies that all database migrations are in the correct order and that all dependencies are satisfied.

## Migration Order (Chronological)

### 1. Version20251105000000.php
**Description:** Create all ISMS core tables for ISO 27001 management
**Creates:**
- asset
- risk
- incident
- control
- document
- Various junction tables

**Dependencies:** None
**Status:** ✅ OK

---

### 2. Version20251105000001.php
**Description:** Create business_process table for BCM/BIA and link to assets
**Creates:**
- business_process
- business_process_asset (junction table)

**Dependencies:**
- ✅ Requires `asset` table (created in Version20251105000000)

**Status:** ✅ OK

---

### 3. Version20251105000002.php
**Description:** Create compliance framework tables
**Creates:**
- compliance_framework
- compliance_requirement
- control_requirement (junction)
- Framework data for ISO 27001, TISAX, DORA

**Dependencies:**
- ✅ Requires `control` table (created in Version20251105000000)

**Status:** ✅ OK

---

### 4. Version20251105000003.php
**Description:** Create audit and management review tables
**Creates:**
- audit
- audit_finding
- management_review
- management_review_action

**Dependencies:**
- ✅ Requires core tables from Version20251105000000

**Status:** ✅ OK

---

### 5. Version20251105000004.php
**Description:** Create users, roles, and permissions tables with Azure AD integration
**Creates:**
- users
- roles
- permissions
- user_roles (junction)
- role_permissions (junction)

**Dependencies:** None
**Status:** ✅ OK

---

### 6. Version20251105000005.php
**Description:** Add owner relationships and notification preferences
**Creates:**
- Adds user_id foreign keys to various tables
- Adds notification_preferences JSON column to users

**Dependencies:**
- ✅ Requires `users` table (created in Version20251105000004)
- ✅ Requires core tables (created in Version20251105000000)

**Status:** ✅ OK

---

### 7. Version20251105100001.php
**Description:** Add default system roles and permissions for RBAC
**Creates:**
- **No tables** (data migration only)
- Inserts 5 default system roles
- Inserts 29 default system permissions

**Dependencies:**
- ✅ Requires `roles` table (created in Version20251105000004)
- ✅ Requires `permissions` table (created in Version20251105000004)

**Status:** ✅ OK (Fixed: changed 'resource' to 'category')

---

### 8. Version20251107121500.php (NEW)
**Description:** Create tenant table for multi-tenancy support
**Creates:**
- tenant

**Dependencies:** None
**Status:** ✅ OK

---

### 9. Version20251107121600.php
**Description:** Add tenant_id relations to User, Asset, Risk, Incident, Control, and Document entities
**Creates:**
- Adds tenant_id column and foreign keys to:
  - users
  - asset
  - risk
  - incident
  - control
  - document
- Adds status column to document

**Dependencies:**
- ✅ Requires `tenant` table (created in Version20251107121500)
- ✅ Requires all entity tables (created in Version20251105000000 and Version20251105000004)

**Status:** ✅ OK (Fixed: now runs AFTER tenant table is created)

---

## Dependency Graph

```
Version20251105000000 (core tables)
├── Version20251105000001 (business_process)
├── Version20251105000002 (compliance)
├── Version20251105000003 (audit)
└── Version20251105000005 (owner relationships)

Version20251105000004 (users, roles, permissions)
├── Version20251105000005 (owner relationships)
└── Version20251105100001 (default data)

Version20251107121500 (tenant)
└── Version20251107121600 (tenant relationships)
```

## Entity to Tenant Relationships

The following entities have tenant relationships in the code:

| Entity | Has Tenant? | Migration Adding tenant_id |
|--------|-------------|----------------------------|
| User | ✅ Yes | Version20251107121600 |
| Asset | ✅ Yes | Version20251107121600 |
| Risk | ✅ Yes | Version20251107121600 |
| Incident | ✅ Yes | Version20251107121600 |
| Control | ✅ Yes | Version20251107121600 |
| Document | ✅ Yes | Version20251107121600 |

All entities with tenant relationships are covered ✅

## Issues Found and Fixed

### Issue 1: Missing 'tenant' Table
**Problem:** Version20251107121600 tried to add foreign keys to non-existent `tenant` table
**Error:** `Can't create table 'asset' (errno: 150 "Foreign key constraint is incorrectly formed")`
**Fix:** Created Version20251107121500 to create `tenant` table before Version20251107121600 runs
**Status:** ✅ FIXED

### Issue 2: Missing tenant_id for User
**Problem:** User entity has tenant relationship but Version20251107121600 didn't add tenant_id
**Fix:** Added tenant_id column and foreign key for users table in Version20251107121600
**Status:** ✅ FIXED

### Issue 3: 'resource' Column Mismatch (Previous Issue)
**Problem:** Permission entity uses 'category' but migration used 'resource'
**Fix:** Changed all 'resource' references to 'category' in Version20251105100001
**Status:** ✅ FIXED (in previous commit)

## Verification Checklist

- [x] All migrations are in chronological order
- [x] All table dependencies are satisfied
- [x] All foreign key references exist before being used
- [x] All entities with tenant relationships have tenant_id added
- [x] No duplicate table creation
- [x] All column names match entity definitions
- [x] All migrations have proper up() and down() methods

## Testing Status

**Structural Verification:** ✅ PASSED
All migration files have been reviewed and all dependencies are satisfied.

**Runtime Testing:** ⏳ PENDING
Requires `composer install` to test actual migration execution.

## Recommended Test Procedure

Once composer dependencies are installed:

```bash
# 1. Clean slate
./reset-database.sh

# 2. Verify schema
php bin/console doctrine:schema:validate

# 3. Check for pending migrations
php bin/console doctrine:migrations:status

# 4. Verify no schema drift
php bin/console doctrine:schema:update --dump-sql
# Should output: "Nothing to update - your database is already in sync"
```

## Conclusion

✅ **All migrations are now in correct order**
✅ **All dependencies are satisfied**
✅ **All issues have been fixed**

The migrations should now run successfully from a clean database.

---

**Last Updated:** 2025-11-07
**Verified By:** Claude AI
**Status:** Ready for testing
