#!/usr/bin/env python3
"""v7.44 — sweep every parameter-free GET route and report anything that is not a clean 200.

The regression gate for repo-wide query changes: run it before and after a sweep of edits
and diff the non-200 list (python3 deploy/dev/audit/full_get_sweep.py [out.json]).
"""
import json, sys, time
sys.path.insert(0, "deploy/dev/audit")
from pmshttp import Pms

out = sys.argv[1] if len(sys.argv) > 1 else "/tmp/sweep.json"
routes = json.load(open("/tmp/routes.json"))
uris = []
for r in routes:
    if "GET" not in r["method"]:
        continue
    u = r["uri"]
    if "{" in u or not u or u == "Dashboard":
        continue
    u = "/" + u.lstrip("/")
    if u not in uris:
        uris.append(u)
p = Pms(timeout=180)
MARKERS = ("Whoops", "Fatal error", "Call to undefined function", "Call to undefined method",
           "SQLSTATE", "syntax error", "Undefined variable", "Attempt to read property",
           "Trying to access array offset", "Division by zero")
bad, ok, t0 = [], 0, time.time()
for i, u in enumerate(uris, 1):
    try:
        html = p.get(u)
        code = p.last_code
    except Exception as e:
        html, code = "EXC " + repr(e), 0
    err = next((m for m in MARKERS if m in html), "")
    if code != 200 or err:
        bad.append({"uri": u, "code": code, "err": err, "bytes": len(html)})
    else:
        ok += 1
    if i % 50 == 0:
        print("  ...%d/%d  %.0fs  bad=%d" % (i, len(uris), time.time() - t0, len(bad)), flush=True)
json.dump({"total": len(uris), "ok": ok, "bad": bad, "seconds": round(time.time() - t0)},
          open(out, "w"), indent=1)
print("\nSWEPT %d pages in %.0fs   clean 200: %d   problems: %d" % (len(uris), time.time() - t0, ok, len(bad)))
for b in bad:
    print("   %-4s %-28s %s (%d bytes)" % (b["code"], b["err"] or "-", b["uri"][:64], b["bytes"]))
