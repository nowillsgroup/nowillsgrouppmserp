<?php

namespace App\Http\Controllers;

use App\Models\DataMigrationBatch;
use App\Services\Migration\MigrationService;
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
        $batches = DataMigrationBatch::latest('id')->paginate(25)->withQueryString();

        return view('settings.migration.index', [
            'batches' => $batches,
            'importers' => MigrationService::importers(),
            'order' => MigrationService::ORDER,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'dataset' => 'required|string|in:'.implode(',', array_keys(MigrationService::DATASETS)),
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
        $rows = $batch->rows()->orderBy('row_no')->paginate(50)->withQueryString();
        $importer = MigrationService::importer($batch->dataset);

        return view('settings.migration.show', [
            'batch' => $batch,
            'rows' => $rows,
            'importer' => $importer,
            'counts' => $batch->counts(),
            'report' => $batch->status === 'imported' ? MigrationService::report($batch) : null,
        ]);
    }

    public function validate(DataMigrationBatch $batch)
    {
        MigrationService::run($batch, true);

        return redirect()->route('migration.show', $batch)
            ->with('success', 'Dry-run complete – nothing was written.');
    }

    public function apply(Request $request, DataMigrationBatch $batch)
    {
        $request->validate(['confirm' => 'required|in:yes']);
        if ($batch->status === 'imported') {
            throw ValidationException::withMessages(['batch' => 'This batch has already been imported. Upload a new file for corrections.']);
        }
        $tally = MigrationService::run($batch, false);

        return redirect()->route('migration.show', $batch)
            ->with('success', sprintf('Imported: %d created, %d updated, %d skipped, %d failed.', $tally['created'], $tally['updated'], $tally['skipped'], $tally['failed']));
    }

    /** CSV template for a dataset (header + one example row). */
    public function template(string $dataset)
    {
        $importer = MigrationService::importer($dataset);
        $head = array_keys($importer->columns());
        $example = array_map(fn ($c) => $importer->columns()[$c]['example'] ?? '', $head);

        $csv = implode(',', array_map(fn ($c) => '"'.str_replace('"', '""', $c).'"', $head))."\n"
            .implode(',', array_map(fn ($c) => '"'.str_replace('"', '""', (string) $c).'"', $example))."\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$dataset.'-import-template.csv"',
        ]);
    }
}
