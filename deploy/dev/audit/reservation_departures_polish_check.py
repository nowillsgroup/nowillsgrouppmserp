#!/usr/bin/env python3
"""Static regression checks for the Reservation Desk departures view."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
VIEW = (ROOT / "resources/views/reservations/index.blade.php").read_text()
LINES = (ROOT / "resources/views/reservations/desk-lines.blade.php").read_text()
SERVICE = (ROOT / "app/Services/ReservationDeskService.php").read_text()


class ReservationDeparturesPolishTest(unittest.TestCase):
    def test_departures_have_a_dedicated_operational_hero_and_summary(self):
        for marker in (
            "'departures' => 'Departures'", 'class="departure-hero"',
            'FRONT OFFICE', 'DEPARTURE QUEUE', 'Make every checkout count.',
            'Prepare every departure.', 'Departure date', 'Business date',
            "@if($view !== 'list' && $view !== 'timeline' && $view !== 'board' && $view !== 'arrivals' && $view !== 'departures' && $view !== 'inhouse' && $view !== 'unassigned' && $view !== 'waitlist' && $view !== 'groups' && $view !== 'noshow' && $view !== 'cancelled')",
            'class="departure-summary"', 'Departure summary',
            "$departureSummary['total']", "$departureSummary['still_in_house']",
            "$departureSummary['checked_out']", "$departureSummary['not_yet_arrived']",
            'Print departures',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)

    def test_departure_totals_are_calculated_before_filters_and_actionable_rows_sort_first(self):
        query_at = SERVICE.index("$departures = ReservationRoom::with($departureRelations)")
        summary_at = SERVICE.index("$departureSummary = [", query_at)
        filter_at = SERVICE.index("$departures = self::filterStays($departures, $request, $date)", summary_at)
        self.assertLess(query_at, summary_at)
        self.assertLess(summary_at, filter_at)
        for marker in (
            "'checked_in' => 0", "'reserved' => 1", "'checked_out' => 2",
            "'still_in_house' => $departures->where('status', 'checked_in')->count()",
            "'checked_out' => $departures->where('status', 'checked_out')->count()",
            "'not_yet_arrived' => $departures->where('status', 'reserved')->count()",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, SERVICE)

    def test_departure_facets_route_to_real_status_filters(self):
        for marker in (
            'aria-label="Filter departures"', 'All departures', 'Still in-house',
            'Checked out', 'Not yet checked in', "'focus' => 'still_in_house'",
            "'focus' => 'checked_out'", "'focus' => 'not_yet_arrived'",
            "$departureSummary['total']", "$departureSummary['still_in_house']",
            "$departureSummary['checked_out']", "$departureSummary['not_yet_arrived']",
            "if ($focus === 'still_in_house')", "elseif ($focus === 'checked_out')",
            "elseif ($focus === 'not_yet_arrived')",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW + SERVICE)

    def test_departure_date_navigation_and_search_are_specific_and_accessible(self):
        for marker in (
            'class="departure-toolbar"', 'id="departureDeskDate"',
            'aria-label="Previous departure date"', 'aria-label="Next departure date"',
            'class="departure-today"', 'id="departureSearch"', 'type="search"',
            'name="q"', 'Find a guest or booking', 'role="search"',
            "$departures = self::filterStays($departures, $request, $date)",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW + SERVICE)

    def test_departure_manifest_has_semantic_stay_room_prep_and_balance_columns(self):
        for marker in (
            'class="departure-manifest"', 'id="departureManifestTitle"',
            'class="departure-table"', 'scope="col">Guest &amp; booking',
            'scope="col">Stay', 'scope="col">Room', 'scope="col">Check-out prep',
            'scope="col">Folio &amp; estimate', 'departure-guest-name',
            'departure-booking-ref', 'departure-stay-dates', 'departure-room-number',
            'departure-prep-note', 'datetime="{{ $s->arrival_date->toDateString() }}"',
            'datetime="{{ $s->departure_date->toDateString() }}"',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, LINES)

    def test_checkout_hold_and_estimate_copy_uses_existing_read_only_service_logic(self):
        self.assertIn("\\App\\Services\\StayService::checkoutHolds($s)", LINES)
        self.assertIn("\\App\\Services\\StayService::quoteCheckoutFigures($s)", LINES)
        self.assertIn('Resolve {{ implode(\' · \', $holdLabels) }} before checkout.', LINES)
        self.assertIn('Estimated after checkout', LINES)
        self.assertIn("$holdLabels[] = 'Laundry '", LINES)
        self.assertIn("$canSeeCheckoutPrep = can_do('frontdesk.view') || $canCheckout", LINES)

    def test_sensitive_fields_and_operational_actions_remain_permission_gated(self):
        self.assertIn("can_do('guests.view') && $guestPhone", LINES)
        self.assertIn("$canViewFolio = can_do('folio.view')", LINES)
        self.assertIn("$canViewFolio && $s->relationLoaded('folio')", LINES)
        self.assertIn("if (can_do('folio.view'))", SERVICE)
        self.assertIn("$canSeeHousekeeping", LINES)
        self.assertIn("$canCheckout = can_do('frontdesk.checkout')", LINES)
        self.assertIn("$canCheckout && $holds", LINES)
        self.assertIn("route('frontdesk.prepare', $s)", LINES)
        self.assertIn("route('frontdesk.checkout', $s)", LINES)
        self.assertIn('rel="noopener"', LINES)

    def test_departure_empty_state_and_responsive_dark_print_styles_are_present(self):
        for marker in (
            'class="departure-empty" role="status"', 'No departures in this view',
            'Clear departure filters', '.departure-table-wrap{width:100%;overflow-x:auto',
            '@media(max-width:1050px)', '@media(max-width:760px)', '@media(max-width:560px)',
            '.desk.dark[data-view="departures"]', '@media print',
            '.departure-actions{display:none}',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW + LINES)

    def test_departures_stay_separate_from_arrivals_and_the_reservation_register(self):
        self.assertIn("@elseif($view === 'departures')\n    @include('reservations.desk-lines', ['rows' => $departures, 'mode' => 'departure'])", VIEW)
        self.assertIn("@if($view === 'list')", VIEW)
        self.assertIn("@elseif($view === 'arrivals')", VIEW)
        self.assertIn("@elseif($view === 'inhouse')", VIEW)
        self.assertIn("@if(($mode ?? '') === 'arrival')", LINES)


if __name__ == "__main__":
    unittest.main(verbosity=2)
