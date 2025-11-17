# Multi-stage Dockerfile for Little ISMS Helper
# Stage 1: Production Build
FROM php:8.4-fpm-alpine AS production

# OCI Image Labels (https://github.com/opencontainers/image-spec/blob/main/annotations.md)
LABEL org.opencontainers.image.title="Little ISMS Helper"
LABEL org.opencontainers.image.description="Webbasierte ISMS-Lösung für ISO 27001:2022 Compliance, Risiko- und BCM-Management"
LABEL org.opencontainers.image.authors="Little ISMS Helper Project"
LABEL org.opencontainers.image.vendor="Little ISMS Helper Project"
LABEL org.opencontainers.image.licenses="AGPL-3.0-or-later"
LABEL org.opencontainers.image.url="https://github.com/moag1000/Little-ISMS-Helper"
LABEL org.opencontainers.image.source="https://github.com/moag1000/Little-ISMS-Helper"
LABEL org.opencontainers.image.documentation="https://github.com/moag1000/Little-ISMS-Helper/blob/main/README.md"
LABEL maintainer="Little ISMS Helper Project"

# Security: Update all packages to latest security patches
# Addresses CVE-2025-10966 (curl) and other potential vulnerabilities
RUN apk update && apk upgrade --no-cache

# Install system dependencies including MariaDB server for standalone deployment
RUN apk add --no-cache \
    git \
    unzip \
    libzip-dev \
    mariadb-dev \
    mariadb \
    mariadb-client \
    icu-dev \
    oniguruma-dev \
    libpng-dev \
    freetype-dev \
    libjpeg-turbo-dev \
    libxml2-dev \
    nginx \
    supervisor \
    py3-pip \
    curl \
    su-exec

# Upgrade pip and setuptools first, then supervisor to latest version (4.3+)
# This fixes pkg_resources deprecation warning by using importlib.metadata
RUN pip3 install --break-system-packages --upgrade pip setuptools && \
    pip3 install --break-system-packages --upgrade supervisor

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg && \
    docker-php-ext-install -j$(nproc) \
    pdo \
    pdo_mysql \
    mysqli \
    intl \
    zip \
    opcache \
    gd \
    mbstring \
    xml \
    soap

# Install Composer (latest 2.x version)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy composer files first for better caching
COPY composer.json symfony.lock ./
# Note: composer.lock intentionally omitted - will be generated during install

# Install dependencies (production) WITHOUT running scripts (bin/console doesn't exist yet)
# This will generate a fresh composer.lock with the latest compatible versions
RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-scripts --verbose

# Copy application files
COPY . .

# Set environment variables for production
ENV APP_ENV=prod
ENV APP_DEBUG=0
# Default database credentials (can be overridden at runtime via -e flags)
ENV MYSQL_DATABASE=isms
ENV MYSQL_USER=isms
# MYSQL_PASSWORD should be set at runtime for security - if not set, auto-generated
# NOTE: DATABASE_URL is NOT set here - it will be configured by init-mysql.sh in .env.local
# This allows the auto-generated password to be used correctly
# Dummy app secret for build-time (Setup Wizard will generate secure secret)
ENV APP_SECRET="build-time-secret-will-be-replaced"

# Configure PHP for production BEFORE running scripts
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Increase PHP memory limit for Symfony cache:clear and other CLI operations
RUN echo "memory_limit=512M" > "$PHP_INI_DIR/conf.d/memory-limit.ini"

# Set max_execution_time for long-running operations (migrations, imports)
RUN echo "max_execution_time=300" > "$PHP_INI_DIR/conf.d/execution-time.ini"

# Now run Symfony scripts (bin/console is now available, memory limit is set)
# Use a build-time DATABASE_URL that won't persist (init-mysql.sh will set the real one)
RUN DATABASE_URL="mysql://build:build@localhost/isms?serverVersion=mariadb-11.4.0" \
    composer run-script --no-dev auto-scripts || true

# Create required directories for logs and cache
RUN mkdir -p var/cache var/log /var/log/supervisor /var/log/nginx && \
    chmod -R 755 var/cache var/log /var/log/supervisor /var/log/nginx

# Set permissions
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html/var && \
    chown -R root:root /var/log/supervisor /var/log/nginx

# Configure OPcache for production (separate file for better management)
RUN cat > "$PHP_INI_DIR/conf.d/opcache-prod.ini" <<'EOF'
opcache.enable=1
opcache.enable_cli=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0
opcache.revalidate_freq=0
EOF

# Configure PHP-FPM to use Unix socket for better performance
RUN sed -i 's|listen = .*|listen = /run/php-fpm.sock|' /usr/local/etc/php-fpm.d/www.conf && \
    sed -i 's|;listen.owner|listen.owner|' /usr/local/etc/php-fpm.d/www.conf && \
    sed -i 's|;listen.group|listen.group|' /usr/local/etc/php-fpm.d/www.conf && \
    sed -i 's|;listen.mode = 0660|listen.mode = 0660|' /usr/local/etc/php-fpm.d/www.conf && \
    sed -i 's|listen = 9000|listen = /run/php-fpm.sock|' /usr/local/etc/php-fpm.d/zz-docker.conf && \
    mkdir -p /run/php-fpm && \
    chown www-data:www-data /run/php-fpm

# Configure MariaDB for standalone deployment
RUN mkdir -p /var/lib/mysql /run/mysqld && \
    chown -R mysql:mysql /var/lib/mysql /run/mysqld && \
    chmod 755 /var/lib/mysql /run/mysqld

# Copy MariaDB initialization script
COPY docker/scripts/init-mysql.sh /var/www/html/docker/scripts/init-mysql.sh
RUN chmod +x /var/www/html/docker/scripts/init-mysql.sh

# Configure Nginx
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf

# Configure Supervisor
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Health check to verify the application is running
HEALTHCHECK --interval=30s --timeout=5s --retries=3 --start-period=40s \
    CMD curl -f http://localhost/health || exit 1

# Expose port
EXPOSE 80

# Volume for persistent database storage
VOLUME ["/var/lib/mysql"]

# Start supervisor (use supervisord from PATH - pip installs to /usr/local/bin)
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

# Stage 2: Development Build
FROM production AS development

# Install development dependencies
RUN apk add --no-cache \
    linux-headers \
    $PHPIZE_DEPS

# Install Xdebug for development (latest stable version)
RUN pecl channel-update pecl.php.net && \
    pecl install xdebug && docker-php-ext-enable xdebug

# Configure Xdebug
RUN echo "xdebug.mode=debug,coverage" >> "$PHP_INI_DIR/conf.d/docker-php-ext-xdebug.ini" && \
    echo "xdebug.client_host=host.docker.internal" >> "$PHP_INI_DIR/conf.d/docker-php-ext-xdebug.ini" && \
    echo "xdebug.client_port=9003" >> "$PHP_INI_DIR/conf.d/docker-php-ext-xdebug.ini" && \
    echo "xdebug.start_with_request=yes" >> "$PHP_INI_DIR/conf.d/docker-php-ext-xdebug.ini"

# Use development PHP configuration
# Note: Production stage already moved php.ini-production to php.ini
# So we just need to replace it with the development version
RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

# Clean vendor from production stage and install all dependencies including dev
# Use --no-scripts first since we already have bin/console but need to reinstall vendor
RUN rm -rf vendor/ && \
    composer install --optimize-autoloader --no-interaction --no-scripts --verbose && \
    composer run-script auto-scripts || true

# Replace production OPcache config with development version
RUN cat > "$PHP_INI_DIR/conf.d/opcache-prod.ini" <<'EOF'
opcache.enable=1
opcache.enable_cli=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=1
opcache.revalidate_freq=2
EOF

CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
