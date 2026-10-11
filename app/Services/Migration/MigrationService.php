<?php

namespace App\Services\Migration;

use App\Models\DataMigrationBatch;
use App\Models\DataMigrationRow;
use App\Models\Hotel;
use App\Services\ActivityLogger;
use App\Services\Migration\Importers\CompanyImporter;
use App\Services\Migration\Importers\EmployeeImporter;
use App\Services\Migration\Importers\GuestImporter;
use App\Services\Migration\Importers\ReservationImporter;
use App\Services\Migration\Importers\RoomImporter;
use App\Support\Numbering;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Legacy PMS → Nowills PMS data migration & cutover (v7.35).
 *
 * Workflow per dataset file: register → validate (dry-run) → apply → report.
 * Applies are idempotent: rows whose external id is already mapped update the
 * existing record or skip it when unchanged. Nothing is silently overwritten
 * with errors — every row gets a recorded outcome.
 */
class MigrationService
{
    public const DATASETS = [
        'companies' => CompanyImporter::class,
        'guests' => GuestImporter::class,
        'employees' => EmployeeImporter::class,
        'rooms' => RoomImporter::class,
        'reservations' => ReservationImporter::class,
    ];

    /** Order that respects cross-dataset references. */
    public const ORDER = ['companies', 'guests', 'employees', 'rooms', 'reservations'];

    public const MAX_ROWS = 5000;

    public static function importers(): array
    {
        $out = [];
        foreach (self::DATASETS as $key => $class) {
            $out[$key] = new $class;
        }

        return $out;
    }

    public static function importer(string $dataset): DatasetImporter
    {
        if (! isset(self::DATASETS[$dataset])) {
            throw ValidationException::withMessages(['dataset' => "Unknown dataset \"{$dataset}\". Choose: ".implode(', ', array_keys(self::DATASETS)).'.']);
        }

        return new (self::DATASETS[$dataset]);
    }

    /**
     * Register an uploaded CSV as a batch and store its parsed rows.
     */
    public static function register(Hotel $hotel, string $dataset, string $tmpPath, ?string $sourceName = null, ?string $originalName = null, ?int $userId = null): DataMigrationBatch
    {
        $importer = self::importer($dataset);

        $header = null;
        $rows = [];
        $fh = fopen($tmpPath, 'r');
        if (! $fh) {
            throw ValidationException::withMessages(['file' => 'The file could not be read.']);
        }
        try {
            $header = fgetcsv($fh, 0, ',', '"', '');
            if (! is_array($header)) {
                throw ValidationException::withMessages(['file' => 'The file is empty or not a CSV.']);
            }
            $header = array_map(fn ($h) => preg_replace('/^\xEF\xBB\xBF/', '', (string) $h), $header);
            $canonical = $importer->canonicalize($header, array_fill(0, count($header), ''));
            foreach ($importer->columns() as $name => $spec) {
                if (! empty($spec['required']) && ! array_key_exists($name, $canonical)) {
                    throw ValidationException::withMessages(['file' => "Missing required column: {$name}. {$spec['desc']}"]);
                }
            }
            $line = 1;
            while (($csvRow = fgetcsv($fh, 0, ',', '"', '')) !== false) {
                $line++;
                if (count(array_filter($csvRow, fn ($v) => trim((string) $v) !== '')) === 0) {
                    continue;
                }
                if (count($rows) >= self::MAX_ROWS) {
                    throw ValidationException::withMessages(['file' => 'Stopped after '.self::MAX_ROWS.' rows – split the file and import it in batches.']);
                }
                $rows[] = ['no' => $line, 'data' => $importer->canonicalize($header, $csvRow)];
            }
        } finally {
            fclose($fh);
        }
        if (! $rows) {
            throw ValidationException::withMessages(['file' => 'The CSV has a header but no data rows.']);
        }

        return DB::transaction(function () use ($hotel, $dataset, $tmpPath, $sourceName, $originalName, $userId, $rows) {
            Tenant::set($hotel);
            $batch = new DataMigrationBatch([
                'code' => Numbering::next('migration'),
                'dataset' => $dataset,
                'source_name' => $sourceName ? mb_substr($sourceName, 0, 120) : null,
                'status' => 'registered',
                'rows_total' => count($rows),
                'created_by' => $userId,
            ]);
            $batch->save();

            $dir = 'migrations/'.$batch->code;
            Storage::makeDirectory($dir);
            $stored = $dir.'/'.($originalName ? preg_replace('/[^A-Za-z0-9._-]/', '_', basename($originalName)) : 'source.csv');
            Storage::put($stored, file_get_contents($tmpPath) ?: '');
            $batch->file_path = $stored;
            $batch->save();

            foreach ($rows as $r) {
                DataMigrationRow::create([
                    'batch_id' => $batch->id,
                    'row_no' => $r['no'],
                    'external_id' => isset($r['data']['external_id']) ? mb_substr((string) $r['data']['external_id'], 0, 100) : null,
                    'status' => 'pending',
                    'payload' => $r['data'],
                ]);
            }
            ActivityLogger::log('migration', 'register', "Registered migration batch {$batch->code} ({$dataset}, ".count($rows).' rows)', $batch);

            return $batch;
        });
    }

    /**
     * Validate (dry-run) or apply a batch row by row.
     *
     * @return array{created:int, updated:int, skipped:int, failed:int}
     */
    public static function run(DataMigrationBatch $batch, bool $dryRun): array
    {
        $importer = self::importer($batch->dataset);
        $ctx = new ImportContext($batch, $batch->hotel);
        $tally = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];

        $rows = DataMigrationRow::where('batch_id', $batch->id)->orderBy('row_no')->get();
        foreach ($rows as $rowRec) {
            $row = (array) $rowRec->payload;
            $outcome = $ctx->asTenant(function () use ($importer, $row, $ctx, $rowRec, $dryRun) {
                $err = $importer->validateRow($row, $ctx, $rowRec->row_no);
                if ($err !== null) {
                    return ['status' => 'failed', 'message' => $err, 'model_type' => null, 'model_id' => null];
                }

                return $importer->importRow($row, $ctx, $dryRun);
            });

            $status = $outcome['status'];
            if ($dryRun && $status === 'created') {
                $status = 'would_create';
            } elseif ($dryRun && $status === 'updated') {
                $status = 'would_update';
            }
            $rowRec->status = $status;
            $rowRec->message = $outcome['message'] ?? null;
            $rowRec->model_type = $outcome['model_type'] ?? null;
            $rowRec->model_id = $outcome['model_id'] ?? null;
            $rowRec->save();

            $bucket = ['created' => 'created', 'would_create' => 'created', 'updated' => 'updated', 'would_update' => 'updated', 'unchanged' => 'skipped', 'skipped' => 'skipped', 'failed' => 'failed'][$status] ?? 'skipped';
            $tally[$bucket]++;
        }

        $batch->rows_created = $tally['created'];
        $batch->rows_updated = $tally['updated'];
        $batch->rows_skipped = $tally['skipped'];
        $batch->rows_failed = $tally['failed'];
        if ($dryRun) {
            $batch->status = 'validated';
            $batch->validated_at = now();
        } else {
            $batch->status = $tally['failed'] > 0 && $tally['created'] + $tally['updated'] === 0 ? 'failed' : 'imported';
            $batch->imported_at = now();
        }
        $batch->save();

        ActivityLogger::log('migration', $dryRun ? 'validate' : 'import',
            sprintf('%s batch %s (%s): %d created, %d updated, %d skipped, %d failed',
                $dryRun ? 'Validated' : 'Imported', $batch->code, $batch->dataset, $tally['created'], $tally['updated'], $tally['skipped'], $tally['failed']),
            $batch);

        return $tally;
    }

    /** Reconciliation figures for the batch's dataset. */
    public static function report(DataMigrationBatch $batch): array
    {
        $importer = self::importer($batch->dataset);
        $ctx = new ImportContext($batch, $batch->hotel);

        return $ctx->asTenant(fn () => $importer->reconcile($ctx));
    }
}
