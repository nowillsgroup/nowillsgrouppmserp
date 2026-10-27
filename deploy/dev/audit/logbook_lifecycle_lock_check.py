from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[3]
SERVICE = (ROOT / "app/Services/LogBookService.php").read_text()
CONTROLLER = (ROOT / "app/Http/Controllers/FrontDeskOpsController.php").read_text()


def method(source, name):
    match = re.search(r"(?:public|protected) static function "+re.escape(name)+r"\(", source)
    if not match:
        match = re.search(r"public function "+re.escape(name)+r"\(", source)
    start = match.start()
    end_match = re.search(r"\n    (?:public|protected) static function |\n    public function ", source[start + 1 :])
    end = start + 1 + end_match.start() if end_match else len(source)
    return source[start:end]


class LogbookLifecycleLockTest(unittest.TestCase):
    def test_entry_mutex_reloads_current_row(self):
        body = method(SERVICE, "lockEntry")
        self.assertIn("LogEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail()", body)

    def test_comments_and_read_acknowledgement_commit_under_entry_lock(self):
        body = method(SERVICE, "comment")
        lock = body.index("self::lockEntry($e)")
        comment = body.index("LogEntryComment::create(", lock)
        read = body.index("self::markRead(collect([$locked])", comment)
        touch = body.index("$locked->touch()", read)
        self.assertLess(lock, comment)
        self.assertLess(comment, read)
        self.assertLess(read, touch)

    def test_resolve_rechecks_unresolved_state_under_mutex(self):
        body = method(SERVICE, "resolve")
        lock = body.index("self::lockEntry($e)")
        state = body.index("if ($locked->status === 'resolved')", lock)
        update = body.index("$locked->forceFill(['status' => 'resolved'", state)
        self.assertLess(lock, state)
        self.assertLess(state, update)

    def test_reopen_rechecks_resolved_state_under_mutex(self):
        body = method(SERVICE, "reopen")
        lock = body.index("self::lockEntry($e)")
        state = body.index("if ($locked->status !== 'resolved')", lock)
        update = body.index("$locked->forceFill(['status' => 'open'", state)
        self.assertLess(lock, state)
        self.assertLess(state, update)

    def test_pin_updates_are_serialized(self):
        body = method(SERVICE, "pin")
        lock = body.index("self::lockEntry($e)")
        update = body.index("$locked->forceFill(['pinned' => $on", lock)
        self.assertLess(lock, update)

    def test_controller_uses_fresh_rows_from_status_mutators(self):
        self.assertIn("$logentry = LogBookService::resolve(", CONTROLLER)
        self.assertIn("$logentry = LogBookService::reopen(", CONTROLLER)
        self.assertIn("$logentry = LogBookService::pin(", CONTROLLER)


if __name__ == "__main__":
    unittest.main(verbosity=2)
