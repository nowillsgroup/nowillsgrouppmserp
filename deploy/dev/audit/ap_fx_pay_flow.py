#!/usr/bin/env python3
"""
v7.54 — settle a supplier bill in the supplier's own currency through the real payment form and controller.

ap_fx_pay.php proves the service and ledger. This is the path the AP clerk walks over HTTP:

  * choose a supplier with a live EUR or USD bill; the payment form only offers currencies that supplier is actually owed
  * fetch the board quote for the selected payment date (not for the invoice date and not for today's date by accident)
  * use the bill's booked GHS amount and the WHT quote to work out the net foreign amount that the bank actually transfers
  * submit the payment through ApController::payStore(); see the booked amount, the rate, the payment date and the
    realised exchange difference on the saved payment, on the register, in the supplier statement and on the remittance
  * see realised gains/losses beside the still-open unrealised exposure
  * cancel the payment through the ordinary cancellation path, prove the bill is open again, then sweep this harness's
    payment, allocation, activity log and any GL journals out of the disposable demo database

Run: python3 deploy/dev/audit/ap_fx_pay_flow.py
"""
import html
import os
import re
import subprocess
import sys
import time
import urllib.parse
from decimal import Decimal, ROUND_HALF_UP

sys.path.insert(0, "deploy/dev/audit")
from pmshttp import Pms  # noqa: E402

PASS = 0
FAIL = 0


def ok(what, got, want):
    global PASS, FAIL
    if got == want:
        PASS += 1
        print("  ok    %-66s %s" % (what, str(got)[:72]))
    else:
        FAIL += 1
        print("  FAIL  %-66s got %r, wanted %r" % (what, got, want))


def has(what, hay, needle):
    ok(what, needle in hay, True)


def region(source, marker, end="</form>"):
    i = source.find(marker)
    if i < 0:
        return ""
    j = source.find(end, i)
    return source[i:j if j > 0 else i + 12000]


def follow(p, loc, fallback):
    return p.get(re.sub(r"^https?://[^/]+", "", loc) if loc else fallback)


def money_number(text):
    """A visible Ghana-money figure to cents, regardless of its thousands separator; dash means zero."""
    raw = re.sub(r"[^0-9.-]", "", html.unescape(text or ""))
    return Decimal(raw or "0").quantize(Decimal("0.01"))


def text_only(source):
    return html.unescape(re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", source))).strip()


def cells(row):
    return [text_only(c) for c in re.findall(r"<td\b[^>]*>(.*?)</td>", row, re.S)]


def exposure_rows(page):
    card = region(page, 'id="fxExposure"', "</table>")
    result = {}
    for tr in re.findall(r"<tr\b[^>]*>(.*?)</tr>", card, re.S):
        c = cells(tr)
        if len(c) >= 9 and re.fullmatch(r"[A-Z]{3}(?: settled)?", c[0]):
            code = c[0].split()[0]
            result[code] = {"bills": c[1], "booked": c[3], "unrealised": c[6], "foreign_paid": c[7], "realised": c[8]}
    return result


def selected(form, name):
    m = re.search(r'<select[^>]*name="%s"[^>]*>(.*?)</select>' % re.escape(name), form, re.S)
    if not m:
        return None
    tag = re.search(r'<option value="([^"]*)"[^>]*selected', m.group(1))
    return tag.group(1) if tag else None


def csrf(form):
    m = re.search(r'name="_token"\s+value="([^"]+)"', form)
    return m.group(1) if m else None


def query_url(path, values):
    return path + "?" + urllib.parse.urlencode(values, doseq=True)


p = Pms()
made = []
ref = "APFXP-HTTP-%d" % int(time.time())

# ── 0. pick an open foreign bill from the real register ──
register = p.get("/ap/bills")
fx_bill = None
supplier_name = None
for tr in re.findall(r"<tr\b[^>]*>.*?</tr>", register, re.S):
    if not re.search(r"\b(?:EUR|USD|GBP|CHF|CAD|AED|JPY|INR|NGN|XOF|ZAR|CNY) [\d,.]+ at [\d.]+", tr):
        continue
    link = re.search(r'href="(?:https?://[^/]+)?/ap/bills/(\d+)"', tr)
    row_cells = cells(tr)
    if link and len(row_cells) > 1:
        fx_bill = int(link.group(1))
        supplier_name = row_cells[1]
        break
if not fx_bill:
    sys.exit("no open foreign supplier bill on /ap/bills — run the demo seeder first")

pick = p.get("/ap/payments/create")
select = re.search(r'<select[^>]*name="supplier_id"[^>]*>(.*?)</select>', pick, re.S)
if not select:
    sys.exit("the supplier payment form did not render a supplier picker")
supplier_id = None
for sid, label in re.findall(r'<option value="(\d+)"[^>]*>(.*?)</option>', select.group(1), re.S):
    if supplier_name.casefold() in text_only(label).casefold():
        supplier_id = int(sid)
        break
if not supplier_id:
    sys.exit("could not match the foreign bill supplier to the payment form")

form_page = p.get("/ap/payments/create?supplier=%d" % supplier_id)
form = region(form_page, 'id="payForm"')
cur_tag = re.search(r'<select[^>]*id="payCurrency"[^>]*>', form)
home = re.search(r'data-home="([A-Z]{3})"', cur_tag.group(0)) if cur_tag else None
home = home.group(1) if home else "GHS"
ccy_tag = re.search(r'<option value="([A-Z]{3})"[^>]*data-rate="([\d.]*)"[^>]*data-owed="([\d.]*)"[^>]*>', form)
# The target bill may not be the first foreign currency option; find the row's currency and allocation instead.
open_bill = re.search(r'<tr data-out="([\d.]+)" data-ccy="([A-Z]{3})" data-fx-rate="([\d.]+)">(.*?)</tr>', form, re.S)
if not open_bill:
    sys.exit("the selected supplier has no payable foreign-currency bill on the payment form")
booked = Decimal(open_bill.group(1)).quantize(Decimal("0.01"))
code = open_bill.group(2)
booked_rate = Decimal(open_bill.group(3)).quantize(Decimal("0.0001"))
row = open_bill.group(4)
bill_link = re.search(r'href="(?:https?://[^/]+)?/ap/bills/(\d+)"', row)
if not bill_link or int(bill_link.group(1)) != fx_bill:
    # A supplier can have several bills; pay the first actual foreign row from the form and report its own id.
    bill_link = re.search(r'href="(?:https?://[^/]+)?/ap/bills/(\d+)"', row)
if not bill_link:
    sys.exit("could not match a foreign allocation row to its bill")
bill_id = int(bill_link.group(1))
ccy_options = re.findall(r'<option value="([A-Z]{3})"[^>]*data-rate="[\d.]+"', form)
ok("the form offers the foreign bill's currency, and not arbitrary currencies", code in ccy_options, True)
has("the rate field is only a board quote until the clerk opts into a deal", form, 'id="fxBoardRate"')
has("and the optional deal rate has an explicit checkbox", form, 'name="fx_deal"')
has("the selected payment date drives a real board lookup", form, 'data-rate-url="/ap/payments/fx-rate"')

# ── 1. quote the rate and the GRA withholding that really apply on the payment date ──
date_match = re.search(r'<input[^>]*name="date"[^>]*value="([^"]+)"', form)
pay_date = date_match.group(1) if date_match else ""
mode = selected(form, "payment_mode_id")
if not pay_date or not mode:
    sys.exit("the payment form did not supply its date and payment mode")
rate_path = query_url("/ap/payments/fx-rate", {"currency": code, "date": pay_date})
rate_body = p.get(rate_path)
try:
    quote = __import__("json").loads(rate_body)
except ValueError:
    sys.exit("the payment-date forex-rate endpoint did not return JSON")
if not quote.get("rate"):
    sys.exit("no %s board rate for payment date %s" % (code, pay_date))
rate = Decimal(str(quote["rate"])).quantize(Decimal("0.0001"))
ok("the API returns the board for the payment date, not the invoice date", quote["date"], pay_date)
ok("and it is explicitly a board rate, not a silently promoted deal", quote["basis"], "reference")

wht_path = query_url("/ap/wht/quote", {"supplier_id": supplier_id, "amount": str(booked), "date": pay_date,
                                           "alloc[%d]" % bill_id: str(booked)})
wht_body = p.get(wht_path)
try:
    wht = __import__("json").loads(wht_body)
except ValueError:
    sys.exit("the withholding-tax quote endpoint did not return JSON")
tax = (Decimal(str(wht.get("wht", 0))) + Decimal(str(wht.get("wvat", 0)))).quantize(Decimal("0.01"))
# The supplier's foreign transfer is net of GRA withholding; the tax liability itself remains in GHS.
foreign_gross = booked / booked_rate
fx_amount = max(Decimal("0.00"), foreign_gross - tax / rate).quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)
bank_ghs = (fx_amount * rate).quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)
expected_diff = (bank_ghs + tax - booked).quantize(Decimal("0.01"))
print("Supplier #%d %s · bill #%d · %s %s booked at %s · pay-date rate %s · GRA withholding %s · bank transfer %s · realised %s"
      % (supplier_id, supplier_name, bill_id, code, booked, booked_rate, rate, tax, fx_amount, expected_diff))

age_before = exposure_rows(p.get("/ap/ageing"))
realised_before = money_number(age_before.get(code, {}).get("realised", "0"))

# ── 2. post a foreign payment through the real controller ──
alloc_name = "alloc[%d]" % bill_id
payload = {"supplier_id": str(supplier_id), "date": pay_date, "payment_mode_id": mode, "currency": code,
           "fx_amount": str(fx_amount), "reference": ref, "notes": "APFXP HTTP harness" , alloc_name: str(booked)}
code_http, body, loc = p.post("/ap/payments", payload, csrf(form), referer="/ap/payments/create?supplier=%d" % supplier_id)
ok("the payment saves through ApController::payStore()", code_http, 302)
if not loc or not re.search(r"/ap/payments/\d+", loc):
    print("  FAIL  payment redirects to its new detail page: %r" % loc)
    FAIL += 1
    payment_id = None
    payment_page = body
else:
    payment_id = int(re.search(r"/ap/payments/(\d+)", loc).group(1))
    made.append(payment_id)
    payment_page = follow(p, loc, "/ap/payments/%d" % payment_id)

if payment_id:
    flash = " ".join(Pms.flash(payment_page))
    has("the confirmation names the currency the bank transferred", flash, "%s %s" % (code, format(fx_amount, ",.2f")))
    has("and the rate the board published for that date", flash, format(rate, ".4f"))
    has("with the board provenance, not a guessed rate", flash, "reference board of")
    has("and states the GHS transfer cost", flash, "= GH₵")
    has("and identifies the realised exchange result", flash, "realised exchange")
    has("the payment detail shows a foreign-settlement block", payment_page, 'id="fxPayPanel"')
    has("and the actual foreign amount", payment_page, "%s %s" % (code, format(fx_amount, ",.2f")))
    has("the posting rate", payment_page, format(rate, ".4f"))
    has("the booked AP amount stays the amount of the bill", payment_page, "what the bills were booked at")
    has("withholding tax is listed separately when present", payment_page, "Withholding tax" if tax > 0 else "Rate used")
    detail_text = Pms.text(payment_page)
    ok("the cedi bank debit is the foreign amount times the payment-date rate",
       format(bank_ghs, ",.2f") in detail_text, True)
    ok("the realised difference is computed after the GRA liability", format(abs(expected_diff), ",.2f") in detail_text, True)

    # ── 3. register, supplier statement and remittance ──
    register = p.get("/ap/payments")
    has("the payment register carries the foreign amount and rate", register,
        "%s %s @ %s" % (code, format(fx_amount, ",.2f"), format(rate, ".4f")))
    if expected_diff > 0:
        has("and labels the realised loss", register, "loss")
    elif expected_diff < 0:
        has("and labels the realised gain", register, "gain")
    else:
        has("and labels the zero exchange movement", register, "no exchange difference")
    statement = p.get("/ap/suppliers/%d/statement" % supplier_id)
    has("the supplier statement identifies the foreign payment", statement, "%s %s at %s" % (code, format(fx_amount, ",.2f"), format(rate, ".4f")))
    has("and keeps the exchange difference off the supplier's balance", statement, "ours, not a charge to you")
    remittance = p.get("/ap/payments/%d?print=1" % payment_id)
    has("the remittance advice is stated in the supplier's currency", remittance, "%s %s" % (code, format(fx_amount, ",.2f")))
    has("and separately states the GHS equivalent", remittance, "equivalent to GH₵")
    bill_page = p.get("/ap/bills/%d" % bill_id)
    has("the bill's allocation identifies the payment's own currency", bill_page, "%s %s @ %s" % (code, format(fx_amount, ",.2f"), format(rate, ".4f")))

    # Realised is distinct from the remaining open exposure. The new difference must move this currency only.
    age = p.get("/ap/ageing")
    row = exposure_rows(age).get(code)
    ok("the ageing card still reports this settled currency", row is not None, True)
    if row and row["bills"] == "0":
        has("and badges a fully settled currency instead of dropping its realised history", age, "settled")
    ok("and presents realised movement separately from unrealised", "Realised" in age and "Unrealised" in age, True)
    if row:
        got_realised = money_number(row["realised"])
        ok("the realised figure moved by the tax-aware exchange difference", got_realised - realised_before, expected_diff)

    # ── 4. cancel by the ordinary AP path and restore the original demo ──
    cancel_tok = Pms.token(payment_page, r"/ap/payments/%d/cancel" % payment_id)
    if not cancel_tok:
        ok("the payment detail has the normal cancellation form", False, True)
    else:
        cancel_code, _, cancel_loc = p.post("/ap/payments/%d/cancel" % payment_id,
            {"reason": "APFXP HTTP harness cleanup"}, cancel_tok, referer="/ap/payments/%d" % payment_id)
        ok("the ordinary AP cancellation releases the bill again", cancel_code, 302)
        after = p.get("/ap/bills/%d" % bill_id)
        after_text = Pms.text(after)
        ok("the bill is open again after cancellation", format(booked, ",.2f") in after_text, True)
        restored = exposure_rows(p.get("/ap/ageing")).get(code, {})
        ok("cancelling also reverses the reported realised exposure", money_number(restored.get("realised", "0")), realised_before)

# ── 5. clean rows created by this HTTP run, including any post-cutoff journal ──
if made:
    ids = ",".join(str(x) for x in made)
    # The reference is unique to this run; the Tinker cleanup removes its allocations, audit rows and journals too.
    code = (
        '$ps=\\App\\Models\\SupplierPayment::where("reference","like","%s%%")->get();'
        '$ids=$ps->pluck("id");'
        '$js=\\App\\Models\\GlJournal::where("source_type","ap_pay")->whereIn("source_key",$ids->map(fn($i)=>(string)$i))->get();'
        '$all=$js->pluck("id"); $rev=\\App\\Models\\GlJournal::whereIn("reversal_of_id",$all)->get(); $all=$all->merge($rev->pluck("id"))->unique();'
        '\\App\\Models\\GlLine::whereIn("gl_journal_id",$all)->delete(); \\App\\Models\\GlJournal::whereIn("id",$all)->delete();'
        '\\App\\Models\\ActivityLog::where("subject_type","SupplierPayment")->whereIn("subject_id",$ids)->delete();'
        '\\App\\Models\\ApAllocation::whereIn("supplier_payment_id",$ids)->delete(); $n=$ps->count(); $ps->each->delete(); printf("SWEPT %%d",$n);'
    ) % ref
    env = dict(os.environ, TINKER=code)
    sweep = subprocess.run(["deploy/dev/bin/php", "-f", "deploy/dev/tinker.php"], capture_output=True, text=True, env=env)
    swept = re.search(r"SWEPT (\d+)", sweep.stdout)
    ok("the harness's payment, allocation, audit and any GL rows are swept", int(swept.group(1)) if swept else -1, len(made))
    remaining = p.get("/ap/payments")
    ok("the register no longer mentions the harness reference", ref in remaining, False)

print()
print("%d passed, %d failed" % (PASS, FAIL))
sys.exit(1 if FAIL else 0)
