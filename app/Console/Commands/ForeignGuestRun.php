<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Services\ForexService;
use App\Services\FormCService;
use App\Support\Tenant;
use Illuminate\Console\Command;

class ForeignGuestRun extends Command
{
    protected $signature = 'pms:foreign-run {--hotel= : Hotel code (default: all hotels)}';

    protected $description = 'Foreign guests: e-mail the desk about Form C reports due / overdue; flag foreign currency held beyond the surrender limit';

    public function handle(): int
    {
        $prev = Tenant::hotel();
        foreach (Hotel::where('is_active', true)->when($this->option('hotel'), fn ($q, $c) => $q->where('code', $c))->get() as $hotel) {
            Tenant::set($hotel);
            $r = FormCService::runDue();
            $fx = ForexService::alerts();
            if ($r['due'] + $r['overdue'] || $fx['surrender_overdue']) {
                $this->line("[{$hotel->code}] Form C: {$r['due']} due soon, {$r['overdue']} overdue ({$r['mails']} alert e-mail) · forex: {$fx['surrender_overdue']} currency lot(s) held beyond {$fx['surrender_days']} days");
            }
        }
        Tenant::set($prev); // leave the caller's property selected

        return self::SUCCESS;
    }
}
