# Nowills PMS — development runtime

A self-contained development runtime for this repository: **no system PHP,
MySQL or Docker required** — only Node/npm (to fetch two packages) once.

## How it works

- **Bun** (JSI-capable JS runtime; binary ships inside its npm tarball) executes
  **PHP 8.5.11 compiled to WebAssembly** (`@kirigami/php-wasm`).
- The repository is mounted read/write into the PHP filesystem at `/app`
  (NODEFS), so scripts run against the real working tree.
- PHP extensions come from the WASM build plus `@kirigami/phpext-*` side
  modules (`pdo_mysql`, `fileinfo`, `intl`, `gmp`, `soap`, `sodium`, `posix`).
- `lib.mjs` polyfills `SessionHandlerInterface` (ext-session is not in the
  WASM build; Laravel implements its own sessions and only needs the contract).
- The dev database is **SQLite** (`database/database.sqlite`), which is also
  the application's default when `DB_CONNECTION` is unset.

## Tools

| Command | Purpose |
|---|---|
| `bin/php <args>` | PHP CLI shim — `php artisan …`, `php -r '…'`, `php -f file.php`, `php -l file.php`, `php -m`, `php -v` |
| `bin/pms-serve` | HTTP dev server on `0.0.0.0:$PORT` (default 8000) — static files from `public/`, everything else through `public/index.php` |
| `setup.sh` | (Re)installs npm packages and the Bun binary |

Run the tools from anywhere; they `cd` here first so phpext side-module
discovery finds `node_modules`.

## First-time setup

```bash
deploy/dev/setup.sh

cp .env.example .env
# in .env set:
#   DB_CONNECTION=sqlite
#   DB_DATABASE=/app/database/database.sqlite   <- the *PHP-visible* path
touch database/database.sqlite
deploy/dev/bin/php artisan key:generate
deploy/dev/bin/php artisan migrate --force
deploy/dev/bin/php artisan db:seed --force
deploy/dev/bin/pms-serve
```

`proc_open`/`exec`/`popen` run real host processes via `createSpawnHandler`
(`/app/...` paths translate to the repo root), so terminal probes and CLI
helpers work instead of crashing. For quick app queries:

```sh
TINKER='$u = App\Models\User::first(); echo $u->username;' deploy/dev/bin/php -f deploy/dev/tinker.php
```

Demo logins (from `database/seeders/DatabaseSeeder.php`):

| Hotel ID | Username | Password | Notes |
|---|---|---|---|
| `NOWILLS` | `admin` | `Admin@123` | Nowills Grand Hotel, super admin |
| `NOWILLS` | `groupadmin` | `Admin@123` | Group admin (can switch properties) |
| `NWRESORT` | `admin` | `Admin@123` | Nowills Lake Resort |

## Known quirks

- PHP runs as the `wasm` SAPI; the shim strips entry-script shebangs from output.
- `ext-session` is polyfilled (interfaces only) in `lib.mjs`'s auto-prepend.
- `register_argc_argv` is forced off: the wasm SAPI otherwise sets
  `$_SERVER['argv']` to a string and Symfony's `ArgvInput` fatals during boot.
- The WASM PHP is slower than native PHP; large report pages take a few seconds.
- `proc_open`/`shell_exec` exist but have no real OS behind them — anything
  shelling out to system binaries will not work.
