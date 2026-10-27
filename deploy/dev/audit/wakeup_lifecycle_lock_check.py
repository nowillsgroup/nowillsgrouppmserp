from pathlib import Path
import unittest

SERVICE = (Path(__file__).resolve().parents[2] / "../app/Services/WakeupService.php").resolve().read_text()


def method(name, next_name):
    start = SERVICE.index(f"public static function {name}(")
    end = SERVICE.index(f"public static function {next_name}(", start)
    return SERVICE[start:end]


class WakeupLifecycleLockTest(unittest.TestCase):
    def test_scheduling_revalidates_locked_stay_before_creating_call(self):
        body = method("schedule", "attempt")
        lock = body.index("ReservationRoom::whereKey($stay->id)->lockForUpdate()->firstOrFail()")
        status = body.index("if (! in_array($stay->status, ['reserved', 'checked_in'], true))", lock)
        clash = body.index("$clash = WakeupCall::where('reservation_room_id'", status)
        create = body.index("WakeupCall::create(", clash)
        self.assertIn("DB::transaction(function () use ($stay, $at, $daily", body)
        self.assertLess(lock, status)
        self.assertLess(status, clash)
        self.assertLess(clash, create)

    def test_attempt_rechecks_call_status_after_row_lock(self):
        body = method("attempt", "retryAt")
        lock = body.index("WakeupCall::whereKey($c->id)->lockForUpdate()->firstOrFail()")
        status = body.index("if (! $c->isScheduled())", lock)
        attempt = body.index("WakeupAttempt::create(", status)
        self.assertLess(lock, status)
        self.assertLess(status, attempt)

    def test_cancel_rechecks_under_call_lock_before_closing_request(self):
        body = method("cancel", "rollNext")
        lock = body.index("WakeupCall::whereKey($c->id)->lockForUpdate()->firstOrFail()")
        status = body.index("if (! $c->isScheduled())", lock)
        save = body.index("$c->forceFill(['status' => 'cancelled',", status)
        close_request = body.index("self::closeRequest($c, 'cancelled'", save)
        self.assertLess(lock, status)
        self.assertLess(status, save)
        self.assertLess(save, close_request)

    def test_departure_cleanup_locks_and_rechecks_each_call(self):
        body = method("onCheckOut", "onRoomMove")
        lock = body.index("WakeupCall::whereKey($id)->lockForUpdate()->first()")
        status = body.index("if (! $call || ! $call->isScheduled())", lock)
        save = body.index("$call->forceFill(['status' => 'cancelled',", status)
        self.assertIn("DB::transaction(function () use ($id)", body)
        self.assertLess(lock, status)
        self.assertLess(status, save)

    def test_late_escalation_claim_is_serialized_before_alert_side_effects(self):
        body = method("escalate", "onCheckOut")
        lock = body.index("WakeupCall::whereKey($c->id)->lockForUpdate()->first()")
        late_status = body.index("if ($locked->status !== 'scheduled'", lock)
        claim = body.index("$locked->forceFill(['escalated_at' => now()])", late_status)
        log = body.index("LogBookService::system(", claim)
        self.assertLess(lock, late_status)
        self.assertLess(late_status, claim)
        self.assertLess(claim, log)

    def test_scheduler_missed_transition_rechecks_under_call_lock(self):
        start = SERVICE.index("public static function run(): array")
        body = SERVICE[start:]
        lock = body.index("WakeupCall::whereKey($id)->lockForUpdate()->first()")
        state = body.index("if (! $locked || ! $locked->isScheduled()", lock)
        save = body.index("$locked->forceFill(['status' => $locked->attempts", state)
        close = body.index("self::closeRequest($locked", save)
        roll = body.index("self::rollNext($call)", close)
        self.assertLess(lock, state)
        self.assertLess(state, save)
        self.assertLess(close, roll)


if __name__ == "__main__":
    unittest.main(verbosity=2)
