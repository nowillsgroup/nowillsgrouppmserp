<?php

namespace App\Http\Controllers;

use App\Models\DataMigrationBatch;
use App\Models\BanquetHall;
use App\Models\BanquetPackage;
use App\Models\BanquetService;
use App\Models\Company;
use App\Services\Migration\MigrationService;
use App\Services\Migration\Importers\ConfiguredMasterImporter;
use App\Services\Migration\Importers\MenuItemImporter;
use App\Services\Migration\Importers\BanquetEnquiryImporter;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Data migration & cutover console (v7.35): upload a legacy-system CSV,
 * dry-run it, apply it, and reconcile the result.
 */
class DataMigrationController extends Controller
{
    public function index()
    {
        $allImporters = MigrationService::importers();
        $order = array_values(array_filter(
            MigrationService::orderedDatasetKeys(),
            fn ($key) => MigrationService::canAccessDataset($key),
        ));
        $importers = array_intersect_key($allImporters, array_flip($order));
        $batches = DataMigrationBatch::whereIn('dataset', $order)->latest('id')->paginate(25)->withQueryString();

        return view('settings.migration.index', compact('batches', 'importers', 'order'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'dataset' => 'required|string|in:'.implode(',', MigrationService::datasetKeys()),
            'source_name' => 'nullable|string|max:120',
            'file' => 'required|file|max:20480|mimes:csv,txt,tsv',
        ]);

        $batch = MigrationService::register(
            Tenant::hotel(),
            $data['dataset'],
            $request->file('file')->getRealPath(),
            $data['source_name'] ?? null,
            $request->file('file')->getClientOriginalName(),
            auth()->id(),
        );
        // Validate right away so the user sees row outcomes without a second click.
        MigrationService::run($batch, true);

        return redirect()->route('migration.show', $batch)
            ->with('success', "Batch {$batch->code} registered and dry-run validated.");
    }

    public function show(DataMigrationBatch $batch)
    {
        MigrationService::assertCanAccessDataset($batch->dataset);
        $rows = $batch->rows()->orderBy('row_no')->paginate(50)->withQueryString();
        $importer = MigrationService::importer($batch->dataset);
        $banquetPreview = $batch->dataset === 'banquet_enquiries' ? $this->banquetPreview($rows) : [];

        return view('settings.migration.show', [
            'batch' => $batch,
            'rows' => $rows,
            'importer' => $importer,
            'banquetPreview' => $banquetPreview,
            'counts' => $batch->counts(),
            'report' => ($batch->status === 'imported' || (in_array($batch->dataset, ['opening_ap', 'opening_ar', 'opening_gl', 'historical_stock', 'inventory_allergens', 'paid_supplier_history', 'bank_account_mappings'], true) && $batch->status === 'validated'))
                ? MigrationService::report($batch)
                : null,
        ]);
    }

    /** Resolve the imported property-local banquet ids for the review table without writing anything. */
    private function banquetPreview($rows): array
    {
        $payloads = [];
        $companyIds = $hallIds = $packageIds = $serviceIds = [];
        foreach ($rows->getCollection() as $row) {
            $payload = (array) $row->payload;
            $companyId = $payload['company_id'] ?? null;
            if (is_numeric($companyId) && (int) $companyId > 0) $companyIds[] = (int) $companyId;
            $functions = json_decode((string) ($payload['functions_json'] ?? ''), true);
            $payloads[$row->id] = ['company_id' => $companyId, 'functions' => is_array($functions) && array_is_list($functions) ? $functions : null];
            foreach ($payloads[$row->id]['functions'] ?? [] as $function) {
                if (! is_array($function)) continue;
                $hallId = $function['banquet_hall_id'] ?? null;
                $packageId = $function['banquet_package_id'] ?? null;
                if (is_numeric($hallId) && (int) $hallId > 0) $hallIds[] = (int) $hallId;
                if (is_numeric($packageId) && (int) $packageId > 0) $packageIds[] = (int) $packageId;
                $items = $function['items'] ?? [];
                if (! is_array($items)) continue;
                foreach ($items as $item) {
                    $serviceId = is_array($item) ? ($item['banquet_service_id'] ?? null) : null;
                    if (is_numeric($serviceId) && (int) $serviceId > 0) $serviceIds[] = (int) $serviceId;
                }
            }
        }

        $names = static fn ($model, array $ids) => $ids ? $model::where('hotel_id', Tenant::id())->whereIn('id', array_unique($ids))->pluck('name', 'id')->all() : [];
        $companies = $names(Company::class, $companyIds);
        $halls = $names(BanquetHall::class, $hallIds);
        $packages = $names(BanquetPackage::class, $packageIds);
        $services = $names(BanquetService::class, $serviceIds);
        $preview = [];
        foreach ($payloads as $rowId => $payload) {
            $companyId = $payload['company_id'];
            $functions = $payload['functions'];
            if (is_array($functions)) {
                foreach ($functions as &$function) {
                    if (! is_array($function)) continue;
                    $hallId = $function['banquet_hall_id'] ?? null;
                    $packageId = $function['banquet_package_id'] ?? null;
                    $function['hall_label'] = $halls[(int) $hallId] ?? (is_numeric($hallId) ? 'Venue #'.(int) $hallId.' (not found in this property)' : 'Venue not specified');
                    $function['package_label'] = $packages[(int) $packageId] ?? (is_numeric($packageId) ? 'Package #'.(int) $packageId.' (not found in this property)' : null);
                    $items = $function['items'] ?? [];
                    if (is_array($items)) {
                        foreach ($items as &$item) {
                            if (! is_array($item)) continue;
                            $serviceId = $item['banquet_service_id'] ?? null;
                            $item['service_label'] = $services[(int) $serviceId] ?? (is_numeric($serviceId) ? 'Service #'.(int) $serviceId.' (not found in this property)' : null);
                        }
                        unset($item);
                        $function['items'] = $items;
                    }
                }
                unset($function);
            }
            $preview[$rowId] = [
                'company_label' => $companies[(int) $companyId] ?? (is_numeric($companyId) ? 'Company #'.(int) $companyId.' (not found in this property)' : null),
                'functions' => $functions,
            ];
        }

        return $preview;
    }

    public function validate(DataMigrationBatch $batch)
    {
        MigrationService::assertCanAccessDataset($batch->dataset);
        MigrationService::run($batch, true);

        return redirect()->route('migration.show', $batch)
            ->with('success', 'Dry-run complete – nothing was written.');
    }

    public function apply(Request $request, DataMigrationBatch $batch)
    {
        MigrationService::assertCanAccessDataset($batch->dataset);
        $request->validate(['confirm' => 'required|in:yes']);
        if ($batch->status === 'imported') {
            throw ValidationException::withMessages(['batch' => 'This batch has already been imported. Upload a new file for corrections.']);
        }
        if ($batch->dataset === 'banquet_enquiries') {
            if ($batch->status !== 'validated' || $batch->rows_failed > 0) {
                throw ValidationException::withMessages(['batch' => 'Run a clean dry-run with zero failed rows before importing banquet enquiries.']);
            }
            if ($request->input('confirm_sensitive') !== 'IMPORT BANQUET ENQUIRIES') {
                throw ValidationException::withMessages(['confirm_sensitive' => 'Type IMPORT BANQUET ENQUIRIES exactly to confirm this non-binding enquiry import.']);
            }
        }
        if (in_array($batch->dataset, ['masters__rate-plans', 'masters__taxes', 'masters__account-heads', 'menu_items'], true)) {
            $requiredPhrase = match ($batch->dataset) {
                'masters__taxes' => 'APPLY TAX SETTINGS',
                'masters__account-heads' => 'APPLY CHART OF ACCOUNTS',
                'menu_items' => 'APPLY MENU ITEMS',
                default => 'APPLY RATE PLANS',
            };
            if ($batch->status !== 'validated' || $batch->rows_failed > 0) {
                throw ValidationException::withMessages(['batch' => 'Run a clean dry-run with zero failed rows before applying pricing, tax or chart-of-accounts data.']);
            }
            if ($request->input('confirm_sensitive') !== $requiredPhrase) {
                throw ValidationException::withMessages(['confirm_sensitive' => 'Type '.$requiredPhrase.' exactly to confirm this financial master-data change.']);
            }
        }
        if (! MigrationService::importer($batch->dataset)->supportsApply()) {
            $message = match ($batch->dataset) {
                'opening_gl' => 'Opening GL is preflight-only; posting remains disabled pending AP-policy approval, subledger sign-off and duplicate-posting controls.',
                'inventory_allergens' => 'Inventory allergen declarations are preflight-only; review source evidence with Food Safety staff before entering or verifying any declaration.',
                'bank_account_mappings' => 'Bank account mappings are read-only preflight only; no Treasury account, statement, ID map or GL entry is created or changed.',
                default => 'This dataset is preflight-only; no apply path is available.',
            };
            throw ValidationException::withMessages(['batch' => $message]);
        }
        $tally = MigrationService::run($batch, false);

        $message = match ($batch->dataset) {
            'historical_stock' => sprintf('Archive complete: %d immutable movement rows created, %d already present, %d blocked. No operational stock or GL was written.', $tally['created'], $tally['skipped'], $tally['failed']),
            'paid_supplier_history' => sprintf('Archive complete: %d immutable supplier-history rows created, %d already present, %d blocked. No AP subledger or GL was written.', $tally['created'], $tally['skipped'], $tally['failed']),
            default => sprintf('Imported: %d created, %d updated, %d skipped, %d failed.', $tally['created'], $tally['updated'], $tally['skipped'], $tally['failed']),
        };

        return redirect()->route('migration.show', $batch)->with('success', $message);
    }

    /** CSV template for a dataset (header + one example row). */
    public function template(string $dataset)
    {
        MigrationService::assertCanAccessDataset($dataset);
        $importer = MigrationService::importer($dataset);
        $head = array_keys($importer->columns());
        $csv = implode(',', array_map(fn ($c) => '"'.str_replace('"', '""', $c).'"', $head))."\n";
        foreach ($importer->templateRows() as $example) {
            $values = array_map(fn ($c) => $example[$c] ?? '', $head);
            $csv .= implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', (string) $v).'"', $values))."\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$dataset.'-import-template.csv"',
        ]);
    }

    /** Export only the safe fields exposed by the selected property-scoped adapter. */
    public function export(string $dataset)
    {
        abort_unless(MigrationService::isConfiguredMasterDataset($dataset) || in_array($dataset, ['menu_items', 'banquet_enquiries'], true), 404);
        MigrationService::assertCanAccessDataset($dataset);
        /** @var ConfiguredMasterImporter|MenuItemImporter|BanquetEnquiryImporter $importer */
        $importer = MigrationService::importer($dataset);
        $columns = array_keys($importer->columns());

        return response()->streamDownload(function () use ($importer, $columns) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $columns);
            foreach ($importer->exportRows() as $row) {
                $values = array_map(function ($column) use ($row) {
                    $value = $row[$column] ?? '';
                    if (is_string($value) && preg_match('/^\s*[=+@-]/', $value)) {
                        return "'".$value;
                    }

                    return $value;
                }, $columns);
                fputcsv($out, $values);
            }
            fclose($out);
        }, $dataset.'-property-export.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
