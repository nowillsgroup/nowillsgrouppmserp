from pathlib import Path
import unittest
ROOT = Path(__file__).resolve().parents[3]
def read(path): return (ROOT / path).read_text()

class ReservationPostedBalanceTests(unittest.TestCase):
    def test_summary_includes_scoped_folios_and_uses_folio_math(self):
        service = read('app/Services/ReservationBillingService.php')
        self.assertIn("'folios.transactions'", service)
        self.assertIn("'folios.stay.room'", service)
        self.assertIn('$folio->totals($folio->transactions)', service)
        for key in ['charges', 'opening', 'discount', 'payments', 'refunds', 'balance']:
            self.assertIn("'" + key + "'", service)
        self.assertNotIn("where('status'", service)

    def test_view_respects_folio_permission_and_distinguishes_existing_checkout(self):
        controller = read('app/Http/Controllers/ReservationController.php')
        self.assertIn("can_do('folio.view') ? ReservationBillingService::postedSummary($reservation) : null", controller)
        view = read('resources/views/reservations/show.blade.php')
        self.assertIn('@if($postedReservationBalance !== null)', view)
        self.assertIn("@include('reservations._posted-balance'", view)
        card = read('resources/views/reservations/_posted-balance.blade.php')
        self.assertIn('Unposted room nights and service charges are not included', card)
        self.assertIn('Room folios are still settled separately', card)
        self.assertIn("$summary['balance'] < 0 ? 'Posted credit'", card)

if __name__ == '__main__': unittest.main()
