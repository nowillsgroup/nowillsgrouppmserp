#!/usr/bin/env python3
"""Structural check for every rendered page.

A page that emits content outside its Blade section lands BEFORE the layout
document: the browser then parses the app shell after the page body, styles
pushed onto a stack the layout never renders are dropped, and the page
renders broken or in quirks mode. That class of defect is invisible to status
code checks and to HTML scraping - it only shows in a real browser - but it
leaves a reliable fingerprint in the bytes:

  * the document does not start with a doctype, and/or
  * it contains more than one <html> / <body> / </html>.

Responses that carry no HTML at all (CSV/JSON/XML exports, feeds, polling
endpoints) are skipped: they have no layout to escape from.

Usage (server already running):
    python3 deploy/dev/page_structure_check.py [--base URL]

Exit code 1 when any page is structurally broken.
"""
import json
import re
import subprocess
import sys
import os

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import flow  # noqa: E402  (shared cookie jar + CSRF driver)

REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

# Parameterised routes worth including because they render real pages.
CURATED = [
    'reservations/65', 'reservations/64', 'folios/65', 'folios/65/invoice',
    'folios/63/invoice', 'folios/183/invoice', 'front-desk/stay/64/check-out',
    'pos/orders/504', 'pos/orders/44', 'accounts/city-ledger/5',
    'reservations?view=departures', 'reservations?view=arrivals',
    'reservations?view=inhouse', 'reservations?view=board',
    'reservations/workspace?date=2026-10-12',
    'reservations/workspace/report?date=2026-10-14',
    'reports/revenue?from=2026-10-13&to=2026-10-13',
    'reports/arrivals?from=2026-10-13&to=2026-10-13',
    'hr/ess', 'offline/desktop', 'rentals', 'rentals/fleet',
]


def route_urls():
    out = subprocess.run(
        [os.path.join(REPO, 'deploy/dev/bin/php'), 'artisan', 'route:list', '--json'],
        cwd=REPO, capture_output=True, text=True, check=True)
    urls = []
    for r in json.loads(out.stdout):
        if 'GET' not in r['method']:
            continue
        uri = r['uri']
        if '{' in uri or uri.startswith('api/'):
            continue
        urls.append(uri)
    return urls


def main():
    urls = list(dict.fromkeys(route_urls() + CURATED))
    checked = skipped = 0
    broken = []
    for u in urls:
        path = '/' + u if not u.startswith('/') else u
        try:
            st, hd, body = flow.get(path)
        except Exception as e:  # noqa: BLE001
            broken.append((u, 'ERR', str(e)[:60]))
            continue
        if st != 200:
            skipped += 1
            continue
        ctype = (hd.get('Content-Type') or '').lower()
        if 'text/html' not in ctype:
            skipped += 1
            continue
        checked += 1
        doctype = body.lstrip()[:20].lower().startswith('<!doctype')
        counts = (body.count('<body'), body.count('<html'), body.count('</html>'))
        if not doctype or counts != (1, 1, 1):
            broken.append((u, st, f'doctype={doctype} body/html//html={counts}'))
    print(f'checked {checked} html pages, skipped {skipped} non-200/non-html')
    if broken:
        print(f'BROKEN: {len(broken)}')
        for row in broken[:20]:
            print('  ', row)
        return 1
    print('all pages structurally sound (doctype first, single html/body)')
    return 0


if __name__ == '__main__':
    sys.exit(main())
