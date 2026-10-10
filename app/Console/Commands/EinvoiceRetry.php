<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Services\EInvoiceService;
use App\Support\Tenant;
use Illuminate\Console\Command;

class EinvoiceRetry extends Command
{
    protected $signature = 'pms:einvoice-retry {--hotel= : Hotel code (default: all hotels)}';

    protected $description = 'GRA E-VAT: retry pending / failed submissions whose back-off has elapsed (5, 15, 60, 180, 720 minutes)';

    public function handle(): int
    {
        foreach (Hotel::where('is_active', true)->when($this->option('hotel'), fn ($q, $c) => $q->where('code', $c))->get() as $hotel) {
            Tenant::set($hotel);
            if (! EInvoiceService::enabled()) {
                continue;
            }
            [$ok, $bad] = EInvoiceService::retryDue();
            [$pok, $pbad] = \App\Services\PosEvatService::retry(true); // v4.4 restaurant bills
            if ($ok + $bad + $pok + $pbad) {
                $this->line("[{$hotel->code}] {$ok} document(s) and {$pok} restaurant bill(s) stamped by E-VAT, ".($bad + $pbad).' still failing');
            }
        }

        return self::SUCCESS;
    }
}
