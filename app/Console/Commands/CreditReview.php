<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Services\CreditService;
use App\Support\Tenant;
use Illuminate\Console\Command;

class CreditReview extends Command
{
    protected $signature = 'pms:credit-review {--hotel= : Hotel code (default: all hotels)}';

    protected $description = 'Credit control: auto-hold companies overdue beyond the grace days, auto-release holds once the overdue is cleared';

    public function handle(): int
    {
        foreach (Hotel::where('is_active', true)->when($this->option('hotel'), fn ($q, $c) => $q->where('code', $c))->get() as $hotel) {
            Tenant::set($hotel);
            $r = CreditService::review();
            $this->line(sprintf('[%s] %d put on hold, %d released', $hotel->code, $r['held'], $r['released']));
        }

        return self::SUCCESS;
    }
}
