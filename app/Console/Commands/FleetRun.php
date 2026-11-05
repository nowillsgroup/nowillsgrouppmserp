<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Services\FleetService;
use App\Services\ActivityLogger;
use App\Support\Tenant;
use Illuminate\Console\Command;

class FleetRun extends Command
{
    protected $signature = 'pms:fleet-run
        {--hotel= : Hotel code (default: all hotels)}
        {--dry-run : Report what would be pruned without deleting anything}';

    protected $description = 'Fleet: prune GPS history beyond the retention window, then report what the transport desk must look at today';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        foreach (Hotel::where('is_active', true)->when($this->option('hotel'), fn ($q, $c) => $q->where('code', $c))->get() as $hotel) {
            Tenant::set($hotel);
            FleetService::ensureDefaults();
            $pruned = $dry ? 0 : FleetService::prune();
            $a = FleetService::alerts();

            // Anything the desk has to act on. Fuel checks and paper expiries are only counted,
            // never auto-repaired: the command reports, a person decides.
            $todo = array_filter([
                'trackers offline' => $a['gps_offline'],
                'trackers never reporting' => $a['no_signal'],
                'services overdue' => $a['maintenance_overdue'],
                'open work orders' => $a['open_repairs'],
                'inspections due' => $a['inspection_due'],
                'papers expired' => $a['papers_expired'],
                'papers expiring' => $a['papers_soon'],
                'fuel fills to check' => $a['fuel_checks'],
                'restricted-area entries today' => $a['restricted_today'],
                'speeding vehicles today' => $a['speed_today'],
                'vehicles off-trip today' => $a['off_trip_today'],
            ]);

            $head = $dry
                ? "[{$hotel->code}] dry run · GPS pruning skipped · "
                : "[{$hotel->code}] {$pruned} position(s) pruned · ";

            $this->line($head.($todo
                ? collect($todo)->map(fn ($n, $k) => "{$n} {$k}")->implode(' · ')
                : 'nothing needs attention'));

            if ($todo) {
                ActivityLogger::log('transport', 'fleet', 'Nightly fleet digest: '
                    .collect($todo)->map(fn ($n, $k) => "{$n} {$k}")->implode(', ').($dry ? ' (dry run)' : ''));
            }
        }

        return self::SUCCESS;
    }
}
