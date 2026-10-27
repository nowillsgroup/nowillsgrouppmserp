from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[3]
SERVICE = (ROOT / "app/Services/GatePassService.php").read_text()
CONTROLLER = (ROOT / "app/Http/Controllers/GatePassController.php").read_text()


def method(source, name):
    match = re.search(rf"(?:public|protected) static function {name}\(", source)
    if not match:
        match = re.search(rf"public function {name}\(", source)
    start = match.start()
    end_match = re.search(r"\n    (?:public|protected) static function |\n    public function ", source[start + 1 :])
    end = start + 1 + end_match.start() if end_match else len(source)
    return source[start:end]


class GatePassLifecycleLockTest(unittest.TestCase):
    def test_asset_based_pass_creation_locks_assets_in_stable_order_and_rechecks_open_pass(self):
        body = method(SERVICE, "create")
        transaction = body.index("return DB::transaction(")
        sort = body.index("sort($assetIds, SORT_NUMERIC)", transaction)
        lock = body.index("Asset::whereIn('id', $assetIds)->orderBy('id')->lockForUpdate()", sort)
        open_pass = body.index("self::assetOnOpenPass($asset->id)", lock)
        create = body.index("$p = GatePass::create(", open_pass)
        self.assertLess(transaction, sort)
        self.assertLess(sort, lock)
        self.assertLess(lock, open_pass)
        self.assertLess(open_pass, create)

    def test_pass_mutex_reloads_current_row_under_lock(self):
        body = method(SERVICE, "lockPass")
        self.assertIn("GatePass::whereKey($pass->id)->lockForUpdate()->firstOrFail()", body)

    def test_approval_rechecks_pending_state_under_pass_mutex(self):
        body = method(SERVICE, "approve")
        lock = body.index("self::lockPass($p)")
        state = body.index("if ($locked->status !== 'pending')", lock)
        update = body.index("$locked->forceFill(['status' => 'approved'", state)
        self.assertLess(lock, state)
        self.assertLess(state, update)

    def test_rejection_and_cancellation_recheck_state_under_pass_mutex(self):
        reject = method(SERVICE, "reject")
        reject_lock = reject.index("self::lockPass($p)")
        reject_state = reject.index("if ($locked->status !== 'pending')", reject_lock)
        reject_update = reject.index("$locked->forceFill(['status' => 'rejected'", reject_state)
        self.assertLess(reject_lock, reject_state)
        self.assertLess(reject_state, reject_update)

        cancel = method(SERVICE, "cancel")
        cancel_lock = cancel.index("self::lockPass($p)")
        cancel_state = cancel.index("if (! in_array($locked->status, ['pending', 'approved'], true))", cancel_lock)
        cancel_update = cancel.index("$locked->forceFill(['status' => 'cancelled'", cancel_state)
        self.assertLess(cancel_lock, cancel_state)
        self.assertLess(cancel_state, cancel_update)

    def test_gate_exit_rechecks_approval_before_recording_departure(self):
        body = method(SERVICE, "markOut")
        lock = body.index("self::lockPass($p)")
        state = body.index("if ($locked->status !== 'approved')", lock)
        departure = body.index("$locked->forceFill($upd)->save()", state)
        self.assertLess(lock, state)
        self.assertLess(state, departure)

    def test_returns_serialize_pass_items_and_asset_reinstatement(self):
        body = method(SERVICE, "receive")
        pass_lock = body.index("self::lockPass($p)")
        state = body.index("if (! in_array($locked->status, GatePass::AWAY, true)", pass_lock)
        item_lock = body.index("$locked->items()->orderBy('id')->lockForUpdate()", state)
        asset_lock = body.index("Asset::whereIn('id', $assetIds)->orderBy('id')->lockForUpdate()", item_lock)
        update = body.index("$it->forceFill(['returned_qty'", asset_lock)
        complete = body.index("$locked->forceFill($left <= 0 ? ['status' => 'returned'", update)
        self.assertLess(pass_lock, state)
        self.assertLess(state, item_lock)
        self.assertLess(item_lock, asset_lock)
        self.assertLess(asset_lock, update)
        self.assertLess(update, complete)

    def test_short_close_and_date_extension_recheck_open_status(self):
        close = method(SERVICE, "close")
        close_lock = close.index("self::lockPass($p)")
        close_state = close.index("if (! in_array($locked->status, GatePass::AWAY, true))", close_lock)
        close_update = close.index("$locked->forceFill(['status' => 'closed'", close_state)
        self.assertLess(close_lock, close_state)
        self.assertLess(close_state, close_update)

        extend = method(SERVICE, "extend")
        extend_lock = extend.index("self::lockPass($p)")
        extend_state = extend.index("if ($locked->type !== 'returnable'", extend_lock)
        extend_update = extend.index("$locked->forceFill(['expected_return_date'", extend_state)
        self.assertLess(extend_lock, extend_state)
        self.assertLess(extend_state, extend_update)

    def test_overdue_alert_rechecks_and_marks_pass_under_mutex(self):
        body = method(SERVICE, "alertOverdue")
        lock = body.index("GatePass::whereKey($id)->lockForUpdate()")
        check = body.index("if (! $p || $p->type !== 'returnable'", lock)
        alert = body.index("LogBookService::system(", check)
        mark = body.index("$p->forceFill(['overdue_alerted_on' => $bd])", alert)
        self.assertLess(lock, check)
        self.assertLess(check, alert)
        self.assertLess(alert, mark)

    def test_controller_uses_fresh_pass_after_mutations(self):
        for action in ("approve", "reject", "cancel", "markOut", "close", "extend"):
            self.assertIn(f"$gatepass = GatePassService::{action}(", CONTROLLER)
        self.assertIn("$gatepass = $gatepass->fresh();", CONTROLLER)


if __name__ == "__main__":
    unittest.main(verbosity=2)
