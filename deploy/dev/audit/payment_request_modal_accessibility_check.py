#!/usr/bin/env python3
"""Accessibility checks for the shared online payment-request modal."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
MODAL = (ROOT / "resources/views/payments/_request-modal.blade.php").read_text()
RESERVATION = (ROOT / "resources/views/reservations/show.blade.php").read_text()
FOLIO = (ROOT / "resources/views/folios/show.blade.php").read_text()


class PaymentRequestModalAccessibilityTest(unittest.TestCase):
    def test_modal_is_a_named_dialog_with_an_accessible_close_control(self):
        self.assertIn('id="payLinkModal" role="dialog" aria-modal="true" aria-labelledby="payLinkModalTitle"', MODAL)
        self.assertIn('<h3 id="payLinkModalTitle">Request online payment</h3>', MODAL)
        self.assertIn('data-close aria-label="Close dialog" title="Close dialog"', MODAL)

    def test_every_text_control_has_an_associated_label(self):
        for control in ('payAmt', 'payLinkHours', 'payLinkDescription', 'payLinkEmail', 'payLinkPhone'):
            with self.subTest(control=control):
                self.assertIn(f'<label for="{control}">', MODAL)
                self.assertIn(f'id="{control}"', MODAL)
        self.assertIn('role="group" aria-labelledby="payLinkSendLegend"', MODAL)
        self.assertIn('<span id="payLinkSendLegend"', MODAL)

    def test_reservation_and_folio_openers_identify_the_dialog(self):
        opener = 'data-modal="payLinkModal" aria-haspopup="dialog" aria-controls="payLinkModal"'
        self.assertIn(opener, RESERVATION)
        self.assertIn(opener, FOLIO)


if __name__ == "__main__":
    unittest.main(verbosity=2)
