#!/usr/bin/env python3
"""Regression checks for the live Kitchen Display board."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
VIEW = (ROOT / "resources/views/kitchen/index.blade.php").read_text()
CONTROLLER = (ROOT / "app/Http/Controllers/KitchenController.php").read_text()
ROUTES = (ROOT / "routes/web.php").read_text()


class KitchenDisplayPolishTest(unittest.TestCase):
    def test_filters_connection_and_sound_controls_are_named_and_stateful(self):
        for marker in (
            'aria-label="Filter tickets by outlet"',
            'aria-label="Filter tickets by kitchen station"',
            'id="kdsConnection" class="badge badge-slate" role="status" aria-live="polite"',
            'id="kdsActionStatus" class="tiny" role="status" aria-live="polite"',
            'id="kdsSound" title="Beep on new tickets" aria-pressed="false"',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)
        self.assertIn("setAttribute('aria-pressed', sound ? 'true' : 'false')", VIEW)
        self.assertIn("setConnection('Live', 'badge-green')", VIEW)
        self.assertIn("setConnection('Connection lost · retrying', 'badge-red')", VIEW)
        self.assertIn('id="kdsArrivalNotice" class="kds-arrival-notice" role="status" aria-live="polite"', VIEW)
        self.assertIn("const arrivals = seen ? [...keys].filter(k => !seen.has(k)) : []", VIEW)
        self.assertIn("freshUntil.set(key, now + 30000)", VIEW)
        self.assertIn("announceArrival(arrivals.length)", VIEW)
        self.assertIn(".tkt.fresh", VIEW)
        self.assertIn("const storage = {get(key)", VIEW)
        self.assertIn("try { return localStorage.getItem(key); } catch (e) { return null; }", VIEW)

    def test_ticket_lanes_and_item_actions_are_semantic_and_keyboard_operable(self):
        self.assertIn('aria-labelledby="kds-title-{{ $k }}"', VIEW)
        self.assertIn('id="kds-title-{{ $k }}"', VIEW)
        for lane in ("held", "new", "preparing", "ready"):
            with self.subTest(lane=lane):
                self.assertIn(f"'{lane}' =>", VIEW)
        self.assertIn('role="list" aria-label="{{ $l }} tickets"', VIEW)
        self.assertIn('role="button" tabindex="0" aria-label=', VIEW)
        self.assertIn("board.addEventListener('keydown'", VIEW)
        self.assertIn("['Enter', ' '].includes(e.key)", VIEW)
        self.assertIn("i.status !== 'served'", VIEW)
        self.assertIn("i.hold", VIEW)
        self.assertIn('rel="noopener noreferrer"', VIEW)

    def test_fullscreen_state_tracks_browser_escape_and_polling_pauses_in_background(self):
        self.assertIn("document.addEventListener('fullscreenchange', syncFullscreen)", VIEW)
        self.assertIn("document.body.classList.toggle('kds-full', active)", VIEW)
        self.assertIn("if (busy || loading || document.hidden) return", VIEW)
        self.assertIn("if (!document.hidden) load()", VIEW)
        self.assertIn("loading = false", VIEW)

    def test_kitchen_workflow_routes_and_server_status_flow_are_preserved(self):
        for route in ("kitchen.feed", "kitchen.bump", "kitchen.station"):
            with self.subTest(route=route):
                self.assertIn(f"name('{route.removeprefix('kitchen.')}", ROUTES)
        self.assertIn("whereIn('status', ['open', 'billed'])", CONTROLLER)
        self.assertIn("'tickets' => $tickets->all()", CONTROLLER)
        self.assertIn("'counts' => $tickets->countBy('status')->all()", CONTROLLER)
        self.assertIn("'modifiers' => collect($l->modifiers ?? [])->pluck('name')->values()->all()", CONTROLLER)
        self.assertIn("$waiting->map($row)->values()->all()", CONTROLLER)
        self.assertIn("$recent->map($row)->values()->all()", CONTROLLER)
        self.assertIn("'item_id' => 'nullable|integer'", CONTROLLER)
        self.assertIn("$items->every(fn ($i) => $i->kitchen_status === 'held')", CONTROLLER)

    def test_grouped_ticket_advances_never_regress_lines_that_are_already_ahead(self):
        bump = CONTROLLER.split("public function bump(Request $request)", 1)[1].split("public function station(Request $request)", 1)[0]
        self.assertIn("$progress = ['new' => 0, 'preparing' => 1, 'ready' => 2, 'served' => 3]", bump)
        self.assertIn("empty($data['item_id']) && ! $undo", bump)
        self.assertIn("($progress[$i->kitchen_status] ?? PHP_INT_MAX) < $progress[$target]", bump)
        self.assertIn("$next = $back[$i->kitchen_status] ?? null", bump)
        self.assertIn("($undo ? 'undo' : ($target ?? 'next'))", bump)

    def test_kds_layout_adapts_from_four_columns_to_touch_friendly_mobile_lanes(self):
        self.assertIn("grid-template-columns:repeat(4,minmax(0,1fr))", VIEW)
        self.assertIn("@media (max-width:1100px){.kds{grid-template-columns:repeat(2,minmax(0,1fr))}}", VIEW)
        self.assertIn("@media (max-width:640px){.kds{grid-template-columns:1fr}", VIEW)
        self.assertIn("li[role=button]:focus-visible", VIEW)


if __name__ == "__main__":
    unittest.main(verbosity=2)
