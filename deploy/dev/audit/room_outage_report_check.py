from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
REPORT = (ROOT / "app/Http/Controllers/ReportController.php").read_text()
SCHEMA = (ROOT / "database/migrations/2026_01_01_007300_reservation_desk.php").read_text()


class RoomOutageReportTest(unittest.TestCase):
    def test_report_queries_real_columns_and_overlapping_date_ranges(self):
        start = REPORT.index("protected function r_room_outages(")
        end = REPORT.index("protected function r_stock_take_lines(", start)
        body = REPORT[start:end]
        for field in ("starts_on", "ends_on", "room_id", "reason", "status", "created_by", "notes"):
            self.assertIn(field, body)
        self.assertIn("->whereDate('starts_on', '<=', $to)", body)
        self.assertIn("->whereDate('ends_on', '>', $from)", body)
        self.assertIn("RoomOutage::with(['room.roomType', 'creator'])", body)
        self.assertIn("$r->creator?->name", body)
        for stale in ("outage_from", "outage_to", "estimated_cost", "actual_cost", "revenue_lost", "user_id"):
            self.assertNotIn(stale, body)

    def test_report_columns_match_the_room_outage_table(self):
        start = SCHEMA.index("Schema::create('room_outages'")
        end = SCHEMA.index("});", start)
        table = SCHEMA[start:end]
        for field in ("room_id", "starts_on", "ends_on", "reason", "notes", "status", "created_by"):
            self.assertIn(f"'{field}'", table)


if __name__ == "__main__":
    unittest.main(verbosity=2)
