#!/bin/sh
#
# Container entrypoint for the Octane/FrankenPHP image.
#
# It performs the boot-time work that cannot be baked into the image because it
# depends on the environment the container is started with:
#
#   1. make `storage/` and `bootstrap/cache/` writable for www-data
#   2. rebuild the Laravel caches (config, routes, views) from the real env
#   3. optionally run migrations
#   4. drop root and exec Octane in FrankenPHP worker mode
#
set -eu

cd /app

# --------------------------------------------------------------------------- #
# 1. Writable paths
#
# Bind mounts (a volume for storage/, for example) inherit the ownership of the
# host directory, so permissions are fixed on every boot instead of at build.
# --------------------------------------------------------------------------- #
mkdir -p \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
    /config \
    /data

chown -R www-data:www-data storage bootstrap/cache /config /data

# A mounted storage volume hides the symlink created at build time.
if [ ! -e public/storage ]; then
    php artisan storage:link --force || true
fi

# SQLite is the framework default and is handy for a quick `docker run`. The
# journal files are created next to the database file, so both the file and its
# directory must belong to the user the server runs as.
if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    db_path="${DB_DATABASE:-database/database.sqlite}"
    case "$db_path" in
        /*) ;;
        *) db_path="/app/${db_path#./}" ;;
    esac

    if [ "$db_path" != ":memory:" ]; then
        mkdir -p "$(dirname "$db_path")"
        touch "$db_path"
        chown www-data:www-data "$(dirname "$db_path")" "$db_path" || true
    fi
fi

# --------------------------------------------------------------------------- #
# 2. Configuration sanity check
# --------------------------------------------------------------------------- #
if [ -z "${APP_KEY:-}" ] && ! grep -qs '^APP_KEY=base64:' .env 2>/dev/null; then
    echo "WARNING: APP_KEY is empty — generate one with" \
         "'php artisan key:generate --show' and pass it as APP_KEY." >&2
fi

# --------------------------------------------------------------------------- #
# 3. Cache configuration, routes, events and views
#
# Must happen here and not in the Dockerfile: `config:cache` freezes the values
# of the env vars that are set *at boot*, so caching at build time would ship
# the build-time environment inside the image.
# --------------------------------------------------------------------------- #
if [ "${RUN_OPTIMIZE:-true}" = "true" ]; then
    php artisan optimize --no-interaction
else
    php artisan optimize:clear --no-interaction
fi

# --------------------------------------------------------------------------- #
# 4. Migrations (opt-in, off by default so replicas cannot race each other)
# --------------------------------------------------------------------------- #
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    attempt=1
    until php artisan migrate --force --no-interaction; do
        if [ "$attempt" -ge 10 ]; then
            echo "Migrations failed after $attempt attempts." >&2
            exit 1
        fi

        attempt=$((attempt + 1))
        echo "Database not ready, retrying in 3s ($attempt/10)…" >&2
        sleep 3
    done
fi

# --------------------------------------------------------------------------- #
# 5. Start Octane
#
# `octane:frankenphp` is the worker-mode server: one booted application shared
# by every request. It is exec'ed (not spawned) so that the container receives
# SIGTERM directly and Caddy can drain in-flight requests.
# --------------------------------------------------------------------------- #
octane_command() {
    set -- php artisan octane:frankenphp \
        --host="${OCTANE_HOST:-0.0.0.0}" \
        --port="${OCTANE_PORT:-8000}" \
        --workers="${OCTANE_WORKERS:-auto}" \
        --max-requests="${OCTANE_MAX_REQUESTS:-500}" \
        --log-level="${OCTANE_LOG_LEVEL:-warn}"

    printf '%s\n' "$@"
}

# The Dockerfile ships `CMD ["php", "artisan", "octane:frankenphp"]` without
# flags; the host/port/worker flags come from the environment instead. Anything
# else — `php artisan migrate`, an explicit octane call with extra options, a
# shell — is executed exactly as given.
if [ "$#" -eq 3 ] && [ "$1" = "php" ] && [ "$2" = "artisan" ] \
        && [ "$3" = "octane:frankenphp" ]; then
    echo "Starting Octane (FrankenPHP) on ${OCTANE_HOST:-0.0.0.0}:${OCTANE_PORT:-8000}…"
    # shellcheck disable=SC2046
    exec su-exec www-data $(octane_command)
fi

echo "Starting: $*"
exec su-exec www-data "$@"
