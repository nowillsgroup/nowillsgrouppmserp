#!/usr/bin/env python3
"""Static regression audit for the front-desk departure queue."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
VIEW = (ROOT / "resources/views/frontdesk/list.blade.php").read_text()
CONTROLLER = (ROOT / "app/Http/Controllers/FrontDeskController.php").read_text()


class FrontDeskDeparturesPolishTest(unittest.TestCase):
    def test_due_out_query_is_validated_scoped_sorted_and_tenant_safe(self):
        for marker in (
            "'date' => ['nullable', 'date_format:Y-m-d']", "Carbon::parse($filters['date'] ?? bdate()->toDateString())",
            "->where('status', 'checked_in')", "->whereDate('departure_date', '<=', $date->toDateString())",
            "->orderBy('departure_date')", "->orderBy('room_id')", "->orderBy('id')",
            "$canViewFolio = can_do('folio.view')", "if ($canViewFolio)",
            "fn ($relation) => $relation->withListBalance()", "'reservation.businessSource'",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, CONTROLLER)

    def test_departure_overview_has_live_dueout_guest_overdue_and_folio_metrics(self):
        for marker in (
            'aria-label="Departure desk overview"', 'Front office · Check-out queue',
            'aria-label="Departure summary"', 'Stays due to check out',
            'Guests across due-outs', 'Past departure date',
            'Outstanding folio balance', 'Rooms to release',
            "$departureSummary['due']", "$departureSummary['guests']",
            "$departureSummary['overdue']", "$departureSummary['balance']",
            'datetime="{{ $departureDate->toDateString() }}"',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW + CONTROLLER)

    def test_departure_manifest_explains_due_dates_party_room_and_folio_state(self):
        for marker in (
            'id="departure-manifest-title"', 'scope="col">Guest',
            'scope="col">Stay &amp; departure', 'scope="col">Room',
            '@if($canViewFolio)<th scope="col">Folio</th>@endif',
            'scope="col" class="no-print">Action', 'arrival_date->format(\'d M Y\')',
            'datetime="{{ $s->departure_date->toDateString() }}"',
            'days past due', 'Due on selected date', 'Housekeeping:',
            'listing_balance', 'No folio linked', 'No guests due out',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)

    def test_date_controls_search_empty_state_and_navigation_are_operable(self):
        for marker in (
            'id="departure-date-filter"', 'name="date"', 'Show due-outs',
            'route(\'frontdesk.departures\', [\'date\' => $departureDate->copy()->subDay()',
            'route(\'frontdesk.departures\', [\'date\' => $departureDate->copy()->addDay()',
            'aria-label="Previous day"', 'aria-label="Next day"',
            'id="departure-manifest-search"', 'data-filter-table="#stayTable"',
            'View in-house guests', 'Open reservation workspace',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)

    def test_folio_balances_and_check_out_actions_are_permission_gated(self):
        self.assertIn('@if($canViewFolio)', VIEW)
        self.assertIn("@if(can_do('frontdesk.checkout'))<a class=\"btn btn-warning btn-sm\"", VIEW)
        self.assertIn("@elseif($s->reservation && can_do('reservations.view'))", VIEW)
        self.assertIn("$relations['folio'] = fn ($relation) => $relation->withListBalance();", CONTROLLER)
        self.assertIn("$relations = ['reservation.guest', 'reservation.company', 'reservation.businessSource', 'room', 'roomType'];", CONTROLLER)

    def test_departure_page_has_responsive_dark_print_and_accessible_styles(self):
        for marker in (
            '@media(max-width:900px)', '@media(max-width:600px)', '@media print',
            'body.dark .departure-intro', 'body.dark .departure-table th',
            'overflow-x:auto', 'class="sr-only" for="departure-manifest-search"',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)

    def test_arrivals_and_inhouse_keep_separate_operational_views(self):
        for marker in (
            "@if($mode === 'arrivals')", '@elseif($mode === \'departures\')',
            'Arrival manifest', 'Departure manifest', '<table class="table" id="stayTable">',
            'class="arrival-footnote"', 'class="departure-footnote"',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)


if __name__ == "__main__":
    unittest.main(verbosity=2)
