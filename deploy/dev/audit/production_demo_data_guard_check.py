#!/usr/bin/env python3
"""Static regression checks preventing demo fixtures from entering production installs."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
INSTALLER = (ROOT / "public/install.php").read_text()
SEEDER = (ROOT / "database/seeders/DatabaseSeeder.php").read_text()
DEPLOY_HOOK = (ROOT / "deploy/cyberpanel/post-deploy.sh").read_text()


class ProductionDemoDataGuardTest(unittest.TestCase):
    def test_browser_installer_creates_only_the_configured_property_and_admin(self):
        self.assertNotIn("seed_demo", INSTALLER)
        self.assertNotIn("db:seed", INSTALLER)
        self.assertIn("$kernel->call('migrate'", INSTALLER)
        self.assertIn("HotelProvisioner::provision($hotel)", INSTALLER)
        self.assertIn("$admin->save()", INSTALLER)

    def test_database_seeder_refuses_to_run_before_creating_synthetic_records(self):
        guard = SEEDER.index("if (app()->environment('production'))")
        first_fixture = SEEDER.index("HotelGroup::create(")
        self.assertLess(guard, first_fixture)
        self.assertIn("disabled in production", SEEDER[guard:first_fixture])

    def test_installation_guides_no_longer_offer_production_demo_seeding(self):
        for path in (
            "docs/INSTALL-CPANEL.md",
            "public/docs/INSTALL-CPANEL.md",
            "docs/INSTALL-CYBERPANEL.md",
        ):
            with self.subTest(path=path):
                guide = (ROOT / path).read_text()
                self.assertNotIn("Demo data |", guide)
                self.assertNotIn("Demo data** — optional", guide)
                self.assertIn("refuses to run when `APP_ENV=production`", guide)

    def test_production_deploy_hook_does_not_seed_demo_records(self):
        self.assertNotIn("db:seed", DEPLOY_HOOK)


if __name__ == "__main__":
    unittest.main(verbosity=2)
