#!/usr/bin/env python3
"""Static safety checks for the Administration import/export hub."""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[3]
CONFIG = (ROOT / "config/pms.php").read_text()
ROUTES = (ROOT / "routes/web.php").read_text()
PAGE = (ROOT / "resources/views/settings/migration/index.blade.php").read_text()
CONTROLLER = (ROOT / "app/Http/Controllers/DataMigrationController.php").read_text()
SERVICE = (ROOT / "app/Services/Migration/MigrationService.php").read_text()
BACKUP = (ROOT / "app/Services/BackupService.php").read_text()
BANQUET_IMPORTER = (ROOT / "app/Services/Migration/Importers/BanquetEnquiryImporter.php").read_text()
MIGRATION_SHOW = (ROOT / "resources/views/settings/migration/show.blade.php").read_text()
CLI_IMPORT = (ROOT / "app/Console/Commands/DataImport.php").read_text()


class DataImportExportHubTest(unittest.TestCase):
    def test_hub_is_exposed_in_administration_and_permission_gated(self):
        self.assertIn("['label' => 'Import & Export Data', 'route' => 'migration.index', 'perm' => 'settings.manage']", CONFIG)
        self.assertIn("Route::prefix('settings/migration')", ROUTES)
        self.assertIn("Route::middleware('perm:settings.manage')->group", ROUTES)
        self.assertIn("Route::get('/settings/backup'", ROUTES)

    def test_all_registered_import_datasets_have_template_actions_and_modes(self):
        dataset_block = SERVICE.split("public const DATASETS = [", 1)[1].split("\n    ];", 1)[0]
        datasets = re.findall(r"^\s*'([^']+)'\s*=>", dataset_block, re.M)
        self.assertGreaterEqual(len(datasets), 15)
        self.assertIn("@foreach($order as $k)", PAGE)
        self.assertIn("route('migration.template', $k)", PAGE)
        self.assertIn("$templateImporter->supportsApply()", PAGE)
        self.assertIn("Preflight only", PAGE)
        self.assertIn("Download CSV template", PAGE)
        for dataset in datasets:
            with self.subTest(dataset=dataset):
                self.assertIn(f"'{dataset}'", dataset_block)

    def test_property_export_uses_tenant_scoped_backup_and_never_accepts_raw_sql_upload(self):
        self.assertIn("route('settings.backup.download')", PAGE)
        self.assertIn('name="type" value="hotel"', PAGE)
        self.assertIn('response()->download($path)', (ROOT / "app/Http/Controllers/SettingController.php").read_text())
        self.assertIn("public static function hotel(int $hotelId", BACKUP)
        self.assertIn("Schema::hasColumn($t, 'hotel_id')", BACKUP)
        self.assertIn("where('hotel_id', $hotelId)", BACKUP)
        self.assertIn("'file' => 'required|file|max:20480|mimes:csv,txt,tsv'", CONTROLLER)
        self.assertIn("'dataset' => 'required|string|in:'", CONTROLLER)
        self.assertIn("Do not use this tool to replay live payments, refunds, POS settlements or journals.", PAGE)
        self.assertNotIn('accept=".sql', PAGE)

    def test_uploads_run_dry_run_before_apply_and_keep_batch_review(self):
        self.assertIn("MigrationService::run($batch, true)", CONTROLLER)
        self.assertIn("MigrationService::run($batch, false)", CONTROLLER)
        self.assertIn("route('migration.show', $batch)", CONTROLLER)
        self.assertIn('Every batch retains the uploaded CSV, row outcomes and a reconciliation report.', PAGE)
        self.assertIn('Batches', PAGE)

    def test_banquet_transfer_is_limited_to_authorized_enquiries(self):
        self.assertIn("'banquet_enquiries' => BanquetEnquiryImporter::class", SERVICE)
        self.assertIn("can_do('banquet.manage')", BANQUET_IMPORTER)
        self.assertIn("$data['status'] = 'enquiry'", BANQUET_IMPORTER)
        self.assertIn("where('status', 'enquiry')->whereNull('folio_id')->whereNull('billed_at')", BANQUET_IMPORTER)
        self.assertIn("'enquiry'", BANQUET_IMPORTER)
        self.assertIn("Run a clean dry-run with zero failed rows before importing banquet enquiries.", CONTROLLER)
        self.assertIn('IMPORT BANQUET ENQUIRIES', MIGRATION_SHOW)
        self.assertIn('Deposit amounts/payments, folios, billing, and GL entries are not imported or changed.', MIGRATION_SHOW)
        self.assertIn('the upload cannot set event status or open venue holds', MIGRATION_SHOW)

    def test_banquet_import_cannot_call_hold_or_money_workflows_and_is_tenant_scoped(self):
        for forbidden in [
            "BanquetSalesService::takeDeposit(",
            "BanquetSalesService::takeDeposits(",
            "BanquetSalesService::folioFor(",
            "BanquetSalesService::confirm(",
            "BanquetSalesService::holdTentative(",
            "FolioService::",
            "FolioTransaction::create(",
        ]:
            with self.subTest(forbidden=forbidden):
                self.assertNotIn(forbidden, BANQUET_IMPORTER)
        self.assertIn("where('hotel_id', Tenant::id())", BANQUET_IMPORTER)
        self.assertIn("'owned:banquet_halls'", BANQUET_IMPORTER)
        self.assertIn("'owned:banquet_packages'", BANQUET_IMPORTER)
        self.assertIn("'owned:banquet_services'", BANQUET_IMPORTER)
        self.assertIn("'banquet_enquiries'", SERVICE)
        self.assertIn("$dataset === 'banquet_enquiries' && ! $this->option('dry-run')", CLI_IMPORT)
        self.assertIn('clean dry-run and typed confirmation', CLI_IMPORT)
        self.assertIn('What the CSV can carry', PAGE)
        self.assertIn('Enquiry-only import', PAGE)
        self.assertIn("where('hotel_id', Tenant::id())", CONTROLLER)
        self.assertIn("'hall_label'", CONTROLLER)
        self.assertIn("'package_label'", CONTROLLER)

    def test_menu_item_variant_rates_stay_optional_on_the_legacy_import(self):
        """Side-dish rate/cost are optional everywhere they are written, including the CSV importer."""
        importer = (ROOT / "app/Services/Migration/Importers/MenuItemImporter.php").read_text()
        for marker in (
            "'variants.*.rate' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99']",
            "'variants.*.cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99']",
            "number_format(round((float) ($variant['rate'] ?? 0), 2), 2, '.', '')",
            "number_format(round((float) ($variant['cost'] ?? 0), 2), 2, '.', '')",
            "rate and cost may be left blank (saved as 0 = included in the item price)",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, importer)
        doc = (ROOT / "docs/DATA-MIGRATION.md").read_text()
        self.assertIn("`rate` and `cost` are optional", doc)


if __name__ == "__main__":
    unittest.main(verbosity=2)
