from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[3]
SERVICE = (ROOT / "app/Services/FrontDeskOpsService.php").read_text()
CONTROLLER = (ROOT / "app/Http/Controllers/FrontDeskOpsController.php").read_text()


def method(source, name):
    match = re.search(rf"(?:public|protected) static function {name}\(", source)
    if not match:
        match = re.search(rf"public function {name}\(", source)
    start = match.start()
    end_match = re.search(r"\n    (?:public|protected) static function |\n    public function ", source[start + 1 :])
    end = start + 1 + end_match.start() if end_match else len(source)
    return source[start:end]


class FrontDeskOpsSettingsAtomicityTest(unittest.TestCase):
    def test_settings_rows_and_audit_event_commit_together(self):
        body = method(SERVICE, "saveSettings")
        transaction = body.index("DB::transaction(")
        update = body.index("Setting::updateOrCreate(", transaction)
        activity = body.index("ActivityLogger::log(", update)
        end = body.index("        });", activity)
        version = body.index("Setting::$version++", end)
        self.assertLess(transaction, update)
        self.assertLess(update, activity)
        self.assertLess(activity, end)
        self.assertLess(end, version)

    def test_partial_updates_only_write_keys_supplied_to_service(self):
        body = method(SERVICE, "saveSettings")
        self.assertIn("if (array_key_exists($short, $d))", body)

    def test_admin_rejects_invalid_shift_order_before_persisting_settings(self):
        body = method(CONTROLLER, "saveSettings")
        validation = body.index("$request->validate(")
        order = body.index("if (! ($data['shift_morning'] < $data['shift_evening']", validation)
        save = body.index("FrontDeskOpsService::saveSettings($data)", order)
        self.assertLess(validation, order)
        self.assertLess(order, save)


if __name__ == "__main__":
    unittest.main(verbosity=2)
