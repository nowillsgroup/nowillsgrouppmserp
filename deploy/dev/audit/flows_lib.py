#!/usr/bin/env python3
"""Shared helpers for the Front Office & Guest Services write-flow probes."""
import datetime as dt
import json
import os
import re

from pmshttp import Pms                                              # noqa: F401

RESULTS = []
HERE = os.path.dirname(os.path.abspath(__file__))
LAST_LOC = ""            # Location header of the most recent submit() POST


def record(module, flow, ok, detail):
    RESULTS.append({"module": module, "flow": flow, "ok": bool(ok), "detail": str(detail)[:400]})
    print(("  PASS  " if ok else "  FAIL  ") + "%-20s %-44s %s" % (module, flow, str(detail)[:160]))


def save(name):
    out = os.path.join(HERE, name)
    json.dump(RESULTS, open(out, "w"), indent=1)
    passed = sum(1 for r in RESULTS if r["ok"])
    print("\n%d/%d passed -> %s" % (passed, len(RESULTS), out))
    return passed, len(RESULTS)


def form_fields(html, action_pattern):
    """name -> value for every field of the form whose action matches.

    Selects also expose `name__options` (all option values, in order).
    """
    out = {}
    for m in re.finditer(r'<form[^>]*action="([^"]*)"[^>]*>(.*?)</form>', html, re.S | re.I):
        if not re.search(action_pattern, re.sub(r"^https?://[^/]+", "", m.group(1))):
            continue
        body = m.group(2)
        for i in re.finditer(r'<(input|textarea|select)\b([^>]*)>(.*?)</\1>|<(input|textarea|select)\b([^>]*)/?>',
                             body, re.S | re.I):
            tag = (i.group(1) or i.group(4) or "").lower()
            attrs = i.group(2) if i.group(1) else (i.group(5) or "")
            inner = i.group(3) or ""
            nm = re.search(r'name="([^"]+)"', attrs)
            if not nm:
                continue
            name = nm.group(1)
            val = re.search(r'value="([^"]*)"', attrs)
            if tag == "select":
                opts = re.findall(r'<option[^>]*value="([^"]*)"', inner)
                sel = re.search(r'<option[^>]*value="([^"]*)"[^>]*selected', inner) or \
                      re.search(r'<option[^>]*selected[^>]*value="([^"]*)"', inner)
                out[name] = sel.group(1) if sel else (opts[0] if opts else "")
                out[name + "__options"] = opts
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
    return {}


def payload(html, action_pattern, overrides=None):
    """Build a POST body from a real form: keep its values, fill empty selects
    with their first non-empty option, then apply overrides."""
    f = form_fields(html, action_pattern)
    if not f:
        return None, None
    out = {}
    for k, v in f.items():
        if k.endswith("__options"):
            continue
        out[k] = v
    for k, opts in f.items():
        if k.endswith("__options"):
            base = k[:-9]
            if not out.get(base):
                ne = [o for o in opts if o]
                if ne:
                    out[base] = ne[0]
    out.update(overrides or {})
    return out, f


def submit(p, path, data, action_pattern=None, read=None, ok_words=(), method="POST"):
    """Read the hosting page for a fresh CSRF token, POST, then read the flash."""
    global LAST_LOC
    page = read or path
    LAST_LOC = ""
    html = p.get(page)
    tok = p.token(html, action_pattern)
    if not tok:
        return None, "no CSRF token on %s (pattern %r)" % (page, action_pattern)
    data = dict(data or {})
    code, _body, loc = p.post(path, data, tok, referer=page)
    LAST_LOC = re.sub(r"^https?://[^/]+", "", loc or "")
    target = LAST_LOC or page
    if target.split("?")[0] == path.split("?")[0]:
        target = page
    after = p.get(target)
    flashes = p.flash(after)
    ok = bool(flashes)
    if ok_words:
        ok = any(any(w.lower() in f.lower() for w in ok_words) for f in flashes)
    return flashes, "POST %s -> %s %s | flash: %s" % (path, code, target[:56], " / ".join(flashes)[:220])


def submit_form(p, path, action_pattern, read=None, overrides=None, ok_words=()):
    page = read or path
    html = p.get(page)
    data, fields = payload(html, action_pattern, overrides)
    if data is None:
        return None, "no form matching %r on %s" % (action_pattern, page)
    data.pop("_token", None)
    return submit(p, path, data, action_pattern=action_pattern, read=page, ok_words=ok_words)


def refused(flashes):
    """True when the app answered with a business-rule refusal rather than success."""
    if not flashes:
        return False
    bad = ("cannot", "can't", "already", "must ", "not allowed", "no ", "nothing", "refus", "outside")
    good = ("saved", "added", "created", "logged", "booked", "posted", "recorded", "updated",
            "removed", "released", "resolved", "completed", "confirmed", "done", "sent", "assigned")
    text = " ".join(flashes).lower()
    return any(b in text for b in bad) and not any(g in text for g in good)


def day(offset=0, fmt="%Y-%m-%d"):
    return (dt.date(2026, 10, 13) + dt.timedelta(days=offset)).strftime(fmt)
