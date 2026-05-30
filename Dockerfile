FROM php:8.5-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpq-dev \
    postgresql-client \
    libpng-dev \
    libjpeg-dev \
    libwebp-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libmagickwand-dev \
    imagemagick \
    ffmpeg \
    poppler-utils \
    libimage-exiftool-perl \
    zip \
    unzip \
    ca-certificates \
    gnupg \
    supervisor \
    nginx \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install pdo pdo_pgsql pgsql mbstring exif pcntl bcmath gd zip \
    && pecl install redis imagick \
    && docker-php-ext-enable redis imagick \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Node.js 22 LTS (Debian's nodejs ships an old 18.x that can't build
# Vite 8 / vue-tsc). Keep this step after the apt cleanup so the NodeSource
# layer caches independently.
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Upload limits — kept slightly below nginx's client_max_body_size
RUN { \
        echo 'upload_max_filesize=500M'; \
        echo 'post_max_size=550M'; \
        echo 'memory_limit=768M'; \
    } > /usr/local/etc/php/conf.d/uploads.ini

# OPcache — caches compiled PHP bytecode in shared memory so Laravel's ~10k
# files aren't re-parsed per request/worker. Tuned for a Laravel app and kept
# dev-safe: validate_timestamps=1 picks up code edits. For maximum production
# throughput set opcache.validate_timestamps=0 (requires a restart to deploy
# code) and enable JIT.
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=0'; \
        echo 'opcache.memory_consumption=192'; \
        echo 'opcache.interned_strings_buffer=16'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=1'; \
        echo 'opcache.revalidate_freq=0'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Handy `a` shim for `php artisan` so `docker compose exec app a queue:flush` works
RUN printf '#!/bin/sh\nexec php /var/www/html/artisan "$@"\n' > /usr/local/bin/a \
    && chmod +x /usr/local/bin/a

# Set working directory
WORKDIR /var/www/html

# Copy composer files first for better caching
COPY composer.json composer.lock ./
RUN composer install --no-scripts --no-autoloader

# Copy package.json for npm
COPY package.json package-lock.json ./
RUN npm install

# Copy the rest of the application
COPY . .

# Generate autoloader
RUN composer dump-autoload --optimize

# Build frontend assets
RUN npm run build

# Copy nginx config
COPY docker/nginx.conf /etc/nginx/sites-available/default

# Copy supervisor config
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Copy entrypoint script
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Set permissions
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
RUN chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 80

# Container healthcheck — uses the public /up route, which now pings the
# database and Redis via the DiagnosingHealth listener. Gives orchestrators
# (docker-compose, k8s, Swarm, Nomad) a real readiness signal.
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl --fail --silent --max-time 4 http://127.0.0.1/up || exit 1

ENTRYPOINT ["entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
