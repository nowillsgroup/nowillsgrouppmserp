#!/usr/bin/env python3
"""Checks waiter-scoped POS live orders and kitchen-ready alerts."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
CONTROLLER = (ROOT / 'app/Http/Controllers/PosController.php').read_text()
VIEW = (ROOT / 'resources/views/pos/live-orders.blade.php').read_text()
NAV = (ROOT / 'resources/views/pos/_workspace-nav.blade.php').read_text()
ROUTES = (ROOT / 'routes/web.php').read_text()


class PosLiveOrdersWaiterTest(unittest.TestCase):
    def test_live_orders_route_and_feed_are_permission_gated(self):
        for route in (
            "Route::get('/live-orders', 'liveOrders')->middleware('perm:pos.view')->name('live-orders')",
            "Route::get('/live-orders/feed', 'liveOrdersFeed')->middleware('perm:pos.view')->name('live-feed')",
        ):
            self.assertIn(route, ROUTES)
        self.assertIn("route('pos.live-orders', [], false)", NAV)

    def test_waiters_get_my_orders_and_can_switch_to_all_live_orders(self):
        self.assertIn("auth()->user()?->hasPermission('pos.settle') ? 'all' : 'mine'", CONTROLLER)
        self.assertIn("'scope' => 'nullable|in:mine,all'", CONTROLLER)
        self.assertIn("$unassigned->where('user_id', $user->id)", CONTROLLER)
        self.assertIn("LOWER(TRIM(COALESCE(steward, ''))) = ?", CONTROLLER)
        self.assertIn('data-scope="mine"', VIEW)
        self.assertIn('data-scope="all"', VIEW)
        self.assertIn("$data['steward'] = filled($data['steward'] ?? null) ? trim($data['steward']) : (auth()->user()?->name ?: null);", CONTROLLER)
        self.assertIn("Route::post('/orders/{order}/claim', 'claim')->middleware('perm:pos.order')->name('claim')", ROUTES)
        self.assertIn("$locked->steward = $waiter", CONTROLLER)
        self.assertIn('data-claim-url="${esc(order.claim_url)}"', VIEW)
        self.assertIn("Order assigned to you and added to My orders.", VIEW)

    def test_feed_exposes_active_kitchen_items_and_ready_state(self):
        for fragment in (
            "whereIn('status', ['open', 'billed'])",
            "'ready_count' => $ready->count()",
            "'ready_at' => $item->ready_at?->toIso8601String()",
            "'serve_url' => route('pos.item.served', [$order, $item], false)",
            "'url' => route('pos.terminal', $order, false)",
            "'claim_url' => route('pos.claim', $order, false)",
            "'feedUrl' => route('pos.live-feed', [], false)",
        ):
            self.assertIn(fragment, CONTROLLER)
        self.assertIn('data-status="ready"', VIEW)
        self.assertIn('Ready to run', VIEW)
        self.assertIn('setInterval(load, 5000)', VIEW)

    def test_kitchen_ready_transitions_alert_waiters_and_keep_a_visual_ready_tab(self):
        self.assertIn('Notification.requestPermission()', VIEW)
        self.assertIn('new Notification(`Kitchen ready · ${group.where}`', VIEW)
        self.assertIn('function announceReady(groups)', VIEW)
        self.assertIn('data-ready="${ready ? \'1\' : \'0\'}"', VIEW)
        self.assertIn('posLiveAlert', VIEW)
        self.assertIn('posLiveReadyTabCount', VIEW)
        self.assertIn('soundOn', VIEW)
        self.assertIn('id="posLiveRetry"', VIEW)
        self.assertIn("retryButton.addEventListener('click', load)", VIEW)
        self.assertIn('hasSuccessfulLoad = true', VIEW)
        self.assertIn('function statusClass(status)', VIEW)
        self.assertIn("allServed ? 'Served'", VIEW)
        self.assertIn('noItemsSent ? \'No items sent\'', VIEW)
        self.assertIn('Ready items to run', VIEW)
        self.assertIn('Ready orders <span', VIEW)
        self.assertIn('aria-live="off" aria-busy="true"', VIEW)

    def test_waiter_can_mark_ready_items_served_without_a_page_reload(self):
        self.assertIn('data-serve-url="${esc(item.serve_url)}"', VIEW)
        self.assertIn("'Accept':'application/json'", VIEW)
        self.assertIn("return $request->expectsJson() ? response()->json(['ok' => true, 'item_id' => $line->id, 'status' => 'served']) : back();", CONTROLLER)
        self.assertIn("$line->forceFill(['kitchen_status' => 'served'", CONTROLLER)


if __name__ == '__main__':
    unittest.main(verbosity=2)
