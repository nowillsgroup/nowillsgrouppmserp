#!/usr/bin/env python3
"""Static regression audit for the printable Front Office Guest Registration Card."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
VIEW = (ROOT / "resources/views/frontdesk/grc.blade.php").read_text()
CONTROLLER = (ROOT / "app/Http/Controllers/FrontDeskController.php").read_text()
ROUTES = (ROOT / "routes/web.php").read_text()


class FrontDeskGrcPolishTest(unittest.TestCase):
    def test_print_card_has_clear_reservation_identity_and_stay_sections(self):
        for marker in (
            "@extends('layouts.print')", "@section('doctitle', 'Guest Registration Card')",
            'id="guestRegistrationCard"', 'aria-labelledby="grcIdentityTitle"',
            'aria-labelledby="grcIdentityDocumentTitle"', 'aria-labelledby="grcStayTitle"',
            'Reservation <b>{{ $stay->reservation->reservation_no }}</b>',
            'Scheduled departure', 'Room rate', 'Booking source',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)

    def test_contact_identity_empty_values_and_address_are_rendered_safely(self):
        for marker in (
            "$address = collect([", "->filter(fn ($part) => filled($part))",
            "config('pms.id_types.'.$g->id_type, $g->id_type)",
            'Mobile', 'Email', 'Nationality', 'Passport number', 'Visa expiry',
            'Not provided', 'Guest TIN',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)
        self.assertNotIn("{{ $g->address }} {{ $g->city }}", VIEW)

    def test_signature_status_documents_terms_and_print_metadata_are_available(self):
        for marker in (
            "Signature <b>{{ $stay->grc_signed_at ? 'On file' : 'Pending' }}</b>",
            'Guest signature on file', 'E-signed {{ $stay->grc_signed_at->format',
            'Front-office representative', 'Document types on file',
            "setting('grc_terms')", 'Guest declaration &amp; terms',
            "now()->format('d M Y H:i')", '@media print',
            '@media(max-width:700px)', 'break-inside:avoid',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)

    def test_controller_eager_loads_document_types_and_route_keeps_frontdesk_view_gate(self):
        grc = CONTROLLER[CONTROLLER.index('public function grc(ReservationRoom $stay)'):]
        self.assertIn("$stay->load('reservation.guest.documents'", grc)
        self.assertIn("return view('frontdesk.grc'", grc)
        self.assertIn("Route::get('/stay/{stay}/grc', 'grc')->name('grc')", ROUTES)
        self.assertIn("Route::middleware('perm:frontdesk.view')->group(function ()", ROUTES)


if __name__ == "__main__":
    unittest.main(verbosity=2)
