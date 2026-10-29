#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Nowills PMS — post-deploy hook for CyberPanel / OpenLiteSpeed
#
# Run it manually after each `git pull` on the server:
#
#   bash /home/pms.nowills.com/public_html/deploy/cyberpanel/post-deploy.sh
#
# Production updates for pms.nowills.com are manual: do not connect this script to
# CyberPanel push-to-deploy, a GitHub webhook, or an automated GitHub Action.
#
# What it does, in order:
#   1. refuses to run if the Composer tree (build/vendor) is missing
#   2. creates the runtime folders the app writes into at run time
#   3. drops every compiled config / route / view, so new code and a new .env win
#   4. fixes permissions (755 folders, 644 files — never 777)
#   5. runs migrations, then re-caches config, routes and views
#   6. logs everything to storage/logs/deploy.log
#
# It is safe to run before the installer has been used: step 5 is skipped while
# there is no .env / no storage/framework/installed.lock.
#
# Environment overrides:
#   PHP_BIN=/usr/local/lsws/lsphp83/bin/php   force a PHP CLI binary
#   SKIP_MIGRATE=1                            skip `artisan migrate --force`
#   SKIP_CACHE=1                              skip re-caching config/routes/views
# ─────────────────────────────────────────────────────────────────────────────
set -uo pipefail

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
LOG="$APP_ROOT/storage/logs/deploy.log"
mkdir -p "$APP_ROOT/storage/logs" 2>/dev/null
: >>"$LOG" 2>/dev/null || LOG="/dev/stderr"

log() { printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" | tee -a "$LOG"; }
fail() { log "ERROR: $*"; exit 1; }

log "── Nowills PMS deploy hook: $APP_ROOT ──"

# ── 0. Preconditions ─────────────────────────────────────────────────────────
[ -f "$APP_ROOT/artisan" ] || fail "no artisan in $APP_ROOT — wrong folder?"
[ -f "$APP_ROOT/build/vendor/autoload.php" ] || fail \
    "build/vendor/autoload.php is missing — the clone is incomplete (this project keeps its Composer packages in build/vendor)"

# ── 0b. Catch the two states that make every page 500 ──────────────────────
# A .env copied from .env.example (APP_DEBUG=true, APP_KEY empty) looks fine in a
# file manager but the site dies with MissingAppKeyException on every request.
if [ -f "$APP_ROOT/.env" ]; then
    ENV_KEY="$(sed -n 's/^[[:space:]]*APP_KEY[[:space:]]*=[[:space:]]*//p' "$APP_ROOT/.env" | tail -n 1 | tr -d '"'"'"'[:space:]')"
    if [ -z "$ENV_KEY" ]; then
        log "WARNING: APP_KEY in .env is EMPTY — every page will fail with MissingAppKeyException."
        log "         Fix: open https://<your-domain>/install.php, or run: php artisan key:generate --force"
    fi
    if grep -q '^APP_DEBUG=true' "$APP_ROOT/.env"; then
        log "WARNING: APP_DEBUG=true — stack traces and .env values are visible to visitors. Set APP_DEBUG=false."
    fi
    if [ ! -f "$APP_ROOT/storage/framework/installed.lock" ]; then
        log "WARNING: not installed yet (no storage/framework/installed.lock) — the wizard at /install.php still has to run."
    fi
fi
log "for a full health report run: bash $APP_ROOT/deploy/cyberpanel/doctor.sh"

# ── 1. Find a PHP 8.3+ CLI ───────────────────────────────────────────────────
find_php() {
    if [ -n "${PHP_BIN:-}" ]; then
        printf '%s' "$PHP_BIN"
        return 0
    fi
    local candidate
    for candidate in \
        /usr/local/lsws/lsphp84/bin/php \
        /usr/local/lsws/lsphp83/bin/php \
        /usr/local/lsws/lsphp85/bin/php \
        /usr/local/lsws/lsphp82/bin/php \
        /usr/local/bin/php \
        /usr/bin/php \
        "$(command -v php 2>/dev/null || true)"
    do
        if [ -n "$candidate" ] && [ -x "$candidate" ]; then
            # Laravel 13 needs 8.3 or newer; a wrong binary must not be used.
            if "$candidate" -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' 2>/dev/null; then
                printf '%s' "$candidate"
                return 0
            fi
        fi
    done
    return 1
}

PHP="$(find_php || true)"
if [ -z "$PHP" ]; then
    log "WARNING: no PHP 8.3+ CLI found — skipping artisan steps (set PHP_BIN=...)"
else
    log "PHP CLI: $PHP ($("$PHP" -r 'echo PHP_VERSION;' 2>/dev/null))"
fi

# ── 2. Runtime folders (two of them are gitignored and absent after a clone) ──
for dir in \
    public/uploads \
    public/uploads/logos \
    public/uploads/menu \
    public/uploads/room-images \
    storage/app/backups \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache
do
    mkdir -p "$APP_ROOT/$dir" || fail "cannot create $dir"
done
log "runtime folders present"

# ── 3. Drop compiled code so the new checkout takes effect ───────────────────
rm -f "$APP_ROOT"/bootstrap/cache/config.php \
      "$APP_ROOT"/bootstrap/cache/routes-v7.php \
      "$APP_ROOT"/bootstrap/cache/routes.php \
      "$APP_ROOT"/bootstrap/cache/services.php \
      "$APP_ROOT"/bootstrap/cache/packages.php \
      "$APP_ROOT"/bootstrap/cache/events.php 2>/dev/null
find "$APP_ROOT/storage/framework/views" -maxdepth 1 -name '*.php' -delete 2>/dev/null
# Never touch the .gitignore files that keep these empty folders in the checkout.
find "$APP_ROOT/storage/framework/cache/data" -mindepth 1 ! -name '.gitignore' -delete 2>/dev/null
log "cleared compiled config / routes / views"

# ── 4. Permissions ───────────────────────────────────────────────────────────
chmod 755 "$APP_ROOT" 2>/dev/null
for dir in storage bootstrap/cache public/uploads; do
    find "$APP_ROOT/$dir" -type d -exec chmod 755 {} + 2>/dev/null
    find "$APP_ROOT/$dir" -type f -exec chmod 644 {} + 2>/dev/null
done
if [ -f "$APP_ROOT/.env" ]; then
    chmod 644 "$APP_ROOT/.env" 2>/dev/null
fi
log "permissions set (755 folders / 644 files)"

# A root-run pull leaves root-owned cache files that the PHP user cannot write.
# Hand everything we just touched back to the folder's real owner.
if [ "$(id -u)" -eq 0 ]; then
    OWNER_UID="$(stat -c '%u' "$APP_ROOT")"
    OWNER_GID="$(stat -c '%g' "$APP_ROOT")"
    chown -R "$OWNER_UID:$OWNER_GID" \
        "$APP_ROOT/storage" "$APP_ROOT/bootstrap/cache" "$APP_ROOT/public/uploads" 2>/dev/null
    log "running as root — chowned runtime folders to $OWNER_UID:$OWNER_GID"
else
    if [ ! -w "$APP_ROOT/storage" ]; then
        log "WARNING: this user cannot write to storage/ — pull as the site user, not as root"
    fi
fi

# ── 5. Migrate, then re-cache ────────────────────────────────────────────────
if [ -n "$PHP" ] && [ -f "$APP_ROOT/.env" ] && [ -f "$APP_ROOT/storage/framework/installed.lock" ]; then
    if [ "${SKIP_MIGRATE:-0}" = "1" ]; then
        log "migrations skipped (SKIP_MIGRATE=1)"
    else
        log "running migrations…"
        if "$PHP" "$APP_ROOT/artisan" migrate --force --no-interaction >>"$LOG" 2>&1; then
            log "migrations OK"
        else
            log "WARNING: migrations reported a problem — see the lines above in deploy.log"
        fi
    fi

    if [ "${SKIP_CACHE:-0}" = "1" ]; then
        log "re-caching skipped (SKIP_CACHE=1)"
    else
        for step in config:cache route:cache view:cache; do
            if "$PHP" "$APP_ROOT/artisan" "$step" >>"$LOG" 2>&1; then
                log "artisan $step OK"
            else
                log "note: artisan $step failed — the app still runs, uncached, for that layer"
            fi
        done
    fi
elif [ -n "$PHP" ]; then
    log "not installed yet (no .env / no installed.lock) — skipping migrate + cache"
fi

# Root-run artisan leaves root-owned cache files behind; fix again.
if [ "$(id -u)" -eq 0 ]; then
    chown -R "$(stat -c '%u' "$APP_ROOT"):$(stat -c '%g' "$APP_ROOT")" \
        "$APP_ROOT/storage" "$APP_ROOT/bootstrap/cache" 2>/dev/null
fi

log "── deploy hook finished ──"
