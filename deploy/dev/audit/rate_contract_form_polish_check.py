#!/usr/bin/env python3
"""Regression checks for the corporate/agent rate contract create-edit form."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
VIEW = (ROOT / "resources/views/contracts/form.blade.php").read_text()
CONTROLLER = (ROOT / "app/Http/Controllers/ContractController.php").read_text()
ROUTES = (ROOT / "routes/web.php").read_text()
MODEL = (ROOT / "app/Models/RateContract.php").read_text()
RATE_SERVICE = (ROOT / "app/Services/ContractService.php").read_text()


class RateContractFormPolishTest(unittest.TestCase):
    def test_existing_create_edit_routes_and_manage_permission_boundary_remain(self):
        self.assertIn("Route::get('/create', 'create')->middleware('perm:contracts.manage')->name('create')", ROUTES)
        self.assertIn("Route::post('/', 'store')->middleware('perm:contracts.manage')->name('store')", ROUTES)
        self.assertIn("Route::get('/{contract}/edit', 'edit')->middleware('perm:contracts.manage')->name('edit')", ROUTES)
        self.assertIn("Route::put('/{contract}', 'update')->middleware('perm:contracts.manage')->name('update')", ROUTES)
        self.assertIn("$isEdit ? route('contracts.update', $contract) : route('contracts.store')", VIEW)
        self.assertIn("@if($isEdit) @method('PUT') @endif", VIEW)

    def test_the_complete_existing_contract_payload_is_still_submitted(self):
        for field in ('company_id', 'code', 'name', 'valid_from', 'valid_to', 'meal_plan', 'inclusions', 'pricing', 'discount_pct', 'lra', 'cancel_days', 'commission_pct', 'room_night_target', 'access_code', 'notes'):
            with self.subTest(field=field):
                self.assertIn(f'name="{field}"', VIEW)
        for prefix, fields in (('rates', ('room_type_id', 'season_name', 'season_from', 'season_to', 'rate', 'double_rate', 'extra_adult_rate')), ('allotments', ('room_type_id', 'date_from', 'date_to', 'rooms', 'release_days')), ('blackouts', ('date_from', 'date_to', 'reason'))):
            for field in fields:
                with self.subTest(prefix=prefix, field=field):
                    self.assertIn(f'name="{prefix}[{{{{ $i }}}}][{field}]"', VIEW)
                    self.assertIn(f'name="{prefix}[#][{field}]"', VIEW)

    def test_pricing_options_and_validation_limits_match_the_existing_contract(self):
        self.assertIn('value="net"', VIEW)
        self.assertIn('value="discount"', VIEW)
        self.assertIn("'pricing' => 'required|in:net,discount'", CONTROLLER)
        self.assertIn("'discount_pct' => ['nullable', 'numeric', 'min:0', 'max:90'", CONTROLLER)
        self.assertIn("'rates.*.rate' => 'required|numeric|min:1|max:10000000'", CONTROLLER)
        self.assertIn("'rates.*.double_rate' => 'nullable|numeric|min:1|max:10000000'", CONTROLLER)
        self.assertIn("'rates.*.extra_adult_rate' => 'nullable|numeric|min:0|max:10000000'", CONTROLLER)
        self.assertIn('Double rate applies from 2 adults', VIEW)

    def test_rate_rows_keep_season_precedence_public_rate_reference_and_input_contract(self):
        self.assertIn("$rates = old('rates'", VIEW)
        self.assertIn("$typeOpts = fn ($sel)", VIEW)
        self.assertIn("data-public=\"'", VIEW)
        self.assertIn('One all-period rate per room type', VIEW)
        self.assertIn('optional date-bounded seasonal rates take precedence', VIEW)
        self.assertIn("$lines->filter(fn ($l) => empty($l['season_from']))->count() > 1", CONTROLLER)
        self.assertIn("$seasons[$i]['season_from'] <= $seasons[$i - 1]['season_to']", CONTROLLER)

    def test_room_allotment_fields_and_server_side_inventory_limits_are_retained(self):
        self.assertIn("$allots = old('allotments'", VIEW)
        self.assertIn('Rooms / night', VIEW)
        self.assertIn('Release days', VIEW)
        self.assertIn("'allotments.*.rooms' => 'required|integer|min:1|max:500'", CONTROLLER)
        self.assertIn("'allotments.*.release_days' => 'required|integer|min:0|max:90'", CONTROLLER)
        self.assertIn("Carbon::parse($a['date_from'])->lt($vf) || Carbon::parse($a['date_to'])->gt($vt)", CONTROLLER)
        self.assertIn("if ($a['rooms'] > $inv)", CONTROLLER)

    def test_blackout_terms_last_room_availability_and_maker_checker_are_unchanged(self):
        self.assertIn("$blackouts = old('blackouts'", VIEW)
        self.assertIn("'blackouts.*.date_to' => 'required|date|after_or_equal:blackouts.*.date_from'", CONTROLLER)
        self.assertIn("'lra' => 'boolean'", CONTROLLER)
        self.assertIn("$data['lra'] = $request->boolean('lra')", CONTROLLER)
        self.assertIn("RateContract::create($data + ['status' => 'draft', 'created_by' => auth()->id()])", CONTROLLER)
        self.assertIn("if (! $contract->isEditable())", CONTROLLER)
        self.assertIn("$contract->update($data)", CONTROLLER)
        self.assertIn("return in_array($this->status, ['draft', 'suspended'])", MODEL)

    def test_error_summary_and_nested_rate_allotment_blackout_errors_are_accessible(self):
        self.assertIn('@if($errors->any())', VIEW)
        self.assertIn('role="alert"', VIEW)
        for key in ("rates.'.$i.'.room_type_id", "rates.'.$i.'.season_from", "rates.'.$i.'.rate", "rates.'.$i.'.double_rate", "rates.'.$i.'.extra_adult_rate", "allotments.'.$i.'.rooms", "allotments.'.$i.'.date_from", "blackouts.'.$i.'.date_to", "blackouts.'.$i.'.reason"):
            with self.subTest(key=key):
                self.assertIn("@error('" + key + "')", VIEW)

    def test_dynamic_rows_keep_data_names_separate_from_accessible_labels_and_default_dates(self):
        for template in ('tpl-rate', 'tpl-allot', 'tpl-black'):
            with self.subTest(template=template):
                self.assertIn(f'id="{template}"', VIEW)
        self.assertIn("replaceAll('[#]', '[' + (seq++) + ']')", VIEW)
        self.assertIn("if (k === 'allot') { row.querySelector('[name$=\"[date_from]\"]').value = vf; row.querySelector('[name$=\"[date_to]\"]').value = vt; }", VIEW)
        self.assertIn('aria-label="Room type for this rate line"', VIEW)
        self.assertIn('aria-label="Rooms per night in allotment"', VIEW)
        self.assertIn('aria-label="Reason for blackout"', VIEW)
        self.assertIn("row.querySelector('select,input')?.focus()", VIEW)

    def test_remove_controls_are_labeled_and_return_keyboard_focus_to_the_add_action(self):
        self.assertIn('aria-label="Remove rate line', VIEW)
        self.assertIn('aria-label="Remove allotment', VIEW)
        self.assertIn('aria-label="Remove blackout', VIEW)
        self.assertIn("document.getElementById(k === 'rateTable' ? 'addRate' : k === 'allotTable' ? 'addAllot' : 'addBlack')?.focus()", VIEW)

    def test_existing_child_sync_keeps_only_persisted_contract_fields(self):
        self.assertIn("$c->rates()->delete()", CONTROLLER)
        self.assertIn("$c->blackouts()->delete()", CONTROLLER)
        self.assertIn("$c->allotments()->delete()", CONTROLLER)
        self.assertIn("['room_type_id', 'season_name', 'season_from', 'season_to', 'rate', 'double_rate', 'extra_adult_rate']", CONTROLLER)
        self.assertIn("['room_type_id', 'date_from', 'date_to', 'rooms', 'release_days']", CONTROLLER)
        self.assertIn("['date_from', 'date_to', 'reason']", CONTROLLER)
        self.assertIn("'public_rate' => (float) $n['rate']", RATE_SERVICE)
        self.assertIn("'contract_nights' => $contractNights", RATE_SERVICE)

    def test_header_back_action_remains_icon_only_and_sections_are_keyboard_navigable(self):
        self.assertIn('class="btn btn-icon rcf-action"', VIEW)
        self.assertIn('aria-label="Back to Rate Contracts" title="Rate Contracts"', VIEW)
        self.assertIn('aria-label="Contract form sections"', VIEW)
        for anchor in ('#rcfAgreement', '#rcfPricing', '#rcfAllotments', '#rcfBlackouts', '#rcfTerms'):
            with self.subTest(anchor=anchor):
                self.assertIn(f'href="{anchor}"', VIEW)
        self.assertIn('tabindex="0"', VIEW)

    def test_pricing_tables_and_discount_mode_are_named_and_explained(self):
        self.assertIn('aria-label="Scrollable contract rate lines"', VIEW)
        self.assertIn('aria-label="Scrollable contract room allotments"', VIEW)
        self.assertIn('aria-label="Scrollable contract blackout periods"', VIEW)
        self.assertIn('Discount off public rate', VIEW)
        self.assertIn('Discount off public rate (%)', VIEW)
        self.assertIn("document.getElementById('rateBox').hidden = !net", VIEW)
        self.assertIn("if (net && !document.querySelector('#rateTable tbody tr'))", VIEW)

    def test_form_is_responsive_dark_mode_aware_and_respects_reduced_motion(self):
        for token in ('@media(max-width:1000px)', '@media(max-width:640px)', 'body.dark .rcf-hero', 'prefers-reduced-motion:reduce'):
            with self.subTest(token=token):
                self.assertIn(token, VIEW)
        self.assertIn('rcf-sr-only', VIEW)
        self.assertIn('Contract pricing', VIEW)
        self.assertIn('Approval before activation', VIEW)


if __name__ == "__main__":
    unittest.main(verbosity=2)
