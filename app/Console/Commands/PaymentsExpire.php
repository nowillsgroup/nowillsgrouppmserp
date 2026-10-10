<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Services\OnlinePaymentService;
use App\Support\Tenant;
use Illuminate\Console\Command;

class PaymentsExpire extends Command
{
    protected $signature = 'pms:payments-expire {--hotel= : Hotel code (default: all hotels)}';

    protected $description = 'Expire unpaid payment links (checking the gateway first) and release unpaid online-booking holds';

    public function handle(): int
    {
        foreach (Hotel::where('is_active', true)->when($this->option('hotel'), fn ($q, $c) => $q->where('code', $c))->get() as $hotel) {
            Tenant::set($hotel);
            $s = OnlinePaymentService::expire();
            $this->line(sprintf('[%s] %d expired, %d found paid at the gateway, %d booking hold(s) released', $hotel->code, $s['expired'], $s['paid'], $s['released']));
        }

        return self::SUCCESS;
    }
}
