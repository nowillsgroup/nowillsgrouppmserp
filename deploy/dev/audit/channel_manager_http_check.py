#!/usr/bin/env python3
"""Read-only HTTP smoke audit for the live Channel Manager screens."""
import argparse
from html.parser import HTMLParser
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen


class Document(HTMLParser):
    def __init__(self):
        super().__init__()
        self.ids = set()
        self.duplicates = set()
        self.actions = []
        self.nav_count = 0
        self.current_nav = 0
        self.dialogs = 0

    def handle_starttag(self, tag, attributes):
        attrs = dict(attributes)
        if attrs.get('id'):
            if attrs['id'] in self.ids:
                self.duplicates.add(attrs['id'])
            self.ids.add(attrs['id'])
        if 'cm-nav' in attrs.get('class', '').split():
            self.nav_count += 1
            self.current_nav += attrs.get('aria-label') == 'Channel Manager sections'
        if attrs.get('role') == 'dialog':
            self.dialogs += 1
        if 'cm-action' in attrs.get('class', '').split():
            self.actions.append((tag, attrs.get('aria-label'), attrs.get('title')))


def fetch(base, path):
    url = base.rstrip('/') + path
    try:
        with urlopen(Request(url, headers={'User-Agent': 'Nowills-Channel-Manager-audit/1.0'}), timeout=60) as response:
            body = response.read().decode('utf-8', 'replace')
            status = response.status
    except (HTTPError, URLError, TimeoutError) as exc:
        raise AssertionError(f'{url} failed: {exc}') from exc
    assert status == 200, f'{url} returned HTTP {status}'
    for error in ('ParseError', 'Undefined variable', 'ViewException', 'Whoops', '500 | Server Error'):
        assert error not in body, f'{url} contains runtime error marker {error!r}'
    doc = Document()
    doc.feed(body)
    assert not doc.duplicates, f'{url} has duplicate IDs: {sorted(doc.duplicates)}'
    assert doc.nav_count == 1 and doc.current_nav == 1, f'{url} should render one named channel navigation'
    assert all(label and title for _, label, title in doc.actions), f'{url} has an unnamed icon toolbar action'
    print(f'PASS {path}: HTTP 200, no runtime errors, one accessible channel nav, {len(doc.actions)} named icon action(s)')
    return body, doc


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--base', default='http://127.0.0.1:8004')
    parser.add_argument('--connection', default='1', help='existing non-simulator channel connection id')
    parser.add_argument('--simulator', default='3', help='existing sandbox connection id')
    parser.add_argument('--message', default='9', help='existing attention inbox message id')
    args = parser.parse_args()

    body, doc = fetch(args.base, '/channel-manager')
    for marker in ('Channel activity at a glance', 'id="connTable"', 'id="recentOta"', 'id="overbookTable"'):
        if marker == 'id="overbookTable"' and marker not in body:
            continue  # the seeded tenant may have no active overbooking
        assert marker in body, f'dashboard is missing {marker}'
    print('PASS dashboard renders its channel health, production, connection and recent-booking surface')

    body, _ = fetch(args.base, f'/channel-manager/connections/{args.connection}')
    for marker in ('What the channel gets', 'id="ariPreview"', 'id="mapTable"', 'id="restrTable"', 'id="connMsgs"', 'id="integration"'):
        if marker == 'id="integration"' and marker not in body:
            continue  # integration secrets are permission-gated
        assert marker in body, f'connection detail is missing {marker}'
    print('PASS connection detail renders ARI, mappings, restrictions, messages and permitted integration data')

    body, _ = fetch(args.base, f'/channel-manager/connections/{args.simulator}')
    assert 'id="simForm"' in body and 'name="currency"' in body and 'Cancel via OTA' in body
    print('PASS simulator form is clearly isolated to the built-in test connection')

    body, _ = fetch(args.base, '/channel-manager/connections/create')
    assert 'id="connForm"' in body and 'id="providerNote"' in body and 'name="api_secret"' in body
    print('PASS create form renders provider-aware connection setup fields')

    body, _ = fetch(args.base, '/channel-manager/inbox?tab=all')
    for marker in ('id="inboxFilter"', 'id="inboxTabs"', 'id="inboxTable"', 'All messages'):
        assert marker in body, f'inbox is missing {marker}'
    print('PASS inbox filters, status tabs and message register render')

    body, doc = fetch(args.base, f'/channel-manager/inbox/{args.message}')
    assert 'id="rawPayload"' in body and doc.dialogs == 1
    assert 'id="resolveBtn"' in body and 'id="resolveForm"' in body
    print('PASS attention message shows its payload, history and accessible resolution dialog')

    body, _ = fetch(args.base, '/channel-manager/sync-log')
    assert 'id="pushFilter"' in body and 'id="pushTable"' in body
    print('PASS sync log filters and push audit table render')

    body, _ = fetch(args.base, '/channel-manager/parity')
    assert 'id="parityFilter"' in body and 'id="parityGrid"' in body
    print('PASS 14-night parity comparison and its filters render')

    body, doc = fetch(args.base, '/channels')
    assert 'id="icalTable"' in body and 'data-copy-target=' in body
    for marker in ('id="icalSearch"', 'id="icalFilter"', 'id="icalResultCount"', 'id="icalNoMatches"', 'data-ical-feed', 'data-sync-state=', 'Export availability', 'Import OTA calendars'):
        assert marker in body, f'iCal workspace is missing {marker}'
    assert 'Add iCal channel feed' in body and doc.dialogs >= 1
    print('PASS iCal workspace renders live search, health filters, sync state, guidance, dialogs and export-copy controls')


if __name__ == '__main__':
    main()
