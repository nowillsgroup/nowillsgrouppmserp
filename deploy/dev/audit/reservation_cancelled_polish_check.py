#!/usr/bin/env python3
"""Static regression checks for the full-shell Reservation Desk cancelled register."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
SERVICE = (ROOT / "app/Services/ReservationDeskService.php").read_text()
VIEW = (ROOT / "resources/views/reservations/index.blade.php").read_text()
DASHBOARD = (ROOT / "resources/views/reservations/desk-dashboard.blade.php").read_text()


class ReservationCancelledPolishTest(unittest.TestCase):
    def test_cancelled_register_is_a_first_class_full_shell_view(self):
        self.assertIn("'noshow', 'cancelled'", SERVICE)
        self.assertIn("'cancelled' => 'Cancelled Register'", VIEW)
        self.assertIn("class=\"cancelled-hero\"", VIEW)
        self.assertIn("Keep cancellation history clear and factual.", VIEW)
        self.assertIn("class=\"cancelled-summary\"", VIEW)
        self.assertIn("class=\"cancelled-manifest\"", VIEW)
        self.assertIn("'cancelled' => 'Cancelled'", VIEW)
        self.assertIn("'cancelled' => 'Cancelled'", VIEW[VIEW.index("$views ="):])

    def test_summary_and_rows_use_recorded_cancellation_timestamps(self):
        branch = SERVICE.index("if ($view === 'cancelled') {")
        query = SERVICE[branch:SERVICE.index("if ($view === 'noshow') {", branch)]
        self.assertIn("where('status', 'cancelled')", query)
        self.assertIn("whereNotNull('cancelled_at')", query)
        self.assertIn("whereDate('cancelled_at', '>=', $windowStart)", query)
        self.assertIn("whereDate('cancelled_at', '<', $windowEnd)", query)
        self.assertLess(query.index("$cancelledSummary = ["), query.index("$cancelledFocus ="))
        self.assertLess(query.index("$cancelledSummary = ["), query.index("$cancelledSearch ="))
        self.assertIn("'room_stays' => ReservationRoom::where('status', 'cancelled')", query)
        self.assertIn("Records without a cancellation timestamp are not assigned an inferred date.", VIEW)
        self.assertIn('datetime="{{ $r->cancelled_at->toIso8601String() }}"', VIEW)

    def test_search_and_guest_contacts_remain_permission_gated(self):
        self.assertIn("$canSearchCancelledContact = can_do('guests.view')", SERVICE)
        self.assertIn("if ($canSearchCancelledContact)", SERVICE)
        self.assertIn("can_do('guests.view') && $cancelledPhone", VIEW)
        self.assertIn("can_do('guests.view') && $r->guest?->is_vip", VIEW)

    def test_cancelled_register_is_semantic_read_only_and_links_to_booking_history(self):
        self.assertIn('class="cancelled-manifest" aria-labelledby="cancelledManifestTitle"', VIEW)
        self.assertIn('<caption class="sr-only">Cancelled reservations with cancellation timestamps', VIEW)
        self.assertIn('<th scope="col">Cancelled at</th>', VIEW)
        self.assertIn('route(\'reservations.show\', $r)', VIEW)
        marker = VIEW.index("Cancelled booking register")
        branch = VIEW.rfind("@elseif($view === 'cancelled')", 0, marker)
        end = VIEW.index("@elseif($view === 'waitlist')", marker)
        self.assertNotIn("reservations.cancel", VIEW[branch:end])
        self.assertIn("route('reservations.show', $r)", VIEW[branch:end])

    def test_facets_toolbar_and_dashboard_drilldowns_are_real_and_accessible(self):
        self.assertIn('aria-label="Filter cancelled bookings"', VIEW)
        self.assertIn("'cancelled_focus' => 'groups'", VIEW)
        self.assertIn("'cancelled_focus' => 'individuals'", VIEW)
        self.assertIn('class="cancelled-toolbar"', VIEW)
        self.assertIn('name="date"', VIEW[VIEW.index('class="cancelled-toolbar"'):VIEW.index("@elseif($view !== 'list')", VIEW.index('class="cancelled-toolbar"'))])
        self.assertIn('name="days"', VIEW)
        self.assertIn('name="q" maxlength="160"', VIEW)
        self.assertIn("['Cancelled', $kpis['cancelled'], ['view' => 'cancelled'", VIEW)
        self.assertIn("['view' => 'cancelled', 'date' => $date->toDateString(), 'days' => 1]", DASHBOARD)

    def test_empty_state_mobile_dark_and_print_styles_are_present(self):
        self.assertIn('class="cancelled-empty" role="status"', VIEW)
        self.assertIn('.desk.dark[data-view="cancelled"]', VIEW)
        self.assertIn('@media(max-width:760px){.cancelled-hero', VIEW)
        self.assertIn('@media(max-width:560px){.cancelled-hero', VIEW)
        self.assertIn('@media print{@page{size:A4 landscape;margin:9mm}#desk[data-view="cancelled"]', VIEW)
        self.assertIn("'cancelled' => 'Cancelled'", VIEW[VIEW.index("@foreach(['timeline'"):])


if __name__ == "__main__":
    unittest.main(verbosity=2)
