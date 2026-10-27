#!/usr/bin/env python3
"""Static source-contract regression guard for post-commit checkout hooks.

Run with: python3 deploy/dev/audit/checkout_hook_isolation_check.py
"""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
SERVICE = (ROOT / "app/Services/StayService.php").read_text()


class CheckoutHookIsolationTest(unittest.TestCase):
    def test_each_post_commit_cleanup_is_run_independently(self):
        method = SERVICE[SERVICE.index("public static function checkOut("):SERVICE.index("protected static function doCheckOut(")]
        self.assertIn("foreach ([", method)
        self.assertGreaterEqual(method.count("self::runPostCheckoutHook($hook)"), 1)
        for hook in [
            "GuestPortalService::closeForStay($out)",
            "UpsellService::onCheckout($out)",
            "CallAccountingService::onCheckOut($out)",
            "KeyCardService::onCheckOut($out)",
            "HousekeepingTask::where('room_id', $out->room_id)",
        ]:
            self.assertIn(hook, method)
        self.assertIn("catch (\\Throwable $e)", SERVICE[SERVICE.index("protected static function runPostCheckoutHook"):])

    def test_housekeeping_and_guest_followups_use_the_isolated_runner(self):
        method = SERVICE[SERVICE.index("public static function checkOut("):SERVICE.index("protected static function doCheckOut(")]
        housekeep = method.index("HousekeepingTask::where('room_id', $out->room_id)")
        hook_loop = method.index("] as $hook)")
        self.assertLess(housekeep, hook_loop)
        self.assertIn("self::runPostCheckoutHook(fn () => MessageService::autoThankYou", method)
        self.assertIn("self::runPostCheckoutHook(fn () => FeedbackService::scheduleFor($out->reservation))", method)


if __name__ == "__main__":
    unittest.main(verbosity=2)
