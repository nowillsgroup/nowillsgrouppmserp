#!/usr/bin/env python3
"""Live smoke-test the Reservation Desk Room Board and its room/stay links.

Requires the development server with auto-login enabled. Example:
    python3 deploy/dev/audit/reservation_room_board_http_check.py \
        --base http://127.0.0.1:8000
"""
from __future__ import annotations

import argparse
from html import unescape
import os
import re
import sys
from html.parser import HTMLParser
from urllib.error import HTTPError, URLError
from urllib.parse import urlsplit
from urllib.request import Request, urlopen


class BoardPage(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.card_count = 0
        self.empty_state = False
        self.links: list[tuple[str, str]] = []
        self.images: list[str] = []
        self.in_card = False
        self.in_heading = False
        self.current_link: tuple[str, str] | None = None

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        values = dict(attrs)
        classes = (values.get("class") or "").split()
        if tag == "img" and self.in_card and values.get("src"):
            self.images.append(values["src"])
        if tag == "article" and "room-board-card" in classes:
            self.card_count += 1
            self.in_card = True
        elif "room-board-empty" in classes:
            self.empty_state = True
        elif tag == "h4" and self.in_card:
            self.in_heading = True
        elif tag == "a" and self.in_card:
            kind = next((name for name in ("room-board-guest", "room-board-reservation") if name in classes), "room" if self.in_heading else "")
            if kind:
                self.current_link = (values.get("href") or "", kind)

    def handle_endtag(self, tag: str) -> None:
        if tag == "a" and self.current_link is not None:
            self.links.append(self.current_link)
            self.current_link = None
        elif tag == "h4":
            self.in_heading = False
        elif tag == "article":
            self.in_card = False



import re as _re
import http.cookiejar as _cookiejar
from urllib.request import build_opener as _build_opener, HTTPCookieProcessor as _HTTPCookieProcessor
from urllib.parse import urlencode as _urlencode

_JAR = _cookiejar.CookieJar()
_OPENER = _build_opener(_HTTPCookieProcessor(_JAR))


def _dev_login(base: str) -> None:
    """Sign in to the dev server so the check sees real pages.

    The cached preview session used by deploy/dev/serve.mjs can rot (database
    sessions expire), so log in explicitly with the same env-tunable dev
    credentials the dev server itself uses (PMS_DEV_HOTEL / PMS_DEV_USER /
    PMS_DEV_PASS, defaulting to the NOWILLS demo admin).
    """
    base = base.rstrip("/")
    proto = urlsplit(base).scheme or "http"
    headers = {"Accept": "text/html", "User-Agent": "Nowills-PMS-audit/1.0", "X-Forwarded-Proto": proto}
    with _OPENER.open(Request(base + "/login", headers=headers), timeout=60) as response:
        html = response.read().decode("utf-8", "replace")
    token = _re.search(r'name="_token" value="([^"]+)"', html)
    assert token, "login page did not render a CSRF token"
    import os as _os
    body = _urlencode({
        "_token": token.group(1),
        "hotel_code": _os.environ.get("PMS_DEV_HOTEL", "NOWILLS"),
        "username": _os.environ.get("PMS_DEV_USER", "admin"),
        "password": _os.environ.get("PMS_DEV_PASS", "Admin@123"),
    }).encode()
    request = Request(base + "/login", data=body, headers={**headers, "Content-Type": "application/x-www-form-urlencoded"})
    with _OPENER.open(request, timeout=60) as response:
        final = response.geturl()
    assert "/login" not in urlsplit(final).path, f"dev-server login failed (landed on {final})"

def fetch(base: str, path: str) -> tuple[int, str, str]:
    request = Request(base + path, headers={"Accept": "text/html", "User-Agent": "Nowills-PMS-room-board-audit/1.0", "X-Forwarded-Proto": urlsplit(base + path).scheme or "http"})
    try:
        with _OPENER.open(request, timeout=45) as response:
            return response.status, response.geturl(), response.read().decode("utf-8", errors="replace")
    except HTTPError as error:
        return error.code, error.geturl(), error.read().decode("utf-8", errors="replace")


def page(path: str, base: str, marker: str | None = None) -> tuple[str, BoardPage]:
    status, final_url, body = fetch(base, path)
    if status != 200 or "/login" in urlsplit(final_url).path:
        raise RuntimeError(f"HTTP {status} for {path} → {final_url}")
    if marker and marker not in body:
        raise RuntimeError(f"Expected {marker!r} in {path}")
    parsed = BoardPage()
    parsed.feed(body)
    return body, parsed


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base", default=os.environ.get("PMS_BASE_URL", "http://127.0.0.1:8000"), help="development server base URL")
    args = parser.parse_args()
    base = args.base.rstrip("/")
    _dev_login(base)

    try:
        preview_home, _ = page("/", base)
        if "Nowills Grand Hotel" not in preview_home:
            raise RuntimeError("Preview home did not render the authenticated Nowills PMS app")
        print("PASS configured preview home renders the authenticated PMS app")
        board, default = page("/reservations?view=board", base, 'data-view="board"')
        for marker in ('ROOM BOARD', 'room-board-hero', 'room-board-metrics'):
            if marker not in board:
                raise RuntimeError(f"Missing Room Board marker: {marker}")
        if not re.search(r'<time datetime="\d{4}-\d{2}-\d{2}">', board):
            raise RuntimeError("Room Board date context lacks machine-readable dates")
        print("PASS machine-readable desk, window and stay dates")
        loopback_asset = re.compile(r'''(?:src|href|action)=["']https?://(?:localhost|127[.]0[.]0[.]1)(?::[0-9]+)?/''', re.IGNORECASE)
        if loopback_asset.search(board):
            raise RuntimeError("Board contains browser-facing asset or navigation URLs pointing at sandbox loopback")
        print("PASS Board asset and navigation URLs remain same-origin in the preview")
        if default.card_count == 0 and not default.empty_state:
            raise RuntimeError("No room cards or accessible empty state rendered")
        print(f"PASS board renders {default.card_count} room cards" if default.card_count else "PASS board renders the empty inventory state")
        if '<section class="kpi-strip' in board:
            raise RuntimeError("The compact Rooms/Reservations/Revenue KPI strip should not appear on the Room Board")
        print("PASS compact KPI strip is omitted from the Room Board")
        dashboard, _ = page("/reservations?view=dashboard", base, 'data-view="dashboard"')
        if '<section class="kpi-strip' not in dashboard:
            raise RuntimeError("Dashboard KPI strip disappeared while removing it from the Room Board")
        print("PASS dashboard KPI strip remains available")

        base_origin = urlsplit(base).netloc
        checked_images: set[str] = set()
        for source in default.images:
            image = urlsplit(source)
            if image.netloc and image.netloc != base_origin:
                continue  # Do not contact external/CDN hosts from this local smoke test.
            path = image.path + ("?" + image.query if image.query else "")
            if not path or path in checked_images:
                continue
            status, final_url, _ = fetch(base, path)
            if status != 200:
                raise RuntimeError(f"Room photo failed: HTTP {status} {path} → {final_url}")
            checked_images.add(path)
        if checked_images:
            print(f"PASS {len(checked_images)} distinct local room photos")

        for group in ("type", "floor", "hk", "status"):
            _, grouped = page(f"/reservations?view=board&group={group}", base, f'aria-label="Hotel rooms grouped by {group}"')
            if grouped.card_count != default.card_count:
                raise RuntimeError(f"{group} grouping changed visible inventory ({default.card_count} → {grouped.card_count})")
            print(f"PASS {group} grouping preserves visible inventory")

        _, no_match = page("/reservations?view=board&q=__room_board_audit_no_match__", base, "No rooms match these filters")
        if no_match.card_count != 0 or not no_match.empty_state:
            raise RuntimeError("Filtered no-results state did not render cleanly")
        print("PASS filtered no-results state")

        seen: set[str] = set()
        checked: set[str] = set()
        for href, kind in default.links:
            if not href:
                raise RuntimeError(f"Blank {kind} link in a room card")
            target = urlsplit(href)
            path = target.path or "/"
            if target.query:
                path += "?" + target.query
            if kind in seen:
                continue
            seen.add(kind)
            status, final_url, target_body = fetch(base, path)
            if status != 200 or "/login" in urlsplit(final_url).path:
                raise RuntimeError(f"{kind} card link failed: HTTP {status} {path} → {final_url}")
            checked.add(kind)
            print(f"PASS {kind} card link → {path}")
            if kind == "room":
                for marker in ('ROOM PROFILE', 'Current occupancy', 'Amenities &amp; notes', 'Upcoming stays', 'Housekeeping activity', 'Maintenance', 'Previous stays', 'Room actions', 'View room timeline'):
                    if marker not in target_body:
                        raise RuntimeError(f"Room profile page is missing section {marker!r}")
                if target_body.count('<h1') != 1 or '<div class="page-head">' in target_body:
                    raise RuntimeError("Room profile should show one page heading without the generic duplicate shell header")
                if loopback_asset.search(target_body):
                    raise RuntimeError("Room profile contains browser-facing asset or navigation URLs pointing at sandbox loopback")
                print("PASS linked room page renders fully styled with a single page heading and same-origin assets")
                timeline_link = re.search(r'<a class="room-profile-button" href="([^"]+)">View room timeline</a>', target_body)
                if not timeline_link:
                    raise RuntimeError("Room profile is missing its room-specific timeline shortcut")
                timeline_target = urlsplit(unescape(timeline_link.group(1)))
                timeline_path = timeline_target.path + ("?" + timeline_target.query if timeline_target.query else "")
                page(timeline_path, base, 'data-view="timeline"')
                print(f"PASS room-specific timeline shortcut → {timeline_path}")
        if default.card_count and "room" not in checked:
            raise RuntimeError(f"Expected room-detail links; checked {sorted(checked)}")
        if any(kind == "room-board-reservation" for _, kind in default.links) and "room-board-reservation" not in checked:
            raise RuntimeError("Expected reservation stay link in a room card")
        return 0
    except (RuntimeError, URLError, TimeoutError) as error:
        print(f"FAIL {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
