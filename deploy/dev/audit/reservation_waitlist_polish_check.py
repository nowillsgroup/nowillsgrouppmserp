#!/usr/bin/env python3
"""Static regression checks for the Reservation Desk waitlist view."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
VIEW = (ROOT / "resources/views/reservations/index.blade.php").read_text()
SERVICE = (ROOT / "app/Services/ReservationDeskService.php").read_text()


class ReservationWaitlistPolishTest(unittest.TestCase):
    def test_waitlist_has_a_distinct_operational_hero_and_permission_aware_summary(self):
        for marker in (
            "'waitlist' => 'Reservation Waitlist'", 'class="waitlist-hero"',
            'RESERVATION WAITLIST', 'Be ready when a room opens.',
            'Conversion does not assign a room.', 'Arrival window starts',
            'class="waitlist-summary"', 'Waitlist summary',
            "$waitlistSummary['total']", "$waitlistSummary['waiting']",
            "$waitlistSummary['next_7_days']", "$waitlistSummary['needs_type']",
            "$waitlistSummary['converted']", "$waitlistSummary['cancelled']",
            "&& $view !== 'unassigned' && $view !== 'waitlist' && $view !== 'groups' && $view !== 'noshow' && $view !== 'cancelled')",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)

    def test_summary_is_computed_before_view_filters_and_matches_filter_definitions(self):
        load_at = SERVICE.index("$waitlist = ReservationWaitlist::with(['guest', 'roomType', 'reservation'])")
        summary_at = SERVICE.index("$waitlistSummary = [", load_at)
        status_at = SERVICE.index("$waitlistStatus =", summary_at)
        search_at = SERVICE.index("if ($view === 'waitlist' && $request->filled('q'))", status_at)
        filtering_at = SERVICE.index("if ($view === 'waitlist')", search_at)
        self.assertLess(load_at, summary_at)
        self.assertLess(summary_at, status_at)
        self.assertLess(status_at, search_at)
        self.assertLess(search_at, filtering_at)
        for marker in (
            "'waiting' => $waitlist->where('status', 'waiting')->count()",
            "'converted' => $waitlist->where('status', 'converted')->count()",
            "'cancelled' => $waitlist->where('status', 'cancelled')->count()",
            "'needs_type' => $waitlist->filter(fn ($entry) => $entry->status === 'waiting' && ! $entry->room_type_id)->count()",
            "'next_7_days' => $waitlist->filter(fn ($entry) => $entry->status === 'waiting'",
            "'waitlist_status' => '', 'waitlist_focus' => ''",
            "in_array($waitlistStatus, ['waiting', 'converted', 'cancelled'], true)",
            "in_array($waitlistFocus, ['next_7_days', 'needs_type'], true)",
            "$entry->arrival_date->toDateString() <= $rangeEnd",
            "$entry->status === 'waiting' && ! $entry->room_type_id",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, SERVICE)

    def test_waitlist_facets_and_date_search_toolbar_preserve_real_query_filters(self):
        for marker in (
            'aria-label="Filter reservation waitlist"', 'All entries', 'Waiting',
            'Arriving in 7 days', 'Room type needed', 'Converted', 'Cancelled',
            "'waitlist_status' => 'waiting'", "'waitlist_status' => 'converted'",
            "'waitlist_status' => 'cancelled'", "'waitlist_focus' => 'next_7_days'",
            "'waitlist_focus' => 'needs_type'", 'class="waitlist-toolbar"',
            'id="waitlistDate"', 'aria-label="Previous waitlist arrival date"',
            'aria-label="Next waitlist arrival date"', 'id="waitlistSearch"',
            'type="search"', 'name="q"', 'role="search"',
            'Find a guest or room request',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)

    def test_add_form_keeps_existing_validated_waitlist_creation_route(self):
        for marker in (
            'id="waitlist-add-form"', "route('reservations.waitlist.store')",
            'id="waitlistContactName"', 'name="contact_name"',
            'id="waitlistPhone"', 'name="phone"', 'autocomplete="tel"',
            'id="waitlistEmail"', 'name="email"', 'autocomplete="email"',
            'id="waitlistArrival"', 'name="arrival_date"',
            'id="waitlistDeparture"', 'name="departure_date"',
            'id="waitlistRoomType"', 'name="room_type_id"',
            'id="waitlistAdults"', 'id="waitlistChildren"',
            'id="waitlistPriority"', 'id="waitlistNotes"',
            'min="{{ bdate()->toDateString() }}"',
            'Add to waitlist', 'No reservation or room is created until you deliberately convert it.',
            'availability is rechecked at conversion time',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)

    def test_manifest_has_accessible_stay_priority_status_and_action_columns(self):
        for marker in (
            'class="waitlist-manifest"', 'id="waitlistManifestTitle"',
            'class="waitlist-table"', 'caption class="sr-only"',
            'scope="col">Priority', 'scope="col">Guest &amp; contact',
            'scope="col">Stay request', 'scope="col">Room preference',
            'scope="col">Status', 'class="waitlist-priority waitlist-priority-',
            'class="waitlist-guest-name"', 'waitlist-stay-dates',
            'waitlist-room-type', 'waitlist-status-{{ $w->status }}',
            'Reservation created · {{ $w->reservation->reservation_no }}',
            'No room has been assigned.',
            'Choose a type before checking or converting.',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)

    def test_guest_contact_and_notes_are_permission_gated(self):
        self.assertIn("can_do('guests.view') && ($w->phone || $w->email)", VIEW)
        self.assertIn("can_do('guests.view') && $w->notes", VIEW)
        self.assertIn("$canSearchWaitlistContact = can_do('guests.view')", SERVICE)
        self.assertIn("$canSearchWaitlistContact ? $entry->phone : null", SERVICE)
        self.assertIn("$canSearchWaitlistContact ? $entry->email : null", SERVICE)
        self.assertIn("$canSearchWaitlistContact ? $entry->notes : null", SERVICE)
        self.assertIn("can_do('guests.view') ? 'Name, confirmation, room type, phone or email'", VIEW)

    def test_check_convert_and_cancel_stay_on_existing_permission_checked_routes(self):
        self.assertIn("route('reservations.waitlist.check', $w)", VIEW)
        self.assertIn("route('reservations.waitlist.convert', $w)", VIEW)
        self.assertIn("route('reservations.waitlist.cancel', $w)", VIEW)
        self.assertIn("$w->status === 'waiting' && $w->room_type_id", VIEW)
        self.assertIn("@if($w->status === 'waiting' && $w->room_type_id)", VIEW)
        self.assertIn("@if(can_do('reservations.create'))<form method=\"post\" action=\"{{ route('reservations.waitlist.convert', $w) }}\"", VIEW)
        self.assertIn("$w->status === 'waiting' && can_do('reservations.edit')", VIEW)
        self.assertIn("AvailabilityService::typeAvailable($type->id, $row->arrival_date, $row->departure_date) < 1", SERVICE)
        self.assertIn("$row->update(['status' => 'converted', 'reservation_id' => $res->id", SERVICE)
        self.assertNotIn("'room_id' =>", SERVICE[SERVICE.index("public static function convertWaitlist"):SERVICE.index("public static function cancelWaitlist")])

    def test_empty_state_and_responsive_dark_print_styles_are_present(self):
        for marker in (
            'class="waitlist-empty" role="status"', 'The waitlist is clear',
            'No entries match these filters', 'Clear filters',
            '.waitlist-table-wrap{width:100%;overflow-x:auto',
            '.desk.dark[data-view="waitlist"]', '@media(max-width:1100px)',
            '@media(max-width:760px)', '@media(max-width:560px)', '@media print',
            '.waitlist-actions{display:none}',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)

    def test_waitlist_is_a_dedicated_reservation_desk_view(self):
        self.assertIn("@elseif($view === 'waitlist')\n    @php $waitlistFormArrival", VIEW)
        self.assertIn("'waitlist' => $waitlist, 'waitlistSummary' => $waitlistSummary", SERVICE)
        self.assertIn("'waitlist' => 'Waitlist'", VIEW)
        self.assertIn("'waitlist' => 'Waitlist', 'groups' => 'Groups', 'noshow' => 'No-show', 'cancelled' => 'Cancelled']", VIEW)


if __name__ == "__main__":
    unittest.main(verbosity=2)
