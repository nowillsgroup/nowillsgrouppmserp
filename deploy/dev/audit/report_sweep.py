#!/usr/bin/env python3
"""v7.44 — render every /reports/{type} over a real date range and flag anything that is not a clean 200.

    python3 deploy/dev/audit/report_sweep.py [out.json] [from] [to]
"""
import json, re, sys, time
sys.path.insert(0, "deploy/dev/audit")
from pmshttp import Pms

out = sys.argv[1] if len(sys.argv) > 1 else "/tmp/reports.json"
frm = sys.argv[2] if len(sys.argv) > 2 else "2026-09-01"
to = sys.argv[3] if len(sys.argv) > 3 else "2026-09-30"
types = sorted(set(re.findall(r"protected function r_([a-z_0-9]+)\(", open("app/Http/Controllers/ReportController.php").read())))
MARK = ("Whoops", "Fatal error", "Call to undefined", "SQLSTATE", "syntax error", "Undefined variable",
        "Attempt to read property", "Trying to access array offset", "Division by zero", "does not exist")
p = Pms(timeout=180)
bad, ok, t0 = [], 0, time.time()
for i, t in enumerate(types, 1):
    u = "/reports/%s?from=%s&to=%s" % (t, frm, to)
    try:
        html = p.get(u); code = p.last_code
    except Exception as e:
        html, code = "EXC " + repr(e), 0
    err = next((m for m in MARK if m in html), "")
    if code != 200 or err:
        bad.append({"report": t, "code": code, "err": err, "bytes": len(html)})
    else:
        ok += 1
    if i % 40 == 0:
        print("  ...%d/%d %.0fs bad=%d" % (i, len(types), time.time() - t0, len(bad)), flush=True)
json.dump({"total": len(types), "ok": ok, "bad": bad, "range": [frm, to]}, open(out, "w"), indent=1)
print("\nREPORTS %d in %.0fs   clean: %d   problems: %d" % (len(types), time.time() - t0, ok, len(bad)))
for b in bad:
    print("   %-4s %-26s %s (%d bytes)" % (b["code"], b["err"] or "-", b["report"], b["bytes"]))
