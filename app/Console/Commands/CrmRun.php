<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Services\CrmService;
use App\Support\Tenant;
use Illuminate\Console\Command;

class CrmRun extends Command
{
    protected $signature = 'pms:crm-run {--hotel= : Hotel code (default: all hotels)} {--journeys : Also refresh guest statistics and run the automated journeys for the business date}';

    protected $description = 'Send scheduled marketing campaigns that are due; with --journeys also run birthday / pre-arrival / post-stay / win-back automations';

    public function handle(): int
    {
        foreach (Hotel::where('is_active', true)->when($this->option('hotel'), fn ($q, $c) => $q->where('code', $c))->get() as $hotel) {
            Tenant::set($hotel);
            config(['app.timezone' => $hotel->timezone]);
            date_default_timezone_set($hotel->timezone ?: 'UTC');
            $line = sprintf('[%s] %d campaign(s) sent', $hotel->code, CrmService::runDue());
            if ($this->option('journeys')) {
                CrmService::refreshStats();
                $line .= sprintf(', %d automation message(s) sent', CrmService::runJourneys());
            }
            $this->line($line);
        }

        return self::SUCCESS;
    }
}
