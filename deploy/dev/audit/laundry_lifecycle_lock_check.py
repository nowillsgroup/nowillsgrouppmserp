from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
SERVICE = (ROOT / "app/Services/LaundryService.php").read_text()
CONTROLLER = (ROOT / "app/Http/Controllers/LaundryController.php").read_text()


def method(source, name, next_marker):
    start = source.index(f"public static function {name}(")
    end = source.index(next_marker, start)
    return source[start:end]


class LaundryLifecycleLockTest(unittest.TestCase):
    def test_guest_order_creation_revalidates_stay_before_creating_order(self):
        body = method(SERVICE, "create", "/**\n     * Replace the order's items.")
        reservation_lock = body.index("Reservation::whereKey($stay->reservation_id)->lockForUpdate()")
        stay_lock = body.index("ReservationRoom::whereKey($stay->id)->lockForUpdate()", reservation_lock)
        state = body.index("if ($stay->status !== 'checked_in' || ! $stay->room_id)", stay_lock)
        create = body.index("LaundryOrder::create(", state)
        self.assertLess(reservation_lock, stay_lock)
        self.assertLess(stay_lock, state)
        self.assertLess(state, create)

    def test_line_and_speed_changes_share_order_mutex_with_lifecycle(self):
        body = method(SERVICE, "setLines", "public static function markReady(")
        lock = body.index("LaundryOrder::whereKey($o->id)->lockForUpdate()")
        state = body.index("if (! $locked->isOpen())", lock)
        speed = body.index("if ($speed !== null && $speed !== $locked->speed)", state)
        delete_lines = body.index("$locked->lines()->delete()", speed)
        save = body.index("$locked->forceFill(['subtotal'", delete_lines)
        self.assertLess(lock, state)
        self.assertLess(state, speed)
        self.assertLess(speed, delete_lines)
        self.assertLess(delete_lines, save)
        self.assertIn("$nextStatus = $clean ? 'processing' : 'collected'", body)
        self.assertIn("'ready_at' => null", body)
        controller = CONTROLLER[CONTROLLER.index("public function items("):CONTROLLER.index("public function ready(")]
        self.assertNotIn("$lorder->forceFill(['speed'", controller)
        self.assertIn("LaundryService::setLines($lorder, $this->gridLines($request), true, $request->input('speed'))", controller)

    def test_ready_transition_rechecks_status_and_contents_under_order_lock(self):
        body = method(SERVICE, "markReady", "/**\n     * Hand the laundry back.")
        lock = body.index("LaundryOrder::whereKey($o->id)->lockForUpdate()")
        status = body.index("if (! in_array($locked->status, ['collected', 'processing'], true))", lock)
        pieces = body.index("if ($locked->pieces < 1)", status)
        save = body.index("$locked->forceFill(['status' => 'ready'", pieces)
        self.assertLess(lock, status)
        self.assertLess(status, pieces)
        self.assertLess(pieces, save)

    def test_delivery_rechecks_status_and_amount_before_charging(self):
        body = method(SERVICE, "deliver", "public static function cancel(")
        lock = body.index("LaundryOrder::whereKey($o->id)->lockForUpdate()")
        status = body.index("if (! in_array($o->status, ['processing', 'ready'], true))", lock)
        amount = body.index("if ((float) $o->total <= 0)", status)
        charge = body.index("FolioService::charge(", amount)
        save = body.index("'status' => 'delivered'", charge)
        self.assertLess(lock, status)
        self.assertLess(status, amount)
        self.assertLess(amount, charge)
        self.assertLess(charge, save)

    def test_cancellation_serializes_with_delivery_and_rechecks_open_status(self):
        body = method(SERVICE, "cancel", "/** Orders still with the laundry")
        lock = body.index("LaundryOrder::whereKey($o->id)->lockForUpdate()")
        state = body.index("if (! $locked->isOpen())", lock)
        save = body.index("$locked->forceFill(['status' => 'cancelled'", state)
        self.assertLess(lock, state)
        self.assertLess(state, save)


if __name__ == "__main__":
    unittest.main(verbosity=2)
