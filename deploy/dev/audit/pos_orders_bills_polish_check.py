#!/usr/bin/env python3
"""Regression checks for the live POS Orders & Bills register."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
VIEW = (ROOT / "resources/views/pos/orders.blade.php").read_text()
CONTROLLER = (ROOT / "app/Http/Controllers/PosController.php").read_text()
CSS = (ROOT / "public/css/app.css").read_text()
NAV = (ROOT / "resources/views/pos/_workspace-nav.blade.php").read_text()


class PosOrdersBillsPolishTest(unittest.TestCase):
    def test_filter_form_keeps_existing_get_query_contract_and_has_linked_labels(self):
        self.assertIn('method="get" action="{{ route(\'pos.orders\') }}"', VIEW)
        for field, input_id in (
            ("from", "posBillsFrom"),
            ("to", "posBillsTo"),
            ("q", "posBillsSearch"),
            ("outlet", "posBillsOutlet"),
            ("status", "posBillsStatus"),
        ):
            with self.subTest(field=field):
                self.assertIn(f'name="{field}"', VIEW)
                self.assertIn(f'for="{input_id}"', VIEW)
                self.assertIn(f'id="{input_id}"', VIEW)
        self.assertIn('aria-label="Filter POS orders and bills"', VIEW)
        self.assertIn('@if($link[\'active\']) aria-current="page" @endif', NAV)
        self.assertIn("route('pos.orders')", VIEW)
        self.assertIn("Unsettled (open / billed)", VIEW)
        self.assertIn("$q", VIEW)
        for query_contract in (
            "whereDate('business_date', '>=', $from)",
            "whereDate('business_date', '<=', $to)",
            "where('outlet_id', $o)",
            "whereIn('status', ['open', 'billed'])",
            "orWhere('customer_phone', 'like', $like)",
        ):
            with self.subTest(query_contract=query_contract):
                self.assertIn(query_contract, CONTROLLER)

    def test_register_table_is_descriptive_and_preserves_date_and_payment_context(self):
        self.assertIn('class="table pos-orders-table" aria-describedby="posOrdersCount"', VIEW)
        self.assertIn('<caption class="sr-only">POS orders and bills matching', VIEW)
        for heading in ("Order", "Bill", "Guest", "Business date / time", "Outlet", "Table / room", "Settlement", "Discount", "Tax", "Total / due", "Status"):
            with self.subTest(heading=heading):
                self.assertRegex(VIEW, rf'<th scope="col"(?: class="num")?>{heading}</th>')
        self.assertIn('datetime="{{ $o->business_date->format(\'Y-m-d\') }}"', VIEW)
        self.assertIn('datetime="{{ $o->created_at->toIso8601String() }}"', VIEW)
        self.assertIn("$o->payments->where('kind', 'payment')", VIEW)
        self.assertIn("$o->paymentMode?->name ?? 'Payment'", VIEW)
        self.assertIn("$o->customer_name ?: '—'", VIEW)
        for source in ("qr", "portal", "tv"):
            with self.subTest(source=source):
                self.assertIn(f"$o->source === '{source}'", VIEW)
        self.assertNotIn("$o->customer_phone", VIEW)

    def test_unsettled_balance_is_visible_without_db_requeries_or_false_room_dues(self):
        self.assertIn('label="Sales excl. tax"', VIEW)
        self.assertIn("$o->payments->where('kind', 'payment')->sum('amount')", VIEW)
        self.assertIn("$o->payments->where('kind', 'refund')->sum('amount')", VIEW)
        self.assertIn("! in_array($o->settlement, ['room_post', 'complimentary'], true)", VIEW)
        self.assertIn("{{ money($billBalance, false) }}", VIEW)
        self.assertIn(".pos-order-balance-due", CSS)

    def test_print_actions_are_named_and_open_safely(self):
        self.assertIn('target="_blank" rel="noopener noreferrer"', VIEW)
        self.assertIn('aria-label="Print bill {{ $o->bill_no ?: $o->order_no }}"', VIEW)
        self.assertIn("route('pos.print', $o)", VIEW)

    def test_large_filtered_ranges_paginate_without_page_limited_summary_totals(self):
        method = CONTROLLER.split("public function orders(Request $request)", 1)[1].split("public function printBill", 1)[0]
        self.assertIn("as settled_count", method)
        self.assertIn("as sales_ex_tax", method)
        self.assertIn("as tax_total", method)
        self.assertIn("as room_post_total", method)
        self.assertIn("paginate(50)->withQueryString()", method)
        self.assertIn("$orders->total()", VIEW)
        self.assertIn("$orders->links('partials.pagination')", VIEW)

    def test_empty_search_has_a_context_preserving_recovery_link(self):
        self.assertIn("No orders match these filters.", VIEW)
        self.assertIn("Reset filters", VIEW)
        self.assertIn("href=\"{{ route('pos.orders') }}\"", VIEW)
        self.assertIn(".pos-orders-empty-action", CSS)

    def test_filters_and_wide_register_adapt_to_small_screens(self):
        self.assertIn(".pos-orders-search { flex: 1 1 250px", CSS)
        self.assertIn(".pos-orders-table { min-width: 1180px; }", CSS)
        self.assertIn(".pos-orders-table .pos-order-settlement", CSS)
        self.assertIn("@media (max-width: 720px)", CSS)
        self.assertIn(".pos-orders-filter-actions { width: 100%;", CSS)


if __name__ == "__main__":
    unittest.main(verbosity=2)
