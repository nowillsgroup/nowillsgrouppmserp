#!/usr/bin/env python3
"""Static regression checks for the live Group Blocks register presentation."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
VIEW = (ROOT / "resources/views/blocks/index.blade.php").read_text()
FORM = (ROOT / "resources/views/blocks/form.blade.php").read_text()
SHOW = (ROOT / "resources/views/blocks/show.blade.php").read_text()
CONTROLLER = (ROOT / "app/Http/Controllers/BlockController.php").read_text()
ROUTES = (ROOT / "routes/web.php").read_text()


class GroupBlocksPolishTest(unittest.TestCase):
    def test_status_counts_use_tenant_scoped_live_room_blocks(self):
        self.assertIn("RoomBlock::query()->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status')", CONTROLLER)
        self.assertIn("compact('blocks', 'statusCounts')", CONTROLLER)
        for status in ("tentative", "definite", "released", "cancelled"):
            with self.subTest(status=status):
                self.assertIn(f"$statusCounts['{status}']", VIEW)

    def test_status_tabs_keep_search_and_have_current_page_semantics(self):
        self.assertIn("$blockTabParams = request()->only('q');", VIEW)
        self.assertIn("$tabQuery = $blockTabParams;", VIEW)
        self.assertIn("$tabQuery['status'] = $tab['status'];", VIEW)
        self.assertIn('aria-label="Filter group blocks by status"', VIEW)
        self.assertIn('@if($tabActive) aria-current="page" @endif', VIEW)
        self.assertIn('class="gb-tab-count"', VIEW)

    def test_search_form_preserves_status_and_clear_search_keeps_the_tab(self):
        self.assertIn('role="search"', VIEW)
        self.assertIn('name="q" value="{{ request(\'q\') }}"', VIEW)
        self.assertIn('name="status" value="{{ request(\'status\') }}"', VIEW)
        self.assertIn("route('blocks.index', request()->only('status'))", VIEW)
        self.assertIn('Block number or group / event name', VIEW)

    def test_register_preserves_live_block_details_and_accessible_pickup_progress(self):
        for label in ('Block / group', 'Company / contact', 'Arrival', 'Departure', 'Cut-off', 'Room allotment', 'Pickup', 'Rate / night', 'Status'):
            with self.subTest(label=label):
                self.assertIn(f'<th scope="col">{label}</th>', VIEW)
        self.assertIn("route('blocks.show', $b)", VIEW)
        self.assertIn("$b->lines", VIEW)
        self.assertIn("$b->pickup_pct", VIEW)
        self.assertIn('role="progressbar" aria-label="Pickup for {{ $b->block_no }}" aria-valuemin="0" aria-valuemax="100"', VIEW)
        self.assertIn("status_badge($b->status)", VIEW)

    def test_cutoff_alert_explains_urgency_and_empty_states_retain_real_actions(self):
        self.assertIn("$b->cutoff_date->lte(bdate()->copy()->addDays(3))", VIEW)
        self.assertIn("'Past cut-off'", VIEW)
        self.assertIn("'Due today'", VIEW)
        self.assertIn("'Due in '.$daysToCutoff.' days'", VIEW)
        self.assertIn("route('blocks.create')", VIEW)
        self.assertIn("route('blocks.index')", VIEW)
        self.assertIn('Create first block', VIEW)
        self.assertIn('Clear filters', VIEW)

    def test_group_blocks_header_action_remains_icon_only_and_permission_gated(self):
        actions = VIEW[VIEW.index("@section('actions')"):VIEW.index("@endsection", VIEW.index("@section('actions')"))]
        self.assertIn("can_do('reservations.create')", actions)
        self.assertIn('class="btn btn-icon"', actions)
        self.assertIn('aria-label="Create group block"', actions)
        self.assertIn('title="New group block"', actions)
        self.assertNotIn('New Block</a>', actions)

    def test_register_has_forest_green_mobile_and_dark_theme_polish(self):
        for rule in (
            '.gb-hero{display:flex;',
            'linear-gradient(118deg,#103a23,#176239 68%,#247849)',
            '.gb-table{min-width:1040px;',
            '@media(max-width:680px)',
            'body.dark .gb-tab.active',
            'body.dark .gb-room-chip',
            '@media(prefers-reduced-motion:reduce)',
        ):
            with self.subTest(rule=rule):
                self.assertIn(rule, VIEW)

    def test_create_and_edit_form_keep_the_existing_save_contract(self):
        self.assertIn('method="post" action="{{ $block ? route(\'blocks.update\', $block) : route(\'blocks.store\') }}"', FORM)
        self.assertIn('@method(\'PUT\')', FORM)
        for field in ('name="company_id"', 'name="business_source_id"', 'name="contact_name"', 'name="contact_phone"', 'name="contact_email"', 'name="billing_instruction"', 'name="status"', 'name="arrival_date"', 'name="departure_date"', 'name="cutoff_date"', 'name="rate"', 'name="lines[{{ $t->id }}]"'):
            with self.subTest(field=field):
                self.assertIn(field, FORM)
        self.assertIn("Route::get('/create', 'create')->middleware('perm:reservations.create')", ROUTES)
        self.assertIn("Route::post('/', 'store')->middleware('perm:reservations.create')", ROUTES)
        self.assertIn("Route::get('/{block}/edit', 'edit')->middleware('perm:reservations.edit')", ROUTES)

    def test_room_quantity_inputs_are_labeled_and_keep_server_side_limits(self):
        self.assertIn('for="block-room-{{ $t->id }}"', FORM)
        self.assertIn('min="0" max="500" step="1"', FORM)
        self.assertIn("'lines' => 'required|array'", CONTROLLER)
        self.assertIn("'lines.*' => 'nullable|integer|min:0|max:500'", CONTROLLER)
        self.assertIn("BlockService::assertFits($lines, $data['arrival_date'], $data['departure_date']", CONTROLLER)
        self.assertIn('Availability is checked for every night and rechecked by the server on save.', FORM)

    def test_plan_summary_is_informational_and_tracks_room_nights_without_changing_submission(self):
        self.assertIn('id="gbfRoomTotal"', FORM)
        self.assertIn('id="gbfNightTotal"', FORM)
        self.assertIn('id="gbfRoomNightTotal"', FORM)
        self.assertIn('const dayNumber = value =>', FORM)
        self.assertIn('const roomNights = totalRooms * nights;', FORM)
        self.assertIn("roomInputs.forEach(input => input.addEventListener('input', refreshSummary));", FORM)
        self.assertIn('Room availability is confirmed when you save the block.', FORM)
        self.assertNotIn("fetch(", FORM)

    def test_form_steps_follow_logical_keyboard_order_and_adapt_to_small_screens(self):
        group = FORM.index('class="card gbf-card gbf-group-card"')
        dates = FORM.index('class="card gbf-card gbf-dates-card"')
        rooms = FORM.index('class="card gbf-card gbf-rooms-card"')
        summary = FORM.index('class="gbf-summary gbf-plan-summary"')
        self.assertLess(group, dates)
        self.assertLess(dates, rooms)
        self.assertLess(rooms, summary)
        self.assertIn('grid-template-areas:"group dates" "rooms summary"', FORM)
        self.assertIn('grid-template-areas:"group" "dates" "rooms" "summary"', FORM)
        self.assertIn('body.dark .gbf-summary', FORM)
        self.assertIn('.gbf-room-count{width:76px;min-height:34px;', FORM)
        self.assertIn('color:var(--c-text);font:inherit;', FORM)

    def test_detail_actions_are_icon_only_and_keep_permission_and_confirmation_gates(self):
        actions = SHOW[SHOW.index("@section('actions')"):SHOW.index("@endsection", SHOW.index("@section('actions')"))]
        self.assertIn("can_do('reservations.edit')", actions)
        self.assertIn("$block->status === 'tentative'", actions)
        self.assertIn('class="btn btn-icon" aria-label="Edit group block', actions)
        self.assertIn('aria-label="Mark block definite"', actions)
        self.assertIn('aria-label="Release unpicked rooms now"', actions)
        self.assertIn('aria-label="Cancel group block"', actions)
        self.assertIn('data-confirm=', actions)
        for visible_text in ('> Edit</a>', '>Mark definite</button>', '>Release now</button>', '>Cancel block</button>'):
            with self.subTest(visible_text=visible_text):
                self.assertNotIn(visible_text, actions)
        self.assertIn("Route::get('/{block}', 'show')->middleware('perm:reservations.view')", ROUTES)
        self.assertIn("Route::post('/{block}/status', 'status')->middleware('perm:reservations.edit')", ROUTES)

    def test_detail_keeps_pickup_fields_permission_gate_and_real_routes(self):
        self.assertIn("@if($block->isHolding() && can_do('reservations.create'))", SHOW)
        self.assertIn("route('blocks.pickup', $block)", SHOW)
        self.assertIn("name=\"action\" value=\"definite\"", SHOW)
        for field in ('name="names"', 'name="room_type_id"', 'name="adults"', 'name="arrival_date"', 'name="departure_date"'):
            with self.subTest(field=field):
                self.assertIn(field, SHOW)
        self.assertIn("Route::post('/{block}/pickup', 'pickup')->middleware('perm:reservations.create')", ROUTES)
        self.assertIn("@elseif($block->isHolding())", SHOW)
        self.assertIn('cannot create reservations for this block', SHOW)

    def test_detail_tables_are_accessible_and_page_adapts_to_dark_and_mobile(self):
        self.assertIn('aria-label="Room pickup by type and night"', SHOW)
        self.assertIn('aria-label="Reservations picked up from this group block"', SHOW)
        self.assertIn('scope="row"', SHOW)
        self.assertIn('picked of {{ $c[\'held\'] }} held', SHOW)
        self.assertIn("$block->cutoff_date->lte(bdate()->copy()->addDays(3))", SHOW)
        self.assertIn('body.dark .gbd-overview', SHOW)
        self.assertIn('@media(max-width:560px)', SHOW)
        self.assertIn('.gbd-pickup-table{min-width:560px}', SHOW)


if __name__ == "__main__":
    unittest.main(verbosity=2)
