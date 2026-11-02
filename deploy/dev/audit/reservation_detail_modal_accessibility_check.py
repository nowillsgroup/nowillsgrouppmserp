#!/usr/bin/env python3
"""Regression checks for reservation-detail modal semantics and controls."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
VIEW = (ROOT / "resources/views/reservations/show.blade.php").read_text()


class ReservationDetailModalAccessibilityTest(unittest.TestCase):
    def test_all_reservation_modals_are_named_dialogs(self):
        modal_titles = {
            'cancelModal': 'reservationCancelTitle',
            'noshowModal': 'reservationNoShowTitle',
            'advModal': 'reservationAdvanceTitle',
            'assignModal': 'reservationAssignTitle',
            'smsModal': 'reservationSmsTitle',
            'mailModal': 'reservationMailTitle',
        }
        for modal, title in modal_titles.items():
            with self.subTest(modal=modal):
                self.assertIn(f'id="{modal}" role="dialog" aria-modal="true" aria-labelledby="{title}"', VIEW)
                self.assertIn(f'<h3 id="{title}">', VIEW)

    def test_modal_close_icons_have_accessible_names(self):
        close = 'class="btn btn-ghost btn-icon" data-close aria-label="Close dialog" title="Close dialog"'
        self.assertEqual(VIEW.count(close), 6)

    def test_modal_openers_identify_the_dialog(self):
        for modal in ('cancelModal', 'noshowModal', 'advModal', 'assignModal', 'smsModal', 'mailModal'):
            with self.subTest(modal=modal):
                self.assertIn(f'data-modal="{modal}" aria-haspopup="dialog" aria-controls="{modal}"', VIEW)

    def test_assign_room_selects_have_associated_labels(self):
        self.assertIn('<label for="assignRoom{{ $s->id }}">', VIEW)
        self.assertIn('<select id="assignRoom{{ $s->id }}" name="assign[{{ $s->id }}]">', VIEW)

    def test_recent_message_recipient_and_error_details_are_log_permission_gated(self):
        self.assertIn("$smsLogs = can_do('logs.view') ? \\App\\Models\\MessageLog::where('related_type', 'Reservation')", VIEW)
        self.assertIn("@if(can_do('logs.view') && $smsLogs->isNotEmpty())", VIEW)
        self.assertIn('{{ $m->to }}', VIEW)
        self.assertIn('{{ $m->error }}', VIEW)

    def test_dynamic_split_advance_fields_have_unique_associated_labels(self):
        for control, label in (
            ('advancePaymentMethod', 'Payment method'),
            ('advanceAmount', 'Amount'),
            ('advanceMomoNetwork', 'MoMo network'),
            ('advanceMomoPhone', 'MoMo phone'),
            ('advanceReference', 'Reference'),
            ('advanceChequeBank', 'Bank name'),
            ('advanceChequeNumber', 'Cheque number'),
            ('advanceChequeBranch', 'Bank branch'),
        ):
            with self.subTest(control=control):
                self.assertIn(f'<label for="{control}${{i}}">{label}</label>', VIEW)
                self.assertIn(f'id="{control}${{i}}"', VIEW)
        self.assertIn('aria-label="Remove tender ${i + 1}"', VIEW)


if __name__ == "__main__":
    unittest.main(verbosity=2)
