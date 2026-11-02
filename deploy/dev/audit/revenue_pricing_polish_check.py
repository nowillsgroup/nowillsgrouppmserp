#!/usr/bin/env python3
"""Regression checks for the live Revenue Pricing Grid and publish contract."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
VIEW = (ROOT / "resources/views/revenue/pricing.blade.php").read_text()
CONTROLLER = (ROOT / "app/Http/Controllers/RevenueController.php").read_text()
SERVICE = (ROOT / "app/Services/RevenueService.php").read_text()
ROUTES = (ROOT / "routes/web.php").read_text()


class RevenuePricingPolishTest(unittest.TestCase):
    def test_actual_revenue_pricing_routes_and_permission_gates_are_retained(self):
        self.assertIn("Route::prefix('revenue')->name('revenue.')", ROUTES)
        self.assertIn("Route::get('/pricing', 'pricing')->name('pricing')", ROUTES)
        self.assertIn("Route::middleware('perm:revenue.view')", ROUTES)
        self.assertIn("Route::middleware('perm:revenue.manage')", ROUTES)
        self.assertIn("Route::post('/pricing/publish', 'publish')->name('publish')", ROUTES)
        self.assertIn("Route::post('/pricing/reset', 'reset')->name('reset')", ROUTES)
        self.assertIn("$editable = $canManage && $mode !== 'auto'", VIEW)
        self.assertIn("route('revenue.publish')", VIEW)
        self.assertIn("route('revenue.reset')", VIEW)

    def test_date_controls_preserve_valid_windows_and_return_to_rate_calendar(self):
        self.assertIn('name="from"', VIEW)
        self.assertIn('name="days"', VIEW)
        self.assertIn('@foreach([7,14,21,28] as $d)', VIEW)
        self.assertIn("'days' => 'nullable|integer|in:7,14,21,28'", CONTROLLER)
        self.assertIn('aria-label="Previous date window"', VIEW)
        self.assertIn('aria-label="Next date window"', VIEW)
        self.assertIn('aria-label="Jump to today"', VIEW)
        self.assertIn("route('rates.index'", VIEW)

    def test_selected_and_accept_all_publish_contracts_and_names_remain_intact(self):
        self.assertIn('name="from" value="{{ $from->toDateString() }}"', VIEW)
        self.assertIn('name="days" value="{{ $days }}"', VIEW)
        self.assertIn('name="rate[{{ $t->id }}][{{ $date }}]"', VIEW)
        self.assertIn('name="pick[]" value="{{ $pickKey }}"', VIEW)
        self.assertIn('name="accept_all" value="1"', VIEW)
        self.assertIn("'accept_all' => 'nullable|boolean'", CONTROLLER)
        self.assertIn("'pick.*' => ['string', 'regex:/^\\d+\\|\\d{4}-\\d{2}-\\d{2}$/']", CONTROLLER)
        self.assertIn("RevenueService::publish($cells)", CONTROLLER)
        self.assertIn("$cells[(int) $typeId][$date] = (float) $rate", CONTROLLER)

    def test_publish_controls_only_render_when_manageable_and_do_not_offer_auto_publish(self):
        self.assertIn('@if($editable)', VIEW)
        self.assertIn('@if($pending)', VIEW)
        self.assertIn("$mode !== 'auto'", VIEW)
        self.assertIn('Auto-pilot is on.</b> Rates are applied live', VIEW)
        self.assertIn('This grid is read-only; nothing needs publishing.', VIEW)
        self.assertIn('You can review live rates and recommendations but cannot publish changes.', VIEW)
        self.assertIn("self::mode() === 'auto'", SERVICE)
        self.assertIn("Switch to Recommend to publish manually.", SERVICE)

    def test_fixed_prices_and_stop_sells_remain_visible_and_protected(self):
        self.assertIn("'fixed' => ! empty($b['fixed'])", SERVICE)
        self.assertIn("'stop' => $cur['stop']", SERVICE)
        self.assertIn("! empty($base[$date]['fixed'])", SERVICE)
        self.assertIn('Fixed-price dates remain protected.', VIEW)
        self.assertIn("$c['fixed']", VIEW)
        self.assertIn("$c['stop']", VIEW)
        self.assertIn('Fixed / protected', VIEW)
        self.assertIn('Stop-sell', VIEW)

    def test_grid_is_accessible_keyboard_scrollable_and_describes_nightly_rates(self):
        self.assertIn('role="region" aria-label="Scrollable {{ $days }}-day rate recommendation grid" tabindex="0"', VIEW)
        self.assertIn('<th scope="col" class="rmp-room-head">', VIEW)
        self.assertIn('<th scope="row" class="rmp-room-cell">', VIEW)
        self.assertIn('aria-label="{{ $t->name }} recommended rate for {{ $dateLabel }}"', VIEW)
        self.assertIn('aria-label="Select {{ $t->name }} on {{ $dateLabel }}"', VIEW)
        self.assertIn('aria-label="{{ $o[\'hotel\'] }} percent occupancy', VIEW)
        self.assertIn('Prices per room / night', VIEW)
        self.assertIn('aria-describedby="rmpLegend"', VIEW)

    def test_recommendation_explanations_and_window_metrics_use_real_data(self):
        self.assertIn("$gridCells = collect($grid)->flatMap", VIEW)
        self.assertIn("$occupancyValues = collect($occ)->pluck('hotel')", VIEW)
        self.assertIn("$gridCells->where('fixed', true)->count()", VIEW)
        self.assertIn("$gridCells->where('stop', true)->count()", VIEW)
        self.assertIn("money($rec['rate'])", VIEW)
        self.assertIn("money($c['current'])", VIEW)
        self.assertIn('Matching occupancy, lead-time, weekday, and date rules apply in priority order.', VIEW)
        self.assertIn('rounded to the nearest', VIEW.lower())

    def test_manager_selection_controls_track_manual_edits_and_selected_count(self):
        self.assertIn('id="pickCount"', VIEW)
        self.assertIn('id="selChanges"', VIEW)
        self.assertIn('id="selNone"', VIEW)
        self.assertIn('id="pubBtn" disabled', VIEW)
        self.assertIn("box.dataset.change === '1'", VIEW)
        self.assertIn("publish.disabled = selected === 0", VIEW)
        self.assertIn("checkbox.checked = true", VIEW)
        self.assertIn("if (checkbox) checkbox.checked = true;", VIEW)

    def test_empty_inventory_state_and_dark_responsive_modes_are_present(self):
        self.assertIn("$types->isEmpty()", VIEW)
        self.assertIn('No active room types', VIEW)
        for token in ('@media(max-width:1050px)', '@media(max-width:760px)', '@media(max-width:520px)', 'body.dark .rmp-hero', 'body.dark .rmp-cell.up', '@media(prefers-reduced-motion:reduce)'):
            with self.subTest(token=token):
                self.assertIn(token, VIEW)

    def test_reset_remains_scoped_and_explicit_about_preserved_seasonal_rates(self):
        self.assertIn('@if($canManage)', VIEW)
        self.assertIn('name="date_from"', VIEW)
        self.assertIn('name="date_to"', VIEW)
        self.assertIn('name="room_type_id"', VIEW)
        self.assertIn("Route::post('/pricing/reset', 'reset')->name('reset')", ROUTES)
        self.assertIn('Seasonal and fixed rates from the Rate Calendar are not removed.', VIEW)
        self.assertIn("'room_type_id' => 'nullable|integer|owned:room_types'", CONTROLLER)


if __name__ == "__main__":
    unittest.main(verbosity=2)
