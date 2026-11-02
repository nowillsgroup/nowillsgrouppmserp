#!/usr/bin/env python3
"""Static regression audit for the live in-house roster."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
VIEW = (ROOT / "resources/views/frontdesk/list.blade.php").read_text()
CONTROLLER = (ROOT / "app/Http/Controllers/FrontDeskController.php").read_text()


class FrontDeskInHousePolishTest(unittest.TestCase):
    def test_inhouse_query_uses_checked_in_stays_and_hotel_business_date(self):
        for marker in (
            "where('status', 'checked_in')", "->orderBy('room_id')", "->orderBy('departure_date')",
            "->orderBy('id')", "$today = bdate()", "'inHouseDate' => $today",
            "'stays' => $stays->count()", "'guests' => $stays->sum(",
            "'dueToday' => $stays->filter(", "'pastDue' => $stays->filter(",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, CONTROLLER)

    def test_live_roster_has_summary_and_business_date_context(self):
        for marker in (
            'aria-label="In-house desk overview"', 'Front office · Live stay roster',
            'aria-label="In-house summary"', 'Checked-in stays', 'Guests in-house',
            'Departing today', 'Past departure date', 'inHouseSummary[\'stays\']',
            'inHouseSummary[\'guests\']', 'inHouseSummary[\'dueToday\']',
            'inHouseSummary[\'pastDue\']', 'datetime="{{ $inHouseDate->toDateString() }}"',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)

    def test_roster_shows_guest_room_stay_and_booking_context(self):
        for marker in (
            'id="inhouse-manifest-title"', 'scope="col">Guest', 'scope="col">Room',
            'scope="col">Stay &amp; departure', 'scope="col">Reservation',
            'scope="col" class="no-print">Actions', 'datetime="{{ $s->arrival_date->toDateString() }}"',
            'datetime="{{ $s->departure_date->toDateString() }}"', 'Room not assigned',
            'Housekeeping:', 'Departing today', 'days past due', 'businessSource?->name',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)

    def test_move_checkout_folio_and_guest_portal_actions_follow_permissions(self):
        for marker in (
            "@if(can_do('frontdesk.roommove'))<a class=\"btn btn-sm\"",
            "@if(can_do('frontdesk.checkout'))<a class=\"btn btn-warning btn-sm\"",
            "@if(can_do('requests.manage'))<a class=\"btn btn-ghost btn-sm\"",
            "@if($canViewFolio)<td class=\"inhouse-folio\"", "route('folios.show', $s->folio)",
            "@if($s->reservation && can_do('reservations.view'))",
            "aria-label=\"Move {{ $guestName }} to another room\"",
            "aria-label=\"Check out {{ $guestName }} from room",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)
        self.assertIn("if ($canViewFolio) {\n            $relations['folio'] = fn ($relation) => $relation->withListBalance();", CONTROLLER)

    def test_search_empty_state_navigation_and_print_are_present(self):
        for marker in (
            'id="inhouse-manifest-search"', 'data-filter-table="#stayTable"',
            'No guests currently in-house', 'Open arrivals', 'Open room view',
            "route('frontdesk.arrivals')", "route('frontdesk.departures')",
            'class="inhouse-footnote"', 'window.print()',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)

    def test_inhouse_roster_is_responsive_dark_mode_and_print_friendly(self):
        for marker in (
            '@media(max-width:900px)', '@media(max-width:600px)', '@media print',
            'body.dark .inhouse-intro', 'body.dark .inhouse-table th',
            'overflow-x:auto', 'class="sr-only" for="inhouse-manifest-search"',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)


if __name__ == "__main__":
    unittest.main(verbosity=2)
