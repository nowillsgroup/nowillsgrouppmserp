#!/usr/bin/env python3
"""Static regression checks for the Reservation Desk in-house view."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
VIEW = (ROOT / "resources/views/reservations/index.blade.php").read_text()
LINES = (ROOT / "resources/views/reservations/desk-lines.blade.php").read_text()
SERVICE = (ROOT / "app/Services/ReservationDeskService.php").read_text()


class ReservationInhousePolishTest(unittest.TestCase):
    def test_inhouse_has_a_distinct_live_hero_and_permission_aware_summary(self):
        for marker in (
            "'inhouse' => 'In-house'", 'class="inhouse-hero"', 'LIVE IN-HOUSE',
            'Every current stay, in view.', 'Live roster', 'Business date',
            'class="inhouse-summary"', 'Current in-house summary',
            "$inhouseSummary['total']", "$inhouseSummary['guests']",
            "$inhouseSummary['due_today']", "$inhouseSummary['overdue']",
            "$inhouseSummary['stayovers']", "$inhouseSummary['balance_review']",
            "@if(can_do('folio.view'))<div><span>Balance follow-up</span>",
            "@if($view !== 'list' && $view !== 'timeline' && $view !== 'board' && $view !== 'arrivals' && $view !== 'departures' && $view !== 'inhouse' && $view !== 'unassigned' && $view !== 'waitlist' && $view !== 'groups' && $view !== 'noshow' && $view !== 'cancelled')",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)

    def test_inhouse_summary_is_live_and_calculated_before_optional_search_and_focus(self):
        query_at = SERVICE.index("$inhouse = ReservationRoom::with($inhouseRelations)")
        summary_at = SERVICE.index("$inhouseSummary = [", query_at)
        search_at = SERVICE.index("$inhouseSearch =", summary_at)
        focus_at = SERVICE.index("if ($focus === 'due_today')", search_at)
        self.assertLess(query_at, summary_at)
        self.assertLess(summary_at, search_at)
        self.assertLess(search_at, focus_at)
        for marker in (
            "->where('status', 'checked_in')", "$stay->departure_date->toDateString() === $businessDay",
            "$stay->departure_date->toDateString() < $businessDay",
            "$stay->departure_date->toDateString() > $businessDay",
            "'guests' => (int) $inhouse->sum(fn ($stay) => (int) $stay->adults + (int) $stay->children)",
            "'vip' => $inhouse->filter(fn ($stay) => (bool) $stay->reservation?->guest?->is_vip)->count()",
            "'unassigned' => $inhouse->whereNull('room_id')->count()",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, SERVICE)

    def test_inhouse_segments_route_to_real_live_roster_filters(self):
        for marker in (
            'aria-label="Filter in-house stays"', 'All in-house', 'Due out today', 'Past due',
            'Stayovers', 'No room assigned', 'Balance follow-up', "'focus' => 'due_today'",
            "'focus' => 'overdue'", "'focus' => 'stayovers'", "'focus' => 'vip'",
            "'focus' => 'unassigned'", "'focus' => 'balance'",
            "if ($focus === 'due_today')", "elseif ($focus === 'overdue')",
            "elseif ($focus === 'stayovers')", "elseif ($focus === 'vip')",
            "elseif ($focus === 'unassigned')", "elseif ($focus === 'balance')",
            "$view === 'inhouse' && ! in_array($focus, ['due_today', 'overdue', 'stayovers', 'vip', 'unassigned', 'balance'], true)",
            "$request->query->remove('focus')",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW + SERVICE)

    def test_search_is_server_submitted_and_guest_phone_search_is_permission_gated(self):
        for marker in (
            'class="inhouse-toolbar"', 'id="inhouseSearch"', 'type="search"',
            'name="q"', 'role="search"', 'Live check-in roster',
            "can_do('guests.view') ? 'Name, confirmation, company, room or phone'",
            "$canSearchGuestPhone = can_do('guests.view')",
            "$canSearchGuestPhone ? $guest?->phone : null",
            'Clear search',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW + SERVICE)

    def test_manifest_exposes_scoped_guest_stay_room_and_optional_folio_columns(self):
        for marker in (
            'class="inhouse-manifest"', 'id="inhouseManifestTitle"',
            'class="inhouse-table"', 'caption class="sr-only"',
            'scope="col">Guest &amp; booking', 'scope="col">Stay &amp; departure',
            'scope="col">Room', 'scope="col">Folio balance',
            'inhouse-guest-name', 'inhouse-booking-ref', 'inhouse-stay-dates',
            'inhouse-room-number', 'inhouse-departure-', 'data-label="Actions"',
            "@if($canViewFolio)<th scope=\"col\">Folio balance</th>@endif",
            'datetime="{{ $s->arrival_date->toDateString() }}"',
            'datetime="{{ $s->departure_date->toDateString() }}"',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, LINES)

    def test_folio_contact_and_housekeeping_details_are_permission_gated(self):
        self.assertIn("if ($canViewInhouseFolios) {", SERVICE)
        self.assertIn("$inhouseRelations[] = 'folio'", SERVICE)
        self.assertIn("'balance_review' => $canViewInhouseFolios", SERVICE)
        self.assertIn("$canViewFolio = can_do('folio.view')", LINES)
        self.assertIn("$folio = $canViewFolio && $s->relationLoaded('folio') ? $s->folio : null", LINES)
        self.assertIn("$balance = $folio ? (float) ($inhouseMoneyFlags[$s->id]['balance'] ?? 0) : null", LINES)
        self.assertIn("'inhouseMoneyFlags' => $canViewInhouseFolios ? $inhouseMoneyFlags : []", SERVICE)
        self.assertIn("when 'opening_balance' then amount", SERVICE)
        self.assertIn("@if($canViewFolio)<td data-label=\"Folio balance\">", LINES)
        self.assertIn("@if($canViewFolio && $folio)", LINES)
        self.assertIn("can_do('guests.view') && $guestPhone", LINES)
        self.assertIn("$canSeeHousekeeping = can_do('housekeeping.view')", LINES)
        self.assertIn("$s->room && $canSeeHousekeeping", LINES)

    def test_checkout_and_room_move_actions_remain_on_existing_permission_checked_routes(self):
        self.assertIn("$canCheckout = can_do('frontdesk.checkout')", LINES)
        self.assertIn("@if($canCheckout)<a class=\"btn btn-sm btn-primary\" href=\"{{ route('frontdesk.checkout', $s) }}\"", LINES)
        self.assertIn("$canMoveRoom = can_do('frontdesk.roommove')", LINES)
        self.assertIn("@if($canMoveRoom)<a class=\"btn btn-sm\" href=\"{{ route('frontdesk.move', $s) }}\"", LINES)
        self.assertIn('rel="noopener" aria-label="Open invoice for {{ $guestName }}"', LINES)

    def test_empty_state_and_responsive_dark_print_styles_are_present(self):
        for marker in (
            'class="inhouse-empty" role="status"', 'No checked-in stays right now',
            'Clear in-house filters', '.inhouse-table-wrap{width:100%;overflow-x:auto',
            '.desk.dark[data-view="inhouse"]', '@media(max-width:1050px)',
            '@media(max-width:760px)', '@media(max-width:560px)', '@media print',
            '.inhouse-actions{display:none}',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW + LINES)

    def test_inhouse_stays_separate_from_arrivals_departures_and_front_desk_roster(self):
        self.assertIn("@elseif($view === 'inhouse')\n    @include('reservations.desk-lines', ['rows' => $inhouse, 'mode' => 'inhouse'])", VIEW)
        self.assertIn("@if(($mode ?? '') === 'arrival')", LINES)
        self.assertIn("@elseif(($mode ?? '') === 'departure')", LINES)
        self.assertIn("@elseif(($mode ?? '') === 'inhouse')", LINES)
        self.assertIn("$inhouse = ReservationRoom::with($inhouseRelations)->where('status', 'checked_in')->get()", SERVICE)


if __name__ == "__main__":
    unittest.main(verbosity=2)
