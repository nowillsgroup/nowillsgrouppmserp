#!/usr/bin/env python3
"""v7.50 — a foreign-currency OTA booking, driven through the pages a person would use.

The service-level proof lives in deploy/dev/audit/channel_fx.php (31 checks, rolled back). This one walks the UI so
the same thing can be seen in the browser:

  1. Foreign Guests › Settings › Forex desk      the conversion switch, the basis, the staleness window
  2. Channel Manager › Test Channel (Sandbox)    the simulator now asks which currency the OTA quotes in
  3. a USD booking pushed from that form         converted at the buy board, warning shown in the flash
  4. Reservations › the booking                  the audit line: what was sent, the rate, which board
  5. Channel Manager › bookings                  the row shows the cedi amount and the figure the OTA sent
  6. an EUR booking at the reference board       the basis setting really changes the rate used
  7. conversion switched off                     the old rejection, saying where the switch is (inbox row removed)
  8. cancelling a converted booking              still works, and the audit line stays on the cancelled booking

Leaves one converted USD booking behind as demo evidence and cancels the EUR one; deletes the artificial rejection.

    python3 deploy/dev/audit/channel_fx_flow.py
"""
import os
import re
import subprocess
import sys
import time

sys.path.insert(0, "deploy/dev/audit")
from pmshttp import Pms  # noqa: E402

PASS = FAIL = 0


def ok(what, got, want):
    """Assert and print one line of the report."""
    global PASS, FAIL
    if got == want:
        PASS += 1
        print("  ok    %-60s %s" % (what, str(got)[:70]))
    else:
        FAIL += 1
        print("  FAIL  %-60s got %r, wanted %r" % (what, str(got)[:90], str(want)[:90]))


def contains(what, hay, needle):
    """Assert that a page/flash carries a phrase, and print it."""
    ok(what, needle in hay, True)


def bdate(days):
    """A date days ahead of the PMS business date (the form defaults can be fully booked)."""
    # the PHP printf format string would collide with Python's % operator — build it by concatenation
    code = 'printf("' + '%s' + '", bdate()->copy()->addDays(' + str(int(days)) + ')->toDateString());'
    out = subprocess.run(["deploy/dev/bin/php", "-f", "deploy/dev/tinker.php"],
                         env=dict(os.environ, TINKER=code), capture_output=True, text=True).stdout.strip()
    return out[-10:]


def follow(p, loc, fallback="/"):
    """Laravel redirects to an absolute URL built on APP_URL, which does not resolve here — strip the origin."""
    return p.get(re.sub(r"^https?://[^/]+", "", loc) if loc else fallback)


def region(html, form_id):
    """The HTML of one form, so fields of the same name in another form are not picked up."""
    i = html.find('id="%s"' % form_id)
    if i < 0:
        return ""
    j = html.find("</form>", i)
    return html[i:j if j > 0 else i + 6000]


def token_of(html, form_id):
    m = re.search(r'name="_token"\s+value="([^"]+)"', region(html, form_id))
    return m.group(1) if m else None


def field(form, name):
    """The submitted value of one field: text/number input, select, or checkbox."""
    m = re.search(r'<(?:input|select|textarea)[^>]*name="%s"[^>]*>' % name, form)
    if not m:
        return None
    tag = m.group(0)
    if tag.startswith("<select"):
        body = form[m.end():form.find("</select>", m.end())]
        sel = re.search(r'<option value="([^"]*)"[^>]*\bselected\b', body)
        return sel.group(1) if sel else (re.search(r'<option value="([^"]*)"', body) or [None, ""])[1]
    v = re.search(r'value="([^"]*)"', tag)
    if "type=\"checkbox\"" in tag:
        return "1" if re.search(r'\bchecked\b', tag) else "0"
    return v.group(1) if v else ""


def forex_settings(p, changes):
    """Read the forex form, change only what is asked, post it back whole."""
    html = p.get("/foreign/settings")
    form = region(html, "forexSettings")
    tok = token_of(html, "forexSettings")
    data = {k: field(form, k) for k in
            ["cash_cap_usd", "resident_cap_usd", "aggregate_days", "id_copy_inr", "max_margin", "fee_pct",
             "surrender_days", "licence", "franchiser", "channel_basis", "channel_stale_days",
             "enabled", "allow_residents", "carry_rates", "channel_convert"]}
    data.update(changes)
    data["section"] = "forex"
    boxes = ["enabled", "allow_residents", "carry_rates", "channel_convert"]
    # an unchecked box is simply absent; a numeric 0 (fee_pct) is a real value and must be posted
    payload = {k: v for k, v in data.items() if v is not None and not (k in boxes and v == "0")}
    code, _, loc = p.post("/foreign/settings", payload, tok, referer="/foreign/settings")
    page = follow(p, loc, "/foreign/settings")
    # a failed save re-renders the posted values, so a page that echoes them proves nothing — say so loudly
    bad = [f for f in Pms.flash(page) if "Please fix" in f]
    ok("  the policy saved (%s)" % ", ".join("%s=%s" % kv for kv in changes.items()), bad, [])
    return code, loc, page


def simulate(p, currency, names, arrival=None, departure=None):
    """Push a booking from the sandbox simulator form exactly as the page posts it."""
    html = p.get("/channel-manager/connections/3")
    form = region(html, "simForm")
    tok = token_of(html, "simForm")
    room = re.search(r'<option value="(\d+)"', form[re.search(r'name="room_map_id"', form).end():])
    data = {
        "event": "new", "booking_id": "FX%d" % (int(time.time() * 10) % 1000000),
        "room_map_id": room.group(1), "rooms": "1", "adults": "2",
        "arrival": arrival or (bdate(21)), "departure": departure or (bdate(24)),
        "first_name": names[0], "last_name": names[1], "email": "", "currency": currency,
    }
    code, _, loc = p.post("/channel-manager/connections/3/simulate", data, tok,
                          referer="/channel-manager/connections/3")
    page = follow(p, loc, "/channel-manager/connections/3") if loc else ""
    flash = " ".join(Pms.flash(page))
    return code, loc, flash, page, data


def booking_link(page, res_no):
    """Find the reservation the simulator just made, from the open-test-bookings list."""
    # route() renders absolute URLs — match the path part only
    for m in re.finditer(r'href="[^"]*?/reservations/(\d+)"[^>]*>\s*([A-Z]{3,4}-\d+)', page):
        if m.group(2) == res_no:
            return int(m.group(1))
    m = re.search(r'href="[^"]*?/reservations/(\d+)"', page)
    return int(m.group(1)) if m else None


def res_no_of(flash):
    m = re.search(r"\b([A-Z]{2,4}-\d{4,6})\b", flash)
    return m.group(1) if m else None


p = Pms()
created = {}

def tinker(code):
    return subprocess.run(["deploy/dev/bin/php", "-f", "deploy/dev/tinker.php"],
                          env=dict(os.environ, TINKER=code), capture_output=True, text=True).stdout.strip()


CLEANUP = (
    '$conn = App\\Models\\ChannelConnection::where("provider","simulator")->first();'
    '$res = App\\Models\\Reservation::where("channel_connection_id",$conn->id)->where("ota_reference","like","FX%")->get();'
    '$rooms = 0; foreach ($res as $r) { $rooms += App\\Models\\ReservationRoom::where("reservation_id",$r->id)->delete(); $r->delete(); }'
    '$msgs = App\\Models\\ChannelMessage::where("channel_connection_id",$conn->id)->where("channel_ref","like","FX%")->delete();'
    'printf("  cleared %d earlier FX test booking(s), %d room line(s), %d inbox message(s)%s", $res->count(), $rooms, $msgs, PHP_EOL);'
)
print("\n0. Baseline — clear earlier FX test bookings, then put the policy back to the shipped defaults")
print(tinker(CLEANUP))
forex_settings(p, {"channel_basis": "reference", "channel_stale_days": "7", "channel_convert": "1"})
print("        reference board · 7-day window · conversion on")
print()

print("1. Foreign Guests › Settings › Forex desk — the policy fields")
html = p.get("/foreign/settings")
form = region(html, "forexSettings")
ok("  the conversion switch renders", field(form, "channel_convert"), "1")
ok("  the basis select renders on reference", field(form, "channel_basis"), "reference")
ok("  the staleness window renders", field(form, "channel_stale_days"), "7")
contains("  the switch is worded for a hotelier", form, "Convert OTA / channel bookings sent in a foreign currency")
contains("  the basis offers the buy rate", form, "what the desk would pay a guest")
print()

print("2. Channel Manager › Test Channel (Sandbox) — the simulator asks for a currency")
form = region(p.get("/channel-manager/connections/3"), "simForm")
contains("  …with the hotel currency first", form, "GHS (hotel)")
ok("  …and USD is offered", "<option value=\"USD\"" in form, True)
ok("  …the field is labelled in plain words", "Currency the OTA quotes in" in form, True)
print()

print("3. A USD booking, converted at the buy board")
forex_settings(p, {"channel_basis": "buy"})          # the buy rate is what the desk would actually give
code, loc, flash, page, data = simulate(p, "USD", ("Kwame", "Boateng"))
ok("  the simulator form posted", code, 302)
print("        flash: “%s”" % flash[:300])
contains("  the flash reports the booking", flash, "RES-")
contains("  …and how it was converted", flash, "the channel sent USD")
contains("  …at the buy rate the setting chose", flash, "buy rate 11.4500")
contains("  …and which board it came from", flash, "board")
no = res_no_of(flash)
ok("  a reservation was made", bool(no), True)
rid = booking_link(p.get("/channel-manager/connections/3"), no)
created["usd"] = (rid, no)
print("        %s → /reservations/%s" % (no, rid))
print()

print("4. Reservations › %s — the audit line" % no)
rhtml = p.get("/reservations/%d" % rid)
fx = re.search(r'id="resFx"[^>]*>(.*?)</div>', rhtml, re.S)
line = re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", fx.group(1))).strip() if fx else ""
print("        “%s”" % line)
ok("  the audit line renders", bool(fx), True)
contains("  it names the currency and amount sent", line, "Sent as USD")
contains("  it names the rate and basis board", line, "11.4500")
contains("  it names the board date", line, "14 Oct 2026")
contains("  the gross is in cedis", rhtml, "gross")
print()

print("5. Channel Manager › bookings — the row keeps both figures")
idx = p.get("/channel-manager")
row = idx[idx.find("/reservations/%d" % rid) - 200:idx.find("/reservations/%d" % rid) + 900]
contains("  …the figure the OTA sent", row, "sent USD")
contains("  …and the rate used", row, "11.4500")
print()

print("6. An EUR booking at the reference board")
forex_settings(p, {"channel_basis": "reference"})
code, loc, flash, page, data = simulate(p, "EUR", ("Amara", "Nwosu"))
contains("  the basis really changes the rate used", flash, "reference rate 13.6184")
no2 = res_no_of(flash)
rid2 = booking_link(p.get("/channel-manager/connections/3"), no2)
created["eur"] = (rid2, no2)
eur_ref = data["booking_id"]
r2 = p.get("/reservations/%d" % rid2)
fx2 = re.search(r'id="resFx"[^>]*>(.*?)</div>', r2, re.S)
print("        %s “%s”" % (no2, re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", fx2.group(1))).strip() if fx2 else ""))
contains("  the EUR booking shows its own audit line", r2, "Sent as EUR")
print()

print("7. Conversion switched off — the old rejection, with the switch named")
forex_settings(p, {"channel_convert": "0"})
code, loc, flash, page, data = simulate(p, "GBP", ("Test", "Rejected"))
contains("  the flash reports a rejection", flash, "does not match the hotel currency")
contains("  …and where the switch is", flash, "Forex desk")
# the inbox opens on the attention tab — a rejection belongs on the failed one
inbox = p.get("/channel-manager/inbox?tab=failed")
contains("  the failed tab carries it", inbox, "does not match the hotel currency GHS")
contains("  …and says the switch is off", inbox, "converting channel bookings is off")
ok("  no reservation was made for it", res_no_of(flash), None)
forex_settings(p, {"channel_convert": "1"})
print()

print("8. Cancelling a converted booking")
chtml = p.get("/channel-manager/connections/3")
# the open-test-bookings list offers a cancel button per booking, keyed on the OTA reference
cancel_form = chtml[chtml.find("Send a cancellation for %s" % eur_ref):]
ok("  the list offers a cancel button for it", "Cancel via OTA" in cancel_form[:700], True)
ctok = re.search(r'name="_token"\s+value="([^"]+)"', cancel_form)
cid = re.search(r'name="booking_id"\s+value="([^"]+)"', cancel_form)
ok("  the button carries the OTA reference", cid.group(1) if cid else None, eur_ref)
code, _, loc = p.post("/channel-manager/connections/3/simulate",
                      {"event": "cancel", "booking_id": cid.group(1)}, ctok.group(1),
                      referer="/channel-manager/connections/3")
page = follow(p, loc, "/channel-manager/connections/3") if loc else ""
contains("  the cancellation is accepted", " ".join(Pms.flash(page)), "cancel")
c = p.get("/reservations/%d" % created["eur"][0])
contains("  the audit line stays on the cancelled booking", c, "Sent as EUR")
print()

# remove the artificial rejection so the demo inbox is clean
print(tinker('$n = App\\Models\\ChannelMessage::where("channel_ref","like","FX%")->where("status","failed")'
             '->where("error","like","%does not match the hotel currency%")->delete();'
             'printf("  removed %d artificial rejection(s) from the inbox%s", $n, PHP_EOL);') or "  (nothing to remove)")

print("\n%d passed, %d failed" % (PASS, FAIL))
print("left as demo evidence: %s (converted, open) · %s (converted, cancelled)" %
      (created.get("usd", ("?", "?"))[1], created.get("eur", ("?", "?"))[1]))
print("click path: Foreign Guests › Settings › Forex desk → Channel Manager › Test Channel (Sandbox) →"
      " Currency the OTA quotes in = USD → Send → Reservations › %s › Channel" % created.get("usd", ("?", "?"))[1])
sys.exit(1 if FAIL else 0)
