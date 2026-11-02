#!/usr/bin/env python3
"""Regression checks for reservation-detail table and date accessibility."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
VIEW = (ROOT / "resources/views/reservations/show.blade.php").read_text()


class ReservationDetailAccessibilityTest(unittest.TestCase):
    def test_core_reservation_tables_have_captions_and_scoped_headers(self):
        for caption in (
            'Room and stay details for reservation {{ $r->reservation_no }}',
            'Advance payments recorded for reservation {{ $r->reservation_no }}',
            'Online payment links for reservation {{ $r->reservation_no }}',
            'Field changes in reservation history version {{ $amendment->version }}',
        ):
            with self.subTest(caption=caption):
                self.assertIn(f'<caption class="sr-only">{caption}</caption>', VIEW)
        self.assertEqual(VIEW.count('scope="col"'), 20)

    def test_reservation_and_transaction_dates_are_machine_readable(self):
        for fragment in (
            '$r->created_at->toIso8601String()',
            '$r->arrival_date->toDateString()',
            '$r->departure_date->toDateString()',
            '$r->cancelled_at->toIso8601String()',
            '$s->arrival_date->toDateString()',
            '$s->departure_date->toDateString()',
            '$a->business_date->toDateString()',
            '$op->created_at->toIso8601String()',
            '$amendment->created_at->toIso8601String()',
        ):
            with self.subTest(fragment=fragment):
                self.assertIn(fragment, VIEW)
        self.assertIn("$s->arrival_date->format('d M Y')", VIEW)
        self.assertIn("$s->departure_date->format('d M Y')", VIEW)
        self.assertIn("$op->created_at->format('d M Y H:i')", VIEW)

    def test_icon_only_room_actions_have_accessible_names(self):
        self.assertIn('aria-label="Cancel room {{ $s->room?->room_no ?? $s->roomType->name }}"', VIEW)
        self.assertIn('aria-label="Print invoice {{ $s->folio->invoice_no }}"', VIEW)
        self.assertIn('aria-label="Open registration card for room {{ $s->room?->room_no ?? \'stay\' }}"', VIEW)
        self.assertIn('aria-label="e-GRC: guest signature and ID scan for room {{ $s->room?->room_no ?? \'stay\' }}"', VIEW)

    def test_confirmation_new_tab_uses_noopener(self):
        self.assertIn('href="{{ route(\'reservations.print\', $r) }}" target="_blank" rel="noopener"', VIEW)
        self.assertIn('target="_blank" rel="noopener" aria-label="Print invoice', VIEW)

    def test_online_checkin_link_copy_is_labeled_and_reports_failures(self):
        self.assertIn('<label class="sr-only" for="reservationCheckinUrl">Guest online check-in link</label>', VIEW)
        self.assertIn('aria-label="Copy guest online check-in link"', VIEW)
        self.assertIn('id="reservationCheckinFeedback" class="sr-only" role="status" aria-live="polite"', VIEW)
        self.assertIn('Online check-in link copied to clipboard.', VIEW)
        self.assertIn('Clipboard unavailable. The link is selected; copy it manually.', VIEW)


if __name__ == "__main__":
    unittest.main(verbosity=2)
