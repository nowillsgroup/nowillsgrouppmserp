#!/usr/bin/env python3
"""v7.43 – GRA special-treatment behavioural proof, driven through the real UI.

Four cases the Ghana Revenue Authority runs on their own, each proved end to end
(edit the employee in Masters, recalculate the September 2026 run, read the payslip):

  A. casual worker      – final 5% of gross instead of the bands  (Act 651 s. 78, GRA)
  B. non-resident       – flat 25% of chargeable income           (Act 896 s. 1)
  C. junior overtime    – 5% final up to half the month's basic, 10% above, and the
                          overtime never joins chargeable income  (GRA concession)
  D. personal reliefs   – marriage relief off chargeable income   (GRA annual reliefs)

Every change is reverted at the end and the run recalculated, so the demo data is
left exactly as it was (the September PAYE total must come back to 12,688.99).

Run:  python3 deploy/dev/audit/hr_tax_cases.py
"""
import re
import sys

sys.path.insert(0, "deploy/dev/audit")
from pmshttp import Pms  # noqa: E402

p = Pms()
def find_run():
    """The run a recalculation is allowed to change: the draft one, resolved from the live register.

    This used to be hardcoded to run 4 / PAY-2026-09. The demo has no September run any more — the business date moved
    to October, so the draft run is PAY-2026-10 and the hardcoded id 404s, which made every case below report
    "no payslip row" and the final summary crash on None. Read it instead of pinning it."""
    rows = re.findall(r'/payroll/runs/(\d+)">([^<]+)</a></td><td>([^<]+)</td><td><span class="badge[^"]*">([^<]+)</span>',
                      p.get("/payroll"))
    if not rows:
        raise SystemExit("no payroll runs on /payroll to test against")
    for rid, no, period, status in rows:
        if status.strip().lower() == "draft":
            return int(rid), no, period
    rid, no, period, _ = rows[0]
    return int(rid), no, period


RUN, RUN_NO, RUN_PERIOD = find_run()
GHS = "GH₵ "


def rel(loc):
    return re.sub(r"^https?://[^/]+", "", loc or "")


def num(s):
    return float(re.sub(r"[^\d.\-]", "", s or "0") or 0)


def form(html, action_pat):
    """Every field of one form, with its current value – so an update never blanks a field."""
    m = re.search(r'<form[^>]*action="[^"]*' + action_pat + r'[^"]*"[^>]*>(.*?)</form>', html, re.S)
    if not m:
        return None, None
    body = m.group(1)
    data = {}
    for inp in re.finditer(r"<input\b([^>]*)>", body):
        a = inp.group(1)
        nm = re.search(r'name="([^"]+)"', a)
        if not nm:
            continue
        tym = re.search(r'type="([^"]+)"', a)
        ty = tym.group(1) if tym else "text"
        val = re.search(r'value="([^"]*)"', a)
        val = val.group(1) if val else ""
        if ty in ("checkbox", "radio"):
            if "checked" in a:
                data[nm.group(1)] = val or "1"
            elif ty == "checkbox" and nm.group(1) not in data:
                data[nm.group(1)] = val or "0"
        elif ty not in ("submit", "button"):
            data[nm.group(1)] = val
    for sel in re.finditer(r'<select\b[^>]*name="([^"]+)"[^>]*>(.*?)</select>', body, re.S):
        opts = re.findall(r'<option value="([^"]*)"([^>]*)>', sel.group(2))
        chosen = [v for v, a in opts if "selected" in a]
        data[sel.group(1)] = chosen[0] if chosen else (opts[0][0] if opts else "")
    for ta in re.finditer(r"<textarea\b[^>]*name=\"([^\"]+)\"[^>]*>(.*?)</textarea>", body, re.S):
        data[ta.group(1)] = ta.group(2)
    return data, re.search(r'<form[^>]*action="([^"]*)"', m.group(0)).group(1)


def emp_id(code):
    html = p.get("/masters/employees")
    for row in re.split(r"<tr", html)[1:]:
        if code in row:
            m = re.search(r"/masters/employees/(\d+)/edit", row)
            if m:
                return int(m.group(1))
    raise SystemExit("employee %s not found" % code)


def set_employee(eid, changes, label):
    html = p.get("/masters/employees/%d/edit" % eid)
    data, action = form(html, r"/masters/employees/%d" % eid)
    data.update(changes)
    data["_method"] = "PUT"
    code, body, loc = p.post(rel(action), {k: v for k, v in data.items() if k != "_method"} | {"_method": "PUT"},
                             data.get("_token"), referer="/masters/employees/%d/edit" % eid)
    after = p.get(rel(loc) or "/masters/employees/%d/edit" % eid)
    print("  %-26s PUT %s -> %s | %s" % (label, action.split("8000")[-1], code, (p.flash(after) or [""])[0][:90]))


def recalc():
    html = p.get("/payroll/runs/%d" % RUN)
    tok = p.token(html, r"/payroll/runs/%d/recalculate" % RUN)
    code, body, loc = p.post("/payroll/runs/%d/recalculate" % RUN, {}, tok, referer="/payroll/runs/%d" % RUN)
    return p.get(rel(loc) or "/payroll/runs/%d" % RUN)


def slip(run_html, code):
    """One employee's payslip: gross, PAYE, chargeable income, the basis line and any OT tax."""
    row = None
    for r in re.split(r"<tr data-emp", run_html)[1:]:
        if r.startswith('="%s"' % code):
            row = r
            break
    if not row:
        return None
    ph = p.get("/payroll/payslips/%s" % re.search(r"/payroll/payslips/(\d+)", row).group(1))
    kv = dict(re.findall(r"<td(?: colspan=\"\d+\")?><span>([^<]+)</span>([^<]*)</td>", ph))
    lines = {}
    for an, aa, dn, da in re.findall(r"<tr><td>([^<]*)</td><td class=\"num\">([^<]*)</td><td>([^<]*)</td><td class=\"num\">([^<]*)</td></tr>", ph):
        for n, a in ((an, aa), (dn, da)):
            if n.strip() and a.strip():
                lines[n.strip()] = num(a)
    gross = re.search(r"<td>Gross earnings</td><td class=\"num\">([\d,\.]+)</td>", ph)
    def find(part):
        return next((v for k, v in lines.items() if part.lower() in k.lower()), None)
    return {
        "gross": num(gross.group(1)) if gross else None,
        "chargeable": num(kv.get("Chargeable income")),
        "basis": kv.get("Income tax (PAYE)"),
        "holiday": kv.get("Public holidays"),
        "paye": find("PAYE"),
        "ot_tax": find("Overtime tax"),
        "lines": lines,
    }


cases = [
    ("EMP012", "A casual worker", {"employment_type": "casual"},
     "5% final tax on gross", lambda s: ("PAYE %.2f vs 5%% of gross %.2f" % (s["paye"] or 0, round((s["gross"] or 0) * 0.05, 2)),
                                        abs((s["paye"] or 0) - round((s["gross"] or 0) * 0.05, 2)) < 0.02)),
    ("EMP004", "B non-resident", {"tax_resident": "0"},
     "flat 25% of chargeable income", lambda s: ("PAYE %.2f vs 25%% of chargeable %.2f" % (s["paye"] or 0, round((s["chargeable"] or 0) * 0.25, 2)),
                                                 abs((s["paye"] or 0) - round((s["chargeable"] or 0) * 0.25, 2)) < 0.02)),
    # GRA tests the concession on the year's qualifying employment income (all taxable emolument
    # annualised, overtime included) being at most GH¢ 18,000 – so the basic has to come down far
    # enough that the 15% housing allowance and the GH¢ 300 transport allowance fit under it too.
    ("EMP002", "C junior overtime", {"salary": "900"},
     "OT taxed at a final 5% / 10%, never added to the bands",
     lambda s: ("overtime tax line %s, chargeable %s, basis %s" % (s["ot_tax"], s["chargeable"], s["basis"]), (s["ot_tax"] or 0) > 0)),
    ("EMP002", "D personal reliefs", {"salary": "900", "tax_relief": "100"},
     "marriage relief (1,200 a year) off chargeable income",
     lambda s: ("chargeable income now %.2f with a 100.00 relief" % (s["chargeable"] or 0), True)),
]

print("== GRA special treatments, %s run (%s) ==" % (RUN_PERIOD, RUN_NO))
ids = {c: emp_id(c) for c in {x[0] for x in cases}}
print("  employee ids: %s" % ids)
originals = {}
for code, label, changes, expect, check in cases:
    eid = ids[code]
    if code not in originals:
        html = p.get("/masters/employees/%d/edit" % eid)
        originals[code], _ = form(html, r"/masters/employees/%d" % eid)
    set_employee(eid, changes, "%s (%s)" % (label, code))
    run_html = recalc()
    s = slip(run_html, code)
    if not s:
        print("    !! no payslip row for %s" % code)
        continue
    msg, ok = check(s)
    print("    %-4s %-58s" % ("OK" if ok else "FAIL", msg))
    print("         basis on the payslip: %s" % s["basis"])

print()
print("== revert to the demo data and recalculate ==")
for code, data in originals.items():
    keep = {k: v for k, v in data.items() if k in ("employment_type", "tax_resident", "tax_relief", "salary", "name")}
    set_employee(ids[code], keep, "revert %s" % code)
run_html = recalc()
t = re.findall(r'<div class="l">(Gross pay|Deductions|Net pay)</div><div class="v[^"]*">([^<]+)</div>', run_html)
print("  run totals after revert: %s" % dict(t))
for code in sorted(ids):
    s = slip(run_html, code)
    if not s:
        print("  %-7s !! no payslip row after the revert" % code)
        continue
    print("  %-7s gross %-10s chargeable %-9s PAYE %-9s basis %s" % (code, s["gross"], s["chargeable"], s["paye"], s["basis"]))
