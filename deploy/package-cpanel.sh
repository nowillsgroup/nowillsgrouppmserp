#!/usr/bin/env bash
#
# Build a cPanel-ready ZIP of Nowills PMS from the committed tree.
#
#   ./deploy/package-cpanel.sh                  # → ../dist/nowills-pms-vX.Y.Z-<sha>.zip
#   ./deploy/package-cpanel.sh --flat           # no top-level folder (for public_html)
#   ./deploy/package-cpanel.sh --out /tmp/x     # choose the output directory
#   ./deploy/package-cpanel.sh --with-tests     # keep tests/ (default: excluded)
#   ./deploy/package-cpanel.sh --with-docs      # keep the internal audit report
#
# The archive is built with `git archive`, so it contains exactly what is
# committed at HEAD — never a stray .env, a working-tree edit or .git itself.
#
# What is excluded by default, and why:
#   docs/AUDIT-*.md, docs/NOWILLS-PMS-CODEBASE-AUDIT-*.md, docs/TEST-STATUS.md and the
#                       module audit reports — these enumerate known weaknesses; they are
#                       not for a live server (a shared host may be read by neighbour tenants)
#   deploy/dev/         the 39 MB development runtime (WASM PHP), 174 preview HTML
#                       snapshots and the audit/attack harness — development tooling only
#   tests/              dev only (skip with --with-tests)
#   .github/ .editorconfig .gitattributes .npmrc phpunit.xml   dev tooling
#
# Not excluded (deliberately INCLUDED):
#   build/vendor/        the Composer packages — this is what removes the need
#                        for Composer on the server. The development packages inside it
#                        (phpunit, pint, faker, …) are removed again in step 2c below.
#   deploy/installers/   the one-time web installer templates, copied to the web root
#   docs/                everything EXCEPT the internal audit reports listed above
#   .htaccess .env.example  the shared-hosting fallback and the env template
#
set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="$(dirname "$REPO")/dist"
FLAT=0
WITH_TESTS=0
WITH_DOCS=0

while [ $# -gt 0 ]; do
    case "$1" in
        --out)          OUT="$2"; shift 2 ;;
        --flat)         FLAT=1; shift ;;
        --with-tests)   WITH_TESTS=1; shift ;;
        --with-docs)    WITH_DOCS=1; shift ;;
        -h|--help)      sed -n '3,26p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *)              echo "unknown option: $1" >&2; exit 2 ;;
    esac
done

cd "$REPO"

VERSION="$(sed -n "s/.*'version' => '\([0-9][0-9.]*\)'.*/\1/p" config/pms.php | head -1)"
SHA="$(git rev-parse --short HEAD)"
[ -n "$VERSION" ] || { echo "could not read the version from config/pms.php" >&2; exit 1; }

# Refuse to package a dirty tree: the archive would silently not match HEAD.
if [ -n "$(git status --porcelain)" ]; then
    echo "working tree has uncommitted changes — commit or stash first" >&2
    git status --short >&2
    exit 1
fi

PREFIX="nowills-pms"
SUFFIX=""
if [ "$FLAT" -eq 1 ]; then
    PREFIX=""
    SUFFIX="-flat"
fi
ZIP="$OUT/nowills-pms-v${VERSION}-${SHA}${SUFFIX}.zip"

EXCLUDES=(
    ':(exclude)docs/screenshots/**'
    ':(exclude).github/**'
    ':(exclude).editorconfig'
    ':(exclude).gitattributes'
    ':(exclude).npmrc'
    ':(exclude)phpunit.xml'
    ':(exclude)deploy/dev/**'
)
[ "$WITH_TESTS" -eq 0 ] && EXCLUDES+=(':(exclude)tests/**')
# The deploy/ tree is dev/build tooling. git pathspec cannot re-include a path under an
# excluded directory, so list the dev parts individually; deploy/installers/*.php.txt
# stays tracked in the archive because step 2b copies them to the web root.
EXCLUDES+=(
    ':(exclude)deploy/dev/**'
    ':(exclude)deploy/tizen-tv/**'
    ':(exclude)deploy/cyberpanel/**'
    ':(exclude)deploy/package-cpanel.sh'
    ':(exclude)deploy/nginx.conf'
    ':(exclude)deploy/crontab.txt'
    ':(exclude)deploy/apache-vhost.conf'
)
# Internal audit documents enumerate known weaknesses; keep them off the server.
# (docs/*AUDIT*.md matches every tracked audit report; docs/AUDIT-2026-11-09.md is
# untracked and never reaches the archive.)
if [ "$WITH_DOCS" -eq 0 ]; then
    EXCLUDES+=(
        ':(exclude)docs/*AUDIT*.md'
        ':(exclude)docs/TEST-STATUS.md'
    )
fi

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

echo "Building Nowills PMS v${VERSION} (${SHA})"
echo "  → $ZIP"
echo

# ── 1. Export the committed tree ─────────────────────────────────────────────
git archive --format=tar --prefix="${PREFIX:+$PREFIX/}" HEAD -- . "${EXCLUDES[@]}" \
    | tar -x -C "$STAGE"

ROOT="$STAGE/${PREFIX:+$PREFIX/}"

# ── 2. Add the runtime folders git does not track ────────────────────────────
# public/uploads and storage/app/backups are gitignored, so they are absent
# from the export. The installer creates them too, but shipping them makes the
# required permissions obvious and survives an extraction that skips dirs.
for d in \
    public/uploads \
    public/uploads/logos \
    public/uploads/menu \
    public/uploads/room-images \
    storage/app/backups
do
    mkdir -p "$ROOT/$d"
    [ -e "$ROOT/$d/.gitkeep" ] || : > "$ROOT/$d/.gitkeep"
done

# ── 2c. Strip the development packages that ship inside build/vendor ──────────
# build/vendor is committed as-is, so the archive would otherwise carry phpunit,
# pint, faker and their dependencies on the live server. They are unreachable from
# the production roots (chillerlan/php-qrcode, laravel/framework, laravel/tinker),
# verified against composer.lock. The autoloader's eager `files` map and the
# installed.json manifest are pruned alongside the package directories so the app
# boots without them. If python3 is missing, or a listed package has since become
# a production dependency, that package is kept — the package is still valid.
# Regenerate with: python3 -c 'see docs/AUDIT-2026-11-09.md §5.1'
cat > "$STAGE/strip-dev.py" <<'PYEOF'
import json, os, re, sys, shutil
root = sys.argv[1]
vendor = os.path.join(root, 'build', 'vendor')
lock = json.load(open(os.path.join(root, 'composer.lock')))
prod_names = {p['name'] for p in lock['packages']}
skip = ["fakerphp/faker","filp/whoops","hamcrest/hamcrest-php","laravel/agent-detector","laravel/pail",
        "laravel/pao","laravel/pint","mockery/mockery","myclabs/deep-copy","nunomaduro/collision",
        "phar-io/manifest","phar-io/version","phpunit/php-code-coverage","phpunit/php-file-iterator",
        "phpunit/php-invoker","phpunit/php-text-template","phpunit/php-timer","phpunit/phpunit",
        "sebastian/cli-parser","sebastian/comparator","sebastian/complexity","sebastian/diff",
        "sebastian/environment","sebastian/exporter","sebastian/global-state","sebastian/lines-of-code",
        "sebastian/object-enumerator","sebastian/object-reflector","sebastian/recursion-context",
        "sebastian/type","sebastian/version","staabm/side-effects-detector","theseer/tokenizer"]
drop = []
for name in skip:
    if name in prod_names:
        print(f"  keep {name} (now a production dependency)")
        continue
    path = os.path.join(vendor, name)
    if os.path.isdir(path):
        drop.append(name)
    else:
        print(f"  note {name} not present under build/vendor")
for name in drop:
    shutil.rmtree(os.path.join(vendor, name))
    # the empty vendor/<namespace>/ directory the package left behind
    namespace = os.path.join(vendor, name.split('/', 1)[0])
    if os.path.isdir(namespace):
        try:
            os.rmdir(namespace)
        except OSError:
            pass  # still holds other packages — keep it

# vendor/bin proxies of dropped packages would be left dangling
dev_lock = {p['name']: (p.get('bin') or []) for p in lock.get('packages-dev', [])}
removed_bin = 0
for name in drop:
    for bin_entry in dev_lock.get(name, []):
        stub = os.path.join(vendor, 'bin', os.path.basename(bin_entry))
        for candidate in (stub, stub + '.bat'):
            if os.path.isfile(candidate) or os.path.islink(candidate):
                os.remove(candidate)
                removed_bin += 1

def prune_files_map(path):
    if not os.path.exists(path):
        return 0
    src = open(path).read()
    out, removed = [], 0
    for line in src.splitlines(keepends=True):
        if any(('/' + name + '/') in line for name in drop):
            removed += 1
            continue
        out.append(line)
    open(path, 'w').write(''.join(out))
    return removed

n1 = prune_files_map(os.path.join(vendor, 'composer', 'autoload_files.php'))
n2 = prune_files_map(os.path.join(vendor, 'composer', 'autoload_static.php'))

# composer 2 manifest used by Laravel's package discovery
manifest = os.path.join(vendor, 'composer', 'installed.json')
data = json.load(open(manifest))
plist = data.get('packages', data if isinstance(data, list) else [])
kept = [p for p in plist if p.get('name') not in set(drop)]
removed = len(plist) - len(kept)
if 'packages' in data:
    data['packages'] = kept
    json.dump(data, open(manifest, 'w'), indent=4)
print(f"  stripped {len(drop)} dev packages ({removed} from installed.json, {n1 + n2} eager autoload entries, {removed_bin} bin proxies)")
PYEOF
if command -v python3 >/dev/null 2>&1; then
    python3 "$STAGE/strip-dev.py" "$ROOT"
    rm -f "$STAGE/strip-dev.py"
    # Nothing that used to require a dev package may remain in the eager file map.
    if grep -E "phpunit|mockery|collision|pao|deep-copy|agent-detector|faker|hamcrest|whoops|pint|pail" "$ROOT/build/vendor/composer/autoload_files.php" "$ROOT/build/vendor/composer/autoload_static.php" | grep -v termwind >/dev/null; then
        echo "  WARNING: eager autoload still references development packages" >&2
    fi
else
    echo "  WARNING: python3 not available — leaving build/vendor untouched" >&2
    rm -f "$STAGE/strip-dev.py"
fi

# ── 2b. Put the installer back where the wizard expects it ───────────────────
# The installers are committed as deploy/installers/*.php.txt and are gitignored at
# the web root, so a finished server never carries one (and never shows a dirty
# tree to CyberPanel). The archive is a fresh install, so it gets them for real.
cp "$ROOT/deploy/installers/public-install.php.txt" "$ROOT/public/install.php"
cp "$ROOT/deploy/installers/root-install.php.txt"   "$ROOT/install.php"

# ── 3. A plain-text pointer at the top of the archive ────────────────────────
if [ "$FLAT" -eq 1 ]; then
    LAYOUT_NOTE="Extracted flat: this folder is the project root.
Point the domain's Document Root at the public/ folder inside it."
else
    LAYOUT_NOTE="This folder ($PREFIX) is the project root.
Point the domain's Document Root at $PREFIX/public."
fi

cat > "$ROOT/START-HERE.txt" <<TXT
Nowills PMS $VERSION — cPanel installation
Built from commit $SHA

$LAYOUT_NOTE

cPanel → Domains → your domain → Document Root.

If the Document Root cannot be changed, leave the upload where it is: the
.htaccess in this folder forwards everything into public/ instead.

--------------------------------------------------------------------
1. Create the database
   cPanel → MySQL Databases
     - create a database
     - create a user with a strong password
     - add the user to the database with ALL PRIVILEGES
   Copy the FULL prefixed names (e.g. myacct_pms) — not what you typed.

2. Set permissions
   storage/            (and everything inside)   755
   bootstrap/cache/                              755
   public/uploads/                               755

3. Open the installer
   https://your-domain.com/install.php
   Fill in the database details, your property and your admin account.

4. Delete the installer
   It deletes itself when it finishes; if it could not, remove public/install.php
   by hand. It is not needed again.

5. Add the cron entry
   cPanel → Cron Jobs → Once Per Minute:
     /usr/local/bin/php /home/YOURUSER/nowills-pms/artisan schedule:run >> /dev/null 2>&1
   Replace the path with where you uploaded this, and /usr/local/bin/php with
   your host's real CLI PHP if it differs. The installer prints the exact line.

--------------------------------------------------------------------
Full guide:  docs/INSTALL-CPANEL.md
Module list: docs/MODULES.md

You do NOT need Composer — the packages are in build/vendor.
You do NOT need SSH — the installer does what artisan would.
TXT

# ── 4. Sanity-check the staged tree before zipping ───────────────────────────
fail=0
check() { # path  description
    if [ -e "$ROOT/$1" ]; then
        echo "  ok   $2"
    else
        echo "  MISSING  $2  ($1)" >&2
        fail=1
    fi
}

echo "Required files:"
check build/vendor/autoload.php   "Composer packages (build/vendor) — no Composer needed on the server"
check public/index.php            "web entry point"
check public/install.php          "web installer (copied from deploy/installers)"
check public/.htaccess            "front-controller rewrite"
check .htaccess                   "shared-hosting fallback + sensitive-path block"
check .env.example                ".env template"
check artisan                     "CLI entry point (used by cron)"
check bootstrap/app.php           "application bootstrap"
check config/pms.php              "module registry"
check routes/console.php          "scheduler definitions"
check database/migrations         "migrations"
check storage/framework/sessions  "writable session folder"
check bootstrap/cache             "writable cache folder"
check public/uploads              "writable upload folder"

# Secrets must never be in the package.
for bad in .env .env.production .env.backup .git; do
    if [ -e "$ROOT/$bad" ]; then
        echo "  LEAK  $bad must not be in the package" >&2
        fail=1
    fi
done

[ "$fail" -eq 0 ] || { echo; echo "refusing to build an incomplete package" >&2; exit 1; }

# ── 5. Zip ───────────────────────────────────────────────────────────────────
mkdir -p "$OUT"
rm -f "$ZIP"
( cd "$STAGE" && zip -r -9 -q "$ZIP" "${PREFIX:-.}" -x '*.DS_Store' )

# ── 6. Report ────────────────────────────────────────────────────────────────
SIZE="$(du -h "$ZIP" | cut -f1)"
FILES="$(unzip -Z1 "$ZIP" | grep -vc '/$' || true)"
RAWSIZE="$(du -sh --exclude='*.zip' "$ROOT" | cut -f1)"

echo
echo "Built:  $ZIP"
echo "  size        $SIZE  (unpacked ${RAWSIZE})"
echo "  files       $FILES"
echo "  version     $VERSION  @ $SHA"
echo "  layout      $([ "$FLAT" -eq 1 ] && echo 'flat — extract into your document root' || echo "$PREFIX/ — point the document root at ${PREFIX}/public")"
echo "  tests       $([ "$WITH_TESTS" -eq 1 ] && echo included || echo excluded)"
echo "  audit doc   $([ "$WITH_DOCS" -eq 1 ] && echo included || echo excluded)"
echo
( cd "$OUT" && sha256sum "$(basename "$ZIP")" )
