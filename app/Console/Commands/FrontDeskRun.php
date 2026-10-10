<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Services\FrontDeskOpsService;
use App\Services\WakeupService;
use App\Support\Tenant;
use Illuminate\Console\Command;

class FrontDeskRun extends Command
{
    protected $signature = 'pms:frontdesk-run {--hotel= : Hotel code (default: all hotels)}';

    protected $description = 'Front desk: escalate wake-up calls that were not made on time, close long-missed ones (daily series continue)';

    public function handle(): int
    {
        $prev = Tenant::hotel();
        foreach (Hotel::where('is_active', true)->when($this->option('hotel'), fn ($q, $c) => $q->where('code', $c))->get() as $hotel) {
            Tenant::set($hotel);
            $w = WakeupService::run();
            $c = FrontDeskOpsService::counts(0);
            if ($w['escalated'] || $w['missed'] || $c['departed_parcels']) {
                $this->line("[{$hotel->code}] wake-ups: {$w['escalated']} escalated, {$w['missed']} closed as missed · {$c['departed_parcels']} parcel(s) held for departed guests");
            }
        }
        Tenant::set($prev); // leave the caller's property selected

        return self::SUCCESS;
    }
}
