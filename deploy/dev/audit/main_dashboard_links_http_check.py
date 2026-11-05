#!/usr/bin/env python3
"""Smoke-test the main dashboard's operational quick-action destinations.

Requires the Nowills dev server to be running. Its automatic admin session is
used when requests arrive without a cookie.

    python3 deploy/dev/audit/main_dashboard_links_http_check.py \
        --base http://127.0.0.1:8001
"""
from __future__ import annotations

import argparse
from collections import Counter
import os
import sys
from html.parser import HTMLParser
from urllib.error import HTTPError, URLError
from urllib.parse import urlsplit
from urllib.request import Request, urlopen


class QuickActions(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.in_actions = False
        self.current: dict[str, str] | None = None
        self.links: list[tuple[str, str]] = []
        self.ids: list[str] = []

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        values = dict(attrs)
        if values.get("id"):
            self.ids.append(values["id"])
        if tag == "nav" and "dash-exec-actions" in (values.get("class") or "").split():
            self.in_actions = True
        elif self.in_actions and tag == "a":
            self.current = {"href": values.get("href") or "", "label": ""}

    def handle_data(self, data: str) -> None:
        if self.current is not None:
            self.current["label"] += data

    def handle_endtag(self, tag: str) -> None:
        if tag == "a" and self.current is not None:
            self.links.append((self.current["href"], " ".join(self.current["label"].split())))
            self.current = None
        elif tag == "nav" and self.in_actions:
            self.in_actions = False


def get(url: str, timeout: int = 45) -> tuple[int, str, str]:
    request = Request(url, headers={"Accept": "text/html", "User-Agent": "Nowills-PMS-dashboard-audit/1.0"})
    with urlopen(request, timeout=timeout) as response:
        return response.status, response.geturl(), response.read().decode("utf-8", errors="replace")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base", default=os.environ.get("PMS_BASE_URL", "http://127.0.0.1:8000"))
    args = parser.parse_args()
    base = args.base.rstrip("/")
    try:
        status, final_url, html = get(f"{base}/Dashboard")
    except (HTTPError, URLError, TimeoutError) as error:
        print(f"FAIL main dashboard request: {error}", file=sys.stderr)
        return 1
    if status != 200 or 'class="dash-exec-actions"' not in html:
        print(f"FAIL expected the main dashboard quick actions (HTTP {status}, {final_url})", file=sys.stderr)
        return 1

    actions = QuickActions()
    actions.feed(html)
    failures = 0
    duplicate_ids = sorted(item for item, count in Counter(actions.ids).items() if count > 1)
    if duplicate_ids:
        print(f"FAIL duplicate HTML ids: {', '.join(duplicate_ids)}")
        failures += 1
    else:
        print(f"PASS unique HTML ids ({len(actions.ids)} checked)")

    revenue_bars = html.count('class="b dash-revenue-bar"')
    occupancy_bars = html.count('class="b dash-occupancy-bar"')
    if revenue_bars != occupancy_bars:
        print(f"FAIL chart series have different sample counts: revenue {revenue_bars}, occupancy {occupancy_bars}")
        failures += 1
    elif revenue_bars:
        print(f"PASS revenue and occupancy charts have matching samples ({revenue_bars} each)")
    elif 'No completed audit days are available for this trend yet.' in html:
        print("PASS chart empty state is present when there is no audit history")
    else:
        print("FAIL charts have no samples and no empty state")
        failures += 1

    required = ("Reservation desk", "Arrivals", "Walk-in")
    destinations = {label: href for href, label in actions.links}
    for label in required:
        href = next((href for text, href in destinations.items() if label.lower() in text.lower()), None)
        if not href:
            print(f"FAIL missing {label} quick action")
            failures += 1
            continue
        target = urlsplit(href)
        path = target.path or "/"
        if target.query:
            path += "?" + target.query
        try:
            target_status, target_url, _ = get(base + path)
            if target_status != 200 or urlsplit(target_url).path == "/login":
                print(f"FAIL HTTP {target_status} {label}: {path} → {target_url}")
                failures += 1
            else:
                print(f"PASS HTTP 200 {label}: {path}")
        except (HTTPError, URLError, TimeoutError) as error:
            print(f"FAIL {label}: {path} → {error}")
            failures += 1
    print(f"Checked {len(required)} main-dashboard quick actions; {failures} failed.")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
