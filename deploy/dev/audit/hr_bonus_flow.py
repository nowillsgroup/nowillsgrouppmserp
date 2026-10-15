#!/usr/bin/env python3
"""v7.45 — a bonus on a real payslip, driven through the UI (no direct DB writes).

  1. reads the October 2026 draft run and one employee's payslip;
  2. adds a festival bonus with the new "Bonus / ex-gratia – GRA final tax" treatment;
  3. checks the payslip, the run totals and the GRA return against figures hand-computed from
     the rule (a final 5% up to 15% of the year's basic, the excess on the Act 1178 bands);
  4. recalculates the run – a bonus must survive recalculation like any other adjustment;
  5. removes it, checks the payslip returns to exactly what it was, and puts the demo bonus back.

Run:  python3 deploy/dev/audit/hr_bonus_flow.py
"""
import re
import sys

sys.path.insert(0, "deploy/dev/audit")
from pmshttp import Pms  # noqa: E402

p = Pms()
GHS = "GH₵"
# GRA PAYE monthly bands in force from 1 Sep 2026 (Income Tax (Amendment) Act 2026, Act 1178).
BANDS = [(588, 0.0), (668, 5.0), (768, 10.0), (3668, 17.5), (19668, 25.0), (50000, 30.0), (None, 35.0)]
BONUS_PCT, BONUS_RATE = 15.0, 5.0
BONUS = 5500.00


def paye(chargeable):
    """The published bands, applied the way GRA applies them."""
    tax, prev = 0.0, 0.0
    for cap, rate in BANDS:
        if cap is None:
            tax += max(0.0, chargeable - prev) * rate / 100
            break
        if chargeable <= prev:
            break
        tax += (min(chargeable, cap) - prev) * rate / 100
        prev = cap
    return round(tax, 2)


def rel(loc):
    return re.sub(r"^https?://[^/]+", "", loc or "")


def rows(html):
    """emp code -> dict(name, resident, basic, gross, deductions, net, slip, manual).

    The run table has four "count" columns before the money ones, so the money cells are taken from
    `<td class="num small">` (monthly basic) up to the pay-mode cell, and the manual adjustments of the
    row are collected with the line id their remove-form posts to.
    """
    out = {}
    for r in re.split(r'<tr data-emp="', html)[1:]:
        code = r.split('"', 1)[0]
        seg = re.search(r'<td class="num small">(.+?)<td class="small">', r, re.S)
        # the segment starts inside the first money cell, so the numbers are matched without a leading ">"
        money = [float(re.sub(r"[^\d.\-]", "", x) or 0) for x in re.findall(r"([\d,]+\.\d\d)</td>", seg.group(1))] if seg else []
        money += [0.0] * (4 - len(money))
        slip = re.search(r"/payroll/payslips/(\d+)", r)
        manual = [(float(re.sub(r"[^\d.]", "", a)), n.strip(), int(lid))
                  for a, n, lid in re.findall(r'badge-(?:green|red)">[^<]*?([\d,]+\.\d\d)</span>\s*([^<]*)<form[^>]*action="[^"]*/payroll/lines/(\d+)"', r)]
        out[code] = {"name": (re.search(r'<span class="bold">([^<]+)', r) or [None, "?"])[1],
                     "resident": "Non-resident" not in r, "basic": money[0], "gross": money[1],
                     "deductions": money[2], "net": money[3], "slip": int(slip.group(1)) if slip else None,
                     "manual": manual}
    return out


def drop_manual(html, emp, referer):
    """Remove every manual adjustment of one employee through its own remove-form; returns the fresh run page."""
    r = rows(html).get(emp, {})
    for amount, name, lid in r.get("manual", []):
        tok = p.token(html, r"/payroll/lines/%d" % lid)
        code, body, loc = p.post("/payroll/lines/%d" % lid, {"_method": "DELETE", "_token": tok}, tok, referer=referer)
        print("  removed %s %s%.2f (line %d) -> %s" % (name, GHS, amount, lid, code))
        html = p.get(rel(loc) or referer)
    return html


def slip_lines(html):
    """The payslip's earning / deduction lines: name -> amount (in the order printed)."""
    out = []
    for m in re.finditer(r"<tr><td>([^<]+)</td><td class=\"num\">([\d,\.]*)</td><td>([^<]*)</td><td class=\"num\">([\d,\.]*)</td></tr>", html):
        if m.group(1).strip():
            out.append((m.group(1).strip(), float(re.sub(r"[^\d.]", "", m.group(2)))))
        if m.group(3).strip():
            out.append((m.group(3).strip(), -float(re.sub(r"[^\d.]", "", m.group(4)))))
    foot = re.findall(r"<tfoot>.*?</tfoot>", html, re.S)
    t = {}
    for label, val in re.findall(r"<td>(Gross earnings|Total deductions)</td><td class=\"num\">([\d,\.]+)</td>", foot[0] if foot else ""):
        t[label] = float(re.sub(r"[^\d.]", "", val))
    net = re.search(r"<span>Net pay</span><b>([^<]+)</b>", html)
    basis = re.search(r"<span>Income tax \(PAYE\)</span>([^<]+)</td>", html)
    return out, t, (float(re.sub(r"[^\d.]", "", net.group(1))) if net else None), (basis.group(1).strip() if basis else "")


def add_bonus(slip_id, amount, tok, referer):
    code, body, loc = p.post("/payroll/payslips/%d/lines" % slip_id,
                             {"kind": "earning", "name": "Festival bonus", "amount": "%.2f" % amount,
                              "component": "BONUS", "_token": tok},
                             tok, referer=referer)
    after = p.get(rel(loc) or referer)
    return code, after


rid = 3
emp = "EMP001"
run_html = p.get("/payroll/runs/%d" % rid)
if rows(run_html).get(emp, {}).get("manual"):
    print("== clearing the adjustments left on %s by an earlier pass ==" % emp)
    run_html = drop_manual(run_html, emp, "/payroll/runs/%d" % rid)
print("\n== October 2026 draft run: before ==")
before = rows(run_html)
e = before[emp]
print("  %s %s — basic %s%.2f, gross %s%.2f, deductions %s%.2f, net %s%.2f (payslip %d)"
      % (emp, e["name"], GHS, e["basic"], GHS, e["gross"], GHS, e["deductions"], GHS, e["net"], e["slip"]))

slip_html = p.get("/payroll/payslips/%d" % e["slip"])
lines0, tot0, net0, basis0 = slip_lines(slip_html)
print("  payslip: gross %s%.2f | deductions %s%.2f | net %s%.2f" % (GHS, tot0.get("Gross earnings", 0), GHS, tot0.get("Total deductions", 0), GHS, net0))
print("  PAYE basis before: %s" % basis0)

# ── what the rule says must happen ────────────────────────────────────────────
cap = round(e["basic"] * 12 * BONUS_PCT / 100, 2)
final_part = round(min(BONUS, cap), 2)
excess = round(BONUS - final_part, 2)
bonus_tax = round(final_part * BONUS_RATE / 100, 2)
ssnit = round(e["basic"] * 0.055, 2)
chargeable0 = round(e["gross"] - ssnit, 2)
chargeable1 = round(chargeable0 + excess, 2)
exp_paye0, exp_paye1 = paye(chargeable0), paye(chargeable1)
print("\n== What GRA's rule requires for a %s%.2f bonus on a %s%.2f basic ==" % (GHS, BONUS, GHS, e["basic"]))
print("  15%% of the year's basic (%.2f x 12) = cap %s%.2f -> %s%.2f at a final 5%% = %s%.2f"
      % (e["basic"], GHS, cap, GHS, final_part, GHS, bonus_tax))
print("  excess over the cap %s%.2f joins chargeable income: %s%.2f -> %s%.2f" % (GHS, excess, GHS, chargeable0, GHS, chargeable1))
print("  PAYE on the bands %s%.2f -> %s%.2f (+%s%.2f)" % (GHS, exp_paye0, GHS, exp_paye1, GHS, round(exp_paye1 - exp_paye0, 2)))
exp_ded = round(ssnit + exp_paye1 + bonus_tax, 2)
exp_gross = round(e["gross"] + BONUS, 2)
exp_net = round(exp_gross - exp_ded, 2)
print("  so the payslip must read: gross %s%.2f | deductions %s%.2f | net %s%.2f" % (GHS, exp_gross, GHS, exp_ded, GHS, exp_net))

print("\n== Add the bonus through Payroll > run > One-off earning ==")
tok = p.token(run_html, r"/payroll/payslips/%d/lines" % e["slip"])
code, run_after = add_bonus(e["slip"], BONUS, tok, "/payroll/runs/3")
print("  POST /payroll/payslips/%d/lines component=BONUS -> %s" % (e["slip"], code))
for m in p.flash(run_after)[:2]:
    print("  flash: %s" % m)
after = rows(run_after)[emp]
slip_html1 = p.get("/payroll/payslips/%d" % e["slip"])
lines1, tot1, net1, basis1 = slip_lines(slip_html1)
print("  payslip lines: %s" % ", ".join("%s %s%.2f" % (n, GHS, v) for n, v in lines1))
print("  payslip: gross %s%.2f | deductions %s%.2f | net %s%.2f" % (GHS, tot1.get("Gross earnings", 0), GHS, tot1.get("Total deductions", 0), GHS, net1))
print("  PAYE basis after: %s" % basis1)
ok = abs(after["gross"] - exp_gross) < 0.01 and abs(after["deductions"] - exp_ded) < 0.01 and abs(after["net"] - exp_net) < 0.01
print("  run row for %s: gross %s%.2f | deductions %s%.2f | net %s%.2f  => %s"
      % (emp, GHS, after["gross"], GHS, after["deductions"], GHS, after["net"], "MATCHES the rule" if ok else "DOES NOT MATCH"))
print("  bonus note printed on the payslip: %s" % ("yes" if "Bonus / ex-gratia payment (GRA)" in slip_html1 else "NO"))

print("\n== Recalculate the run — an adjustment must survive ==")
tok = p.token(run_after, r"/payroll/runs/%d/recalculate" % rid)
code, body, loc = p.post("/payroll/runs/%d/recalculate" % rid, {}, tok, referer="/payroll/runs/3")
recalc = p.get(rel(loc) or "/payroll/runs/3")
r2 = rows(recalc)[emp]
print("  POST recalculate -> %s; %s gross %s%.2f | deductions %s%.2f | net %s%.2f  => %s"
      % (code, emp, GHS, r2["gross"], GHS, r2["deductions"], GHS, r2["net"],
         "bonus and its tax survived" if abs(r2["net"] - exp_net) < 0.01 else "LOST"))
for m in re.finditer(r'badge-slate">([A-Z0-9_]+)</span>[^<]*</td><td class="num small muted">(\d+)</td><td class="num">([^<]+)</td>', recalc):
    if m.group(1) in ("BONUS", "BONUS_TAX", "PAYE"):
        print("    run summary %-10s %s slip(s)  %s %s" % (m.group(1), m.group(2), GHS, m.group(3).strip()))

print("\n== The GRA return (a locked run builds rows; a draft does not) ==")
st = p.get("/payroll/statutory?tab=paye&period=2026-07")
print("  GET /payroll/statutory?tab=paye&period=2026-07 -> %s, columns: %s"
      % (p.last_code, "Bonus + Bonus tax present" if ">Bonus</th>" in st and ">Bonus tax</th>" in st else "MISSING"))

print("\n== Remove it again: the payslip must come back exactly ==")
man = rows(recalc).get(emp, {}).get("manual", [])
line_id = man[0][2] if man else None
tok = p.token(recalc, r"/payroll/lines/%s" % line_id)
code, body, loc = p.post("/payroll/lines/%s" % line_id, {"_method": "DELETE", "_token": tok}, tok, referer="/payroll/runs/3")
removed = p.get(rel(loc) or "/payroll/runs/3")
r3 = rows(removed)[emp]
print("  DELETE /payroll/lines/%s -> %s; %s gross %s%.2f | deductions %s%.2f | net %s%.2f  => %s"
      % (line_id, code, emp, GHS, r3["gross"], GHS, r3["deductions"], GHS, r3["net"],
         "back to the original" if abs(r3["net"] - e["net"]) < 0.01 and abs(r3["gross"] - e["gross"]) < 0.01 else "DIFFERENT"))

print("\n== Put the demo bonus back so the preview shows the rule working ==")
tok = p.token(removed, r"/payroll/payslips/%d/lines" % e["slip"])
code, final_html = add_bonus(e["slip"], BONUS, tok, "/payroll/runs/3")
fin = rows(final_html)[emp]
print("  %s now: gross %s%.2f | deductions %s%.2f | net %s%.2f (payslip /payroll/payslips/%d)"
      % (emp, GHS, fin["gross"], GHS, fin["deductions"], GHS, fin["net"], e["slip"]))
t = totals = re.findall(r'<div class="l">([^<]+)</div><div class="v[^"]*">([^<]+)</div>', final_html)
print("  run totals: %s" % " | ".join("%s %s" % (a.strip(), b.strip()) for a, b in t[:4]))
