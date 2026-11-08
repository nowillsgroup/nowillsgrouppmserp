from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
SERVICE = (ROOT / 'app/Services/LaundryService.php').read_text()
CONTROLLER = (ROOT / 'app/Http/Controllers/LaundryController.php').read_text()
VIEW = (ROOT / 'resources/views/laundry/create.blade.php').read_text()

class GuestPickupContract(unittest.TestCase):
    def test_collection_locks_then_revalidates_before_linking(self):
        body = SERVICE[SERVICE.index('public static function create('):SERVICE.index('public static function itemEditState(')]
        tokens = ['DB::transaction(', 'Reservation::whereKey($stay->reservation_id)->lockForUpdate()',
                  'ReservationRoom::whereKey($stay->id)->lockForUpdate()', "LaundryOrder::where('guest_request_id', $requestId)",
                  'GuestRequest::whereKey($requestId)->lockForUpdate()', "$req->category !== 'laundry'",
                  '(int) $req->reservation_room_id !== (int) $stay->id', '$existing->isNotEmpty()',
                  'LaundryOrder::create(', "if ($req->status !== 'in_progress')", "'linked_type' => 'LaundryOrder'"]
        positions = [body.index(s) for s in tokens]
        self.assertEqual(positions, sorted(positions))
        self.assertIn('if ($requestId && ! $stay)', body)
        self.assertIn('$req->linked_type !== null || $req->linked_id !== null', body)

    def test_terminal_actions_only_change_their_own_open_request(self):
        for name, end, order in [('deliver', 'public static function cancel(', '$o'), ('cancel', '/** Orders still with the laundry', '$locked')]:
            body = SERVICE[SERVICE.index('public static function '+name+'('):SERVICE.index(end, SERVICE.index('public static function '+name+'('))]
            order_lock = body.index('LaundryOrder::whereKey($o->id)->lockForUpdate()')
            request_lock = body.index('GuestRequest::whereKey(', order_lock)
            transition = body.index('GuestPortalService::transition(', request_lock)
            self.assertLess(order_lock, request_lock)
            self.assertLess(request_lock, transition)
            self.assertIn("$req->linked_type === 'LaundryOrder'", body[request_lock:transition])
            self.assertIn(f'(int) $req->linked_id === (int) {order}->id', body[request_lock:transition])
            self.assertIn('GuestRequest::OPEN, true', body[request_lock:transition])

    def test_list_and_form_prevent_accidental_reassignment(self):
        self.assertIn("->whereNull('linked_type')->whereNull('linked_id')", CONTROLLER)
        self.assertIn("where('status', '!=', 'cancelled')", CONTROLLER)
        self.assertIn("catch (\\RuntimeException $e)", CONTROLLER)
        self.assertIn('->withInput()', CONTROLLER)
        self.assertIn('@disabled($requestUnavailable)', VIEW)
        self.assertIn('name="guest_request_id" value="{{ $req->id }}"', VIEW)
        self.assertIn("old('q', [])", VIEW)
        self.assertIn("old('r', [])", VIEW)
        self.assertIn("(int) $req->reservation_room_id === (int) $s->id", VIEW)

if __name__ == '__main__': unittest.main(verbosity=2)
