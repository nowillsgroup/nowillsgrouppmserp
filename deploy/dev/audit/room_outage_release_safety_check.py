from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
SERVICE = (ROOT / "app/Services/ReservationDeskService.php").read_text()
MIGRATION = (ROOT / "database/migrations/2026_10_27_000100_track_room_outage_status_changes.php").read_text()


def method(name, next_name):
    start = SERVICE.index(f"public static function {name}(")
    end = SERVICE.index(f"public static function {next_name}(", start)
    return SERVICE[start:end]


class RoomOutageReleaseSafetyTest(unittest.TestCase):
    def test_block_records_only_status_changes_it_makes(self):
        body = method("block", "release")
        flag = body.index("$setsOutOfOrder = $starts->lte(bdate())")
        create = body.index("'room_status_changed_by_block' => $setsOutOfOrder", flag)
        update = body.index("if ($setsOutOfOrder)", create)
        mark = body.index("$room->update(['status' => 'out_of_order'", update)
        self.assertLess(flag, create)
        self.assertLess(create, update)
        self.assertLess(update, mark)

    def test_release_restores_only_status_owned_by_the_released_block(self):
        body = method("release", "addWaitlist")
        lock_outage = body.index("RoomOutage::whereKey($outage->id)->lockForUpdate()->firstOrFail()")
        lock_room = body.index("Room::whereKey($room->id)->lockForUpdate()->first()", lock_outage)
        restore = body.index("$locked->room_status_changed_by_block && $room->status === 'out_of_order'", lock_room)
        vacant = body.index("$room->update(['status' => 'vacant'])", restore)
        self.assertIn("! $still && ! $ticket", body[restore:vacant])
        self.assertLess(lock_outage, lock_room)
        self.assertLess(lock_room, restore)
        self.assertLess(restore, vacant)

    def test_migration_preserves_legacy_active_outage_release_behavior(self):
        self.assertIn("$t->boolean('room_status_changed_by_block')->default(false)", MIGRATION)
        self.assertIn("Schema::hasColumn('room_outages', 'room_status_changed_by_block')", MIGRATION)
        self.assertIn("->update(['room_status_changed_by_block' => true])", MIGRATION)
        self.assertIn("->where('rooms.status', 'out_of_order')", MIGRATION)


if __name__ == "__main__":
    unittest.main(verbosity=2)
