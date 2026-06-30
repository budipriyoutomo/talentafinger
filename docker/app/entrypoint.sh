#!/usr/bin/env bash
set -euo pipefail

# Entrypoint bersama untuk service app / queue / scheduler.
# Perilaku ditentukan oleh CONTAINER_ROLE: app | worker | scheduler.
ROLE="${CONTAINER_ROLE:-app}"

log() { echo "[entrypoint:${ROLE}] $*"; }

wait_for_tcp() {
    local host="$1" port="$2" name="$3" tries=60
    log "Menunggu ${name} (${host}:${port})..."
    until (echo > "/dev/tcp/${host}/${port}") >/dev/null 2>&1; do
        tries=$((tries - 1))
        if [ "$tries" -le 0 ]; then
            log "TIMEOUT menunggu ${name}."
            exit 1
        fi
        sleep 2
    done
    log "${name} siap."
}

# --- Tunggu dependency ---
# PostgreSQL bisa di luar VPS (DB_HOST = host eksternal); cek TCP tetap berlaku.
wait_for_tcp "${DB_HOST:-db}" "${DB_PORT:-5432}" "PostgreSQL"

# Redis HANYA ditunggu bila salah satu driver memang memakainya. Default deploy
# ini pakai driver 'database' untuk queue/cache/session -> Redis dilewati.
if [ "${QUEUE_CONNECTION:-database}" = "redis" ] \
    || [ "${CACHE_STORE:-database}" = "redis" ] \
    || [ "${SESSION_DRIVER:-database}" = "redis" ]; then
    wait_for_tcp "${REDIS_HOST:-redis}" "${REDIS_PORT:-6379}" "Redis"
fi

# --- Inisialisasi sekali, hanya di service app saat menjalankan php-fpm ---
# (perintah one-off spt `php artisan ...` lewat `compose run` melewati blok ini)
if [ "$ROLE" = "app" ] && [ "${1:-}" = "php-fpm" ]; then
    if [ -z "${APP_KEY:-}" ]; then
        log "PERINGATAN: APP_KEY kosong. Generate sekali dengan:"
        log "  docker compose run --rm app php artisan key:generate --show"
        log "lalu isi nilainya ke .env.docker (APP_KEY=...)."
    fi

    log "Menjalankan migrasi database..."
    php artisan migrate --force

    if [ "${RUN_SEED:-false}" = "true" ]; then
        log "Seeding (RUN_SEED=true)..."
        php artisan db:seed --force || true
    fi

    php artisan storage:link || true

    if [ "${APP_ENV:-production}" = "production" ]; then
        log "Cache config/route/view (production)..."
        php artisan config:cache
        php artisan route:cache
        php artisan view:cache
    else
        php artisan optimize:clear || true
    fi
fi

# Manifest paket dibutuhkan tiap container (app/worker/scheduler).
php artisan package:discover --ansi || true

log "Menjalankan: $*"
exec "$@"
