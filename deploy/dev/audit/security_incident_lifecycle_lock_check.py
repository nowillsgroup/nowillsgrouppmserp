from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[3]
SERVICE = (ROOT / "app/Services/IncidentService.php").read_text()
CONTROLLER = (ROOT / "app/Http/Controllers/IncidentController.php").read_text()


def method(source, name):
    match = re.search(rf"(?:public|protected) static function {name}\(", source)
    if not match:
        match = re.search(rf"public function {name}\(", source)
    start = match.start()
    end_match = re.search(r"\n    (?:public|protected) static function |\n    public function ", source[start + 1 :])
    end = start + 1 + end_match.start() if end_match else len(source)
    return source[start:end]


class SecurityIncidentLifecycleLockTest(unittest.TestCase):
    def test_incident_links_cannot_pair_a_stay_with_a_different_room(self):
        body = method(SERVICE, "clean")
        mismatch = body.index("if ($stay && $room && $stay->room_id")
        error = body.index("The selected room does not match the selected stay.", mismatch)
        return_data = body.index("return ['type' => $type", error)
        self.assertLess(mismatch, error)
        self.assertLess(error, return_data)

    def test_assignees_must_be_active_and_belong_to_current_tenant(self):
        body = method(SERVICE, "clean")
        self.assertIn("User::where('hotel_id', Tenant::id())->where('is_active', true)->findOrFail", body)

    def test_incident_alert_sms_occurs_after_incident_and_logbook_commit(self):
        body = method(SERVICE, "report")
        transaction = body.index("[$i, $alertPhone] = DB::transaction(")
        logbook = body.index("LogBookService::system(", transaction)
        transaction_end = body.index("        });", logbook)
        sms = body.index("MessageService::send(", transaction_end)
        self.assertLess(transaction, logbook)
        self.assertLess(logbook, transaction_end)
        self.assertLess(transaction_end, sms)

    def test_incident_mutex_reloads_current_row(self):
        body = method(SERVICE, "lockIncident")
        self.assertIn("SecurityIncident::whereKey($incident->id)->lockForUpdate()->firstOrFail()", body)

    def test_edit_rechecks_closed_state_under_incident_mutex(self):
        body = method(SERVICE, "update")
        lock = body.index("self::lockIncident($i)")
        state = body.index("if ($locked->status === 'closed')", lock)
        update = body.index("$locked->forceFill($data)->save()", state)
        self.assertLess(lock, state)
        self.assertLess(state, update)

    def test_followup_and_status_change_commit_as_one_locked_transition(self):
        body = method(SERVICE, "note")
        lock = body.index("self::lockIncident($i)")
        state = body.index("if ($locked->status === 'closed')", lock)
        note = body.index("SecurityIncidentUpdate::create(", state)
        status = body.index("$nextStatus ? $locked->forceFill", note)
        self.assertLess(lock, state)
        self.assertLess(state, note)
        self.assertLess(note, status)

    def test_close_rechecks_state_and_resolves_linked_log_entry_under_lock(self):
        body = method(SERVICE, "close")
        lock = body.index("self::lockIncident($i)")
        state = body.index("if ($locked->status === 'closed')", lock)
        note = body.index("SecurityIncidentUpdate::create(", state)
        log_lock = body.index("LogEntry::whereKey($locked->log_entry_id)->lockForUpdate()", note)
        resolve = body.index("LogBookService::resolve($e", log_lock)
        self.assertLess(lock, state)
        self.assertLess(state, note)
        self.assertLess(note, log_lock)
        self.assertLess(log_lock, resolve)

    def test_reopen_rechecks_state_and_reopens_the_linked_logbook_entry(self):
        body = method(SERVICE, "reopen")
        lock = body.index("self::lockIncident($i)")
        state = body.index("if ($locked->status !== 'closed')", lock)
        note = body.index("SecurityIncidentUpdate::create(", state)
        log_lock = body.index("LogEntry::whereKey($locked->log_entry_id)->lockForUpdate()", note)
        reopen = body.index("LogBookService::reopen($e)", log_lock)
        self.assertLess(lock, state)
        self.assertLess(state, note)
        self.assertLess(note, log_lock)
        self.assertLess(log_lock, reopen)

    def test_controller_uses_fresh_incident_after_mutations(self):
        for action in ("update", "close", "reopen"):
            self.assertIn(f"$incident = IncidentService::{action}(", CONTROLLER)


if __name__ == "__main__":
    unittest.main(verbosity=2)
