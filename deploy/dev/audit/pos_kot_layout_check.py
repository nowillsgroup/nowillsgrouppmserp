#!/usr/bin/env python3
"""Checks readable 80 mm KOT typography while retaining a compact 58 mm variant."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
CSS = (ROOT / 'public/css/app.css').read_text()
SLIP = (ROOT / 'resources/views/pos/kot-slip.blade.php').read_text()
LINES = (ROOT / 'resources/views/pos/kot-lines.blade.php').read_text()


class PosKotLayoutTest(unittest.TestCase):
    def test_80mm_slip_uses_readable_type_and_a_strong_quantity_column(self):
        self.assertIn('max-width: 320px', CSS)
        self.assertIn('font-size: 13px; line-height: 1.4', CSS)
        self.assertIn('.kot-slip-title { display: inline-block; font-size: 17px;', CSS)
        self.assertIn('.kot-items .kot-item-qty { width: 42px;', CSS)
        self.assertIn('table-layout: fixed', CSS)

    def test_58mm_variant_remains_compact(self):
        self.assertIn('.kot-slip.w58 { max-width: 220px; padding: .65rem; font-size: 11px;', CSS)
        self.assertIn('.kot-slip.w58 .kot-items .kot-item-qty { width: 32px;', CSS)
        self.assertIn("($p->paper ?? '80') === '58' ? ' w58' : ''", SLIP)

    def test_item_details_and_quantity_heading_remain_present_in_printed_slips(self):
        for marker in ('HOLD', 'SPECIAL', 'Side dishes:', 'Options:', 'kot-item-note', 'kot-item-allergy'):
            with self.subTest(marker=marker):
                self.assertIn(marker, LINES)
        self.assertIn('aria-label="Quantity"', LINES)
        self.assertIn('>Qty</th>', LINES)
        self.assertIn('>Item</th>', LINES)
        self.assertIn('.kot-item-qty-heading { width: 42px;', CSS)
        for label in ('KOT No.', 'Date', 'Time', 'Table No.', 'Order No.'):
            with self.subTest(label=label):
                self.assertIn(f'>{label}</span>', SLIP)
        self.assertIn("$kotPrintedAt->format('d/m/Y')", SLIP)
        self.assertIn("$kotPrintedAt->format('H:i')", SLIP)
        self.assertIn('.kot-slip-meta-primary { grid-template-columns: repeat(3, minmax(0, 1fr)); }', CSS)
        self.assertIn('<b>Covers / Pax:</b> {{ $order->covers }}', SLIP)
        self.assertIn('Waiter {{ $order->steward }}', SLIP)
        self.assertNotIn('Steward {{ $order->steward }}', SLIP)

    def test_prints_remain_separate_thermal_slips(self):
        self.assertIn('.kot-slip { border: 0 !important; margin: 0; max-width: none; break-after: page; }', CSS)
        self.assertIn('.kot-slip:last-child { break-after: auto; }', CSS)
        self.assertIn('.no-print', CSS)


if __name__ == '__main__':
    unittest.main(verbosity=2)
