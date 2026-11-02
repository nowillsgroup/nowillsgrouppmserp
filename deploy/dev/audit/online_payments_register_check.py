#!/usr/bin/env python3
"""Regression checks for the staff Online Payments register."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
CONTROLLER = (ROOT / "app/Http/Controllers/OnlinePaymentController.php").read_text()
VIEW = (ROOT / "resources/views/payments/index.blade.php").read_text()


class OnlinePaymentsRegisterTest(unittest.TestCase):
    def test_filter_values_are_sanitized_and_search_is_bounded(self):
        self.assertIn("is_string($rawStatus) && in_array($rawStatus, ['', 'pending', 'paid', 'review', 'refunded', 'expired', 'cancelled'], true)", CONTROLLER)
        self.assertIn("is_string($rawSearch) ? mb_substr(trim($rawSearch), 0, 160) : ''", CONTROLLER)
        self.assertIn("$request->merge(['status' => $status, 'q' => $search]);", CONTROLLER)

    def test_pending_stats_include_links_without_expiry(self):
        self.assertIn("$pending = fn () => OnlinePayment::where('status', 'pending')->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>=', now()))", CONTROLLER)
        self.assertIn("'pending' => (float) $pending()->sum('amount')", CONTROLLER)
        self.assertIn("'pending_n' => $pending()->count()", CONTROLLER)

    def test_register_search_has_a_programmatic_label_and_tabs_announce_selection(self):
        self.assertIn('<label class="sr-only" for="paymentSearch">Search online payments</label>', VIEW)
        self.assertIn('<input id="paymentSearch" name="q"', VIEW)
        self.assertIn('aria-current="page"', VIEW)

    def test_table_has_caption_and_scoped_headers(self):
        self.assertIn('<caption class="sr-only">Online payment link register</caption>', VIEW)
        self.assertEqual(VIEW.count('scope="col"'), 8)

    def test_payment_timestamps_show_the_year(self):
        for fragment in (
            "$p->created_at->format('d M Y, H:i')",
            "$p->expires_at->format('d M Y H:i')",
            "$p->paid_at->format('d M Y, H:i')",
            "$p->created_at->toIso8601String()",
            "$p->expires_at->toIso8601String()",
            "$p->paid_at->toIso8601String()",
            '<time datetime="{{ $p->created_at->toIso8601String() }}">',
        ):
            with self.subTest(fragment=fragment):
                self.assertIn(fragment, VIEW)


if __name__ == "__main__":
    unittest.main(verbosity=2)
