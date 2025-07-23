# Multi-stage build for optimized production image
FROM php:8.3-cli-alpine AS base

# Install system dependencies
RUN apk add --no-cache \
    git \
    curl \
    libpng-dev \
    libxml2-dev \
    zip \
    unzip \
    sqlite \
    sqlite-dev \
    nodejs \
    npm \
    supervisor \
    openssh-client \
    && docker-php-ext-install pdo pdo_sqlite pcntl \
    && rm -rf /var/cache/apk/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/octomind

# Create octomind user
RUN addgroup -g 1000 octomind && \
    adduser -u 1000 -G octomind -s /bin/sh -D octomind

# Development stage
FROM base AS development

# Install development dependencies
RUN apk add --no-cache \
    bash \
    vim \
    htop \
    curl \
    && docker-php-ext-install pcntl posix

# Copy composer files first for better caching
COPY composer.json composer.lock ./
RUN composer install --no-scripts --no-autoloader --no-dev --prefer-dist

# Copy application code
COPY . .

# Set proper permissions
RUN chown -R octomind:octomind /var/www/octomind \
    && chmod -R 755 /var/www/octomind/storage \
    && chmod -R 755 /var/www/octomind/bootstrap/cache

# Generate autoloader
RUN composer dump-autoload --optimize

# Create necessary directories
RUN mkdir -p storage/app/repositories \
    && mkdir -p storage/logs \
    && mkdir -p storage/framework/cache \
    && mkdir -p storage/framework/sessions \
    && mkdir -p storage/framework/views \
    && chown -R octomind:octomind storage/

USER octomind

EXPOSE 8000

CMD ["php", "artisan", "octomind:start"]

# Production stage
FROM base AS production

# Copy composer files
COPY composer.json composer.lock ./

# Install production dependencies only
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --optimize-autoloader

# Copy application code
COPY . .

# Set proper permissions
RUN chown -R octomind:octomind /var/www/octomind \
    && chmod -R 755 /var/www/octomind/storage \
    && chmod -R 755 /var/www/octomind/bootstrap/cache

# Generate optimized autoloader
RUN composer dump-autoload --optimize --classmap-authoritative

# Create necessary directories
RUN mkdir -p storage/app/repositories \
    && mkdir -p storage/logs \
    && mkdir -p storage/framework/cache \
    && mkdir -p storage/framework/sessions \
    && mkdir -p storage/framework/views \
    && chown -R octomind:octomind storage/

# Create SQLite database file
RUN touch database/database.sqlite \
    && chown octomind:octomind database/database.sqlite

# Copy supervisor configuration
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

USER octomind

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD php artisan octomind:start --config-check || exit 1

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"] 