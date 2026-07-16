# syntax=docker/dockerfile:1
#
# ADVS web/app image for Render (Docker). The same image runs both the web
# service and the queue worker (the worker overrides the command in render.yaml).
#
# Build order matters: Tailwind v4 reads Flux's CSS/stubs from vendor/ (see
# resources/css/app.css), so PHP dependencies are installed BEFORE the asset
# build, and the built assets + vendor/ are copied into the runtime image.

# ── Stage 1: PHP dependencies (Composer) ──────────────────────────────────────
FROM composer:2 AS vendor
WORKDIR /app
COPY . .
# The runtime image (serversideup) carries the real PHP extensions, so skip the
# platform check here; skip scripts because artisan package:discover runs at boot.
RUN composer install \
        --no-dev \
        --no-scripts \
        --prefer-dist \
        --no-interaction \
        --optimize-autoloader \
        --ignore-platform-reqs

# ── Stage 2: Frontend assets (Vite + Tailwind v4) ─────────────────────────────
# Debian (glibc) base to match the *-linux-x64-gnu native binaries pinned in
# package-lock.json (rollup / tailwind oxide / lightningcss) — an Alpine/musl
# base would fail to resolve them under `npm ci`.
FROM node:20-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js ./
COPY resources ./resources
# Flux ships its CSS + blade stubs in vendor/ — required for Tailwind to detect
# the classes it uses.
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

# ── Stage 3: Runtime (PHP-FPM + Nginx, serversideup) ──────────────────────────
FROM serversideup/php:8.2-fpm-nginx AS runtime
WORKDIR /var/www/html

# Application source, then dependencies and built assets from the earlier stages.
# serversideup runs as the www-data user (uid 9999); own everything to it.
COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=vendor /app/vendor ./vendor
COPY --chown=www-data:www-data --from=assets /app/public/build ./public/build

# Boot-time optimizations (config/view cache, package discovery).
COPY --chown=www-data:www-data docker/entrypoint.d/ /etc/entrypoint.d/
USER root
RUN chmod +x /etc/entrypoint.d/*.sh \
    # Framework runtime dirs are emptied by .dockerignore — recreate them so
    # config:cache / view:cache / sessions have somewhere to write, owned by the
    # runtime user.
    && mkdir -p \
        storage/framework/views \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/logs \
        storage/app/private \
        storage/app/public \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data

# serversideup serves /var/www/html/public on port 8080 and exposes /up for
# Render's health check. Migrations run via Render's Pre-Deploy Command.
