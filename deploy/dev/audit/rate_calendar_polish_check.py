#!/usr/bin/env python3
"""Regression checks for the live Rate Calendar UI and existing rate-rule contract."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
VIEW = (ROOT / "resources/views/rates/index.blade.php").read_text()
CONTROLLER = (ROOT / "app/Http/Controllers/RateController.php").read_text()
SERVICE = (ROOT / "app/Services/RateService.php").read_text()
ROUTES = (ROOT / "routes/web.php").read_text()
LAYOUT = (ROOT / "resources/views/layouts/app.blade.php").read_text()


class RateCalendarPolishTest(unittest.TestCase):
    def test_actual_rate_calendar_routes_and_permissions_are_preserved(self):
        self.assertIn("Route::prefix('rates')->name('rates.')->middleware('perm:reservations.rates')", ROUTES)
        self.assertIn("Route::get('/', 'index')->name('index')", ROUTES)
        self.assertIn("Route::post('/', 'store')->name('store')", ROUTES)
        self.assertIn("Route::delete('/{override}', 'destroy')->name('destroy')", ROUTES)
        self.assertIn("route('rates.index'", VIEW)
        self.assertIn("route('rates.store')", VIEW)
        self.assertIn("route('rates.destroy', $o)", VIEW)

    def test_existing_date_range_and_bulk_rule_fields_are_retained(self):
        for value in ('name="from"', 'name="days"', 'name="name"', 'name="room_type_ids[]"', 'name="date_from"', 'name="date_to"', 'name="rate"', 'name="weekend_rate"', 'name="min_stay"', 'name="stop_sell"', 'name="is_fixed"'):
            with self.subTest(value=value):
                self.assertIn(value, VIEW)
        self.assertIn("'from' => 'nullable|date'", CONTROLLER)
        self.assertIn("'days' => 'nullable|integer|in:14,21,30,45,60'", CONTROLLER)
        self.assertIn("'room_type_ids' => 'required|array|min:1'", CONTROLLER)
        self.assertIn("'stop_sell' => 'nullable|boolean', 'is_fixed' => 'nullable|boolean'", CONTROLLER)
        self.assertIn("route('rates.index', ['from' => $data['date_from']])", CONTROLLER)
        self.assertIn("@method('DELETE')", VIEW)

    def test_daily_cells_are_keyboard_accessible_and_prefill_one_day_rule(self):
        self.assertIn('role="region" aria-label="Scrollable {{ $days }}-day rate calendar', VIEW)
        self.assertIn('tabindex="0"', VIEW)
        self.assertIn('<th scope="col" class="rc-room-head">', VIEW)
        self.assertIn('<th scope="row" class="rc-room-cell">', VIEW)
        self.assertIn('class="rc-cell" data-modal="rateModal"', VIEW)
        self.assertIn('data-fdate_from="{{ $date }}" data-fdate_to="{{ $date }}"', VIEW)
        self.assertIn('aria-haspopup="dialog"', VIEW)
        self.assertIn("box.value === trigger.dataset.type", VIEW)
        self.assertIn("route('rates.store')", VIEW)

    def test_live_rate_status_and_existing_pricing_semantics_are_explained(self):
        for token in ('rc-above', 'rc-below', 'rc-stop', 'rc-neutral', "$c['fixed']", "$c['stop']", "$c['min_stay']", "$c['source']", "$c['season']"):
            with self.subTest(token=token):
                self.assertIn(token, VIEW)
        self.assertIn("'weekend' => $weekend", SERVICE)
        self.assertIn("'fixed' => $covering->contains('is_fixed', true)", SERVICE)
        self.assertIn("'stop' => $covering->contains('stop_sell', true)", SERVICE)
        self.assertIn("'min_stay' => (int) max(1, $covering->max('min_stay') ?? 1)", SERVICE)
        self.assertIn('Revenue management is <b>', VIEW)
        self.assertIn('Fixed price · protect these dates from revenue management', VIEW)

    def test_seasonal_rules_are_still_created_removed_and_listed(self):
        self.assertIn('RateOverride::create([', CONTROLLER)
        self.assertIn('ActivityLogger::log(\'rates\', \'create\'', CONTROLLER)
        self.assertIn("$override->delete();", CONTROLLER)
        self.assertIn('active / upcoming', VIEW)
        self.assertIn('Newest rule wins where prices overlap', VIEW)
        self.assertIn("$o->weekend_rate !== null ? money($o->weekend_rate)", VIEW)
        self.assertIn('To date (inclusive)', VIEW)

    def test_rate_calendar_has_responsive_dark_mode_and_clear_visual_legend(self):
        for token in ('linear-gradient(118deg,#103a23,#176239 68%,#247849)', '@media(max-width:980px)', '@media(max-width:760px)', '@media(max-width:520px)', 'body.dark .rc-hero', 'body.dark .rc-calendar td.rc-stop', 'id="rcLegend"', 'Above base rate', 'Below base rate', 'Stop-sell'):
            with self.subTest(token=token):
                self.assertIn(token, VIEW)

    def test_modal_preserves_validation_and_supports_scoped_room_type_selection(self):
        self.assertIn('id="rateModal" role="dialog" aria-modal="true"', VIEW)
        self.assertIn("@if($errors->any()) document.getElementById('rateModal')?.classList.add('show'); @endif", VIEW)
        self.assertIn("document.getElementById('rcSelectAll')?.addEventListener", VIEW)
        self.assertIn("@error('room_type_ids')", VIEW)
        self.assertIn("$errors->first('room_type_ids.*')", VIEW)
        self.assertIn('aria-label="Close rate form"', VIEW)

    def test_calendar_summary_is_informational_and_empty_inventory_is_handled(self):
        self.assertIn("$calendarCells = collect($grid)->flatMap", VIEW)
        self.assertIn("$visibleRates->min()", VIEW)
        self.assertIn("$calendarCells->where('stop', true)->count()", VIEW)
        self.assertIn("$types->isEmpty()", VIEW)
        self.assertIn('No active room types', VIEW)
        self.assertIn('Final booking availability is still checked by the PMS.', VIEW)

    def test_modal_restarts_clean_for_bulk_and_applies_clicked_room_after_shared_reset(self):
        self.assertIn('action="{{ route(\'rates.store\') }}" data-reset', VIEW)
        self.assertIn("document.addEventListener('click', event =>", VIEW)
        self.assertIn("trigger.classList.contains('rc-cell')", VIEW)
        self.assertIn("box.value === trigger.dataset.type", VIEW)
        self.assertIn("['date_from', 'date_to', 'rate', 'weekend_rate']", VIEW)
        self.assertIn("box.checked = false", VIEW)
        self.assertLess(LAYOUT.index("asset('js/app.js')"), LAYOUT.index("@stack('scripts')"))


if __name__ == "__main__":
    unittest.main(verbosity=2)
