<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Services\GiftVoucherService;
use App\Support\Tenant;
use Illuminate\Console\Command;

class VouchersRun extends Command
{
    protected $signature = 'pms:vouchers {--hotel= : Hotel code (default: all hotels)}';

    protected $description = 'Gift vouchers: expire vouchers past their validity (breakage) and e-mail expiry reminders to holders';

    public function handle(): int
    {
        $prev = Tenant::hotel();
        foreach (Hotel::where('is_active', true)->when($this->option('hotel'), fn ($q, $c) => $q->where('code', $c))->get() as $hotel) {
            Tenant::set($hotel);
            $e = GiftVoucherService::expireDue();
            $r = GiftVoucherService::remindExpiring();
            if ($e['count'] || $r) {
                $this->line("[{$hotel->code}] {$e['count']} voucher(s) expired (".number_format($e['amount'], 2)." breakage) · {$r} reminder(s) e-mailed");
            }
        }
        Tenant::set($prev);

        return self::SUCCESS;
    }
}
