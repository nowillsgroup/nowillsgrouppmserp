<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Services\TransportService;
use App\Support\Tenant;
use Illuminate\Console\Command;

class TransportRun extends Command
{
    protected $signature = 'pms:transport-run {--hotel= : Hotel code (default: all hotels)}';

    protected $description = 'Transport desk: remind drivers before they leave, close transfer requests nobody confirmed in time';

    public function handle(): int
    {
        foreach (Hotel::where('is_active', true)->when($this->option('hotel'), fn ($q, $c) => $q->where('code', $c))->get() as $hotel) {
            Tenant::set($hotel);
            $r = TransportService::runDue();
            if (array_sum($r)) {
                $this->line("[{$hotel->code}] {$r['reminders']} driver reminder(s), {$r['expired']} request(s) expired");
            }
        }

        return self::SUCCESS;
    }
}
