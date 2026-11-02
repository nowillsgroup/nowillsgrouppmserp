#!/usr/bin/env python3
"""Static regression checks for the Reservation Desk arrivals view."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
VIEW = (ROOT / "resources/views/reservations/index.blade.php").read_text()
LINES = (ROOT / "resources/views/reservations/desk-lines.blade.php").read_text()
SERVICE = (ROOT / "app/Services/ReservationDeskService.php").read_text()
STAY_CARD = (ROOT / "resources/views/reservations/desk-stay.blade.php").read_text()
STAY_SERVICE = (ROOT / "app/Services/StayService.php").read_text()


class ReservationArrivalsPolishTest(unittest.TestCase):
    def test_arrivals_have_a_dedicated_operational_hero_and_summary(self):
        for marker in (
            "'arrivals' => 'Arrivals'",
            'class="arrival-hero"', 'FRONT OFFICE', 'ARRIVAL QUEUE',
            'Welcome starts here.', 'Prepare every arrival.',
            'arrivalSummary[\'total\']', 'arrivalSummary[\'due_in\']',
            'arrivalSummary[\'processed\']', 'arrivalSummary[\'unassigned\']',
            'arrivalSummary[\'guest_details\']', 'Guest details needed',
            "arrivalSummary['late_eta']", 'Past ETA',
            'aria-label="Arrival summary"',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)

    def test_arrival_facets_have_counts_and_route_to_real_due_in_and_walkin_filters(self):
        for marker in (
            'aria-label="Filter arrivals"', 'All arrivals', 'Due in', 'Walk-ins', 'Needs a room',
            "'due' => 'in'", "'focus' => 'walkin'", "'focus' => 'unassigned'", "'focus' => 'guest_details'", "'focus' => 'late_eta'", "$arrivalSummary['walkins']", "$arrivalSummary['unassigned']", "$arrivalSummary['guest_details']", "$arrivalSummary['late_eta']", "elseif ($focus === 'unassigned')", "elseif ($focus === 'guest_details')", "elseif ($focus === 'late_eta')", "if($date->isSameDay(bdate()))", "self::etaHasPassed($date, $stay->reservation?->eta, $now)",
            "if ($request->input('due') === 'in')", "$arrivals->where('status', 'reserved')",
            "if ($focus === 'walkin')",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW + SERVICE)

    def test_date_navigation_and_search_are_arrival_specific_and_accessible(self):
        for marker in (
            'class="arrival-toolbar"', 'id="arrivalDeskDate"', 'name="date"',
            'aria-label="Previous arrival date"', 'aria-label="Next arrival date"',
            'class="arrival-today"', 'id="arrivalSearch"', 'type="search"',
            'name="q"', 'Find a guest or booking', 'role="search"',
            "$arrivals = self::filterStays($arrivals, $request, $date)",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW + SERVICE)

    def test_manifest_prioritizes_pending_guests_by_eta_and_places_missing_eta_last(self):
        arrival_query = SERVICE.index("$arrivals = ReservationRoom::with(")
        arrival_sort = SERVICE.index("->sort(function ($a, $b)", arrival_query)
        next_query = SERVICE.index("$departures = ReservationRoom::with(", arrival_sort)
        sort_block = SERVICE[arrival_sort:next_query]
        for marker in (
            "$a->status === 'reserved' ? 0 : 1",
            "$b->status === 'reserved' ? 0 : 1",
            "'99:99'",
            "$etaA <=> $etaB",
            "$a->id <=> $b->id",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, sort_block)

    def test_unassigned_arrivals_link_directly_to_the_permission_gated_room_assignment(self):
        self.assertIn("$canManageRoom = can_do('frontdesk.roommove') || ($s->status === 'reserved' && can_do('reservations.edit'))", LINES)
        self.assertIn("{{ !$s->room_id && $canManageRoom ? '#stay-room-assignment' : '' }}", LINES)
        self.assertIn("? 'Assign room' : 'Open stay'", LINES)
        self.assertIn('id="stay-room-assignment" tabindex="-1"', STAY_CARD)
        self.assertIn("@if((can_do('frontdesk.roommove') || ($stay->status === 'reserved' && can_do('reservations.edit'))) && in_array($stay->status, ['reserved', 'checked_in'], true))", STAY_CARD)

    def test_phone_search_is_gated_by_guest_view_permission(self):
        self.assertIn("can_do('guests.view') ? $res?->guest?->phone : null", SERVICE)
        self.assertIn("can_do('guests.view') ? 'Name, confirmation, room or phone' : 'Name, confirmation or room'", VIEW)

    def test_past_eta_is_a_live_today_only_queue_filter(self):
        self.assertIn("$date->isSameDay(bdate())", SERVICE)
        self.assertIn("self::etaHasPassed($date, $stay->reservation?->eta, $now)", SERVICE)
        self.assertIn("$stay->status === 'reserved'", SERVICE)
        self.assertIn("'late_eta' => $lateEtaIds->count()", SERVICE)
        self.assertIn("'lateEtaIds' => $lateEtaIds", SERVICE)
        self.assertIn('$lateEtaIds->contains($s->id)', LINES)
        self.assertIn("'focus' => request('focus') === 'late_eta' ? null : request('focus')", VIEW)
        self.assertIn('id="arrivalLateEtaFocus"', VIEW)
        self.assertIn('arrivalLateEtaFocus?.remove()', VIEW)
        self.assertIn('class="arrival-eta-alert"', LINES)
        self.assertIn('.arrival-eta-alert{', VIEW)
        self.assertIn('.desk.dark[data-view="arrivals"] .arrival-eta-alert', VIEW)
        self.assertIn('@if($date->isSameDay(bdate()))', VIEW)

    def test_guest_details_filter_reuses_checkin_required_fields(self):
        self.assertIn("public static function missingCheckInRequirements(ReservationRoom $stay): array", STAY_SERVICE)
        self.assertIn("$needs = self::missingCheckInRequirements($stay);", STAY_SERVICE)
        self.assertIn("StayService::missingCheckInRequirements($stay)", SERVICE)
        self.assertIn("in_array('ID', $needs, true) || in_array('phone', $needs, true)", SERVICE)
        self.assertIn("'guest_details' => $guestDetailsIds->count()", SERVICE)
        self.assertIn("$arrivals->whereIn('id', $guestDetailsIds)->values()", SERVICE)

    def test_arrival_summary_is_calculated_before_optional_filters(self):
        summary_at = SERVICE.index("$arrivalSummary = [")
        filter_at = SERVICE.index("$arrivals = self::filterStays($arrivals, $request, $date)")
        self.assertLess(summary_at, filter_at)
        for marker in (
            "'total' => $arrivals->count()",
            "'due_in' => $arrivals->where('status', 'reserved')->count()",
            "'processed' => $arrivals->whereIn('status', ['checked_in', 'checked_out'])->count()",
            "'unassigned' => $arrivals->where('status', 'reserved')->whereNull('room_id')->count()",
            "'walkins' => $arrivals->filter",
            "'arrivalSummary' => $arrivalSummary",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, SERVICE)

    def test_manifest_has_semantic_guest_stay_room_eta_and_preparation_columns(self):
        for marker in (
            'class="arrival-manifest"', 'class="arrival-manifest-head"',
            'class="arrival-table"', 'scope="col">Guest &amp; booking',
            'scope="col">Stay', 'scope="col">Room', 'scope="col">ETA',
            'scope="col">Check-in prep', 'arrival-guest-name', 'arrival-booking-ref',
            'arrival-stay-dates', 'arrival-room-number', 'arrival-prep-note',
            'datetime="{{ $s->arrival_date->toDateString() }}"',
            'datetime="{{ $s->departure_date->toDateString() }}"',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, LINES)
        self.assertNotIn('ID &amp; phone', LINES)

    def test_sensitive_data_and_actions_remain_permission_gated(self):
        self.assertIn("can_do('guests.view') && $guestPhone", LINES)
        self.assertIn("$canViewFolio = can_do('folio.view')", LINES)
        self.assertIn("@if($canViewFolio)", LINES)
        self.assertIn("can_do('frontdesk.checkin')", LINES)
        self.assertIn('rel="noopener"', LINES)

    def test_empty_state_and_responsive_accessible_styles_are_present(self):
        for marker in (
            'class="arrival-empty" role="status"', 'No arrivals in this view',
            'Clear arrival filters', '@media(max-width:1050px)',
            '@media(max-width:760px)', '@media(max-width:560px)',
            '.desk.dark[data-view="arrivals"]', 'prefers-reduced-motion:reduce', '@media print',
            '.arrival-table-wrap{width:100%;overflow-x:auto}',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW + LINES)

    def test_other_reservation_desk_lists_keep_their_existing_layout(self):
        for marker in (
            "@elseif($view === 'departures')", "@elseif($view === 'inhouse')",
            "@elseif($view === 'unassigned')", "@if(($mode ?? '') === 'departure')",
            "route('frontdesk.checkout', $s)", "route('folios.show', $s->folio)",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW + LINES)


if __name__ == "__main__":
    unittest.main(verbosity=2)
