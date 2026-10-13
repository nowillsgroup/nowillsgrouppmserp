#!/usr/bin/env python3
"""Front Office & Guest Services — write-flow exercise (batch A: Front Office,
Desk Operations).  Every step POSTs a real form payload to the real route and
then reads the flash message the app replies with, so a route that exists but
does nothing cannot pass.

Run:  python3 deploy/dev/audit/flows_a.py
"""
import datetime as dt
import json
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from pmshttp import Pms                                              # noqa: E402

RESULTS = []


def record(module, flow, ok, detail):
    RESULTS.append({"module": module, "flow": flow, "ok": bool(ok), "detail": detail[:300]})
    print(("  PASS  " if ok else "  FAIL  ") + "%-14s %-42s %s" % (module, flow, detail[:150]))


def form_fields(html, action_pattern):
    """name -> current value for every input/select/textarea of one form."""
    out = {}
    for m in re.finditer(r'<form[^>]*action="([^"]*)"[^>]*>(.*?)</form>', html, re.S | re.I):
        if not re.search(action_pattern, m.group(1)):
            continue
        body = m.group(2)
        for i in re.finditer(r'<(input|textarea|select)\b([^>]*)>(.*?)</\1>|<(input|textarea|select)\b([^>]*)/?>',
                             body, re.S | re.I):
            attrs = i.group(2) or i.group(5) or ""
            inner = i.group(3) or ""
            nm = re.search(r'name="([^"]+)"', attrs)
            if not nm:
                continue
            name = nm.group(1)
            tag = (i.group(1) or i.group(4) or "").lower()
            val = re.search(r'value="([^"]*)"', attrs)
            if tag == "select":
                sel = re.search(r'<option[^>]*\bselected\b[^>]*value="([^"]*)"', inner) or \
                      re.search(r'<option[^>]*value="([^"]*)"[^>]*\bselected\b', inner) or \
                      re.search(r'<option[^>]*value="([^"]*)"', inner)
                out[name] = sel.group(1) if sel else ""
                out[name + "__options"] = re.findall(r'<option[^>]*value="([^"]*)"', inner)
            elif tag == "textarea":
                out[name] = re.sub(r"<[^>]+>", "", inner).strip()
            else:
                typ = (re.search(r'type="([^"]*)"', attrs) or [None, "text"])[1]
                if typ in ("checkbox", "radio"):
                    if "checked" in attrs:
                        out[name] = val.group(1) if val else "1"
                    else:
                        out.setdefault(name, val.group(1) if val else "1")
                else:
                    out[name] = val.group(1) if val else ""
    return out


def submit(p, path, data, action_pattern=None, read=None, label=""):
    """GET the form page (for a fresh token), POST, then read the flash back.

    `back()` redirects echo the Referer, so when the Location is the POST target
    itself the flash lives on the page the form was rendered on (`read`).
    """
    page = read or path
    html = p.get(page)
    tok = p.token(html, action_pattern)
    if not tok:
        return None, "no CSRF token found on %s (pattern %s)" % (page, action_pattern)
    code, _body, loc = p.post(path, data, tok, referer=page)
    target = re.sub(r"^https?://[^/]+", "", loc or "") or page
    if target.split("?")[0] == path.split("?")[0]:
        target = page
    after = p.get(target)
    flashes = p.flash(after)
    return flashes, "POST %s -> %s %s | flash: %s" % (path, code, target[:60], " / ".join(flashes)[:200])


def main():
    p = Pms()
    stay = 62                                   # in-house (Rahul Verma), room 1
    today = "2026-10-13"

    # ---------- Front Office: guest request lifecycle ----------
    fl, d = submit(p, "/guest-requests", {
        "reservation_room_id": stay, "category": "amenity", "item": "Extra pillows",
        "quantity": 2, "notes": "Audit probe — guest asked for two extra pillows",
    }, action_pattern=r"/guest-requests$", read="/guest-requests")
    ok = bool(fl) and any("logged" in f.lower() for f in fl or [])
    record("Front Office", "guest request: log", ok, d)
    no = None
    if fl:
        m = re.search(r"([A-Z]{2,}-?\d[\w-]*)", " ".join(fl))
        no = m.group(1) if m else None

    greq_id = None
    lst = p.get("/guest-requests")
    ids = re.findall(r"/guest-requests/(\d+)/(?:acknowledge|start|done)", lst)
    if ids:
        greq_id = ids[0]
    if greq_id:
        fl, d = submit(p, "/guest-requests/%s/start" % greq_id, {"note": "Housekeeping on the way"},
                       action_pattern=r"/guest-requests/%s/start" % greq_id, read="/guest-requests")
        record("Front Office", "guest request: start", bool(fl) and any("progress" in f.lower() for f in fl), d)
        fl, d = submit(p, "/guest-requests/%s/done" % greq_id, {"note": "Towels delivered"},
                       action_pattern=r"/guest-requests/%s/done" % greq_id, read="/guest-requests")
        record("Front Office", "guest request: complete", bool(fl) and any("done" in f.lower() for f in fl), d)
        fl, d = submit(p, "/guest-requests/%s/assign" % greq_id, {"assigned_to": 1},
                       action_pattern=r"/guest-requests/%s/assign" % greq_id, read="/guest-requests")
        record("Front Office", "guest request: assign", bool(fl) and any("assigned" in f.lower() for f in fl), d)
    else:
        record("Front Office", "guest request: transitions", False, "no request id found on the queue page")

    fl, d = submit(p, "/guest-requests/stay/%d/portal/send" % stay, {"channel": "sms"},
                   action_pattern=r"/guest-requests/stay/%d/portal/send" % stay, read="/guest-requests")
    record("Front Office", "guest request portal: send link", bool(fl), d)

    # ---------- Desk Operations: log book ----------
    fl, d = submit(p, "/desk/logbook", {
        "title": "Audit probe — lobby light flickering", "body": "Logged by the completeness audit.",
        "category": "maintenance", "department": "front_office", "priority": "high",
        "needs_action": 1, "follow_up_at": "2026-10-14",
    }, action_pattern=r"/desk/logbook$", read="/desk/logbook")
    record("Desk Operations", "log book: entry", bool(fl) and any("added" in f.lower() for f in fl), d)
    lb = p.get("/desk/logbook")
    lids = [int(x) for x in re.findall(r"/desk/logbook/(\d+)", lb)]
    done = False
    for cand in sorted(set(lids + [max(lids) + 1 if lids else 1]), reverse=True)[:6]:
        page = p.get("/desk/logbook/%d" % cand)
        if re.search(r"/desk/logbook/%d/resolve" % cand, page):
            fl, d = submit(p, "/desk/logbook/%d/resolve" % cand,
                           {"resolution": "Engineering replaced the ballast"},
                           action_pattern=r"/desk/logbook/%d/resolve" % cand,
                           read="/desk/logbook/%d" % cand)
            record("Desk Operations", "log book: resolve entry %d" % cand, bool(fl), d)
            done = True
            break
    if not done:
        record("Desk Operations", "log book: resolve", False, "no entry exposes a resolve form")

    # ---------- Desk Operations: wake-up call ----------
    # WakeupService: call_at must be in the future but within departure day, and
    # an attempt is only allowed once the call is inside the lead window — so
    # schedule it two minutes out and attempt it straight away.
    soon = (dt.datetime.now() + dt.timedelta(minutes=2)).replace(second=0, microsecond=0)
    fl, d = submit(p, "/desk/wakeups", {
        "reservation_room_id": stay, "call_date": soon.strftime("%Y-%m-%d"),
        "call_time": soon.strftime("%H:%M"),
        "note": "Audit probe — early flight", "source": "desk",
    }, action_pattern=r"/desk/wakeups$", read="/desk/wakeups")
    record("Desk Operations", "wake-up: schedule", bool(fl) and any("booked" in f.lower() for f in fl), d)
    wk = p.get("/desk/wakeups")
    wids = re.findall(r"/desk/wakeups/(\d+)/attempt", wk)
    if wids:
        fl, d = submit(p, "/desk/wakeups/%s/attempt" % wids[0], {"outcome": "answered", "note": "Guest awake"},
                       action_pattern=r"/desk/wakeups/%s/attempt" % wids[0], read="/desk/wakeups")
        record("Desk Operations", "wake-up: attempt answered", bool(fl), d)
    else:
        record("Desk Operations", "wake-up: attempt answered", False, "no attempt button for the new call")

    # ---------- Desk Operations: message / parcel ----------
    fl, d = submit(p, "/desk/messages", {
        "reservation_room_id": stay, "kind": "parcel", "channel": "courier",
        "from_name": "DHL Accra", "from_phone": "+233302000000", "courier": "DHL",
        "tracking_no": "AUDIT123", "pieces": 1, "storage_location": "Cage A / shelf 3",
        "urgent": 1, "notify": 1,
    }, action_pattern=r"/desk/messages$", read="/desk/messages")
    record("Desk Operations", "messages: log parcel + notify", bool(fl) and any("logged" in f.lower() for f in fl), d)
    ms = p.get("/desk/messages")
    mids = re.findall(r"/desk/messages/(\d+)/deliver", ms)
    if mids:
        fl, d = submit(p, "/desk/messages/%s/deliver" % mids[0],
                       {"delivery_note": "Handed to guest", "collected_by": "Guest in person"},
                       action_pattern=r"/desk/messages/%s/deliver" % mids[0], read="/desk/messages")
        record("Desk Operations", "messages: deliver", bool(fl), d)
    else:
        record("Desk Operations", "messages: deliver", False, "no deliver button for the new parcel")

    # ---------- Desk Operations: trace ----------
    fl, d = submit(p, "/desk/traces", {
        "trace_date": today, "due_time": "18:00", "department": "front_office",
        "text": "Audit probe — confirm departure time with Mr Verma", "reservation_room_id": stay,
    }, action_pattern=r"/desk/traces$", read="/desk/traces")
    record("Desk Operations", "traces: set", bool(fl) and any("trace set" in f.lower() for f in fl), d)
    tr = p.get("/desk/traces")
    tids = re.findall(r"/desk/traces/(\d+)/done", tr)
    if tids:
        fl, d = submit(p, "/desk/traces/%s/done" % tids[0], {"note": "Guest confirmed 10:00 departure"},
                       action_pattern=r"/desk/traces/%s/done" % tids[0], read="/desk/traces")
        record("Desk Operations", "traces: complete", bool(fl), d)
    else:
        record("Desk Operations", "traces: complete", False, "no done button for the new trace")

    # ---------- Desk Operations: lost & found ----------
    fl, d = submit(p, "/lost-found", {
        "item_name": "Audit probe — charger %s" % dt.datetime.now().strftime("%H%M%S"), "category": "electronics",
        "description": "Found in room 101 by the audit", "valuable": 1, "est_value": 450,
        "room_id": 1, "location": "Room 101 bedside", "storage_location": "Safe 2",
        "found_date": today, "found_by": "Room attendant",
    }, action_pattern=r"/lost-found$", read="/lost-found")
    ok = bool(fl) and any("logged as" in f.lower() for f in fl)
    record("Desk Operations", "lost & found: log item", ok, d)
    item_id = None
    if ok:
        loc = p.last_location
        m = re.search(r"/lost-found/(\d+)", loc or "")
        item_id = m.group(1) if m else None
    if not item_id:
        lf = p.get("/lost-found")
        m = re.findall(r"/lost-found/(\d+)", lf)
        item_id = m[0] if m else None
    if item_id:
        page = p.get("/lost-found/%s" % item_id)
        f = form_fields(page, r"/lost-found/%s/return" % item_id)
        methods = [x for x in (f.get("return_method__options") or []) if x]
        idtypes = [x for x in (f.get("claimant_id_type__options") or []) if x]
        fl, d = submit(p, "/lost-found/%s/return" % item_id, {
            "returned_to": "Mr Kwame Ansah", "claimant_phone": "+233244000000",
            "return_method": methods[0] if methods else "in_person",
            # valuable items require the claimant's photo ID before release
            "claimant_id_type": idtypes[0] if idtypes else "passport",
            "claimant_id_no": "GHA-7781234567",
        }, action_pattern=r"/lost-found/%s/return" % item_id, read="/lost-found/%s" % item_id)
        record("Desk Operations", "lost & found: return to owner", bool(fl) and any("returned" in x.lower() for x in fl), d)
    else:
        record("Desk Operations", "lost & found: return to owner", False, "could not find the logged item")

    # ---------- Desk Operations: settings round-trip ----------
    page = p.get("/desk/settings")
    f = form_fields(page, r"/desk/settings")
    payload = {k: v for k, v in f.items() if not k.endswith("__options") and k != "_token"}
    if payload.get("log_unread_days"):
        payload["log_unread_days"] = str(min(30, int(payload["log_unread_days"])))
    fl, d = submit(p, "/desk/settings", payload, action_pattern=r"/desk/settings$", read="/desk/settings")
    record("Desk Operations", "desk settings: save", bool(fl) and any("saved" in x.lower() for x in fl),
           d + " | fields=%d" % len(payload))

    out = os.path.join(os.path.dirname(os.path.abspath(__file__)), "flows_a.json")
    json.dump(RESULTS, open(out, "w"), indent=1)
    passed = sum(1 for r in RESULTS if r["ok"])
    print("\nbatch A: %d/%d passed -> %s" % (passed, len(RESULTS), out))


if __name__ == "__main__":
    main()
