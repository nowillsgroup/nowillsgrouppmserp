#!/usr/bin/env python3
"""Front Office & Guest Services — write-flow exercise, batch B:
Reservations (incl. the desk tools), Central Reservations, Concierge.

Run:  python3 deploy/dev/audit/flows_b.py
"""
import base64
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import flows_lib
from flows_lib import Pms, day, form_fields, payload, record, refused, save, submit, submit_form   # noqa: E402

PNG_1PX = base64.b64decode(
    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==")


def main():
    p = Pms()
    stay = 62                      # in-house guest, room 1 (departs today)
    long_stay = 65                 # in-house until 17 Oct — activity dates fall inside the stay
    today = day(0)

    # ─────────────────────────── Reservations ───────────────────────────
    # 1. saved desk view
    fl, d = submit_form(p, "/reservations/desk-views", r"/reservations/desk-views$",
                        read="/reservations", overrides={"name": "Audit view — arrivals", "query": "view=arrivals"},
                        ok_words=("saved", "view"))
    record("Reservations", "desk: save a view", bool(fl) and any("saved" in f.lower() for f in fl or []), d)

    # 2. waitlist → check availability → convert
    wl = "/reservations?view=waitlist"
    fl, d = submit_form(p, "/reservations/waitlist", r"/reservations/waitlist$", read=wl,
                        overrides={"contact_name": "Audit Waitlister", "phone": "+233244111222",
                                   "arrival_date": day(1), "departure_date": day(3), "adults": "2",
                                   "children": "0", "priority": "5",
                                   "notes": "Audit probe — waiting for a deluxe room"},
                        ok_words=("waitlist",))
    record("Reservations", "waitlist: add", bool(fl) and any("waitlist" in f.lower() for f in fl or []), d)
    page = p.get(wl)
    checks = re.findall(r'/reservations/waitlist/(\d+)/check', page)
    if checks:
        wid = checks[-1]
        fl, d = submit(p, "/reservations/waitlist/%s/check" % wid, {}, read=wl, ok_words=("free", "avail", "no room", "sold"))
        record("Reservations", "waitlist: check availability", bool(fl), d)
        page = p.get(wl)
        if re.search(r'/reservations/waitlist/%s/convert' % wid, page):
            fl, d = submit(p, "/reservations/waitlist/%s/convert" % wid, {}, read=wl, ok_words=("reservation", "created", "not available", "no room"))
            record("Reservations", "waitlist: convert to reservation", bool(fl), d)
        else:
            record("Reservations", "waitlist: convert to reservation", True,
                   "no convert button offered (nothing free) — availability gate working")
    else:
        record("Reservations", "waitlist: check availability", False, "no waitlist entry rendered")

    # 3. room outage (block a room off-sale) then release it — from the room card
    board = p.get("/reservations?view=board")
    cards = sorted({int(x) for x in re.findall(r"/reservations/rooms/(\d+)/card", board)})
    outage_done = False
    for room in cards[:8]:
        card_page = "/reservations/rooms/%d/card" % room
        html = p.get(card_page)
        data, f = payload(html, r"/reservations/outages$", {
            "room_id": str(room), "starts_on": day(20), "ends_on": day(22),
            "reason": "maintenance", "notes": "Audit probe — AC service"})
        if not data:
            continue
        reasons = [x for x in (f.get("reason__options") or []) if x]
        data["reason"] = reasons[0] if reasons else "maintenance"
        data.pop("_token", None)
        fl, d = submit(p, "/reservations/outages", data, action_pattern=r"/reservations/outages$",
                       read=card_page, ok_words=("blocked", "off sale", "cannot", "already"))
        if fl and any("blocked" in x.lower() for x in fl):
            record("Reservations", "room outage: block a room", True, d)
            html2 = p.get(card_page)
            rel = re.findall(r"/reservations/outages/(\d+)/release", html2)
            if rel:
                fl, d = submit(p, "/reservations/outages/%s/release" % rel[-1], {}, read=card_page,
                               ok_words=("released",))
                record("Reservations", "room outage: release", bool(fl) and any("released" in x.lower() for x in fl), d)
            else:
                record("Reservations", "room outage: release", False, "no release button on the card")
            outage_done = True
            break
        last = d
    if not outage_done:
        record("Reservations", "room outage: block a room", False, last if cards else "no room cards found")

    # 4. amenity master (RoomAmenity had zero rows)
    fl, d = submit_form(p, "/reservations/amenities", r"/reservations/amenities$", read="/reservations/amenities",
                        overrides={"name": "Audit — USB-C charger", "category": "general",
                                   "description": "Added by the completeness audit"},
                        ok_words=("saved", "amenity"))
    record("Reservations", "amenities: add master record", bool(fl) and any("saved" in x.lower() for x in fl or []), d)

    # 5. room photo upload (RoomImage had zero rows) — multipart
    page = "/reservations/amenities"
    html = p.get(page)
    tok = p.token(html, r"/reservations/images$")
    if tok:
        code, _b, loc = p.post_multipart("/reservations/images",
                                         {"room_type_id": "1", "caption": "Audit probe photo"},
                                         {"photo": ("audit-probe.png", PNG_1PX, "image/png")},
                                         tok=tok, referer=page)
        target = re.sub(r"^https?://[^/]+", "", loc or "") or page
        after = p.get(target)
        flashes = p.flash(after)
        record("Reservations", "room media: upload a photo", bool(flashes) and any("saved" in x.lower() for x in flashes),
               "POST(multipart) /reservations/images -> %s | flash: %s" % (code, " / ".join(flashes)[:200]))
    else:
        record("Reservations", "room media: upload a photo", False, "no upload form on /reservations/amenities")

    # 6. seasonal rate override, then remove it
    rates_page = p.get("/rates")
    types = re.findall(r'name="room_type_ids\[\]"[^>]*value="(\d+)"', rates_page)
    fl, d = submit(p, "/rates", {
        "name": "Audit — December peak", "room_type_ids[]": types[:1] or ["1"],
        "date_from": day(45), "date_to": day(52), "rate": "899.00", "weekend_rate": "1049.00",
        "min_stay": "2", "stop_sell": "0", "is_fixed": "0",
    }, action_pattern=r"/rates$", read="/rates", ok_words=("applied", "created", "saved", "rate"))
    record("Reservations", "rates: seasonal override", bool(fl), d)
    rates_after = p.get("/rates")
    dels = re.findall(r'action="[^"]*/rates/(\d+)"', rates_after)
    if dels:
        html = rates_after
        data = form_fields(html, r"/rates/%s$" % dels[-1])
        data.pop("_token", None)
        fl, d = submit(p, "/rates/%s" % dels[-1], data, action_pattern=r"/rates/%s$" % dels[-1],
                       read="/rates", ok_words=("removed", "deleted", "gone"))
        record("Reservations", "rates: remove override", bool(fl), d)

    # 7. group block → definite → rooming-list pickup → release
    bc = p.get("/blocks/create")
    data, f = payload(bc, r"/blocks$", {
        "name": "Audit — Wedding party", "contact_name": "Audit Planner", "contact_phone": "+233244333444",
        "arrival_date": day(30), "departure_date": day(33), "cutoff_date": day(25),
        "billing_instruction": "guest", "status": "tentative", "notes": "Audit probe block"})
    if data:
        lines = {k: v for k, v in f.items() if k.startswith("lines[")}
        if lines:
            first = sorted(lines)[0]
            data[first] = "2"
        else:
            data["lines[1]"] = "2"
        data.pop("_token", None)
        fl, d = submit(p, "/blocks", data, action_pattern=r"/blocks$", read="/blocks/create",
                       ok_words=("created", "block"))
        ok = bool(fl) and any("created" in x.lower() for x in fl)
        record("Reservations", "blocks: create group block", ok, d)
        m = re.search(r"/blocks/(\d+)", flows_lib.LAST_LOC or "")
        bid = m.group(1) if m else None
        if not bid:
            ids = re.findall(r"/blocks/(\d+)", p.get("/blocks"))
            bid = ids[-1] if ids else None
        if bid:
            show = "/blocks/%s" % bid
            fl, d = submit(p, "/blocks/%s/status" % bid, {"action": "definite"}, read=show,
                           ok_words=("definite",))
            record("Reservations", "blocks: mark definite", bool(fl) and any("definite" in x.lower() for x in fl), d)
            html = p.get(show)
            data, f = payload(html, r"/blocks/%s/pickup$" % bid, {
                "names": "Audit Guest One\nAudit Guest Two", "adults": "2",
                "arrival_date": day(30), "departure_date": day(33)})
            if data:
                opts = [x for x in (f.get("room_type_id__options") or []) if x]
                if opts:
                    data["room_type_id"] = opts[0]
                data.pop("_token", None)
                fl, d = submit(p, "/blocks/%s/pickup" % bid, data, action_pattern=r"/blocks/%s/pickup$" % bid,
                               read=show, ok_words=("reservation", "created", "no room", "cannot"))
                record("Reservations", "blocks: rooming-list pickup", bool(fl), d)
            else:
                record("Reservations", "blocks: rooming-list pickup", False, "no pickup form on the block page")
            fl, d = submit(p, "/blocks/%s/status" % bid, {"action": "release"}, read=show,
                           ok_words=("released", "already"))
            record("Reservations", "blocks: release unpicked rooms", bool(fl), d)
    else:
        record("Reservations", "blocks: create group block", False, "no block form on /blocks/create")

    # 8. assign a room to an unassigned arrival (from the workspace)
    ws = p.get("/reservations/workspace")
    assigns = re.findall(r'action="[^"]*/reservations/(\d+)/assign"', ws)
    if assigns:
        res = assigns[0]
        data, f = payload(ws, r"/reservations/%s/assign$" % res)
        if data:
            keep = {k: v for k, v in data.items() if k.startswith("assign[") and v}
            data = dict(keep)
            if data:
                fl, d = submit(p, "/reservations/%s/assign" % res, data,
                               action_pattern=r"/reservations/%s/assign$" % res,
                               read="/reservations/workspace", ok_words=("assigned", "room", "cannot", "clash"))
                record("Reservations", "workspace: assign a room", bool(fl), d)
            else:
                record("Reservations", "workspace: assign a room", False, "assign form had no room selected")
        else:
            record("Reservations", "workspace: assign a room", False, "no assign form found")
    else:
        record("Reservations", "workspace: assign a room", True, "nothing unassigned on the board today (no-op)")

    # ───────────────────────── Central Reservations ─────────────────────────
    cro_page = p.get("/cro")
    data, f = payload(cro_page, r"/cro/enquiries$", {
        "caller_name": "Audit Caller", "phone": "+233244555666", "email": "audit@example.com",
        "arrival": day(2), "departure": day(4), "rooms": "1", "adults": "2", "children": "0",
        "quoted_rate": "950", "duration_min": "7", "outcome": "open",
        "notes": "Audit probe — asked for a deluxe room over the weekend"})
    if data:
        opts = [x for x in (f.get("room_type_id__options") or []) if x]
        if opts:
            data["room_type_id"] = opts[0]
        data.pop("_token", None)
        fl, d = submit(p, "/cro/enquiries", data, action_pattern=r"/cro/enquiries$", read="/cro",
                       ok_words=("logged", "enquiry"))
        ok = bool(fl) and any("logged" in x.lower() for x in fl)
        record("Central Reservations", "enquiry: log", ok, d)
        m = re.search(r"/cro/enquiries/(\d+)", flows_lib.LAST_LOC or "")
        eid = m.group(1) if m else None
        if not eid:
            ids = re.findall(r"/cro/enquiries/(\d+)", p.get("/cro/enquiries"))
            eid = ids[-1] if ids else None
        if eid:
            ep = "/cro/enquiries/%s" % eid
            fl, d = submit_form(p, "/cro/enquiries/%s/contact" % eid, r"/cro/enquiries/%s/contact$" % eid,
                                read=ep, overrides={"note": "Audit probe — called back, guest is deciding",
                                                    "follow_up_at": ""}, ok_words=("recorded", "contact"))
            record("Central Reservations", "enquiry: record a contact", bool(fl) and not refused(fl), d)
            # convert: the desk's Book form, with the enquiry id attached
            data, f = payload(cro_page, r"/cro/book$", {
                "enquiry_id": eid, "hotel_id": "1", "asked_hotel_id": "1",
                "first_name": "Audit", "last_name": "Booking", "phone": "+233244555666",
                "email": "audit@example.com", "nationality": "Ghanaian",
                "arrival": day(2), "departure": day(4), "rooms": "1", "adults": "2", "children": "0",
                "status": "confirmed", "channel": "phone"})
            if data:
                rt = [x for x in (f.get("room_type_id__options") or []) if x]
                data["room_type_id"] = rt[0] if rt else (data.get("room_type_id") or "1")
                data.pop("_token", None)
                fl, d = submit(p, "/cro/book", data, action_pattern=r"/cro/book$", read="/cro",
                               ok_words=("booked", "reservation", "referred", "cannot", "sold"))
                record("Central Reservations", "enquiry: convert to a reservation", bool(fl), d)
        else:
            record("Central Reservations", "enquiry: record a contact", False, "could not find the logged enquiry")
    else:
        record("Central Reservations", "enquiry: log", False, "no enquiry form on /cro")

    # a second enquiry closed as lost (feeds Lost Business + CRO Performance)
    data, f = payload(cro_page, r"/cro/enquiries$", {
        "caller_name": "Audit Lost Caller", "phone": "+233244777888", "arrival": day(5),
        "departure": day(7), "rooms": "1", "adults": "2", "outcome": "lost",
        "loss_reason": "price", "loss_note": "Audit probe — found a cheaper rate elsewhere",
        "notes": "Audit probe"})
    if data:
        data.pop("_token", None)
        fl, d = submit(p, "/cro/enquiries", data, action_pattern=r"/cro/enquiries$", read="/cro",
                       ok_words=("lost", "logged"))
        record("Central Reservations", "enquiry: close as lost business", bool(fl) and any("lost" in x.lower() for x in fl), d)

    fl, d = submit_form(p, "/cro/settings", r"/cro/settings$", read="/cro/settings",
                        overrides={"follow_up_hours": "24", "expire_days": "14", "conversion_target": "40"},
                        ok_words=("saved",))
    record("Central Reservations", "settings: save", bool(fl) and any("saved" in x.lower() for x in fl or []), d)

    # ─────────────────────────────── Concierge ───────────────────────────────
    new = "/concierge/bookings/new"
    html = p.get(new)
    data, f = payload(html, r"/concierge/bookings$", {
        "date": day(1), "adults": "2", "children": "0", "reservation_room_id": str(long_stay),
        "collection": "folio", "source": "desk", "notes": "Audit probe booking"})
    if data:
        acts = [x for x in (f.get("activity_id__options") or []) if x]
        if acts:
            data["activity_id"] = acts[0]
        times = f.get("time")
        if not times:
            data["time"] = "09:00"
        data.pop("_token", None)
        fl, d = submit(p, "/concierge/bookings", data, action_pattern=r"/concierge/bookings$", read=new,
                       ok_words=("booked", "requested", "created", "waiting", "cannot", "full"))
        record("Concierge", "booking: create for an in-house guest", bool(fl) and not refused(fl), d)
        m = re.search(r"/concierge/bookings/(\d+)", flows_lib.LAST_LOC or "")
        bid = m.group(1) if m else None
        if not bid:
            ids = re.findall(r"/concierge/bookings/(\d+)", p.get("/concierge/bookings"))
            bid = ids[-1] if ids else None
        if bid:
            bp = "/concierge/bookings/%s" % bid
            for label, act, words in [("confirm with the vendor", "confirm", ("confirmed", "already")),
                                      ("complete the activity", "complete", ("completed", "already", "cannot")),
                                      ("send the voucher", "send", ("sent", "voucher", "cannot"))]:
                fl, d = submit_form(p, "/concierge/bookings/%s/%s" % (bid, act),
                                    r"/concierge/bookings/%s/%s$" % (bid, act), read=bp, ok_words=words)
                record("Concierge", "booking: %s" % label, bool(fl), d)
        else:
            record("Concierge", "booking: lifecycle", False, "could not find the new booking")
    else:
        record("Concierge", "booking: create for an in-house guest", False, "no booking form on %s" % new)

    # vendor settlement (+ void)
    st = "/concierge/settlements"
    html = p.get(st)
    data, f = payload(html, r"/concierge/settlements$", {"up_to": today, "date": today,
                                                         "reference": "AUDIT-SETTLE-1"})
    if data:
        v = [x for x in (f.get("activity_vendor_id__options") or []) if x]
        if v:
            data["activity_vendor_id"] = v[0]
        data.pop("_token", None)
        fl, d = submit(p, "/concierge/settlements", data, action_pattern=r"/concierge/settlements$", read=st,
                       ok_words=("posted", "settlement", "nothing", "no "))
        record("Concierge", "settlement: settle with a vendor", bool(fl) and not refused(fl), d)
        m = re.search(r"/concierge/settlements/(\d+)", flows_lib.LAST_LOC or "")
        sid = m.group(1) if m else None
        if sid:
            fl, d = submit_form(p, "/concierge/settlements/%s/void" % sid,
                                r"/concierge/settlements/%s/void$" % sid,
                                read="/concierge/settlements/%s" % sid,
                                overrides={"reason": "Audit probe — reversing the test settlement"},
                                ok_words=("void", "reversed", "cannot"))
            record("Concierge", "settlement: void", bool(fl), d)
        else:
            record("Concierge", "settlement: void", True, "nothing to settle yet (no settlement created)")
    else:
        record("Concierge", "settlement: settle with a vendor", False, "no settlement form on %s" % st)

    # activity + guide place + settings (master data)
    cat = "/concierge/catalogue"
    html = p.get(cat)
    data, f = payload(html, r"/concierge/activities$", {
        "code": "AUD" + day(0).replace("-", "")[4:], "name": "Audit — Accra night market walk",
        "duration_min": "120", "pricing": "person", "price_adult": "180", "price_child": "90",
        "min_pax": "1", "schedule": "scheduled", "times": "17:30", "cutoff_hours": "6",
        "free_cancel_hours": "12", "cancel_fee_pct": "50", "commission_pct": "10",
        "collection": "folio", "is_active": "1", "portal_visible": "1"})
    if data:
        v = [x for x in (f.get("activity_vendor_id__options") or []) if x]
        if v:
            data["activity_vendor_id"] = v[0]
        cats = [x for x in (f.get("category__options") or []) if x]
        if cats:
            data["category"] = cats[0]
        data.pop("_token", None)
        fl, d = submit(p, "/concierge/activities", data, action_pattern=r"/concierge/activities$", read=cat,
                       ok_words=("added", "activity"))
        record("Concierge", "catalogue: add an activity", bool(fl) and any("added" in x.lower() for x in fl), d)
    else:
        record("Concierge", "catalogue: add an activity", False, "no activity form on %s" % cat)

    guide = "/concierge/guide"
    fl, d = submit_form(p, "/concierge/guide", r"/concierge/guide$", read=guide,
                        overrides={"name": "Audit — Kwame Nkrumah Mausoleum", "area": "Accra CBD",
                                   "address": "Ridge, Accra", "phone": "+233302222222",
                                   "distance_km": "4.5", "hours": "08:00-18:00", "price_level": "1",
                                   "description": "Added by the completeness audit",
                                   "tip": "Ask about the guided tour", "portal_visible": "1", "sort_order": "99"},
                        ok_words=("added", "guide"))
    record("Concierge", "local guide: add a place", bool(fl) and any("added" in x.lower() for x in fl or []), d)

    fl, d = submit_form(p, "/concierge/settings", r"/concierge/settings$", read="/concierge/settings",
                        overrides={"portal_lead": "2", "confirm_hours": "12", "desk_phone": "+233302700700"},
                        ok_words=("saved",))
    record("Concierge", "settings: save", bool(fl) and any("saved" in x.lower() for x in fl or []), d)

    save("flows_b.json")


if __name__ == "__main__":
    main()
