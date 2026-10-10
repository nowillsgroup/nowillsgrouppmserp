<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Services\TableBookingService;
use App\Support\Tenant;
use Illuminate\Console\Command;

class TablesRun extends Command
{
    protected $signature = 'pms:tables-run {--hotel= : Hotel code (default: all hotels)}';

    protected $description = 'Restaurant table reservations: send reminders, mark late parties as no-shows, close yesterday\'s waitlist and forgotten seated parties';

    public function handle(): int
    {
        foreach (Hotel::where('is_active', true)->when($this->option('hotel'), fn ($q, $c) => $q->where('code', $c))->get() as $hotel) {
            Tenant::set($hotel);
            $r = TableBookingService::runDue();
            if (array_sum($r)) {
                $this->line("[{$hotel->code}] {$r['reminders']} reminder(s), {$r['no_shows']} no-show(s), {$r['waitlist_closed']} waitlist closed, {$r['closed_seated']} seated closed");
            }
        }

        return self::SUCCESS;
    }
}
