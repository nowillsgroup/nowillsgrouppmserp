#!/usr/bin/env python3
"""Regression checks for the real Nowills PMS main dashboard."""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[3]
VIEW = (ROOT / "resources/views/dashboard.blade.php").read_text()
CSS = (ROOT / "public/css/dashboard.css").read_text()
APP_CSS = (ROOT / "public/css/app.css").read_text()
CONTROLLER = (ROOT / "app/Http/Controllers/DashboardController.php").read_text()


class MainDashboardPolishTest(unittest.TestCase):
    def test_hero_has_permission_aware_operational_shortcuts(self):
        for fragment in (
            'class="dash-welcome-actions" aria-label="Property actions"',
            "can_do('reservations.view')", "route('reservations.index', ['view' => 'dashboard'])",
            "can_do('frontdesk.view')", "route('frontdesk.arrivals')",
            "can_do('frontdesk.checkin')", "route('frontdesk.walkin')",
            "config('pms.version') }}-dashboard-layout-v8",
        ):
            with self.subTest(fragment=fragment):
                self.assertIn(fragment, VIEW)

    def test_room_flow_metrics_explain_their_counts(self):
        for fragment in (
            'label="Occupancy"', "$occupied.' occupied · '.$sellable.' sellable rooms'",
            'label="Arrivals checked in"', "$arrivalsCheckedIn.' / '.$arrivalsExpected",
            'sub="Checked in / due today (includes no-shows)"', 'label="Departures pending" :value="$departuresCount"',
            'sub="Due today or earlier"', 'sub="Currently staying"',
            'Occupancy rate excludes unavailable rooms',
            'unavailable because of room restrictions, outages or leases',
            'Dirty is a housekeeping count; these rooms may also be occupied or vacant.',
        ):
            with self.subTest(fragment=fragment):
                self.assertIn(fragment, VIEW)

    def test_arrival_progress_uses_the_due_arrival_date(self):
        self.assertIn("ReservationRoom::whereDate('arrival_date', $d)->whereIn('status', ['reserved', 'checked_in', 'checked_out', 'no_show'])", CONTROLLER)
        self.assertIn("$arrivalsExpected = $arrivalCounts->sum()", CONTROLLER)
        self.assertNotIn("whereDate('checked_in_at', $d)", CONTROLLER)

    def test_finance_metrics_describe_sources_and_avoid_mislabelled_adr(self):
        for fragment in (
            'label="Room revenue"', 'Posted room and extra-bed charges',
            'Other folio charges + settled POS', 'Payments + settled POS',
            'label="Room revenue / occupied room"', 'Posted room revenue ÷ rooms occupied now',
        ):
            with self.subTest(fragment=fragment):
                self.assertIn(fragment, VIEW)
        self.assertNotIn('label="ADR (today)"', VIEW)
        self.assertIn("@if($canSeeFinance)\n  <section class=\"dash-section dash-finance-section\"", VIEW)

    def test_history_charts_plot_revenue_and_occupancy_separately(self):
        for fragment in (
            "$canSeeFinance ? 'Revenue & occupancy trend' : 'Occupancy trend'", 'Last 14 completed audit days',
            'id="dashRevenueTrendHeading">Daily revenue', 'id="dashOccupancyTrendHeading">Daily occupancy',
            'role="group" aria-label="Daily revenue for the last 14 completed audit days"',
            'role="group" aria-label="Daily occupancy for the last 14 completed audit days"',
            'class="b dash-revenue-bar"', 'class="b dash-occupancy-bar"',
            'aria-label="{{ $a->business_date->format(\'d M Y\') }}: {{ $a->occupancy_pct }}% occupancy"',
        ):
            with self.subTest(fragment=fragment):
                self.assertIn(fragment, VIEW)
        chart_start = VIEW.index('dash-chart-card')
        finance_panel = VIEW.index('id="dashRevenueTrendHeading">Daily revenue', chart_start)
        occupancy_panel = VIEW.index('id="dashOccupancyTrendHeading">Daily occupancy', chart_start)
        self.assertIn('@if($canSeeFinance)', VIEW[chart_start:finance_panel])
        self.assertLess(finance_panel, occupancy_panel)
        self.assertIn('No completed audit days are available for this trend yet.', VIEW)
        self.assertNotIn('Run night audit to build a daily occupancy history.', VIEW)
        self.assertIn('.dash-chart-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))', CSS)
        self.assertIn('.dash-chart-card .dash-history-chart .dash-occupancy-bar', CSS)

    def test_arrival_and_departure_previews_disclose_hidden_rows(self):
        for fragment in (
            'Expected arrivals <span class="badge badge-blue">{{ $arrivalsCount }}</span>',
            'Due departures <span class="badge badge-amber">{{ $departuresCount }}</span>',
            '@forelse($arrivals->take(6) as $s)', '@if($arrivalsCount > 6)',
            '+ {{ $arrivalsCount - 6 }} more pending arrivals',
            '@forelse($departures->take(6) as $s)', '@if($departuresCount > 6)',
            '+ {{ $departuresCount - 6 }} more due departures',
        ):
            with self.subTest(fragment=fragment):
                self.assertIn(fragment, VIEW)

    def test_restricted_dashboard_links_respect_their_permissions(self):
        self.assertIn("can_do('frontdesk.view')\n            ? $arrivalsQuery", CONTROLLER)
        self.assertIn("can_do('frontdesk.view')\n            ? $departuresQuery", CONTROLLER)
        self.assertIn("@if(can_do('maintenance.manage'))<li><span><x-icon name=\"tool\"", VIEW)
        self.assertIn("@if(can_do('pos.view'))<li><span><x-icon name=\"cup\"", VIEW)

    def test_sensitive_rows_are_permission_gated_and_sampled_in_the_controller(self):
        for fragment in (
            "'arrivalsCount' => $arrivalsCount", "'departuresCount' => $departuresCount",
            "can_do('frontdesk.view')", "->orderBy('id')->limit(7)->get()",
            "'activity' => can_do('logs.view') ? ActivityLog::with('user')",
            "'events' => can_do('banquet.view')", "can_do('inventory.view')",
            "'openTickets' => can_do('maintenance.manage')", "'openPos' => can_do('pos.view')",
            "can_do('reservations.view')\n            ? Reservation::query()",
        ):
            with self.subTest(fragment=fragment):
                self.assertIn(fragment, CONTROLLER)
        for fragment in (
            '@if(can_do(\'frontdesk.view\'))\n    <div class="grid g-2 dash-stay-grid">',
            '@if(can_do(\'logs.view\'))\n    <div class="card dash-activity-card">',
            '@if(can_do(\'reservations.view\'))\n    <div class="card">\n      <div class="card-head"><h3>Reservation source mix',
            '@if(can_do(\'banquet.view\'))\n    <div class="card">\n      <div class="card-head"><h3>Upcoming banquets',
            '@if(can_do(\'inventory.view\'))\n          @foreach($lowStock as $i)',
        ):
            with self.subTest(fragment=fragment):
                self.assertIn(fragment, VIEW)

    def test_departure_folio_balance_requires_folio_permission(self):
        start = VIEW.index('@forelse($departures->take(6) as $s)')
        end = VIEW.index('@empty <li class="muted">No pending departures.', start)
        departure_preview = VIEW[start:end]
        folio_gate = departure_preview.index("@if(can_do('folio.view'))")
        due_query = departure_preview.index("StayService::quoteCheckoutFigures($s)['due']")
        restricted_fallback = departure_preview.index('@else · Folio balance restricted')
        self.assertLess(folio_gate, due_query)
        self.assertLess(due_query, restricted_fallback)

    def test_department_snapshots_are_hidden_when_viewer_has_no_module_access(self):
        self.assertIn("@if(can_do('budget.view') || can_do('calls.view') || can_do('keys.view') || can_do('vouchers.view') || can_do('security.view') || can_do('utilities.view') || can_do('compliance.view') || can_do('reservations.view') || can_do('banquet.view'))\n<section class=\"dash-section dash-module-section\"", VIEW)
        self.assertIn('id="dashModulesHeading"', VIEW)
        self.assertIn("@if(can_do('reservations.view'))\n    <div class=\"card\">\n      <div class=\"card-head\"><h3>Reservation source mix", VIEW)
        self.assertIn("@if(can_do('banquet.view'))\n    <div class=\"card\">\n      <div class=\"card-head\"><h3>Upcoming banquets", VIEW)

    def test_financial_data_and_booking_aggregate_are_permission_gated(self):
        for fragment in (
            "$canSeeFinance = can_do('folio.view') || can_do('reports.view')",
            'if ($canSeeFinance) {',
            "select('type', 'category', DB::raw('SUM(amount) as amount_sum'))",
            "selectRaw('COALESCE(SUM(total - tax), 0) as net_total, COALESCE(SUM(total), 0) as gross_total')",
            "NightAudit::query()->select('business_date', 'occupancy_pct')",
            "if ($canSeeFinance) {\n            $auditQuery->addSelect('room_revenue', 'other_revenue');",
            "'newBookings' => can_do('reservations.view') ? Reservation::whereDate('created_at', $d)->count() : 0",
        ):
            with self.subTest(fragment=fragment):
                self.assertIn(fragment, CONTROLLER)
        self.assertIn("@if(can_do('reservations.view'))<li><span><x-icon name=\"calendar\" size=\"sm\"/> New bookings today", VIEW)
        self.assertIn("'income_audit.view'", CONTROLLER)

    def test_action_centre_is_visible_before_historical_analysis(self):
        self.assertIn('Only actions from areas you can access are shown. A blank list means none are currently flagged.', VIEW)
        self.assertLess(
            VIEW.index('<section class="dash-section dash-attention-section"'),
            VIEW.index('<div class="grid g-2-1 dash-main-grid">'),
            "time-sensitive action centre should appear before trends and activity",
        )

    def test_dashboard_styles_support_mobile_dark_mode_and_reduced_motion(self):
        for fragment in (
            '.dash-welcome-actions', '.dash-chart-panel', 'body.dark .dash-chart-panel',
            '@media(max-width:640px)', '.dash-chart-grid{grid-template-columns:1fr}',
            '@media(prefers-reduced-motion:reduce)', '.dash-shortcuts-card .quick a:focus-visible',
            'body.dark .dash-welcome{background:linear-gradient(105deg,var(--c-surface),var(--c-primary-50))',
            'body.dark .dash-snapshot-label{border-color:var(--c-primary-100);background:var(--c-primary-50);color:var(--c-primary)}',
        ):
            with self.subTest(fragment=fragment):
                self.assertIn(fragment, CSS)

    def test_dashboard_dark_palette_meets_small_text_contrast(self):
        match = re.search(r"body\.dark\s*\{([^}]*)\}", APP_CSS)
        self.assertIsNotNone(match, "dark theme color tokens should be present")
        tokens = dict(re.findall(r"(--[\w-]+):\s*(#[0-9a-fA-F]{6})", match.group(1)))

        def luminance(color):
            channels = [int(color[index:index + 2], 16) / 255 for index in (1, 3, 5)]
            linear = [channel / 12.92 if channel <= .04045 else ((channel + .055) / 1.055) ** 2.4 for channel in channels]
            return .2126 * linear[0] + .7152 * linear[1] + .0722 * linear[2]

        def contrast(foreground, background):
            light, dark = sorted((luminance(foreground), luminance(background)), reverse=True)
            return (light + .05) / (dark + .05)

        for label, foreground, background in (
            ("green accent on green highlight", "--c-primary", "--c-primary-50"),
            ("body text on page background", "--c-text", "--c-bg"),
            ("secondary text on card surface", "--c-text-2", "--c-surface"),
            ("muted text on page background", "--c-muted", "--c-bg"),
        ):
            with self.subTest(pair=label):
                self.assertIn(foreground, tokens)
                self.assertIn(background, tokens)
                self.assertGreaterEqual(contrast(tokens[foreground], tokens[background]), 4.5)

        self.assertIn('body.dark .dash-chart-card .dash-history-chart .dash-revenue-bar{background:linear-gradient(180deg,#4ade80,#16a34a)}', CSS)
        self.assertIn('body.dark .dash-chart-card .dash-history-chart .dash-occupancy-bar{background:linear-gradient(180deg,#93c5fd,#3b82f6)}', CSS)
        for color in ('#4ade80', '#16a34a', '#93c5fd', '#3b82f6'):
            with self.subTest(chart_bar=color):
                self.assertGreaterEqual(contrast(color, tokens['--c-bg']), 3.0)


if __name__ == "__main__":
    unittest.main(verbosity=2)
