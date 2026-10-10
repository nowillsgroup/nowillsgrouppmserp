<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Services\PrivacyService;
use App\Support\Tenant;
use Illuminate\Console\Command;

class PrivacyRetention extends Command
{
    protected $signature = 'pms:privacy-retention {--hotel= : Hotel code (default: all hotels)} {--dry-run : Only show what is due}';

    protected $description = 'Data privacy: apply the retention policies (ID scans & signatures, message contents, inactive profiles, ended legal holds, unverified requests)';

    public function handle(): int
    {
        foreach (Hotel::where('is_active', true)->when($this->option('hotel'), fn ($q, $c) => $q->where('code', $c))->get() as $hotel) {
            Tenant::set($hotel);
            if ($this->option('dry-run')) {
                foreach (PrivacyService::retentionPreview() as $row) {
                    $this->line(sprintf('[%s] %-45s %d', $hotel->code, $row['label'], $row['count']));
                }

                continue;
            }
            if (! PrivacyService::settings()['retention_enabled']) {
                $this->line("[{$hotel->code}] retention switched off – skipped");

                continue;
            }
            $run = PrivacyService::runRetention('schedule');
            $this->line("[{$hotel->code}] ".(collect($run->counts)->filter()->map(fn ($n, $k) => "$n ".strtolower(PrivacyService::RETENTION_LABELS[$k]))->implode(', ') ?: 'nothing due'));
        }

        return self::SUCCESS;
    }
}
