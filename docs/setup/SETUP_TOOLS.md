# Setup Tools Documentation

**Purpose:** Shell scripts for database setup, validation and license reporting.

The recommended installation path is the setup wizard at `/setup` ([QUICKSTART.md](../QUICKSTART.md)).
These scripts are for developers and for hosts where the wizard cannot run. The application runs on
PHP 8.4+ and MySQL 8 / MariaDB 10.6+ only.

## Overview

This project provides complementary scripts for database setup, validation, and compliance reporting:

### Database Management

| Script | Purpose | When to Use |
|--------|---------|-------------|
| `scripts/setup/validate-setup.sh` | Pre-flight validation | Before creating database |
| `scripts/setup/create-database.sh` | Create fresh database | First-time setup |
| `scripts/setup/reset-database.sh` | Reset existing database | After migration errors |

### License Compliance

| Script | Purpose | When to Use |
|--------|---------|-------------|
| `scripts/tools/license-report.sh` | Generate license report | Before releases, compliance audits |
| `bin/license-report.js` | Core license analysis | Called by wrapper script |

> **Note:** Wrappers in the root directory: `./validate-setup.sh`, `./create-database.sh`, `./reset-database.sh`.

---

## 1. validate-setup.sh

**Comprehensive setup validation script**

### Purpose
Checks for potential issues before running database setup. Performs 18+ validation checks.

### Usage
```bash
chmod +x scripts/setup/validate-setup.sh
scripts/setup/validate-setup.sh
```

Or use the backward-compatible wrapper:
```bash
chmod +x validate-setup.sh
./validate-setup.sh
```

### Checks Performed

**Prerequisites (Checks 1-4):**
- ✓ PHP version (the script still accepts >= 8.2; the application needs 8.4)
- ✓ Required PHP extensions (pdo_mysql, pdo_sqlite, mbstring, xml, ctype, iconv, intl, json — `pdo_sqlite` is checked but not used)
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
- ✅ Database type detection (the script also knows SQLite/PostgreSQL; the application supports MySQL/MariaDB only)
- ✅ Safe handling of existing databases (asks for confirmation)
- ✅ Complete migration execution
- ✅ Optional admin user creation
- ✅ Optional ISO 27001 controls loading
- ✅ Schema validation after setup
- ✅ Detailed summary report

### Usage
```bash
chmod +x scripts/setup/create-database.sh
scripts/setup/create-database.sh
```

Or use the backward-compatible wrapper:
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
- Runs `doctrine:database:create`

**Step 3: Migrations**
- Executes all migrations
- Shows progress

**Step 4: Roles & Permissions**
- Runs `app:setup-permissions`
- Creates admin user (if requested)
- Sets up roles and permissions

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
chmod +x scripts/setup/reset-database.sh
scripts/setup/reset-database.sh
```

Or use the backward-compatible wrapper:
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

## 4. license-report.sh

**License compliance reporting tool**

### Purpose
Generates a comprehensive license report for all project dependencies, analyzing commercial usability and compliance requirements.

### Features
- ✅ Analyzes PHP dependencies (Composer)
- ✅ Analyzes JavaScript dependencies (Symfony ImportMap)
- ✅ Tracks manually included packages
- ✅ Evaluates commercial use permissions
- ✅ Identifies problematic licenses
- ✅ Generates detailed compliance report
- ✅ Provides actionable recommendations

### Usage
```bash
chmod +x scripts/tools/license-report.sh
scripts/tools/license-report.sh
```

Or use the backward-compatible wrapper:
```bash
chmod +x license-report.sh
./license-report.sh
```

### What It Analyzes

**1. Composer Packages (PHP)**
- Reads from `composer.lock`
- Extracts license information
- Validates commercial use rights

**2. ImportMap Packages (JavaScript)**
- Reads from `importmap.php`
- Maps packages to known licenses
- Checks commercial compatibility

**3. Manual Packages**
- CDN-loaded libraries (marked.js)
- Bundled components (FOSJsRoutingBundle)
- Custom attribution tracking

### License Classifications

| Status | Icon | Description | Commercial Use |
|--------|------|-------------|----------------|
| **Erlaubt** | ✅ | Permissive (MIT, BSD, Apache-2.0) | Yes, with attribution |
| **Eingeschränkt** | ⚠️ | Weak copyleft (MPL-2.0, EPL, CC-BY) | Yes, with conditions |
| **Copyleft** | 🔄 | Strong copyleft (GPL/LGPL/AGPL) | Yes, source disclosure required |
| **Nicht erlaubt** | ❌ | Non-commercial (NC licenses) | No |
| **Unbekannt** | ❓ | No/unclear license | Manual review required |

### Output

**Report Location:** `docs/reports/license-report.md`

**Report Sections:**
1. **Executive Summary**
   - Overall compliance status
   - Risk assessment
   - Key findings

2. **Statistics Table**
   - Packages by license type
   - Counts per ecosystem
   - Total compliance metrics

3. **Problematic Packages**
   - Unknown licenses (requires review)
   - Restricted licenses (attribution needed)
   - Recommendations for each

4. **License Distribution**
   - Most common licenses
   - Frequency analysis
   - Compliance percentages

5. **Action Items**
   - Immediate actions
   - Short-term tasks
   - Long-term recommendations

6. **Detailed Package Lists**
   - PHP packages (collapsible)
   - JavaScript packages (collapsible)
   - Manual packages
   - Full license information

7. **Compliance Guide**
   - License type explanations
   - Attribution requirements
   - Copyleft obligations

### Example Output
```
╔════════════════════════════════════════════════════════════════╗
║          Little ISMS Helper - Lizenzbericht                   ║
╚════════════════════════════════════════════════════════════════╝

→ Analysiere Abhängigkeiten...

✅ Lizenzbericht erstellt: docs/reports/license-report.md
📊 Statistik: 127 Pakete analysiert
   - 122 erlaubt
   - 3 eingeschränkt
   - 0 copyleft
   - 0 nicht erlaubt
   - 2 unbekannt

╔════════════════════════════════════════════════════════════════╗
║                  ✓ Erfolgreich abgeschlossen!                 ║
╚════════════════════════════════════════════════════════════════╝

→ Bericht verfügbar unter: docs/reports/license-report.md
```

### When to Use

**Before Major Releases:**
```bash
scripts/tools/license-report.sh
# Review docs/reports/license-report.md
# Address any unknown/restricted licenses
```

**During Compliance Audits:**
```bash
scripts/tools/license-report.sh
# Share report with legal team
# Document compliance measures
```

**After Adding Dependencies:**
```bash
composer require some/package
scripts/tools/license-report.sh
# Verify new package license
```

**Regular Compliance Checks:**
```bash
# Monthly/quarterly review
scripts/tools/license-report.sh
# Track license changes
# Update NOTICE file if needed
```

### Integration with CI/CD

The workflow `.github/workflows/license-compliance.yml` runs the report in CI.

### Compliance Workflow

**1. Generate Report:**
```bash
scripts/tools/license-report.sh
```

**2. Review Findings:**
```bash
# View report
cat docs/reports/license-report.md

# Or open in browser
open docs/reports/license-report.md
```

**3. Address Issues:**
- **Unknown licenses:** Research package, contact maintainer, or replace
- **Restricted licenses:** Ensure attribution is in place
- **Copyleft:** Verify compliance with disclosure requirements
- **Non-commercial:** Remove from commercial projects

**4. Create NOTICE File:**
```bash
# Extract attributions from report
# Create docs/NOTICE.md with required attributions
```

**5. Update Documentation:**
```bash
# Add license compliance section to README
# Document known license requirements
```

### Troubleshooting

**"Node.js ist nicht installiert"**
```bash
# Install Node.js
# Ubuntu/Debian:
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt-get install -y nodejs

# macOS:
brew install node
```

**"Composer ist nicht installiert"**
```bash
# Install Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

**"composer.lock nicht gefunden"**
```bash
# Install dependencies first
composer install
```

**"Keine Schreibrechte für docs/reports/"**
```bash
# Create directory and set permissions
mkdir -p docs/reports
chmod -R 775 docs/reports
```

### Customization

**Adding Manual Packages:**

Edit `bin/license-report.js` and add to the `manualPackages` array:

```javascript
const manualPackages = [
  {
    name: 'your-package',
    version: '1.0.0',
    licenses: ['MIT'],
    evaluation: {
      status: 'allowed',
      note: 'Permissive Lizenz erlaubt kommerzielle Nutzung'
    },
    type: 'CDN',
    homepage: 'https://github.com/example/package',
    description: 'Package description',
    copyright: 'Copyright (c) 2025 Example'
  }
];
```

**Adjusting License Classifications:**

Modify the `evaluateLicense()` function in `bin/license-report.js` to adjust how licenses are categorized.

---

## Recommended Workflow

### First-Time Setup
```bash
# 1. Validate prerequisites
scripts/setup/validate-setup.sh

# 2. Create database (if validation passed)
scripts/setup/create-database.sh

# 3. Generate license compliance report
scripts/tools/license-report.sh
```

### After Migration Errors
```bash
# Reset database and try again
scripts/setup/reset-database.sh
```

### Before Production Release
```bash
# 1. Run all validations
scripts/setup/validate-setup.sh

# 2. Generate fresh license report
scripts/tools/license-report.sh

# 3. Review compliance
cat docs/reports/license-report.md

# 4. Address any issues found
# 5. Create/update NOTICE file with attributions
```

### CI/CD Pipeline
```bash
# In your CI/CD script
scripts/setup/validate-setup.sh || exit 1
scripts/tools/license-report.sh
```

---

## Troubleshooting

### validate-setup.sh Reports Errors

**"PHP version too old"** — install PHP 8.4 or 8.5 (`sudo apt install php8.4`, `brew install php@8.4`).

**"Missing extensions"**
```bash
sudo apt install php8.4-{mysql,sqlite3,mbstring,xml,intl,zip}
```

**"APP_SECRET is empty"**
```bash
# Generate APP_SECRET
echo "APP_SECRET=$(openssl rand -hex 32)" >> .env.local
```

### create-database.sh Fails

**"Database creation failed"**
- Check DATABASE_URL in .env.local
- Ensure the MySQL/MariaDB server is running
- Check database user permissions

**"Migration failed"**
- Run: `./validate-setup.sh` to identify issues
- Check migration files in `migrations/`
- Schema drift: `php bin/console app:schema:reconcile --dry-run` or `/quick-fix` ([QUICK_FIX.md](../user-guide/QUICK_FIX.md))

### reset-database.sh Issues

**"Database did not exist"**
- This is normal on first run
- The script will create a new database

**"Permission denied"**
- Check database user has CREATE/DROP permissions

---

## Advanced Usage

### Running Specific Steps Only

**Only validate, don't create:**
```bash
scripts/setup/validate-setup.sh
```

**Create database without admin user:**
```bash
scripts/setup/create-database.sh
# Answer "n" to admin user prompt
```

**Reset database with specific admin credentials:**
```bash
scripts/setup/reset-database.sh
# Provide credentials when prompted
```

> **Alternative:** You can use the backward-compatible root wrappers: `./validate-setup.sh`, `./create-database.sh`, `./reset-database.sh`

The scripts are interactive; there is no non-interactive mode.

---

## Files Created/Modified

### Database Files
- MySQL/MariaDB database named in `DATABASE_URL`

### Migration Tracking
- `migrations/` directory - All migration files
- `doctrine_migration_versions` table - Tracks executed migrations

### Generated Data
- Roles and permissions from `app:setup-permissions`
- Admin user: As specified during setup
- ISO Controls: 93 Annex A controls (if loaded)

---

## Related Documentation

- [ENTITY_TABLE_MAPPING.md](../architecture/ENTITY_TABLE_MAPPING.md) — entities and their tables
- [DATABASE_SETUP.md](../DATABASE_SETUP.md) — fresh vs. development database
- [QUICKSTART.md](../QUICKSTART.md) — installation with the setup wizard
- `docs/reports/license-report.md` — generated by `scripts/tools/license-report.sh`
