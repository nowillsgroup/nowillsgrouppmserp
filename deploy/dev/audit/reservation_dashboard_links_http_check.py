#!/usr/bin/env python3
"""Smoke-test every rendered Reservation Desk KPI destination.

Requires the Nowills dev server to be running. The dev server's automatic
admin session is used when requests do not carry a cookie.

    python3 deploy/dev/audit/reservation_dashboard_links_http_check.py \
        --base http://127.0.0.1:8001
"""
from __future__ import annotations

import argparse
import os
import sys
from html.parser import HTMLParser
from urllib.error import HTTPError, URLError
from urllib.parse import urlsplit
from urllib.request import Request, urlopen


class DashboardLinks(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.current: dict[str, str] | None = None
        self.links: list[tuple[str, str]] = []

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        if tag != "a":
            return
        values = dict(attrs)
        classes = (values.get("class") or "").split()
        if "desk-summary-card" in classes or "desk-priority-card" in classes or "kpi" in classes:
            self.current = {"href": values.get("href") or "", "label": ""}

    def handle_data(self, data: str) -> None:
        if self.current is not None:
            self.current["label"] += data

    def handle_endtag(self, tag: str) -> None:
        if tag == "a" and self.current is not None:
            self.links.append((self.current["href"], " ".join(self.current["label"].split())))
            self.current = None


def get(url: str, timeout: int = 30) -> tuple[int, str, str]:
    request = Request(url, headers={"Accept": "text/html", "User-Agent": "Nowills-PMS-desk-audit/1.0"})
    with urlopen(request, timeout=timeout) as response:
        return response.status, response.geturl(), response.read().decode("utf-8", errors="replace")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--base",
        default=os.environ.get("PMS_BASE_URL", "http://127.0.0.1:8000"),
        help="base URL for the running development server (default: %(default)s)",
    )
    args = parser.parse_args()
    base = args.base.rstrip("/")

    try:
        status, final_url, html = get(f"{base}/reservations?view=dashboard")
    except (HTTPError, URLError, TimeoutError) as error:
        print(f"FAIL dashboard request: {error}", file=sys.stderr)
        return 1
    if status != 200 or 'data-view="dashboard"' not in html:
        print(f"FAIL expected the Reservation Desk dashboard (HTTP {status}, {final_url})", file=sys.stderr)
        return 1

    parsed = DashboardLinks()
    parsed.feed(html)
    unique: dict[str, str] = {}
    for href, label in parsed.links:
        if not href:
            continue
        target = urlsplit(href)
        path = target.path or "/"
        if target.query:
            path += "?" + target.query
        unique.setdefault(path, label)

    if len(unique) < 6:
        print(f"FAIL only {len(unique)} KPI destinations were rendered", file=sys.stderr)
        return 1

    failures = 0
    for path, label in unique.items():
        try:
            target_status, final_target, body = get(base + path)
            if target_status != 200 or "/login" in urlsplit(final_target).path:
                failures += 1
                print(f"FAIL HTTP {target_status} {label}: {path} → {final_target}")
            else:
                print(f"PASS HTTP 200 {label}: {path}")
        except (HTTPError, URLError, TimeoutError) as error:
            failures += 1
            print(f"FAIL {label}: {path} → {error}")

    print(f"Checked {len(unique)} unique Reservation Desk KPI destinations; {failures} failed.")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
