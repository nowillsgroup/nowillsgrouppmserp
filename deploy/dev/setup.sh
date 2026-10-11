#!/usr/bin/env bash
# Sets up the self-contained WASM PHP development runtime:
#  - Bun (JSPI-capable JS runtime; the binary ships inside its npm tarball)
#  - @kirigami/php-wasm (PHP 8.5.11 WebAssembly) + PHP extension side modules
#
# Everything lands in deploy/dev/node_modules (gitignored). Internet access to
# registry.npmjs.org is required; nothing else is needed on the host.
set -euo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$DIR"

echo "==> Installing PHP-WASM packages (npm)"
npm install --silent \
  @kirigami/php-wasm@8.5.11-4 \
  @kirigami/phpext-pdo_mysql \
  @kirigami/phpext-fileinfo \
  @kirigami/phpext-intl \
  @kirigami/phpext-gmp \
  @kirigami/phpext-soap \
  @kirigami/phpext-sodium \
  @kirigami/phpext-posix

BUN_DIR="$DIR/node_modules/.bin-local"
BUN="$BUN_DIR/bun"
if [ ! -x "$BUN" ]; then
  echo "==> Fetching Bun binary"
  mkdir -p "$BUN_DIR" /tmp/bun-extract
  (cd /tmp/bun-extract && rm -f oven-bun-linux-x64-*.tgz && npm pack @oven/bun-linux-x64 --silent && tar xzf oven-bun-linux-x64-*.tgz)
  cp /tmp/bun-extract/package/bin/bun "$BUN"
  chmod +x "$BUN"
fi

cd "$DIR/.."
if [ ! -f vendor/autoload.php ]; then
  echo "==> vendor/ missing — rebuilding from composer.lock (see deploy/dev/rebuild-vendor.py)"
  python3 "$DIR/rebuild-vendor.py"
fi

echo "==> Smoke test"
"$BUN" "$DIR/php.mjs" -v
echo "OK — use: deploy/dev/bin/php  and  deploy/dev/bin/pms-serve"
