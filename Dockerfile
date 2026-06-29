# syntax=docker/dockerfile:1

# =========================================================================
# ADMS Middleware — image aplikasi (PHP-FPM + Python pyzk untuk zk_sync.py)
# Multi-stage: build aset (node) + vendor (composer) -> runtime php-fpm.
# Image yang sama dipakai untuk service: app, queue, scheduler.
# =========================================================================

# ---- Stage 1: build aset frontend (Vite / React / Tailwind) ----
FROM node:20-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

# ---- Stage 2: dependency PHP (Composer) ----
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
# --no-scripts: package:discover butuh boot Laravel + ekstomik; dijalankan di runtime.
# --ignore-platform-reqs: ekstensi nyata tersedia di image runtime (stage final).
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader \
        --ignore-platform-reqs

# ---- Stage 3: runtime PHP-FPM ----
FROM php:8.3-fpm-bookworm AS runtime

# Paket sistem + toolchain ekstensi + Python untuk scripts/zk_sync.py
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libpq-dev libzip-dev libpng-dev libicu-dev libonig-dev \
        python3 python3-pip python3-venv \
        postgresql-client ca-certificates \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql bcmath intl zip gd pcntl opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && update-ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# pyzk untuk komunikasi TCP 4370 ke mesin ZKTeco (dipakai zk_sync.py)
# PEP 668: pakai --break-system-packages di Debian bookworm.
RUN pip3 install --no-cache-dir --break-system-packages pyzk

# Composer CLI (untuk dump-autoload bila diperlukan di runtime)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Konfigurasi PHP production
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www

# Source + vendor + aset hasil build
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

# Autoloader teroptimasi (vendor sudah ada; ini regen classmap aman)
RUN composer dump-autoload --no-dev --optimize --no-scripts \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rw storage bootstrap/cache

COPY docker/app/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

EXPOSE 9000
ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# ---- Stage 4: web (nginx membundel aset statis agar selalu sinkron dgn build) ----
FROM nginx:1.27-alpine AS web
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
# /app/public dari stage assets sudah berisi build/ + favicon/robots/index.php
COPY --from=assets /app/public /var/www/public

