from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
CONTROLLER = (ROOT / "app/Http/Controllers/FrontDeskController.php").read_text()
SERVICE = (ROOT / "app/Services/StayService.php").read_text()


class FrontDeskCheckInLockTest(unittest.TestCase):
    def test_checkin_controller_obeys_reservation_then_stay_lock_order_before_writes(self):
        start = CONTROLLER.index("public function checkIn(Request $request")
        end = CONTROLLER.index("public function checkOutForm(", start)
        body = CONTROLLER[start:end]
        reservation_lock = body.index("Reservation::whereKey($stay->reservation_id)->lockForUpdate()")
        stay_lock = body.index("ReservationRoom::whereKey($stay->id)->lockForUpdate()", reservation_lock)
        guest_write = body.index("$reservation->guest->fill(", stay_lock)
        rate_write = body.index("$lockedStay->update(['rate' => $data['rate']])", guest_write)
        lifecycle = body.index("StayService::checkIn($lockedStay", rate_write)
        self.assertLess(reservation_lock, stay_lock)
        self.assertLess(stay_lock, guest_write)
        self.assertLess(guest_write, rate_write)
        self.assertLess(rate_write, lifecycle)

    def test_checkin_service_rechecks_current_stay_after_lock(self):
        start = SERVICE.index("protected static function doCheckIn(")
        end = SERVICE.index("public static function postedNights(", start)
        body = SERVICE[start:end]
        reservation_lock = body.index("Reservation::whereKey($stay->reservation_id)->lockForUpdate()")
        stay_lock = body.index("ReservationRoom::whereKey($stay->id)->lockForUpdate()", reservation_lock)
        status_check = body.index("if ($stay->status !== 'reserved')", stay_lock)
        self.assertLess(reservation_lock, stay_lock)
        self.assertLess(stay_lock, status_check)

    def test_checkout_locks_stay_and_room_before_folio_postings(self):
        start = CONTROLLER.index("public function checkOut(Request $request")
        end = CONTROLLER.index("public function prepareBill(", start)
        body = CONTROLLER[start:end]
        reservation_lock = body.index("Reservation::whereKey($stay->reservation_id)->lockForUpdate()")
        stay_lock = body.index("ReservationRoom::whereKey($stay->id)->lockForUpdate()", reservation_lock)
        room_lock = body.index("Room::whereKey($lockedStay->room_id)->lockForUpdate()", stay_lock)
        state_check = body.index("if ($lockedStay->status !== 'checked_in')", room_lock)
        night_posting = body.index("StayService::postPendingNights($stay, bdate())", state_check)
        settlement = body.index("FolioService::payment(", night_posting)
        self.assertLess(reservation_lock, stay_lock)
        self.assertLess(stay_lock, room_lock)
        self.assertLess(room_lock, state_check)
        self.assertLess(state_check, night_posting)
        self.assertLess(night_posting, settlement)

    def test_tape_chart_assignments_use_serialized_stay_services(self):
        start = CONTROLLER.index("public function tapeDrop(")
        end = CONTROLLER.index("protected function stayList(", start)
        body = CONTROLLER[start:end]
        self.assertIn("StayService::moveRoom($stay", body)
        self.assertIn("StayService::placeStay($stay", body)
        self.assertNotIn("$stay->update(['room_id'", body)

    def test_reserved_room_assignment_revalidates_under_shared_locks(self):
        start = SERVICE.index("public static function placeStay(")
        end = SERVICE.rfind("\n}")
        body = SERVICE[start:end]
        reservation_lock = body.index("Reservation::whereKey($stay->reservation_id)->lockForUpdate()")
        stay_lock = body.index("ReservationRoom::whereKey($stay->id)->lockForUpdate()", reservation_lock)
        room_lock = body.index("Room::with('roomType')->whereKey($roomId)->lockForUpdate()", stay_lock)
        status_check = body.index("if (! in_array($stay->status", stay_lock)
        clash = body.index("AvailabilityService::blockingStays(", room_lock)
        save = body.index("$stay->update(['room_id'", clash)
        self.assertLess(reservation_lock, stay_lock)
        self.assertLess(stay_lock, status_check)
        self.assertLess(status_check, room_lock)
        self.assertLess(room_lock, clash)
        self.assertLess(clash, save)


if __name__ == "__main__":
    unittest.main(verbosity=2)
