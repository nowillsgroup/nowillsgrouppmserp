from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
SERVICE = (ROOT / "app/Services/LostFoundService.php").read_text()
CONTROLLER = (ROOT / "app/Http/Controllers/LostFoundController.php").read_text()


def method(name, next_marker):
    start = SERVICE.index(f"public static function {name}(")
    end = SERVICE.index(next_marker, start)
    return SERVICE[start:end]


class LostFoundLifecycleLockTest(unittest.TestCase):
    def test_edit_rechecks_stored_state_and_keeps_value_threshold_protection(self):
        body = method("update", "/** hand the item back")
        lock = body.index("LostFoundItem::whereKey($item->id)->lockForUpdate()")
        state = body.index("if (! $locked->isStored())", lock)
        threshold = body.index("$valuable = ! empty($d['valuable'])", state)
        downgrade = body.index("if ($locked->valuable && ! $valuable && ! can_do('lostfound.release'))", threshold)
        save = body.index("$locked->save()", downgrade)
        self.assertIn("$value >= $threshold", body[threshold:downgrade])
        self.assertLess(lock, state)
        self.assertLess(state, threshold)
        self.assertLess(threshold, downgrade)
        self.assertLess(downgrade, save)

    def test_return_serializes_item_and_closes_related_reports_after_item_commit(self):
        body = method("returnItem", "/** dispose after the retention period")
        lock = body.index("LostFoundItem::whereKey($item->id)->lockForUpdate()")
        state = body.index("if (! $locked->isStored())", lock)
        value_check = body.index("if ($locked->valuable)", state)
        save = body.index("'status' => 'returned'", value_check)
        transaction_end = body.index("// Close related reports after releasing the item mutex.", save)
        close_reports = body.index("LostReport::where('lost_found_item_id', $returned->id)", transaction_end)
        self.assertLess(lock, state)
        self.assertLess(state, value_check)
        self.assertLess(value_check, save)
        self.assertLess(save, transaction_end)
        self.assertLess(transaction_end, close_reports)
        self.assertIn("$item = LostFoundService::returnItem($item, $data)", CONTROLLER)

    def test_disposal_rechecks_storage_retention_and_value_rules_under_lock(self):
        body = method("dispose", "// ───────────────────────── loss reports")
        lock = body.index("LostFoundItem::whereKey($item->id)->lockForUpdate()")
        stored = body.index("if (! $locked->isStored())", lock)
        due = body.index("if ($method !== 'police' && ! $locked->isDue())", stored)
        category = body.index("if ($locked->category === 'documents'", due)
        witness = body.index("if ($locked->valuable)", category)
        save = body.index("'status' => 'disposed'", witness)
        self.assertLess(lock, stored)
        self.assertLess(stored, due)
        self.assertLess(due, category)
        self.assertLess(category, witness)
        self.assertLess(witness, save)

    def test_report_matching_locks_report_then_item_and_rechecks_both(self):
        body = method("match", "public static function closeReport(")
        report_lock = body.index("LostReport::whereKey($r->id)->lockForUpdate()")
        report_state = body.index("if ($report->status !== 'open')", report_lock)
        item_lock = body.index("LostFoundItem::whereKey($item->id)->lockForUpdate()", report_state)
        item_state = body.index("if (! $found->isStored())", item_lock)
        duplicate = body.index("where('status', 'matched')", item_state)
        update_report = body.index("$report->forceFill(['status' => 'matched'", duplicate)
        update_item = body.index("$found->forceFill(['lost_report_id'", update_report)
        self.assertLess(report_lock, report_state)
        self.assertLess(report_state, item_lock)
        self.assertLess(item_lock, item_state)
        self.assertLess(item_state, duplicate)
        self.assertLess(duplicate, update_report)
        self.assertLess(update_report, update_item)

    def test_closing_matched_report_uses_the_same_report_then_item_lock_order(self):
        body = method("closeReport", "\n    }\n}")
        report_lock = body.index("LostReport::whereKey($r->id)->lockForUpdate()")
        state = body.index("if ($locked->status === 'closed')", report_lock)
        item_lock = body.index("LostFoundItem::whereKey($locked->lost_found_item_id)->lockForUpdate()", state)
        save_report = body.index("$locked->forceFill(['status' => 'closed'", item_lock)
        self.assertLess(report_lock, state)
        self.assertLess(state, item_lock)
        self.assertLess(item_lock, save_report)


if __name__ == "__main__":
    unittest.main(verbosity=2)
