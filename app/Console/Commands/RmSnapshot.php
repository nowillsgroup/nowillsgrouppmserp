<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Services\RevenueService;
use App\Support\Tenant;
use Illuminate\Console\Command;

class RmSnapshot extends Command
{
    protected $signature = 'pms:rm-snapshot {--hotel= : Hotel code (default: all hotels)} {--backfill=0 : Also rebuild the last N days from booking history}';

    protected $description = 'Record the on-the-books position for revenue management (the night audit does this automatically)';

    public function handle(): int
    {
        $hotels = Hotel::where('is_active', true)->when($this->option('hotel'), fn ($q, $c) => $q->where('code', $c))->get();
        foreach ($hotels as $hotel) {
            Tenant::set($hotel);
            $days = max(0, min(90, (int) $this->option('backfill')));
            for ($i = $days; $i >= 1; $i--) {
                RevenueService::reconstruct(bdate()->copy()->subDays($i));
            }
            $n = RevenueService::snapshot(bdate()->copy()->subDay()); // position at the end of the last closed day
            $this->line(sprintf('[%s] %d day(s) rebuilt, %d on-the-books row(s) recorded for %s', $hotel->code, $days, $n, bdate()->copy()->subDay()->toDateString()));
        }

        return self::SUCCESS;
    }
}
