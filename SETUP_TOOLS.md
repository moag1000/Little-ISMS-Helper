# Setup Tools Documentation

**Date:** 2025-11-07
**Purpose:** Comprehensive database setup and validation tools

## Overview

This project provides three complementary scripts for database setup and validation:

| Script | Purpose | When to Use |
|--------|---------|-------------|
| `validate-setup.sh` | Pre-flight validation | Before creating database |
| `create-database.sh` | Create fresh database | First-time setup |
| `reset-database.sh` | Reset existing database | After migration errors |

---

## 1. validate-setup.sh

**Comprehensive setup validation script**

### Purpose
Checks for potential issues before running database setup. Performs 18+ validation checks.

### Usage
```bash
chmod +x validate-setup.sh
./validate-setup.sh
```

### Checks Performed

**Prerequisites (Checks 1-4):**
- ✓ PHP version >= 8.2
- ✓ Required PHP extensions (pdo, pdo_mysql, pdo_sqlite, mbstring, xml, ctype, iconv, intl, json)
- ✓ Composer installed
- ✓ Composer dependencies (vendor/ directory)

**Configuration (Checks 5-8):**
- ✓ .env file exists
- ✓ .env.local file exists
- ✓ APP_SECRET is set and not empty
- ✓ DATABASE_URL is set

**Entity-Migration Consistency (Checks 9-10):**
- ✓ All entities have database tables in migrations
- ✓ NOT NULL fields without defaults (informational)

**Migration Integrity (Checks 11-13):**
- ✓ Migration files syntax is valid
- ✓ Foreign key references are correct
- ✓ No duplicate table creation

**AuditLog Configuration (Checks 14-16):**
- ✓ AuditLogListener uses setUserName() (not setUser())
- ✓ AuditLogListener has CLI fallback ('system')
- ✓ AuditLogListener serializes arrays to JSON

**Command Validation (Checks 17-18):**
- ✓ SetupPermissionsCommand exists
- ✓ app:setup-permissions command is registered

### Exit Codes
- **0**: All checks passed or only warnings
- **1**: Errors found, must fix before proceeding

### Example Output
```
==========================================
Setup Validation Tool
==========================================

=== PREREQUISITES ===

[1] PHP version >= 8.2... ✓
→  Found: PHP 8.4.0

[2] Required PHP extensions... ✓

[3] Composer installed... ✓
→  Composer version 2.6.5

[4] Composer dependencies installed... ✓

=== CONFIGURATION ===

[5] .env file exists... ✓
[6] .env.local file exists... ✓
[7] APP_SECRET is set... ✓
→  Length: 64 characters
[8] DATABASE_URL is set... ✓
→  mysql://root@127.0.0.1:3306/littlehelper?se...

...

==========================================
SUMMARY
==========================================

Total Checks:  18
Passed:        16
Warnings:      2
Errors:        0

⚠ PASSED WITH WARNINGS

Setup should work, but review warnings above.
```

---

## 2. create-database.sh

**Safe database creation script (won't drop existing databases without confirmation)**

### Purpose
Creates a fresh database from scratch with complete setup. Safe to run on fresh installations.

### Features
- ✅ Prerequisites checking (PHP, Composer, .env files)
- ✅ Automatic APP_SECRET generation if missing
- ✅ Database type detection (SQLite, MySQL, PostgreSQL)
- ✅ Safe handling of existing databases (asks for confirmation)
- ✅ Complete migration execution (10 migrations)
- ✅ Optional admin user creation
- ✅ Optional ISO 27001 controls loading
- ✅ Schema validation after setup
- ✅ Detailed summary report

### Usage
```bash
chmod +x create-database.sh
./create-database.sh
```

### Interactive Prompts

1. **If database exists:**
   ```
   ⚠ Database already exists!

   Options:
     1. Delete existing database and create fresh (RESET)
     2. Keep existing database and exit

   Your choice (1/2):
   ```

2. **Admin user creation:**
   ```
   Create admin user? (Y/n)
   Admin email [admin@example.com]:
   Admin password [admin123]:
   ```

3. **ISO 27001 controls:**
   ```
   Load Annex A controls? (Y/n)
   ```

### What It Does

**Step 1: Prerequisites Check**
- PHP version check
- Composer check
- Project root verification
- .env file check
- .env.local creation (if needed)
- APP_SECRET generation (if empty)

**Step 2: Database Creation**
- Detects database type
- Creates var/ directory for SQLite
- Runs `doctrine:database:create`

**Step 3: Migrations**
- Executes all 10 migrations
- Shows progress

**Step 4: Roles & Permissions**
- Runs `app:setup-permissions`
- Creates admin user (if requested)
- Sets up 4 roles and 42 permissions

**Step 5: ISO Controls**
- Optionally loads 93 Annex A controls
- Runs `isms:load-annex-a-controls`

**Step 6: Validation**
- Validates database schema
- Shows summary

### Example Output
```
==========================================
Database Creation & Setup Tool
==========================================

→ Checking prerequisites...
✓ PHP found: PHP 8.4.0
✓ Project root detected
✓ .env file found
✓ .env.local found
→ Database URL: mysql://root@127.0.0.1:3306/littlehelper...
→ Database type: MySQL

→ Creating database...
✓ Database created

→ Running migrations...
✓ All migrations completed (10/10)

→ Setting up roles & permissions...
Create admin user? (Y/n) y

Admin email [admin@example.com]: admin@mycompany.com
Admin password [admin123]: ********

✓ Roles, permissions, and admin user created

→ Login credentials:
  Email: admin@mycompany.com
  Password: ********

→ Loading ISO 27001:2022 Controls...
Load Annex A controls? (Y/n) y
✓ ISO 27001 Controls loaded (93 controls)

→ Validating database schema...
✓ Database schema is valid and in sync

==========================================
✓ Database setup completed successfully!
==========================================

Database Summary:
  Type: MySQL
  Migrations: 10/10 executed
  Roles: 4 (USER, AUDITOR, MANAGER, ADMIN)
  Permissions: 42
  Admin User: Created ✓

→ Next steps:
  1. Start the development server:
     symfony serve -d
     OR
     php -S localhost:8000 -t public/

  2. Open your browser:
     http://localhost:8000

  3. Login with your admin credentials

✓ Ready to use!
```

---

## 3. reset-database.sh

**Database reset script (destructive - use after migration errors)**

### Purpose
Completely resets the database by dropping and recreating it. Use when migrations fail.

### Features
- ⚠️ **DESTRUCTIVE**: Drops existing database
- ✅ Interactive confirmation
- ✅ Database type detection
- ✅ Complete reset workflow
- ✅ Optional admin user creation
- ✅ Optional ISO controls loading

### Usage
```bash
chmod +x reset-database.sh
./reset-database.sh
```

### When to Use
- After migration errors (column not found, constraint violations)
- When database schema is out of sync
- During development after entity changes
- When testing migration fixes

### Example Output
```
==========================================
Database Reset & Migration Tool
==========================================

→ Database URL: mysql://root@127.0.0.1:3306/littlehelper...

⚠ This script will:
  1. Drop the existing database
  2. Create a new database
  3. Run all migrations
  4. (Optional) Load default roles & permissions
  5. (Optional) Create admin user

Continue? (y/N) y

→ Step 1: Dropping existing database...
✓ Database dropped

→ Step 2: Creating database...
✓ Database created

→ Step 3: Running migrations...
✓ Migrations completed

→ Step 4: Loading default roles & permissions...
Run app:setup-permissions? (Y/n) y

Create admin user? (Y/n) y
Admin email [admin@example.com]:
Admin password [admin123]:

✓ Roles, permissions, and admin user created

→ Step 5: (Optional) Load ISO 27001 Controls...
Run isms:load-annex-a-controls? (Y/n) y
✓ ISO 27001 Controls loaded

==========================================
✓ Database setup completed successfully!
==========================================

→ Next steps:
  1. Start the server: symfony serve
  2. Or use: php -S localhost:8000 -t public/
  3. Open: http://localhost:8000
```

---

## Recommended Workflow

### First-Time Setup
```bash
# 1. Validate prerequisites
./validate-setup.sh

# 2. Create database (if validation passed)
./create-database.sh
```

### After Migration Errors
```bash
# Reset database and try again
./reset-database.sh
```

### CI/CD Pipeline
```bash
# In your CI/CD script
./validate-setup.sh || exit 1
./create-database.sh --non-interactive  # (if implemented)
```

---

## Troubleshooting

### validate-setup.sh Reports Errors

**"PHP version too old"**
```bash
# Install PHP 8.2 or higher
# Ubuntu/Debian:
sudo apt install php8.2

# macOS:
brew install php@8.2
```

**"Missing extensions"**
```bash
# Install missing PHP extensions
# Ubuntu/Debian:
sudo apt install php8.2-{pdo,mysql,sqlite3,mbstring,xml,intl}

# macOS:
brew install php@8.2-{extensions}
```

**"APP_SECRET is empty"**
```bash
# Generate APP_SECRET
echo "APP_SECRET=$(openssl rand -hex 32)" >> .env.local
```

### create-database.sh Fails

**"Database creation failed"**
- Check DATABASE_URL in .env.local
- Ensure database server is running (MySQL/PostgreSQL)
- Check database user permissions

**"Migration failed"**
- Run: `./validate-setup.sh` to identify issues
- Check migration files in `migrations/`
- See MIGRATION_FIX.md for known issues

### reset-database.sh Issues

**"Database did not exist"**
- This is normal on first run
- The script will create a new database

**"Permission denied"**
- Check database user has CREATE/DROP permissions
- For SQLite, ensure var/ directory is writable:
  ```bash
  chmod -R 775 var/
  ```

---

## Advanced Usage

### Running Specific Steps Only

**Only validate, don't create:**
```bash
./validate-setup.sh
```

**Create database without admin user:**
```bash
./create-database.sh
# Answer "n" to admin user prompt
```

**Reset database with specific admin credentials:**
```bash
./reset-database.sh
# Provide credentials when prompted
```

### Automation (Non-Interactive)

For CI/CD pipelines, you can pre-set environment variables:

```bash
# Example: Automated setup
export ADMIN_EMAIL="admin@ci-cd.com"
export ADMIN_PASSWORD="SecurePassword123!"
export AUTO_CONFIRM="yes"

# Then modify scripts to read these variables
# (Implementation would require script modifications)
```

---

## Files Created/Modified

### Database Files
- **SQLite**: `var/data.db` (or path in DATABASE_URL)
- **MySQL/PostgreSQL**: Database named in DATABASE_URL

### Migration Tracking
- `migrations/` directory - All migration files
- `doctrine_migration_versions` table - Tracks executed migrations

### Generated Data
- Roles: ROLE_USER, ROLE_AUDITOR, ROLE_MANAGER, ROLE_ADMIN
- Permissions: 42 permissions across categories
- Admin user: As specified during setup
- ISO Controls: 93 Annex A controls (if loaded)

---

## Related Documentation

- **MIGRATION_FIX.md** - Documents 5 critical migration errors and fixes
- **ENTITY_TABLE_MAPPING.md** - Maps all entities to database tables
- **SETUP_VALIDATION.md** - Original setup validation report
- **README.md** - Main project documentation with setup instructions

---

## Version History

### Version 1.0 (2025-11-07)
- Initial release
- Three complementary scripts
- 18+ validation checks
- Interactive setup workflow
- Comprehensive error handling

---

**Status:** ✅ Production Ready
**Last Updated:** 2025-11-07
**Tested On:** PHP 8.2+, Symfony 7.3, MySQL 8.0, PostgreSQL 16, SQLite 3
