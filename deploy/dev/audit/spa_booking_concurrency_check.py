#!/usr/bin/env python3
"""Static regression guard: serialize spa appointment slot checks and writes.

Run with: python3 deploy/dev/audit/spa_booking_concurrency_check.py
"""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
SERVICE = (ROOT / "app/Services/SpaBookingService.php").read_text()
PORTAL = (ROOT / "app/Http/Controllers/GuestPortalController.php").read_text()


class SpaBookingConcurrencyTest(unittest.TestCase):
    def test_slot_is_rechecked_under_shared_lock_before_create_or_update(self):
        method = SERVICE[SERVICE.index("public static function save("):SERVICE.index("public static function setStatus(")]
        transaction = method[method.index("DB::transaction"):]
        lock = transaction.index("Setting::where('key', 'spa_booking_lock')->lockForUpdate()")
        clash = transaction.index("$msg = self::clash(")
        write = transaction.index("SpaAppointment::create(")
        self.assertLess(lock, clash)
        self.assertLess(clash, write)
        self.assertIn("$appt = SpaAppointment::whereKey($appt->id)->lockForUpdate()->firstOrFail()", transaction)
        self.assertIn("$appt?->id", transaction[clash:write])

    def test_settlement_locks_the_appointment_before_charging_and_rechecks_status(self):
        method = SERVICE[SERVICE.index("public static function settle("):SERVICE.rindex("\n}")]
        transaction = method[method.index("DB::transaction"):]
        lock = transaction.index("SpaAppointment::with('service')->whereKey($appt->id)->lockForUpdate()")
        status = transaction.index("if (! $appt->isOpen())")
        charge = transaction.index("FolioService::charge(")
        self.assertLess(lock, status)
        self.assertLess(status, charge)
        self.assertIn("ReservationRoom::whereKey($appt->reservation_room_id)->lockForUpdate()", transaction)

    def test_portal_cancellation_rechecks_ownership_status_and_cutoff_under_lock(self):
        method = SERVICE[SERVICE.index("public static function cancelFromPortal("):SERVICE.index("public static function settle(")]
        self.assertIn("SpaAppointment::whereKey($appt->id)->lockForUpdate()->firstOrFail()", method)
        self.assertIn("(int) $appt->reservation_room_id !== $stayId", method)
        self.assertIn("->lt(now()->addHours(2))", method)
        self.assertIn("SpaBookingService::cancelFromPortal($a, $stay->id)", PORTAL)


if __name__ == "__main__":
    unittest.main(verbosity=2)
