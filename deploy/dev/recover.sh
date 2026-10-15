#!/usr/bin/env bash
# Dev-only: rebuild the disposable runtime state (env, sqlite, migrations, seed).
# Safe to re-run; it never touches git or the working tree sources.
set -uo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."
ROOT="$PWD"
PHP="deploy/dev/bin/php"

echo "==> .env"
if [ ! -f .env ]; then cp .env.example .env; fi
sed -i 's|^DB_CONNECTION=.*|DB_CONNECTION=sqlite|; s|^DB_DATABASE=.*|DB_DATABASE=/app/database/database.sqlite|' .env
grep -q '^PMS_CHANNEL_ALLOW_PRIVATE=' .env || echo 'PMS_CHANNEL_ALLOW_PRIVATE=1' >> .env

echo "==> sqlite file"
touch database/database.sqlite

echo "==> key:generate"
$PHP artisan key:generate --force 2>&1 | tail -2

echo "==> migrate"
$PHP artisan migrate:fresh --force 2>&1 | tail -3

echo "==> seed"
$PHP artisan db:seed --force 2>&1 | tail -3

echo "==> clear compiled views"
rm -f storage/framework/views/*.php

echo "RECOVER_DONE root=$ROOT"
