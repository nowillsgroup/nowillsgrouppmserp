from pathlib import Path
import unittest
ROOT = Path(__file__).resolve().parents[3]
def read(path): return (ROOT / path).read_text()

class ReservationBillOriginContracts(unittest.TestCase):
    def test_tenant_scoped_provenance_and_eager_loaded_views(self):
        model = read('app/Models/ReservationBillTransfer.php')
        self.assertIn('use BelongsToHotel;', model)
        tx = read('app/Models/FolioTransaction.php')
        self.assertIn("hasOne(ReservationBillTransfer::class, 'folio_transaction_id')", tx)
        controller = read('app/Http/Controllers/FolioController.php')
        self.assertGreaterEqual(controller.count('consolidationTransfer.sourceFolio.stay.room'), 3)
        for view in ['resources/views/folios/show.blade.php', 'resources/views/folios/invoice.blade.php']:
            self.assertIn("@include('folios._line-origin'", read(view))

    def test_archived_source_explains_its_state_and_is_not_offered_reopen(self):
        view = read('resources/views/folios/show.blade.php')
        self.assertIn('This source is retained for history and cannot be reopened', view)
        self.assertIn("! $folio->consolidated_into_folio_id && can_do('folio.void')", view)
        partial = read('resources/views/folios/_line-origin.blade.php')
        self.assertIn('last assigned room', partial)
        self.assertIn('source folio unavailable', partial)

if __name__ == '__main__': unittest.main()
