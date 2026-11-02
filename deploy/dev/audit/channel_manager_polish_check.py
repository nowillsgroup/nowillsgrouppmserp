#!/usr/bin/env python3
"""Regression checks for the real PMS Channel Manager workspace and its existing operations."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
VIEWS = ROOT / "resources/views/channel-manager"
INDEX = (VIEWS / "index.blade.php").read_text()
SHOW = (VIEWS / "show.blade.php").read_text()
FORM = (VIEWS / "form.blade.php").read_text()
INBOX = (VIEWS / "inbox.blade.php").read_text()
MESSAGE = (VIEWS / "message.blade.php").read_text()
PUSHES = (VIEWS / "pushes.blade.php").read_text()
PARITY = (VIEWS / "parity.blade.php").read_text()
NAV = (VIEWS / "_nav.blade.php").read_text()
MODAL = (VIEWS / "_resolve_modal.blade.php").read_text()
ICAL = (ROOT / "resources/views/channels/index.blade.php").read_text()
ICAL_CONTROLLER = (ROOT / "app/Http/Controllers/ChannelController.php").read_text()
FIELD_COMPONENT = (ROOT / "resources/views/components/field.blade.php").read_text()
MANAGER = (ROOT / "app/Http/Controllers/ChannelManagerController.php").read_text()
CONNECTIONS = (ROOT / "app/Http/Controllers/ChannelConnectionController.php").read_text()
ROUTES = (ROOT / "routes/web.php").read_text()
APP_JS = (ROOT / "public/js/app.js").read_text()


class ChannelManagerPolishTest(unittest.TestCase):
    def test_primary_navigation_is_named_current_aware_and_keeps_all_channel_workspaces(self):
        self.assertIn('<nav class="cm-nav" aria-label="Channel Manager sections">', NAV)
        for target in ("route('cm.index')", "route('cm.inbox')", "route('cm.pushes')", "route('cm.parity')", "route('channels.index')"):
            with self.subTest(target=target):
                self.assertIn(target, NAV)
        self.assertIn('aria-current="page"', NAV)
        self.assertIn("ChannelService::attentionCount()", NAV)
        self.assertIn("routeIs('cm.connections.*')", NAV)

    def test_shared_visual_system_is_responsive_dark_mode_and_reduced_motion_safe(self):
        for token in ('cm-hero', 'cm-stat-grid', 'cm-detail-grid', 'cm-inbox-table', 'cm-parity-table', 'cm-sr-only', 'body.dark .cm-page', '@media(max-width:1180px)', '@media(max-width:700px)', '@media(max-width:390px)', 'prefers-reduced-motion:reduce'):
            with self.subTest(token=token):
                self.assertIn(token, NAV)
        self.assertIn('forest:#14532d', NAV)

    def test_dashboard_keeps_production_health_attention_overbooking_and_push_metrics(self):
        for token in ("ChannelService::attentionCount()", "$connections->where('is_active', true)", "$c->health() === 'failing'", "$totals['bookings']", "$totals['revenue']", "$totals['commission']", "$overbooked", "$attention", "$pushes", "route('cm.connections.push', $conn)", "route('cm.reservation.resolve', $r)"):
            with self.subTest(token=token):
                self.assertIn(token, INDEX)
        self.assertIn('id="connTable"', INDEX)
        self.assertIn('id="recentOta"', INDEX)
        self.assertIn('channel_currency', INDEX)
        self.assertIn('role="meter"', INDEX)
        self.assertIn("can_do('channels.resolve')", INDEX)

    def test_dashboard_header_actions_are_icon_only_and_accessibly_named(self):
        self.assertIn('aria-label="Open channel production report" title="Channel production"', INDEX)
        self.assertIn('aria-label="Add channel connection" title="New connection"', INDEX)
        self.assertIn('aria-label="Push pending changes for {{ $conn->name }}"', INDEX)
        self.assertIn('class="btn btn-icon cm-action"', INDEX)

    def test_connection_detail_keeps_push_test_pull_edit_and_delete_gates_and_endpoints(self):
        for token in ("route('cm.connections.push', $c)", "route('cm.connections.test', $c)", "route('cm.connections.pull', $c)", "route('cm.connections.edit', $c)", "route('cm.connections.destroy', $c)", "can_do('channels.manage')", "$c->provider !== 'simulator'", "! $c->reservations()->exists()", "data-confirm=\"Send the full"):
            with self.subTest(token=token):
                self.assertIn(token, SHOW)
        for label in ('Push pending changes', 'Full sync', 'Test connection', 'Pull bookings', 'Edit connection', 'Delete {{ $c->name }} connection'):
            with self.subTest(label=label):
                self.assertIn(label, SHOW)
        self.assertIn("route('cm.connections.mapping', $c)", SHOW)
        self.assertIn("route('cm.connections.restrict', $c)", SHOW)
        self.assertIn("route('cm.connections.rotate', $c)", SHOW)

    def test_connection_detail_preserves_seven_day_ari_mapping_restrictions_and_inbox_drilldowns(self):
        for token in ('id="ariPreview"', 'id="mapTable"', 'id="restrTable"', 'id="connMsgs"', "$preview", "route('cm.parity'", "route('cm.inbox'", 'id="mapping"', 'id="restrictions"'):
            with self.subTest(token=token):
                self.assertIn(token, SHOW)
        self.assertIn("ChannelService::ari($c", CONNECTIONS)
        self.assertIn("$v['stop_sell']", SHOW)
        self.assertIn("$v['min_stay']", SHOW)
        self.assertIn("$v['cta']", SHOW)
        self.assertIn("$c->rate_markup_pct", SHOW)

    def test_connection_mapping_inputs_and_restriction_fields_remain_intact(self):
        for token in ('rooms[{{ $t->id }}][code]', 'rooms[{{ $t->id }}][max_sell]', 'rooms[{{ $t->id }}][active]', 'rates[{{ $t->id }}][bar]', 'id="mappingForm"', 'id="restrForm"', 'name="min_stay"', 'name="stop_sell"', 'name="closed_to_arrival"', 'name="date_from"', 'name="date_to"'):
            with self.subTest(token=token):
                self.assertIn(token, SHOW)
        self.assertIn("'rooms.*.code'", CONNECTIONS)
        self.assertIn("'rates.*.*'", CONNECTIONS)
        self.assertIn('Channel codes may use letters', CONNECTIONS)

    def test_simulator_remains_explicitly_sandboxed_and_supports_booking_currency_and_cancel_flow(self):
        self.assertIn("$c->isSimulator() && $manage", SHOW)
        self.assertIn('Test channel – simulate', SHOW)
        self.assertIn("route('cm.connections.simulate', $c)", SHOW)
        for token in ('name="event"', 'name="booking_id"', 'name="room_map_id"', 'name="currency"', 'value="cancel"', 'Cancel via OTA', "ev.value === 'cancel'"):
            with self.subTest(token=token):
                self.assertIn(token, SHOW)

    def test_reservation_drilldowns_on_connection_pages_follow_reservations_view(self):
        self.assertIn("@if(can_do('reservations.view'))<a href=\"{{ route('reservations.show', $m->reservation) }}\"", SHOW)
        self.assertIn("@if(can_do('reservations.view'))<a href=\"{{ route('reservations.show', $b) }}\"", SHOW)
        self.assertIn("@if(can_do('reservations.view'))<a href=\"{{ route('reservations.show', $m->reservation) }}\"", MESSAGE)
        self.assertIn("can_do('reservations.view')", INDEX)
        self.assertIn("can_do('reservations.view')", INBOX)

    def test_connection_form_retains_provider_credentials_commercial_fields_and_active_behavior(self):
        for token in ('name="provider"', 'name="endpoint_url"', 'name="api_key"', 'name="api_secret"', 'name="commission_pct"', 'name="rate_markup_pct"', 'name="rate_rounding"', 'name="payment_model"', 'name="push_days"', 'name="is_active"', 'id="connForm"'):
            with self.subTest(token=token):
                self.assertIn(token, FORM)
        for provider in ('hub:', 'simulator:', 'mirror:', 'booking:', 'expedia:', 'siteminder:'):
            with self.subTest(provider=provider):
                self.assertIn(provider, FORM)
        self.assertIn("p.value === 'simulator'", FORM)
        self.assertIn("ChannelConnection::endpointProviders()", CONNECTIONS)
        self.assertIn("'api_secret' => 'nullable|string|max:255'", CONNECTIONS)
        self.assertIn("'push_days' => 'required|integer|min:30|max:400'", CONNECTIONS)

    def test_inbox_tabs_filters_pagination_message_warnings_and_reservation_permission_are_preserved(self):
        for token in ('id="inboxFilter"', 'name="connection"', 'name="q"', 'INBOX_TABS', 'withQueryString()', 'paginate(25)', 'id="inboxTable"', '$m->statusBadge()', '$m->warnings', "can_do('reservations.view')", "route('cm.message', $m)"):
            with self.subTest(token=token):
                self.assertIn(token, INBOX + MANAGER)
        self.assertIn("ChannelMessage::ATTENTION", INBOX)
        self.assertIn("request()->except('page')", INBOX)
        self.assertIn('aria-current="page"', INBOX)

    def test_message_detail_keeps_reprocess_and_resolution_permission_and_state_gates(self):
        self.assertIn("can_do('channels.manage') && in_array($m->status, ['attention', 'failed']) && $m->attention !== 'overbooked'", MESSAGE)
        self.assertIn("can_do('channels.resolve') && in_array($m->status, ['attention', 'failed'])", MESSAGE)
        self.assertIn("route('cm.reprocess', $m)", MESSAGE)
        self.assertIn("route('cm.resolve', $m)", MESSAGE)
        self.assertIn('id="rawPayload"', MESSAGE)
        self.assertIn('payment-card fields are removed on receipt', MESSAGE)
        self.assertIn("can_do('reservations.view')", MESSAGE)
        self.assertIn("ChannelMessage::ATTENTION", MESSAGE)

    def test_resolution_dialog_is_named_and_keeps_note_validation_and_global_close_behavior(self):
        self.assertIn('role="dialog" aria-modal="true" aria-labelledby="resolveTitle"', MODAL)
        self.assertIn('aria-label="Close resolution dialog"', MODAL)
        self.assertIn('name="note" label="Resolution" required', MODAL)
        self.assertIn("openModal('resolveModal')", MODAL)
        self.assertIn("openModal(o.dataset.modal)", APP_JS)
        self.assertIn("e.key !== 'Escape'", APP_JS)
        self.assertIn("'note' => 'required|string|min:5|max:255'", MANAGER)

    def test_sync_log_keeps_retry_explanation_filters_and_push_metadata(self):
        for token in ('id="pushFilter"', 'name="connection"', 'name="status"', '$failed24', 'after 1, 5, 15, 30', 'id="pushTable"', '$p->requests', '$p->duration_ms', '$p->attempt', '$p->error', '$p->user?->name', 'paginate(40)'):
            with self.subTest(token=token):
                self.assertIn(token, PUSHES + MANAGER)

    def test_rate_parity_keeps_connection_date_range_expected_vs_acknowledged_comparison(self):
        for token in ('id="parityFilter"', 'name="connection"', 'name="from"', 'ChannelService::parity', '$outOfSync', "$cell['expected']", "$cell['has']", "'out_of_sync'", "route('cm.connections.push', $c)", 'id="parityGrid"'):
            with self.subTest(token=token):
                self.assertIn(token, PARITY + MANAGER)
        self.assertIn('14 nights', PARITY)
        self.assertIn('PMS expected availability and rates compared with the values last acknowledged', PARITY)

    def test_ical_feed_workspace_preserves_export_import_copy_sync_and_token_rotation(self):
        for token in ('id="icalTable"', "route('ical.feed'", 'id="icalUrl{{ $c->id }}"', 'data-copy-target=', "route('channels.sync', $c)", "route('channels.regenerate', $c)", "route('channels.destroy', $c)", "route('channels.update', $c)", 'name="import_url"'):
            with self.subTest(token=token):
                self.assertIn(token, ICAL)
        self.assertIn("IcalService::sync($channel)", ICAL_CONTROLLER)
        self.assertIn("IcalService::export($channel)", ICAL_CONTROLLER)
        self.assertIn("'export_token' => Str::random(32)", ICAL_CONTROLLER)
        self.assertIn("Route::prefix('channels')->name('channels.')->middleware('perm:channels.manage')", ROUTES)

    def test_ical_dialogs_and_copy_controls_are_accessible_and_state_preserving(self):
        self.assertIn('role="dialog" aria-modal="true" aria-labelledby="{{ $modalTitleId }}"', ICAL)
        self.assertIn('aria-label="Close iCal channel dialog"', ICAL)
        self.assertIn('aria-label="Copy export URL for {{ $c->name }}"', ICAL)
        self.assertIn("'id' => null", FIELD_COMPONENT)
        self.assertIn(':id="$modalId.\'Name\'"', ICAL)
        self.assertIn(':id="$modalId.\'ImportUrl\'"', ICAL)
        self.assertIn("window.isSecureContext", ICAL)
        self.assertIn("document.execCommand('copy')", ICAL)
        self.assertIn("data-confirm=\"The current export URL will stop working", ICAL)
        self.assertIn("data-confirm=\"Delete this iCal feed", ICAL)
        self.assertIn('aria-describedby="{{ $modalDescId }}"', ICAL)
        self.assertIn('old(\'modal_id\') === $modalId', ICAL)

    def test_ical_register_adds_live_search_filters_and_sync_health_states(self):
        for token in ('id="icalSearch"', 'id="icalFilter"', 'id="icalResultCount"', 'id="icalNoMatches"', 'data-ical-feed', 'data-search="{{ $searchText }}"', 'data-sync-state="{{ $syncState }}"', 'data-copy-status=', 'role="status" aria-live="polite"'):
            with self.subTest(token=token):
                self.assertIn(token, ICAL)
        for token in ('Export availability', 'Import OTA calendars', 'Sync issues', 'route(\'channels.sync\', $c)'):
            with self.subTest(token=token):
                self.assertIn(token, ICAL)

    def test_all_channel_manager_routes_keep_existing_permission_boundaries(self):
        for token in ("Route::get('/', 'index')->name('index')", "Route::get('/inbox', 'inbox')->name('inbox')", "Route::get('/sync-log', 'pushes')->name('pushes')", "Route::get('/parity', 'parity')->name('parity')", "Route::post('/{chconn}/mapping', 'mapping')->name('mapping')", "Route::post('/{chconn}/simulate', 'simulate')->name('simulate')", "Route::post('/inbox/{chmsg}/resolve', 'resolve')", "Route::post('/inbox/{chmsg}/reprocess', 'reprocess')"):
            with self.subTest(token=token):
                self.assertIn(token, ROUTES)
        self.assertIn("Route::middleware('perm:channels.view')", ROUTES)
        self.assertIn("middleware('perm:channels.resolve')", ROUTES)
        self.assertIn("middleware('perm:channels.manage')", ROUTES)

    def test_icon_toolbar_actions_are_icon_only_and_have_accessible_names(self):
        for token in ('aria-label="Add channel connection" title="New connection"', 'aria-label="Push pending changes for {{ $c->name }}"', 'aria-label="Test connection to {{ $c->name }}"', 'aria-label="Edit {{ $c->name }} connection"', 'aria-label="Reprocess message {{ $m->channel_ref }}"', 'aria-label="Mark message {{ $m->channel_ref }} resolved"'):
            with self.subTest(token=token):
                self.assertIn(token, INDEX + SHOW + MESSAGE)
        self.assertIn('class="btn btn-icon cm-action"', INDEX + SHOW + MESSAGE)


if __name__ == '__main__':
    unittest.main(verbosity=2)
