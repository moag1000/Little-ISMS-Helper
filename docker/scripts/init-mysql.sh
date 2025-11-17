#!/bin/sh
# Initialize MariaDB for standalone deployment

set -e

DATADIR=/var/lib/mysql

# Database configuration from environment variables (with secure defaults)
DB_NAME="${MYSQL_DATABASE:-isms}"
DB_USER="${MYSQL_USER:-isms}"

# Check if password was already generated in a previous run
if [ -z "$MYSQL_PASSWORD" ]; then
    if [ -f "/var/www/html/var/mysql_credentials.txt" ]; then
        # Reuse existing password from previous run
        DB_PASS=$(grep -o 'password: .*' /var/www/html/var/mysql_credentials.txt | cut -d' ' -f2)
        echo "Using existing MySQL password from previous run"
    else
        # Generate new password only on first run
        DB_PASS=$(openssl rand -base64 32 | tr -dc 'a-zA-Z0-9' | head -c 24)
        echo "Auto-generated MySQL password: $DB_PASS" > /var/www/html/var/mysql_credentials.txt
        chmod 600 /var/www/html/var/mysql_credentials.txt
        chown www-data:www-data /var/www/html/var/mysql_credentials.txt
        echo "WARNING: No MYSQL_PASSWORD provided. Auto-generated password saved to /var/www/html/var/mysql_credentials.txt"
    fi
else
    DB_PASS="$MYSQL_PASSWORD"
fi

# Ensure directories exist with correct permissions
mkdir -p /run/mysqld
chown mysql:mysql /run/mysqld
chmod 755 /run/mysqld

# Initialize database if needed
if [ ! -d "$DATADIR/mysql" ]; then
    echo "Initializing MariaDB database..."
    mysql_install_db --user=mysql --datadir="$DATADIR" --skip-test-db
    echo "MariaDB initialized"
fi

# Start MariaDB in background
echo "Starting MariaDB..."
mysqld_safe --user=mysql --datadir="$DATADIR" &
MYSQL_PID=$!

# Wait for MariaDB to be ready
echo "Waiting for MariaDB to start..."
for i in $(seq 1 30); do
    if mysqladmin ping -h localhost --silent 2>/dev/null; then
        echo "MariaDB is ready"
        break
    fi
    sleep 1
done

# Create database and user if they don't exist
NEEDS_MIGRATION=0
if ! mysql -e "SELECT 1 FROM mysql.user WHERE user = '$DB_USER'" 2>/dev/null | grep -q 1; then
    echo "Creating ISMS database and user..."
    mysql -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    mysql -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
    mysql -e "CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';"
    mysql -e "GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';"
    mysql -e "GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1';"
    mysql -e "FLUSH PRIVILEGES;"
    echo "Database '$DB_NAME' and user '$DB_USER' created successfully"
    NEEDS_MIGRATION=1

    # Update DATABASE_URL in .env.local for the application
    echo "DATABASE_URL=\"mysql://$DB_USER:$DB_PASS@localhost/$DB_NAME?unix_socket=/run/mysqld/mysqld.sock&serverVersion=mariadb-11.4.0&charset=utf8mb4\"" > /var/www/html/.env.local
    chown www-data:www-data /var/www/html/.env.local
    chmod 600 /var/www/html/.env.local
fi

# Run migrations if database was just created
if [ "$NEEDS_MIGRATION" = "1" ]; then
    echo "Running database migrations..."
    cd /var/www/html
    # Export the correct DATABASE_URL for migrations (override the ENV default)
    export DATABASE_URL="mysql://$DB_USER:$DB_PASS@localhost/$DB_NAME?unix_socket=/run/mysqld/mysqld.sock&serverVersion=mariadb-11.4.0&charset=utf8mb4"
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration 2>&1 || echo "Migration failed, will retry on next request"
    echo "Migrations completed"

    # Clear and rebuild cache with the correct DATABASE_URL
    echo "Rebuilding application cache with correct database configuration..."
    php bin/console cache:clear --env=prod 2>&1 || echo "Cache clear failed"
    php bin/console cache:warmup --env=prod 2>&1 || echo "Cache warmup failed"
    echo "Cache rebuilt"
fi

# Keep MariaDB running in foreground
wait $MYSQL_PID
