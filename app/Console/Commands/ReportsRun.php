<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Services\KpiService;
use App\Services\ReportScheduleService;
use App\Support\Tenant;
use Illuminate\Console\Command;

class ReportsRun extends Command
{
    protected $signature = 'pms:reports-run {--hotel= : Hotel code (default: all hotels)} {--alerts : Also re-check KPI alerts for the last audited date}';

    protected $description = 'Report automation: e-mail scheduled reports that are due (run every 5 minutes); optionally re-check KPI alerts';

    public function handle(): int
    {
        $prev = Tenant::hotel();
        foreach (Hotel::where('is_active', true)->when($this->option('hotel'), fn ($q, $c) => $q->where('code', $c))->get() as $hotel) {
            Tenant::set($hotel);
            $r = ReportScheduleService::due();
            $line = "[{$hotel->code}] reports sent {$r['sent']} · partial {$r['partial']} · skipped {$r['skipped']} · failed {$r['failed']}";
            if ($this->option('alerts')) {
                $k = KpiService::evaluate();
                $line .= " · KPI alerts checked {$k['evaluated']}, {$k['triggered']} new";
            }
            $this->line($line);
        }
        Tenant::set($prev);

        return self::SUCCESS;
    }
}
