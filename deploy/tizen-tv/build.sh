#!/bin/sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
PROFILE=${TIZEN_CERT_PROFILE:-}

if [ -z "$PROFILE" ]; then
  echo "Set TIZEN_CERT_PROFILE to a Tizen Studio certificate profile name." >&2
  exit 2
fi
if ! command -v tizen >/dev/null 2>&1; then
  echo "Tizen CLI not found. Install Tizen Studio with the Samsung TV Extension and add its tools to PATH." >&2
  exit 2
fi

cd "$ROOT"
tizen build-web -- "$ROOT"
tizen package -t wgt -s "$PROFILE" -- "$ROOT/.buildResult"

mkdir -p "$ROOT/dist"
PACKAGE=$(find "$ROOT/.buildResult" "$ROOT" -maxdepth 2 -type f -name '*.wgt' ! -path "$ROOT/dist/*" -print -quit)
if [ -z "$PACKAGE" ]; then
  echo "Tizen CLI finished but no .wgt was found in the project build output; check the CLI output and Tizen Studio version." >&2
  exit 1
fi
cp "$PACKAGE" "$ROOT/dist/NowillsPmsTV.wgt"
echo "Signed TV app: $ROOT/dist/NowillsPmsTV.wgt"
