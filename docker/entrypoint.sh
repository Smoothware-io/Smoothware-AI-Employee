#!/bin/sh
set -e

# Runs before the web app, the queue worker, and the scheduler alike. Keep it
# idempotent: it executes on every container start, including restarts.

# ---------------------------------------------------------------------------
# APP_KEY — read this before touching anything here
# ---------------------------------------------------------------------------
#
# This app encrypts real data at rest with APP_KEY: call transcripts (personal
# audio content of a real conversation) and each rep's Sonetel access/refresh
# tokens. Change the key and that data does not "reset" — it becomes permanently
# unreadable, and the GDPR erasure path (CallContentEraser) can no longer read
# what it is supposed to erase.
#
# So this entrypoint NEVER runs `key:generate`. Plenty of Docker Laravel
# templates do, which quietly re-keys the app on every deploy and destroys
# exactly this kind of data. Generate the key ONCE, put it in Dokploy's
# environment, and back it up somewhere you would not lose.
if [ -z "${APP_KEY:-}" ]; then
    echo "FATAL: APP_KEY is not set — refusing to start." >&2
    echo "  Generate one locally:  php artisan key:generate --show" >&2
    echo "  Then set it in Dokploy's Environment tab and never change it." >&2
    echo "  Changing it makes existing transcripts and Sonetel tokens" >&2
    echo "  permanently undecryptable." >&2
    exit 1
fi

# ---------------------------------------------------------------------------
# Wait for Postgres
# ---------------------------------------------------------------------------
# Compose's depends_on only waits for the container to exist, not for Postgres to
# accept connections. Without this, the first deploy races the database and the
# migration step fails on a cold start.
echo "Waiting for the database..."
ATTEMPTS=0
until php artisan db:show --quiet 2>/dev/null; do
    ATTEMPTS=$((ATTEMPTS + 1))
    if [ "$ATTEMPTS" -ge 30 ]; then
        echo "FATAL: database unreachable after 30 attempts." >&2
        php artisan db:show 2>&1 | tail -5 >&2
        exit 1
    fi
    sleep 2
done
echo "Database is up."

# ---------------------------------------------------------------------------
# Migrations — web container only
# ---------------------------------------------------------------------------
# RUN_MIGRATIONS is set only on the web service. If the worker and scheduler ran
# migrations too, three containers would race the same schema change on every
# deploy — which is how you get a half-applied migration and a lock nobody owns.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    echo "Running migrations..."
    php artisan migrate --force --isolated || {
        echo "FATAL: migrations failed — refusing to serve." >&2
        exit 1
    }
fi

# ---------------------------------------------------------------------------
# Caches
# ---------------------------------------------------------------------------
# Built at runtime, not build time: config:cache bakes in the environment, and
# the environment does not exist until Dokploy injects it. Cached at build time
# it would freeze the builder's empty values into production.
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Public disk symlink for anything user-uploaded. Ignore failure — it already
# exists on a restart, and that is not an error worth stopping a deploy for.
php artisan storage:link 2>/dev/null || true

echo "Starting: $*"
exec "$@"
