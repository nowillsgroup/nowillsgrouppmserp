<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Services\SecurityService;
use App\Support\Tenant;
use Illuminate\Console\Command;

class SecurityRun extends Command
{
    protected $signature = 'pms:security {--hotel= : Hotel code (default: all hotels)}';

    protected $description = 'Security: alert visitor overstays, lapse no-show pre-registered visitors, alert overdue gate-pass returns, anonymise visitor ID data past retention';

    public function handle(): int
    {
        $prev = Tenant::hotel();
        foreach (Hotel::where('is_active', true)->when($this->option('hotel'), fn ($q, $c) => $q->where('code', $c))->get() as $hotel) {
            Tenant::set($hotel);
            $r = SecurityService::run();
            $this->line("[{$hotel->code}] {$r['overstays']} overstay alert(s) · {$r['no_shows']} no-show visitor(s) · {$r['overdue_passes']} overdue gate pass(es) · {$r['anonymised']} old visitor record(s) anonymised");
        }
        Tenant::set($prev);

        return self::SUCCESS;
    }
}
