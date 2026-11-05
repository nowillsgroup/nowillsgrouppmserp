#!/usr/bin/env python3
"""Regression checks for the permission-aware management dashboard."""
from pathlib import Path
import re
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[3]
MAIN_VIEW = (ROOT / "resources/views/dashboard.blade.php").read_text()
OVERVIEW = (ROOT / "resources/views/dashboard/management-overview.blade.php").read_text()
TRENDS = (ROOT / "resources/views/dashboard/management-trends.blade.php").read_text()
VIEW = MAIN_VIEW.replace("@include('dashboard.management-overview')", OVERVIEW)
VIEW = VIEW.replace("@include('dashboard.management-trends')", TRENDS)
CSS = (ROOT / "public/css/dashboard.css").read_text()
APP_CSS = (ROOT / "public/css/app.css").read_text()
CONTROLLER = (ROOT / "app/Http/Controllers/DashboardController.php").read_text()
PREVIEW = (ROOT / "deploy/dev/previews/management-dashboard.html").read_text()
ROLE_MATRIX = (ROOT / "deploy/dev/audit/main_dashboard_role_matrix_check.php").read_text()
RESTRICTED_MATRIX = (ROOT / "deploy/dev/audit/main_dashboard_permission_http_check.php").read_text()


class MainDashboardPolishTest(unittest.TestCase):
    def test_management_hero_and_quick_actions_are_permission_aware(self):
        for fragment in (
            'aria-label="Management dashboard heading"', 'Management overview',
            'BUSINESS DATE', '{{ bdate()->format(\'D, d M Y\') }}',
            "can_do('reservations.create')", "route('reservations.create')",
            "can_do('reservations.view')", "route('reservations.index', ['view' => 'dashboard'])",
            "can_do('frontdesk.view')", "route('frontdesk.arrivals')",
            "can_do('frontdesk.checkin')", "route('frontdesk.walkin')",
            "can_do('reports.view')", "route('reports.index')",
            "can_do('nightaudit.run')", "route('nightaudit.index')",
        ):
            with self.subTest(fragment=fragment):
                self.assertIn(fragment, VIEW)
        self.assertIn('dashboard-layout-v14', MAIN_VIEW)

    def test_executive_kpis_only_include_authorized_values(self):
        self.assertIn("if (can_do('frontdesk.view')) {\n            $executiveKpis[]", CONTROLLER)
        self.assertIn('if ($canSeeFinance) {\n            $executiveKpis[]', CONTROLLER)
        for fragment in (
            "'label' => 'Room occupancy'", "'label' => 'Arrivals due'",
            "'label' => 'In-house guests'", "'label' => 'Revenue today'",
            "'label' => 'Collections today'", "'label' => 'Room ADR'",
        ):
            with self.subTest(fragment=fragment):
                self.assertIn(fragment, CONTROLLER)
        self.assertIn('aria-label="Executive key performance indicators"', VIEW)
        self.assertIn("$canSeeFinance = can_do('folio.view') || can_do('reports.view') || can_do('revenue.view') || can_do('income_audit.view')", CONTROLLER)
        self.assertIn('if ($canSeeFinance) {', CONTROLLER)

    def test_department_pulse_is_gated_by_module_permissions(self):
        for permission in (
            'frontdesk.view', 'reservations.view', 'reports.view', 'pos.view',
            'housekeeping.view', 'banquet.view', 'maintenance.manage', 'inventory.view',
            'feedback.view', 'payroll.view', 'hr.manage',
        ):
            with self.subTest(permission=permission):
                self.assertIn(f"can_do('{permission}')", CONTROLLER)
        for title in (
            'Rooms & front office', 'Reservations & channels', 'Finance & revenue',
            'Food & beverage', 'Housekeeping', 'Events & banquets', 'Maintenance',
            'Inventory & procurement', 'Guest experience', 'People & payroll',
        ):
            with self.subTest(module=title):
                self.assertIn(title, CONTROLLER)
        self.assertIn('@if($managementModules)', VIEW)
        self.assertIn("@if($module['href'])", VIEW)
        self.assertIn('Live KPIs from the modules you’re authorized to view.', VIEW)
        self.assertIn('financial detail restricted', CONTROLLER)

    def test_finance_sources_and_adr_are_explicit_and_gated(self):
        self.assertIn("DB::raw('SUM(amount) as amount_sum')", CONTROLLER)
        self.assertIn("COALESCE(SUM(total - tax), 0) as net_total", CONTROLLER)
        self.assertIn("COALESCE(SUM(total), 0) as gross_total", CONTROLLER)
        self.assertIn("$adr = $occupied ? $roomRevenue / max(1, $occupied) : 0", CONTROLLER)
        self.assertIn('Payments received · excludes city ledger', CONTROLLER)
        self.assertIn('Posted room revenue per occupied room', CONTROLLER)
        self.assertIn('$posRevenueToday = $canSeeFinance', CONTROLLER)
        self.assertIn("'executiveKpis' => $executiveKpis", CONTROLLER)

    def test_trends_keep_finance_private_and_plot_14_audit_days(self):
        for fragment in (
            "{{ $canSeeFinance ? 'Revenue & occupancy trend' : 'Occupancy trend' }}",
            'Last 14 completed audit days', 'id="dashRevenueTrendHeading">Daily revenue',
            'id="dashOccupancyTrendHeading">Daily occupancy',
            'role="group" aria-label="Daily revenue for the last 14 completed audit days"',
            'role="group" aria-label="Daily occupancy for the last 14 completed audit days"',
            'class="b dash-revenue-bar"', 'class="b dash-occupancy-bar"',
            'No completed audit days are available for this trend yet.',
        ):
            with self.subTest(fragment=fragment):
                self.assertIn(fragment, VIEW)
        self.assertIn("NightAudit::query()->select('business_date', 'occupancy_pct')", CONTROLLER)
        self.assertIn("if ($canSeeFinance) {\n            $auditQuery->addSelect('room_revenue', 'other_revenue');", CONTROLLER)
        self.assertIn('@if($canSeeFinance)', TRENDS)
        action = VIEW.index('<section class="dash-section dash-attention-section"')
        chart = VIEW.index('<section class="dash-management-trends"')
        operating = VIEW.index('<div class="grid g-2-1 dash-main-grid">')
        self.assertLess(action, chart, 'time-sensitive action centre stays ahead of historical analysis')
        self.assertLess(chart, operating)

    def test_arrival_progress_uses_the_due_arrival_date(self):
        self.assertIn("$canSeeFrontDeskMetrics = can_do('frontdesk.view') || can_do('reservations.view')", CONTROLLER)
        self.assertIn("$arrivalCounts = $canSeeFrontDeskMetrics\n            ? ReservationRoom::whereDate('arrival_date', $d)", CONTROLLER)
        self.assertIn("ReservationRoom::whereDate('arrival_date', $d)->whereIn('status', ['reserved', 'checked_in', 'checked_out', 'no_show'])", CONTROLLER)
        self.assertIn('$departuresCount = $canSeeFrontDeskMetrics ?', CONTROLLER)
        self.assertIn("$inHouseGuests = can_do('frontdesk.view')", CONTROLLER)
        self.assertIn('$arrivalsExpected = $arrivalCounts->sum()', CONTROLLER)
        self.assertNotIn("whereDate('checked_in_at', $d)", CONTROLLER)

    def test_departure_folio_balance_requires_folio_permission(self):
        start = VIEW.index('@forelse($departures->take(6) as $s)')
        end = VIEW.index('@empty <li class="muted">No pending departures.', start)
        preview = VIEW[start:end]
        gate = preview.index("@if(can_do('folio.view'))")
        due = preview.index("StayService::quoteCheckoutFigures($s)['due']")
        restricted = preview.index('@else · Folio balance restricted')
        self.assertLess(gate, due)
        self.assertLess(due, restricted)

    def test_existing_front_desk_and_department_workflows_remain(self):
        for fragment in (
            'Expected arrivals <span class="badge badge-blue">{{ $arrivalsCount }}</span>',
            'Due departures <span class="badge badge-amber">{{ $departuresCount }}</span>',
            '@forelse($arrivals->take(6) as $s)', '@forelse($departures->take(6) as $s)',
            'StayService::quoteCheckoutFigures($s)[\'due\']',
            "route('frontdesk.rooms')", "route('housekeeping.index')",
            "route('banquet.diary')", 'Reservation sources', 'Upcoming banquets',
        ):
            with self.subTest(fragment=fragment):
                self.assertIn(fragment, VIEW)
        self.assertIn("can_do('frontdesk.view')\n            ? $arrivalsQuery", CONTROLLER)
        self.assertIn("can_do('frontdesk.view')\n            ? $departuresQuery", CONTROLLER)
        self.assertIn('unavailable because of room restrictions, outages or leases', VIEW)
        self.assertIn('Dirty is a housekeeping count; these rooms may also be occupied or vacant.', VIEW)

    def test_all_dashboard_module_panels_remain_permission_gated(self):
        for permission in ('budget.view', 'calls.view', 'keys.view', 'vouchers.view', 'security.view', 'utilities.view', 'compliance.view', 'reservations.view', 'banquet.view'):
            with self.subTest(permission=permission):
                self.assertIn(f"can_do('{permission}')", VIEW)
        self.assertIn('id="dashModulesHeading"', VIEW)
        self.assertIn("@if(can_do('banquet.view'))\n    <div class=\"card\">\n      <div class=\"card-head\"><h3>Upcoming banquets", VIEW)

    def test_mix_pie_charts_use_permission_gated_live_aggregates(self):
        self.assertIn('@if($canSeeFinance || can_do(\'reservations.view\') || can_do(\'pos.view\'))', VIEW)
        self.assertIn("@if($canSeeFinance)\n    @php\n      $revenueSlices", VIEW)
        self.assertIn("@if(can_do('reservations.view'))\n    @php", VIEW)
        self.assertIn('Revenue breakdown · today', VIEW)
        self.assertIn('Reservation sources', VIEW)
        self.assertIn('No non-cancelled bookings in the last 30 days.', VIEW)
        self.assertIn('other folio charges and settled POS', VIEW)
        self.assertIn('cancelled bookings excluded', VIEW)
        self.assertIn("$sourceNoSource = $sourceMix->firstWhere('name', 'No source')", VIEW)
        self.assertIn("$sourceMix->reject(fn ($row) => $row->name === 'No source')->take(4)->values()", VIEW)
        self.assertIn('conic-gradient(', VIEW)
        self.assertIn('role="img" aria-label="Today\'s revenue mix.', VIEW)
        self.assertIn('role="img" aria-label="Reservation source mix', VIEW)
        self.assertIn('negative adjustments', VIEW)
        self.assertIn('SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as positive_amount_sum', CONTROLLER)
        self.assertIn("'roomRevenuePositive' => $roomRevenuePositive", CONTROLLER)
        self.assertIn('$revenueMixHasAdjustments', VIEW)
        self.assertIn('Pie proportions use positive revenue postings only', VIEW)
        self.assertIn("$sourceMix = can_do('reservations.view')", CONTROLLER)
        self.assertIn("leftJoin('business_sources', function ($join)", CONTROLLER)
        self.assertIn("->on('business_sources.hotel_id', '=', 'reservations.hotel_id')", CONTROLLER)
        self.assertIn("COALESCE(business_sources.name, 'No source') as name", CONTROLLER)
        self.assertIn("str_contains($html, 'dashRevenueMixTitle') === $canSeeFinance", ROLE_MATRIX)
        self.assertIn("str_contains($html, 'dashSourceMixTitle') === $canSeeReservations", ROLE_MATRIX)
        self.assertIn("str_contains($html, 'dashPosMixTitle') === $canSeePos", ROLE_MATRIX)
        self.assertIn("! str_contains($html, 'dashRevenueMixTitle')", RESTRICTED_MATRIX)
        self.assertIn("! str_contains($html, 'dashSourceMixTitle')", RESTRICTED_MATRIX)
        self.assertIn("str_contains($html, 'dashPosMixTitle') === $canSeePos", RESTRICTED_MATRIX)
        self.assertIn("str_contains($sql, 'sum(') || in_array('payment', $bindings, true)", ROLE_MATRIX)
        self.assertIn("str_contains($sql, 'sum(') || in_array('payment', $bindings, true)", RESTRICTED_MATRIX)
        self.assertIn("if (str_contains($sql, 'reservation_rooms'))", ROLE_MATRIX)
        self.assertIn("if (str_contains($sql, 'reservation_rooms'))", RESTRICTED_MATRIX)
        self.assertIn("$user->hasPermission('reservations.view') || $user->hasPermission('frontdesk.view')", RESTRICTED_MATRIX)
        self.assertIn('guest-flow aggregates are skipped without front-desk or reservation access', ROLE_MATRIX)
        self.assertIn('guest-flow queries are skipped without front-desk or reservation access', RESTRICTED_MATRIX)
        self.assertIn("$posOrderStatusToday = can_do('pos.view')", CONTROLLER)
        self.assertIn("whereDate('business_date', $d)->whereIn('status', ['open', 'billed', 'settled'])", CONTROLLER)
        self.assertIn("whereIn('status', ['open', 'billed', 'settled'])", CONTROLLER)
        self.assertIn("'posOrderStatusToday' => $posOrderStatusToday", CONTROLLER)
        self.assertIn('void and merged checks excluded', VIEW)
        self.assertIn('No open, billed or settled POS checks today.', VIEW)
        self.assertIn('dashPosMixTitle', VIEW)
        self.assertNotIn('GH₵ 46,850', (ROOT / 'resources/views/dashboard/management-trends.blade.php').read_text())

    def test_preview_shows_the_live_screen_without_preview_chrome(self):
        # The preview exists to show what the screen looks like in the application, so no
        # banner, badge, aria description or caption may mark it out as a preview. Those
        # labels were removed at the user's request and must not creep back in.
        for marker in ('UI-only preview', 'illustrative', 'Illustrative', 'not live property data',
                       'No records are created', 'management-preview-note'):
            with self.subTest(marker=marker):
                self.assertNotIn(marker, PREVIEW)
        # Its provenance is still stated, where only a reader of the source sees it.
        self.assertIn('<!-- Generated by deploy/dev/build-dashboard-preview.mjs', PREVIEW)
        # And it carries the live screen's own copy rather than a preview paraphrase.
        for copy in (
            'GHS · figures respect your access',
            '11 business areas</span>',
            'Last 14 completed audit days · hover or keyboard-focus a bar for its exact value.',
            '>Night audit</a>',
            '<h2 id="dashMixHeading">Business mix</h2>',
            "Mix charts are built from current tenant records and follow finance, reservation and POS permissions.",
            "aria-label=\"Today's revenue mix. Net revenue GH₵ 46,850.",
            'aria-label="Reservation source mix for the last 30 days. 214 bookings, cancelled bookings excluded."',
            'aria-label="POS checks today: 74 total. Open 12; Billed 11; Settled 51."',
        ):
            with self.subTest(copy=copy):
                self.assertIn(copy, PREVIEW)
        self.assertIn('dash-mix-label">No source</span><strong>10</strong>', PREVIEW)
        self.assertIn('dash-mix-label">Other sources</span><strong>8</strong>', PREVIEW)

    def test_preview_is_the_full_screen_inside_the_application_shell(self):
        """The dashboard is never shown bare in the app, so the preview carries the shell.

        The sidebar is generated from config/pms.php, so this also fails when a module
        is added to the menu registry and the preview is not rebuilt.
        """
        for fragment in (
            '<aside class="sidebar" id="primary-sidebar"', '<nav class="nav" aria-label="Primary navigation">',
            'class="brand"', 'brand-logo-full', 'brand-logo-mark',
            '<header class="topbar">', 'class="top-search"', 'class="top-tools"', 'class="bdate"',
            'class="user-chip"', 'class="side-profile"', 'class="side-foot"',
            '<div class="page-head">', '<h1>Dashboard</h1>', 'dash-page-actions',
        ):
            with self.subTest(fragment=fragment):
                self.assertIn(fragment, PREVIEW)

        # Every section and top-level module in the menu registry is on the page, in order.
        # Only the 'menu' array counts — the same file holds other label lists after it.
        config = (ROOT / 'config/pms.php').read_text()
        open_at = config.index('[', config.index("'menu' => ["))
        depth, close_at = 0, open_at
        for close_at in range(open_at, len(config)):
            depth += {'[': 1, ']': -1}.get(config[close_at], 0)
            if depth == 0:
                break
        menu = config[open_at:close_at]
        # 'employee' => true entries only appear for a user linked to a staff record —
        # the preview renders an administrator login, so those are not on the page.
        labels = [label for line in menu.splitlines()
                  if "'employee' => true" not in line
                  for label in re.findall(r"'label' => '([^']+)'", line)]
        sections = re.findall(r"'section' => '([^']+)'", menu)
        positions = []
        for label in labels:
            escaped = label.replace('&', '&amp;').replace('<', '&lt;').replace('"', '&quot;')
            self.assertIn(f'data-nav-label="{escaped}"', PREVIEW, f'menu label not in the preview sidebar: {label}')
        for section in dict.fromkeys(sections):
            self.assertIn(f'<div class="nav-section-label">{section}</div>', PREVIEW)
            positions.append(PREVIEW.index(f'<div class="nav-section-label">{section}</div>'))
        self.assertEqual(positions, sorted(positions), 'menu sections are out of order')
        self.assertIn('nav-link-item active', PREVIEW)  # Dashboard → Overview is the current page

    def test_preview_is_current_with_its_sources(self):
        """Fails when config/pms.php, the layout or the content changed without a rebuild."""
        build = ROOT / 'deploy/dev/build-dashboard-preview.mjs'
        result = subprocess.run(
            ['node', str(build), '--check'], cwd=ROOT, capture_output=True, text=True, timeout=120
        )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_dashboard_styles_support_responsive_dark_mode_and_reduced_motion(self):
        for fragment in (
            '.dash-exec-kpi-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr))',
            '.dash-management-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr))',
            'body.dark .dash-management-icon', 'body.dark .dash-exec-kpi.tone-blue',
            'body.dark .dash-mix-card', '.dash-mix-donut',
            '@media(max-width:860px){.dash-mix-grid{grid-template-columns:1fr}',
            '@media(max-width:640px)', '.dash-management-grid{grid-template-columns:1fr}',
            '@media(prefers-reduced-motion:reduce)',
        ):
            with self.subTest(fragment=fragment):
                self.assertIn(fragment, CSS)

    def test_dark_palette_meets_small_text_contrast(self):
        match = re.search(r"body\.dark\s*\{([^}]*)\}", APP_CSS)
        self.assertIsNotNone(match, 'dark theme color tokens should be present')
        tokens = dict(re.findall(r"(--[\w-]+):\s*(#[0-9a-fA-F]{6})", match.group(1)))

        def luminance(color):
            channels = [int(color[index:index + 2], 16) / 255 for index in (1, 3, 5)]
            linear = [channel / 12.92 if channel <= .04045 else ((channel + .055) / 1.055) ** 2.4 for channel in channels]
            return .2126 * linear[0] + .7152 * linear[1] + .0722 * linear[2]

        def contrast(foreground, background):
            light, dark = sorted((luminance(foreground), luminance(background)), reverse=True)
            return (light + .05) / (dark + .05)

        for label, foreground, background in (
            ('body text on page background', '--c-text', '--c-bg'),
            ('secondary text on card surface', '--c-text-2', '--c-surface'),
            ('muted text on page background', '--c-muted', '--c-bg'),
        ):
            with self.subTest(pair=label):
                self.assertIn(foreground, tokens)
                self.assertIn(background, tokens)
                self.assertGreaterEqual(contrast(tokens[foreground], tokens[background]), 4.5)


if __name__ == '__main__':
    unittest.main(verbosity=2)
