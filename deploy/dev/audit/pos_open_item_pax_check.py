#!/usr/bin/env python3
"""Checks the POS open-item form shows order covers/pax separately from billing quantity."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
VIEW = (ROOT / 'resources/views/pos/terminal.blade.php').read_text()


class PosOpenItemPaxTest(unittest.TestCase):
    def test_quantity_and_read_only_covers_reference_are_separate_fields(self):
        self.assertIn('Price per item', VIEW)
        self.assertIn('<label for="openQty">Quantity</label>', VIEW)
        self.assertIn('id="openQty" type="number" min="0.5" max="99" step="0.5" value="1"', VIEW)
        self.assertIn('<label for="openCovers">Covers / Pax</label>', VIEW)
        self.assertIn('id="openCovers" type="number" value="{{ (int) $order->covers }}" readonly', VIEW)
        self.assertIn('Order covers, for reference only.', VIEW)
        self.assertNotIn('name="openCovers"', VIEW, 'Covers/pax is informational and is not submitted for billing')

    def test_repeated_open_item_quantity_returns_to_its_original_default(self):
        self.assertIn("document.getElementById('openQty').value = '1';", VIEW)
        self.assertNotIn('dataset.orderCovers', VIEW)
        self.assertIn('${esc(posCurrencySymbol)} ${Number(c.price).toFixed(2)} each', VIEW)

    def test_open_item_modal_has_a_direct_preview_link_state(self):
        self.assertIn("request('modal') === 'open-item' ? ' show' : ''", VIEW)
        self.assertIn("if (posRequestedModal === 'open-item') openModal('openModal');", VIEW)


if __name__ == '__main__':
    unittest.main(verbosity=2)
