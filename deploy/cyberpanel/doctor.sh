#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Nowills PMS — deployment doctor for CyberPanel / OpenLiteSpeed
#
#   bash /home/pms.nowills.com/public_html/deploy/cyberpanel/doctor.sh
#
# Prints one screen of facts: PHP versions, whether .env exists and is complete,
# whether the app was ever installed, what is in bootstrap/cache, what the site
# user can write, and the tail of the error log. It changes nothing and never
# prints the .env password or the application key (only whether they exist).
#
# Use it as the first step of any "500 / blank page / login loop" investigation.
# ─────────────────────────────────────────────────────────────────────────────
set -uo pipefail

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ENV_FILE="$APP_ROOT/.env"
CACHE_DIR="$APP_ROOT/bootstrap/cache"
PUBLIC_DIR="$APP_ROOT/public"
LOG="$APP_ROOT/storage/logs/laravel.log"
CREATED_FILES="storage bootstrap/cache public/uploads"

ok()   { printf '  \033[32m✔\033[0m %s\n' "$*"; }
bad()  { printf '  \033[31m✘\033[0m %s\n' "$*"; }
warn() { printf '  \033[33m!\033[0m %s\n' "$*"; }
info() { printf '    %s\n' "$*"; }
hdr()  { printf '\n\033[1m%s\033[0m\n' "$*"; }

env_get() { # env_get KEY → value from .env, without sourcing it
    [ -f "$ENV_FILE" ] || return 0
    sed -n "s/^[[:space:]]*$1[[:space:]]*=[[:space:]]*//p" "$ENV_FILE" | tail -n 1 \
        | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'\$/\1/" -e 's/[[:space:]]*$//'
}

hdr "1. Where we are"
info "project root : $APP_ROOT"
info "owner        : $(stat -c '%U:%G' "$APP_ROOT" 2>/dev/null)  (PHP must run as this user)"
info "cwd user     : $(id -un 2>/dev/null) ($(id -u 2>/dev/null))"
if [ -d "$APP_ROOT/.git" ]; then
    info "git branch   : $(git -C "$APP_ROOT" rev-parse --abbrev-ref HEAD 2>/dev/null) @ $(git -C "$APP_ROOT" log -1 --format='%h %s' 2>/dev/null | cut -c1-70)"
    if [ -n "$(git -C "$APP_ROOT" status --porcelain 2>/dev/null)" ]; then
        warn "working tree is dirty — a pull/deploy hook may be fighting local edits"
        git -C "$APP_ROOT" status --porcelain 2>/dev/null | sed -n '1,5{s/^/      /;p}'
    else
        ok "working tree clean"
    fi
else
    warn "no .git here — this folder was not cloned (uploads only, or wrong path)"
fi
[ -f "$APP_ROOT/build/vendor/autoload.php" ] \
    && ok "build/vendor present (no Composer needed)" \
    || bad "build/vendor is MISSING — re-clone the repository"

hdr "2. PHP"
found_php=""
for candidate in /usr/local/lsws/lsphp85/bin/php /usr/local/lsws/lsphp84/bin/php \
                 /usr/local/lsws/lsphp83/bin/php /usr/local/lsws/lsphp82/bin/php \
                 /usr/local/lsws/lsphp81/bin/php /usr/local/bin/php /usr/bin/php \
                 "$(command -v php 2>/dev/null || true)"; do
    [ -n "$candidate" ] && [ -x "$candidate" ] || continue
    ver="$("$candidate" -r 'echo PHP_VERSION;' 2>/dev/null)"
    if "$candidate" -r 'exit(version_compare(PHP_VERSION,"8.3.0",">=")?0:1);' 2>/dev/null; then
        ok "$candidate  ($ver)  usable for artisan"
        [ -n "$found_php" ] || found_php="$candidate"
    else
        warn "$candidate  ($ver)  too old for Laravel 13 (needs 8.3+)"
    fi
done
[ -n "$found_php" ] || bad "no PHP 8.3+ CLI found — cron and artisan cannot run"
for ext in pdo_mysql mbstring openssl tokenizer ctype json fileinfo; do
    if [ -n "$found_php" ]; then
        "$found_php" -r "exit(extension_loaded('$ext')?0:1);" 2>/dev/null || bad "extension missing: $ext"
    fi
done
if [ -n "$found_php" ] && "$found_php" -r "exit(extension_loaded('session')?0:1);" 2>/dev/null; then
    ok "needed PHP extensions loaded"
else
    bad "extension missing: session — nobody can log in without it"
fi
[ -x /usr/local/lsws/lsphp84/bin/lsphp ] && info "web handler (LSAPI) present: /usr/local/lsws/lsphp84/bin/lsphp"

hdr "3. .env"
if [ ! -f "$ENV_FILE" ]; then
    bad ".env does not exist — the app has never been configured"
    info "fix: open https://<domain>/install.php (or copy .env.example and edit it by hand)"
else
    info ".env present, $(stat -c '%U:%G %a' "$ENV_FILE" 2>/dev/null)"
    app_env="$(env_get APP_ENV)"; app_debug="$(env_get APP_DEBUG)"; app_url="$(env_get APP_URL)"
    key="$(env_get APP_KEY)"; db_name="$(env_get DB_DATABASE)"
    db_user="$(env_get DB_USERNAME)"; db_pass="$(env_get DB_PASSWORD)"
    db_host="$(env_get DB_HOST)"; session_driver="$(env_get SESSION_DRIVER)"

    info "APP_ENV=$app_env  APP_DEBUG=$app_debug  APP_URL=$app_url"
    info "DB_HOST=$db_host  DB_DATABASE=$db_name  DB_USERNAME=$db_user  DB_PASSWORD="\
"$([ -n "$db_pass" ] && echo '(set)' || echo '(EMPTY)')  SESSION_DRIVER=$session_driver"

    if [ -z "$key" ]; then
        bad "APP_KEY is EMPTY — every page dies with MissingAppKeyException"
        info "fix: cd '$APP_ROOT' && ${found_php:-<php>} artisan key:generate --force"
        info "     (or let the installer do it: https://<domain>/install.php)"
    else
        ok "APP_KEY set (${#key} chars)"
    fi
    case "$key" in base64:*) [ $(( (${#key} - 7) * 3 / 4 )) -eq 32 ] && ok "APP_KEY looks like a valid 32-byte AES key" || warn "APP_KEY length looks unusual";; esac

    [ "$app_debug" = "true" ] && warn "APP_DEBUG=true on a public site — stack traces, .env values and SQL are visible to visitors. Set APP_DEBUG=false once it works."
    if [ -n "$db_pass" ] && [ "$db_pass" = "change_me" ]; then
        bad "DB_PASSWORD is still 'change_me' — this .env looks like an untouched copy of .env.example"
    fi
    if [ "$app_env" = "local" ] && [ -n "$app_url" ] && [ "$app_url" = "http://localhost:8000" ]; then
        bad "APP_URL is the .env.example default — this is a copy of .env.example, not a configured install"
        info "fix: run the installer (https://<domain>/install.php) — it writes all of this correctly"
    fi
    if [ "$session_driver" = "database" ] && [ ! -f "$APP_ROOT/storage/framework/installed.lock" ]; then
        warn "SESSION_DRIVER=database but the app was never installed → the sessions table does not exist"
    fi
fi

hdr "4. Installed?"
if [ -f "$APP_ROOT/storage/framework/installed.lock" ]; then
    ok "installer completed: $(cat "$APP_ROOT/storage/framework/installed.lock" 2>/dev/null)"
else
    bad "storage/framework/installed.lock is missing — migrations and the first property/admin were never created"
    info "fix: open https://<domain>/install.php and finish the wizard"
fi
if [ -f "$PUBLIC_DIR/install.php" ]; then
    warn "public/install.php is still online — it exposes your PHP config and folder permissions"
    info "fix: rm '$PUBLIC_DIR/install.php' once the install has finished"
else
    ok "installer not web-reachable (deleted)"
fi

hdr "5. Compiled caches (the usual cause of 'I deployed but nothing changed')"
compiled=()
for f in "$CACHE_DIR/config.php" "$CACHE_DIR/services.php" "$CACHE_DIR/packages.php" "$CACHE_DIR"/routes-*.php; do
    [ -e "$f" ] && compiled+=("$f")
done
if [ ${#compiled[@]} -eq 0 ]; then
    ok "bootstrap/cache is empty — the app reads .env directly"
else
    for f in "${compiled[@]}"; do
        info "$(basename "$f")  built $(stat -c '%y' "$f" 2>/dev/null | cut -c1-19)  owner=$(stat -c '%U' "$f" 2>/dev/null)"
    done
    if [ -f "$CACHE_DIR/config.php" ] && [ -f "$ENV_FILE" ]; then
        cached_key=$(sed -n "s/.*'key' => '\([^']*\)'.*/\1/p" "$CACHE_DIR/config.php" 2>/dev/null | sed -n 1p)
        cached_key_len=${#cached_key}
        env_key="$(env_get APP_KEY)"
        if [ "${cached_key_len:-0}" -eq 0 ] && [ -n "$env_key" ]; then
            bad "config.php has an EMPTY key while .env has one — the cache predates your APP_KEY"
            info "fix: rm '$CACHE_DIR'/config.php  (the post-deploy hook does this)"
        elif [ "${cached_key_len:-0}" -eq 0 ]; then
            warn "config.php was built with no APP_KEY at all — delete it before using the app"
        else
            ok "compiled config carries a key"
        fi
    fi
    info "note: while a cache exists, edits to .env are IGNORED until it is deleted"
fi
viewcount=$(find "$APP_ROOT/storage/framework/views" -maxdepth 1 -name '*.php' 2>/dev/null | wc -l)
info "compiled Blade views: $viewcount"

hdr "6. Writable by the PHP user?"
for dir in $CREATED_FILES storage/framework/sessions storage/framework/views storage/logs storage/app/backups; do
    if [ -d "$APP_ROOT/$dir" ]; then
        if [ -w "$APP_ROOT/$dir" ]; then ok "$dir (owner $(stat -c '%U' "$APP_ROOT/$dir"))"
        else bad "$dir is NOT writable by $(id -un) — owner is $(stat -c '%U' "$APP_ROOT/$dir")"; fi
    else
        warn "$dir does not exist (the post-deploy hook creates it)"
    fi
done
root_owned=$(find "$APP_ROOT/storage" "$APP_ROOT/bootstrap/cache" "$PUBLIC_DIR/uploads" ! -user "$(stat -c '%U' "$APP_ROOT")" 2>/dev/null | wc -l)
[ "$root_owned" -gt 0 ] && bad "$root_owned file(s) under storage/, bootstrap/cache/ or uploads/ are owned by another user (root clone or root artisan run)" \
    && info "fix: chown -R $(stat -c '%U' "$APP_ROOT"):$(stat -c '%G' "$APP_ROOT") '$APP_ROOT/storage' '$APP_ROOT/bootstrap/cache' '$PUBLIC_DIR/uploads'"

hdr "7. Last errors"
if [ -f "$LOG" ]; then
    info "$LOG ($(stat -c '%s' "$LOG") bytes, modified $(stat -c '%y' "$LOG" | cut -c1-19))"
    tail -n 25 "$LOG" | sed 's/^/      /'
else
    warn "no storage/logs/laravel.log yet — the app has not booted far enough to write one"
fi

hdr "8. Reminders this script cannot check"
info "• vHost Conf: the docRoot line must end in /public"
info "• vHost Conf rewrite block: enable 1 and autoLoadHtaccess 1 (public/.htaccess needs it)"
info "• Manage → open_basedir must allow the project root, not just public/"
info "• cron, once a minute: <lsphp path> $APP_ROOT/artisan schedule:run"
