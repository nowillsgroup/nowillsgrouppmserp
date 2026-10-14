#!/usr/bin/env python3
"""v7.52 — per-property VAT registration, driven through the screens a manager would use.

Act 1151 made registration depend on what a property supplies: services must register whatever they turn over, goods
register at GH₵750,000 (raised from GH₵200,000), and e-invoicing is mandatory for every registered business. This walks
the real forms — Administration › Settings holds the record, Billing › GRA VAT › Settings runs the test, Billing › GRA VAT
› E-VAT refuses to submit while the property may not — and puts the record back exactly as it started.

    python3 deploy/dev/audit/vat_registration_flow.py
"""
import re
import sys

sys.path.insert(0, "deploy/dev/audit")
from flows_lib import RESULTS, payload, record, submit                     # noqa: E402
from pmshttp import Pms                                                    # noqa: E402

p = Pms()
MOD = "vat-reg"
ACT = r"^/settings$"          # the property profile form; /settings/properties and /settings/backup are other forms
SETTINGS, EVAT, VATSET, LOG = "/settings", "/vat/e-vat", "/vat/settings", "/activity-log"
SHIPPED = {"vat_registered": "1", "vat_registered_at": "", "vat_supply_kind": "services", "supports_e_invoicing": "1"}


def check(what, got, want):
    record(MOD, what, got == want, "%r" % (got,) if got != want else "%s" % (want,))
    return got == want


def save(overrides):
    """Post the WHOLE property form with these fields changed.

    A partial post would blank every other setting, because the form writes each key it is given — and an
    unticked checkbox must stay absent, or posting it switches on whatever the page renders as off.
    """
    html = p.get(SETTINGS)
    data, _ = payload(html, ACT, ticked_only=True)
    data.pop("_token", None)
    data.pop("logo", None)                                    # a file input, never a string
    for k, v in list(overrides.items()):
        box = re.search(r'<input[^>]*name="%s"[^>]*>' % re.escape(k), html)
        is_box = bool(box and re.search(r'type="(?:checkbox|radio)"', box.group(0)))
        if v == "" and is_box:
            data.pop(k, None)                                 # an unticked box is absent, not empty
        else:
            data[k] = v                                       # a cleared text or date field posts 
    flashes, detail = submit(p, SETTINGS, data, action_pattern=ACT, read=SETTINGS)
    # a rejected save re-renders the form, so a page echoing the new value proves nothing: check it landed
    check("  the save landed on the property page", p.status(SETTINGS)[0], 200)
    return flashes, detail


def ticked():
    """Every settings[] box the property form renders as ticked — the round trip must not move one."""
    html = p.get(SETTINGS)
    return sorted(m.group(1) for m in re.finditer(r'<input[^>]*name="settings\[([^\]]+)\]"[^>]*checked', html))


def checked(name):
    m = re.search(r'<input[^>]*name="%s"[^>]*>' % re.escape(name), p.get(SETTINGS))
    return bool(m and re.search(r"\bchecked\b", m.group(0)))


def selected(name):
    m = re.search(r'<select[^>]*name="%s"[^>]*>(.*?)</select>' % re.escape(name), p.get(SETTINGS), re.S)
    if not m:
        return None
    o = re.search(r'<option[^>]*value="([^"]*)"[^>]*selected', m.group(1)) or \
        re.search(r'<option[^>]*selected[^>]*value="([^"]*)"', m.group(1))
    return o.group(1) if o else ""


def value(name):
    m = re.search(r'<input[^>]*name="%s"[^>]*value="([^"]*)"' % re.escape(name), p.get(SETTINGS))
    return m.group(1) if m else None


def vat_card():
    """The registration card on the VAT settings page, as text."""
    html = p.get(VATSET)
    m = re.search(r'<li><span>VAT registration</span>(.*?)</div></div>', html, re.S)
    return re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", m.group(1))).strip() if m else ""


def submit_controls():
    """How many ways the page offers to send something to the VSDC."""
    html = p.get(EVAT)
    return len(re.findall(r'action="[^"]*/vat/documents/\d+/e-vat"', html)) + html.count('id="retryAll"')


def blocked():
    html = p.get(EVAT)
    m = re.search(r'id="evatBlocked"(.*?)</div>\s*</div>', html, re.S)
    return re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", m.group(1))).strip() if m else None


def restore_shipped():
    save(dict(SHIPPED))


# ── 0. the shipped record ──────────────────────────────────────────────────────
# an earlier run may have left the property off the register, so start from the shipped record rather than assuming it
if not checked("vat_registered") or selected("vat_supply_kind") != "services" or not checked("supports_e_invoicing"):
    print("  (resetting the property to its shipped registration record first)")
    restore_shipped()
html = p.get(SETTINGS)
TICKED_AT_START = ticked()
check("the property form carries the registration record",
      all('name="%s"' % k in html for k in SHIPPED), True)
check("…and it starts registered, supplying services", [checked("vat_registered"), selected("vat_supply_kind")], [True, "services"])
# the card holds the record and the test; the Act it applies is named in the note underneath it
vs = p.get(VATSET)
check("the VAT settings page runs the test", "no date recorded" in vat_card() and "Act 1151" in vs, True)
check("…and the E-VAT page offers submission", submit_controls() > 0, True)
check("…with nothing blocking it", blocked(), None)

# ── 1. take the property off the register ──────────────────────────────────────
flashes, detail = save({"vat_registered": "", "vat_registered_at": "2019-04-01"})
check("saving without the register ticked is accepted", flashes and not any("fix" in f.lower() for f in flashes), True)
record(MOD, "  %s" % detail[:60], True, " / ".join(flashes or [])[:110])
check("the checkbox comes back unticked", checked("vat_registered"), False)
check("…and the registration date is cleared with it", value("vat_registered_at"), "")
b = blocked()
check("the E-VAT page now says it is not submitting", bool(b and "not on the GRA VAT register" in b), True)
check("…and offers no way to submit", submit_controls(), 0)
card = vat_card()
check("the test calls it Must register", "Must register" in card, True)
check("…because services have no threshold", "no registration threshold for services" in card, True)
test = re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", re.search(r'id="vatTest"(.*?)</div>\s*</div>', p.get(VATSET), re.S).group(1)))
check("…and it quotes the turnover it measured", "GH₵" in test and "taxable supplies has been recorded" in test, True)
check("the change is in the audit trail", "NOT VAT-registered" in p.get(LOG), True)

# ── 2. the same property as a supplier of goods ────────────────────────────────
save({"vat_registered": "", "vat_supply_kind": "goods"})
card = vat_card()
check("a goods supplier is measured against GH₵ 750,000", "750,000" in card, True)
check("…and told how far through it is", bool(re.search(r"\b\d+(\.\d+)?% ", card)), True)
check("the supply kind is remembered on the form", selected("vat_supply_kind"), "goods")

# ── 3. registered, but with no certified invoicing system ─────────────────────
save({"vat_registered": "1", "vat_registered_at": "2019-04-01", "vat_supply_kind": "services", "supports_e_invoicing": ""})
check("a registered property with no CIS is blocked too",
      bool((blocked() or "").find("not marked as able to e-invoice") >= 0), True)
check("…and the test says a registered business has to e-invoice", "certified invoicing system" in vat_card(), True)

# ── 4. put it back on the register ────────────────────────────────────────────
save({"vat_registered": "1", "vat_registered_at": "2019-04-01", "supports_e_invoicing": "1"})
check("registering clears the block", blocked(), None)
check("…and submission is offered again", submit_controls() > 0, True)
card = vat_card()
check("the card shows the registered status", "VAT registered" in card and "Must register" not in card, True)
check("…with the date GRA registered it", "since 01 Apr 2019" in card, True)
check("the date is on the property form", value("vat_registered_at"), "2019-04-01")
check("…and that change is in the audit trail", "Recorded as VAT-registered" in p.get(LOG), True)

# ── 5. back to exactly how it shipped ─────────────────────────────────────────
restore_shipped()
check("the record is back as it shipped",
      [checked("vat_registered"), value("vat_registered_at"), selected("vat_supply_kind"), checked("supports_e_invoicing")],
      [True, "", "services", True])
check("…and the VAT page agrees", "no date recorded" in vat_card() and "Must register" not in vat_card(), True)
check("…and nothing is blocked", blocked(), None)
for u in (SETTINGS, EVAT, VATSET, "/vat", "/vat/credit-notes", "/vat/return"):
    record(MOD, "  %s still renders" % u, p.status(u)[0] == 200, p.status(u)[0])

check("the round trip moved no other setting on the form", ticked(), TICKED_AT_START)

passed = sum(1 for r in RESULTS if r["ok"])
fails = [r["flow"] for r in RESULTS if not r["ok"]]
print("\n%d checks passed, %d failed%s" % (passed, len(fails), (": " + ", ".join(fails)) if fails else ""))
sys.exit(1 if fails else 0)
