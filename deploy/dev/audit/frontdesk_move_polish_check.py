#!/usr/bin/env python3
"""Static regression audit for the Front Desk room-move and stay-amendment page."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
VIEW = (ROOT / "resources/views/frontdesk/move.blade.php").read_text()
CONTROLLER = (ROOT / "app/Http/Controllers/FrontDeskController.php").read_text()
STAY_SERVICE = (ROOT / "app/Services/StayService.php").read_text()
ROUTES = (ROOT / "routes/web.php").read_text()


class FrontDeskMovePolishTest(unittest.TestCase):
    def test_actual_stay_context_and_current_operational_summary_render(self):
        for marker in (
            'aria-label="Room move and stay amendment overview"',
            'Room move &amp; stay amendment', '{{ $guestName }}',
            'aria-label="Current stay dates"', 'Current room',
            'Eligible vacant rooms through', 'Scheduled room nights',
            'Rate per night', 'roommove-history-table',
            "'canViewReservation' => can_do('reservations.view')",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW + CONTROLLER)

    def test_room_move_choices_use_the_remaining_stay_window_and_show_a_safe_preview(self):
        form = CONTROLLER[CONTROLLER.index("public function moveForm"):CONTROLLER.index("public function move(Request")]
        for marker in (
            "abort_unless($stay->status === 'checked_in', 404)",
            "$moveUntil = $stay->departure_date->gt($businessDate) ? $stay->departure_date : $businessDate->copy()->addDay()",
            "AvailabilityService::freeRooms($businessDate, $moveUntil, null, $stay->id)",
            "->where('status', 'vacant')->values()",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, form)
        for marker in (
            'id="moveToRoom"', 'id="moveRoomPreview"', 'data-room-type=',
            'data-base-rate=', 'base rate is only a reference',
            'preview.replaceChildren(heading, document.createTextNode(',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)
        self.assertNotIn('preview.innerHTML', VIEW)

    def test_reservation_and_front_desk_navigation_follow_permissions(self):
        for marker in (
            "@if($canViewReservation)<a href=\"{{ route('reservations.show', $stay->reservation_id) }}\"",
            "@elseif(can_do('frontdesk.view'))<a href=\"{{ route('frontdesk.inhouse') }}\"",
            "@if(can_do('frontdesk.view'))<a class=\"btn btn-soft\" href=\"{{ route('frontdesk.inhouse') }}\"",
            "@if(can_do('frontdesk.checkout'))<a class=\"btn btn-warning\" href=\"{{ route('frontdesk.checkout', $stay) }}\"",
            "if (can_do('reservations.view')) {\n            return redirect()->route('reservations.show'",
            "if (can_do('frontdesk.view')) {\n            return redirect()->route('frontdesk.inhouse'",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW + CONTROLLER)
        self.assertIn("Route::middleware('perm:frontdesk.roommove')->group(function ()", ROUTES)

    def test_stay_amendments_use_the_serialized_date_change_service_and_report_night_audit_behavior(self):
        amend = CONTROLLER[CONTROLLER.index("public function amend(Request"):CONTROLLER.index("public function grc(")]
        self.assertIn("StayService::changeDates($stay, $stay->arrival_date, $newDeparture)", amend)
        self.assertIn("catch (\\RuntimeException $e)", amend)
        self.assertIn("$stay->fresh()->update(['rate' => $newRate])", amend)
        self.assertIn("ActivityLogger::log('frontdesk', 'amend_rate'", amend)
        self.assertIn("'departure_date' => 'required|date|after:'.bdate()->toDateString()", amend)
        self.assertIn("public static function changeDates(ReservationRoom $stay", STAY_SERVICE)

    def test_move_history_and_form_controls_are_accessible_and_responsive(self):
        for marker in (
            'id="roomMoveForm"', 'id="amendStayForm"',
            'for="moveToRoom"', 'for="moveNewRate"', 'for="moveReason"',
            'for="amendDeparture"', 'for="amendRate"',
            'aria-describedby="moveRoomHint"', 'aria-live="polite"',
            'role="alert"', '@empty', 'scope="col">From',
            '@media(max-width:1050px)', '@media(max-width:650px)',
            '@media(prefers-reduced-motion:reduce)', '@media print',
            'body.dark .roommove-hero', 'overflow-x:auto',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW)

    def test_move_and_amend_forms_keep_their_existing_post_routes_and_protections(self):
        for marker in (
            "route('frontdesk.move.store', $stay)", "route('frontdesk.amend', $stay)",
            'data-confirm="Move this checked-in guest',
            'data-confirm="Save the new departure date or nightly rate?',
            'The server rechecks vacancy, overlapping stays, leases and room outages',
            "catch (\\RuntimeException $e)",
            "StayService::moveRoom($stay, (int) $data['to_room_id']",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, VIEW + CONTROLLER)


if __name__ == "__main__":
    unittest.main(verbosity=2)
