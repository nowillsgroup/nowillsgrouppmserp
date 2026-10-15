#!/usr/bin/env python3
"""v7.44 — save an already-rostered week twice through the UI and count the rows.

Pre-fix, RosterService::saveWeek() looked the existing shift up with string equality on a DATE
column that stores "Y-m-d 00:00:00", so every posted cell looked like a change and
updateOrCreate() INSERTed a second row for the same employee+day -> UNIQUE constraint 500.
Post-fix the lookup and the upsert both match by calendar day.

    python3 deploy/dev/audit/roster_save_flow.py [dept] [week]
"""
import re, subprocess, sys, json
sys.path.insert(0, "deploy/dev/audit")
from pmshttp import Pms

dept = sys.argv[1] if len(sys.argv) > 1 else "9"
week = sys.argv[2] if len(sys.argv) > 2 else "2026-10-12"
TINK = 'deploy/dev/bin/php'


def counts():
    out = subprocess.run(["bash", "-c", "cd /home/user/nowillsgrouppmserp && DEPT=%s WEEK=%s "
                          "TINKER='require \"/app/deploy/dev/audit/roster_counts.php\";' deploy/dev/bin/php -f deploy/dev/tinker.php 2>&1 | tail -1" % (dept, week)],
                         capture_output=True, text=True).stdout.strip()
    try:
        return json.loads(out)
    except Exception:
        print("   !! count probe failed:", out[:200])
        return {}


p = Pms(timeout=180)
path = "/hr/roster?dept=%s&week=%s" % (dept, week)
html = p.get(path)
print("GET %-34s -> %s (%d bytes)" % (path, p.last_code, len(html)))
tok = p.token(html)
cells = dict(re.findall(r'name="(cells\[[^\]]+\]\[[^\]]+\])"[^>]*value="([^"]*)"', html))
if not cells:                                    # select-based grid
    for m in re.finditer(r'<select[^>]*name="(cells\[[^\]]+\]\[[^\]]+\])"[^>]*>(.*?)</select>', html, re.S):
        sel = re.search(r'<option[^>]*value="([^"]*)"[^>]*selected', m.group(2))
        cells[m.group(1)] = sel.group(1) if sel else ""
print("harvested %d roster cells, _token %s..." % (len(cells), (tok or "")[:8]))
c0 = counts()
print("BEFORE: %s" % json.dumps(c0))

data = {"dept": dept, "week": week}
data.update(cells)
code, body, loc = p.post("/hr/roster", data, tok, referer=path)
print("POST unchanged week  -> %s  location %s" % (code, loc))
if code == 500:
    m = re.search(r"<title>(.*?)</title>", body, re.S)
    print("   500 title: %s" % (m.group(1).strip()[:150] if m else "-"))
    hit = re.search(r"(UNIQUE constraint failed[^<\"]{0,80}|SQLSTATE\[\w+\][^<\"]{0,120})", body)
    print("   500 cause: %s" % (hit.group(1)[:150] if hit else "see storage/logs/laravel.log"))
else:
    loc = re.sub(r"^https?://[^/]+", "", loc) or path
    nxt = p.get(loc)
    m = re.search(r"(Roster saved[^<]{0,90}|Please fix the following[^<]{0,90})", nxt)
    print("   flash: %s" % (m.group(1).strip() if m else "(none found)"))
c1 = counts()
print("AFTER : %s" % json.dumps(c1))
print("VERDICT: %s" % ("row count unchanged, no duplicates -> upsert matched by day"
                       if c0 and c1 and c0.get("roster_shifts_total") == c1.get("roster_shifts_total") and not c1.get("dup_groups")
                       else "ROW COUNT CHANGED or duplicates present"))
