<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Services\CroService;
use App\Support\Tenant;
use Illuminate\Console\Command;

class CroExpire extends Command
{
    protected $signature = 'pms:cro-expire {--hotel= : Hotel code (default: all hotels)}';

    protected $description = 'Close open reservations enquiries whose arrival has passed or that stayed open too long (lost – regret, no response)';

    public function handle(): int
    {
        foreach (Hotel::where('is_active', true)->when($this->option('hotel'), fn ($q, $c) => $q->where('code', $c))->get() as $hotel) {
            Tenant::set($hotel);
            CroService::flush();
            $this->line(sprintf('[%s] %d enquiry(ies) lapsed', $hotel->code, CroService::expire()));
        }

        return self::SUCCESS;
    }
}
