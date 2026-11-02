#!/usr/bin/env python3
"""Static checks for the full-shell Reservation Desk no-show register."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
SERVICE = (ROOT / "app/Services/ReservationDeskService.php").read_text()
VIEW = (ROOT / "resources/views/reservations/index.blade.php").read_text()
DASHBOARD = (ROOT / "resources/views/reservations/desk-dashboard.blade.php").read_text()


class ReservationNoShowPolishTest(unittest.TestCase):
    def test_no_show_is_a_first_class_reservation_desk_view(self):
        self.assertIn("'groups', 'noshow'", SERVICE)
        self.assertIn("'noshow' => 'No-show Register'", VIEW)
        self.assertIn("'noshow' => 'No-show'", VIEW)
        self.assertIn("'noshow' => 'No-show'", VIEW[VIEW.index("$views ="):])
        self.assertIn("'noshow' => 'No-show'", VIEW[VIEW.index("@foreach(['timeline'"):])

    def test_arrival_window_summary_precedes_focus_and_search(self):
        branch = SERVICE.index("if ($view === 'noshow') {", SERVICE.index("$group = in_array"))
        query = SERVICE[branch:SERVICE.index("if ($view === 'list') {", branch)]
        self.assertIn("where('status', 'no_show')", query)
        self.assertIn("whereDate('arrival_date', '>=', $windowStart)", query)
        self.assertIn("whereDate('arrival_date', '<', $windowEnd)", query)
        self.assertLess(query.index("$noShowSummary = ["), query.index("$noShowFocus ="))
        self.assertLess(query.index("$noShowSummary = ["), query.index("$noShowSearch ="))
        self.assertIn("'room_stays' => ReservationRoom::where('status', 'no_show')", query)

    def test_guest_contact_search_and_display_are_permission_gated(self):
        self.assertIn("$canSearchNoShowContact = can_do('guests.view')", SERVICE)
        self.assertIn("if ($canSearchNoShowContact)", SERVICE)
        self.assertIn("can_do('guests.view') && $noShowPhone", VIEW)
        self.assertIn("can_do('guests.view') && $r->guest?->is_vip", VIEW)

    def test_no_show_register_is_semantic_read_only_and_links_to_existing_records(self):
        self.assertIn('class="noshow-manifest" aria-labelledby="noshowManifestTitle"', VIEW)
        self.assertIn('<caption class="sr-only">No-show reservations with arrivals in the selected', VIEW)
        self.assertIn('<th scope="col">Expected stay</th>', VIEW)
        self.assertIn('route(\'reservations.show\', $r)', VIEW)
        self.assertIn('No-show timestamps are not inferred from arrival dates.', VIEW)
        marker = VIEW.index("No-show booking register")
        branch = VIEW.rfind("@elseif($view === 'noshow')", 0, marker)
        end = VIEW.index("@elseif($view === 'waitlist')", marker)
        self.assertNotIn("reservations.noshow", VIEW[branch:end])

    def test_filter_facets_and_search_toolbar_are_accessible_and_real(self):
        self.assertIn('aria-label="Filter no-show bookings"', VIEW)
        self.assertIn("'noshow_focus' => 'groups'", VIEW)
        self.assertIn("'noshow_focus' => 'individuals'", VIEW)
        self.assertIn('class="noshow-toolbar"', VIEW)
        toolbar = VIEW[VIEW.index('class="noshow-toolbar"'):VIEW.index("@elseif($view !== 'list')", VIEW.index('class="noshow-toolbar"'))]
        self.assertIn('name="date"', toolbar)
        self.assertIn('name="days"', toolbar)
        self.assertIn('name="q" maxlength="160"', toolbar)
        self.assertIn("['No-shows', $kpis['noshow'], ['view' => 'noshow'", VIEW)
        self.assertIn("['view' => 'noshow', 'date' => $date->toDateString(), 'days' => 1]", DASHBOARD)

    def test_empty_state_and_responsive_dark_print_styles_are_present(self):
        self.assertIn('class="noshow-empty" role="status"', VIEW)
        self.assertIn('.desk.dark[data-view="noshow"]', VIEW)
        self.assertIn('@media(max-width:760px){.noshow-hero', VIEW)
        self.assertIn('@media(max-width:560px){.noshow-hero', VIEW)
        self.assertIn('@media print{@page{size:A4 landscape;margin:9mm}#desk[data-view="noshow"]', VIEW)
        self.assertIn("'noshow' => 'No-show'", VIEW[VIEW.index("@foreach(['timeline'"):])


if __name__ == "__main__":
    unittest.main(verbosity=2)
