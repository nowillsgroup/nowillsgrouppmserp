<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Services\PmService;
use App\Support\Tenant;
use Illuminate\Console\Command;

class PmGenerate extends Command
{
    protected $signature = 'pms:pm-generate {--hotel= : Hotel code (default: all hotels)}';

    protected $description = 'Raise preventive-maintenance tickets for schedules that fall due within their lead time';

    public function handle(): int
    {
        foreach (Hotel::where('is_active', true)->when($this->option('hotel'), fn ($q, $c) => $q->where('code', $c))->get() as $hotel) {
            Tenant::set($hotel);
            $made = PmService::generate();
            $this->line(sprintf('[%s] %d PM ticket(s) raised%s', $hotel->code, count($made), $made ? ': '.collect($made)->pluck('ticket_no')->implode(', ') : ''));
        }

        return self::SUCCESS;
    }
}
