"""Channel Manager end-to-end drive: create, map, test, push and pull two real OTA adapters.

Seeds the local simulators with hotel-currency bookings, then runs the whole Channel Manager flow over HTTP exactly
as an operator would: create the connection, map room types and rate plans, test it, push a full ARI refresh, pull the
reservation feed, and cancel one booking at the desk so the cancellation travels back to the channel.

    python3 deploy/dev/audit/channel_e2e.py            # needs the sims (bash deploy/dev/sims-up.sh) and the PMS server
"""
import json
import re
import sys
import urllib.request

sys.path.insert(0, "/home/user/nowillsgrouppmserp/deploy/dev/audit")
from flows_lib import Pms, payload, submit  # noqa: E402

SM, EXP = "http://127.0.0.1:8096", "http://127.0.0.1:8095"


def seed(base, path, row):
    req = urllib.request.Request(base + path, data=json.dumps(row).encode(), headers={"Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=10) as r:
        return r.status


def flash_text(x):
    return " | ".join(str(i)[:200] for i in x) if isinstance(x, list) else str(x or "")[:200]


p = Pms()
CM = "/channel-manager/connections"

# --- 1. queue hotel-currency bookings at both channels -------------------------------------------
print("seed siteminder:", seed(SM, "/__bookings", {
    "ref": "SM-77120999", "modify": "New", "arrival": "2026-10-18", "departure": "2026-10-21",
    "first": "Ama", "last": "Serwaa", "email": "ama.serwaa@example.gh", "phone": "+233 24 777 1234", "country": "GH",
    "room_code": "SM-STD", "rate_code": "SM-BAR", "adults": 2, "children": 1, "amount": "4120.00", "currency": "GHS",
    "comments": ["Late arrival around 23:00", "Airport pick-up requested"]}))
print("seed siteminder:", seed(SM, "/__bookings", {
    "ref": "SM-77120998", "modify": "New", "arrival": "2026-10-20", "departure": "2026-10-22",
    "first": "Jonas", "last": "Lindqvist", "email": "jonas@example.se", "phone": "+46 70 111 2233", "country": "SE",
    "room_code": "SM-TWN", "rate_code": "SM-NR", "adults": 2, "children": 0, "amount": "2380.00", "currency": "GHS",
    "comments": []}))
print("seed expedia:", seed(EXP, "/__bookings", {
    "itinerary_id": "EXP-88420998", "property_id": "300452", "checkin": "2026-10-24", "checkout": "2026-10-27",
    "modified": False, "cancel": False,
    "guest": {"first_name": "Nana", "last_name": "Adjei", "email": "nana.adjei@example.gh", "phone": "+233 20 555 7788", "country": "GH"},
    "room_id": "100021", "rate_plan_id": "200033", "number_of_adults": 2, "number_of_children": 0,
    "total": {"amount": "2960.00", "currency": "GHS"}, "special_request": "Quiet room away from the pool"}))

# --- 2. build both connections through the UI ----------------------------------------------------
plan = [
    ("SiteMinder (sandbox)", "SMIND", "siteminder", "NWG01", SM, {"api_key": "smtoken"},
     {"rooms[1][code]": "SM-STD", "rooms[1][active]": "1", "rooms[2][code]": "SM-TWN", "rooms[2][active]": "1",
      "rates[1][1]": "SM-BAR", "rates[2][4]": "SM-NR"}),
    ("Expedia Rapid (sandbox)", "EXPRAP", "expedia", "300452", EXP, {"api_key": "expkey", "api_secret": "expsecret"},
     {"rooms[1][code]": "100021", "rooms[1][active]": "1", "rooms[2][code]": "100022", "rooms[2][active]": "1",
      "rates[1][1]": "200033"}),
]

created = []
for name, code, provider, prop, endpoint, creds, maps in plan:
    fields = {"name": name, "code": code, "provider": provider, "property_code": prop, "endpoint_url": endpoint,
              "payment_model": "channel_collect", "is_active": "1"}
    fields.update(creds)
    data, _ = payload(p.get(CM + "/create"), CM, fields)
    data.pop("_token", None)
    res = submit(p, CM, data, action_pattern=CM, read=CM + "/create")
    m = re.search(r"-> \d+ /channel-manager/connections/(\d+)", res[1] or "")
    if not m:
        print("FAILED to create", provider, "::", flash_text(res[1])[:200])
        continue
    cid = m.group(1)
    created.append((provider, cid))
    show = CM + "/" + cid
    print("%-10s created -> %s :: %s" % (provider, show, flash_text(res[1])[:110]))

    h = p.get(show)
    data, _ = payload(h, show + "/mapping", maps)
    data.pop("_token", None)
    res = submit(p, show + "/mapping", data, action_pattern=show + "/mapping", read=show)
    print("%-10s mapping :: %s" % (provider, flash_text(res[1])[:110]))

    for action, extra in (("test", {}), ("push", {"full": "1"}), ("pull", {})):
        h = p.get(show)
        tok = p.token(h, show + "/push")
        c, b, loc = p.post(show + "/" + action, extra, tok, referer=show)
        msg = flash_text(p.flash(p.get(re.sub(r"^https?://[^/]+", "", loc)))) if loc else ""
        print("%-10s %-5s -> %s %s" % (provider, action, c, msg[:190]))

# --- 3. cancel one at the desk and watch it travel back to the channel ---------------------------
if created:
    cid = created[0][1]
    row = None
    for r in re.finditer(r"/reservations/(\d+)", p.get(CM + "/" + cid)):
        row = r.group(1)
        break
    if row:
        page = "/reservations/" + row
        h = p.get(page)
        tok = p.token(h, page + "/cancel")
        c, b, loc = p.post(page + "/cancel", {"reason": "Guest cancelled by e-mail – two-way channel audit",
                                              "waive_fee": "1", "waive_reason": "Channel booking, no retention"}, tok, referer=page)
        print("desk cancel %s -> %s %s" % (page, c, flash_text(p.flash(p.get(re.sub(r"^https?://[^/]+", "", loc))))[:230] if loc else ""))

print("\nchannel simulators:")
for label, base in (("siteminder", SM), ("expedia", EXP)):
    with urllib.request.urlopen(base + "/status", timeout=10) as r:
        d = json.load(r)
    keep = {k: d[k] for k in d if k in ("ari", "served", "cancelled", "bad_auth", "bad_xml", "bad_sig", "queued", "failures")}
    print("  %-10s %s" % (label, json.dumps(keep)))
