from pathlib import Path
import unittest

SERVICE = (Path(__file__).resolve().parents[2] / "../app/Services/ConciergeService.php").resolve().read_text()


def method(name, next_name):
    start = SERVICE.index(f"public static function {name}(")
    end = SERVICE.index(f"public static function {next_name}(", start)
    return SERVICE[start:end]


class ConciergeChargeIdempotencyTest(unittest.TestCase):
    def test_confirmation_locks_and_rechecks_booking_before_charging(self):
        body = method("confirm", "postCharge")
        departure_lock = body.index("ActivityDeparture::whereKey($waitlistDepartureId)->lockForUpdate()")
        booking_lock = body.index("ActivityBooking::whereKey($b->id)->lockForUpdate()->firstOrFail()")
        status_check = body.index("if (! in_array($b->status, ['requested', 'waitlisted'], true))", booking_lock)
        charge = body.index("self::postCharge($b)")
        self.assertIn("DB::transaction(function ()", body)
        self.assertLess(departure_lock, booking_lock)
        self.assertLess(booking_lock, status_check)
        self.assertLess(status_check, charge)

    def test_cancellation_uses_departure_then_booking_lock_before_waitlist_promotion(self):
        body = method("cancel", "promoteWaitlist")
        departure_lock = body.index("ActivityDeparture::whereKey($departureId)->lockForUpdate()")
        booking_lock = body.index("ActivityBooking::whereKey($b->id)->lockForUpdate()->firstOrFail()")
        stale_status_check = body.index("if (! $b->isOpen())", booking_lock)
        cancel_write = body.index("'status' => 'cancelled'", stale_status_check)
        promote = body.index("self::promoteWaitlist($dep)")
        self.assertLess(departure_lock, booking_lock)
        self.assertLess(booking_lock, stale_status_check)
        self.assertLess(cancel_write, promote)

    def test_folio_charge_uses_booking_lock_as_idempotency_mutex(self):
        start = SERVICE.index("public static function postCharge(")
        end = SERVICE.index("protected static function voidCharge(", start)
        body = SERVICE[start:end]
        lock = body.index("ActivityBooking::whereKey($b->id)->lockForUpdate()->firstOrFail()")
        duplicate_check = body.index("if ($locked->folio_transaction_id)")
        charge = body.index("FolioService::charge(")
        save_link = body.index("'folio_transaction_id' => $tx->id")
        self.assertLess(lock, duplicate_check)
        self.assertLess(duplicate_check, charge)
        self.assertLess(charge, save_link)


if __name__ == "__main__":
    unittest.main(verbosity=2)
