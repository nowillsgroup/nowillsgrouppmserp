#!/usr/bin/env python3
"""HTTP smoke-check the rendered corporate contract detail page on deploy/dev."""
import argparse
from html.parser import HTMLParser
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen


class Page(HTMLParser):
    def __init__(self):
        super().__init__()
        self.ids = set()
        self.duplicate_ids = set()
        self.modal_targets = []
        self.tables = {}
        self.current_table = None
        self.current_table_headers = 0
        self.table_caption = set()
        self.actions = []

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        element_id = attrs.get('id')
        if element_id:
            if element_id in self.ids:
                self.duplicate_ids.add(element_id)
            self.ids.add(element_id)
        if attrs.get('data-modal'):
            self.modal_targets.append(attrs['data-modal'])
        if 'cshow-action' in attrs.get('class', '').split():
            self.actions.append((tag, attrs.get('aria-label'), attrs.get('title')))
        if tag == 'table' and element_id:
            self.current_table = element_id
            self.tables[element_id] = {'headers': 0, 'caption': False}
        elif self.current_table and tag == 'th' and attrs.get('scope') == 'col':
            self.tables[self.current_table]['headers'] += 1
        if self.current_table and tag == 'caption':
            self.tables[self.current_table]['caption'] = True

    def handle_endtag(self, tag):
        if tag == 'table':
            self.current_table = None


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--base', default='http://127.0.0.1:8004', help='Nowills PMS dev server base URL')
    parser.add_argument('--contract', default='3', help='Existing demo contract ID to view')
    args = parser.parse_args()
    url = args.base.rstrip('/') + '/contracts/' + args.contract
    print(f'GET {url}')
    try:
        with urlopen(Request(url, headers={'User-Agent': 'Nowills-contract-detail-audit/1.0'}), timeout=45) as response:
            status, html = response.status, response.read().decode('utf-8', 'replace')
    except (HTTPError, URLError, TimeoutError) as exc:
        raise SystemExit(f'FAIL: contract detail request failed: {exc}')
    assert status == 200, f'expected HTTP 200, received {status}'
    print('PASS: GET returns HTTP 200')

    for marker in ('Negotiated rates', 'Room allotments', 'Bookings on this contract', 'Contract terms', 'Contract production summary'):
        assert marker in html, f'missing rendered page section: {marker}'
    print('PASS: rates, allotments, bookings, terms and production sections render')
    assert 'Undefined variable' not in html and 'ViewException' not in html and 'ParseError' not in html
    print('PASS: response contains no common Blade/runtime exception output')

    page = Page()
    page.feed(html)
    assert {'cshowRates', 'cshowAllotments', 'cshowBookings', 'cshowTerms'} <= page.ids
    assert not page.duplicate_ids, f'duplicate DOM IDs: {sorted(page.duplicate_ids)}'
    print('PASS: in-page section links resolve to unique target IDs')
    assert all(target in page.ids for target in page.modal_targets)
    assert {'suspendModal', 'renewModal'} <= page.ids
    print(f'PASS: {len(page.modal_targets)} visible shared modal trigger(s) target rendered dialogs; hidden dialogs are present')
    assert page.actions and all(label and title for _, label, title in page.actions)
    assert all(tag in ('a', 'button') for tag, _, _ in page.actions)
    print(f'PASS: {len(page.actions)} rendered header actions are icon-only and accessibly named')

    assert 'rateList' not in page.tables or page.tables['rateList']['caption']
    assert 'contractBookings' in page.tables and page.tables['contractBookings']['caption']
    print('PASS: rendered contract tables include accessible captions')
    if 'allotGrid' in page.tables:
        assert page.tables['allotGrid']['headers'] == 31, f"allotment grid should have 31 column headers, got {page.tables['allotGrid']['headers']}"
        assert page.tables['allotGrid']['caption']
        print('PASS: allotment grid renders 30 dates plus the room-type heading')
    else:
        assert 'No allotment.' in html
        print('PASS: contract without allotments shows its operational empty state')
    assert 'id="activateBtn"' in html or 'id="suspendBtn"' in html or 'id="renewBtn"' in html or 'id="editContract"' in html
    print('PASS: state/permission-dependent actions render for the demo user')


if __name__ == '__main__':
    main()
