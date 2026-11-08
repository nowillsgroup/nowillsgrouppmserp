from pathlib import Path
import unittest
ROOT = Path(__file__).resolve().parents[3]
S = (ROOT / 'app/Services/ReservationBillConsolidator.php').read_text()
C = (ROOT / 'app/Http/Controllers/FolioController.php').read_text()
M = (ROOT / 'database/migrations/2026_11_08_000460_reservation_bill_consolidation.php').read_text()

class ReservationBillConsolidationContracts(unittest.TestCase):
    def test_locks_and_provenance_precede_moves(self):
        reservation = S.index('Reservation::whereKey($reservation->id)->lockForUpdate()')
        stays = S.index("ReservationRoom::where('reservation_id'", reservation)
        folios = S.index("Folio::where('reservation_id'", stays)
        lines = S.index("FolioTransaction::withoutGlobalScope('hotel')", folios)
        audit = S.index("DB::table('reservation_bill_transfers')->insert", lines)
        move = S.index("->update(['folio_id' => $target->id])", audit)
        self.assertLess(reservation, stays); self.assertLess(stays, folios); self.assertLess(folios, lines)
        self.assertLess(lines, audit); self.assertLess(audit, move)
        self.assertGreaterEqual(S.count("orderBy('id')->lockForUpdate()->get()"), 3)
        self.assertIn("['checked_out', 'cancelled', 'no_show']", S)
        self.assertIn("$source->invoice_no !== null", S)
        self.assertNotIn('FolioService::close(', S)

    def test_no_production_activation_until_checkout_is_ready(self):
        for folder in ('app/Http', 'app/Services', 'routes'):
            for path in (ROOT / folder).rglob('*.php'):
                if path.name == 'ReservationBillConsolidator.php': continue
                self.assertNotIn('ReservationBillConsolidator::consolidate(', path.read_text(), str(path))

    def test_closed_sources_cannot_be_reopened_and_history_blocks_rollback(self):
        body = C[C.index('public function reopen('):C.index('public function receipt(')]
        self.assertLess(body.index('lockForUpdate()'), body.index('consolidated_into_folio_id'))
        self.assertLess(body.index('consolidated_into_folio_id'), body.index("$folio->update(['status' => 'open'])"))
        self.assertIn("DB::table('reservation_bill_transfers')->exists()", M)
        self.assertIn('Cannot remove reservation bill consolidation history', M)

if __name__ == '__main__': unittest.main()
