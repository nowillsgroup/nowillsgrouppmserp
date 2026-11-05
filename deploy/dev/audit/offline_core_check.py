#!/usr/bin/env python3
"""Static regression checks for the offline queue and the offline restaurant terminal.

There is no browser in the development sandbox, so these checks hold the rules the
browser code relies on: the operation id that makes a replay safe, the FIFO order of
the queue, the business date a device is allowed to post, the permission checks, and
the promise that a device never shows invented data.
"""

from __future__ import annotations

import pathlib
import re
import subprocess
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[3]


def read(path: str) -> str:
    return (ROOT / path).read_text()


CORE = read("public/js/offline-core.js")
SW = read("public/sw.js")
POS_JS = read("public/js/offline-pos.js")
SERVICE = read("app/Services/OfflinePosService.php")
SYNC_SERVICE = read("app/Services/OfflineSyncService.php")
ROUTES = read("routes/web.php")
CONTROLLER = read("app/Http/Controllers/OfflineSyncController.php")
POS_VIEW = read("resources/views/offline/pos.blade.php")
HUB_VIEW = read("resources/views/offline/index.blade.php")
LAYOUT = read("resources/views/layouts/app.blade.php")
MENU = read("config/pms.php")
MIGRATION = read("database/migrations/2026_11_05_000100_add_offline_ref_to_pos_orders.php")
DOC = read("docs/OFFLINE.md")
DESK_SERVICE = read("app/Services/OfflineFrontDeskService.php")
DESK_JS = read("public/js/offline-frontdesk.js")
DESK_VIEW = read("resources/views/offline/frontdesk.blade.php")
QUEUE_JS = read("public/js/offline-queue.js")
QUEUE_VIEW = read("resources/views/offline/queue.blade.php")
RECON_SERVICE = read("app/Services/OfflineReconciliationService.php")
RECON_VIEW = read("resources/views/offline/reconciliation.blade.php")
PREP_SERVICE = read("app/Services/OfflinePreparationService.php")
PREPARE_JS = CORE[CORE.index("function prepare(plan)"):CORE.index("function backoff(attempts)")]
STAY_SERVICE = read("app/Services/StayService.php")
FOLIO_SERVICE = read("app/Services/FolioService.php")


class OfflineCoreCheck(unittest.TestCase):
    # ── the queue ──────────────────────────────────────────────────────────────

    def test_an_operation_id_is_made_on_the_device_and_reused_on_replay(self):
        self.assertIn("function uuid()", CORE)
        self.assertIn("crypto.randomUUID", CORE)
        self.assertIn("id: uuid()", CORE, "every queued operation carries a device-generated id")
        self.assertIn("operation_id: row.id", CORE, "the queue posts that id to the server")
        # The server must answer a repeated id from its own store, not by applying again.
        self.assertIn("OfflineSyncOperation::where('operation_id', $operationId)->lockForUpdate()->first()", SYNC_SERVICE)
        self.assertIn("$existing = OfflineSyncOperation::where('operation_id', $operationId)->lockForUpdate()->first();", SERVICE,
                      "the POS slice replays through the same store")

    def test_the_queue_is_first_in_first_out(self):
        # An order opened offline must reach the server before its kitchen tickets.
        self.assertIn("Oldest first", CORE)
        self.assertIn("(a.created_at || 0) - (b.created_at || 0)", CORE)

    def test_failures_back_off_and_are_never_dropped_silently(self):
        self.assertIn("function backoff(attempts)", CORE)
        self.assertIn("Math.min(30 * Math.pow(2", CORE)
        self.assertIn("MAX_ATTEMPTS = 20", CORE)
        for status in ("conflict", "rejected", "stalled", "retry"):
            self.assertIn(f"'{status}'", CORE)
        # Nothing in the queue may be deleted on an error path.
        delete_guards = [line for line in CORE.splitlines() if "drop(row.id)" in line]
        self.assertTrue(delete_guards, "applied operations leave the queue")
        self.assertNotIn("drop(row.id)", CORE[CORE.index("if (result.status === 'conflict')"):CORE.index("if (result.status === 'rejected')")],
                         "a conflict must stay on the device")

    def test_a_signed_out_device_keeps_its_work(self):
        self.assertIn("signed-out", CORE)
        self.assertIn("Nothing is lost; the queue waits for a fresh session.", CORE)
        self.assertIn("X-CSRF-TOKEN", CORE)

    def test_background_sync_is_registered_and_handled(self):
        self.assertIn("registration.sync.register('pms-outbox')", CORE)
        self.assertIn("addEventListener('sync'", CORE)
        self.assertIn("event.tag === 'pms-outbox'", CORE)
        self.assertIn("importScripts('/js/offline-core.js')", SW)

    def test_snapshots_come_from_the_device_when_the_network_is_gone(self):
        self.assertIn("function snapshot(name, url, options)", CORE)
        self.assertIn("This device is offline and no saved copy of", CORE)
        self.assertIn("_stale: true", CORE, "a cached snapshot is marked stale, never passed off as fresh")
        self.assertIn("return new Response(", SW, "a navigation with nothing cached still explains itself")

    # ── the server half ────────────────────────────────────────────────────────

    def test_the_pos_slice_is_dispatched_and_returns_the_order(self):
        self.assertIn("'pos.order.create' => OfflinePosService::createOrder(", SYNC_SERVICE)
        self.assertIn("'pos.order.kot' => OfflinePosService::addItems(", SYNC_SERVICE)
        self.assertIn("'order' => $operation->result['order'] ?? null", SYNC_SERVICE,
                      "the device learns the order number the sync produced")
        self.assertIn("public const VERSION = 7;", SYNC_SERVICE)

    def test_the_device_reference_keeps_one_bill_together(self):
        self.assertIn("'offline_ref' => $localRef", SERVICE)
        self.assertIn("PosOrder::with('items')->where('offline_ref', $localRef)->first()", SERVICE)
        self.assertIn("PosOrder::with('outlet')->where('offline_ref', $localRef)->lockForUpdate()->first()", SERVICE)
        self.assertIn("Waiting for the order to reach the server first", SERVICE)
        self.assertIn("$table->string('offline_ref', 64)->nullable()", MIGRATION)
        self.assertIn("$table->index(['hotel_id', 'offline_ref']", MIGRATION)
        # Rendering must expose the reference so a reloaded device can still match.
        self.assertIn("'offline_ref' => $order->offline_ref", SERVICE)

    def test_work_keeps_the_business_date_it_happened_on(self):
        self.assertIn("public const BACKDATE_DAYS = 7;", SERVICE)
        self.assertIn("A device may only post work from the last", SYNC_SERVICE)
        self.assertIn("$order->forceFill(['created_at' => $clientCreatedAt])->saveQuietly();", SERVICE,
                      "the moment the guest was served is preserved")
        self.assertIn("business_date: order.businessDate", POS_JS)

    def test_a_table_already_running_is_joined_not_duplicated(self):
        self.assertIn("PosOrder::with('items')->where('pos_table_id', $table->id)", SERVICE)
        self.assertIn("'conflict', $running->id", SERVICE)
        self.assertIn("this device joined it instead of opening a second one", SERVICE)

    def test_every_operation_checks_the_permission_and_the_shift(self):
        self.assertIn("if (! $user->hasPermission('pos.order'))", SERVICE)
        self.assertIn("DepartmentShiftService::requiresPersonalShift($user)", SERVICE)
        self.assertIn("Open your service shift while connected, then send this order again.", SERVICE)
        self.assertIn("'rejected'", SERVICE)
        self.assertNotIn("<form method=\"post\"", POS_VIEW, "offline screens post through the queue, not a form")

    def test_money_is_not_posted_from_a_queued_operation(self):
        # Settlement, folio posting and VAT stay on the online cashier path.
        for forbidden in ("PosPayment::create", "FolioService::charge", "PosEvatService::onSettled", "'settled_at' =>"):
            with self.subTest(forbidden=forbidden):
                self.assertNotIn(forbidden, SERVICE)
        self.assertIn("charge a guest twice", DOC)
        self.assertIn("Settlement stays online", POS_VIEW)

    def test_prices_use_the_same_rules_as_the_online_terminal(self):
        for call in ("PosModifierService::snapshot(", "PosMenuVariantService::snapshot(", "TaxService::splitPrice(", "$order->recalculate();"):
            with self.subTest(call=call):
                self.assertIn(call, SERVICE)

    # ── routes, screens and the shell ──────────────────────────────────────────

    def test_the_offline_routes_are_gated_and_named(self):
        self.assertIn("Route::get('/', 'hub')->name('hub')", ROUTES)
        self.assertIn("Route::get('/pos', 'posWorkspace')->middleware('perm:pos.order,pos.view')->name('pos')", ROUTES)
        self.assertIn("Route::get('/pos/snapshot', 'posSnapshot')->middleware('perm:pos.order,pos.view')->name('pos.snapshot')", ROUTES)
        self.assertIn("Route::post('/sync', 'sync')", ROUTES)
        self.assertIn("public function hub()", CONTROLLER)
        self.assertIn("public function posSnapshot(Request $request): JsonResponse", CONTROLLER)
        self.assertIn('Cache-Control\' => \'no-store', CONTROLLER)
        self.assertIn("['label' => 'Offline POS Terminal', 'route' => 'offline.pos', 'perm' => 'pos.order']", MENU)

    def test_the_terminal_draws_from_the_snapshot_and_queues_intents(self):
        self.assertIn('data-snapshot-url="{{ route(\'offline.pos.snapshot\') }}"', POS_VIEW)
        self.assertIn("js/offline-pos.js", POS_VIEW)
        # The queue engine is loaded once, by the shell, and every screen shares it.
        self.assertIn("js/offline-core.js", LAYOUT)
        self.assertEqual(POS_VIEW.count("offline-core.js"), 1, "the terminal mentions the engine only in its comment")
        self.assertNotIn("<script src=\"{{ asset('js/offline-core.js')", POS_VIEW)
        self.assertIn("core.snapshot(SNAPSHOT, root.dataset.snapshotUrl)", POS_JS)
        self.assertIn("core.enqueue('pos.order.create'", POS_JS)
        self.assertIn("core.enqueue('pos.order.kot'", POS_JS)
        # A dish with sides asks for them first, the same rule as the online terminal.
        self.assertIn("if ((item.sides || []).length) openSideSheet(item);", POS_JS)
        self.assertIn("included", POS_JS)

    def test_the_shell_says_what_is_queued(self):
        self.assertIn('data-sync-strip', LAYOUT)
        self.assertIn("js/offline-core.js", LAYOUT)
        self.assertIn("window.PmsOffline.watch();", LAYOUT)
        self.assertIn("waiting to sync", LAYOUT)
        self.assertIn("Offline — this device keeps working", LAYOUT)

    def test_the_hub_lists_every_offline_workspace(self):
        for route in ("offline.pos", "offline.housekeeping", "offline.guest_requests", "offline.desktop.workspace"):
            with self.subTest(route=route):
                self.assertIn(f"route('{route}')", HUB_VIEW)
        self.assertIn("data-hub-queue", HUB_VIEW)
        self.assertIn("Needs a person", HUB_VIEW)

    def test_the_service_worker_caches_only_what_it_can_serve(self):
        self.assertIn("nowills-pms-v3", SW)
        for asset in ("/js/offline-core.js", "/js/offline-pos.js", "/js/offline-frontdesk.js", "/css/offline.css"):
            with self.subTest(asset=asset):
                self.assertIn(asset, SW)
        self.assertIn("const WORKSPACES = ['/offline', '/offline/pos', '/offline/housekeeping', '/offline/guest-requests', '/offline/frontdesk', '/offline/queue']", SW)
        self.assertIn("if (request.method !== 'GET') return;", SW, "a POST is never served from the cache")
        self.assertIn("response.redirected", SW, "a redirect to sign-in must not become the offline copy")
        self.assertIn("self.skipWaiting()", SW)
        self.assertIn("self.clients.claim()", SW)

    def test_the_runbook_describes_the_limits_honestly(self):
        for phrase in ("What deliberately waits for a connection", "Nothing is silently overwritten",
                       "Background Sync", "iOS", "Extending offline support", "Honest limits",
                       "Clearing site data deletes unsent work"):
            with self.subTest(phrase=phrase):
                self.assertIn(phrase, DOC)
        self.assertIn("offline_core_check.py", DOC)

    def test_the_preview_is_built_from_the_screen(self):
        preview = read("deploy/dev/previews/offline-pos.html")
        self.assertIn("Generated by deploy/dev/build-offline-pos-preview.js", preview)
        self.assertIn("offline-pos-table", preview, "the preview shows the table board")
        self.assertIn("offline-pos-line", preview, "the preview shows a ticket with dishes on it")
        self.assertIn('href="/css/offline.css"', preview)
        self.assertEqual(preview.count("UI-only preview"), 0, "the preview shows the screen, not a notice about it")
        self.assertIn("/__preview/offline-pos", read("deploy/dev/previews/index.html"))
        self.assertIn("'/offline-pos'", read("deploy/dev/management-dashboard-preview-server.mjs"))
        # Staleness is caught by the builder itself when jsdom is available.
        result = subprocess.run(["node", str(ROOT / "deploy/dev/build-offline-pos-preview.js"), "--check"],
                                capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    # ── the offline front desk ─────────────────────────────────────────────────

    def test_the_desk_preview_is_built_from_the_screen(self):
        preview = read("deploy/dev/previews/offline-frontdesk.html")
        self.assertIn("Generated by deploy/dev/build-offline-frontdesk-preview.js", preview)
        self.assertIn("offline-desk-row", preview, "the preview shows the arrivals and in-house rows")
        self.assertIn("data-arrival=", preview)
        self.assertIn("data-inhouse=", preview)
        self.assertIn("offline-pos-queue-row", preview, "the preview shows the outbox with the desk's work in it")
        self.assertIn('href="/css/offline.css"', preview)
        self.assertIn("Charge saved on this device", preview, "the preview shows the offline promise, not a disclaimer")
        self.assertIn("Offline — working from this device", preview)
        self.assertEqual(preview.count("UI-only preview"), 0)
        self.assertIn("/__preview/offline-frontdesk", read("deploy/dev/previews/index.html"))
        self.assertIn("'/offline-frontdesk'", read("deploy/dev/management-dashboard-preview-server.mjs"))
        result = subprocess.run(["node", str(ROOT / "deploy/dev/build-offline-frontdesk-preview.js"), "--check"],
                                capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_the_front_desk_is_dispatched_through_the_shared_outbox(self):
        self.assertIn("'frontdesk.checkin' => OfflineFrontDeskService::checkIn(", SYNC_SERVICE)
        self.assertIn("'frontdesk.charge' => OfflineFrontDeskService::postCharge(", SYNC_SERVICE)
        self.assertIn("core.enqueue('frontdesk.checkin', {", DESK_JS)
        self.assertIn("core.enqueue('frontdesk.charge', {", DESK_JS)
        self.assertIn("var core = window.PmsOffline;", DESK_JS)
        self.assertIn("data-snapshot-url=\"{{ route('offline.frontdesk.snapshot') }}\"", DESK_VIEW)
        self.assertIn("Route::get('/frontdesk/snapshot', 'frontDeskSnapshot')", ROUTES)
        self.assertIn("OfflineFrontDeskService::snapshot($request->user())", CONTROLLER)

    def test_the_desk_reuses_the_online_check_in_and_charge_services(self):
        self.assertIn("BusinessDateContext::run($businessDate, fn () => StayService::checkIn(", DESK_SERVICE,
                      "a queued check-in runs the same code as the online desk")
        self.assertIn("FolioService::charge($folio, $category, $description, $amount, $applyTax)", DESK_SERVICE)
        self.assertIn("FolioService::openForStay($stay)", DESK_SERVICE)
        self.assertIn("public static function checkIn(", STAY_SERVICE)
        self.assertIn("public static function charge(Folio $folio", FOLIO_SERVICE)

    def test_no_money_is_taken_by_a_queued_operation(self):
        for forbidden in ("FolioService::payment", "FolioService::settle", "FolioService::refund",
                          "Payment::create", "FolioPayment", "::checkOut("):
            with self.subTest(forbidden=forbidden):
                self.assertNotIn(forbidden, DESK_SERVICE,
                                 "payments, refunds and check-out stay on the online cashier screen")
        # The device form has no payment fields at all.
        for absent in ("data-payment", "data-settle", "data-checkout", "Take payment"):
            with self.subTest(absent=absent):
                self.assertNotIn(absent, DESK_VIEW)
        self.assertIn("No payment is taken here", DESK_VIEW)

    def test_categories_with_a_workflow_behind_them_are_not_offered(self):
        self.assertIn("public const EXCLUDED_CATEGORIES = ['room', 'cancellation', 'excursion'];", DESK_SERVICE)
        self.assertIn("array_diff_key($all, array_flip(self::EXCLUDED_CATEGORIES))", DESK_SERVICE)
        self.assertIn("array_key_exists($category, self::categories())", DESK_SERVICE)
        self.assertIn("That charge category cannot be posted while offline.", DESK_SERVICE)
        # The select is filled from the server snapshot, never hard-coded on the device.
        self.assertIn("var categories = (state.snapshot && state.snapshot.categories) || {};", DESK_JS)
        self.assertNotIn("value=\"minibar\"", DESK_VIEW)

    def test_a_charge_lands_on_the_business_date_the_desk_was_working(self):
        self.assertIn("public const BACKDATE_DAYS = 7;", DESK_SERVICE)
        self.assertIn("OfflineSyncService::deviceBusinessDate($payload, self::BACKDATE_DAYS)", DESK_SERVICE)
        self.assertIn("public static function deviceBusinessDate(", SYNC_SERVICE)
        self.assertIn("business_date: root.dataset.businessDate", DESK_JS)
        self.assertIn("data-business-date=\"{{ bdate()->toDateString() }}\"", DESK_VIEW)

    def test_the_server_refuses_what_the_device_should_not_do(self):
        self.assertIn("if (! $user->hasPermission('frontdesk.checkin'))", DESK_SERVICE)
        self.assertIn("if (! $user->hasPermission('folio.post'))", DESK_SERVICE)
        self.assertIn("Your login cannot check guests in.", DESK_SERVICE)
        self.assertIn("Your login cannot post folio charges.", DESK_SERVICE)
        self.assertIn("if ($room->status !== 'vacant')", DESK_SERVICE)
        self.assertIn("was taken by the time this check-in reached the server", DESK_SERVICE)
        self.assertIn("'conflict', $stay->id", DESK_SERVICE)
        self.assertIn("if ($stay->status !== 'checked_in')", DESK_SERVICE)
        self.assertIn("if ($stay->status !== 'reserved')", DESK_SERVICE)
        self.assertIn("$amount > self::MAX_CHARGE", DESK_SERVICE)
        self.assertIn("if ((int) $stay->room_id === $roomId)", DESK_SERVICE,
                      "a lost response replays as applied instead of checking the guest in twice")
        self.assertIn("'already' => true", DESK_SERVICE)

    def test_a_queued_check_in_only_offers_a_vacant_room(self):
        self.assertIn("room.status === 'vacant'", DESK_JS)
        self.assertIn("Number(a.room_type_id) === Number(roomTypeId)", DESK_JS,
                      "the booked room type is offered first")
        self.assertIn("data-can-checkin=\"{{ can_do('frontdesk.checkin') ? '1' : '0' }}\"", DESK_VIEW)
        self.assertIn("data-can-charge=\"{{ can_do('folio.post') ? '1' : '0' }}\"", DESK_VIEW)
        self.assertIn("root.dataset.canCheckin !== '1'", DESK_JS)
        self.assertIn("root.dataset.canCharge !== '1'", DESK_JS)

    def test_the_desk_snapshot_keeps_guest_names_off_the_device_only_when_needed(self):
        self.assertIn("'arrivals' => $arrivals->map(", DESK_SERVICE)
        self.assertIn("'in_house' => $inHouse->map(", DESK_SERVICE)
        self.assertIn("public const VERSION = 1;", DESK_SERVICE)
        self.assertIn("'can_checkin' => $user->hasPermission('frontdesk.checkin')", DESK_SERVICE)
        self.assertIn("'can_charge' => $user->hasPermission('folio.post')", DESK_SERVICE)
        self.assertIn("$canViewFolio && $stay->folio", DESK_SERVICE,
                      "a balance is only sent to a login that may read folios")

    def test_the_desk_is_reachable_from_the_hub_and_the_service_worker(self):
        self.assertIn("route('offline.frontdesk')", HUB_VIEW)
        self.assertIn("Route::get('/frontdesk', 'frontDeskWorkspace')->middleware('perm:frontdesk.view')", ROUTES)
        self.assertIn("'/offline/frontdesk'", SW)
        self.assertIn("/js/offline-frontdesk.js", SW)
        # The outbox has one door: a desk login may push its own work.
        sync = [line for line in ROUTES.splitlines() if "Route::post('/sync', 'sync')" in line][0]
        for perm in ("frontdesk.checkin", "folio.post", "pos.order", "housekeeping.manage"):
            with self.subTest(perm=perm):
                self.assertIn(perm, sync)

    # ── reading the outbox back ────────────────────────────────────────────────

    def test_a_person_can_send_refused_work_again(self):
        self.assertIn("function retry(id)", CORE)
        self.assertIn("status: 'queued', attempts: 0, next_attempt_at: 0, error: null", CORE,
                      "a retry forgets the backoff and tries at once")
        self.assertIn("flush({ force: true })", CORE)
        self.assertIn("retry: retry,", CORE)
        self.assertIn("core.retry(retry.getAttribute('data-retry'))", QUEUE_JS)

    def test_only_a_person_may_throw_work_away(self):
        self.assertIn("function discard(id)", CORE)
        self.assertIn("discard: discard,", CORE)
        self.assertIn("window.confirm(", QUEUE_JS, "the screen asks before it drops a change")
        self.assertIn("cannot be recovered", QUEUE_JS)
        self.assertIn("core.discard(discard.getAttribute('data-discard'))", QUEUE_JS)
        self.assertIn("Discarding throws a change away for good", QUEUE_VIEW)
        # The flush path drops an operation only after the server applied it.
        self.assertNotIn("discard(", CORE.split("function discard")[0])

    def test_the_outbox_screen_shows_the_reason_not_only_a_count(self):
        self.assertIn("Needs a person", QUEUE_VIEW)
        self.assertIn("Waiting to send", QUEUE_VIEW)
        self.assertIn("row.error ?", QUEUE_JS, "the server's reason is on the row")
        self.assertIn("What was sent", QUEUE_JS, "a supervisor can see the payload")
        self.assertIn("Try again</button>", QUEUE_JS, "a refused change can be sent again from the row")
        self.assertIn("Route::get('/queue', 'queue')", ROUTES)
        self.assertIn("view('offline.queue')", CONTROLLER)
        self.assertIn("route('offline.queue')", HUB_VIEW)
        self.assertIn("/js/offline-queue.js", SW)
        self.assertIn("/css/offline.css", SW)

    def test_the_outbox_preview_is_built_from_the_screen(self):
        preview = read("deploy/dev/previews/offline-outbox.html")
        self.assertIn("Generated by deploy/dev/build-offline-outbox-preview.js", preview)
        self.assertIn("Needs a person", preview)
        self.assertIn("Waiting to send", preview)
        self.assertIn("Room 103 was taken by the time this check-in reached the server.", preview,
                      "the preview shows the server's actual reason on the row")
        self.assertIn("data-retry=", preview)
        self.assertIn("data-discard=", preview)
        self.assertIn('href="/css/offline.css"', preview)
        self.assertEqual(preview.count("UI-only preview"), 0)
        self.assertIn("/__preview/offline-outbox", read("deploy/dev/previews/index.html"))
        self.assertIn("'/offline-outbox'", read("deploy/dev/management-dashboard-preview-server.mjs"))
        result = subprocess.run(["node", str(ROOT / "deploy/dev/build-offline-outbox-preview.js"), "--check"],
                                capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    # ── after the line returns ─────────────────────────────────────────────────

    def test_the_reconciliation_page_reads_the_receipts_and_never_edits_them(self):
        self.assertIn("class OfflineReconciliationService", RECON_SERVICE)
        self.assertIn("OfflineSyncOperation::query()", RECON_SERVICE)
        for write in ("->save(", "->update(", "->delete(", "::create(", "->forceDelete("):
            with self.subTest(write=write):
                self.assertNotIn(write, RECON_SERVICE, "looking at what happened must not change what happened")

    def test_the_report_window_is_bounded(self):
        self.assertIn("public const WINDOW_DAYS = 31;", RECON_SERVICE)
        self.assertIn("if ($from->lt($earliest))", RECON_SERVICE)
        self.assertIn("if ($to->gt($today))", RECON_SERVICE)
        self.assertIn("public const LIMIT = 500;", RECON_SERVICE)
        self.assertIn("'truncated' => $counts->sum() > self::LIMIT", RECON_SERVICE)
        self.assertIn("if ($to->lt($from))", RECON_SERVICE, "a backwards range is swapped, not rejected")

    def test_the_report_says_what_each_change_produced(self):
        for key in ("$result['order']", "$result['stay']", "$result['charge']", "$result['room']", "$result['request']"):
            with self.subTest(key=key):
                self.assertIn(key, RECON_SERVICE)
        self.assertIn("public const LABELS = [", RECON_SERVICE)
        self.assertIn("'frontdesk.checkin' => 'Checked a guest in'", RECON_SERVICE)
        self.assertIn("'pos.order.kot' => 'Sent dishes to the kitchen'", RECON_SERVICE)
        self.assertIn("'needs_person' => in_array($operation->status, ['conflict', 'rejected'], true)", RECON_SERVICE)

    def test_the_receipt_knows_who_sent_it(self):
        model = read("app/Models/OfflineSyncOperation.php")
        self.assertIn("public function user(): BelongsTo", model)
        self.assertIn("return $this->belongsTo(User::class);", model)
        self.assertIn("->with('user:id,name')", RECON_SERVICE)

    def test_a_supervisor_reaches_the_report_and_can_export_it(self):
        self.assertIn("Route::get('/reconciliation', 'reconciliation')", ROUTES)
        self.assertIn("perm:offline.desktop.view,frontdesk.view,housekeeping.manage", ROUTES)
        self.assertIn("return view('offline.reconciliation', $report);", CONTROLLER)
        self.assertIn("response()->streamDownload(", CONTROLLER)
        self.assertIn("OfflineReconciliationService::csv($report['rows'])", CONTROLLER)
        self.assertIn("fputcsv(", RECON_SERVICE)
        self.assertIn("request()->fullUrlWithQuery(['export' => 'csv'])", RECON_VIEW)
        self.assertIn("route('offline.reconciliation')", read("resources/views/offline/desktop.blade.php"))
        self.assertIn("route('offline.queue')", RECON_VIEW, "a refused change links to the device outbox")

    # ── preparing a device ─────────────────────────────────────────────────────

    def test_a_device_is_prepared_in_one_press(self):
        self.assertIn("function prepare(plan)", CORE)
        self.assertIn("prepare: prepare,", CORE)
        self.assertIn("data-hub-prepare", HUB_VIEW)
        self.assertIn("window.PmsOffline.prepare(slices)", HUB_VIEW)
        self.assertIn("window.PmsOffline.local.set(PREPARED, saved)", HUB_VIEW)
        self.assertIn("data-preparation-url=\"{{ route('offline.preparation') }}\"", HUB_VIEW)
        self.assertIn("Route::get('/preparation', 'preparation')", ROUTES)
        self.assertIn("OfflinePreparationService::plan($request->user())", CONTROLLER)

    def test_preparing_keeps_the_screen_and_its_list(self):
        self.assertIn("fetch(slice.page, { credentials: 'same-origin' })", PREPARE_JS,
                      "the page is fetched so the service worker has the screen")
        self.assertIn("slice.snapshot_key", PREPARE_JS,
                      "screens that read through this queue keep the snapshot in its store")
        self.assertIn("entry.snapshot = true;", PREPARE_JS)
        self.assertIn("entry.page = !!response.ok;", PREPARE_JS)
        # Preparation is a copy, not a transaction: it never queues work.
        self.assertNotIn("enqueue(", PREPARE_JS)
        self.assertNotIn("flush(", PREPARE_JS)

    def test_preparation_only_offers_what_the_login_may_work(self):
        for perm in ("'pos.order'", "'pos.view'", "'frontdesk.view'", "'housekeeping.attendant'",
                     "'housekeeping.manage'", "'requests.manage'"):
            with self.subTest(perm=perm):
                self.assertIn("hasPermission(" + perm + ")", PREP_SERVICE)
        self.assertIn("! Route::has($page) || ! Route::has($snapshot)", PREP_SERVICE,
                      "a slice whose routes are gone is not offered")
        self.assertIn("array_values(array_filter([", PREP_SERVICE)
        self.assertIn("'snapshot_key' => $snapshotKey", PREP_SERVICE)
        self.assertIn("'pos'", PREP_SERVICE)
        self.assertIn("'frontdesk'", PREP_SERVICE)

    # ── it has to parse ────────────────────────────────────────────────────────

    def test_the_browser_files_parse(self):
        for script in ("public/js/offline-core.js", "public/js/offline-pos.js", "public/js/offline-frontdesk.js",
                       "public/js/offline-queue.js", "public/sw.js"):
            with self.subTest(script=script):
                result = subprocess.run(["node", "--check", str(ROOT / script)], capture_output=True, text=True)
                self.assertEqual(result.returncode, 0, result.stderr[-400:])

    def test_the_php_parses(self):
        for php in ("app/Services/OfflinePosService.php", "app/Services/OfflineSyncService.php",
                    "app/Http/Controllers/OfflineSyncController.php",
                    "database/migrations/2026_11_05_000100_add_offline_ref_to_pos_orders.php"):
            with self.subTest(php=php):
                body = read(php)
                self.assertTrue(body.startswith("<?php"), php)
                self.assertEqual(body.count("{"), body.count("}"), f"{php}: braces must balance")
                self.assertNotIn("TODO", body)

    def test_no_template_debug_is_left_in_the_screens(self):
        for path in ("resources/views/offline/pos.blade.php", "resources/views/offline/index.blade.php"):
            body = read(path)
            for marker in ("console.log(", "dd(", "var_dump(", "Lorem ipsum"):
                with self.subTest(path=path, marker=marker):
                    self.assertNotIn(marker, body)


if __name__ == "__main__":
    unittest.main(verbosity=2)
