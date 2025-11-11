# Multi-stage Dockerfile for Little ISMS Helper
# Stage 1: Production Build
FROM php:8.4-fpm-alpine AS production

# Install system dependencies
RUN apk add --no-cache \
    git \
    unzip \
    libzip-dev \
    postgresql-dev \
    icu-dev \
    oniguruma-dev \
    libpng-dev \
    freetype-dev \
    libjpeg-turbo-dev \
    libxml2-dev \
    nginx \
    supervisor \
    curl

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg && \
    docker-php-ext-install -j$(nproc) \
    pdo \
    pdo_pgsql \
    pgsql \
    intl \
    zip \
    opcache \
    gd \
    mbstring \
    xml \
    soap

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

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

# Now run Symfony scripts (bin/console is now available)
RUN composer run-script --no-dev auto-scripts || true

# Create required directories for logs and cache
RUN mkdir -p var/cache var/log /var/log/supervisor /var/log/nginx && \
    chmod -R 755 var/cache var/log /var/log/supervisor /var/log/nginx

# Set permissions
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html/var && \
    chown -R root:root /var/log/supervisor /var/log/nginx

# Configure PHP for production
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" && \
    echo "opcache.enable=1" >> "$PHP_INI_DIR/conf.d/opcache.ini" && \
    echo "opcache.enable_cli=1" >> "$PHP_INI_DIR/conf.d/opcache.ini" && \
    echo "opcache.memory_consumption=256" >> "$PHP_INI_DIR/conf.d/opcache.ini" && \
    echo "opcache.interned_strings_buffer=16" >> "$PHP_INI_DIR/conf.d/opcache.ini" && \
    echo "opcache.max_accelerated_files=20000" >> "$PHP_INI_DIR/conf.d/opcache.ini" && \
    echo "opcache.validate_timestamps=0" >> "$PHP_INI_DIR/conf.d/opcache.ini"

# Configure PHP-FPM to listen on TCP port instead of socket (required for nginx config)
RUN sed -i 's/listen = .*/listen = 127.0.0.1:9000/' /usr/local/etc/php-fpm.d/www.conf

# Configure Nginx
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf

# Configure Supervisor
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Expose port
EXPOSE 80

# Start supervisor
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

# Stage 2: Development Build
FROM production AS development

# Install development dependencies
RUN apk add --no-cache \
    linux-headers \
    $PHPIZE_DEPS

# Install Xdebug for development
RUN pecl install xdebug && docker-php-ext-enable xdebug

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

# Enable opcache validation in development
RUN echo "opcache.validate_timestamps=1" >> "$PHP_INI_DIR/conf.d/opcache.ini" && \
    echo "opcache.revalidate_freq=2" >> "$PHP_INI_DIR/conf.d/opcache.ini"

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
