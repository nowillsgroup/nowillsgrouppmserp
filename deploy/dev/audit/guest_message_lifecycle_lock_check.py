from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
SERVICE = (ROOT / "app/Services/GuestMessageService.php").read_text()


def method(name, next_marker):
    start = SERVICE.index(f"public static function {name}(")
    end = SERVICE.index(next_marker, start)
    return SERVICE[start:end]


class GuestMessageLifecycleLockTest(unittest.TestCase):
    def test_taking_message_revalidates_arrival_or_inhouse_stay_under_locks(self):
        body = method("take", "protected static function who(")
        reservation_lock = body.index("Reservation::whereKey($stay->reservation_id)->lockForUpdate()")
        stay_lock = body.index("ReservationRoom::whereKey($stay->id)->lockForUpdate()", reservation_lock)
        state = body.index("if (! in_array($stay->status, ['reserved', 'checked_in'], true))", stay_lock)
        create = body.index("GuestMessage::create(", state)
        self.assertLess(reservation_lock, stay_lock)
        self.assertLess(stay_lock, state)
        self.assertLess(state, create)

    def test_notification_rechecks_open_state_and_departure_before_sending(self):
        body = method("notify", "public static function deliver(")
        reservation_lock = body.index("Reservation::whereKey($staySnapshot->reservation_id)->lockForUpdate()")
        stay_lock = body.index("ReservationRoom::whereKey($staySnapshot->id)->lockForUpdate()", reservation_lock)
        lock = body.index("GuestMessage::whereKey($m->id)->lockForUpdate()", stay_lock)
        state = body.index("if (! $locked->isOpen())", lock)
        checkout = body.index("$stay?->status === 'checked_out'", state)
        send = body.index("MessageService::send(", checkout)
        save = body.index("$locked->forceFill(['status' => 'notified'", send)
        self.assertLess(reservation_lock, stay_lock)
        self.assertLess(stay_lock, lock)
        self.assertLess(lock, state)
        self.assertLess(state, checkout)
        self.assertLess(checkout, send)
        self.assertLess(send, save)

    def test_failed_notification_log_commits_before_error_is_raised(self):
        body = method("notify", "public static function deliver(")
        failed_send = body.index("if ($log->status !== 'sent')")
        defer_error = body.index("$sendError = 'Could not send: '.$log->error", failed_send)
        transaction_end = body.index("        });", defer_error)
        throw_error = body.index("if ($sendError !== null)", transaction_end)
        self.assertLess(failed_send, defer_error)
        self.assertLess(defer_error, transaction_end)
        self.assertLess(transaction_end, throw_error)

    def test_delivery_rechecks_open_state_under_message_lock(self):
        body = method("deliver", "public static function close(")
        lock = body.index("GuestMessage::whereKey($m->id)->lockForUpdate()")
        state = body.index("if (! $locked->isOpen())", lock)
        save = body.index("$locked->forceFill(['status' => 'delivered'", state)
        self.assertLess(lock, state)
        self.assertLess(state, save)

    def test_close_rechecks_open_state_under_message_lock(self):
        body = method("close", "/** guest opened the portal")
        lock = body.index("GuestMessage::whereKey($m->id)->lockForUpdate()")
        state = body.index("if (! $locked->isOpen())", lock)
        save = body.index("$locked->forceFill(['status' => $status", state)
        self.assertLess(lock, state)
        self.assertLess(state, save)

    def test_portal_read_serializes_message_delivery_updates(self):
        body = method("portalRead", "/** check-in:")
        query = body.index("whereIn('status', GuestMessage::OPEN)")
        lock = body.index("->orderBy('id')->lockForUpdate()->get()", query)
        update = body.index("$m->save()", lock)
        self.assertLess(query, lock)
        self.assertLess(lock, update)

    def test_checkout_lapses_only_open_messages_under_row_locks(self):
        body = method("onCheckOut", "public static function openFor(")
        lock = body.index("->orderBy('id')->lockForUpdate()->get()")
        state = body.index("if ($m->isOpen() && ! $m->isParcel())", lock)
        cancel = body.index("'status' => 'cancelled'", state)
        self.assertIn("->where('kind', 'message')", body)
        self.assertLess(lock, state)
        self.assertLess(state, cancel)


if __name__ == "__main__":
    unittest.main(verbosity=2)
