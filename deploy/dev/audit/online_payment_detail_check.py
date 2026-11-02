#!/usr/bin/env python3
"""Regression checks for staff Online Payment details and action modals."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
VIEW = (ROOT / "resources/views/payments/show.blade.php").read_text()


class OnlinePaymentDetailTest(unittest.TestCase):
    def test_send_and_refund_fields_have_associated_labels(self):
        for control, label in (
            ('payUrl', 'Guest payment link'),
            ('paymentSendEmail', 'E-mail'),
            ('paymentSendPhone', 'Mobile'),
            ('refundAmount', 'Amount'),
            ('refundReason', 'Reason'),
            ('acceptPaymentNote', 'Note'),
            ('cancelPaymentReason', 'Reason'),
        ):
            with self.subTest(control=control):
                label_fragment = (
                    f'<label class="sr-only" for="{control}">{label}</label>'
                    if control == 'payUrl'
                    else f'<label for="{control}">{label}'
                )
                self.assertIn(label_fragment, VIEW)
                self.assertIn(f'id="{control}"', VIEW)

    def test_refund_table_has_caption_scoped_headers_and_full_timestamps(self):
        self.assertIn('<caption class="sr-only">Refunds for payment {{ $p->pay_no }}</caption>', VIEW)
        self.assertEqual(VIEW.count('scope="col"'), 6)
        self.assertIn('$rf->created_at->toIso8601String()', VIEW)
        self.assertIn("$rf->created_at->format('d M Y, H:i')", VIEW)

    def test_all_action_modal_close_icons_have_accessible_names(self):
        close_button = 'class="btn btn-ghost btn-icon" data-close aria-label="Close dialog" title="Close dialog"'
        self.assertEqual(VIEW.count(close_button), 3)
        for modal in ('refund', 'accept', 'cancel'):
            with self.subTest(modal=modal):
                self.assertIn(f'role="dialog" aria-modal="true" aria-labelledby="{modal}ModalTitle"', VIEW)
                self.assertIn(f'id="{modal}ModalTitle"', VIEW)

    def test_payment_copy_feedback_is_announced(self):
        self.assertIn('aria-live="polite"', VIEW)
        self.assertIn('role="status" aria-live="polite"', VIEW)
        self.assertIn('aria-label="Copy payment link"', VIEW)
        self.assertIn('Payment link copied to clipboard.', VIEW)
        self.assertIn('Clipboard unavailable. The link is selected; copy it manually.', VIEW)
        self.assertIn('if (!navigator.clipboard?.writeText) throw new Error', VIEW)

    def test_payment_timeline_dates_are_machine_readable_and_include_year(self):
        self.assertIn('$p->created_at->toIso8601String()', VIEW)
        self.assertIn('$p->paid_at->toIso8601String()', VIEW)
        self.assertIn('$p->expires_at->toIso8601String()', VIEW)
        self.assertIn('$e->created_at->toIso8601String()', VIEW)
        self.assertIn("$e->created_at->format('d M Y H:i:s')", VIEW)


if __name__ == "__main__":
    unittest.main(verbosity=2)
