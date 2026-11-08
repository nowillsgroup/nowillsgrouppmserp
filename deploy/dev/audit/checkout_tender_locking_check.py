#!/usr/bin/env python3
"""Guard checkout tender and lock-order contracts; not a concurrency test."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
controller = (ROOT / 'app/Http/Controllers/FrontDeskController.php').read_text()
folio = (ROOT / 'app/Services/FolioService.php').read_text()
voucher = (ROOT / 'app/Services/GiftVoucherService.php').read_text()


def part(text, start, end):
    a = text.index(start)
    return text[a:text.index(end, a)]


class CheckoutTenderLockingCheck(unittest.TestCase):
    def test_shared_scoped_mode_guard(self):
        guard = part(controller, 'protected function checkoutDirectPaymentMode', '/** Validate and post split')
        self.assertIn('PaymentMode::whereKey($modeId)->lockForUpdate()->first()', guard)
        self.assertIn("! $mode->is_active || $mode->type === 'city_ledger'", guard)
        split = part(controller, 'protected function postCheckoutPaymentAllocations', '/** Checkout by direct payment')
        self.assertLess(split.index('asort($modeIds, SORT_NUMERIC)'), split.index('$this->checkoutDirectPaymentMode'))
        self.assertIn('"payments.{$i}.payment_mode_id"', split)
        self.assertIn('], $lockedModes[$i])', split)
        checkout = part(controller, 'public function checkOut(Request', '/** Route successful check-outs')
        self.assertEqual(checkout.count("$this->checkoutDirectPaymentMode((int) $data['payment_mode_id'], 'payment_mode_id')"), 2)
        self.assertEqual(checkout.count('], $mode)'), 2)
        self.assertIn('FolioService::lockedTransactions($folio)', checkout)

    def test_locked_mode_drives_voucher_and_cheque_posting(self):
        payment = part(folio, 'public static function payment(', '/** Call inside a transaction')
        self.assertIn("$lockedMode->type === 'gift_voucher'", payment)
        self.assertIn("$lockedMode->type === 'cheque'", payment)
        self.assertIn('$lockedMode ? $paymentModeId : null', payment)
        voucher_payment = part(voucher, 'public static function folioPayment(', '/** money back onto a voucher')
        self.assertIn('$modeId ??= self::modeId()', voucher_payment)
        self.assertIn("'payment_mode_id' => $modeId", voucher_payment)

if __name__ == '__main__':
    unittest.main(verbosity=2)
