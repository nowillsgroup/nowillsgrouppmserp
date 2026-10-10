<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Services\UpsellService;
use App\Support\Tenant;
use Illuminate\Console\Command;

class UpsellRun extends Command
{
    protected $signature = 'pms:upsell {--hotel= : Hotel code (default: all hotels)}';

    protected $description = 'Upselling: e-mail personalised offers to confirmed guests arriving within the window (once each) and decline requests nobody confirmed in time';

    public function handle(): int
    {
        $prev = Tenant::hotel();
        foreach (Hotel::where('is_active', true)->when($this->option('hotel'), fn ($q, $c) => $q->where('code', $c))->get() as $hotel) {
            Tenant::set($hotel);
            $r = UpsellService::run();
            $this->line("[{$hotel->code}] offers e-mailed to {$r['sent']} guest(s)".($r['failed'] ? ", {$r['failed']} failed" : '')." · {$r['expired']} stale request(s) declined");
        }
        Tenant::set($prev);

        return self::SUCCESS;
    }
}
