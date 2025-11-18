#!/bin/sh
# Initialize PostgreSQL for standalone deployment

set -e

PGDATA=/var/lib/postgresql/data

# Ensure directories exist
mkdir -p /run/postgresql
chown postgres:postgres /run/postgresql

# Initialize database if needed
if [ ! -f "$PGDATA/PG_VERSION" ]; then
    echo "Initializing PostgreSQL database..."
    su-exec postgres initdb -D "$PGDATA" --auth-host=md5 --auth-local=trust
    echo "host all all 127.0.0.1/32 md5" >> "$PGDATA/pg_hba.conf"
fi

# Start PostgreSQL in background
echo "Starting PostgreSQL..."
su-exec postgres postgres -D "$PGDATA" &
PG_PID=$!

# Wait for PostgreSQL to be ready
echo "Waiting for PostgreSQL to start..."
for i in $(seq 1 30); do
    if su-exec postgres pg_isready -q; then
        echo "PostgreSQL is ready"
        break
    fi
    sleep 1
done

# Create database and user if they don't exist
NEEDS_MIGRATION=0
if ! su-exec postgres psql -lqt | cut -d \| -f 1 | grep -qw isms; then
    echo "Creating ISMS database and user..."
    su-exec postgres psql -c "CREATE USER isms WITH PASSWORD 'isms';"
    su-exec postgres psql -c "CREATE DATABASE isms OWNER isms;"
    echo "Database created successfully"
    NEEDS_MIGRATION=1
fi

# Run migrations if database was just created
if [ "$NEEDS_MIGRATION" = "1" ]; then
    echo "Running database migrations..."
    cd /var/www/html
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration 2>&1 || echo "Migration failed, will retry on next request"
    echo "Migrations completed"
fi

# Keep PostgreSQL running in foreground
wait $PG_PID
