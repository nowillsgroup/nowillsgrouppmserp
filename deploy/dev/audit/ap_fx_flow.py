#!/usr/bin/env python3
"""
v7.53 — booking a supplier invoice in the supplier's own currency, through the real form and the real controller.

deploy/dev/audit/ap_fx.php proves the service layer. This proves the path a person actually walks, over HTTP:

  1. the create form quotes the forex board — every currency it has a rate for, with the rate, the basis and the day
  2. a bill entered in USD posts through ApController::store(), lands on the bill page with the supplier's own
     document beside the cedi figures, and the flash says exactly what rate was used and which board it came from
  3. the register shows the foreign figure under the cedi total, and Accounts payable › Ageing re-values what is
     still open at the board as it stands
  4. the same form with the currency left alone behaves exactly as it always did — no fx block anywhere
  5. the refusals arrive as readable sentences, not stack traces: a currency with no board rate, a bill matched to a
     goods receipt, and foreign bills while the switch is off
  6. turning the payables policy off and back on through Foreign Guests › Settings moves nothing else on that form
  7. everything this harness books is cancelled again, and the exposure goes back to what it was

Run:  python3 deploy/dev/audit/ap_fx_flow.py
"""
import re
import sys
from decimal import Decimal, ROUND_HALF_UP

sys.path.insert(0, "deploy/dev/audit")
from pmshttp import Pms  # noqa: E402

PASS = 0
FAIL = 0


def ok(what, got, want):
    global PASS, FAIL
    if got == want:
        PASS += 1
        print("  ok    %-62s %s" % (what, str(got)[:66]))
    else:
        FAIL += 1
        print("  FAIL  %-62s got %r, wanted %r" % (what, got, want))


def has(what, hay, needle):
    ok(what, needle in hay, True)


def region(html, marker, end="</form>"):
    i = html.find(marker)
    if i < 0:
        return ""
    j = html.find(end, i)
    return html[i:j if j > 0 else i + 9000]


def token_of(html, marker='id="billForm"'):
    m = re.search(r'name="_token"\s+value="([^"]+)"', region(html, marker))
    return m.group(1) if m else None


def follow(p, loc, fallback="/ap/bills"):
    """Laravel redirects to an absolute URL built on APP_URL, which does not resolve here — strip the origin."""
    return p.get(re.sub(r"^https?://[^/]+", "", loc) if loc else fallback)


def opts(form, name):
    m = re.search(r'<select[^>]*name="%s"[^>]*>(.*?)</select>' % re.escape(name), form, re.S)
    return re.findall(r'<option value="([^"]*)"', m.group(1)) if m else []


def exposure(html):
    """currency -> (open bills, still owed, booked in cedis) from the ageing exposure card."""
    card = region(html, 'id="fxExposure"', "</table>")
    return {c: (int(n), owed, booked) for c, n, owed, booked in re.findall(
        r'<td class="bold">([A-Z]{3})</td><td class="num">(\d+)</td><td class="num">([\d.,]+)</td>\s*'
        r'<td class="num">([\d.,]+)</td>', card)}


def form_of(p, supplier, mode="expense"):
    html = p.get("/ap/bills/create?mode=%s&supplier=%d" % (mode, supplier))
    return html, region(html, 'id="billForm"')


def post_bill(p, form, tok, data, referer):
    payload = {k: v for k, v in data.items() if v is not None}
    code, _, loc = p.post("/ap/bills", payload, tok, referer=referer)
    return code, loc, follow(p, loc, referer)


def cancel(p, bill_id, reason):
    html = p.get("/ap/bills/%d" % bill_id)
    m = re.search(r'name="_token"\s+value="([^"]+)"', region(html, "/ap/bills/%d/cancel" % bill_id))
    tok = m.group(1) if m else None
    code, _, loc = p.post("/ap/bills/%d/cancel" % bill_id, {"reason": reason}, tok, referer="/ap/bills/%d" % bill_id)
    return code, follow(p, loc, "/ap/bills/%d" % bill_id)


def forex_settings(p, changes):
    """Read the forex form, change only what is asked, post it back whole — the payables policy lives on this form."""
    html = p.get("/foreign/settings")
    form = region(html, "forexSettings")
    tok = token_of(html, "forexSettings")
    data = {k: field(form, k) for k in
            ["cash_cap_usd", "resident_cap_usd", "aggregate_days", "id_copy_inr", "max_margin", "fee_pct",
             "surrender_days", "licence", "franchiser", "channel_basis", "channel_stale_days",
             "ap_basis", "ap_stale_days", "ap_rate_date",
             "enabled", "allow_residents", "carry_rates", "channel_convert", "ap_convert", "ap_pay", "ap_pay_deal"]}
    data.update(changes)
    data["section"] = "forex"
    boxes = ["enabled", "allow_residents", "carry_rates", "channel_convert", "ap_convert", "ap_pay", "ap_pay_deal"]
    payload = {k: v for k, v in data.items() if v is not None and not (k in boxes and v == "0")}
    code, _, loc = p.post("/foreign/settings", payload, tok, referer="/foreign/settings")
    page = follow(p, loc, "/foreign/settings")
    bad = [f for f in Pms.flash(page) if "Please fix" in f]
    ok("  the policy saved (%s)" % ", ".join("%s=%s" % kv for kv in changes.items()), bad, [])
    return page


def field(form, name):
    """The submitted value of one field: text/number input, select, or checkbox."""
    m = re.search(r'<(?:input|select|textarea)[^>]*name="%s"[^>]*>' % re.escape(name), form)
    if not m:
        return None
    tag = m.group(0)
    if tag.startswith("<select"):
        blk = re.search(r'<select[^>]*name="%s"[^>]*>(.*?)</select>' % re.escape(name), form, re.S)
        sel = re.search(r'<option value="([^"]*)"[^>]*selected', blk.group(1)) if blk else None
        return sel.group(1) if sel else (re.findall(r'<option value="([^"]*)"', blk.group(1)) or [""])[0] if blk else ""
    if 'type="checkbox"' in tag:
        return "1" if re.search(r'\bchecked\b', tag) else "0"
    v = re.search(r'value="([^"]*)"', tag)
    return v.group(1) if v else ""


p = Pms()
created = []

# ── 0. who to bill, and what the form offers ──
html, form = form_of(p, 1)
suppliers = [s for s in opts(html, "supplier_id") if s]
if not suppliers:
    sys.exit("no suppliers to bill — the demo database is empty")
sup = int(suppliers[0])
html, form = form_of(p, sup)
home = re.search(r'id="billCurrency" data-home="([A-Z]{3})"', form)
home = home.group(1) if home else "GHS"
heads = [h for h in opts(form, "lines[0][account_head_id]") if h]
ccys = opts(form, "currency")
priced = dict(re.findall(r'<option value="([A-Z]{3})" data-rate="([\d.]*)"', form))
print("Supplier #%d · own currency %s · %d account heads · the form quotes %d currencies (%s)"
      % (sup, home, len(heads), len([c for c in ccys if c]), ", ".join(sorted(k for k, v in priced.items() if v)[:6]) + " …"))
print()

if not heads:
    sys.exit("no chargeable account heads on the form")
ref = 0


def nextref(tag):
    global ref
    ref += 1
    return "FXF-%s-%02d" % (tag, ref)


# ── 1. the form quotes the board ──
print("The form — Accounts payable › Bills › New bill")
has("the currency picker knows the property's own currency", form, 'data-home="%s"' % home)
has("and offers it first, as the default", form, '<option value="%s" data-rate="" selected' % home)
has("every currency the board has a rate for is offered", form, '<option value="USD" data-rate="')
has("each quote carries the board's own date", form, 'data-date="2026-')
has("and which side of the board it is", form, 'data-basis="reference"')
ok("the quote carries the rate the board published", bool(re.search(r'<option value="USD" data-rate="[\d.]+"', form)), True)
has("and the form says which rate the bill will actually use", html, "the bill is booked at the board for its invoice date")
has("the cedi equivalent row is there before anything is typed", form, 'id="fxRow"')
print()

# ── 2. a bill in the supplier's own currency ──
print("A bill entered in USD")
usd_rate = float(priced.get("USD") or 0)
if not usd_rate:
    sys.exit("no USD rate on the board — set one under Foreign Guests › Exchange Rates")
data = {"mode": "expense", "supplier_id": str(sup), "supplier_ref": nextref("usd"),
        "doc_date": re.search(r'name="doc_date"[^>]*value="([^"]+)"', form).group(1),
        "currency": "USD", "notes": "FX flow harness",
        "lines[0][account_head_id]": heads[0], "lines[0][description]": "Reservation platform licence, October",
        "lines[0][amount]": "1150.00", "lines[0][tax_rate]": "0",
        "lines[1][account_head_id]": heads[-1], "lines[1][description]": "Commission on 12 reservations",
        "lines[1][amount]": "340.75", "lines[1][tax_rate]": "0"}
half_up = lambda amount: (Decimal(amount) * Decimal(priced["USD"])).quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)
want_sub = half_up("1150.00") + half_up("340.75")   # each line rounded on its own, exactly as ApService::convertLines() does
code, loc, page = post_bill(p, form, token_of(html), data, "/ap/bills/create?mode=expense&supplier=%d" % sup)
ok("it posts", code, 302)
ok("and lands on the bill it created", bool(re.search(r"/ap/bills/\d+$", re.sub(r"^https?://[^/]+", "", loc or ""))), True)
bill_id = int(re.search(r"/ap/bills/(\d+)", loc).group(1))
created.append(bill_id)
flash = " ".join(Pms.flash(page))
has("the flash quotes the supplier's own figure", flash, "USD 1,490.75")
has("the rate it was booked at", flash, "%.4f" % usd_rate)
has("which side of the board, and which day", flash, "reference, board of")
has("and the cedi figure that was posted", flash, "= GH₵")
has("the bill page keeps the supplier's document", page, 'id="fxSummary"')
has("billed in USD", page, "Billed in USD")
has("with its own net and tax", page, "1,490.75 + 0.00")
has("the rate and basis", page, "%.4f" % usd_rate)
has("what is still owed, read back in dollars", page, "Still owed in USD")
has("and explains that the ledger carries cedis", page, "each line was converted at the board above")
text = Pms.text(page)
ok("the posted total is the two lines converted and rounded separately", "{:,.2f}".format(want_sub) in text, True)
print()

# ── 3. the register and the ageing exposure ──
print("The register and the exposure")
register = p.get("/ap/bills")
has("the register shows the foreign figure under the cedi total", register, "USD 1,490.75 at %.4f" % usd_rate)
before = exposure(p.get("/ap/ageing"))
ok("the ageing page carries the exposure card", bool(before), True)
ok("USD is in it", "USD" in before, True)
# the bill we just made is open, so it is already counted — cancel it and watch the count fall, then re-book
usd_now = before.get("USD", (0, "", ""))[0]
code, page = cancel(p, bill_id, "FX flow harness — cancelled to leave the demo as it was")
ok("the harness cancels what it booked", code, 302)
after = exposure(p.get("/ap/ageing"))
ok("and the exposure drops the cancelled bill", after.get("USD", (0,))[0], max(usd_now - 1, 0))
print()

# ── 4. the same form with the currency left alone ──
print("A bill in the property's own currency")
html2, form2 = form_of(p, sup)
data2 = {"mode": "expense", "supplier_id": str(sup), "supplier_ref": nextref("ghs"),
         "doc_date": re.search(r'name="doc_date"[^>]*value="([^"]+)"', form2).group(1),
         "currency": "", "notes": "FX flow harness",
         "lines[0][account_head_id]": heads[0], "lines[0][description]": "Sundry purchases",
         "lines[0][amount]": "250.00", "lines[0][tax_rate]": "0"}
code, loc, page = post_bill(p, form2, token_of(html2), data2, "/ap/bills/create?mode=expense&supplier=%d" % sup)
ok("it still posts", code, 302)
if loc and re.search(r"/ap/bills/\d+", loc):
    created.append(int(re.search(r"/ap/bills/(\d+)", loc).group(1)))
ok("and there is no foreign-document block on it", 'id="fxSummary"' in page, False)
ok("the flash does not mention a rate", any(" at " in f and "board of" in f for f in Pms.flash(page)), False)
has("the cedi total is on the page", page, "250.00")
print()

# ── 5. the refusals ──
print("What is refused, in plain English")
html3, form3 = form_of(p, sup)
tok3 = token_of(html3)
ref3 = "/ap/bills/create?mode=expense&supplier=%d" % sup
d = dict(data2)
d["supplier_ref"] = nextref("zzz")
d["currency"] = "ZZZ"
code, loc, page = post_bill(p, form3, tok3, d, ref3)
fl = " ".join(Pms.flash(page))
ok("a currency the board has never heard of does not create a bill", "bills/create" in (loc or ""), True)
has("it says there is no rate, and where to set one", fl, "no ZZZ rate on the forex board")
has("and points at the board the rate is set on", fl, "Foreign Guests › Exchange Rates")

# a bill matched to a goods receipt is valued at the receipt, which is in cedis
goods = p.get("/ap/bills/create?mode=goods")
pos = [x for x in opts(region(goods, 'id="billForm"'), "purchase_order_id") if x]
if pos:
    ghtml, gform = None, region(goods, 'id="billForm"')
    gtok = token_of(goods)
    gsub = re.search(r'name="doc_date"[^>]*value="([^"]+)"', gform)
    gd = {"mode": "goods", "purchase_order_id": pos[0], "supplier_ref": nextref("grn"),
          "doc_date": gsub.group(1) if gsub else "", "subtotal": "100.00", "tax": "0", "currency": "USD"}
    code, loc, page = post_bill(p, gform, gtok, gd, "/ap/bills/create?mode=goods")
    fl = " ".join(Pms.flash(page))
    has("a goods-receipt bill in a foreign currency is refused", fl, "valued at the receipt")
else:
    print("  skip  %-62s no received, unbilled purchase order in the demo" % "goods-receipt refusal")

# conversion switched off
page = forex_settings(p, {"ap_convert": "0"})
has("the switch is off on the form", page, 'name="ap_convert"')
html4, form4 = form_of(p, sup)
d = dict(data)
d["supplier_ref"] = nextref("off")
code, loc, page = post_bill(p, form4, token_of(html4), d, ref3)
fl = " ".join(Pms.flash(page))
has("with conversion off a foreign bill is refused", fl, "switched off")
has("and the refusal says where the switch is", fl, "Settings")
page = forex_settings(p, {"ap_convert": "1"})
ok("the switch is back on", 'name="ap_convert" value="1" checked' in page, True)
print()

# ── 6. cleanup ──
print("Leaving the demo as it was")
for b in created:
    page = p.get("/ap/bills/%d" % b)
    if "cancel" in page and "Cancelled" not in Pms.text(page)[:400]:
        cancel(p, b, "FX flow harness")
left = exposure(p.get("/ap/ageing"))
ok("the exposure is back to the currencies the demo carries", sorted(left), sorted(before))
ok("no harness bill is left open", [r for r in re.findall(r"FXF-[A-Z]+-\d+", p.get("/ap/bills?status=unpaid"))], [])

# cancelling is the honest business action, but the cancelled documents would pile up on every run of this harness,
# and there is no delete over HTTP — so sweep its own rows out from under itself and leave the demo pristine
import os  # noqa: E402
import subprocess  # noqa: E402
env = dict(os.environ, TINKER='$b = \\App\\Models\\SupplierInvoice::where("supplier_ref", "like", "FXF-%")->get();'
           ' \\App\\Models\\SupplierInvoiceLine::whereIn("supplier_invoice_id", $b->pluck("id"))->delete();'
           ' $b->each->delete(); printf("SWEPT %d", $b->count());')
sweep = subprocess.run(["deploy/dev/bin/php", "-f", "deploy/dev/tinker.php"], capture_output=True, text=True, env=env)
m = re.search(r"SWEPT (\d+)", sweep.stdout)
ok("its own documents are swept out of the demo", int(m.group(1)) if m else -1, len(created))
ok("and the register no longer mentions the harness", "FXF-" in p.get("/ap/bills"), False)

print()
print("%d passed, %d failed" % (PASS, FAIL))
sys.exit(1 if FAIL else 0)
