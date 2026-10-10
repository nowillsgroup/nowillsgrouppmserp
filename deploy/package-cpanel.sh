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
#   docs/screenshots/    84 MB of documentation images, never used at runtime
#   docs/AUDIT-REPORT.md enumerates known weaknesses; not for a live server
#   tests/               6 MB, dev only
#   .github/ .editorconfig .gitattributes .npmrc phpunit.xml   dev tooling
#
# What is deliberately INCLUDED:
#   build/vendor/        the Composer packages — this is what removes the need
#                        for Composer on the server
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
)
[ "$WITH_TESTS" -eq 0 ] && EXCLUDES+=(':(exclude)tests/**')
[ "$WITH_DOCS" -eq 0 ]  && EXCLUDES+=(':(exclude)docs/AUDIT-REPORT.md')

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
   Remove public/install.php when it finishes. It is not needed again.

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
check public/install.php          "web installer"
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
