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
        self.assertIn("public const VERSION = 6;", SYNC_SERVICE)

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
        self.assertIn("A device may only post work from the last", SERVICE)
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
        for asset in ("/js/offline-core.js", "/js/offline-pos.js", "/css/offline.css"):
            with self.subTest(asset=asset):
                self.assertIn(asset, SW)
        self.assertIn("const WORKSPACES = ['/offline', '/offline/pos', '/offline/housekeeping', '/offline/guest-requests']", SW)
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

    # ── it has to parse ────────────────────────────────────────────────────────

    def test_the_browser_files_parse(self):
        for script in ("public/js/offline-core.js", "public/js/offline-pos.js", "public/sw.js"):
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
