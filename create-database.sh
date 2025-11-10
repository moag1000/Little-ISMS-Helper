#!/bin/bash
# Little ISMS Helper - Database Creation & Setup Script
# Creates a new database and runs complete setup
# Safe to run on fresh installations (won't drop existing database)

set -e

echo "=========================================="
echo "Little ISMS Helper"
echo "Database Creation & Setup Tool"
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

# Step 0: Prerequisites check
info "Checking prerequisites..."

# Check PHP
if ! command -v php &> /dev/null; then
    error "PHP is not installed or not in PATH"
    exit 1
fi
success "PHP found: $(php -v | head -1)"

# Check composer
if [ ! -f "composer.json" ]; then
    error "composer.json not found! Are you in the project root?"
    exit 1
fi
success "Project root detected"

# Check if .env exists
if [ ! -f ".env" ]; then
    error ".env file not found!"
    exit 1
fi
success ".env file found"

# Check if .env.local exists
if [ ! -f ".env.local" ]; then
    warning ".env.local not found. Creating from .env..."
    cp .env .env.local

    # Generate APP_SECRET if not set
    if ! grep -q "^APP_SECRET=" .env.local || grep -q "^APP_SECRET=$" .env.local; then
        info "Generating APP_SECRET..."
        APP_SECRET=$(openssl rand -hex 32)
        echo "" >> .env.local
        echo "APP_SECRET=${APP_SECRET}" >> .env.local
        success "APP_SECRET generated"
    fi
fi
success ".env.local found"

# Get database URL from .env.local
DB_URL=$(grep "^DATABASE_URL=" .env.local | head -1 | cut -d '=' -f 2- | tr -d '"')

if [ -z "$DB_URL" ]; then
    # Fall back to .env
    DB_URL=$(grep "^DATABASE_URL=" .env | head -1 | cut -d '=' -f 2- | tr -d '"')
fi

if [ -z "$DB_URL" ]; then
    error "DATABASE_URL not found in .env or .env.local"
    exit 1
fi

info "Database URL: ${DB_URL:0:50}..."
echo ""

# Determine database type and extract connection details
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
    # Extract database name from URL (format: mysql://user:pass@host:port/dbname)
    DB_NAME=$(echo $DB_URL | sed -n 's|.*\/\([^?]*\).*|\1|p')
    DB_USER=$(echo $DB_URL | sed -n 's|.*://\([^:]*\):.*|\1|p')
    info "Database type: MySQL/MariaDB"
    info "Database name: $DB_NAME"
    info "Database user: $DB_USER"
elif [[ $DB_URL == postgresql* ]] || [[ $DB_URL == pgsql* ]]; then
    DB_TYPE="postgresql"
    # Extract database name from URL
    DB_NAME=$(echo $DB_URL | sed -n 's|.*\/\([^?]*\).*|\1|p')
    DB_USER=$(echo $DB_URL | sed -n 's|.*://\([^:]*\):.*|\1|p')
    info "Database type: PostgreSQL"
    info "Database name: $DB_NAME"
    info "Database user: $DB_USER"
else
    error "Unknown database type in DATABASE_URL"
    exit 1
fi

echo ""

# Check and update database configuration if needed
if [ "$DB_TYPE" != "sqlite" ]; then
    echo ""
    info "Database configuration check:"
    read -p "Do you want to configure database credentials before creating? (y/N) " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        echo ""
        read -p "Database name [$DB_NAME]: " NEW_DB_NAME
        NEW_DB_NAME=${NEW_DB_NAME:-$DB_NAME}

        read -p "Database user [$DB_USER]: " NEW_DB_USER
        NEW_DB_USER=${NEW_DB_USER:-$DB_USER}

        read -s -p "Database password (leave empty to keep current): " NEW_DB_PASS
        echo ""

        if [ ! -z "$NEW_DB_NAME" ] || [ ! -z "$NEW_DB_USER" ] || [ ! -z "$NEW_DB_PASS" ]; then
            # Update DATABASE_URL in .env.local
            if [ "$DB_TYPE" = "mysql" ]; then
                if [ -z "$NEW_DB_PASS" ]; then
                    # Extract current password
                    CURRENT_PASS=$(echo $DB_URL | sed -n 's|.*:\([^@]*\)@.*|\1|p')
                    NEW_DB_PASS=$CURRENT_PASS
                fi
                HOST_PORT=$(echo $DB_URL | sed -n 's|.*@\(.*\)/.*|\1|p')
                NEW_DB_URL="mysql://${NEW_DB_USER}:${NEW_DB_PASS}@${HOST_PORT}/${NEW_DB_NAME}"
            elif [ "$DB_TYPE" = "postgresql" ]; then
                if [ -z "$NEW_DB_PASS" ]; then
                    # Extract current password
                    CURRENT_PASS=$(echo $DB_URL | sed -n 's|.*:\([^@]*\)@.*|\1|p')
                    NEW_DB_PASS=$CURRENT_PASS
                fi
                HOST_PORT=$(echo $DB_URL | sed -n 's|.*@\(.*\)/.*|\1|p')
                # Extract additional parameters if any
                PARAMS=$(echo $DB_URL | sed -n 's|.*?\(.*\)|\1|p')
                if [ ! -z "$PARAMS" ]; then
                    NEW_DB_URL="postgresql://${NEW_DB_USER}:${NEW_DB_PASS}@${HOST_PORT}/${NEW_DB_NAME}?${PARAMS}"
                else
                    NEW_DB_URL="postgresql://${NEW_DB_USER}:${NEW_DB_PASS}@${HOST_PORT}/${NEW_DB_NAME}?serverVersion=16&charset=utf8"
                fi
            fi

            # Update .env.local
            if grep -q "^DATABASE_URL=" .env.local; then
                sed -i "s|^DATABASE_URL=.*|DATABASE_URL=\"${NEW_DB_URL}\"|" .env.local
                success "Database configuration updated in .env.local"

                # Update variables for further use
                DB_URL=$NEW_DB_URL
                DB_NAME=$NEW_DB_NAME
                DB_USER=$NEW_DB_USER
            else
                echo "DATABASE_URL=\"${NEW_DB_URL}\"" >> .env.local
                success "Database configuration added to .env.local"

                # Update variables for further use
                DB_URL=$NEW_DB_URL
                DB_NAME=$NEW_DB_NAME
                DB_USER=$NEW_DB_USER
            fi
        fi
    fi
fi

# Check if database already exists and handle accordingly
echo ""
info "Checking if database exists..."

if [ "$DB_TYPE" = "sqlite" ]; then
    # For SQLite, check if file exists
    if [ -f "$DB_FILE" ]; then
        warning "Database file already exists: $DB_FILE"
        echo ""
        read -p "Delete and recreate? (y/N): " -n 1 -r
        echo

        if [[ $REPLY =~ ^[Yy]$ ]]; then
            rm -f "$DB_FILE"
            success "SQLite database deleted"
        else
            warning "Keeping existing database. Exiting."
            exit 0
        fi
    fi

    # Ensure var/ directory exists
    mkdir -p "$(dirname "$DB_FILE")"
else
    # For MySQL/PostgreSQL, try to create and handle error if exists
    # This is the safest approach - let Doctrine handle it
    :  # No pre-check needed
fi

info "Creating database..."
CREATE_OUTPUT=$(php bin/console doctrine:database:create 2>&1)
CREATE_EXIT=$?

if [ $CREATE_EXIT -eq 0 ]; then
    success "Database created"
elif echo "$CREATE_OUTPUT" | grep -qi "already exists\|database exists"; then
    # Database already exists
    warning "Database already exists!"
    echo ""
    echo "→ The database is already set up."
    echo ""
    read -p "Drop and recreate? (y/N): " -n 1 -r
    echo

    if [[ $REPLY =~ ^[Yy]$ ]]; then
        info "Dropping existing database..."
        php bin/console doctrine:database:drop --force
        success "Database dropped"

        info "Creating fresh database..."
        php bin/console doctrine:database:create
        success "Database created"
    else
        warning "Keeping existing database. Exiting."
        exit 0
    fi
else
    # Some other error occurred
    error "Failed to create database"
    echo "$CREATE_OUTPUT"
    exit 1
fi

echo ""
info "Running migrations..."
php bin/console doctrine:migrations:migrate --no-interaction
success "All migrations completed"

echo ""
info "Setting up roles & permissions..."

# Ask for admin credentials upfront
read -p "Create admin user? (Y/n) " -n 1 -r
echo

if [[ ! $REPLY =~ ^[Nn]$ ]]; then
    echo ""
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
    success "Roles and permissions created (no admin user)"
fi

echo ""
info "Loading ISO 27001:2022 Controls..."
read -p "Load Annex A controls? (Y/n) " -n 1 -r
echo

if [[ ! $REPLY =~ ^[Nn]$ ]]; then
    if php bin/console isms:load-annex-a-controls 2>/dev/null; then
        success "ISO 27001 Controls loaded"
    else
        warning "isms:load-annex-a-controls command not found (skipped)"
    fi
else
    warning "Skipped loading controls"
fi

echo ""
info "Validating database schema..."
if php bin/console doctrine:schema:validate --skip-sync 2>&1 | grep -q "database schema is in sync"; then
    success "Database schema is valid and in sync"
else
    warning "Schema validation warnings (this may be normal)"
fi

echo ""
echo "=========================================="
success "Database setup completed successfully!"
echo "=========================================="
echo ""
info "Database Summary:"
echo "  Type: $DB_TYPE"
if [ "$DB_TYPE" = "sqlite" ]; then
    echo "  File: $DB_FILE"
else
    echo "  Database: $DB_NAME"
    echo "  User: $DB_USER"
fi
echo "  Migrations: Executed ✓"
echo "  Roles: 4 (USER, AUDITOR, MANAGER, ADMIN)"
echo "  Permissions: Setup completed ✓"
if [[ ! $REPLY =~ ^[Nn]$ ]]; then
    echo "  Admin User: Created ✓"
fi
echo ""
info "Next steps:"
echo "  1. Start the development server:"
echo "     symfony serve -d"
echo "     OR"
echo "     php -S localhost:8000 -t public/"
echo ""
echo "  2. Open your browser:"
echo "     http://localhost:8000"
echo ""
echo "  3. Login with your admin credentials"
echo ""
success "Ready to use!"
echo ""
