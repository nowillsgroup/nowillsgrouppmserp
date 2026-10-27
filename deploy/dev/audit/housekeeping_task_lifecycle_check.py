from pathlib import Path
import unittest

SERVICE = Path(__file__).resolve().parents[2] / "../app/Services/HousekeepingService.php"
SERVICE = SERVICE.resolve().read_text()


def method(name, next_name):
    start = SERVICE.index(f"public static function {name}(")
    end = SERVICE.index(f"public static function {next_name}(", start)
    return SERVICE[start:end]


class HousekeepingTaskLifecycleTest(unittest.TestCase):
    def test_concurrent_generators_serialize_before_checking_existing_tasks(self):
        body = SERVICE[SERVICE.index("public static function generate("):SERVICE.index("public static function start(")]
        hotel_lock = body.index("Hotel::whereKey(Tenant::id())->lockForUpdate()->firstOrFail()")
        existing_check = body.index("$existing = HousekeepingTask::where('task_date', $date)")
        create_task = body.index("HousekeepingTask::create(")
        self.assertIn("DB::transaction(function () use ($date)", body)
        self.assertLess(hotel_lock, existing_check)
        self.assertLess(existing_check, create_task)

    def test_minibar_revalidates_occupancy_in_checkout_lock_order(self):
        body = method("postMinibar", "reportIssue")
        reservation_lock = body.index("Reservation::whereKey($candidate->reservation_id)->lockForUpdate()")
        stay_lock = body.index("ReservationRoom::whereKey($candidate->id)->lockForUpdate()")
        room_lock = body.index("Room::whereKey($room->id)->lockForUpdate()")
        occupancy_check = body.index("if ($stay->status !== 'checked_in'")
        charge = body.index("FolioService::charge(")
        self.assertLess(reservation_lock, stay_lock)
        self.assertLess(stay_lock, room_lock)
        self.assertLess(room_lock, occupancy_check)
        self.assertLess(occupancy_check, charge)

    def test_start_and_dnd_lock_and_recheck_the_current_task(self):
        for name, next_name in (("start", "dnd"), ("dnd", "complete")):
            with self.subTest(action=name):
                body = method(name, next_name)
                self.assertIn("DB::transaction(function () use ($task)", body)
                lock = body.index("HousekeepingTask::whereKey($task->id)->lockForUpdate()->firstOrFail()")
                self.assertLess(lock, body.index("self::assertOpen($locked)"))

    def test_completion_serializes_task_and_room_updates(self):
        body = method("complete", "inspect")
        task_lock = body.index("HousekeepingTask::whereKey($task->id)->lockForUpdate()->firstOrFail()")
        checklist = body.index("count($checked) < count($items)")
        room_lock = body.index("Room::whereKey($locked->room_id)->lockForUpdate()->first()")
        self.assertIn("DB::transaction(function () use ($task, $checked, $notes)", body)
        self.assertLess(task_lock, checklist)
        self.assertLess(checklist, room_lock)
        self.assertIn("self::assertOpen($locked)", body)

    def test_inspection_rechecks_pending_inspection_under_locks(self):
        body = method("inspect", "postMinibar")
        task_lock = body.index("HousekeepingTask::whereKey($task->id)->lockForUpdate()->firstOrFail()")
        state_check = body.index("if (! $locked->awaitingInspection())")
        room_lock = body.index("Room::whereKey($locked->room_id)->lockForUpdate()->first()")
        self.assertIn("DB::transaction(function () use ($task, $pass, $notes)", body)
        self.assertLess(task_lock, state_check)
        self.assertLess(state_check, room_lock)


if __name__ == "__main__":
    unittest.main(verbosity=2)
