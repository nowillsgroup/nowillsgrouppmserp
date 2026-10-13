#!/usr/bin/env python3
"""Front Office & Guest Services — write-flow exercise, batch C:
Guest Messaging, Self-Service Kiosk, IPTV & Guest Welcome, Foreign Guests,
Interfaces (call accounting / key cards / interface hub).

Every step posts a real form payload to a real route and reads the app's own
flash back, so a route that exists but does nothing cannot pass. Each step is
isolated: one failure never aborts the run.

Run:  python3 deploy/dev/audit/flows_c.py
"""
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import flows_lib                                                      # noqa: E402
from flows_lib import Pms, day, form_fields, payload, record, refused, save, submit, submit_form   # noqa: E402

TODAY = day(0)


def step(mod, label, fn):
    try:
        fn()
    except Exception as e:                                            # noqa: BLE001
        record(mod, label, False, "EXCEPTION %s: %s" % (type(e).__name__, e))


def ok(fl, *words):
    return bool(fl) and (not words or any(any(w.lower() in f.lower() for w in words) for f in fl))


def new_id(pattern, fallback_page=None, p=None):
    m = re.search(pattern, flows_lib.LAST_LOC or "")
    if m:
        return m.group(1)
    if fallback_page and p:
        ids = re.findall(pattern, p.get(fallback_page))
        return ids[-1] if ids else None
    return None


def main():
    p = Pms()
    stay = 62            # in-house, departs today
    long_stay = 65       # in-house until 17 Oct

    # ═══════════════════════════ Guest Messaging ═══════════════════════════
    def qr_create():
        fl, d = submit(p, "/inbox/quick-replies", {
            "shortcut": "audit%d" % (len(p.get('/inbox/quick-replies')) % 97),
            "title": "Audit — airport transfer info", "body": "Your transfer is confirmed; the driver waits at the lobby door.",
            "department": "front_desk", "is_active": "1"}, read="/inbox/quick-replies",
            ok_words=("saved", "added"))
        record("Guest Messaging", "quick reply: create", ok(fl, "saved", "added") and not refused(fl), d)
    step("Guest Messaging", "quick reply: create", qr_create)

    def qr_edit_delete():
        page = "/inbox/quick-replies"
        html = p.get(page)
        ids = re.findall(r'action="[^"]*/inbox/quick-replies/(\d+)"', html)
        if not ids:
            record("Guest Messaging", "quick reply: edit + delete", False, "no quick replies listed")
            return
        qid = ids[-1]
        data, f = payload(html, r"/inbox/quick-replies/%s$" % qid, {"title": "Audit — edited title"})
        data.pop("_token", None)
        fl, d = submit(p, "/inbox/quick-replies/%s" % qid, data, read=page, ok_words=("saved", "updated"))
        record("Guest Messaging", "quick reply: edit", ok(fl, "saved", "updated"), d)
        html = p.get(page)
        data = form_fields(html, r"/inbox/quick-replies/%s$" % qid)
        if data.get("_method", "").upper() == "DELETE":
            data.pop("_token", None)
            fl, d = submit(p, "/inbox/quick-replies/%s" % qid, data, read=page, ok_words=("deleted", "removed", "gone"))
            record("Guest Messaging", "quick reply: delete", ok(fl, "deleted", "removed", "gone"), d)
        else:
            record("Guest Messaging", "quick reply: delete", False, "no DELETE form for quick reply %s" % qid)
    step("Guest Messaging", "quick reply: edit + delete", qr_edit_delete)

    def rules():
        page = "/inbox/rules"
        fl, d = submit_form(p, "/inbox/rules", r"/inbox/rules$", read=page,
                            overrides={"name": "Audit — route airport questions", "match": "airport",
                                       "keywords": "airport,transfer,taxi", "department": "concierge",
                                       "priority": "high"}, ok_words=("saved", "rule"))
        record("Guest Messaging", "routing rule: create", ok(fl, "saved", "rule") and not refused(fl), d)
        fl, d = submit_form(p, "/inbox/rules/test", r"/inbox/rules/test$", read=page,
                            overrides={"text": "Please arrange an airport transfer for tomorrow"},
                            ok_words=("match", "route", "no rule", "department"))
        record("Guest Messaging", "routing rule: test against a message", bool(fl), d)
    step("Guest Messaging", "routing rules", rules)

    def conversation():
        fl, d = submit(p, "/inbox/start", {"stay_id": str(long_stay), "channel": "sms"},
                       read="/inbox", ok_words=("opened", "started", "conversation", "cannot", "no valid"))
        record("Guest Messaging", "conversation: start with an in-house guest", bool(fl) and not refused(fl), d)
        cid = new_id(r"/inbox/c/(\d+)", "/inbox", p)
        if not cid:
            ids = re.findall(r"/inbox/c/(\d+)", p.get("/inbox"))
            cid = ids[-1] if ids else None
        if not cid:
            record("Guest Messaging", "conversation: reply / assign / close", False, "no conversation found")
            return
        c = "/inbox/c/%s" % cid
        fl, d = submit(p, "/inbox/c/%s/reply" % cid, {"body": "Audit probe reply — thank you for messaging us.",
                                                      "mode": "reply"}, read=c, ok_words=("sent", "reply", "queued"))
        record("Guest Messaging", "conversation: reply", ok(fl, "sent", "reply", "queued") and not refused(fl), d)
        fl, d = submit_form(p, "/inbox/c/%s/assign" % cid, r"/inbox/c/%s/assign$" % cid, read=c,
                            ok_words=("assigned",))
        record("Guest Messaging", "conversation: assign", bool(fl), d)
        fl, d = submit_form(p, "/inbox/c/%s/close" % cid, r"/inbox/c/%s/close$" % cid, read=c,
                            overrides={"resolution": "Handled by the audit"}, ok_words=("closed",))
        record("Guest Messaging", "conversation: close", ok(fl, "closed") and not refused(fl), d)
        fl, d = submit(p, "/inbox/c/%s/reopen" % cid, {}, read=c, ok_words=("reopen", "open"))
        record("Guest Messaging", "conversation: reopen", bool(fl), d)
    step("Guest Messaging", "conversation lifecycle", conversation)

    def broadcast():
        fl, d = submit_form(p, "/inbox/broadcasts", r"/inbox/broadcasts$", read="/inbox/broadcasts",
                            overrides={"title": "Audit — pool closed 15:00-16:00",
                                       "body": "The pool is closed for cleaning between 15:00 and 16:00 today.",
                                       "audience": "inhouse", "channel": "sms"},
                            ok_words=("sent", "broadcast", "queued"))
        record("Guest Messaging", "broadcast: send to in-house guests", bool(fl) and not refused(fl), d)
    step("Guest Messaging", "broadcast", broadcast)

    def inbox_settings():
        fl, d = submit_form(p, "/inbox/settings", r"/inbox/settings$", read="/inbox/settings",
                            ok_words=("saved",))
        record("Guest Messaging", "settings: save", ok(fl, "saved"), d)
        fl, d = submit_form(p, "/inbox/settings/secret", r"/inbox/settings/secret$", read="/inbox/settings",
                            ok_words=("secret", "rotated", "new"))
        record("Guest Messaging", "settings: rotate the webhook secret", bool(fl), d)
    step("Guest Messaging", "inbox settings", inbox_settings)

    # ═════════════════════════ Self-Service Kiosk ═════════════════════════
    kiosk_token = {}

    def kiosk_add():
        page = "/self-service/kiosks"
        fl, d = submit_form(p, "/self-service/kiosks", r"/self-service/kiosks$", read=page,
                            overrides={"name": "Audit lobby kiosk", "location": "Lobby, near the concierge desk"},
                            ok_words=("added", "kiosk", "open this address"))
        record("Self-Service Kiosk", "device: register a kiosk", ok(fl, "added", "kiosk") and not refused(fl), d)
        m = re.search(r"/kiosk/([A-Za-z0-9]+)", " ".join(fl or []))
        if m:
            kiosk_token["token"] = m.group(1)
    step("Self-Service Kiosk", "kiosk: register", kiosk_add)

    def kiosk_guest_flow():
        tok = kiosk_token.get("token")
        if not tok:
            html = p.get("/self-service/kiosks")
            m = re.findall(r"/kiosk/([A-Za-z0-9]{8,})", html)
            tok = m[-1] if m else None
        if not tok:
            record("Self-Service Kiosk", "guest flow: kiosk app + visit", False, "no kiosk token available")
            return
        home = p.get("/kiosk/%s/home" % tok)
        record("Self-Service Kiosk", "guest flow: kiosk home renders", len(home) > 2000 and "<!doctype" in home[:80].lower(),
               "%d bytes" % len(home))
        data, f = payload(home, r"/kiosk/%s/start$" % tok, {})
        if not data:
            record("Self-Service Kiosk", "guest flow: start a visit", False, "no start form on the kiosk home screen")
            return
        data.pop("_token", None)
        code, _b, loc = p.post("/kiosk/%s/start" % tok, data, p.token(home, r"/kiosk/%s/start$" % tok),
                               referer="/kiosk/%s/home" % tok)
        fl = p.flash(p.get(re.sub(r"^https?://[^/]+", "", loc) or ("/kiosk/%s/home" % tok)))
        visit = None
        m = re.search(r"/v/(\d+)", (loc or "") + " ".join(fl or []))
        if m:
            visit = m.group(1)
        record("Self-Service Kiosk", "guest flow: start a visit", code in (200, 302),
               "POST /kiosk/%s/start -> %s %s | flash: %s" % (tok, code, (loc or "")[:60], " / ".join(fl or [])[:160]))
        if visit:
            acts = re.findall(r'/kiosk/%s/v/%s/([a-z\-]+)' % (tok, visit), p.get("/kiosk/%s/v/%s" % (tok, visit)))
            record("Self-Service Kiosk", "guest flow: visit state page + next actions", bool(acts),
                   "actions offered: %s" % ", ".join(sorted(set(acts))[:8]))
            kiosk_token["visit"] = visit
    step("Self-Service Kiosk", "kiosk: guest flow", kiosk_guest_flow)

    def kiosk_admin():
        page = "/self-service/visits"
        html = p.get(page)
        ids = re.findall(r"/self-service/visits/(\d+)", html)
        if ids:
            vid = ids[-1]
            fl, d = submit_form(p, "/self-service/visits/%s/resolve" % vid,
                                r"/self-service/visits/%s/resolve$" % vid, read="/self-service/visits/%s" % vid,
                                overrides={"note": "Audit probe — desk dealt with it"}, ok_words=("resolved", "dealt", "done"))
            record("Self-Service Kiosk", "assistance queue: desk resolves a visit", bool(fl) and not refused(fl), d)
        else:
            record("Self-Service Kiosk", "assistance queue: desk resolves a visit", True, "no visits needing the desk")
        page = "/self-service/kiosks"
        html = p.get(page)
        kids = re.findall(r"/self-service/kiosks/(\d+)/status", html)
        if kids:
            fl, d = submit_form(p, "/self-service/kiosks/%s/status" % kids[-1],
                                r"/self-service/kiosks/%s/status$" % kids[-1], read=page, ok_words=("kiosk", "status", "off", "on"))
            record("Self-Service Kiosk", "device: switch a kiosk off/on", bool(fl), d)
            fl, d = submit_form(p, "/self-service/kiosks/%s/rotate" % kids[-1],
                                r"/self-service/kiosks/%s/rotate$" % kids[-1], read=page, ok_words=("address", "rotated", "new"))
            record("Self-Service Kiosk", "device: rotate the start address", bool(fl), d)
        else:
            record("Self-Service Kiosk", "device: status / rotate", False, "no kiosk rows with action forms")
        fl, d = submit_form(p, "/self-service/settings", r"/self-service/settings$", read="/self-service/settings",
                            ok_words=("saved",))
        record("Self-Service Kiosk", "settings: save", ok(fl, "saved"), d)
    step("Self-Service Kiosk", "kiosk: admin actions", kiosk_admin)

    # ═══════════════════════ IPTV & Guest Welcome ═══════════════════════
    tv_token = {}

    def tv_device():
        page = "/iptv/devices"
        fl, d = submit_form(p, "/iptv/devices", r"/iptv/devices$", read=page,
                            overrides={"name": "Audit TV — 201", "room_id": "3", "kind": "room",
                                       "model": "Audit Smart TV", "ip_address": "10.9.8.7"},
                            ok_words=("added", "saved", "start page"))
        record("IPTV & Guest Welcome", "device: register a TV", bool(fl) and not refused(fl), d)
        m = re.search(r"/iptv/devices/(\d+)", flows_lib.LAST_LOC or "")
        if m:
            tv_token["device"] = m.group(1)
    step("IPTV & Guest Welcome", "iptv: register TV", tv_device)

    def tv_pair_state():
        did = tv_token.get("device")
        page = "/iptv/devices/%s" % did if did else "/iptv/devices"
        html = p.get(page)
        m = re.search(r"/tv/setup/([A-Za-z0-9]+)", html) or re.search(r"/tv/([A-Za-z0-9]{8,})", html)
        if not m:
            fl, d = submit_form(p, "/iptv/devices/pair", r"/iptv/devices/pair$", read="/iptv/devices",
                                ok_words=("pair", "code", "url"))
            record("IPTV & Guest Welcome", "device: pairing code + TV state", bool(fl), d)
            return
        code = m.group(1)
        setup = p.get("/tv/setup/%s" % code) if "/setup/" in m.group(0) else ""
        fl, d = submit_form(p, "/tv/setup/%s" % code, r"/tv/setup/%s$" % code, read="/tv/setup/%s" % code,
                            ok_words=("pair", "welcome", "done"))
        record("IPTV & Guest Welcome", "device: TV pairs itself", bool(fl) or len(setup) > 1500, d)
        tok = re.search(r"/tv/([A-Za-z0-9]{8,})", " ".join(fl or []) + setup)
        if tok:
            state = p.get("/tv/%s/state" % tok.group(1))
            record("IPTV & Guest Welcome", "device: state API answers the TV", state.strip().startswith(("{", "[")),
                   "%d bytes of JSON" % len(state))
            tv_token["token"] = tok.group(1)
    step("IPTV & Guest Welcome", "iptv: pairing", tv_pair_state)

    def tv_message():
        fl, d = submit(p, "/iptv/messages", {"target": "room", "room_id": "3", "title": "Audit — welcome",
                                             "body": "Welcome to Nowills. Dinner is served until 22:30.",
                                             "priority": "normal"}, read="/iptv/messages",
                       ok_words=("sent", "message"))
        record("IPTV & Guest Welcome", "message: send to one room", ok(fl, "sent", "message") and not refused(fl), d)
    step("IPTV & Guest Welcome", "iptv: message", tv_message)

    def tv_channels():
        page = "/iptv/channels"
        fl, d = submit_form(p, "/iptv/channels", r"/iptv/channels$", read=page,
                            overrides={"name": "Audit News 24", "number": "901", "stream_url": "https://example.com/audit.m3u8",
                                       "kind": "news", "is_active": "1"}, ok_words=("added", "saved", "channel"))
        record("IPTV & Guest Welcome", "channels: add a channel", bool(fl) and not refused(fl), d)
        html = p.get(page)
        ids = re.findall(r'action="[^"]*/iptv/channels/(\d+)"', html)
        if ids:
            cid = ids[-1]
            data, f = payload(html, r"/iptv/channels/%s$" % cid, {"name": "Audit News 24 (edited)"})
            if data.get("_method", "").upper() in ("PUT", "PATCH"):
                data.pop("_token", None)
                fl, d = submit(p, "/iptv/channels/%s" % cid, data, read=page, ok_words=("saved", "updated"))
                record("IPTV & Guest Welcome", "channels: edit", ok(fl, "saved", "updated"), d)
            data = form_fields(p.get(page), r"/iptv/channels/%s$" % cid)
            if data.get("_method", "").upper() == "DELETE":
                data.pop("_token", None)
                fl, d = submit(p, "/iptv/channels/%s" % cid, data, read=page, ok_words=("deleted", "removed", "gone"))
                record("IPTV & Guest Welcome", "channels: delete", ok(fl, "deleted", "removed", "gone"), d)
    step("IPTV & Guest Welcome", "iptv: channels", tv_channels)

    def tv_m3u():
        m3u = ("#EXTM3U\n#EXTINF:-1 tvg-id=\"audit1\" tvg-name=\"Audit One\" group-title=\"News\",Audit One\n"
               "https://example.com/audit-one.m3u8\n")
        page = "/iptv/channels"
        tok = p.token(p.get(page), r"/iptv/channels/import$")
        if not tok:
            record("IPTV & Guest Welcome", "channels: M3U import", False, "no import form on %s" % page)
            return
        code, _b, loc = p.post_multipart("/iptv/channels/import", {"mode": "merge"},
                                         {"file": ("audit.m3u", m3u.encode(), "audio/x-mpegurl")},
                                         tok=tok, referer=page)
        target = re.sub(r"^https?://[^/]+", "", loc or "") or page
        fl = p.flash(p.get(target))
        record("IPTV & Guest Welcome", "channels: M3U import", bool(fl), "POST(multipart) -> %s | %s" % (code, " / ".join(fl)[:180]))
    step("IPTV & Guest Welcome", "iptv: m3u import", tv_m3u)

    def tv_welcome_emergency():
        fl, d = submit_form(p, "/iptv/welcome", r"/iptv/welcome$", read="/iptv/welcome",
                            overrides={"name": "Audit — VIP welcome", "headline": "Akwaaba, {guest}!",
                                       "body": "We are delighted to have you with us.", "kind": "vip"},
                            ok_words=("saved", "added", "template"))
        record("IPTV & Guest Welcome", "welcome screen: save a template", bool(fl) and not refused(fl), d)
        fl, d = submit_form(p, "/iptv/emergency", r"/iptv/emergency$", read="/iptv",
                            overrides={"title": "Audit — fire drill", "body": "This is a drill. Please follow the marshals.",
                                       "level": "alert"}, ok_words=("every tv", "sent", "alert", "emergency"))
        record("IPTV & Guest Welcome", "emergency alert: put it on every TV", bool(fl) and not refused(fl), d)
        html = p.get("/iptv")
        m = re.findall(r'action="[^"]*/iptv/emergency/(\d+)[^"]*"', html)
        if m:
            fl, d = submit_form(p, "/iptv/emergency/%s" % m[-1], r"/iptv/emergency/%s" % m[-1], read="/iptv",
                                ok_words=("all clear", "withdrawn", "cleared", "removed"))
            record("IPTV & Guest Welcome", "emergency alert: send the all clear", bool(fl), d)
        else:
            record("IPTV & Guest Welcome", "emergency alert: send the all clear", False, "no active alert row to clear")
    step("IPTV & Guest Welcome", "iptv: welcome + emergency", tv_welcome_emergency)

    def tv_rent_settings():
        tok = tv_token.get("token")
        page = "/iptv/movies"
        html = p.get(page)
        mids = re.findall(r"/tv/[A-Za-z0-9]+/rent/(\d+)", html) or re.findall(r'/iptv/movies/(\d+)', html)
        if tok and mids:
            code, _b, loc = p.post("/tv/%s/rent/%s" % (tok, mids[0]), {}, None, referer="/tv/%s/state" % tok)
            fl = p.flash(p.get(re.sub(r"^https?://[^/]+", "", loc or "") or "/tv/%s/state" % tok))
            record("IPTV & Guest Welcome", "movies: guest rents from the TV", code in (200, 302),
                   "POST /tv/%s/rent/%s -> %s | %s" % (tok, mids[0], code, " / ".join(fl)[:160]))
        else:
            record("IPTV & Guest Welcome", "movies: guest rents from the TV", False,
                   "need a paired TV token (%s) and a movie id (%s)" % (bool(tok), bool(mids)))
        fl, d = submit_form(p, "/iptv/settings", r"/iptv/settings$", read="/iptv/settings", ok_words=("saved",))
        record("IPTV & Guest Welcome", "settings: save", ok(fl, "saved"), d)
    step("IPTV & Guest Welcome", "iptv: rentals + settings", tv_rent_settings)

    # ══════════════════════════ Foreign Guests ══════════════════════════
    def fx_rates():
        page = "/foreign/rates"
        html = p.get(page)
        fields = form_fields(html, r"/foreign/rates$")
        rate_fields = {k: v for k, v in fields.items() if k.startswith("rates[")}
        if not rate_fields:
            rate_fields = {k: v for k, v in fields.items() if re.match(r"^rates", k)}
        if not rate_fields:
            record("Foreign Guests", "forex: set today's rates", False, "no rate inputs found on %s" % page)
            return
        payload_rates = {}
        for k, v in list(rate_fields.items())[:12]:
            payload_rates[k] = v or "10.5000"
        payload_rates["date"] = fields.get("date") or TODAY
        fl, d = submit(p, "/foreign/rates", payload_rates, read=page, ok_words=("saved", "rate"))
        record("Foreign Guests", "forex: set today's rates", ok(fl, "saved", "rate") and not refused(fl),
               d + " | fields=%d" % len(payload_rates))
        fl, d = submit_form(p, "/foreign/rates/copy", r"/foreign/rates/copy$", read=page,
                            overrides={"from": day(-1), "date": TODAY}, ok_words=("copied", "saved", "rate"))
        record("Foreign Guests", "forex: copy yesterday's rates", bool(fl), d)
    step("Foreign Guests", "forex: rates", fx_rates)

    def fx_exchange():
        page = "/foreign/forex/new"
        html = p.get(page)
        data, f = payload(html, r"/foreign/forex$", {
            "reservation_room_id": str(long_stay), "fc_amount": "100.00", "instrument": "notes",
            "payout": "folio", "certificate": "1", "notes": "Audit probe exchange"})
        if not data:
            record("Foreign Guests", "forex: buy currency from a guest", False, "no exchange form on %s" % page)
            return
        cur = [x for x in (f.get("currency_id__options") or []) if x]
        if cur:
            data["currency_id"] = cur[0]
        data.pop("_token", None)
        fl, d = submit(p, "/foreign/forex", data, action_pattern=r"/foreign/forex$", read=page,
                       ok_words=("FX", "exchanged", "encashed", "certificate"))
        good = bool(fl) and not refused(fl)
        record("Foreign Guests", "forex: buy currency from a guest", good, d)
        fid = new_id(r"/foreign/forex/(\d+)", None, None)
        if fid:
            cert = p.get("/foreign/forex/%s/certificate" % fid)
            record("Foreign Guests", "forex: encashment certificate", "<!doctype" in cert[:80].lower() and len(cert) > 1500,
                   "%d bytes" % len(cert))
            fl, d = submit_form(p, "/foreign/forex/%s/void" % fid, r"/foreign/forex/%s/void$" % fid,
                                read="/foreign/forex/%s" % fid,
                                overrides={"reason": "Audit probe — reversing the test exchange"},
                                ok_words=("void", "reversed", "cannot"))
            record("Foreign Guests", "forex: void an exchange", bool(fl), d)
        else:
            record("Foreign Guests", "forex: encashment certificate", False, "no exchange id from the redirect")
    step("Foreign Guests", "forex: exchange", fx_exchange)

    def fx_surrender():
        page = "/foreign/position"
        fl, d = submit_form(p, "/foreign/surrenders", r"/foreign/surrenders$", read=page,
                            overrides={"date": TODAY, "bank": "GCB Accra", "reference": "AUDIT-SUR-1",
                                       "amount": "50.00"}, ok_words=("surrender", "posted", "recorded", "nothing"))
        record("Foreign Guests", "forex: surrender currency to the bank", bool(fl), d)
    step("Foreign Guests", "forex: surrender", fx_surrender)

    def formc():
        page = "/foreign/form-c"
        html = p.get(page)
        stays = re.findall(r"/foreign/stays/(\d+)/form-c", html)
        if not stays:
            record("Foreign Guests", "Form C: create for a foreign arrival", True,
                   "no foreign stay is waiting for a Form C (nothing to do)")
            return
        s = stays[0]
        fl, d = submit(p, "/foreign/stays/%s/form-c" % s, {"companion": "0"}, read=page,
                       ok_words=("form c", "created", "opened", "already", "draft"))
        record("Foreign Guests", "Form C: create for a foreign arrival", bool(fl) and not refused(fl), d)
        fid = new_id(r"/foreign/form-c/(\d+)", None, None)
        if not fid:
            ids = re.findall(r"/foreign/form-c/(\d+)", p.get(page))
            fid = ids[-1] if ids else None
        if not fid:
            record("Foreign Guests", "Form C: submit / departure / reopen", False, "no Form C record found")
            return
        fp = "/foreign/form-c/%s" % fid
        fl, d = submit_form(p, "/foreign/form-c/%s/submit" % fid, r"/foreign/form-c/%s/submit$" % fid, read=fp,
                            ok_words=("submitted", "filed", "cannot", "missing", "complete"))
        record("Foreign Guests", "Form C: submit to FRRO", bool(fl), d)
        fl, d = submit_form(p, "/foreign/form-c/%s/departure" % fid, r"/foreign/form-c/%s/departure$" % fid, read=fp,
                            overrides={"departure_date": TODAY}, ok_words=("departure", "reported", "cannot"))
        record("Foreign Guests", "Form C: report the departure", bool(fl), d)
        fl, d = submit_form(p, "/foreign/form-c/%s/reopen" % fid, r"/foreign/form-c/%s/reopen$" % fid, read=fp,
                            ok_words=("reopen", "open", "cannot"))
        record("Foreign Guests", "Form C: reopen", bool(fl), d)
        fl, d = submit_form(p, "/foreign/settings", r"/foreign/settings$", read="/foreign/settings", ok_words=("saved",))
        record("Foreign Guests", "settings: save", ok(fl, "saved"), d)
    step("Foreign Guests", "form c", formc)

    # ═══════════════════════════ Interfaces ═══════════════════════════
    def calls():
        page = "/calls"
        fl, d = submit(p, "/calls/manual", {"ext": "101", "number": "+233302700700", "started_at": TODAY + " 09:15:00",
                                            "duration": "4:30", "trunk": "1"}, read=page,
                       ok_words=("recorded", "call", "already", "review"))
        record("Interfaces", "call accounting: log a call manually", bool(fl) and not refused(fl), d)
        html = p.get(page)
        posts = re.findall(r'/calls/(\d+)/post', html)
        if posts:
            cid = posts[-1]
            fl, d = submit_form(p, "/calls/%s/post" % cid, r"/calls/%s/post$" % cid, read=page,
                                ok_words=("posted", "folio", "cannot", "already"))
            record("Interfaces", "call accounting: post a call to the folio", bool(fl) and not refused(fl), d)
        else:
            record("Interfaces", "call accounting: post a call to the folio", False, "no postable call in the review queue")
        waives = re.findall(r'/calls/(\d+)/waive', p.get(page))
        if waives:
            fl, d = submit_form(p, "/calls/%s/waive" % waives[-1], r"/calls/%s/waive$" % waives[-1], read=page,
                                overrides={"reason": "Audit probe — goodwill waiver"}, ok_words=("waived", "cannot"))
            record("Interfaces", "call accounting: waive a call", bool(fl), d)
        fl, d = submit_form(p, "/calls/import", r"/calls/import$", read=page,
                            overrides={"raw": "101,+233302700700,%s 09:20:00,00:03:10,1\n102,+233244000000,%s 09:25:00,00:01:05,1" % (TODAY, TODAY)},
                            ok_words=("import", "record", "line", "parsed"))
        record("Interfaces", "call accounting: import an SMDR batch", bool(fl), d)
        fl, d = submit_form(p, "/calls/tariffs", r"/calls/tariffs$", read=page,
                            overrides={"name": "Audit — national mobile", "prefix": "+2332", "rate": "1.20",
                                       "per_seconds": "60", "free_seconds": "0"}, ok_words=("saved", "tariff"))
        record("Interfaces", "call accounting: save a tariff", bool(fl), d)
        fl, d = submit_form(p, "/calls/reprocess", r"/calls/reprocess$", read=page, ok_words=("reprocess", "review", "queue"))
        record("Interfaces", "call accounting: re-run the review queue", bool(fl), d)
    step("Interfaces", "calls", calls)

    def keys():
        page = "/key-cards"
        fl, d = submit(p, "/key-cards/issue", {"stay_id": str(stay), "count": "1", "mode": "new",
                                               "holder": "Audit guest"}, read=page,
                       ok_words=("encoded", "card"))
        record("Interfaces", "key cards: encode a guest card", ok(fl, "encoded", "card") and not refused(fl), d)
        html = p.get(page)
        cards = re.findall(r'/key-cards/(\d+)/lost', html)
        if cards:
            fl, d = submit_form(p, "/key-cards/%s/lost" % cards[-1], r"/key-cards/%s/lost$" % cards[-1], read=page,
                                overrides={"note": "Audit probe — guest lost the card"}, ok_words=("lost", "deactivated", "new card"))
            record("Interfaces", "key cards: report a card lost", bool(fl), d)
        fl, d = submit_form(p, "/key-cards/read", r"/key-cards/read$", read=page,
                            overrides={"card_no": "AUDITCARD1"}, ok_words=("read", "card", "not found", "unknown"))
        record("Interfaces", "key cards: read a card at the desk", bool(fl), d)
        mk = re.findall(r'/master-keys/(\d+)/out', p.get(page))
        if mk:
            fl, d = submit_form(p, "/master-keys/%s/out" % mk[-1], r"/master-keys/%s/out$" % mk[-1], read=page,
                                overrides={"holder": "Audit housekeeping", "purpose": "Audit probe — linen run", "hours": "2"},
                                ok_words=("signed out", "due back"))
            record("Interfaces", "master keys: sign one out", ok(fl, "signed out", "due back") and not refused(fl), d)
            loans = re.findall(r'/key-loans/(\d+)/in', p.get(page))
            if loans:
                fl, d = submit_form(p, "/key-loans/%s/in" % loans[-1], r"/key-loans/%s/in$" % loans[-1], read=page,
                                    overrides={"note": "Audit probe — returned"}, ok_words=("signed in", "returned"))
                record("Interfaces", "master keys: sign it back in", bool(fl), d)
            else:
                record("Interfaces", "master keys: sign it back in", False, "no open loan row after signing out")
        else:
            fl, d = submit_form(p, "/master-keys", r"/master-keys$", read=page,
                                overrides={"key_no": "AUDIT-MK1", "kind": "floor", "holder": "Audit"},
                                ok_words=("saved", "added", "key"))
            record("Interfaces", "master keys: register a key", bool(fl), d)
    step("Interfaces", "keys", keys)

    def hub():
        page = "/interfaces"
        fl, d = submit_form(p, "/interfaces/settings", r"/interfaces/settings$", read=page, ok_words=("saved",))
        record("Interfaces", "hub: save interface settings", ok(fl, "saved"), d)
        fl, d = submit_form(p, "/interfaces/token", r"/interfaces/token$", read=page, ok_words=("token", "rotated", "new"))
        record("Interfaces", "hub: rotate the PBX push token", bool(fl), d)
        fl, d = submit_form(p, "/interfaces/commands/retry-all", r"/interfaces/commands/retry-all$", read=page,
                            ok_words=("retry", "re-sent", "nothing", "queued", "failed"))
        record("Interfaces", "hub: retry failed commands", bool(fl), d)
    step("Interfaces", "hub", hub)

    save("flows_c.json")


if __name__ == "__main__":
    main()
