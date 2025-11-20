#!/bin/bash
# Little ISMS Helper - Database Reset and Migration Script
# Use this to clean up and re-run migrations after fixing migration issues

# Note: We don't use set -e to allow graceful error handling

echo "=========================================="
echo "Little ISMS Helper"
echo "Database Reset & Migration Tool"
echo "=========================================="
echo ""

# Color codes
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# Functions
success() { echo -e "${GREEN}✓ $1${NC}"; }
error() { echo -e "${RED}✗ $1${NC}"; }
warning() { echo -e "${YELLOW}⚠ $1${NC}"; }
info() { echo -e "${BLUE}→ $1${NC}"; }

# Check if .env.local exists
if [ ! -f ".env.local" ]; then
    error ".env.local not found!"
    echo ""
    echo "Please create .env.local first:"
    echo "  cp .env .env.local"
    echo "  # Configure database settings:"
    echo "  # DB_USER=your_user"
    echo "  # DB_PASS=your_password"
    echo "  # DB_HOST=127.0.0.1"
    echo "  # DB_PORT=3306"
    echo "  # DB_NAME=little_isms_helper"
    echo "  echo \"APP_SECRET=\$(openssl rand -hex 32)\" >> .env.local"
    exit 1
fi

# URL encode a string for use in DATABASE_URL
urlencode() {
    local string="$1"
    local strlen=${#string}
    local encoded=""
    local pos c o

    for (( pos=0 ; pos<strlen ; pos++ )); do
        c=${string:$pos:1}
        case "$c" in
            [-_.~a-zA-Z0-9] ) o="${c}" ;;
            * ) printf -v o '%%%02x' "'$c"
        esac
        encoded+="${o}"
    done
    echo "${encoded}"
}

# Load environment variables from .env and .env.local
load_env_var() {
    local var_name=$1
    local value=""

    # Try .env.local first
    if [ -f ".env.local" ]; then
        value=$(grep "^${var_name}=" .env.local | head -1 | cut -d '=' -f 2- | tr -d '"')
    fi

    # Fall back to .env if not found
    if [ -z "$value" ] && [ -f ".env" ]; then
        value=$(grep "^${var_name}=" .env | head -1 | cut -d '=' -f 2- | tr -d '"')
    fi

    echo "$value"
}

# Get database configuration
DB_URL=$(load_env_var "DATABASE_URL")
DB_USER=$(load_env_var "DB_USER")
DB_PASS=$(load_env_var "DB_PASS")
DB_HOST=$(load_env_var "DB_HOST")
DB_PORT=$(load_env_var "DB_PORT")
DB_NAME=$(load_env_var "DB_NAME")

if [ -z "$DB_URL" ]; then
    error "DATABASE_URL not found in .env.local or .env"
    exit 1
fi

info "Database Configuration:"
echo "  User: ${DB_USER}"
echo "  Host: ${DB_HOST}:${DB_PORT}"
echo "  Database: ${DB_NAME}"
echo ""

# Determine database type from URL and extract actual database name
if [[ $DB_URL == sqlite* ]]; then
    DB_TYPE="sqlite"
    # Extract SQLite database file path
    DB_FILE=$(echo $DB_URL | sed 's|sqlite:///%kernel.project_dir%/||' | sed 's|sqlite:///||')
    if [[ $DB_FILE == %* ]]; then
        DB_FILE="var/data.db"
    fi
    info "Database type: SQLite"
    info "Database file: $DB_FILE"
elif [[ $DB_URL == mysql* ]]; then
    DB_TYPE="mysql"
    # Extract actual database name from URL: mysql://user:pass@host:port/dbname
    DB_NAME_FROM_URL=$(echo "$DB_URL" | sed -n 's|.*://[^/]*/\([^?]*\).*|\1|p')
    if [ ! -z "$DB_NAME_FROM_URL" ]; then
        DB_NAME="$DB_NAME_FROM_URL"
        info "Database type: MySQL/MariaDB"
        info "Actual database name from URL: $DB_NAME"
    else
        info "Database type: MySQL/MariaDB"
        warning "Could not extract database name from URL, using DB_NAME from env: $DB_NAME"
    fi
elif [[ $DB_URL == postgresql* ]]; then
    DB_TYPE="postgresql"
    # Extract actual database name from URL: postgresql://user:pass@host:port/dbname
    DB_NAME_FROM_URL=$(echo "$DB_URL" | sed -n 's|.*://[^/]*/\([^?]*\).*|\1|p')
    if [ ! -z "$DB_NAME_FROM_URL" ]; then
        DB_NAME="$DB_NAME_FROM_URL"
        info "Database type: PostgreSQL"
        info "Actual database name from URL: $DB_NAME"
    else
        info "Database type: PostgreSQL"
        warning "Could not extract database name from URL, using DB_NAME from env: $DB_NAME"
    fi
else
    error "Unknown database type in DATABASE_URL"
    exit 1
fi

echo ""
echo "=========================================="
echo "Database Reset Options"
echo "=========================================="
echo ""
echo "Choose how to reset the database:"
echo ""
echo "  [1] Drop & Create (Delete database and create new)"
echo "  [2] Drop tables (Drop all tables including migration history, keep database)"
echo ""
read -p "Select option [1/2]: " RESET_OPTION

case $RESET_OPTION in
    1)
        OPERATION_MODE="drop"
        warning "Mode: Drop & Create database"
        ;;
    2)
        OPERATION_MODE="empty"
        warning "Mode: Empty existing database"
        ;;
    *)
        error "Invalid option selected"
        exit 1
        ;;
esac

echo ""
warning "This script will:"
if [ "$OPERATION_MODE" = "drop" ]; then
    echo "  1. Drop the existing database"
    echo "  2. Create a new database"
else
    echo "  1. Drop all tables (including migration history)"
    echo "  2. Keep database structure"
fi
echo "  3. Run all migrations (will recreate tables)"
echo "  4. (Optional) Load default roles & permissions"
echo "  5. (Optional) Create admin user"
echo ""

read -p "Continue? (y/N) " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    warning "Operation cancelled."
    exit 0
fi

# Function to empty database tables
empty_database() {
    if [ "$DB_TYPE" = "sqlite" ]; then
        # For SQLite, we need to drop and recreate as TRUNCATE is not efficient
        if [ -f "$DB_FILE" ]; then
            rm "$DB_FILE"
            success "SQLite database file deleted: $DB_FILE"
        else
            warning "SQLite database not found: $DB_FILE (will be created)"
        fi

        # Create database
        if php bin/console doctrine:database:create 2>/dev/null; then
            success "SQLite database recreated"
        else
            warning "Could not create SQLite database (may already exist)"
        fi
    elif [ "$DB_TYPE" = "mysql" ]; then
        info "Dropping MySQL database tables (will be recreated by migrations)..."

        # Use a more reliable method to count tables first
        TABLE_COUNT_RAW=$(php bin/console dbal:run-sql "SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = '$DB_NAME';" 2>&1)
        TABLE_COUNT=$(echo "$TABLE_COUNT_RAW" | grep -oE '[0-9]+' | tail -1)

        if [ -z "$TABLE_COUNT" ] || [ "$TABLE_COUNT" = "0" ]; then
            success "Database is already empty (no tables found)"
            return 0
        fi

        info "Found $TABLE_COUNT tables to drop (including migration history)"

        # Get ALL table names using a simple format
        # Using GROUP_CONCAT to get all tables in one row, easier to parse
        TABLES_LIST_RAW=$(php bin/console dbal:run-sql "SELECT GROUP_CONCAT(table_name SEPARATOR '|') as tables FROM information_schema.tables WHERE table_schema = '$DB_NAME';" 2>&1)

        # Extract the actual list of tables (grep for lines that don't contain status markers)
        TABLES_LIST=$(echo "$TABLES_LIST_RAW" | grep -v "^\[" | grep -v "^+" | grep -v "^-" | grep -v "tables" | grep -v "rows affected" | grep "|" | head -1)

        if [ -z "$TABLES_LIST" ]; then
            error "Could not extract table names from database"
            return 1
        fi

        # Convert pipe-separated list to array
        IFS='|' read -ra TABLES <<< "$TABLES_LIST"

        # Build a single SQL statement with all DROP commands
        # Drop ALL tables at once with FOREIGN_KEY_CHECKS disabled
        DROP_SQL="SET FOREIGN_KEY_CHECKS = 0;"
        for TABLE in "${TABLES[@]}"; do
            # Remove any leading/trailing whitespace
            TABLE=$(echo "$TABLE" | xargs)
            if [ ! -z "$TABLE" ]; then
                DROP_SQL="$DROP_SQL DROP TABLE IF EXISTS \`$TABLE\`;"
                info "  Will drop: $TABLE"
            fi
        done
        DROP_SQL="$DROP_SQL SET FOREIGN_KEY_CHECKS = 1;"

            # Execute all DROP statements in one go
            info "Executing DROP statements for all tables..."
            DROP_OUTPUT=$(php bin/console dbal:run-sql "$DROP_SQL" 2>&1)
            DROP_EXIT=$?

            if [ $DROP_EXIT -eq 0 ]; then
                success "All tables dropped successfully"

                # Verify all tables are gone
                VERIFY_RAW=$(php bin/console dbal:run-sql "SELECT COUNT(*) as count FROM information_schema.tables WHERE table_schema = '$DB_NAME';" 2>&1)
                REMAINING=$(echo "$VERIFY_RAW" | grep -v "^+" | grep -v "^|" | grep -v "count" | grep -v "^$" | grep -v "rows" | grep -v "\[" | grep -v "!" | grep -v "^-*$" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//' | grep -v "^$" | grep -v "^-*$" | head -1)

                if [ "$REMAINING" = "0" ]; then
                    success "✓ Verified: Database is completely empty (0 tables remaining)"
                else
                    warning "⚠ Warning: $REMAINING table(s) may still exist - attempting forced cleanup..."

                    # Force drop any remaining tables
                    REMAINING_TABLES=$(php bin/console dbal:run-sql "SELECT table_name FROM information_schema.tables WHERE table_schema = '$DB_NAME';" 2>&1 | grep -v "^+" | grep -v "^|" | grep -v "table_name" | grep -v "^$" | grep -v "rows" | grep -v "\[" | grep -v "!" | grep -v "^-*$" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//' | grep -v "^$")

                    if [ ! -z "$REMAINING_TABLES" ]; then
                        FORCE_DROP="SET FOREIGN_KEY_CHECKS = 0;"
                        while IFS= read -r TABLE; do
                            TABLE=$(echo "$TABLE" | xargs)
                            if [ ! -z "$TABLE" ]; then
                                FORCE_DROP="$FORCE_DROP DROP TABLE IF EXISTS \`$TABLE\`;"
                                info "  Force dropping: $TABLE"
                            fi
                        done <<< "$REMAINING_TABLES"
                        FORCE_DROP="$FORCE_DROP SET FOREIGN_KEY_CHECKS = 1;"
                        php bin/console dbal:run-sql "$FORCE_DROP" 2>&1 > /dev/null
                        success "Forced cleanup completed"
                    fi
                fi
            else
                error "Failed to drop tables: $DROP_OUTPUT"
                error "Attempting alternative cleanup method..."

                # Alternative: Drop database and recreate (more aggressive)
                warning "Falling back to drop/create database..."
                php bin/console doctrine:database:drop --force 2>&1 && \
                php bin/console doctrine:database:create 2>&1

                if [ $? -eq 0 ]; then
                    success "Database dropped and recreated successfully"
                else
                    error "Failed to drop/create database"
                    return 1
                fi
            fi
        else
            success "Database is already empty (no tables found)"
        fi
    elif [ "$DB_TYPE" = "postgresql" ]; then
        info "Dropping PostgreSQL database tables (will be recreated by migrations)..."

        # Get ALL table names including doctrine_migration_versions
        TABLES_RAW=$(php bin/console dbal:run-sql "SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename;" 2>&1)

        # Extract table names from output (skip header lines, status messages, etc.)
        # Filter status messages that may have leading spaces, then trim whitespace
        TABLES=$(echo "$TABLES_RAW" | grep -v "^+" | grep -v "^|" | grep -v "tablename" | grep -v "^$" | grep -v "rows" | grep -v "\[" | grep -v "!" | grep -v "^-*$" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//' | grep -v "^$" | grep -v "^-*$")

        if [ ! -z "$TABLES" ]; then
            TABLE_COUNT=$(echo "$TABLES" | wc -l | xargs)
            info "Found $TABLE_COUNT tables to drop (including migration history)"

            # Build a single SQL statement with all DROP commands
            # CASCADE ensures all dependent objects are dropped too
            DROP_SQL=""
            while IFS= read -r TABLE; do
                TABLE=$(echo "$TABLE" | xargs)
                if [ ! -z "$TABLE" ]; then
                    DROP_SQL="$DROP_SQL DROP TABLE IF EXISTS \"$TABLE\" CASCADE;"
                fi
            done <<< "$TABLES"

            # Execute all DROP statements in one go
            info "Executing DROP statements for all tables..."
            DROP_OUTPUT=$(php bin/console dbal:run-sql "$DROP_SQL" 2>&1)
            DROP_EXIT=$?

            if [ $DROP_EXIT -eq 0 ]; then
                success "All tables dropped successfully"

                # Verify all tables are gone
                VERIFY_RAW=$(php bin/console dbal:run-sql "SELECT COUNT(*) as count FROM pg_tables WHERE schemaname = 'public';" 2>&1)
                REMAINING=$(echo "$VERIFY_RAW" | grep -v "^+" | grep -v "^|" | grep -v "count" | grep -v "^$" | grep -v "rows" | grep -v "\[" | grep -v "!" | grep -v "^-*$" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//' | grep -v "^$" | grep -v "^-*$" | head -1)

                if [ "$REMAINING" = "0" ]; then
                    success "✓ Verified: Database is completely empty (0 tables remaining)"
                else
                    warning "⚠ Warning: $REMAINING table(s) may still exist - attempting forced cleanup..."

                    # Force drop any remaining tables
                    REMAINING_TABLES=$(php bin/console dbal:run-sql "SELECT tablename FROM pg_tables WHERE schemaname = 'public';" 2>&1 | grep -v "^+" | grep -v "^|" | grep -v "tablename" | grep -v "^$" | grep -v "rows" | grep -v "\[" | grep -v "!" | grep -v "^-*$" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//' | grep -v "^$")

                    if [ ! -z "$REMAINING_TABLES" ]; then
                        FORCE_DROP=""
                        while IFS= read -r TABLE; do
                            TABLE=$(echo "$TABLE" | xargs)
                            if [ ! -z "$TABLE" ]; then
                                FORCE_DROP="$FORCE_DROP DROP TABLE IF EXISTS \"$TABLE\" CASCADE;"
                                info "  Force dropping: $TABLE"
                            fi
                        done <<< "$REMAINING_TABLES"
                        php bin/console dbal:run-sql "$FORCE_DROP" 2>&1 > /dev/null
                        success "Forced cleanup completed"
                    fi
                fi
            else
                error "Failed to drop tables: $DROP_OUTPUT"
                error "Attempting alternative cleanup method..."

                # Alternative: Drop database and recreate (more aggressive)
                warning "Falling back to drop/create database..."
                php bin/console doctrine:database:drop --force 2>&1 && \
                php bin/console doctrine:database:create 2>&1

                if [ $? -eq 0 ]; then
                    success "Database dropped and recreated successfully"
                else
                    error "Failed to drop/create database"
                    return 1
                fi
            fi
        else
            success "Database is already empty (no tables found)"
        fi
    fi
}

echo ""
if [ "$OPERATION_MODE" = "drop" ]; then
    info "Step 1: Dropping existing database..."

    if [ "$DB_TYPE" = "sqlite" ]; then
        if [ -f "$DB_FILE" ]; then
            rm "$DB_FILE"
            success "SQLite database deleted: $DB_FILE"
        else
            warning "SQLite database not found: $DB_FILE (will be created)"
        fi
    else
        if php bin/console doctrine:database:drop --force 2>/dev/null; then
            success "Database dropped"
        else
            warning "Database did not exist"
        fi
    fi

    echo ""
    info "Step 2: Creating database..."
    if php bin/console doctrine:database:create 2>&1; then
        success "Database created"
    else
        error "Failed to create database"
        exit 1
    fi
else
    info "Step 1: Checking if database exists..."

    # Try to create database if it doesn't exist
    DB_CREATE_OUTPUT=$(php bin/console doctrine:database:create 2>&1)
    DB_CREATE_EXIT=$?

    if [ $DB_CREATE_EXIT -eq 0 ]; then
        success "Database created (did not exist)"
    else
        # Check if error is because database already exists
        if echo "$DB_CREATE_OUTPUT" | grep -qi "already exists\|database exists"; then
            success "Database exists"
        else
            error "Failed to verify/create database: $DB_CREATE_OUTPUT"
            exit 1
        fi
    fi

    echo ""
    info "Step 2: Dropping database tables..."
    if ! empty_database; then
        error "Failed to drop database tables"
        exit 1
    fi
fi

# Check and update database configuration if needed
if [ "$DB_TYPE" != "sqlite" ]; then
    echo ""
    info "Database configuration check:"
    read -p "Do you want to update the database configuration in .env.local? (y/N) " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        echo ""
        read -p "Database host [$DB_HOST]: " NEW_DB_HOST
        NEW_DB_HOST=${NEW_DB_HOST:-$DB_HOST}

        read -p "Database port [$DB_PORT]: " NEW_DB_PORT
        NEW_DB_PORT=${NEW_DB_PORT:-$DB_PORT}

        read -p "Database name [$DB_NAME]: " NEW_DB_NAME
        NEW_DB_NAME=${NEW_DB_NAME:-$DB_NAME}

        read -p "Database user [$DB_USER]: " NEW_DB_USER
        NEW_DB_USER=${NEW_DB_USER:-$DB_USER}

        read -s -p "Database password (leave empty to keep current): " NEW_DB_PASS
        echo ""
        NEW_DB_PASS=${NEW_DB_PASS:-$DB_PASS}

        # Update individual variables in .env.local (safe for special characters)
        update_env_var() {
            local var_name=$1
            local var_value=$2

            # Escape special characters for sed
            local escaped_value=$(printf '%s\n' "$var_value" | sed -e 's/[\/&]/\\&/g' -e 's/|/\\|/g')

            if grep -q "^${var_name}=" .env.local; then
                # Use @ as delimiter to avoid conflicts with special chars
                sed -i "s@^${var_name}=.*@${var_name}=\"${escaped_value}\"@" .env.local
            else
                echo "${var_name}=\"${var_value}\"" >> .env.local
            fi
        }

        update_env_var "DB_HOST" "$NEW_DB_HOST"
        update_env_var "DB_PORT" "$NEW_DB_PORT"
        update_env_var "DB_NAME" "$NEW_DB_NAME"
        update_env_var "DB_USER" "$NEW_DB_USER"
        update_env_var "DB_PASS" "$NEW_DB_PASS"

        info "Password stored securely in .env.local"
        warning "Note: DATABASE_URL variable interpolation will handle special characters automatically"

        # Update DB_* variables for current session
        DB_HOST=$NEW_DB_HOST
        DB_PORT=$NEW_DB_PORT
        DB_NAME=$NEW_DB_NAME
        DB_USER=$NEW_DB_USER
        DB_PASS=$NEW_DB_PASS

        success "Database configuration updated in .env.local"
    fi
fi

echo ""
info "Step 3: Running migrations..."
if php bin/console doctrine:migrations:migrate --no-interaction 2>&1; then
    success "Migrations completed"
else
    error "Migrations failed"
    exit 1
fi

echo ""
info "Step 4: Loading default roles & permissions..."
read -p "Run app:setup-permissions? (Y/n) " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Nn]$ ]]; then
    echo ""
    read -p "Create admin user? (Y/n) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Nn]$ ]]; then
        read -p "Admin email [admin@example.com]: " ADMIN_EMAIL
        ADMIN_EMAIL=${ADMIN_EMAIL:-admin@example.com}

        read -s -p "Admin password [admin123]: " ADMIN_PASSWORD
        echo
        ADMIN_PASSWORD=${ADMIN_PASSWORD:-admin123}

        if php bin/console app:setup-permissions \
            --admin-email="$ADMIN_EMAIL" \
            --admin-password="$ADMIN_PASSWORD" 2>&1; then
            success "Roles, permissions, and admin user created"
            echo ""
            info "Login credentials:"
            echo "  Email: $ADMIN_EMAIL"
            echo "  Password: ******* (hidden)"
        else
            error "Failed to setup permissions and admin user"
            exit 1
        fi
    else
        if php bin/console app:setup-permissions 2>&1; then
            success "Roles and permissions created"
        else
            error "Failed to setup permissions"
            exit 1
        fi
    fi
else
    warning "Skipped setup-permissions"
fi

echo ""
info "Step 5: (Optional) Load ISO 27001 Controls..."
read -p "Run isms:load-annex-a-controls? (Y/n) " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Nn]$ ]]; then
    if php bin/console isms:load-annex-a-controls 2>&1; then
        success "ISO 27001 Controls loaded"
    else
        warning "Failed to load ISO 27001 Controls (continuing anyway)"
    fi
else
    warning "Skipped loading controls"
fi

echo ""
info "Step 6: (Optional) Load German Compliance Frameworks..."
echo ""
info "Available frameworks:"
echo "  1. BSI C5:2020 (Cloud Security - 121 criteria)"
echo "  2. BSI C5:2025 Community Draft (43 new requirements)"
echo "  3. KRITIS (Critical Infrastructure - 135 controls)"
echo "  4. KRITIS-Health (Hospital IT Security - 37 requirements)"
echo "  5. DiGAV (Digital Health Apps - 38 requirements)"
echo "  6. TKG-2024 (Telecommunications - 43 requirements)"
echo "  7. GxP (Pharma/Life Sciences - 55 requirements)"
echo ""
read -p "Load German compliance frameworks? (y/N) " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    echo ""
    read -p "  Load BSI C5:2020? (Y/n) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Nn]$ ]]; then
        php bin/console app:load-c5-requirements 2>&1 && success "  ✓ BSI C5:2020 loaded" || warning "  ⚠ C5:2020 failed"
    fi

    read -p "  Load BSI C5:2025 Community Draft? (Y/n) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Nn]$ ]]; then
        php bin/console app:load-c5-2025-requirements 2>&1 && success "  ✓ BSI C5:2025 loaded" || warning "  ⚠ C5:2025 failed"
    fi

    read -p "  Load KRITIS? (Y/n) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Nn]$ ]]; then
        php bin/console app:load-kritis-requirements 2>&1 && success "  ✓ KRITIS loaded" || warning "  ⚠ KRITIS failed"
    fi

    read -p "  Load KRITIS-Health? (Y/n) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Nn]$ ]]; then
        php bin/console app:load-kritis-health-requirements 2>&1 && success "  ✓ KRITIS-Health loaded" || warning "  ⚠ KRITIS-Health failed"
    fi

    read -p "  Load DiGAV? (Y/n) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Nn]$ ]]; then
        php bin/console app:load-digav-requirements 2>&1 && success "  ✓ DiGAV loaded" || warning "  ⚠ DiGAV failed"
    fi

    read -p "  Load TKG-2024? (Y/n) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Nn]$ ]]; then
        php bin/console app:load-tkg-requirements 2>&1 && success "  ✓ TKG-2024 loaded" || warning "  ⚠ TKG-2024 failed"
    fi

    read -p "  Load GxP? (Y/n) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Nn]$ ]]; then
        php bin/console app:load-gxp-requirements 2>&1 && success "  ✓ GxP loaded" || warning "  ⚠ GxP failed"
    fi
else
    warning "Skipped loading German compliance frameworks"
fi

echo ""
echo "=========================================="
success "Database setup completed successfully!"
echo "=========================================="
echo ""
info "Next steps:"
echo "  1. Start the server: symfony serve"
echo "  2. Or use: php -S localhost:8000 -t public/"
echo "  3. Open: http://localhost:8000"
echo "  4. Load additional frameworks via UI: /compliance/frameworks/manage"
echo ""
