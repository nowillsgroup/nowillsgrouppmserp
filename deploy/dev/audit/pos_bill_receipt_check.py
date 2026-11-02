#!/usr/bin/env python3
"""Regression checks for the POS guest-bill receipt and sign-off print layout."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
BILL = (ROOT / "resources/views/pos/bill.blade.php").read_text()
CSS = (ROOT / "public/css/app.css").read_text()
KOT = (ROOT / "resources/views/pos/kot-slip.blade.php").read_text()


class PosBillReceiptPrintTest(unittest.TestCase):
    def test_bill_lines_use_a_semantic_four_column_table(self):
        self.assertIn('class="pos-bill-items" aria-label="Bill items, quantities, rates and amounts"', BILL)
        self.assertIn('<caption class="sr-only">Bill items, quantities, rates and amounts</caption>', BILL)
        for heading in ("ITEMS", "QTY", "RATE", "AMOUNT"):
            with self.subTest(heading=heading):
                self.assertIn(f'<th scope="col">{heading}</th>', BILL)
        for value in ("$l->item_name", "(float)$l->qty", "$l->rate", "$l->amount"):
            with self.subTest(value=value):
                self.assertIn(value, BILL)
        self.assertIn(".pos-bill-items td + td", CSS)
        self.assertIn("border-left: 1px solid #e2e8f0", CSS)

    def test_receipt_and_signoff_sections_precede_gra_evat(self):
        markers = (
            "Waiter Name:",
            "TAX SUMMARY",
            "RECEIPTS",
            "PAY MODE",
            "RECEIPTS TOTAL",
            "Cashier:</b>",
            "GUEST DETAILS &amp; SIGN-OFF",
            "Guest Name",
            "Room Number",
            "Signature",
            "<b>GRA E-VAT</b>",
        )
        positions = [BILL.index(marker) for marker in markers]
        self.assertEqual(positions, sorted(positions))
        self.assertIn('id="billReceipts" class="pos-bill-receipts" aria-label="Payment receipts"', BILL)
        self.assertIn('id="billSignoff" class="pos-bill-signoff" aria-label="Guest name, room number, and signature"', BILL)
        self.assertIn("$order->customer_name ?: '____________________________'", BILL)
        self.assertIn("$order->stay?->room?->room_no ?: '____________________________'", BILL)
        self.assertIn("'has-content' : 'is-blank'", BILL)
        self.assertIn('class="pos-bill-signoff-value is-blank"', BILL)
        self.assertIn('Signature:</span>\n      <span class="pos-bill-signoff-value is-blank">_____________________________</span>', BILL)
        self.assertIn('<div class="pos-bill-meta">', BILL)
        self.assertIn('<div class="pos-bill-cashier"><b>Cashier:</b> {{ $order->user?->name ?: \'—\' }}</div>', BILL)
        self.assertLess(BILL.index('Waiter Name:'), BILL.index('GUEST DETAILS &amp; SIGN-OFF'))
        self.assertNotIn('<dl class="pos-bill-meta">', BILL)
        for contact in ("$currentHotel->address", "$currentHotel->city", "$currentHotel->phone", "$currentHotel->email", "$currentHotel->tax_number"):
            with self.subTest(property_contact=contact):
                self.assertIn(contact, BILL)
        for label in ("Bill:", "Order:", "Date:", "Table:", "Room:", "Covers:", "Waiter Name:"):
            with self.subTest(metadata=label):
                self.assertIn(label, BILL)

    def test_receipt_values_are_dynamic_and_include_legacy_single_mode_settlements(self):
        for expression in (
            "$p->paymentMode?->name ?? 'Payment'",
            "number_format($p->amount, 2)",
            "$p->tendered_amount !== null",
            "-{{ number_format($p->change_amount, 2) }}",
            "$order->paymentMode?->name ?? 'Payment'",
            "$order->paidAmount()",
            "$order->balanceDue()",
            "$order->user?->name",
            "$order->steward",
            "$order->stay?->room?->room_no",
            "$order->customer_name",
        ):
            with self.subTest(expression=expression):
                self.assertIn(expression, BILL)
        self.assertIn("$order->settlement === 'payment'", BILL)
        self.assertIn("$order->payments->where('kind', 'refund')", BILL)
        for sample_value in ("Dzidefo", "605.00", "Mobile Money Restaurant"):
            with self.subTest(sample_value=sample_value):
                self.assertNotIn(sample_value, BILL)

    def test_bill_print_styles_preserve_columns_and_prevent_awkward_page_splits(self):
        for selector in (
            ".print-page.thermal.pos-bill",
            ".print-page.pos-bill .pos-bill-items th",
            ".print-page.pos-bill .pos-bill-receipt-table th",
            ".print-page.pos-bill .pos-bill-receipt-total td",
            ".print-page.pos-bill .pos-bill-cashier",
            ".print-page.pos-bill .pos-bill-signoff",
            ".print-page.pos-bill .pos-bill-signoff-fields",
            ".print-page.pos-bill .pos-bill-signoff-field",
            "grid-template-columns: 82px minmax(0,1fr)",
            ".print-page.pos-bill .pos-bill-signoff-value",
            ".pos-bill-signoff-value.is-blank",
            "white-space: nowrap; overflow: hidden;",
            ".print-page.pos-bill #billEvat div[style*=\"text-align:left\"]",
            "display: table-header-group",
            "break-inside: avoid",
        ):
            with self.subTest(selector=selector):
                self.assertIn(selector, CSS)
        self.assertIn("@section('pageclass', 'thermal pos-bill')", BILL)
        self.assertIn("font-family: var(--mono)", CSS)
        self.assertIn("KOT", KOT)


if __name__ == "__main__":
    unittest.main(verbosity=2)
