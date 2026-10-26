#!/usr/bin/env python3
"""Static regression guard for transport completion and room-charge idempotency.

Run with: python3 deploy/dev/audit/transport_billing_idempotency_check.py
"""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
SERVICE = (ROOT / "app/Services/TransportService.php").read_text()


class TransportBillingIdempotencyTest(unittest.TestCase):
    def test_trip_completion_locks_and_revalidates_before_any_charge(self):
        method = SERVICE[SERVICE.index("public static function complete("):SERVICE.index("protected static function chargeText(")]
        transaction = method[method.index("DB::transaction"):]
        lock = transaction.index("TransportTrip::with('tariff')->whereKey($t->id)->lockForUpdate()")
        status = transaction.index("if (! in_array($t->status, ['assigned', 'en_route', 'onboard'], true))")
        charge = transaction.index("FolioService::charge(")
        self.assertLess(lock, status)
        self.assertLess(status, charge)
        self.assertIn("FolioService::payment(", transaction)

    def test_standalone_room_charge_rechecks_unbilled_state_under_trip_lock(self):
        method = SERVICE[SERVICE.index("public static function postCharge("):SERVICE.index("public static function pendingForStay(")]
        transaction = method[method.index("DB::transaction"):]
        lock = transaction.index("TransportTrip::whereKey($t->id)->lockForUpdate()")
        guard = transaction.index("if (! $t->unbilled()")
        charge = transaction.index("FolioService::charge(")
        self.assertLess(lock, guard)
        self.assertLess(guard, charge)
        self.assertIn("ReservationRoom::whereKey($t->reservation_room_id)->lockForUpdate()", transaction)
        self.assertIn("'folio_transaction_id' => $tx->id", transaction)


if __name__ == "__main__":
    unittest.main(verbosity=2)
