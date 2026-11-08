from pathlib import Path
import unittest
ROOT = Path(__file__).resolve().parents[3]
S = (ROOT / 'app/Services/OnlinePaymentService.php').read_text()

class OnlinePaymentFolioLocks(unittest.TestCase):
    def test_lock_order_and_current_reads(self):
        body = S[S.index('public static function targetFolio('):S.index('/**', S.index('public static function targetFolio('))]
        self.assertIn('DB::transactionLevel() < 1', body)
        reservation = body.index('Reservation::whereKey($p->reservation_id)->lockForUpdate()')
        stay = body.index("ReservationRoom::where('reservation_id'", reservation)
        folios = body.index('})->orderBy(\'id\')->lockForUpdate()->get()', stay)
        self.assertLess(reservation, stay)
        self.assertLess(stay, folios)
        self.assertIn("->orderBy('id')->lockForUpdate()->first()", body[stay:folios])
        self.assertIn('$f->status === \'open\'', body[folios:])
        self.assertLess(body.index("(int) $f->id === (int) $p->folio_id", folios), body.index("(int) $f->reservation_room_id === (int) $stay->id", folios))

    def test_gateway_post_and_review_accept_lock_payment_in_transaction(self):
        complete = S[S.index('public static function complete('):S.index('protected static function confirmBooking(')]
        accept = S[S.index('public static function accept('):S.index('public static function cancel(')]
        for method in (complete, accept):
            self.assertLess(method.index('DB::transaction('), method.index('OnlinePayment::lockForUpdate()'))
            self.assertIn('self::post($p,', method)
        post = S[S.index('protected static function post('):S.index('public static function accept(')]
        self.assertLess(post.index('self::targetFolio($p)'), post.index('$f->transactions()->create('))

if __name__ == '__main__': unittest.main()
