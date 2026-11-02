#!/usr/bin/env python3
"""Static regression audit for the front-desk arrival queue."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
VIEW = (ROOT / "resources/views/frontdesk/list.blade.php").read_text()
CONTROLLER = (ROOT / "app/Http/Controllers/FrontDeskController.php").read_text()


class FrontDeskArrivalsPolishTest(unittest.TestCase):
    def test_arrivals_have_a_clear_operational_overview_and_live_counts(self):
        for marker in (
            'aria-label="Arrival desk overview"', 'Front office · Check-in queue',
            'aria-label="Arrival summary"', 'Pending check-ins', 'Guests in this queue',
            'Online pre-check-ins', 'Rooms to assign', "$arrivalSummary['pending']",
            "$arrivalSummary['guests']", "$arrivalSummary['prechecked']", "$arrivalSummary['unassigned']",
            'class="arrival-date-badge"', 'datetime="{{ $arrivalDate->toDateString() }}"',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)

    def test_arrival_date_and_overdue_filters_are_explicit_and_usable(self):
        for marker in (
            'id="arrival-date-filter"', 'name="date"', 'name="overdue"',
            'Include arrivals up to 30 days earlier', 'route(\'frontdesk.arrivals\')',
            'aria-label="Change arrival date"', 'aria-label="Previous day"',
            'aria-label="Next day"', 'route(\'frontdesk.arrivals\', [\'date\' =>',
            "'date' => ['nullable', 'date_format:Y-m-d']", "'overdue' => ['nullable', 'boolean']",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW + CONTROLLER)

    def test_query_is_scoped_to_pending_stays_and_reports_each_room_stay(self):
        for marker in (
            "->where('status', 'reserved')", "->whereDate('arrival_date', '<=', $dateString)",
            "->whereDate('arrival_date', '>=', $date->copy()->subDays($includeOverdue ? 30 : 0)->toDateString())",
            "'reservation.guest', 'reservation.company', 'reservation.businessSource', 'room', 'roomType'",
            "->orderBy('arrival_date')", "->orderBy('room_id')", "->orderBy('id')",
            'For group bookings, each room stay is listed separately',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, CONTROLLER + VIEW)

    def test_manifest_has_guest_stay_room_booking_and_readiness_context(self):
        for marker in (
            'id="arrival-manifest-title"', 'scope="col">Guest', 'scope="col">Stay &amp; party',
            'scope="col">Room', 'scope="col">Booking', 'scope="col">Readiness',
            'arrival-guest-name', 'arrival-guest-meta', 'datetime="{{ $s->arrival_date->toDateString() }}"',
            'datetime="{{ $s->departure_date->toDateString() }}"', 'Room unassigned',
            'Housekeeping:', 'Online pre-checked', 'days past due', 'businessSource?->name',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)

    def test_checkin_and_housekeeping_actions_remain_permission_gated(self):
        self.assertIn("@if(can_do('frontdesk.checkin'))<a class=\"btn btn-success btn-sm\"", VIEW)
        self.assertIn("@elseif($s->reservation && can_do('reservations.view'))", VIEW)
        self.assertIn("$arrivalHousekeepingVisible = can_do('housekeeping.view') || can_do('housekeeping.manage') || can_do('housekeeping.attendant')", VIEW)
        self.assertIn("@if($arrivalHousekeepingVisible && $s->room->hk_status)", VIEW)
        self.assertIn("aria-label=\"Check in {{ $guestName }} for reservation", VIEW)

    def test_empty_state_and_list_search_are_accessible(self):
        for marker in (
            'class="sr-only" for="arrival-manifest-search"', 'type="search"',
            'data-filter-table="#stayTable"', 'aria-labelledby="arrival-manifest-title"',
            'No pending arrivals', 'Include overdue arrivals', 'Open reservation workspace',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)

    def test_arrival_styles_support_mobile_dark_mode_and_print(self):
        for marker in (
            '@media(max-width:900px)', '@media(max-width:600px)', '@media print',
            'body.dark .arrival-intro', 'body.dark .arrival-table th',
            'overflow-x:auto', '.arrival-action',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)

    def test_other_front_desk_lists_keep_their_existing_table_actions(self):
        for marker in (
            "@else\n  <div class=\"card flush\">", "@if($mode !== 'arrivals')",
            'route(\'frontdesk.checkout\', $s)', 'route(\'folios.show\', $s->folio)',
            '$s->folio?->listing_balance', '$s->departure_date->format(\'d M\')',
            'data-filter-table="#stayTable"',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)


if __name__ == "__main__":
    unittest.main(verbosity=2)
