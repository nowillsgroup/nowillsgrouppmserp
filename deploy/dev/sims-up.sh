#!/usr/bin/env bash
# Dev-only: start every OTA / channel simulator the adapter contract harness drives.
#   8091 channel-sim      Nowills Channel hub protocol (signed JSON)
#   8093 booking-eps-sim  Booking.com EPS
#   8095 expedia-sim      Expedia Rapid (HMAC-SHA512 request signing — rejects bad signatures)
#   8096 siteminder-sim   SiteMinder (OTA XML — rejects a wrong bearer token)
# Safe to re-run after a wipe; it only starts local processes. Ctrl-C (or stop_process) takes them all down.
set -uo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."
BUN="deploy/dev/node_modules/.bin-local/bun"
if [ ! -x "$BUN" ]; then echo "Runtime not installed — run deploy/dev/setup.sh first" >&2; exit 1; fi

pids=()
start() {
  local name="$1"; shift
  "$BUN" "$@" &
  pids+=($!)
  echo "[sims] $name started (pid $!)"
}
trap 'kill "${pids[@]}" 2>/dev/null' TERM INT

start channel-sim     deploy/dev/channel-sim.mjs     --port=8091
start booking-eps-sim deploy/dev/booking-eps-sim.mjs --port=8093 --secret=epstest
start expedia-sim     deploy/dev/expedia-sim.mjs     --port=8095 --key=expkey --secret=expsecret
start siteminder-sim  deploy/dev/siteminder-sim.mjs  --port=8096 --token=smtoken --hotel=NWG01

for port in 8091 8093 8095 8096; do
  up=""
  for _ in $(seq 1 30); do
    code=$(curl -s -o /dev/null -w '%{http_code}' -m 2 "http://127.0.0.1:${port}/" 2>/dev/null || true)
    if [ -n "$code" ] && [ "$code" != "000" ]; then up="$code"; break; fi
    sleep 0.4
  done
  echo "[sims] :${port} ${up:-NOT RESPONDING}"
done
echo "SIMS_READY"
wait
