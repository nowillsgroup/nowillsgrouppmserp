#!/usr/bin/env python3
"""Static regression checks for the polished Front Office Tape Chart."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
CONTROLLER = (ROOT / "app/Http/Controllers/FrontDeskController.php").read_text()
VIEW = (ROOT / "resources/views/frontdesk/tapechart.blade.php").read_text()
ROUTES = (ROOT / "routes/web.php").read_text()


class FrontDeskTapeChartPolishTest(unittest.TestCase):
    def test_window_filters_are_validated_without_breaking_existing_range(self):
        method = CONTROLLER.split("public function tapeChart(Request $request)", 1)[1].split("public function tapeDrop", 1)[0]
        self.assertIn("'from' => ['nullable', 'date_format:Y-m-d']", method)
        self.assertIn("'days' => ['nullable', 'integer', 'between:7,31']", method)
        self.assertIn("subDays(2)->toDateString()", method)
        self.assertIn("$filters['days'] ?? 14", method)
        self.assertIn("$from->copy()->addDays($days)", method)

    def test_window_query_keeps_overlapping_room_and_unassigned_stays(self):
        method = CONTROLLER.split("public function tapeChart(Request $request)", 1)[1].split("public function tapeDrop", 1)[0]
        self.assertIn("whereIn('status', ['reserved', 'checked_in', 'checked_out'])", method)
        self.assertIn("whereDate('arrival_date', '<', $to->toDateString())", method)
        self.assertIn("whereDate('departure_date', '>', $from->toDateString())", method)
        self.assertIn("$stays->whereNotNull('room_id')->groupBy('room_id')", method)
        self.assertIn("$stays->whereNull('room_id')->where('status', 'reserved')", method)

    def test_summary_and_housekeeping_details_are_permission_aware(self):
        method = CONTROLLER.split("public function tapeChart(Request $request)", 1)[1].split("public function tapeDrop", 1)[0]
        self.assertIn("'arrivals' => $stays->filter", method)
        self.assertIn("'inHouse' => $stays->where('status', 'checked_in')->count()", method)
        self.assertIn("'departures' => $stays->filter", method)
        self.assertIn("'canViewHousekeeping' => $canViewHousekeeping", method)
        self.assertIn("@if($canViewHousekeeping)<span class=\"tape-chart-room-hk", VIEW)
        self.assertIn("housekeeping.manage", method)
        self.assertIn("housekeeping.attendant", method)

    def test_navigation_and_empty_cell_booking_creation_remain_available(self):
        self.assertIn("Previous week", VIEW)
        self.assertIn("Next week", VIEW)
        for days in (7, 14, 21, 30):
            self.assertIn(str(days), VIEW)
        self.assertIn("route('reservations.create'", VIEW)
        self.assertIn("can_do('reservations.create')", VIEW)
        self.assertIn("['out_of_order','out_of_service']", VIEW)
        self.assertIn("$day->gte($today)", VIEW)

    def test_reservation_links_and_drag_permissions_follow_user_access(self):
        method = CONTROLLER.split("public function tapeChart(Request $request)", 1)[1].split("public function tapeDrop", 1)[0]
        self.assertIn("$canDrag = can_do('frontdesk.roommove') || can_do('reservations.edit')", method)
        self.assertIn("$canMoveInHouse = can_do('frontdesk.roommove')", method)
        self.assertIn("'canViewReservation' => $canViewReservation", method)
        self.assertIn("@if($canViewReservation)<a class=\"bar tape-bar", VIEW)
        self.assertIn("$stay->status === 'checked_in' && $canMoveInHouse", VIEW)
        self.assertIn("$stay->status === 'reserved'", VIEW)
        self.assertIn("reservations.view", method)

    def test_drop_contract_keeps_route_permissions_and_safe_server_recheck(self):
        self.assertIn("Route::post('/tape-chart/drop', 'tapeDrop')->middleware('perm:frontdesk.roommove,reservations.edit')", ROUTES)
        handler = CONTROLLER.split("public function tapeDrop(Request $request)", 1)[1].split("public function arrivals", 1)[0]
        self.assertIn("StayService::moveRoom", handler)
        self.assertIn("StayService::placeStay", handler)
        self.assertIn("$request->expectsJson()", handler)
        self.assertIn("Only reserved or in-house stays can be moved.", handler)

    def test_chart_has_responsive_dark_print_and_accessible_states(self):
        for token in ("@media(max-width:700px)", "body.dark .tape-chart-hero", "@media print", "role=\"region\"", "aria-live=\"polite\"", "<caption class=\"sr-only\"", "tabindex=\"0\""):
            self.assertIn(token, VIEW)
        self.assertIn("prefers-reduced-motion", VIEW)
        self.assertIn("#166534", VIEW)
        self.assertIn("#b91c1c", VIEW)


if __name__ == "__main__":
    unittest.main(verbosity=2)
