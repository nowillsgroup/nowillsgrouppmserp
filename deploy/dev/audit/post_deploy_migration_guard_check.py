#!/usr/bin/env python3
"""Static regression checks for fail-closed production migrations."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
HOOK = (ROOT / "deploy/cyberpanel/post-deploy.sh").read_text()
GUIDE = (ROOT / "docs/INSTALL-CYBERPANEL.md").read_text()


class PostDeployMigrationGuardTest(unittest.TestCase):
    def test_installed_app_cannot_report_success_without_a_supported_php_cli(self):
        self.assertIn('if [ -f "$APP_ROOT/.env" ] && [ -f "$APP_ROOT/storage/framework/installed.lock" ]; then', HOOK)
        self.assertIn('fail "no PHP 8.3+ CLI found — cannot apply migrations to this installed app', HOOK)

    def test_migration_failure_stops_the_hook_instead_of_leaving_a_partial_release_live(self):
        self.assertIn('migrate --force --no-interaction', HOOK)
        self.assertIn('fail "artisan migrate failed — deployment is incomplete;', HOOK)
        self.assertNotIn('WARNING: migrations reported a problem', HOOK)
        self.assertIn('SKIP_MIGRATE=1', HOOK)  # intentional, explicit operator override remains documented

    def test_missing_shift_table_has_an_actionable_hostinger_recovery_note(self):
        self.assertIn('SQLSTATE[42S02]', GUIDE)
        self.assertIn('front_desk_shift_sessions', GUIDE)
        self.assertIn('2026_10_31_000200_create_front_desk_shift_sessions', GUIDE)
        self.assertIn('Do not create the table manually', GUIDE)
        self.assertIn('storage/logs/deploy.log', GUIDE)


if __name__ == "__main__":
    unittest.main(verbosity=2)
