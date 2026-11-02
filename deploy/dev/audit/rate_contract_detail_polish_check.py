#!/usr/bin/env python3
"""Regression checks for the corporate/agent Rate Contract detail and approval page."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
VIEW = (ROOT / "resources/views/contracts/show.blade.php").read_text()
CONTROLLER = (ROOT / "app/Http/Controllers/ContractController.php").read_text()
ROUTES = (ROOT / "routes/web.php").read_text()
MODEL = (ROOT / "app/Models/RateContract.php").read_text()
APP_JS = (ROOT / "public/js/app.js").read_text()


class RateContractDetailPolishTest(unittest.TestCase):
    def test_existing_detail_and_approval_routes_keep_their_permission_boundaries(self):
        self.assertIn("Route::get('/{contract}', 'show')->middleware('perm:contracts.view')->name('show')", ROUTES)
        self.assertIn("Route::get('/{contract}/letter', 'letter')->middleware('perm:contracts.view')->name('letter')", ROUTES)
        self.assertIn("Route::post('/{contract}/activate', 'activate')->middleware('perm:contracts.approve')->name('activate')", ROUTES)
        self.assertIn("Route::post('/{contract}/suspend', 'suspend')->middleware('perm:contracts.approve')->name('suspend')", ROUTES)
        self.assertIn("Route::post('/{contract}/renew', 'renew')->middleware('perm:contracts.manage')->name('renew')", ROUTES)
        self.assertIn("Route::delete('/{contract}', 'destroy')->middleware('perm:contracts.manage')->name('destroy')", ROUTES)

    def test_detail_controller_keeps_the_same_contract_production_rate_and_allotment_data(self):
        for item in ('ContractService::production($contract)', 'ContractService::allotmentGrid($contract, $from, 30)', 'ContractService::allotmentUsage($a)', "'bookings' => Reservation::with('guest', 'rooms.roomType')", "'publicRates' => RoomType::pluck('base_rate', 'id')"):
            with self.subTest(item=item):
                self.assertIn(item, CONTROLLER)
        self.assertIn("return view('contracts.show'", CONTROLLER)
        self.assertIn("'renewals' => RateContract::where('renewed_from_id', $contract->id)", CONTROLLER)

    def test_approval_suspend_renew_and_draft_delete_actions_remain_permission_and_state_gated(self):
        for condition in ("can_do('contracts.approve') && $c->status === 'active'", "can_do('contracts.approve') && in_array($c->status, ['draft', 'suspended']) && $st !== 'expired'", "can_do('contracts.manage') && in_array($st, ['active', 'expired', 'upcoming'])", "can_do('contracts.manage') && $c->status === 'draft' && $prod['bookings'] === 0"):
            with self.subTest(condition=condition):
                self.assertIn(condition, VIEW)
        self.assertIn("$c->isEditable()", VIEW)
        self.assertIn("@method('DELETE')", VIEW)
        self.assertIn('data-confirm="Approve and activate', VIEW)
        self.assertIn('data-confirm="Delete draft', VIEW)

    def test_header_actions_remain_icon_only_with_accessible_names_and_tooltips(self):
        for item in ('aria-label="View agreement letter for {{ $c->code }}" title="Agreement letter"', 'aria-label="Edit contract {{ $c->code }}" title="Edit contract"', 'aria-label="Draft renewal for {{ $c->code }}" title="Renew contract"', 'aria-label="Suspend contract {{ $c->code }}" title="Suspend contract"', 'aria-label="Approve and activate {{ $c->code }}" title="Approve and activate"', 'aria-label="Delete draft contract {{ $c->code }}"'):
            with self.subTest(item=item):
                self.assertIn(item, VIEW)
        self.assertIn('btn-icon cshow-action', VIEW)

    def test_status_messages_distinguish_draft_suspended_and_expiring_contracts(self):
        self.assertIn("$c->status === 'draft'", VIEW)
        self.assertIn("$c->status === 'suspended'", VIEW)
        self.assertIn("$st === 'active' && $c->daysToExpiry()", VIEW)
        self.assertIn('New bookings continue to receive public rates', VIEW)
        self.assertIn('existing bookings retain their contracted price', VIEW)
        self.assertIn("route('contracts.show', $r)", VIEW)

    def test_production_cards_continue_to_use_existing_contract_production_metrics(self):
        for key in ("$prod['bookings']", "$prod['cancelled']", "$prod['nights']", "$prod['stayed']", "$prod['target_pct']", "$prod['revenue']", "$prod['adr']"):
            with self.subTest(key=key):
                self.assertIn(key, VIEW)
        self.assertIn("label=\"Room revenue\"", VIEW)
        self.assertIn('aria-label="Contract production summary"', VIEW)

    def test_net_rate_lines_are_compared_honestly_to_base_bar_without_claiming_guaranteed_savings(self):
        self.assertIn("$c->pricing === 'net' && $c->rates->isNotEmpty()", VIEW)
        self.assertIn("$publicRates[$r->room_type_id]", VIEW)
        self.assertIn("($r->rate / $pub - 1) * 100", VIEW)
        self.assertIn('Base BAR', VIEW)
        self.assertIn('Vs base BAR', VIEW)
        self.assertIn('below base', VIEW)
        self.assertIn('above base', VIEW)
        self.assertNotIn('>Saving<', VIEW)
        self.assertIn("$c->pricing === 'discount'", VIEW)
        self.assertIn("$c->discount_pct", VIEW)

    def test_net_rates_seasons_blackouts_and_inclusive_dates_are_preserved(self):
        self.assertIn("$c->rates->sortBy(fn ($r) => [$r->roomType?->base_rate", VIEW)
        self.assertIn("$r->season_from->format('d M')", VIEW)
        self.assertIn("$r->season_to->format('d M Y')", VIEW)
        self.assertIn("$c->blackouts", VIEW)
        self.assertIn('Public rate applies', VIEW)
        self.assertIn("$b->date_from->toDateString()", VIEW)
        self.assertIn("$b->date_to->toDateString()", VIEW)

    def test_allotment_grid_and_usage_use_the_current_30_day_controller_data(self):
        self.assertIn('Allotments</a>', VIEW)
        self.assertIn('Remaining / held rooms over the next 30 nights.', VIEW)
        self.assertIn('Scroll', VIEW) if 'Scroll' in VIEW else self.assertIn('Scrollable 30-night room allotment grid', VIEW)
        self.assertIn('$grid[$a->id] ?? []', VIEW)
        self.assertIn("$cell['released']", VIEW)
        self.assertIn("$cell['left']", VIEW)
        self.assertIn("$cell['picked']", VIEW)
        self.assertIn("$cellClass = $cell['released'] ? 'released' : ($cell['left'] === 0 ? 'full'", VIEW)
        self.assertIn('Remaining</span>', VIEW)
        self.assertIn('Fully picked up</span>', VIEW)
        self.assertNotIn('style="background:{{ $bg }}"', VIEW)
        self.assertIn('body.dark .cshow-allotment td.full', VIEW)
        self.assertIn("$usage[$a->id]", VIEW)
        self.assertIn('Allotted RN', VIEW)
        self.assertIn('Released unsold', VIEW)

    def test_contract_bookings_remain_limited_to_latest_25_and_reservation_links_are_permission_gated(self):
        self.assertIn("->limit(25)->get()", CONTROLLER)
        self.assertIn('Latest 25 reservations linked to this agreement.', VIEW)
        self.assertIn("can_do('reservations.view')", VIEW)
        self.assertIn("route('reservations.show', $b)", VIEW)
        self.assertIn("$b->rooms->where('status', '!=', 'cancelled')->avg('rate')", VIEW)

    def test_contract_terms_keep_codes_escaped_company_gates_and_approval_audit_fields(self):
        self.assertIn("can_do('credit.view')", VIEW)
        self.assertIn("route('credit.show', $c->company)", VIEW)
        self.assertIn("e($c->access_code)", VIEW)
        self.assertIn('$c->creator?->name', VIEW)
        self.assertIn('$c->approver->name', VIEW)
        self.assertIn('$c->approved_at?->format', VIEW)
        self.assertIn("route('contracts.show', $c->renewedFrom)", VIEW)

    def test_modal_controls_have_dialog_names_and_keep_original_required_fields_and_renewal_limits(self):
        self.assertIn('aria-modal="true" aria-labelledby="suspendTitle"', VIEW)
        self.assertIn('aria-modal="true" aria-labelledby="renewTitle"', VIEW)
        self.assertIn('aria-label="Close suspend dialog"', VIEW)
        self.assertIn('aria-label="Close renewal dialog"', VIEW)
        self.assertIn('name="reason"', VIEW)
        self.assertIn('name="code"', VIEW)
        self.assertIn('name="uplift"', VIEW)
        self.assertIn("'reason' => 'required|string|min:5|max:200'", CONTROLLER)
        self.assertIn("'uplift' => 'required|numeric|min:-50|max:100'", CONTROLLER)

    def test_suspend_and_renew_modals_match_the_shared_open_close_escape_and_focus_behavior(self):
        self.assertIn('data-modal="suspendModal"', VIEW)
        self.assertIn('data-modal="renewModal"', VIEW)
        self.assertIn('id="suspendModal"', VIEW)
        self.assertIn('id="renewModal"', VIEW)
        self.assertIn("openModal(o.dataset.modal)", APP_JS)
        self.assertIn("m.classList.add('show')", APP_JS)
        self.assertIn("m.classList.remove('show')", APP_JS)
        self.assertIn("e.key !== 'Escape'", APP_JS)
        self.assertIn("[data-close]", APP_JS)
        self.assertIn('input:not([type=hidden]),select,textarea', APP_JS)
        self.assertIn("route('contracts.suspend', $c)", VIEW)
        self.assertIn("route('contracts.renew', $c)", VIEW)

    def test_detail_has_responsive_dark_mode_reduced_motion_and_keyboard_scroll_regions(self):
        for token in ('@media(max-width:1150px)', '@media(max-width:650px)', '@media(max-width:390px)', 'body.dark .cshow-hero', 'prefers-reduced-motion:reduce'):
            with self.subTest(token=token):
                self.assertIn(token, VIEW)
        self.assertIn('aria-label="Scrollable negotiated rate lines" tabindex="0"', VIEW)
        self.assertIn('aria-label="Scrollable contract bookings" tabindex="0"', VIEW)
        self.assertIn('aria-label="Contract detail sections"', VIEW)


if __name__ == "__main__":
    unittest.main(verbosity=2)
