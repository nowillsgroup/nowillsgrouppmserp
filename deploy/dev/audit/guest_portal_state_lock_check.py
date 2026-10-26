#!/usr/bin/env python3
"""Static regression guard for portal writes racing with check-out/amendments.

Run with: python3 deploy/dev/audit/guest_portal_state_lock_check.py
This source-contract check complements runtime tests; PHP is unavailable in the
local audit workspace.
"""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
SERVICE = (ROOT / "app/Services/GuestPortalService.php").read_text()
CONCIERGE = (ROOT / "app/Services/ConciergeService.php").read_text()


class GuestPortalStateLockTest(unittest.TestCase):
    def test_guest_request_revalidates_locked_stay_state_before_writing(self):
        method = SERVICE[SERVICE.index("public static function raise("):SERVICE.index("protected static function link(")]
        self.assertIn("$stay = ReservationRoom::whereKey($stay->id)->lockForUpdate()->firstOrFail()", method)
        self.assertIn("$state = $stay->portalState()", method)
        self.assertIn("This stay is no longer accepting this request", method)

    def test_guest_request_transitions_serialize_and_recheck_current_state(self):
        method = SERVICE[SERVICE.index("public static function transition("):SERVICE.index("public static function syncFromLinked(")]
        self.assertIn("DB::transaction(function () use ($req, $action, $note)", method)
        self.assertIn("GuestRequest::whereKey($req->id)->lockForUpdate()->firstOrFail()", method)
        wake_lock = method.index("WakeupCall::whereKey($snapshot->linked_id)->lockForUpdate()")
        request_lock = method.index("GuestRequest::whereKey($req->id)->lockForUpdate()")
        self.assertLess(wake_lock, request_lock)
        self.assertLess(request_lock, method.index("if (! $to || ! in_array($req->status"))

    def test_dining_revalidates_locked_stay_state_before_creating_order(self):
        method = SERVICE[SERVICE.index("public static function orderDining("):SERVICE.index("public static function needsTherapist(")]
        self.assertIn("$stay = ReservationRoom::whereKey($stay->id)->lockForUpdate()->firstOrFail()", method)
        self.assertIn("$stay->portalState() !== 'inhouse'", method)
        self.assertLess(method.index("$stay->portalState() !== 'inhouse'", method.index("DB::transaction")), method.index("PosOrder::where"))

    def test_concierge_booking_revalidates_portal_stay_and_limit_under_lock(self):
        method = CONCIERGE[CONCIERGE.index("public static function book("):CONCIERGE.index("protected static function checkTiming(")]
        self.assertIn("ReservationRoom::with('guest', 'room', 'reservation.guest')->lockForUpdate()", method)
        self.assertIn("$stay->portalState()", method)
        self.assertIn("ActivityBooking::where('reservation_room_id', $stay->id)->whereIn('status', ActivityBooking::OPEN)->count() >= 6", method)
        self.assertLess(method.index("$stay->portalState()"), method.index("ActivityBooking::create("))

    def test_checkout_closes_only_still_open_requests_under_row_locks(self):
        method = SERVICE[SERVICE.index("public static function closeForStay("):SERVICE.index("public static function rate(")]
        self.assertIn("DB::transaction(function () use ($stay)", method)
        self.assertIn("whereIn('status', GuestRequest::OPEN)", method)
        self.assertIn("->orderBy('id')->lockForUpdate()->get()", method)

    def test_spa_revalidates_locked_stay_state_and_dates_before_booking(self):
        method = SERVICE[SERVICE.index("public static function bookSpa("):SERVICE.index("public const WELCOME_TEMPLATE")]
        transaction = method[method.index("DB::transaction"):]
        self.assertIn("$stay = ReservationRoom::whereKey($stay->id)->lockForUpdate()->firstOrFail()", transaction)
        self.assertIn("$stay->portalState() !== 'inhouse'", transaction)
        self.assertIn("$date > $stay->departure_date->toDateString()", transaction)
        self.assertLess(transaction.index("$stay->portalState() !== 'inhouse'"), transaction.index("SpaBookingService::save"))


if __name__ == "__main__":
    unittest.main(verbosity=2)
