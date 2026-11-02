#!/usr/bin/env python3
"""Static regression checks for the Reservation Desk unassigned-stays view."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
VIEW = (ROOT / "resources/views/reservations/index.blade.php").read_text()
LINES = (ROOT / "resources/views/reservations/desk-lines.blade.php").read_text()
SERVICE = (ROOT / "app/Services/ReservationDeskService.php").read_text()


class ReservationUnassignedPolishTest(unittest.TestCase):
    def test_unassigned_has_a_dedicated_assignment_hero_and_date_aware_summary(self):
        for marker in (
            "'unassigned' => 'Unassigned'", 'class="unassigned-hero"',
            'ROOM ASSIGNMENT QUEUE', 'Every stay deserves a room.',
            'availability-checked stay workflow', 'Desk date', 'Business date',
            'class="unassigned-summary"', 'Unassigned stay summary',
            "$unassignedSummary['total']", "$unassignedSummary['arriving_today']",
            "$unassignedSummary['past_arrival']", "$unassignedSummary['next_7_days']",
            "$unassignedSummary['groups']",
            "@if($view !== 'list' && $view !== 'timeline' && $view !== 'board' && $view !== 'arrivals' && $view !== 'departures' && $view !== 'inhouse' && $view !== 'unassigned' && $view !== 'waitlist' && $view !== 'groups' && $view !== 'noshow' && $view !== 'cancelled')",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)

    def test_summary_precedes_search_and_facets_and_matches_active_unassigned_scope(self):
        query_at = SERVICE.index("$unassigned = ReservationRoom::with($unassignedRelations)")
        summary_at = SERVICE.index("$unassignedSummary = [", query_at)
        search_at = SERVICE.index("if ($view === 'unassigned' && $request->filled('q'))", summary_at)
        focus_at = SERVICE.index("if ($view === 'unassigned')", search_at)
        self.assertLess(query_at, summary_at)
        self.assertLess(summary_at, search_at)
        self.assertLess(search_at, focus_at)
        for marker in (
            "->where('status', 'reserved')->whereNull('room_id')->whereDate('departure_date', '>', $day)",
            "'total' => $unassigned->count()",
            "'arriving_today' => $unassigned->filter(fn ($stay) => $stay->arrival_date->toDateString() === $day)->count()",
            "$stay->arrival_date->toDateString() < $day",
            "$stay->arrival_date->toDateString() <= $date->copy()->addDays(7)->toDateString()",
            "$stay->reservation?->booking_type === 'group'",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, SERVICE)

    def test_unassigned_segments_route_to_real_date_relative_filters(self):
        for marker in (
            'aria-label="Filter unassigned stays"', 'All unassigned', 'On desk date',
            'Past arrival', 'Next 7 days', 'Group stays',
            "'focus' => 'arriving_today'", "'focus' => 'past_arrival'",
            "'focus' => 'next_7_days'", "'focus' => 'groups'",
            "if ($focus === 'arriving_today')", "elseif ($focus === 'past_arrival')",
            "elseif ($focus === 'next_7_days')", "elseif ($focus === 'groups')",
            "$view === 'unassigned' && ! in_array($focus, ['arriving_today', 'past_arrival', 'next_7_days', 'groups'], true)",
            "$request->query->remove('focus')",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW + SERVICE)

    def test_date_navigation_and_search_are_unassigned_specific_and_accessible(self):
        for marker in (
            'class="unassigned-toolbar"', 'id="unassignedDeskDate"',
            'aria-label="Previous unassigned desk date"', 'aria-label="Next unassigned desk date"',
            'class="unassigned-today"', 'id="unassignedSearch"', 'type="search"',
            'name="q"', 'role="search"', 'Find a guest, booking or room type',
            "$unassignedSearch = mb_strtolower(trim((string) $request->input('q')))",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW + SERVICE)

    def test_search_covers_guest_booking_company_source_and_room_type_with_phone_gating(self):
        for marker in (
            "$reservation?->reservation_no", "$reservation?->group_name",
            "$reservation?->company?->name", "$reservation?->source?->name",
            "$stay->roomType?->name", "$stay->roomType?->code",
            "$canSearchUnassignedPhone = can_do('guests.view')",
            "$canSearchUnassignedPhone ? $guest?->phone : null",
            "can_do('guests.view') ? 'Name, confirmation, group, type, company or phone'",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, SERVICE + VIEW)

    def test_manifest_has_accessible_guest_stay_room_request_and_assignment_columns(self):
        for marker in (
            'class="unassigned-manifest"', 'id="unassignedManifestTitle"',
            'class="unassigned-table"', 'caption class="sr-only"',
            'scope="col">Guest &amp; booking', 'scope="col">Stay &amp; arrival',
            'scope="col">Requested room', 'scope="col">Assignment',
            'unassigned-guest-name', 'unassigned-booking-ref', 'unassigned-stay-dates',
            'unassigned-arrival-', 'unassigned-room-type', 'Room not assigned',
            'Choose a room from the stay page.',
            'datetime="{{ $s->arrival_date->toDateString() }}"',
            'datetime="{{ $s->departure_date->toDateString() }}"',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, LINES)

    def test_assignment_remains_explicit_and_permission_gated(self):
        self.assertIn("$canAssignRoom = can_do('frontdesk.roommove') || can_do('reservations.edit')", LINES)
        self.assertIn("@if($canAssignRoom)<a class=\"btn btn-sm btn-primary\"", LINES)
        self.assertIn("route('reservations.stay', $s) }}#stay-room-assignment", LINES)
        self.assertIn("($stay->status === 'reserved' && can_do('reservations.edit'))", (ROOT / 'resources/views/reservations/desk-stay.blade.php').read_text())
        unassigned = LINES[LINES.index("@elseif(($mode ?? '') === 'unassigned')"):LINES.index('@else\n<div class="card flush">', LINES.index("@elseif(($mode ?? '') === 'unassigned')"))]
        self.assertNotIn('folio.balance', unassigned)
        self.assertNotIn("route('folios.show'", unassigned)
        self.assertIn("can_do('guests.view') && $guestPhone", unassigned)

    def test_empty_state_and_responsive_dark_print_styles_are_present(self):
        for marker in (
            'class="unassigned-empty" role="status"', 'All reserved stays have a room',
            'Clear unassigned filters', '.unassigned-table-wrap{width:100%;overflow-x:auto',
            '.desk.dark[data-view="unassigned"]', '@media(max-width:1050px)',
            '@media(max-width:760px)', '@media(max-width:560px)', '@media print',
            '.unassigned-actions{display:none}',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW + LINES)

    def test_linked_stay_card_keeps_guest_and_folio_details_permission_gated(self):
        stay_card = (ROOT / "resources/views/reservations/desk-stay.blade.php").read_text()
        for marker in (
            "$canViewGuest = can_do('guests.view')",
            "$canViewFolio = can_do('folio.view')",
            "@if($canViewGuest)<div class=\"small text-2\"",
            "@if($canViewFolio)",
            "@if($canViewGuest && $res->special_request)",
            "@if($canViewGuest)<p class=\"small\">Loyalty points on file:",
            "@if($canViewGuest)@if($idOnFile)",
            "@if($canViewFolio && $folio)",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, stay_card)
        unassigned = LINES[LINES.index("@elseif(($mode ?? '') === 'unassigned')"):LINES.index('@else\n<div class="card flush">', LINES.index("@elseif(($mode ?? '') === 'unassigned')"))]
        self.assertNotIn("route('reservations.show'", unassigned)

    def test_unassigned_stays_separate_from_other_desk_queues(self):
        self.assertIn("@elseif($view === 'unassigned')\n    @include('reservations.desk-lines', ['rows' => $unassigned, 'mode' => 'unassigned'])", VIEW)
        self.assertIn("@if(($mode ?? '') === 'arrival')", LINES)
        self.assertIn("@elseif(($mode ?? '') === 'departure')", LINES)
        self.assertIn("@elseif(($mode ?? '') === 'inhouse')", LINES)
        self.assertIn("@elseif(($mode ?? '') === 'unassigned')", LINES)


if __name__ == "__main__":
    unittest.main(verbosity=2)
