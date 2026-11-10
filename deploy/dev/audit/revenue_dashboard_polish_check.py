#!/usr/bin/env python3
"""Regression checks for the live Revenue Dashboard and forecast/pickup display."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
VIEW = (ROOT / "resources/views/revenue/index.blade.php").read_text()
CONTROLLER = (ROOT / "app/Http/Controllers/RevenueController.php").read_text()
SERVICE = (ROOT / "app/Services/RevenueService.php").read_text()
ROUTES = (ROOT / "routes/web.php").read_text()


class RevenueDashboardPolishTest(unittest.TestCase):
    def test_existing_revenue_dashboard_and_read_manage_routes_are_unchanged(self):
        self.assertIn("Route::get('/', 'index')->name('index')", ROUTES)
        self.assertIn("Route::middleware('perm:revenue.view')->group(function ()", ROUTES)
        self.assertIn("Route::middleware('perm:revenue.manage')->group(function ()", ROUTES)
        self.assertIn("return view('revenue.index'", CONTROLLER)
        self.assertIn("Route::post('/snapshot', 'snapshot')->name('snapshot')", ROUTES)

    def test_date_window_selector_matches_controller_validation(self):
        self.assertIn('@foreach([14,30,60,90] as $d)', VIEW)
        self.assertIn('name="days"', VIEW)
        self.assertIn("'days' => 'nullable|integer|in:14,30,60,90'", CONTROLLER)
        self.assertIn('aria-label="Forecast window"', VIEW)
        self.assertIn('data-autosubmit', VIEW)

    def test_mode_status_and_notices_are_truthful_and_keep_original_links(self):
        self.assertIn("RevenueService::MODES[$mode]", VIEW)
        self.assertIn("$mode === 'recommend' && $pending", VIEW)
        self.assertIn("$mode === 'off'", VIEW)
        self.assertIn("$mode === 'auto'", VIEW)
        self.assertIn("route('revenue.pricing')", VIEW)
        self.assertIn("route('revenue.rules')", VIEW)
        self.assertIn("can_do('revenue.manage')", VIEW)

    def test_headline_cards_use_existing_seven_and_thirty_day_kpis_and_pickup_data(self):
        for item in ("$kpi['d7']", "$kpi['d30']", "$kpi['pickup']", "Occupancy on the books", "Room revenue on the books", "Pickup · bookings made in the last 7 days"):
            with self.subTest(item=item):
                self.assertIn(item, VIEW)
        self.assertIn("'d7' => $agg(array_slice($rows, 0, 7))", SERVICE)
        self.assertIn("'d30' => $agg(array_slice($rows, 0, 30))", SERVICE)

    def test_chart_scales_each_night_to_its_own_sellable_capacity(self):
        self.assertIn("$y = fn ($rooms, $dayCap) =>", VIEW)
        self.assertIn("$y($r['rooms'], $r['cap'])", VIEW)
        self.assertIn("$y($r['rooms'] + $r['held'], $r['cap'])", VIEW)
        self.assertIn("$y(max($r['fc_rooms'], $r['rooms'] + $r['held']), $r['cap'])", VIEW)
        self.assertIn("'cap' => $capDay", SERVICE)
        self.assertIn('100% of date capacity', VIEW)
        self.assertIn("role=\"img\" aria-labelledby=\"rvdSvgTitle rvdSvgDesc\"", VIEW)

    def test_chart_distinguishes_booked_rooms_holds_and_forecast_with_date_tooltips(self):
        for item in ('class="b-otb"', 'class="b-held"', 'class="b-fc"', "'rooms' => $o['rooms']", "'held' => $o['held']", "'fc_rooms' => $fc", "format('D d M')", "'adr' => round($adr, 2)"):
            with self.subTest(item=item):
                self.assertIn(item, VIEW if item.startswith('class=') or item.startswith('format(') else SERVICE)
        self.assertIn('role="region" aria-label="Scrollable demand outlook chart" tabindex="0"', VIEW)
        self.assertIn('Bars show booked rooms and holds as a share of each night’s sellable capacity', VIEW)

    def test_booking_source_summary_retains_existing_seven_day_non_cancelled_query(self):
        self.assertIn("where('created_at', '>=', $since)", CONTROLLER)
        self.assertIn("where('status', '!=', 'cancelled')", CONTROLLER)
        self.assertIn("->groupBy(fn ($r) => $r->source?->name ?? 'Unspecified')", CONTROLLER)
        self.assertIn('Pickup by booking source', VIEW)
        self.assertIn('aria-hidden="true"', VIEW)
        self.assertIn('No non-cancelled bookings were created in the last 7 days.', VIEW)

    def test_missing_snapshot_pickup_remains_blank_in_rows_and_totals(self):
        self.assertIn("'pickup1' => $snap1 !== null", SERVICE)
        self.assertIn("'pickup7' => $snap7 !== null", SERVICE)
        self.assertIn("$pk = fn ($v) => $v === null ?", VIEW)
        self.assertIn("$pickup1Values = array_filter(array_column($rows, 'pickup1'), fn ($value) => $value !== null)", VIEW)
        self.assertIn("$pickup7Values = array_filter(array_column($rows, 'pickup7'), fn ($value) => $value !== null)", VIEW)
        # the view hoists the counts into $pk1/$pk7 so the em-dash total and the "partial window" asterisk share one number
        self.assertIn("$pk1 = count($pickup1Values); $pk7 = count($pickup7Values);", VIEW)
        self.assertIn("$pk1 ? array_sum($pickup1Values) : '—'", VIEW)
        self.assertIn("$pk7 ? array_sum($pickup7Values) : '—'", VIEW)

    def test_nightly_table_keeps_forecast_columns_and_date_specific_pricing_action(self):
        for heading in ('Booked', 'Held', 'Occ %', 'ADR', 'Revenue', 'Pickup 1d', 'Pickup 7d', 'Forecast', 'Fcst revenue'):
            with self.subTest(heading=heading):
                self.assertIn(f'>{heading}<', VIEW)
        self.assertIn("route('revenue.pricing', ['from' => $r['date'], 'days' => 7])", VIEW)
        self.assertIn('aria-label="Scrollable nightly forecast and pickup table"', VIEW)
        self.assertIn('rvd-sr-only">Actions', VIEW)
        self.assertIn('datetime="{{ $r[\'date\'] }}"', VIEW)

    def test_pickup_snapshot_rebuild_stays_manage_gated_and_confirmed(self):
        self.assertIn("@if(can_do('revenue.manage'))<form method=\"post\" action=\"{{ route('revenue.snapshot') }}\"", VIEW)
        self.assertIn("confirm('Rebuild the last 14 days of on-the-books history from reservation records?')", VIEW)
        self.assertIn("Route::post('/snapshot', 'snapshot')->name('snapshot')", ROUTES)
        self.assertIn("Route::post('/snapshot', 'snapshot')->name('snapshot')", ROUTES)

    def test_dashboard_has_responsive_dark_mode_and_reduced_motion_styles(self):
        for token in ('@media(max-width:1050px)', '@media(max-width:650px)', '@media(max-width:390px)', 'body.dark .rvd-hero', 'prefers-reduced-motion:reduce'):
            with self.subTest(token=token):
                self.assertIn(token, VIEW)
        self.assertIn('class="rvd-sr-only"', VIEW)
        self.assertIn('aria-label="Revenue performance summary"', VIEW)
        self.assertIn('RevenueService::forecast', CONTROLLER)
        self.assertIn('RevenueService::kpis($all)', CONTROLLER)


if __name__ == "__main__":
    unittest.main(verbosity=2)
