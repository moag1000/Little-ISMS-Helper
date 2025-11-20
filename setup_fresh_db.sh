#!/bin/bash
# Fresh Database Setup Script for Little-ISMS-Helper

echo "⚠️  WARNING: This will DELETE ALL DATA in the database!"
echo "Press CTRL+C to cancel, or Enter to continue..."
read

echo "📦 Dropping existing database..."
php bin/console doctrine:database:drop --force --if-exists

echo "🔨 Creating fresh database..."
php bin/console doctrine:database:create

echo "📋 Running all migrations..."
php bin/console doctrine:migrations:migrate --no-interaction

echo "✅ Fresh database setup completed!"
echo ""
echo "Next steps:"
echo "1. Load initial data if needed"
echo "2. Create first user/tenant"
