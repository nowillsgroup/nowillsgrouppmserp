from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[3]
SERVICE = (ROOT / "app/Services/TraceService.php").read_text()
CONTROLLER = (ROOT / "app/Http/Controllers/FrontDeskOpsController.php").read_text()


def method(source, name):
    match = re.search(rf"(?:public|protected) static function {name}\(", source)
    if not match:
        match = re.search(rf"public function {name}\(", source)
    start = match.start()
    end_match = re.search(r"\n    (?:public|protected) static function |\n    public function ", source[start + 1 :])
    end = start + 1 + end_match.start() if end_match else len(source)
    return source[start:end]


class FrontDeskTraceLifecycleLockTest(unittest.TestCase):
    def test_trace_creation_locks_reservation_then_stay_and_rechecks_state_and_date(self):
        body = method(SERVICE, "create")
        tx = body.index("return DB::transaction(")
        reservation = body.index("Reservation::whereKey($snapshot->reservation_id)->lockForUpdate()", tx)
        stay = body.index("ReservationRoom::whereKey($snapshot->id)->lockForUpdate()", reservation)
        status = body.index("if ($res && in_array($res->status", stay)
        date = body.index("if ($date->lt(bdate()->copy()->startOfDay()))", status)
        create = body.index("$t = Trace::create(", date)
        self.assertLess(tx, reservation)
        self.assertLess(reservation, stay)
        self.assertLess(stay, status)
        self.assertLess(status, date)
        self.assertLess(date, create)

    def test_completion_rechecks_open_state_under_trace_mutex(self):
        body = method(SERVICE, "complete")
        lock = body.index("Trace::whereKey($t->id)->lockForUpdate()->firstOrFail()")
        state = body.index("if (! $locked->isOpen())", lock)
        update = body.index("$locked->forceFill(['status' => 'done'", state)
        self.assertLess(lock, state)
        self.assertLess(state, update)

    def test_cancellation_rechecks_open_state_and_logs_the_reason(self):
        body = method(SERVICE, "cancel")
        lock = body.index("Trace::whereKey($t->id)->lockForUpdate()->firstOrFail()")
        state = body.index("if (! $locked->isOpen())", lock)
        update = body.index("$locked->forceFill(['status' => 'cancelled'", state)
        activity = body.index("ActivityLogger::log('traces', 'cancel'", update)
        self.assertLess(lock, state)
        self.assertLess(state, update)
        self.assertLess(update, activity)

    def test_carry_forward_rechecks_open_state_and_date_under_trace_mutex(self):
        body = method(SERVICE, "carryForward")
        ids = body.index("->orderBy('id')->pluck('id')")
        lock = body.index("Trace::whereKey($id)->lockForUpdate()->first()", ids)
        state = body.index("if (! $trace || $trace->status !== 'open'", lock)
        date = body.index("$trace->trace_date->toDateString() > $closingDate", state)
        update = body.index("$trace->forceFill(['carried_from'", date)
        self.assertLess(ids, lock)
        self.assertLess(lock, state)
        self.assertLess(state, date)
        self.assertLess(date, update)

    def test_controller_uses_service_transitions_for_trace_actions(self):
        self.assertIn("TraceService::complete($trace", CONTROLLER)
        self.assertIn("TraceService::cancel($trace", CONTROLLER)


if __name__ == "__main__":
    unittest.main(verbosity=2)
