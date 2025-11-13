#!/bin/bash
# Test script to verify README installation instructions
# This script validates that all setup commands work correctly

# set -e  # Exit on any error - disabled for testing

echo "=========================================="
echo "Testing Little ISMS Helper Setup Process"
echo "=========================================="
echo ""

# Color codes for output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Function to print success
success() {
    echo -e "${GREEN}✓ $1${NC}"
}

# Function to print error
error() {
    echo -e "${RED}✗ $1${NC}"
}

# Function to print warning
warning() {
    echo -e "${YELLOW}⚠ $1${NC}"
}

# Function to print step
step() {
    echo ""
    echo "===> $1"
}

# Track test results
TESTS_PASSED=0
TESTS_FAILED=0
WARNINGS=0

# Step 1: Check if we're in the right directory
step "Step 1: Verifying directory structure"
if [ -f "composer.json" ] && [ -f "symfony.lock" ]; then
    success "Directory structure looks correct"
    ((TESTS_PASSED++))
else
    error "Not in Little-ISMS-Helper directory or missing files"
    ((TESTS_FAILED++))
    exit 1
fi

# Step 2: Check if .env exists
step "Step 2: Checking .env file"
if [ -f ".env" ]; then
    success ".env file exists"
    ((TESTS_PASSED++))
else
    error ".env file not found"
    ((TESTS_FAILED++))
fi

# Step 3: Test .env.local creation
step "Step 3: Testing .env.local creation"
if [ -f ".env.local" ]; then
    warning ".env.local already exists - backing up to .env.local.backup"
    cp .env.local .env.local.backup
    ((WARNINGS++))
fi

cp .env .env.local.test
if [ -f ".env.local.test" ]; then
    success "Can create .env.local (test file created)"
    rm .env.local.test
    ((TESTS_PASSED++))
else
    error "Cannot create .env.local"
    ((TESTS_FAILED++))
fi

# Step 4: Test APP_SECRET generation
step "Step 4: Testing APP_SECRET generation"
TEST_SECRET=$(openssl rand -hex 32)
if [ ${#TEST_SECRET} -eq 64 ]; then
    success "APP_SECRET generation works (length: ${#TEST_SECRET})"
    ((TESTS_PASSED++))
else
    error "APP_SECRET generation failed (length: ${#TEST_SECRET})"
    ((TESTS_FAILED++))
fi

# Step 5: Check database URL configuration
step "Step 5: Checking DATABASE_URL in .env"
if grep -q "DATABASE_URL=" .env; then
    DB_URL=$(grep "DATABASE_URL=" .env | head -1)
    success "DATABASE_URL is configured: ${DB_URL:0:50}..."
    ((TESTS_PASSED++))
else
    error "DATABASE_URL not found in .env"
    ((TESTS_FAILED++))
fi

# Step 6: Check if migrations exist
step "Step 6: Checking database migrations"
if [ -d "migrations" ] && [ "$(ls -A migrations)" ]; then
    MIGRATION_COUNT=$(ls migrations/*.php 2>/dev/null | wc -l)
    success "Found $MIGRATION_COUNT migration files"
    ((TESTS_PASSED++))
else
    error "No migration files found"
    ((TESTS_FAILED++))
fi

# Step 7: Check if custom commands exist
step "Step 7: Verifying custom console commands"
COMMANDS=(
    "LoadAnnexAControlsCommand.php"
    "SetupPermissionsCommand.php"
    "LoadTisaxRequirementsCommand.php"
    "LoadDoraRequirementsCommand.php"
    "LoadC5RequirementsCommand.php"
    "LoadC52025RequirementsCommand.php"
    "LoadKritisRequirementsCommand.php"
    "LoadKritisHealthRequirementsCommand.php"
    "LoadDigavRequirementsCommand.php"
    "LoadTkgRequirementsCommand.php"
    "LoadGxpRequirementsCommand.php"
)
for cmd in "${COMMANDS[@]}"; do
    if [ -f "src/Command/$cmd" ]; then
        success "Command exists: $cmd"
        ((TESTS_PASSED++))
    else
        error "Command missing: $cmd"
        ((TESTS_FAILED++))
    fi
done

# Step 8: Verify command names in files
step "Step 8: Verifying command names"
EXPECTED_COMMANDS=(
    "isms:load-annex-a-controls"
    "app:setup-permissions"
    "app:load-tisax-requirements"
    "app:load-dora-requirements"
    "app:load-c5-requirements"
    "app:load-c5-2025-requirements"
    "app:load-kritis-requirements"
    "app:load-kritis-health-requirements"
    "app:load-digav-requirements"
    "app:load-tkg-requirements"
    "app:load-gxp-requirements"
)

for cmd_name in "${EXPECTED_COMMANDS[@]}"; do
    if grep -r "name: '$cmd_name'" src/Command/ >/dev/null 2>&1; then
        success "Command name verified: $cmd_name"
        ((TESTS_PASSED++))
    else
        error "Command name not found: $cmd_name"
        ((TESTS_FAILED++))
    fi
done

# Step 9: Check Doctrine configuration
step "Step 9: Checking Doctrine configuration"
if [ -f "config/packages/doctrine.yaml" ]; then
    success "Doctrine configuration exists"
    ((TESTS_PASSED++))
else
    error "Doctrine configuration missing"
    ((TESTS_FAILED++))
fi

# Step 10: Verify Entity classes
step "Step 10: Verifying Entity classes"
ENTITIES=("User" "Role" "Permission" "Asset" "Risk" "Control" "Incident")
for entity in "${ENTITIES[@]}"; do
    if [ -f "src/Entity/$entity.php" ]; then
        success "Entity exists: $entity"
        ((TESTS_PASSED++))
    else
        error "Entity missing: $entity"
        ((TESTS_FAILED++))
    fi
done

# Step 11: Check if vendor directory would be created by composer
step "Step 11: Checking composer.json"
if [ -f "composer.json" ]; then
    if grep -q "symfony/framework-bundle" composer.json; then
        success "composer.json has required Symfony packages"
        ((TESTS_PASSED++))
    else
        error "composer.json missing Symfony framework"
        ((TESTS_FAILED++))
    fi
else
    error "composer.json not found"
    ((TESTS_FAILED++))
fi

# Step 12: Verify importmap configuration
step "Step 12: Checking importmap configuration"
if [ -f "importmap.php" ]; then
    success "importmap.php exists"
    ((TESTS_PASSED++))
else
    warning "importmap.php not found (will be created by importmap:install)"
    ((WARNINGS++))
fi

# Step 13: Check var directory permissions
step "Step 13: Checking var directory"
if [ -d "var" ]; then
    if [ -w "var" ]; then
        success "var directory is writable"
        ((TESTS_PASSED++))
    else
        error "var directory is not writable"
        ((TESTS_FAILED++))
    fi
else
    warning "var directory does not exist (will be created)"
    ((WARNINGS++))
fi

# Step 14: Verify public directory structure
step "Step 14: Checking public directory"
if [ -f "public/index.php" ]; then
    success "public/index.php exists"
    ((TESTS_PASSED++))
else
    error "public/index.php missing"
    ((TESTS_FAILED++))
fi

# Summary
echo ""
echo "=========================================="
echo "Test Summary"
echo "=========================================="
echo -e "${GREEN}Tests Passed: $TESTS_PASSED${NC}"
if [ $TESTS_FAILED -gt 0 ]; then
    echo -e "${RED}Tests Failed: $TESTS_FAILED${NC}"
fi
if [ $WARNINGS -gt 0 ]; then
    echo -e "${YELLOW}Warnings: $WARNINGS${NC}"
fi
echo ""

if [ $TESTS_FAILED -eq 0 ]; then
    success "All critical tests passed! ✓"
    echo ""
    echo "The setup instructions in README.md should work correctly."
    echo "Note: This script validates structure only. Actual installation"
    echo "requires running 'composer install' and the database commands."
    exit 0
else
    error "Some tests failed. Please check the errors above."
    exit 1
fi
