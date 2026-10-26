#!/usr/bin/env python3
"""Static regression guard for transport completion and room-charge idempotency.

Run with: python3 deploy/dev/audit/transport_billing_idempotency_check.py
"""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
SERVICE = (ROOT / "app/Services/TransportService.php").read_text()


class TransportBillingIdempotencyTest(unittest.TestCase):
    def test_dispatch_assignments_and_assigned_trip_edits_share_a_clash_lock(self):
        assign = SERVICE[SERVICE.index("public static function assign("):SERVICE.index("public static function unassign(")]
        save = SERVICE[SERVICE.index("public static function save("):SERVICE.index("public static function portalRequest(")]
        self.assertLess(assign.index("self::lockDispatchBoard()"), assign.index("self::clashes("))
        self.assertIn("TransportTrip::whereKey($t->id)->lockForUpdate()", assign)
        self.assertLess(save.index("self::lockDispatchBoard()"), save.index("self::clashes("))

    def test_transport_lifecycle_transitions_recheck_state_under_row_lock(self):
        boundaries = [
            ("public static function confirm(", "protected static function lockDispatchBoard(", "if ($t->status !== 'requested')"),
            ("public static function unassign(", "// ───────────────────────── on the road", "if ($t->status !== 'assigned')"),
            ("public static function start(", "public static function onboard(", "if ($t->status !== 'assigned')"),
            ("public static function onboard(", "/**\n     * Finish the trip:", "if ($t->status !== 'en_route')"),
            ("public static function noShow(", "public static function cancel(", "if (! in_array($t->status, ['confirmed', 'assigned', 'en_route', 'onboard'], true))"),
            ("public static function cancel(", "protected static function voidCharge(", "if (! $t->isPlanned())"),
        ]
        for start, end, guard in boundaries:
            method = SERVICE[SERVICE.index(start):SERVICE.index(end)]
            lock = method.index("whereKey($t->id)->lockForUpdate()")
            self.assertLess(lock, method.index(guard), start)

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
        self.assertIn("ReservationRoom::whereKey($t->reservation_room_id)->first()", transaction)
        self.assertIn("'folio_transaction_id' => $tx->id", transaction)


if __name__ == "__main__":
    unittest.main(verbosity=2)
