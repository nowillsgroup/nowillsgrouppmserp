<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Services\BanquetSalesService;
use App\Support\Tenant;
use Illuminate\Console\Command;

class BanquetRelease extends Command
{
    protected $signature = 'pms:banquet-release {--hotel= : Hotel code (default: all hotels)}';

    protected $description = 'Release tentative banquet holds whose option date has passed (marks them lost, frees the venue)';

    public function handle(): int
    {
        foreach (Hotel::where('is_active', true)->when($this->option('hotel'), fn ($q, $c) => $q->where('code', $c))->get() as $hotel) {
            Tenant::set($hotel);
            $this->line(sprintf('[%s] %d tentative hold(s) released', $hotel->code, BanquetSalesService::releaseExpired()));
        }

        return self::SUCCESS;
    }
}
