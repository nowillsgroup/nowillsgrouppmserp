#!/usr/bin/env python3
"""Regression checks for reservation confirmation and registration actions."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
VIEW = (ROOT / "resources/views/reservations/show.blade.php").read_text()


class ReservationConfirmationDropdownTest(unittest.TestCase):
    def test_confirmation_actions_use_a_native_accessible_disclosure(self):
        self.assertIn('<details class="reservation-confirmation-menu">', VIEW)
        self.assertIn('<summary class="btn"><x-icon name="print"/> Confirmation', VIEW)
        self.assertIn('<div class="dropdown" role="group" aria-label="Confirmation actions">', VIEW)
        self.assertIn('.reservation-confirmation-menu[open] > .dropdown { display: block; }', (ROOT / "public/css/app.css").read_text())

    def test_print_and_pdf_actions_have_separate_routes(self):
        self.assertIn('href="{{ route(\'reservations.print\', $r) }}" target="_blank" rel="noopener"><x-icon name="print"/> Print Booking Confirmation', VIEW)
        self.assertIn("route('reservations.pdf', $r)", VIEW)
        self.assertIn('Download Booking Confirmation PDF', VIEW)
        self.assertNotIn('autoprint=1', VIEW)

    def test_pdf_action_returns_a_private_attachment_without_external_dependencies(self):
        controller = (ROOT / "app/Http/Controllers/ReservationController.php").read_text()
        renderer = (ROOT / "app/Support/BookingConfirmationPdf.php").read_text()
        routes = (ROOT / "routes/web.php").read_text()
        self.assertIn("Route::get('/{reservation}/pdf', 'downloadConfirmationPdf')->middleware('perm:reservations.view')->name('pdf')", routes)
        self.assertIn("'Content-Type' => 'application/pdf'", controller)
        self.assertIn("'Content-Disposition' => 'attachment; filename=", controller)
        self.assertIn("'Cache-Control' => 'private, no-store, max-age=0'", controller)
        self.assertIn('/BaseFont /Helvetica', renderer)
        self.assertIn('startxref', renderer)
        self.assertIn('WinAnsiEncoding', renderer)

    def test_registration_forms_are_permission_gated_and_per_active_stay(self):
        self.assertIn("@if(can_do('frontdesk.view'))", VIEW)
        self.assertIn("$r->rooms->reject(fn ($stay) => in_array($stay->status, ['cancelled', 'no_show']))->values()", VIEW)
        self.assertIn("@foreach($registrationStays as $registrationStay)", VIEW)
        self.assertIn("route('frontdesk.grc', $registrationStay)", VIEW)
        self.assertIn("$registrationStays->count() > 1", VIEW)
        self.assertIn('Print Guest Registration Form', VIEW)
        self.assertIn('target="_blank" rel="noopener"><x-icon name="book"/>', VIEW)


if __name__ == "__main__":
    unittest.main(verbosity=2)
