#!/usr/bin/env python3
"""v7.46 — paternity leave through the real UI (Act 651 provides none, so the type is contractual).

Idempotent: any paternity leave this script recorded on an earlier pass is cancelled first, so the
balances and the attendance register are back where they started before the new request is made.

  1. asserts the PL type exists and what it is seeded as;
  2. records 9–13 November 2026 (five working days) for one employee and approves it in the same post;
  3. checks the balance falls by exactly those days and the other types do not move;
  4. checks the attendance register for the first day reads on leave, PL;
  5. cancels it again and checks the balance and the register come back.

Run:  python3 deploy/dev/audit/hr_paternity_flow.py [employee_id]
"""
import re
import sys

sys.path.insert(0, "deploy/dev/audit")
from pmshttp import Pms  # noqa: E402

p = Pms()
EMP = sys.argv[1] if len(sys.argv) > 1 else "3"
FROM, TO = "2026-11-09", "2026-11-13"
DAYS = 5.0


def rel(loc):
    return re.sub(r"^https?://[^/]+", "", loc or "")


def types(html):
    """code -> (name, id) from the Record leave modal's type select."""
    return {c: (n, i) for i, n, c in re.findall(r'<option value="(\d+)"[^>]*>([^<]+) \(([A-Z]+)\)', html)}


def balances(html, name):
    """That employee's balances row: type name -> (available, entitled).

    An unpaid type renders no numbers at all, so the cells are paired with the headers positionally
    rather than by matching the paid-cell pattern, which would shift every column after it.
    """
    row = re.search(r'<tr><td class="bold">%s.*?</tr>' % re.escape(name), html, re.S)
    if not row:
        return {}
    heads = re.findall(r'<th class="num" title="([^"]+)">', html)
    cells = re.findall(r'<td class="num">(.*?)</td>', row.group(0), re.S)
    out = {}
    for head, cell in zip(heads, cells):
        avail = re.search(r'<b[^>]*>([\d.]+)</b>', cell)
        ent = re.search(r'of ([\d.]+)', cell)
        out[head] = (float(avail.group(1)) if avail else None, float(ent.group(1)) if ent else None)
    return out


def pl_balance(html, name):
    for k, v in balances(html, name).items():
        if k.startswith("Paternity") and v[0] is not None:
            return v
    return None, None


def register(html, name):
    row = re.search(r"%s.*?</tr>" % re.escape(name), html, re.S)
    return re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", row.group(0))).strip()[:150] if row else "no row"


leave = p.get("/hr/leave")
tt = types(leave)
name = re.search(r'<option value="%s"[^>]*>([^<]+)</option>' % EMP, leave)
emp_name = name.group(1) if name else "?"
print("== the leave type ==")
if "PL" not in tt:
    print("  PL is missing — run PayrollService::ensureDefaults() (it seeds PL on an existing installation)")
    sys.exit(1)
print("  PL = %s (leave type id %s)" % (tt["PL"][0], tt["PL"][1]))
print("  statutory versus contractual note on the page: %s"
      % ("yes" if "no statutory paternity leave" in leave else "NO"))

bal = p.get("/hr/leave?tab=balances")
before = balances(bal, emp_name)
print("\n== %s (%s) before ==" % (emp_name, "EMP" + EMP.zfill(3)))
print("  %s" % ", ".join("%s %s" % (k.split(" (")[0][:22], "unpaid" if v[0] is None else "%s of %s" % v) for k, v in before.items()))

# cancel anything an earlier pass left behind, so the balances start clean
old = re.findall(r'<tr data-id="(\d+)"><td class="bold">%s(?:(?!</tr>).)*?Paternity' % re.escape(emp_name),
                 p.get("/hr/leave?status=all"), re.S)
for lid in old:
    h = p.get("/hr/leave?status=all")
    code, _, loc = p.post("/hr/leave/%s/cancel" % lid, {"reason": "re-run of the paternity flow"},
                          p.token(h, r"/hr/leave/%s/cancel" % lid), referer="/hr/leave?status=all")
    print("  cancelled the earlier request %s -> %s" % (lid, code))
    bal = p.get("/hr/leave?tab=balances")

print("\n== record and approve %s to %s (%d working days) ==" % (FROM, TO, DAYS))
leave = p.get("/hr/leave")
code, _, loc = p.post("/hr/leave", {"employee_id": EMP, "leave_type_id": tt["PL"][1], "date_from": FROM,
                                    "date_to": TO, "reason": "Birth of a child", "approve": "1"},
                      p.token(leave, r'/hr/leave"'), referer="/hr/leave")
after = p.get(rel(loc) or "/hr/leave")
print("  POST /hr/leave -> %s" % code)
for m in p.flash(after)[:1]:
    print("  flash: %s" % m)
b2 = pl_balance(p.get("/hr/leave?tab=balances"), emp_name)
b1 = pl_balance(bal, emp_name)
print("  PL balance %s of %s -> %s of %s  => %s" % (b1[0], b1[1], b2[0], b2[1],
      "fell by exactly %.1f day(s)" % DAYS if abs(b1[0] - b2[0] - DAYS) < 0.01 else "WRONG"))
rest = {k: v for k, v in balances(p.get("/hr/leave?tab=balances"), emp_name).items() if not k.startswith("Paternity")}
print("  the other types: %s" % ("unchanged" if rest == {k: v for k, v in before.items() if not k.startswith("Paternity")} else "MOVED %s" % rest))
print("  register %s: %s" % (FROM, register(p.get("/hr/attendance?date=" + FROM), emp_name)))
appr = p.get("/hr/leave?status=approved")
print("  listed under approved leave: %s" % ("yes" if re.search(r'%s(?:(?!</tr>).)*?Paternity' % re.escape(emp_name), appr, re.S) else "NO"))

print("\n== cancel it again: the balance and the register must come back ==")
lid = re.findall(r'<tr data-id="(\d+)"><td class="bold">%s(?:(?!</tr>).)*?Paternity' % re.escape(emp_name),
                 p.get("/hr/leave?status=all"), re.S)
if lid:
    h = p.get("/hr/leave?status=all")
    code, _, loc = p.post("/hr/leave/%s/cancel" % lid[0], {"reason": "flow check"},
                          p.token(h, r"/hr/leave/%s/cancel" % lid[0]), referer="/hr/leave?status=all")
    print("  POST /hr/leave/%s/cancel -> %s" % (lid[0], code))
    b3 = pl_balance(p.get("/hr/leave?tab=balances"), emp_name)
    print("  PL balance back to %s of %s  => %s" % (b3[0], b3[1], "restored" if abs(b3[0] - b1[0]) < 0.01 else "NOT restored"))
    print("  register %s: %s" % (FROM, register(p.get("/hr/attendance?date=" + FROM), emp_name)))
else:
    print("  nothing to cancel")

# leave the demo in place so the preview shows an approved paternity leave
print("\n== put the demo back ==")
leave = p.get("/hr/leave")
code, _, loc = p.post("/hr/leave", {"employee_id": EMP, "leave_type_id": tt["PL"][1], "date_from": FROM,
                                    "date_to": TO, "reason": "Birth of a child", "approve": "1"},
                      p.token(leave, r'/hr/leave"'), referer="/hr/leave")
fin = p.get(rel(loc) or "/hr/leave")
b4 = pl_balance(p.get("/hr/leave?tab=balances"), emp_name)
print("  POST /hr/leave -> %s; PL balance %s of %s, register %s: %s"
      % (code, b4[0], b4[1], FROM, register(p.get("/hr/attendance?date=" + FROM), emp_name)))
