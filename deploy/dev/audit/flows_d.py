#!/usr/bin/env python3
"""Front Office & Guest Services — write-flow exercise, batch D: the device-facing
and JS-driven corners that batch C could not reach with static form scraping
(kiosk guest flow, IPTV guest/TV side, conversation handling, call review queue,
key-card and master-key desk actions).

Run:  python3 deploy/dev/audit/flows_d.py
"""
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import flows_lib                                                      # noqa: E402
from flows_lib import Pms, day, form_fields, payload, record, refused, save, submit, submit_form   # noqa: E402

TODAY = day(0)


def post_direct(p, path, data, referer, ok_words=()):
    """POST to a route whose form is built by JS (action="#" in the markup)."""
    html = p.get(referer)
    tok = p.token(html)
    if not tok:
        return None, "no CSRF token on %s" % referer
    code, _b, loc = p.post(path, data, tok, referer=referer)
    target = re.sub(r"^https?://[^/]+", "", loc or "") or referer
    if target.split("?")[0] == path.split("?")[0]:
        target = referer
    fl = p.flash(p.get(target))
    return fl, "POST %s -> %s %s | flash: %s" % (path, code, target[:56], " / ".join(fl)[:220])


def ok(fl, *words):
    return bool(fl) and (not words or any(any(w.lower() in f.lower() for w in words) for f in fl))


def step(mod, label, fn):
    try:
        fn()
    except Exception as e:                                            # noqa: BLE001
        record(mod, label, False, "EXCEPTION %s: %s" % (type(e).__name__, e))


def main():
    p = Pms()

    # ══════════════════════════ Guest Messaging ══════════════════════════
    def conversation():
        inbox = p.get("/inbox")
        cids = re.findall(r"/inbox/c/(\d+)", inbox)
        if not cids:
            fl, d = submit(p, "/inbox/start", {"stay_id": "65", "channel": "sms"}, read="/inbox",
                           ok_words=("opened", "started", "message"))
            m = re.search(r"[?&]c=(\d+)", flows_lib.LAST_LOC or "")
            cid = m.group(1) if m else None
            record("Guest Messaging", "conversation: start with an in-house guest", bool(cid), d)
        else:
            cid = cids[0]
        if not cid:
            record("Guest Messaging", "conversation: lifecycle", False, "no conversation available")
            return
        c = "/inbox/c/%s" % cid
        fl, d = submit(p, "/inbox/c/%s/reply" % cid, {"body": "Audit probe — thank you, the desk is on it.",
                                                      "mode": "reply"}, read=c, ok_words=("sent", "reply", "queued"))
        record("Guest Messaging", "conversation: reply to the guest", ok(fl, "sent", "reply", "queued") and not refused(fl), d)
        fl, d = submit(p, "/inbox/c/%s/reply" % cid, {"body": "Audit probe — internal note", "mode": "note"},
                       read=c, ok_words=("note",))
        record("Guest Messaging", "conversation: internal note", ok(fl, "note") and not refused(fl), d)
        for label, path, data, words in [
                ("assign to a teammate", "assign", {"user_id": "1"}, ("assigned",)),
                ("set the priority", "priority", {"priority": "high"}, ("priority", "high", "set")),
                ("route to a department", "department", {"department": "housekeeping"}, ("department", "routed", "housekeeping")),
                ("snooze", "snooze", {"until": TODAY + " 18:00"}, ("snooz", "until")),
                ("close", "close", {"resolution": "Audit probe — dealt with"}, ("closed",)),
                ("reopen", "reopen", {}, ("reopen", "open"))]:
            fl, d = post_direct(p, "/inbox/c/%s/%s" % (cid, path), data, c, words)
            record("Guest Messaging", "conversation: %s" % label, bool(fl) and not refused(fl), d)
        fl, d = post_direct(p, "/inbox/c/%s/request" % cid,
                            {"category": "housekeeping", "item": "Clean my room now", "notes": "Raised from the audit conversation"},
                            c, ("request", "raised", "logged"))
        record("Guest Messaging", "conversation: raise a guest request from a message", bool(fl), d)
    step("Guest Messaging", "conversation lifecycle", conversation)

    # ═════════════════════════ Self-Service Kiosk ═════════════════════════
    def kiosk():
        page = "/self-service/kiosks"
        html = p.get(page)
        kids = re.findall(r"/self-service/kiosks/(\d+)/rotate", html)
        if not kids:
            record("Self-Service Kiosk", "guest flow", False, "no kiosk rows on %s" % page)
            return
        fl, d = submit_form(p, "/self-service/kiosks/%s/rotate" % kids[0],
                            r"/self-service/kiosks/%s/rotate$" % kids[0], read=page, ok_words=("address",))
        m = re.search(r"/kiosk/([A-Za-z0-9]{10,})", " ".join(fl or []))
        record("Self-Service Kiosk", "device: rotate the start address", bool(m), d)
        if not m:
            return
        tok = m.group(1)
        home = p.get("/kiosk/%s/home" % tok)
        record("Self-Service Kiosk", "guest flow: kiosk home renders", len(home) > 1200,
               "%d bytes, doctype-first=%s" % (len(home), home.lstrip()[:15].lower().startswith("<!doctype")))
        data, f = payload(home, r"/kiosk/%s/start$" % tok, {})
        if not data:
            fields = sorted(k for k in form_fields(home, r"/kiosk/%s/" % tok) if not k.endswith("__options"))
            record("Self-Service Kiosk", "guest flow: start a visit", False,
                   "no start form; kiosk home fields: %s" % ", ".join(fields[:12]))
            return
        data.pop("_token", None)
        code, _b, loc = p.post("/kiosk/%s/start" % tok, data, p.token(home), referer="/kiosk/%s/home" % tok)
        target = re.sub(r"^https?://[^/]+", "", loc or "")
        fl = p.flash(p.get(target or ("/kiosk/%s/home" % tok)))
        visit = re.search(r"/v/(\d+)", (loc or "") + target + " ".join(fl or []))
        record("Self-Service Kiosk", "guest flow: start a visit", code in (200, 302) and bool(visit),
               "POST /kiosk/%s/start -> %s %s | visit=%s | %s" % (tok, code, target[:50],
                                                                  visit.group(1) if visit else "-", " / ".join(fl)[:120]))
        if visit:
            v = visit.group(1)
            state = p.get("/kiosk/%s/v/%s" % (tok, v))
            acts = sorted(set(re.findall(r"/kiosk/%s/v/%s/([a-z\-]+)" % (tok, v), state)))
            record("Self-Service Kiosk", "guest flow: visit state + next actions", bool(acts),
                   "%d bytes; actions offered: %s" % (len(state), ", ".join(acts[:10])))
            if acts:
                code, _b, loc = p.post("/kiosk/%s/v/%s/%s" % (tok, v, acts[0]), {}, p.token(state),
                                       referer="/kiosk/%s/v/%s" % (tok, v))
                nxt = p.get(re.sub(r"^https?://[^/]+", "", loc or "") or "/kiosk/%s/v/%s" % (tok, v))
                record("Self-Service Kiosk", "guest flow: first action (%s)" % acts[0], code in (200, 302),
                       "POST -> %s | %d bytes | %s" % (code, len(nxt), " / ".join(p.flash(nxt))[:140]))
        # admin side: the assistance queue
        vis = p.get("/self-service/visits")
        vids = re.findall(r"/self-service/visits/(\d+)", vis)
        if vids:
            fl, d = post_direct(p, "/self-service/visits/%s/resolve" % vids[-1],
                                {"note": "Audit probe — desk dealt with it"}, "/self-service/visits/%s" % vids[-1],
                                ("resolved", "dealt", "done", "already"))
            record("Self-Service Kiosk", "assistance queue: desk resolves a visit", bool(fl), d)
        else:
            record("Self-Service Kiosk", "assistance queue: desk resolves a visit", True, "no visits needing the desk")
    step("Self-Service Kiosk", "kiosk guest flow", kiosk)

    # ═══════════════════════ IPTV & Guest Welcome ═══════════════════════
    def iptv():
        dev_page = "/iptv/devices"
        html = p.get(dev_page)
        ids = re.findall(r"/iptv/devices/(\d+)", html)
        tok = None
        for did in ids[:6]:
            m = re.search(r"/tv/([A-Za-z0-9]{20,})", p.get("/iptv/devices/%s" % did))
            if m:
                tok, device = m.group(1), did
                break
        if not tok:
            record("IPTV & Guest Welcome", "guest side: TV app + state API", False, "no paired TV token found")
        else:
            state = p.get("/tv/%s/state" % tok)
            record("IPTV & Guest Welcome", "guest side: TV state API", state.strip().startswith("{"),
                   "%d bytes of JSON for device %s" % (len(state), device))
            app = p.get("/tv/%s" % tok)
            record("IPTV & Guest Welcome", "guest side: TV app shell", app.lstrip()[:15].lower().startswith("<!doctype"),
                   "%d bytes" % len(app))
            code, _b, loc = p.post("/tv/%s/event" % tok, {"event": "audit_probe", "section": "home"}, None,
                                   referer="/tv/%s" % tok)
            record("IPTV & Guest Welcome", "guest side: usage event recorded", code in (200, 201, 204, 302),
                   "POST /tv/%s/event -> %s" % (tok, code))
            movies = p.get("/iptv/movies")
            mids = re.findall(r"/iptv/movies/(\d+)", movies) or re.findall(r'data-movie="(\d+)"', movies)
            if mids:
                code, _b, loc = p.post("/tv/%s/rent/%s" % (tok, mids[0]), {}, None, referer="/tv/%s" % tok)
                body = p.get(re.sub(r"^https?://[^/]+", "", loc or "") or "/tv/%s/state" % tok)
                record("IPTV & Guest Welcome", "guest side: rent a movie from the TV", code in (200, 302),
                       "POST /tv/%s/rent/%s -> %s | %s" % (tok, mids[0], code, " / ".join(p.flash(body))[:140]))
            else:
                record("IPTV & Guest Welcome", "guest side: rent a movie from the TV", False, "no movie ids found")
        # emergency alert: the form makes you type EMERGENCY to confirm
        page = "/iptv"
        html = p.get(page)
        data, f = payload(html, r"/iptv/emergency$", {"title": "Audit — fire drill",
                                                      "body": "This is a drill. Please follow the marshals to the car park.",
                                                      "confirm": "EMERGENCY"})
        if data:
            data.pop("_token", None)
            fl, d = submit(p, "/iptv/emergency", data, action_pattern=r"/iptv/emergency$", read=page,
                           ok_words=("every tv", "alert", "sent", "emergency"))
            record("IPTV & Guest Welcome", "emergency alert: put it on every TV", bool(fl) and not refused(fl), d)
            html = p.get(page)
            m = re.findall(r'/iptv/emergency/(\d+)', html)
            if m:
                fl, d = submit_form(p, "/iptv/emergency/%s" % m[-1], r"/iptv/emergency/%s" % m[-1], read=page,
                                    ok_words=("all clear", "withdrawn", "cleared", "removed"))
                record("IPTV & Guest Welcome", "emergency alert: send the all clear", bool(fl), d)
            else:
                record("IPTV & Guest Welcome", "emergency alert: send the all clear", False, "no active alert row")
        else:
            record("IPTV & Guest Welcome", "emergency alert: put it on every TV", False, "no emergency form on /iptv")
        # device remote command + status
        if ids:
            fl, d = submit_form(p, "/iptv/devices/%s/command" % ids[-1], r"/iptv/devices/%s/command$" % ids[-1],
                                read="/iptv/devices/%s" % ids[-1], ok_words=("sent", "command", "queued", "offline"))
            record("IPTV & Guest Welcome", "device: send a remote command", bool(fl), d)
            fl, d = submit_form(p, "/iptv/devices/%s/status" % ids[-1], r"/iptv/devices/%s/status$" % ids[-1],
                                read=dev_page, ok_words=("kiosk", "tv", "status", "updated", "off", "on"))
            record("IPTV & Guest Welcome", "device: switch a TV off/on", bool(fl), d)
        # settings: keep every language checkbox the form offers
        page = "/iptv/settings"
        html = p.get(page)
        f = form_fields(html, r"/iptv/settings$")
        langs = re.findall(r'name="languages\[\]"[^>]*value="([^"]+)"[^>]*checked', html) or \
                re.findall(r'name="languages\[\]"[^>]*value="([^"]+)"', html)[:3]
        data = {k: v for k, v in f.items() if not k.endswith("__options") and k != "_token"}
        if langs:
            data["languages[]"] = langs
            data["default_language"] = langs[0]
        fl, d = submit(p, "/iptv/settings", data, action_pattern=r"/iptv/settings$", read=page, ok_words=("saved",))
        record("IPTV & Guest Welcome", "settings: save", ok(fl, "saved") and not refused(fl), d + " | languages=%s" % langs[:4])
    step("IPTV & Guest Welcome", "iptv guest side + emergency + settings", iptv)

    # ═══════════════════════════ Interfaces ═══════════════════════════
    def calls():
        page = "/calls?tab=review"
        fl, d = submit(p, "/calls/import", {"text": "101,+233302700700,%s 09:20:00,00:03:10,1\n102,+233244000000,%s 09:25:00,00:01:05,1" % (TODAY, TODAY)},
                       action_pattern=r"/calls/import$", read=page, ok_words=("import", "record", "line", "parsed", "call"))
        record("Interfaces", "call accounting: import an SMDR batch", bool(fl) and not refused(fl), d)
        html = p.get(page)
        posted = re.findall(r"/calls/(\d+)/post", html)
        waived = re.findall(r"/calls/(\d+)/waive", html)
        if posted:
            fl, d = post_direct(p, "/calls/%s/post" % posted[-1], {"stay_id": "62"}, page, ("posted", "folio", "already", "cannot"))
            record("Interfaces", "call accounting: post a call to the folio", bool(fl) and not refused(fl), d)
        else:
            record("Interfaces", "call accounting: post a call to the folio", True, "review queue empty — nothing to post")
        if waived:
            fl, d = post_direct(p, "/calls/%s/waive" % waived[-1], {"reason": "Audit probe — goodwill waiver"}, page,
                                ("waived", "cannot", "already"))
            record("Interfaces", "call accounting: waive a call", bool(fl), d)
        fl, d = submit_form(p, "/calls/extensions", r"/calls/extensions$", read=page,
                            overrides={"extension": "199", "type": "admin", "department": "Front desk",
                                       "label": "Audit extension"}, ok_words=("saved", "extension"))
        record("Interfaces", "call accounting: save an extension", bool(fl) and not refused(fl), d)
        fl, d = submit_form(p, "/calls/extensions-sync", r"/calls/extensions-sync$", read=page,
                            ok_words=("sync", "extension", "imported", "nothing"))
        record("Interfaces", "call accounting: sync extensions from the PABX", bool(fl), d)
        fl, d = submit_form(p, "/calls/reprocess", r"/calls/reprocess$", read=page,
                            ok_words=("reprocess", "review", "queue", "nothing"))
        record("Interfaces", "call accounting: re-run the review queue", bool(fl), d or "no reprocess form rendered")
    step("Interfaces", "call accounting", calls)

    def keys():
        page = "/key-cards"
        fl, d = submit(p, "/key-cards/read", {"serial": "AUDIT-SERIAL-1"}, read=page,
                       ok_words=("read", "card", "not found", "unknown", "no card"))
        record("Interfaces", "key cards: read a card at the desk", bool(fl), d)
        html = p.get(page)
        cards = re.findall(r"/key-cards/(\d+)/(lost|return)", html)
        if cards:
            cid, act = cards[-1]
            fl, d = post_direct(p, "/key-cards/%s/lost" % cid, {"reissue": "1", "charge_fee": "1", "fee": "250"},
                                page, ("lost", "deactivated", "new card", "cannot"))
            record("Interfaces", "key cards: report a card lost + reissue", bool(fl) and not refused(fl), d)
            html = p.get(page)
            ret = re.findall(r"/key-cards/(\d+)/return", html)
            if ret:
                fl, d = post_direct(p, "/key-cards/%s/return" % ret[-1], {}, page, ("returned", "back", "cannot", "already"))
                record("Interfaces", "key cards: take a card back", bool(fl), d)
        else:
            record("Interfaces", "key cards: lost / return", False, "no card rows with actions on %s" % page)
        fl, d = submit(p, "/master-keys", {"key_no": "AUDIT-MK1", "name": "Audit floor master", "level": "floor",
                                           "department": "Housekeeping", "scope": "Floor 2"}, read=page,
                       ok_words=("saved", "added", "key"))
        record("Interfaces", "master keys: register a key", bool(fl) and not refused(fl), d)
        html = p.get(page)
        outs = re.findall(r"/master-keys/(\d+)/out", html)
        if outs:
            fl, d = post_direct(p, "/master-keys/%s/out" % outs[-1], {"holder": "Audit housekeeping",
                                                                      "purpose": "Audit probe — linen run", "hours": "2"},
                                page, ("signed out", "due back"))
            record("Interfaces", "master keys: sign one out", ok(fl, "signed out", "due back") and not refused(fl), d)
            loans = re.findall(r"/key-loans/(\d+)/in", p.get(page))
            if loans:
                fl, d = post_direct(p, "/key-loans/%s/in" % loans[-1], {"note": "Audit probe — returned"}, page,
                                    ("signed in", "returned", "back"))
                record("Interfaces", "master keys: sign it back in", bool(fl) and not refused(fl), d)
            else:
                record("Interfaces", "master keys: sign it back in", False, "no open loan after signing out")
        else:
            record("Interfaces", "master keys: sign one out", False, "no master-key rows with an out action")
    step("Interfaces", "key cards + master keys", keys)

    def hub():
        page = "/interfaces"
        fl, d = submit_form(p, "/interfaces/commands/retry-all", r"/interfaces/commands/retry-all$", read=page,
                            ok_words=("retry", "re-sent", "nothing", "queued", "failed"))
        record("Interfaces", "hub: retry failed commands", bool(fl), d or "no retry-all form rendered (nothing failed)")
        html = p.get(page)
        one = re.findall(r"/interfaces/commands/(\d+)/retry", html)
        if one:
            fl, d = post_direct(p, "/interfaces/commands/%s/retry" % one[-1], {}, page, ("retry", "queued", "sent", "failed"))
            record("Interfaces", "hub: retry one command", bool(fl), d)
        else:
            record("Interfaces", "hub: retry one command", True, "command log empty — nothing to retry")
    step("Interfaces", "interface hub", hub)

    save("flows_d.json")


if __name__ == "__main__":
    main()
