from pathlib import Path
import unittest

SERVICE = Path(__file__).resolve().parents[2] / "../app/Services/HousekeepingService.php"
SERVICE = SERVICE.resolve().read_text()


def method(name, next_name):
    start = SERVICE.index(f"public static function {name}(")
    end = SERVICE.index(f"public static function {next_name}(", start)
    return SERVICE[start:end]


class HousekeepingTaskLifecycleTest(unittest.TestCase):
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
