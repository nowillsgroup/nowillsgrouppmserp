#!/usr/bin/env python3
"""Source guard for lock order; SQLite runtime tests do not exercise row locks."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
service = (ROOT / 'app/Services/FolioService.php').read_text()
controller = (ROOT / 'app/Http/Controllers/FolioController.php').read_text()
loyalty = (ROOT / 'app/Services/LoyaltyService.php').read_text()


def section(text, start, end):
    return text[text.index(start):text.index(end, text.index(start))]


class FolioLockingContracts(unittest.TestCase):
    def test_locked_ledger_decisions(self):
        self.assertIn("orderBy('id')->lockForUpdate()->get()", section(service, 'public static function lockedTransactions', 'public static function discount('))
        for start, end in [('protected static function postDiscount', 'public static function transferToCityLedger'), ('public static function transferToCityLedger', 'public static function void'), ('public static function close', '\n}')]:
            body = section(service, start, end)
            self.assertIn('DB::transaction(', body)
            self.assertIn('self::lockedTransactions($folio)', body)
            self.assertTrue('self::lockOpen($folio)' in body or 'lockForUpdate()->firstOrFail()' in body)
        self.assertIn("$folio->update(['company_id' => $companyId])", service)
        self.assertIn('FolioService::discountPercent(', section(controller, 'public function discount(', 'public function transfer('))

    def test_void_locks_folio_then_line_and_refuses_duplicate(self):
        body = section(service, 'public static function void', '/** Settle and close')
        self.assertLess(body.index('Folio::whereKey'), body.index('FolioTransaction::whereKey'))
        for text in ['self::assertOpen($folio)', '$current->is_void', '$current->folio_id', 'IncomeAuditService::assertCanVoid($current)', 'GiftVoucherService::onFolioVoid($current)']:
            self.assertIn(text, body)

    def test_move_locks_folios_in_order_before_lines(self):
        body = section(controller, 'public function move(', 'public function addFixed(')
        self.assertLess(body.index('sort($ids, SORT_NUMERIC)'), body.index('Folio::whereKey($id)->lockForUpdate()'))
        self.assertLess(body.index('Folio::whereKey($id)->lockForUpdate()'), body.index('$folio->transactions()->whereIn'))
        self.assertIn("$folio->status !== 'open'", body)
        self.assertIn("$target->status !== 'open'", body)

    def test_loyalty_obeys_folio_then_guest_order(self):
        body = section(loyalty, 'public static function redeem(', '/** Called when a loyalty discount')
        self.assertLess(body.index('FolioService::lockOpen($folio)'), body.index('Guest::lockForUpdate()'))
        self.assertIn('FolioService::lockedTransactions($folio)', body)

if __name__ == '__main__':
    unittest.main(verbosity=2)
