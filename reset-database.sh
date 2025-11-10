#!/bin/bash
# Little ISMS Helper - Database Reset and Migration Script
# Use this to clean up and re-run migrations after fixing migration issues

set -e

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

# Determine database type from URL
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
    info "Database type: MySQL/MariaDB"
elif [[ $DB_URL == postgresql* ]]; then
    DB_TYPE="postgresql"
    info "Database type: PostgreSQL"
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
echo "  [2] Empty existing (Clear all tables, keep database)"
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
    echo "  1. Empty all tables in existing database"
    echo "  2. Keep database and user credentials"
fi
echo "  3. Run all migrations"
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
        php bin/console doctrine:database:create 2>/dev/null || true
        success "SQLite database recreated"
    elif [ "$DB_TYPE" = "mysql" ]; then
        info "Emptying MySQL database tables..."
        # Get list of tables and truncate them
        php bin/console dbal:run-sql "SET FOREIGN_KEY_CHECKS = 0;" 2>/dev/null || true

        # Get table names and truncate each one
        TABLES=$(php bin/console dbal:run-sql "SELECT GROUP_CONCAT(table_name) FROM information_schema.tables WHERE table_schema = '$DB_NAME';" 2>/dev/null | tail -1)

        if [ ! -z "$TABLES" ] && [ "$TABLES" != "NULL" ]; then
            # Split tables by comma and truncate each
            IFS=',' read -ra TABLE_ARRAY <<< "$TABLES"
            for TABLE in "${TABLE_ARRAY[@]}"; do
                php bin/console dbal:run-sql "TRUNCATE TABLE \`$TABLE\`;" 2>/dev/null || true
            done
            success "All tables emptied"
        else
            warning "No tables found to empty"
        fi

        php bin/console dbal:run-sql "SET FOREIGN_KEY_CHECKS = 1;" 2>/dev/null || true
    elif [ "$DB_TYPE" = "postgresql" ]; then
        info "Emptying PostgreSQL database tables..."
        # Get list of tables and truncate them
        TABLES=$(php bin/console dbal:run-sql "SELECT string_agg(tablename, ',') FROM pg_tables WHERE schemaname = 'public';" 2>/dev/null | tail -1)

        if [ ! -z "$TABLES" ] && [ "$TABLES" != "" ]; then
            # Truncate all tables at once
            php bin/console dbal:run-sql "TRUNCATE TABLE $TABLES CASCADE;" 2>/dev/null || true
            success "All tables emptied"
        else
            warning "No tables found to empty"
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
        php bin/console doctrine:database:drop --force 2>/dev/null || warning "Database did not exist"
        success "Database dropped"
    fi

    echo ""
    info "Step 2: Creating database..."
    php bin/console doctrine:database:create
    success "Database created"
else
    info "Step 1: Checking if database exists..."

    # Try to create database if it doesn't exist
    php bin/console doctrine:database:create 2>/dev/null && success "Database created (did not exist)" || success "Database exists"

    echo ""
    info "Step 2: Emptying existing database..."
    empty_database
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

        # Update individual variables in .env.local
        update_env_var() {
            local var_name=$1
            local var_value=$2

            if grep -q "^${var_name}=" .env.local; then
                sed -i "s|^${var_name}=.*|${var_name}=\"${var_value}\"|" .env.local
            else
                echo "${var_name}=\"${var_value}\"" >> .env.local
            fi
        }

        update_env_var "DB_HOST" "$NEW_DB_HOST"
        update_env_var "DB_PORT" "$NEW_DB_PORT"
        update_env_var "DB_NAME" "$NEW_DB_NAME"
        update_env_var "DB_USER" "$NEW_DB_USER"
        update_env_var "DB_PASS" "$NEW_DB_PASS"

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
php bin/console doctrine:migrations:migrate --no-interaction
success "Migrations completed"

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

        php bin/console app:setup-permissions \
            --admin-email="$ADMIN_EMAIL" \
            --admin-password="$ADMIN_PASSWORD"
        success "Roles, permissions, and admin user created"
        echo ""
        info "Login credentials:"
        echo "  Email: $ADMIN_EMAIL"
        echo "  Password: ******* (hidden)"
    else
        php bin/console app:setup-permissions
        success "Roles and permissions created"
    fi
else
    warning "Skipped setup-permissions"
fi

echo ""
info "Step 5: (Optional) Load ISO 27001 Controls..."
read -p "Run isms:load-annex-a-controls? (Y/n) " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Nn]$ ]]; then
    php bin/console isms:load-annex-a-controls
    success "ISO 27001 Controls loaded"
else
    warning "Skipped loading controls"
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
echo ""
