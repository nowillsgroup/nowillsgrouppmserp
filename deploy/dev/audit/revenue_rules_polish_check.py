#!/usr/bin/env python3
"""Regression checks for live Revenue Rules, settings, guard-rails, and rule forms."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
RULES = (ROOT / "resources/views/revenue/rules.blade.php").read_text()
FORM = (ROOT / "resources/views/revenue/rule-form.blade.php").read_text()
CONTROLLER = (ROOT / "app/Http/Controllers/RevenueController.php").read_text()
MODEL = (ROOT / "app/Models/YieldRule.php").read_text()
ROUTES = (ROOT / "routes/web.php").read_text()


class RevenueRulesPolishTest(unittest.TestCase):
    def test_real_routes_and_existing_view_manage_permission_split_are_preserved(self):
        self.assertIn("Route::get('/rules', 'rules')->name('rules')", ROUTES)
        self.assertIn("Route::middleware('perm:revenue.view')->group(function ()", ROUTES)
        self.assertIn("Route::middleware('perm:revenue.manage')->group(function ()", ROUTES)
        for route in ("Route::post('/settings', 'settings')->name('settings')", "Route::post('/limits', 'limits')->name('limits')", "Route::post('/rules', 'storeRule')->name('rules.store')", "Route::put('/rules/{yrule}', 'updateRule')->name('rules.update')", "Route::post('/rules/{yrule}/toggle', 'toggleRule')->name('rules.toggle')", "Route::delete('/rules/{yrule}', 'destroyRule')->name('rules.destroy')"):
            with self.subTest(route=route):
                self.assertIn(route, ROUTES)
        self.assertIn("$canManage = can_do('revenue.manage')", RULES)
        self.assertIn('@unless($canManage)', RULES)
        self.assertIn('@if($canManage)', RULES)

    def test_pricing_mode_rounding_horizon_and_limit_fields_keep_their_contract(self):
        for name in ('rm_mode', 'rm_round_to', 'rm_horizon_days', 'min_rate[{{ $t->id }}]', 'max_rate[{{ $t->id }}]'):
            with self.subTest(name=name):
                self.assertIn(f'name="{name}"', RULES)
        self.assertIn("'rm_mode' => ['required', Rule::in(array_keys(RevenueService::MODES))]", CONTROLLER)
        self.assertIn("'rm_round_to' => 'required|numeric|min:0|max:10000'", CONTROLLER)
        self.assertIn("'rm_horizon_days' => 'required|integer|min:14|max:365'", CONTROLLER)
        self.assertIn("'min_rate.*' => 'nullable|numeric|min:0|max:99999999'", CONTROLLER)
        self.assertIn("'max_rate.*' => 'nullable|numeric|min:0|max:99999999'", CONTROLLER)
        self.assertIn("$max < (float) $min", CONTROLLER)

    def test_mode_descriptions_and_management_states_are_truthful_and_accessible(self):
        self.assertIn("'off' => ['Forecast only'", RULES)
        self.assertIn("'recommend' => ['Review and publish'", RULES)
        self.assertIn("'auto' => ['Auto-pilot · live'", RULES)
        self.assertIn('aria-describedby="rvrMode-{{ $k }}"', RULES)
        self.assertIn('Auto-pilot takes effect immediately when saved.', RULES)
        self.assertIn('You can review the current mode and guard-rails', RULES)
        self.assertIn('Rate limits are view only', RULES)
        self.assertIn("RevenueService::MODES[$mode]", RULES)

    def test_rate_limit_table_has_room_specific_labels_and_per_field_errors(self):
        self.assertIn('aria-label="Rate limits by room type"', RULES)
        self.assertIn('aria-label="Minimum rate floor for {{ $t->name }}"', RULES)
        self.assertIn('aria-label="Maximum rate ceiling for {{ $t->name }}"', RULES)
        self.assertIn("@error('min_rate.'.$t->id)", RULES)
        self.assertIn("@error('max_rate.'.$t->id)", RULES)
        self.assertIn("$t->weekend_rate !== null ? money($t->weekend_rate)", RULES)
        self.assertIn('Leave either field blank to remove that limit.', RULES)

    def test_rule_register_keeps_real_data_and_permission_gated_manage_actions(self):
        self.assertIn("$r->conditionLabel()", RULES)
        self.assertIn("$r->adjustmentLabel()", RULES)
        self.assertIn("route('revenue.rules.toggle', $r)", RULES)
        self.assertIn("route('revenue.rules.edit', $r)", RULES)
        self.assertIn("route('revenue.rules.destroy', $r)", RULES)
        self.assertIn('aria-label="Edit {{ $r->name }}"', RULES)
        self.assertIn('aria-label="Delete {{ $r->name }}"', RULES)
        self.assertIn('data-confirm="Delete the ‘{{ $r->name }}’ pricing rule?', RULES)
        self.assertIn('Priority', RULES)
        self.assertIn('Conditions', RULES)

    def test_rule_create_and_edit_use_original_save_contract_and_fields(self):
        self.assertIn("$isEdit ? route('revenue.rules.update', $rule) : route('revenue.rules.store')", FORM)
        self.assertIn('@method(\'PUT\')', FORM)
        for field in ('name', 'room_type_id', 'priority', 'adjust_type', 'adjust_value', 'basis', 'occ_min', 'occ_max', 'lead_min', 'lead_max', 'min_stay', 'days[]', 'date_from', 'date_to', 'is_active'):
            with self.subTest(field=field):
                self.assertIn(f'name="{field}"', FORM)
        self.assertIn("'basis' => 'required|in:hotel,type'", CONTROLLER)
        self.assertIn("'adjust_type' => 'required|in:percent,amount'", CONTROLLER)
        self.assertIn("'adjust_value' => ['required', 'numeric', 'not_in:0'", CONTROLLER)
        self.assertIn("'days.*' => 'integer|between:1,7'", CONTROLLER)

    def test_rule_conditions_explain_range_logic_and_weekday_presets_only_change_checkboxes(self):
        self.assertIn('Every condition you set must match', FORM)
        self.assertIn('No days selected means every night.', FORM)
        self.assertIn('id="rvrfAllDays"', FORM)
        self.assertIn('id="rvrfClearDays"', FORM)
        self.assertIn("day.checked = true", FORM)
        self.assertIn("day.checked = false", FORM)
        self.assertIn("$d['occ_max'] < $d['occ_min']", CONTROLLER)
        self.assertIn("$d['lead_max'] < $d['lead_min']", CONTROLLER)
        self.assertIn('ISO weekdays, Monday through Sunday.', FORM)

    def test_adjustment_form_keeps_error_context_and_describes_math(self):
        self.assertIn('Rate adjustment', FORM)
        self.assertIn('Positive raises the current price; negative lowers it.', FORM)
        self.assertIn('Percent changes are limited to −90% through +300%, fixed amounts to ±9,999,999.', FORM)
        self.assertIn("@error('adjust_type')", FORM)
        self.assertIn("@error('adjust_value')", FORM)
        self.assertIn('Matching rules stack in priority order.', FORM)
        self.assertIn('Fixed-price dates are not adjusted.', FORM)

    def test_rules_register_and_form_include_mobile_dark_styles_and_no_new_backend_logic(self):
        for token in ('@media(max-width:1000px)', '@media(max-width:760px)', '@media(max-width:520px)', 'body.dark .rvr-hero'):
            with self.subTest(token=token):
                self.assertIn(token, RULES)
        for token in ('@media(max-width:900px)', '@media(max-width:580px)', 'body.dark .rvrf-hero', 'prefers-reduced-motion:reduce'):
            with self.subTest(token=token):
                self.assertIn(token, FORM)
        self.assertIn('route(\'revenue.rules\')', FORM)
        self.assertIn("route('revenue.pricing')", FORM)
        self.assertIn('class YieldRule extends Model', MODEL)
        self.assertIn('Matching rules apply cumulatively in priority order.', MODEL)

    def test_statistics_reflect_real_rule_and_room_type_collections(self):
        self.assertIn("$activeCount = $rules->where('is_active', true)->count()", RULES)
        self.assertIn("$pausedCount = $rules->count() - $activeCount", RULES)
        self.assertIn("$types->filter(fn ($type) => $type->min_rate !== null || $type->max_rate !== null)->count()", RULES)
        self.assertIn('aria-label="Pricing rules summary"', RULES)
        self.assertIn('aria-label="Revenue management yield rules"', RULES)


if __name__ == "__main__":
    unittest.main(verbosity=2)
