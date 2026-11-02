#!/usr/bin/env python3
"""Static regression audit for the e-GRC signature and ID-document page."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
VIEW = (ROOT / "resources/views/frontdesk/grc-sign.blade.php").read_text()
CONTROLLER = (ROOT / "app/Http/Controllers/GrcController.php").read_text()
ROUTES = (ROOT / "routes/web.php").read_text()


class FrontDeskGrcSignPolishTest(unittest.TestCase):
    def test_actual_egrc_page_has_guest_stay_terms_and_identity_sections(self):
        for marker in (
            "@extends('layouts.app')", 'class="egrc-page"', 'Guest identity &amp; contact',
            'Reservation &amp; stay details', 'Guest declaration &amp; terms',
            'aria-labelledby="egrcSignatureTitle"', 'aria-labelledby="egrcDocumentsTitle"',
            'Passport number', 'Visa expiry', 'Purpose of visit',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)

    def test_signature_form_keeps_existing_endpoint_and_acceptance_contract(self):
        for marker in (
            "route('frontdesk.grc.signature.store', $stay)", 'name="signature"',
            'name="accept" value="1" required', 'id="sigPad"', 'id="sigClear"',
            "canvas.toDataURL('image/png')", "'accept' => 'accepted'",
            "'starts_with:data:image/png;base64,'", 'Guest signature', 'Signature pending',
        ):
            source = VIEW if marker not in CONTROLLER else CONTROLLER
            with self.subTest(marker=marker):
                self.assertIn(marker, source)
        self.assertIn("id=\"sigPad\" class=\"egrc-sign-pad\" width=\"900\" height=\"300\"", VIEW)
        self.assertIn('aspect-ratio:3/1', VIEW)
        self.assertIn("hasInk = false", VIEW)
        self.assertIn("save.disabled = false", VIEW)

    def test_guest_document_upload_and_delete_controls_remain_permission_aware(self):
        self.assertIn("route('guests.documents.store', $g)", VIEW)
        self.assertIn("@include('guests._documents', ['docs' => $documents])", VIEW)
        self.assertIn("can_do('guests.manage')", (ROOT / "resources/views/guests/_documents.blade.php").read_text())
        self.assertIn("Route::post('/guests/{guest}/documents'", ROUTES)
        self.assertIn('public function upload(', CONTROLLER)

    def test_existing_route_permissions_and_permission_aware_reservation_link(self):
        self.assertIn("Route::get('/stay/{stay}/grc/sign', 'sign')->middleware('perm:frontdesk.checkin')", ROUTES)
        self.assertIn("Route::post('/stay/{stay}/grc/sign', 'saveSignature')->middleware('perm:frontdesk.checkin')", ROUTES)
        self.assertIn("@if(can_do('reservations.view'))<a href=\"{{ route('reservations.show', $stay->reservation) }}\"", VIEW)
        self.assertIn("$stay->load('reservation.guest.documents'", CONTROLLER)


if __name__ == "__main__":
    unittest.main(verbosity=2)
