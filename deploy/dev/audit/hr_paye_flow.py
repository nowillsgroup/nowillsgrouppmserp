#!/usr/bin/env python3
"""v7.43 HR & workforce flow proof – drives the real UI, no direct DB writes.

  1. recalculates the October 2026 draft run and reports the PAYE it now computes
     (Act 1178 bands, in force 1 Sep 2026);
  2. creates the September 2026 run through Payroll > New run;
  3. marks 21 Sep 2026 (Kwame Nkrumah Memorial Day) as a worked public holiday for
     three employees on HR > Attendance, with clock times;
  4. recalculates September and reports holiday days / hours, the OTH holiday pay and PAYE.

Run:  python3 deploy/dev/audit/hr_paye_flow.py
"""
import re
import sys

sys.path.insert(0, "deploy/dev/audit")
from pmshttp import Pms  # noqa: E402

p = Pms()
GHS = "GH₵"


def rel(loc):
    """A Laravel redirect Location is absolute (back() echoes the Referer host) – make it a path."""
    return re.sub(r"^https?://[^/]+", "", loc or "")


def num(s):
    return float(re.sub(r"[^\d.\-]", "", s) or 0)


def totals(html):
    out = {}
    for label, val in re.findall(r'<div class="l">([^<]+)</div><div class="v[^"]*">([^<]+)</div>', html):
        out[label.strip()] = val.strip()
    return out


def codes(html):
    """code -> (rows, amount) from the three Earnings / Deductions / Employer tables."""
    res = {}
    for m in re.finditer(r'badge-slate">([A-Z0-9_]+)</span>\s*([^<]*)</td><td class="num small muted">(\d+)</td><td class="num">([^<]+)</td>', html):
        res[m.group(1)] = (int(m.group(3)), num(m.group(4)))
    return res


def run_ids():
    html = p.get("/payroll")
    out = {}
    # run rows: link then run number somewhere in the row
    for row in re.split(r"<tr", html)[1:]:
        mid = re.search(r'/payroll/runs/(\d+)', row)
        mno = re.search(r'(PAY-\d{4}-\d{2})', row)
        if mid and mno:
            out[mno.group(1)] = int(mid.group(1))
    return out


def recalc(rid, label):
    html = p.get("/payroll/runs/%d" % rid)
    tok = p.token(html, r"/payroll/runs/%d/recalculate" % rid)
    if not tok:
        print("  !! no recalculate form on %s" % label)
        return None
    code, body, loc = p.post("/payroll/runs/%d/recalculate" % rid, {}, tok, referer="/payroll/runs/%d" % rid)
    after = p.get(rel(loc) or "/payroll/runs/%d" % rid)
    for msg in p.flash(after)[:2]:
        print("  flash: %s" % msg)
    return after


print("== October 2026 draft: recompute on the bands in force (Act 1178) ==")
ids = run_ids()
print("  runs found: %s" % ids)
oct_id = ids.get("PAY-2026-10")
if oct_id:
    after = recalc(oct_id, "October")
    t, c = totals(after), codes(after)
    print("  gross %s | deductions %s | net %s" % (t.get("Gross pay"), t.get("Deductions"), t.get("Net pay")))
    for k in ("BASIC", "HOUSING", "TRANSPORT", "OT", "OTH", "SSNIT", "PAYE", "OT_TAX", "ADV"):
        if k in c:
            print("    %-8s %2d slip(s)  %s %s" % (k, c[k][0], GHS, format(c[k][1], ",.2f")))
    m = re.search(r'PAYE bands: ([^<]+)', after)
    print("  bands line on the run page: %s" % (m.group(1).strip() if m else "MISSING"))

print()
print("== September 2026: create the run (Act 1178 month + Kwame Nkrumah Memorial Day) ==")
html = p.get("/payroll")
tok = p.token(html, r"/payroll")
code, body, loc = p.post("/payroll", {"month": "2026-09", "notes": "Act 1178 bands + public-holiday demo"}, tok, referer="/payroll")
after = p.get(rel(loc) or "/payroll")
ids = run_ids()
sep_id = ids.get("PAY-2026-09")
print("  POST /payroll month=2026-09 -> %s, location %s, run id %s" % (code, loc, sep_id))
for msg in p.flash(after)[:2]:
    print("  flash: %s" % msg)

print()
print("== Mark 21 Sep 2026 as a worked public holiday for three employees ==")
att = p.get("/hr/attendance?date=2026-09-21")
m = re.search(r'Public holiday[^<]*<', att)
print("  holiday banner on the attendance sheet: %s" % (re.sub(r"<[^>]+>", "", m.group(0)).strip() if m else "MISSING"))
tok = p.token(att, r"/hr/attendance")
emp = re.findall(r'name="att\[(\d+)\]\[status\]"', att)
names = dict(re.findall(r'data-emp="(\d+)"[^>]*>\s*<td[^>]*>([^<]+)</td>', att))
pick = emp[:3]
data = {}
for e in pick:
    data["att[%s][status]" % e] = "PH"
    data["att[%s][in_time]" % e] = "08:00"
    data["att[%s][out_time]" % e] = "17:00"
    data["att[%s][remarks]" % e] = "Kwame Nkrumah Memorial Day - worked, flow test"
code, body, loc = p.post("/hr/attendance?date=2026-09-21", data, tok, referer="/hr/attendance?date=2026-09-21")
after = p.get(rel(loc) or "/hr/attendance?date=2026-09-21")
for msg in p.flash(after)[:2]:
    print("  flash: %s" % msg)
sel = re.findall(r'name="att\[(\d+)\]\[status\]"[^>]*>.*?<option value="PH"([^>]*)>', after, re.S)
print("  PH selected for: %s" % [e for e, a in sel if "selected" in a])

print()
print("== September 2026: recompute with the holiday worked ==")
if sep_id:
    after = recalc(sep_id, "September")
    t, c = totals(after), codes(after)
    print("  gross %s | deductions %s | net %s" % (t.get("Gross pay"), t.get("Deductions"), t.get("Net pay")))
    for k in ("BASIC", "HOUSING", "TRANSPORT", "OT", "OTH", "SSNIT", "PAYE", "OT_TAX", "ADV"):
        if k in c:
            print("    %-8s %2d slip(s)  %s %s" % (k, c[k][0], GHS, format(c[k][1], ",.2f")))
    m = re.search(r'PAYE bands: ([^<]+)', after)
    print("  bands line on the run page: %s" % (m.group(1).strip() if m else "MISSING"))
    hol = re.findall(r'<td class="num "[^>]*title="([\d.]+) h worked on a public holiday">([\d.]+)</td>', after)
    print("  PH column: %s" % (hol if hol else "no holiday-day cells found"))

print()
print("== Statutory returns for September (the module that used to say 'no payroll run') ==")
for tab in ("ssnit", "tier2", "paye"):
    st, body, loc = p.status("/payroll/statutory?tab=%s&period=2026-09" % tab)
    txt = re.sub(r"<[^>]+>", " ", body)
    tot = re.search(r'id="statTotal"[^>]*>([^<]+)<', body) or re.search(r'(GH₵\s*[\d,]+\.\d\d)', txt)
    print("  %-6s HTTP %s  total %s  %s" % (tab, st, tot.group(1).strip() if tot else "?",
                                            "no payroll run" if "No payroll run" in txt else ""))
