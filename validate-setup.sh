#!/bin/bash
# Comprehensive Setup Validation Script
# Checks for potential issues in fresh installations

set -e

echo "=========================================="
echo "Setup Validation Tool"
echo "=========================================="
echo ""

# Color codes
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# Counters
CHECKS=0
PASSED=0
WARNINGS=0
ERRORS=0

# Functions
check() {
    CHECKS=$((CHECKS+1))
    echo -n "[$CHECKS] $1... "
}

pass() {
    PASSED=$((PASSED+1))
    echo -e "${GREEN}✓${NC}"
}

warn() {
    WARNINGS=$((WARNINGS+1))
    echo -e "${YELLOW}⚠ $1${NC}"
}

fail() {
    ERRORS=$((ERRORS+1))
    echo -e "${RED}✗ $1${NC}"
}

info() { echo -e "${BLUE}→ $1${NC}"; }

echo "=== PREREQUISITES ==="
echo ""

# Check 1: PHP version
check "PHP version >= 8.2"
PHP_VERSION=$(php -r 'echo PHP_VERSION;')
if php -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);'; then
    pass
    info "  Found: PHP $PHP_VERSION"
else
    fail "PHP version too old: $PHP_VERSION"
fi

# Check 2: Required PHP extensions
check "Required PHP extensions"
REQUIRED_EXTENSIONS=("pdo" "pdo_mysql" "pdo_sqlite" "mbstring" "xml" "ctype" "iconv" "intl" "json")
MISSING_EXTENSIONS=()

for ext in "${REQUIRED_EXTENSIONS[@]}"; do
    if ! php -m | grep -q "^$ext$"; then
        MISSING_EXTENSIONS+=("$ext")
    fi
done

if [ ${#MISSING_EXTENSIONS[@]} -eq 0 ]; then
    pass
else
    fail "Missing extensions: ${MISSING_EXTENSIONS[*]}"
fi

# Check 3: Composer
check "Composer installed"
if command -v composer &> /dev/null; then
    pass
    info "  $(composer --version 2>&1 | head -1)"
else
    fail "Composer not found in PATH"
fi

# Check 4: vendor/ directory
check "Composer dependencies installed"
if [ -d "vendor" ] && [ -f "vendor/autoload.php" ]; then
    pass
else
    fail "Run: composer install"
fi

echo ""
echo "=== CONFIGURATION ==="
echo ""

# Check 5: .env file
check ".env file exists"
if [ -f ".env" ]; then
    pass
else
    fail "Missing .env file"
fi

# Check 6: .env.local file
check ".env.local file exists"
if [ -f ".env.local" ]; then
    pass
else
    warn ".env.local not found (will be created)"
fi

# Check 7: APP_SECRET
check "APP_SECRET is set"
if [ -f ".env.local" ]; then
    APP_SECRET=$(grep "^APP_SECRET=" .env.local | cut -d'=' -f2 | tr -d '"')
elif [ -f ".env" ]; then
    APP_SECRET=$(grep "^APP_SECRET=" .env | cut -d'=' -f2 | tr -d '"')
fi

if [ -n "$APP_SECRET" ] && [ "$APP_SECRET" != "" ]; then
    pass
    info "  Length: ${#APP_SECRET} characters"
else
    fail "APP_SECRET is empty"
fi

# Check 8: DATABASE_URL
check "DATABASE_URL is set"
if [ -f ".env.local" ]; then
    DB_URL=$(grep "^DATABASE_URL=" .env.local | cut -d'=' -f2- | tr -d '"')
elif [ -f ".env" ]; then
    DB_URL=$(grep "^DATABASE_URL=" .env | cut -d'=' -f2- | tr -d '"')
fi

if [ -n "$DB_URL" ]; then
    pass
    info "  ${DB_URL:0:50}..."
else
    fail "DATABASE_URL not set"
fi

echo ""
echo "=== ENTITY-MIGRATION CONSISTENCY ==="
echo ""

# Check 9: All entities have migrations
check "All entities have database tables"
ENTITIES_WITHOUT_TABLES=()

for entity_file in src/Entity/*.php; do
    entity_name=$(basename "$entity_file" .php)

    # Convert CamelCase to snake_case
    table_name=$(echo "$entity_name" | sed 's/\([A-Z]\)/_\L\1/g' | sed 's/^_//' | tr '[:upper:]' '[:lower:]')

    # Special cases
    case $entity_name in
        "ISMSContext") table_name="ismscontext" ;;
        "ISMSObjective") table_name="ismsobjective" ;;
    esac

    # Check if table is created in any migration
    if ! grep -rq "CREATE TABLE $table_name" migrations/ 2>/dev/null && \
       ! grep -rq "CREATE TABLE \`$table_name\`" migrations/ 2>/dev/null; then
        ENTITIES_WITHOUT_TABLES+=("$entity_name ($table_name)")
    fi
done

if [ ${#ENTITIES_WITHOUT_TABLES[@]} -eq 0 ]; then
    pass
else
    warn "Entities without tables:"
    for entity in "${ENTITIES_WITHOUT_TABLES[@]}"; do
        info "    $entity"
    done
fi

# Check 10: Entity NOT NULL fields have defaults
check "NOT NULL fields without defaults"
NULLABLE_ISSUES=()

for entity_file in src/Entity/*.php; do
    entity_name=$(basename "$entity_file" .php)

    # Find ORM\Column without nullable or default
    while IFS= read -r line; do
        if [[ $line =~ "#[ORM\\Column" ]] && \
           ! [[ $line =~ "nullable" ]] && \
           ! [[ $line =~ "nullable: true" ]] && \
           ! [[ $line =~ "default" ]]; then
            # Get property name from next line
            prop_line=$(grep -A1 "$line" "$entity_file" | tail -1)
            if [[ $prop_line =~ "private.*\$([a-zA-Z]+)" ]]; then
                prop_name="${BASH_REMATCH[1]}"
                NULLABLE_ISSUES+=("$entity_name::$prop_name")
            fi
        fi
    done < "$entity_file"
done

if [ ${#NULLABLE_ISSUES[@]} -eq 0 ]; then
    pass
else
    info "  Found ${#NULLABLE_ISSUES[@]} fields (may need constructor defaults)"
fi

echo ""
echo "=== MIGRATION INTEGRITY ==="
echo ""

# Check 11: Migration files syntax
check "Migration files syntax"
MIGRATION_ERRORS=()

for migration in migrations/*.php; do
    if ! php -l "$migration" >/dev/null 2>&1; then
        MIGRATION_ERRORS+=("$(basename $migration)")
    fi
done

if [ ${#MIGRATION_ERRORS[@]} -eq 0 ]; then
    pass
else
    fail "Syntax errors in: ${MIGRATION_ERRORS[*]}"
fi

# Check 12: Foreign key references
check "Foreign key references"
FK_ISSUES=()

# Check if all FK references point to existing tables
while IFS= read -r line; do
    if [[ $line =~ REFERENCES\ ([a-z_]+) ]]; then
        referenced_table="${BASH_REMATCH[1]}"

        # Check if table is created before this FK
        if ! grep -q "CREATE TABLE $referenced_table" migrations/*.php 2>/dev/null; then
            FK_ISSUES+=("FK to $referenced_table (table not created)")
        fi
    fi
done < <(grep -h "REFERENCES" migrations/*.php 2>/dev/null)

if [ ${#FK_ISSUES[@]} -eq 0 ]; then
    pass
else
    warn "Potential FK issues:"
    for issue in "${FK_ISSUES[@]}"; do
        info "    $issue"
    done
fi

# Check 13: Duplicate table creation
check "Duplicate table creation"
DUPLICATES=()

for table in $(grep -h "CREATE TABLE" migrations/*.php | sed 's/.*CREATE TABLE //' | sed 's/ .*//' | sort | uniq -d); do
    DUPLICATES+=("$table")
done

if [ ${#DUPLICATES[@]} -eq 0 ]; then
    pass
else
    fail "Tables created multiple times: ${DUPLICATES[*]}"
fi

echo ""
echo "=== AUDITLOG CONFIGURATION ==="
echo ""

# Check 14: AuditLog setUserName
check "AuditLogger uses setUserName()"
if grep -q "setUserName" src/Service/AuditLogger.php; then
    pass
else
    fail "AuditLogger not using setUserName() - will cause NULL constraint error"
fi

# Check 15: AuditLog fallback for CLI
check "AuditLogger has CLI fallback"
if grep -q "'system'" src/Service/AuditLogger.php || \
   grep -q '"system"' src/Service/AuditLogger.php; then
    pass
else
    fail "No CLI fallback - admin user creation will fail"
fi

# Check 16: AuditLog JSON serialization
check "AuditLogger serializes arrays"
if grep -q "json_encode" src/Service/AuditLogger.php; then
    pass
else
    fail "No JSON serialization - type error will occur"
fi

echo ""
echo "=== COMMAND VALIDATION ==="
echo ""

# Check 17: SetupPermissionsCommand
check "SetupPermissionsCommand exists"
if [ -f "src/Command/SetupPermissionsCommand.php" ]; then
    pass
else
    fail "SetupPermissionsCommand not found"
fi

# Check 18: Command can be found
check "app:setup-permissions command registered"
if php bin/console list | grep -q "app:setup-permissions"; then
    pass
else
    fail "Command not registered in Symfony"
fi

echo ""
echo "=========================================="
echo "SUMMARY"
echo "=========================================="
echo ""
echo "Total Checks:  $CHECKS"
echo -e "Passed:        ${GREEN}$PASSED${NC}"
echo -e "Warnings:      ${YELLOW}$WARNINGS${NC}"
echo -e "Errors:        ${RED}$ERRORS${NC}"
echo ""

if [ $ERRORS -eq 0 ] && [ $WARNINGS -eq 0 ]; then
    echo -e "${GREEN}✓ ALL CHECKS PASSED!${NC}"
    echo ""
    echo "Your setup is ready. Run:"
    echo "  ./create-database.sh"
    exit 0
elif [ $ERRORS -eq 0 ]; then
    echo -e "${YELLOW}⚠ PASSED WITH WARNINGS${NC}"
    echo ""
    echo "Setup should work, but review warnings above."
    exit 0
else
    echo -e "${RED}✗ VALIDATION FAILED${NC}"
    echo ""
    echo "Fix the errors above before proceeding."
    exit 1
fi
