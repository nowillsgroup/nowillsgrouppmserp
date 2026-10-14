#!/usr/bin/env python3
"""Tiny HTTP driver for exercising the PMS over the dev server (deploy/dev).

The dev server signs itself in (serve.mjs `ensureDevLogin`) and injects the
session cookie into requests that arrive without one, so a shared cookie jar is
enough to stay authenticated. POSTs need a `_token` that matches the session —
read it from the page that carries the form, then post with the same jar.

Usage:
    from pmshttp import Pms
    p = Pms()                      # http://127.0.0.1:8000
    html = p.get('/desk/wakeups')
    tok = p.token(html)
    r = p.post('/desk/wakeups', {'reservation_room_id': 62, ...}, tok)
"""
import http.cookiejar
import re
import urllib.error
import urllib.parse
import urllib.request


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):   # noqa: D102
        return None


class Pms:
    def __init__(self, base="http://127.0.0.1:8000", timeout=240):
        self.base = base.rstrip("/")
        self.timeout = timeout
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(self.jar), NoRedirect())
        self.last_location = ""
        self.last_code = 0

    # ---- primitives -------------------------------------------------
    def _open(self, req):
        try:
            resp = self.opener.open(req, timeout=self.timeout)
            body = resp.read().decode("utf-8", "replace")
            return resp.status, body, dict(resp.headers)
        except urllib.error.HTTPError as e:                          # 3xx land here (NoRedirect)
            body = e.read().decode("utf-8", "replace")
            return e.code, body, dict(e.headers)

    def get(self, path, follow=True):
        """GET a page. GET redirects (e.g. a saved default desk view) are followed;
        POST redirects are not — see post()."""
        code, body, hdr = self._open(urllib.request.Request(self.base + path,
                                                            headers={"Accept": "text/html"}))
        self.last_location = hdr.get("Location", "")
        hops = 0
        while follow and code in (301, 302, 303, 307, 308) and self.last_location and hops < 5:
            nxt = self.last_location
            nxt = nxt if nxt.startswith("/") else re.sub(r"^https?://[^/]+", "", nxt)
            if not nxt:
                break
            code, body, hdr = self._open(urllib.request.Request(self.base + nxt,
                                                                headers={"Accept": "text/html"}))
            self.last_location = hdr.get("Location", "")
            hops += 1
        self.last_code = code          # v7.44 — sweeps need the final status, not just the body
        return body

    def status(self, path):
        code, body, hdr = self._open(urllib.request.Request(self.base + path))
        return code, body, hdr.get("Location", "")

    def post(self, path, data, tok=None, referer=None, expect=None):
        payload = dict(data)
        if tok:
            payload["_token"] = tok
        body = urllib.parse.urlencode(payload, doseq=True).encode()
        req = urllib.request.Request(self.base + path, data=body, method="POST", headers={
            "Content-Type": "application/x-www-form-urlencoded",
            "Accept": "text/html",
            "Referer": self.base + (referer or path),
        })
        code, html, hdr = self._open(req)
        self.last_location = hdr.get("Location", "")
        return code, html, self.last_location

    def post_multipart(self, path, fields, files, tok=None, referer=None):
        """fields: {name: value}; files: {name: (filename, bytes, content_type)}."""
        boundary = "----pmsaudit%s" % re.sub(r"\W", "", str(__import__("time").time()))
        parts = []
        data = dict(fields)
        if tok:
            data["_token"] = tok
        for k, v in data.items():
            parts.append(("--%s\r\nContent-Disposition: form-data; name=\"%s\"\r\n\r\n%s\r\n"
                          % (boundary, k, v)).encode())
        for k, (fname, blob, ctype) in files.items():
            parts.append(("--%s\r\nContent-Disposition: form-data; name=\"%s\"; filename=\"%s\"\r\n"
                          "Content-Type: %s\r\n\r\n" % (boundary, k, fname, ctype)).encode()
                         + blob + b"\r\n")
        parts.append(("--%s--\r\n" % boundary).encode())
        body = b"".join(parts)
        req = urllib.request.Request(self.base + path, data=body, method="POST", headers={
            "Content-Type": "multipart/form-data; boundary=%s" % boundary,
            "Accept": "text/html",
            "Referer": self.base + (referer or path),
        })
        code, html, hdr = self._open(req)
        self.last_location = hdr.get("Location", "")
        return code, html, self.last_location

    def delete(self, path, tok=None, referer=None):
        """DELETE a resource. The CSRF token goes in the query string: Laravel reads
        `_token` from the request input, and a DELETE body is not always parsed."""
        url = self.base + path + (("&" if "?" in path else "?") + "_token=" + tok if tok else "")
        req = urllib.request.Request(url, data=b"", method="DELETE", headers={
            "Accept": "text/html", "Referer": self.base + (referer or path)})
        code, html, hdr = self._open(req)
        self.last_location = hdr.get("Location", "")
        return code, html, self.last_location

    # ---- helpers ----------------------------------------------------
    @staticmethod
    def token(html, action_pattern=None):
        """CSRF token from a page; optionally the token of one specific form."""
        if action_pattern:
            for m in re.finditer(r'<form[^>]*action="([^"]*)"[^>]*>(.*?)</form>', html, re.S):
                if re.search(action_pattern, m.group(1)):
                    t = re.search(r'name="_token"\s+value="([^"]+)"', m.group(2)) or \
                        re.search(r'value="([^"]+)"\s+name="_token"', m.group(2))
                    if t:
                        return t.group(1)
        t = re.search(r'name="_token"\s+value="([^"]+)"', html) or \
            re.search(r'value="([^"]+)"\s+name="_token"', html)
        return t.group(1) if t else None

    @staticmethod
    def flash(html):
        """Flash / validation messages rendered back on the page.

        The app wraps the text in a nested div (`<div class="alert alert-success">
        <x-icon/> <div>text</div></div>`), so a naive non-greedy match to the first
        </div> returns nothing — take a window and strip tags instead.
        """
        out = []
        for m in re.finditer(r'Please fix the following:(.{0,900}?)</div>\s*</div>', html, re.S):
            t = re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", m.group(1))).strip()
            if t:
                out.append("Please fix the following: " + t)
        for m in re.finditer(r'class="alert[^"]*"', html):
            window = html[m.end():m.end() + 900]
            cut = re.search(r"</div>\s*</div>", window)
            if cut:
                window = window[:cut.start()]
            t = re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", window)).strip()
            t = re.sub(r"^\s*", "", t)
            if t and t not in out:
                out.append(t[:300])
        return out

    @staticmethod
    def text(html):
        t = re.sub(r"<script.*?</script>", " ", html, flags=re.S | re.I)
        t = re.sub(r"<style.*?</style>", " ", t, flags=re.S | re.I)
        return re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", t))
