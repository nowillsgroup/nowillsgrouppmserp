#!/usr/bin/env python3
"""Regression checks for the guest-facing printed booking confirmation."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
VIEW = (ROOT / "resources/views/reservations/print.blade.php").read_text()


class ReservationPrintConfirmationTest(unittest.TestCase):
    def test_confirmation_identifies_booking_guest_and_stay(self):
        self.assertIn("Booking confirmation · '.$r->reservation_no", VIEW)
        self.assertIn("$r->reservation_no", VIEW)
        self.assertIn("$r->guest?->full_name ?? 'Guest'", VIEW)
        self.assertIn("$r->guest?->phone ?: 'Not provided'", VIEW)
        self.assertIn("$r->guest?->email ?: 'Not provided'", VIEW)
        self.assertIn("Check-in {{ $arrivalTime }}", VIEW)
        self.assertIn("Check-out {{ $departureTime }}", VIEW)
        self.assertIn("$r->status === 'tentative'", VIEW)
        self.assertIn("$r->status === 'cancelled'", VIEW)
        self.assertIn("$r->status === 'no_show'", VIEW)
        self.assertIn("@if(in_array($r->status, ['confirmed', 'tentative']))", VIEW)
        self.assertIn("Thank you for staying with us.", VIEW)

    def test_room_table_and_amounts_are_accessible_and_transparent(self):
        self.assertIn("$r->rooms->where('status', '!=', 'cancelled')->values()", VIEW)
        self.assertIn("(float) $stay->rate + (float) $stay->extra_charge", VIEW)
        self.assertIn("$nightlyRate * max(0, (int) $stay->nights)", VIEW)
        self.assertIn('scope="col"', VIEW)
        self.assertIn('scope="row" colspan="6">Room total (excluding taxes)', VIEW)
        self.assertIn('caption class="sr-only"', VIEW)
        self.assertIn('Room not yet assigned', VIEW)

    def test_payment_and_refund_history_is_labelled_separately(self):
        self.assertIn("$transactions->where('type', 'payment')->sum('amount')", VIEW)
        self.assertIn("$transactions->where('type', 'refund')->sum('amount')", VIEW)
        self.assertIn('Payments received', VIEW)
        self.assertIn('Refunds recorded', VIEW)
        self.assertIn("$r->folios->flatMap->transactions->where('is_void', false)", VIEW)

    def test_policy_is_property_configured_not_a_hard_coded_cancellation_promise(self):
        self.assertIn("$bookingPolicy = trim((string) setting('booking_policy'))", VIEW)
        self.assertIn("@if($bookingPolicy !== '')", VIEW)
        self.assertIn("nl2br(e($bookingPolicy))", VIEW)
        self.assertNotIn('free cancellation up to 48 hours', VIEW.lower())

    def test_print_specific_layout_repeats_table_headers_and_avoids_split_rows(self):
        self.assertIn("@section('pageclass', 'booking-confirmation')", VIEW)
        self.assertIn("@section('backurl', route('reservations.show', $r))", VIEW)
        self.assertIn("$__env->yieldContent('backurl', url()->previous())", (ROOT / "resources/views/layouts/print.blade.php").read_text())
        self.assertIn('@page { size: A4; margin: 14mm; }', VIEW)
        self.assertIn('.bc-rooms thead { display: table-header-group; }', VIEW)
        self.assertIn('.bc-rooms tr { break-inside: avoid; page-break-inside: avoid; }', VIEW)
        self.assertIn('.print-page.booking-confirmation { width: 100%; max-width: none; padding: 0;', VIEW)

    def test_downloadable_pdf_uses_the_same_status_and_property_policy(self):
        pdf = (ROOT / "app/Support/BookingConfirmationPdf.php").read_text()
        self.assertIn("'cancelled' => 'This reservation has been cancelled.", pdf)
        self.assertIn("'tentative' => 'Thank you for choosing ", pdf)
        self.assertIn("$bookingPolicy = trim((string) setting('booking_policy'))", pdf)
        self.assertIn("BOOKING AND CANCELLATION POLICY", pdf)
        self.assertIn("Room total (excluding taxes)", pdf)
        self.assertIn("Payments received", pdf)
        self.assertIn("Refunds recorded", pdf)
        self.assertIn("'Booked on '.$this->bookedOn", pdf)
        self.assertNotIn("Cancellation policy: free cancellation up to 48 hours", pdf)


if __name__ == "__main__":
    unittest.main(verbosity=2)
