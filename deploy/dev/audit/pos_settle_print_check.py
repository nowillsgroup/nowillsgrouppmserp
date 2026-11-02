#!/usr/bin/env python3
"""Check the POS settle-and-print action and its browser print handoff."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
VIEW = (ROOT / 'resources/views/pos/terminal.blade.php').read_text()
CONTROLLER = (ROOT / 'app/Http/Controllers/PosController.php').read_text()
PRINT_LAYOUT = (ROOT / 'resources/views/layouts/print.blade.php').read_text()


class PosSettlePrintTest(unittest.TestCase):
    def test_settle_and_print_button_posts_an_explicit_print_intent(self):
        self.assertIn('name="print_bill" value="1"', VIEW)
        self.assertIn('Settle &amp; Print', VIEW)
        self.assertIn("@if(can_do('pos.view'))", VIEW)
        self.assertIn("abort_unless(! $request->boolean('print_bill') || can_do('pos.view'), 403);", CONTROLLER)

    def test_successful_full_settlement_redirects_to_autoprint_bill(self):
        self.assertIn("'print_bill' => 'nullable|boolean'", CONTROLLER)
        self.assertIn("if ($request->boolean('print_bill') && $result['status'] === 'settled')", CONTROLLER)
        self.assertIn("route('pos.print', $order, false).'?autoprint=1'", CONTROLLER)
        self.assertIn("window.addEventListener('load', () => window.print())", PRINT_LAYOUT)

    def test_partial_payment_does_not_claim_to_print_a_settled_bill(self):
        self.assertIn("Bill was not printed because a balance remains.", CONTROLLER)
        self.assertIn("$result['status'] === 'partial'", CONTROLLER)


if __name__ == '__main__':
    unittest.main(verbosity=2)
