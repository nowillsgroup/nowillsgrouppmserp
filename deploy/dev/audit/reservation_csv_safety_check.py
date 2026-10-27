from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
CONTROLLER = (ROOT / "app/Http/Controllers/ReservationDeskController.php").read_text()


class ReservationCsvSafetyTest(unittest.TestCase):
    def test_export_applies_sanitizer_to_every_row_value(self):
        start = CONTROLLER.index("public function export(")
        end = CONTROLLER.index("public function printPlan(", start)
        body = CONTROLLER[start:end]
        self.assertIn("array_map(fn ($value) => $this->csvSafe($value)", body)
        self.assertIn("fputcsv($out, array_map", body)

    def test_sanitizer_prefixes_formula_markers_after_leading_whitespace(self):
        start = CONTROLLER.index("protected function csvSafe(")
        end = CONTROLLER.index("public function printPlan(", start)
        body = CONTROLLER[start:end]
        self.assertIn("/^[\\\\t\\\\r\\\\n ]*[=+\\\\-@]/", body)
        self.assertIn("! is_numeric($value)", body)
        self.assertIn("\"'\".$value", body)


if __name__ == "__main__":
    unittest.main(verbosity=2)
