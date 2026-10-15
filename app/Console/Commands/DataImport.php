<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Services\Migration\MigrationService;
use App\Support\Tenant;
use Illuminate\Console\Command;

/**
 * Cut legacy PMS data into Nowills PMS from a CSV export (v7.35).
 *
 *   pms:import guests /path/guests.csv --hotel=1 --source="Exceed PMS"
 *   pms:import guests /path/guests.csv --hotel=1 --dry-run
 */
class DataImport extends Command
{
    protected $signature = 'pms:import
        {dataset : One of: companies, guests, employees, rooms, reservations}
        {file : Path to the CSV export}
        {--hotel= : Hotel (property) id to import into}
        {--source= : Label of the legacy system / export date}
        {--dry-run : Validate only – record outcomes, create nothing}';

    protected $description = 'Import a legacy-system CSV export (data migration & cutover)';

    public function handle(): int
    {
        $dataset = (string) $this->argument('dataset');
        $file = (string) $this->argument('file');
        if (! is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        $hotel = $this->resolveHotel();
        if (! $hotel) {
            return self::FAILURE;
        }
        Tenant::set($hotel);
        $this->info("Importing {$dataset} into {$hotel->name} (id {$hotel->id})".($this->option('dry-run') ? ' [dry run]' : ''));

        $batch = MigrationService::register(
            $hotel,
            $dataset,
            $file,
            $this->option('source') ? (string) $this->option('source') : null,
            basename($file),
            null,
        );
        $this->info("Batch {$batch->code} registered with {$batch->rows_total} rows.");

        $tally = MigrationService::run($batch, (bool) $this->option('dry-run'));
        $this->table(
            ['Would create / Created', 'Would update / Updated', 'Unchanged / Skipped', 'Failed'],
            [[$tally['created'], $tally['updated'], $tally['skipped'], $tally['failed']]],
        );

        $fails = $batch->rows()->where('status', 'failed')->orderBy('row_no')->get();
        foreach ($fails->take(25) as $f) {
            $this->warn("  row {$f->row_no}: {$f->message}");
        }
        if ($fails->count() > 25) {
            $this->warn('  … '.($fails->count() - 25).' more failed rows – see the Data Migration screen.');
        }

        $this->line('Reconciliation:');
        foreach (MigrationService::report($batch) as $label => $n) {
            $this->line(sprintf('  %-45s %s', $label, is_numeric($n) ? number_format((float) $n) : $n));
        }

        return $tally['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function resolveHotel(): ?Hotel
    {
        $id = $this->option('hotel');
        if ($id) {
            $hotel = Hotel::withoutGlobalScopes()->find((int) $id);
            if (! $hotel) {
                $this->error("Hotel id {$id} not found.");

                return null;
            }

            return $hotel;
        }
        $hotels = Hotel::withoutGlobalScopes()->orderBy('id')->get();
        if ($hotels->count() === 1) {
            return $hotels->first();
        }
        $this->error('This installation has several properties – pass --hotel=ID. Options:');
        foreach ($hotels as $h) {
            $this->line("  {$h->id}  {$h->code}  {$h->name}");
        }

        return null;
    }
}
