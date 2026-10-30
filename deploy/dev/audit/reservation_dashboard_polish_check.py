#!/usr/bin/env python3
"""Regression checks for the reservation desk dashboard refresh."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
INDEX = (ROOT / "resources/views/reservations/index.blade.php").read_text()
DASHBOARD = (ROOT / "resources/views/reservations/desk-dashboard.blade.php").read_text()


class ReservationDashboardPolishTest(unittest.TestCase):
    def test_dashboard_has_a_distinct_daily_operations_hero(self):
        for needle in (
            "@elseif($view === 'dashboard')",
            'class="desk-head desk-dashboard-hero"',
            'FRONT OFFICE', 'DAILY OPERATIONS', 'Today at a glance',
            "Business date · {{ bdate()->format('d M Y') }}",
            "{{ number_format($kpis['sellable']) }} rooms on sale",
        ):
            with self.subTest(needle=needle):
                self.assertIn(needle, INDEX)

    def test_dashboard_has_six_operational_links_and_collapsible_detail_metrics(self):
        self.assertIn('$dashboardStats = [', INDEX)
        for label in ('Rooms available', 'Arrivals', 'Departures', 'In-house', 'Occupancy', 'Unassigned'):
            with self.subTest(metric=label):
                self.assertIn(f"['{label}'", INDEX)
        self.assertIn('role="group" aria-label="Front desk snapshot"', INDEX)
        self.assertIn('class="desk-summary-card tone-{{ $tone }}"', INDEX)
        self.assertIn('<details class="desk-metric-details">', INDEX)
        self.assertIn('<summary>All desk figures', INDEX)

    def test_dashboard_search_opens_the_reservation_register(self):
        self.assertIn('class="desk-dashboard-search"', INDEX)
        self.assertIn('method="get" role="search"', INDEX)
        self.assertIn('name="view" value="list"', INDEX)
        self.assertIn('id="deskDashboardSearch" type="search" name="q"', INDEX)
        self.assertIn('Guest, confirmation, phone or room', INDEX)
        self.assertIn("can_do('frontdesk.checkin')", INDEX)
        self.assertIn("route('frontdesk.walkin')", INDEX)

    def test_dashboard_tools_are_available_without_cluttering_the_hero(self):
        self.assertIn('<details class="desk-dashboard-tools">', INDEX)
        self.assertIn('<summary><x-icon name="grid" size="sm"/> Desk tools</summary>', INDEX)
        for route in ('reservations.plan', 'reservations.daysummary', 'reservations.export', 'frontdesk.tapechart', 'reservations.amenities'):
            with self.subTest(route=route):
                self.assertIn(f"route('{route}'", INDEX)
        self.assertIn('desk-dashboard-date-tools', INDEX)
        self.assertIn('Update desk date', INDEX)
        self.assertIn('@if($view === \'timeline\')<label class="zoom">', INDEX)
        self.assertIn('@if($view !== \'dashboard\')\n  <nav class="desk-nav', INDEX)

    def test_room_snapshot_is_compact_and_accessible(self):
        for needle in (
            'LIVE ROOM STATUS', 'Room snapshot', 'role="list" aria-label="Active rooms',
            'role="listitem"', 'housekeeping {{ ucwords', 'class="room-status-label"',
            'room-strip-legend', 'Out of order / service', '.desk-dashboard .room-strip{display:flex',
        ):
            with self.subTest(needle=needle):
                haystack = INDEX if needle.startswith('.desk-dashboard') else DASHBOARD
                self.assertIn(needle, haystack)

    def test_forecast_charts_have_text_alternatives_and_responsive_styles(self):
        for needle in (
            'aria-label="Expected occupancy for the next 14 days"',
            'aria-label="Expected room revenue before tax for the next 14 days"',
            'aria-label="Daily arrivals and departures for the next 7 days"',
            'role="img" aria-label="{{ $p[\'date\']->format(\'d M\') }}: expected occupancy',
            'desk-chart-legend', 'desk-flow-bars',
        ):
            with self.subTest(needle=needle):
                self.assertIn(needle, DASHBOARD if 'aria-label=' in needle else INDEX + DASHBOARD)
        for selector in (
            '#desk[data-view="dashboard"] .desk-dashboard-hero',
            '.desk-summary-grid{grid-template-columns:repeat(3',
            '@media(max-width:760px)', '@media(max-width:420px)',
            '.desk.dark[data-view="dashboard"]', '@media(prefers-reduced-motion:reduce)',
        ):
            with self.subTest(selector=selector):
                self.assertIn(selector, INDEX)


if __name__ == "__main__":
    unittest.main(verbosity=2)
