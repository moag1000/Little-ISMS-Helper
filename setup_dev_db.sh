#!/bin/bash
# Development Database Setup - Uses schema:create instead of migrations

echo "⚠️  WARNING: This will DELETE ALL DATA in the database!"
echo "This method uses schema:create (good for development)"
echo "Press CTRL+C to cancel, or Enter to continue..."
read

echo "📦 Dropping existing database..."
php bin/console doctrine:database:drop --force --if-exists

echo "🔨 Creating fresh database..."
php bin/console doctrine:database:create

echo "📋 Creating schema from entities (faster for dev)..."
php bin/console doctrine:schema:create

echo "📝 Marking all migrations as executed (to prevent re-running)..."
php bin/console doctrine:migrations:version --add --all --no-interaction

echo "✅ Development database setup completed!"
