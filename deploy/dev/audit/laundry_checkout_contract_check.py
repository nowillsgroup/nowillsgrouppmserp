from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
def read(path): return (ROOT / path).read_text()
SERVICE = read('app/Services/StayService.php')
CONTROLLER = read('app/Http/Controllers/FrontDeskController.php')
LAUNDRY = read('app/Services/LaundryService.php')

class LaundryCheckoutContracts(unittest.TestCase):
    def test_current_order_read_and_lock_sequence(self):
        guard = SERVICE[SERVICE.index('public static function assertLaundryClear'):SERVICE.index('public static function assembleBill')]
        for token in ["where('reservation_room_id', $stay->id)", "whereIn('status', LaundryOrder::OPEN)", 'orderBy(\'id\')->lockForUpdate()->get']:
            self.assertIn(token, guard)
        for body, guard_call, night_call in [
            (SERVICE[SERVICE.index('protected static function doCheckOut'):], 'self::assertLaundryClear($stay)', 'self::postPendingNights($stay'),
            (CONTROLLER[CONTROLLER.index('public function checkOut(Request'):CONTROLLER.index('public function prepareBill')], 'StayService::assertLaundryClear($stay)', 'StayService::postPendingNights($stay')]:
            reservation = body.index('Reservation::whereKey($stay->reservation_id)->lockForUpdate()')
            stay = body.index('ReservationRoom::whereKey($stay->id)->lockForUpdate()', reservation)
            guard_at = body.index(guard_call, stay)
            self.assertLess(guard_at, body.index(night_call, guard_at))
            self.assertNotIn('if ($force)', body[stay:guard_at])
        create = LAUNDRY[LAUNDRY.index('public static function create('):LAUNDRY.index('public static function ', LAUNDRY.index('public static function create(') + 25)]
        self.assertLess(create.index('Reservation::whereKey('), create.index('ReservationRoom::whereKey('))
        self.assertIn('lockForUpdate()', create)

    def test_other_checkout_paths_use_shared_lifecycle(self):
        for f in ['app/Services/KioskService.php', 'app/Services/DesktopSyncService.php']:
            self.assertIn('StayService::checkOut(', read(f))

    def test_bill_preparation_never_delivers_laundry(self):
        body = SERVICE[SERVICE.index('public static function assembleBill'):SERVICE.index('public static function ', SERVICE.index('public static function assembleBill') + 30)]
        self.assertNotIn('LaundryService::deliver', body)
        self.assertIn('delivery and settlement or cancellation', body)

    def test_views_and_workspace_use_blocking_hold(self):
        self.assertIn("+ $laundry->count()", SERVICE)
        self.assertIn("$holds['laundry']['count']", read('app/Services/ReservationWorkspaceService.php'))
        self.assertIn("$holds['blocking'] ?? 0", read('resources/views/reservations/day-summary-print.blade.php'))
        view = read('resources/views/frontdesk/checkout.blade.php')
        self.assertIn("$postable = $holds['pos']['count'] || $holds['trips']['count'];", view)
        self.assertIn('deliver and settle them, or cancel them', view)

if __name__ == '__main__': unittest.main()
