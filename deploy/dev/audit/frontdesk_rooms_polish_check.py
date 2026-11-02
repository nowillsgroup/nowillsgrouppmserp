#!/usr/bin/env python3
"""Static regression audit for the Front Office room inventory view."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
VIEW = (ROOT / "resources/views/frontdesk/rooms.blade.php").read_text()
CONTROLLER = (ROOT / "app/Http/Controllers/FrontDeskController.php").read_text()


class FrontDeskRoomsPolishTest(unittest.TestCase):
    def test_inventory_query_validates_and_keeps_legacy_status_filters_compatible(self):
        for marker in (
            "'type' => ['nullable', 'integer', 'min:1']",
            "'room_status' => ['nullable', 'in:vacant,occupied,reserved,due_out,out_of_order,out_of_service']",
            "'hk_status' => ['nullable', 'in:clean,dirty,inspected,touch_up']",
            "$legacyStatus = $filters['status'] ?? null",
            "$housekeepingStatus = null",
            "Room::with($relations)->where('is_active', true)",
            "->whereDate('arrival_date', $todayString)",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, CONTROLLER)
        self.assertIn("in_array($legacyStatus, ['clean', 'dirty', 'inspected', 'touch_up'], true)", CONTROLLER)

    def test_live_statuses_derive_arrivals_and_dueouts_from_the_business_date(self):
        for marker in (
            "$today = bdate()", "$room->view_status = $room->status",
            "$room->currentStay->departure_date->lte($today)", "$room->view_status = 'due_out'",
            "isset($arrivingToday[$room->id])", "$room->view_status = 'reserved'",
            "$room->arrival = $arrivingToday[$room->id]",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, CONTROLLER)

    def test_inventory_metrics_are_calculated_before_room_status_filters(self):
        self.assertIn("$counts = $allRooms->countBy('view_status');", CONTROLLER)
        self.assertIn("$hk = $canViewHousekeeping ? $allRooms->countBy('hk_status') : collect();", CONTROLLER)
        self.assertIn("$rooms = $allRooms\n            ->when($roomStatus", CONTROLLER)
        for marker in (
            "$counts['vacant']", "$counts['occupied']", "$counts['reserved']",
            "$counts['due_out']", "$counts['out_of_order']", "$counts['out_of_service']",
            'number_format($totalRooms)', 'number_format($visibleRooms)',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)

    def test_room_view_has_separate_room_and_housekeeping_filters_and_live_search(self):
        for marker in (
            'id="room-type-filter"', 'name="type"', 'id="room-status-filter"', 'name="room_status"',
            'id="room-hk-filter"', 'name="hk_status"', 'data-room-search', 'data-room-card',
            'room-view-result-count', 'room-view-search-empty', 'data-room-group',
            'aria-live="polite"', 'request()->hasAny',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)

    def test_room_tiles_explain_status_guest_arrival_housekeeping_and_floor(self):
        for marker in (
            'aria-label="Room inventory, grouped by floor"', 'room-view-tile-status',
            'statusLabels[$room->view_status]', 'Available for assignment',
            'Expected today', 'Due out', 'room-view-hk', 'aria-label="Housekeeping status:',
            'room-view-floor', 'room-view-floor-heading', 'datetime="{{ $today->toDateString() }}"',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)

    def test_room_menus_folio_reservation_and_housekeeping_actions_follow_permissions(self):
        for marker in (
            "@if(can_do('reservations.view'))<a role=\"menuitem\"",
            "@if($canViewFolio && $stay->folio)", "@if(can_do('frontdesk.roommove'))",
            "@if(can_do('frontdesk.checkout'))", "@if(can_do('frontdesk.checkin'))",
            "@if(can_do('reservations.create'))", "@if(can_do('housekeeping.manage'))",
            "'currentStay.folio'", "'folio.view'", 'role="menu"', 'aria-haspopup="menu"',
            'aria-expanded="false"', 'event.key !== \'Escape\'',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW + CONTROLLER)

    def test_room_status_housekeeping_updates_keep_csrf_and_only_offer_safe_room_actions(self):
        for marker in (
            "route('housekeeping.status')", '@csrf', 'name="rooms[]"', 'name="hk_status"',
            "name=\"status\" value=\"out_of_order\"", "in_array($room->status, ['out_of_order','out_of_service'], true)",
            "@if($room->view_status === 'vacant')<form method=\"post\"",
            "route('frontdesk.walkin', ['room_id' => $room->id])",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)

    def test_room_inventory_has_empty_dark_mobile_and_print_states(self):
        for marker in (
            'No rooms match these filters', 'Clear filters', '@media(max-width:1100px)',
            '@media(max-width:700px)', '@media(max-width:620px)', '@media(max-width:390px)',
            '@media print', 'body.dark .room-view-hero', 'body.dark .room-view-tile-status',
            'prefers-reduced-motion:reduce',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)


if __name__ == "__main__":
    unittest.main(verbosity=2)
