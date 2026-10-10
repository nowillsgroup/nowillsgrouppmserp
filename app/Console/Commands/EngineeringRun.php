<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Services\ComplianceService;
use App\Services\UtilityService;
use App\Support\Tenant;
use Illuminate\Console\Command;

class EngineeringRun extends Command
{
    protected $signature = 'pms:engineering {--hotel= : Hotel code (default: all hotels)}';

    protected $description = 'Engineering: licence / inspection renewal reminders (digest e-mail + log book) and the utility meter-reading status';

    public function handle(): int
    {
        $prev = Tenant::hotel();
        foreach (Hotel::where('is_active', true)->when($this->option('hotel'), fn ($q, $c) => $q->where('code', $c))->get() as $hotel) {
            Tenant::set($hotel);
            $c = ComplianceService::alerts();
            $missing = UtilityService::missing(bdate()->copy()->subDay()->toDateString(), true)->count();
            if ($c['due'] || $c['expired'] || $missing) {
                $this->line("[{$hotel->code}] {$c['due']} licence(s) newly due, {$c['expired']} newly expired, {$c['mails']} e-mail(s) · {$missing} meter(s) not read yesterday");
            }
        }
        Tenant::set($prev);

        return self::SUCCESS;
    }
}
