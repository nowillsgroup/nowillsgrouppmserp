#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Nowills PMS — make the live checkout clean again
#
#   bash deploy/cyberpanel/fix-git-tree.sh                  # diagnose only
#   bash deploy/cyberpanel/fix-git-tree.sh --fix            # clean what it safely can
#   bash deploy/cyberpanel/fix-git-tree.sh --stash          # also park real edits
#   bash deploy/cyberpanel/fix-git-tree.sh --site /path     # not the default site
#
# Why this exists
# ---------------
# CyberPanel's Git Manager (and `git pull`) refuse to run on a checkout with
# uncommitted changes. The classic case is the web installer: the guides tell you to
# delete `public/install.php` after installing, which leaves ` D public/install.php`
# for ever, and every later pull/push is refused with a message that does not
# mention the installer at all.
#
# The installer is no longer tracked (it ships as deploy/installers/*.php.txt), so a
# fresh clone stays clean. This script is for a checkout made before that change:
# it marks those paths "assume unchanged" so deleting them is legitimate, and it
# reports anything else that stands in the way.
#
# It never deletes, resets, checks out over your work, or touches the database.
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

SITE=/home/pms.nowills.com/public_html
MODE=report

while [ $# -gt 0 ]; do
    case "$1" in
        --site)  SITE="${2:?--site needs a path}"; shift 2 ;;
        --fix)   MODE=fix; shift ;;
        --stash) MODE=stash; shift ;;
        -h|--help) sed -n '2,30p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "unknown option: $1" >&2; exit 2 ;;
    esac
done

say()  { printf '%s\n' "$*"; }
head_() { printf '\n\033[1m%s\033[0m\n' "$*"; }

[ -d "$SITE/.git" ] || { echo "no git checkout at $SITE" >&2; exit 1; }
cd "$SITE"

# CyberPanel may run as the site user while .git was created by root; git then
# refuses every command with "detected dubious ownership".
if ! git status --porcelain >/dev/null 2>&1; then
    err="$(git status --porcelain 2>&1 || true)"
    if printf '%s' "$err" | grep -q "dubious ownership"; then
        head_ "git will not read this repository (dubious ownership)"
        say "Run, then run this script again:"
        say "  git config --global --add safe.directory $SITE"
    fi
    printf '%s\n' "$err" >&2
    exit 1
fi

head_ "Where we are"
say "site      $SITE"
say "user      $(id -un)   (CyberPanel normally runs as: $(stat -c '%U' "$SITE"))"
say "branch    $(git rev-parse --abbrev-ref HEAD)"
say "commit    $(git log -1 --oneline)"

head_ "Uncommitted changes"
DIRT="$(git status --porcelain || true)"
if [ -z "$DIRT" ]; then
    say "none — the checkout is clean, so the panel can pull and push."
else
    printf '%s\n' "$DIRT"
fi

# Paths that are tracked in older checkouts but must not exist on a finished server.
INSTALLERS=(public/install.php install.php)
FIXED=0
for f in "${INSTALLERS[@]}"; do
    if git ls-files --error-unmatch "$f" >/dev/null 2>&1; then
        state="$(git status --porcelain -- "$f" || true)"
        head_ "Installer: $f"
        say "still tracked in this checkout (it ships as deploy/installers/*.php.txt now)"
        if [ -n "$state" ]; then
            say "state     $state"
        fi
        if [ "$MODE" = report ]; then
            say "fix       git update-index --skip-worktree $f   (then delete the file, if it is still there)"
        else
            git update-index --skip-worktree "$f"
            say "fixed     marked skip-worktree — deletion of $f no longer counts as a change"
            FIXED=$((FIXED + 1))
        fi
    fi
done

OTHER="$(git status --porcelain | grep -v -E ' (public/install\.php|install\.php)$' || true)"
if [ -n "$OTHER" ]; then
    head_ "Other uncommitted files"
    printf '%s\n' "$OTHER"
    case "$MODE" in
        stash)
            say "Parking them in a stash so a pull can run; restore with: git stash pop"
            git stash push -u -m "live edits before pull $(date -u +%Y-%m-%dT%H:%M:%SZ)"
            ;;
        *)
            say "These are real edits. Either commit them, or park them for the pull:"
            say "  git stash push -u -m 'live edits'  &&  git pull --ff-only  &&  git stash pop"
            say "Or just re-run this script with --stash."
            ;;
    esac
fi

head_ "Result"
if [ -z "$(git status --porcelain)" ]; then
    say "clean — safe for the panel, or for:"
    say "  sudo -u $(stat -c '%U' "$SITE") git -C $SITE pull --ff-only"
    say "  sudo bash $SITE/deploy/cyberpanel/post-deploy.sh"
else
    say "still not clean — see above. Nothing was deleted or reset."
fi

[ "$FIXED" -eq 0 ] || say "(installed skip-worktree on $FIXED path(s); undo with: git update-index --no-skip-worktree <path>)"
