#!/usr/bin/env python3
"""Regression checks for the public, guest-facing online payment page."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
PAY_VIEW = (ROOT / "resources/views/pay/show.blade.php").read_text()
DEMO_VIEW = (ROOT / "resources/views/pay/demo.blade.php").read_text()
PAY_CONTROLLER = (ROOT / "app/Http/Controllers/PayController.php").read_text()


class PublicPaymentPageTest(unittest.TestCase):
    def test_full_and_partial_refunds_are_not_shown_as_cancelled_or_due(self):
        full = PAY_VIEW.index("@elseif($st === 'refunded')")
        partial = PAY_VIEW.index("@elseif($st === 'part_refunded')")
        paid = PAY_VIEW.index("@elseif($p->status === 'paid')")
        self.assertLess(full, paid)
        self.assertLess(partial, paid)
        self.assertIn('This payment was fully refunded.', PAY_VIEW)
        self.assertIn('A partial refund was issued.', PAY_VIEW)
        self.assertIn('No further payment is due on this link.', PAY_VIEW)
        self.assertIn('This page remains a receipt for the original payment.', PAY_VIEW)

    def test_pending_gateway_refunds_are_not_claimed_as_returned(self):
        self.assertIn("$refunds = $p->refunds;", PAY_CONTROLLER)
        self.assertIn("'processedRefundAmount' => (float) $refunds->where('status', 'processed')->sum('amount')", PAY_CONTROLLER)
        self.assertIn("'pendingRefundAmount' => (float) $refunds->where('status', 'pending')->sum('amount')", PAY_CONTROLLER)
        self.assertIn('A partial refund is in progress.', PAY_VIEW)
        self.assertIn('The full refund is still being processed.', PAY_VIEW)
        self.assertIn('is still pending with the gateway.', PAY_VIEW)
        self.assertIn('<dt>Refund total</dt>', PAY_VIEW)

    def test_reservation_and_payment_deadlines_show_full_year(self):
        self.assertIn("$r->arrival_date->format('d M Y')", PAY_VIEW)
        self.assertIn("$r->departure_date->format('d M Y')", PAY_VIEW)
        self.assertIn("$p->expires_at->format('d M Y, H:i')", PAY_VIEW)
        self.assertIn('<dt>Expired on</dt>', PAY_VIEW)
        self.assertIn("$p->expires_at->toIso8601String()", PAY_VIEW)

    def test_booking_hold_message_shows_date_and_time(self):
        self.assertIn("held until {{ $p->expires_at->format('d M Y, H:i') }}", PAY_VIEW)

    def test_guest_card_details_stay_on_the_gateway(self):
        self.assertIn('You will be taken to', PAY_VIEW)
        self.assertIn("{{ $hotel->name }} never sees your card details.", PAY_VIEW)
        for sensitive_name in ('card_number', 'cardNumber', 'cvv', 'card_cvc'):
            with self.subTest(sensitive_name=sensitive_name):
                self.assertNotIn(f'name="{sensitive_name}"', PAY_VIEW)

    def test_public_payment_summary_has_descriptive_amount_and_definition_list(self):
        self.assertIn("in_array($st, ['paid', 'part_refunded', 'refunded'], true) ? 'RECEIPT' : 'PAYMENT REQUEST'", PAY_VIEW)
        self.assertIn("in_array($st, ['paid', 'part_refunded', 'review'], true) ? 'AMOUNT RECEIVED' : ($p->isPayable() ? 'AMOUNT DUE' : 'ORIGINAL AMOUNT')", PAY_VIEW)
        self.assertIn('$p->paid_at->toIso8601String()', PAY_VIEW)
        self.assertIn('$p->expires_at->toIso8601String()', PAY_VIEW)
        self.assertIn('<dl class="kv-list">', PAY_VIEW)
        for item in ('For', 'Name', 'Reservation', 'Paid on', 'Pay by', 'Refund total'):
            with self.subTest(item=item):
                self.assertIn(f'<dt>{item}</dt>', PAY_VIEW)
        self.assertIn('.kv-list dt{', PAY_VIEW)
        self.assertIn('.kv-list dd{margin:0;', PAY_VIEW)

    def test_test_gateway_payment_method_radios_remain_keyboard_and_screen_reader_accessible(self):
        self.assertIn('<fieldset class="tabs3">', DEMO_VIEW)
        self.assertIn('<legend class="sr-only">Payment method</legend>', DEMO_VIEW)
        self.assertIn('.tabs3 input{position:absolute;', DEMO_VIEW)
        self.assertIn('.tabs3 label:focus-within{outline:3px solid', DEMO_VIEW)
        self.assertNotIn('.tabs3 input{display:none}', DEMO_VIEW)

    def test_public_payment_response_is_private_and_not_indexed(self):
        self.assertIn("->header('Referrer-Policy', 'no-referrer')", PAY_CONTROLLER)
        self.assertIn("->header('X-Robots-Tag', 'noindex, nofollow')", PAY_CONTROLLER)
        self.assertIn("->header('Cache-Control', 'no-store, private')", PAY_CONTROLLER)


if __name__ == "__main__":
    unittest.main(verbosity=2)
