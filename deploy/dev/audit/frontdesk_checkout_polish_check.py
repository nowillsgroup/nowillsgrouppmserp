#!/usr/bin/env python3
"""Static regression audit for the actual Front Desk Check-out workflow."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
VIEW = (ROOT / "resources/views/frontdesk/checkout.blade.php").read_text()
CONTROLLER = (ROOT / "app/Http/Controllers/FrontDeskController.php").read_text()
STAY_SERVICE = (ROOT / "app/Services/StayService.php").read_text()
ROUTES = (ROOT / "routes/web.php").read_text()


class FrontDeskCheckoutPolishTest(unittest.TestCase):
    def test_actual_checkout_overview_uses_stay_dates_room_and_live_settlement_data(self):
        for marker in (
            'aria-label="Check-out overview"', 'Check out {{ $guestName }}',
            'aria-label="Stay dates"', 'datetime="{{ $stay->arrival_date->toDateString() }}"',
            'datetime="{{ $checkoutDate->toDateString() }}"',
            'aria-label="Check-out settlement summary"', 'Projected balance due',
            'Room nights not yet posted', 'Checkout blocking items',
            "$checkoutFigures['missing']", "$checkoutDue ?? $currentBalance",
            "$stay->room?->room_no", "$folio->folio_no",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)

    def test_checkout_quote_reuses_the_figures_and_holds_computed_by_the_controller(self):
        form = CONTROLLER[CONTROLLER.index("public function checkOutForm"):CONTROLLER.index("/** Validate and post split checkout")]
        self.assertIn("$checkoutFigures = StayService::quoteCheckoutFigures($stay);", form)
        self.assertIn("$checkoutHolds = StayService::checkoutHolds($stay);", form)
        self.assertIn("StayService::quoteCheckout($stay, $checkoutFigures, $checkoutHolds)", form)
        self.assertIn("'checkoutFigures' => $checkoutFigures", form)
        self.assertIn("'checkoutHolds' => $checkoutHolds", form)
        self.assertNotIn("StayService::checkoutHolds($stay)", VIEW)
        self.assertIn("?array $fig = null, ?array $holds = null", STAY_SERVICE)

    def test_folio_details_actions_and_post_checkout_redirect_follow_permissions(self):
        for marker in (
            "@if($canViewFolio)<a class=\"btn\" href=\"{{ route('folios.show', $folio) }}\"",
            "@if($canViewFolio)\n  <div class=\"card flush checkout-ledger\">",
            "@if(can_do('reservations.view'))<a class=\"btn btn-soft\" href=\"{{ route('reservations.show', $stay->reservation_id) }}\"",
            "'transactions' => $canViewFolio ? $folio->transactions()",
            "if (can_do('folio.view')) {\n            return redirect()->route('folios.invoice'",
            "if (can_do('reservations.view')) {\n            return redirect()->route('reservations.show'",
            "if (can_do('frontdesk.view')) {\n            return redirect()->route('frontdesk.departures'",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW + CONTROLLER)
        self.assertIn("Route::middleware('perm:frontdesk.checkout')->group(function ()", ROUTES)

    def test_operational_detail_links_match_their_screen_permissions(self):
        for marker in (
            "@if(can_do('pos.view'))@foreach($openPos as $o)<a href=\"{{ route('pos.terminal', $o) }}\"",
            "@if(can_do('laundry.view'))@foreach($openLaundry as $lo)<a href=\"{{ route('laundry.show', $lo) }}\"",
            "@if(can_do('parking.view') || can_do('parking.operate') || can_do('parking.validate') || can_do('parking.manage'))@foreach($openParking as $pk)<a href=\"{{ route('parking.session', $pk) }}\"",
            "@if(can_do('transport.view'))@foreach($openTrips as $tt)<a href=\"{{ route('tr.show', $tt) }}\"",
            "@if(can_do('desk.messages'))<b>Hand over before the guest leaves:",
            "@if(can_do('lostfound.manage'))@foreach($deskItems as $li)<a href=\"{{ route('lostfound.show'",
            "@if(can_do('formc.view'))for @foreach($formcOpen as $fc)<a href=\"{{ route('fg.formc.show'",
            "can_do('forex.exchange') && $totals['balance'] > 0",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)

    def test_existing_checkout_settlement_and_operational_gates_remain(self):
        for marker in (
            "route('frontdesk.checkout.store', $stay)", 'id="settleBy"',
            'id="checkoutSinglePayment"', 'id="checkoutSplitPayment"',
            'id="checkoutPaymentAllocations"', 'Settle & Check-out',
            "@if ($holds['blocking'] > 0)", "route('frontdesk.prepare', $stay)",
            "StayService::checkOut($stay->fresh(), false)",
            "return $this->checkOutSuccessRedirect($stay->fresh()",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW + CONTROLLER)

    def test_page_has_responsive_dark_mode_reduced_motion_and_print_styles(self):
        for marker in (
            '@media(max-width:1100px)', '@media(max-width:700px)',
            '@media(prefers-reduced-motion:reduce)', '@media print',
            'body.dark .checkout-hero', 'body.dark .checkout-ledger-table th',
            'overflow-x:auto',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)


if __name__ == "__main__":
    unittest.main(verbosity=2)
