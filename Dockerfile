# syntax=docker/dockerfile:1

# PHP version. serversideup publishes 8.3 / 8.4 (8.5 once stable).
# composer.json requires php ^8.3, so 8.4 is safe for production.
ARG PHP_VERSION=8.4

#######################################################################
# Stage 1 — Build: composer deps + frontend assets (needs PHP + Node)
#######################################################################
# Wayfinder runs `php artisan wayfinder:generate` during `vite build`,
# so this stage MUST have both PHP (with vendor) and Node available.
FROM serversideup/php:${PHP_VERSION}-cli AS build

USER root
WORKDIR /app

# PHP extensions required by the app (intl is a hard requirement of
# filament/support; gd/exif for image handling, bcmath for money math).
RUN install-php-extensions intl gd bcmath exif

# Install Node 22 (matches local toolchain)
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl ca-certificates gnupg \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# --- Composer dependencies (cached layer) ---
COPY composer.json composer.lock ./
# Build-time .env so artisan can boot during the build (real env is injected
# at runtime by Coolify; this .env is removed before the runtime stage).
COPY .env.example .env
RUN composer install \
        --no-dev \
        --no-interaction \
        --prefer-dist \
        --no-progress \
        --no-scripts

# --- Node dependencies (cached layer) ---
COPY package.json package-lock.json ./
RUN npm ci

# --- Application source ---
COPY . .

# A throwaway key so artisan can boot during the build (real APP_KEY is
# injected at runtime via the environment).
RUN php artisan key:generate --force

# Build frontend first so the Vite manifest exists. This also runs
# wayfinder:generate via the Vite plugin (which boots artisan).
RUN npm run build

# Optimized autoloader + package manifest, now that code + assets exist
RUN composer dump-autoload --optimize --no-dev \
    && php artisan package:discover --ansi

# Drop the build-time .env so it can never leak into the runtime image
RUN rm -f .env

#######################################################################
# Stage 2 — Runtime: Nginx + PHP-FPM (serversideup)
#######################################################################
FROM serversideup/php:${PHP_VERSION}-fpm-nginx AS runtime

# serversideup runs as the unprivileged www-data user by default.
USER root
WORKDIR /var/www/html

# Same PHP extensions at runtime (intl is required by filament/support)
RUN install-php-extensions intl gd bcmath exif

# Copy the fully-built application (vendor + public/build included)
COPY --from=build --chown=www-data:www-data /app /var/www/html

# Make sure Laravel's writable dirs are owned by the runtime user
RUN chown -R www-data:www-data storage bootstrap/cache \
    && rm -rf /var/www/html/node_modules

USER www-data

# Laravel's built-in health endpoint — used by Coolify's health check
HEALTHCHECK --interval=15s --timeout=5s --start-period=40s --retries=5 \
    CMD curl -fsS http://127.0.0.1:8080/up || exit 1
