<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Services\CaseService;
use App\Services\FeedbackService;
use App\Support\Tenant;
use Illuminate\Console\Command;

class FeedbackRun extends Command
{
    protected $signature = 'pms:feedback-run {--hotel= : Hotel code (default: all hotels)}';

    protected $description = 'Guest feedback: send due post-stay surveys, one reminder, expire old links, and escalate service-recovery cases past their SLA';

    public function handle(): int
    {
        foreach (Hotel::where('is_active', true)->when($this->option('hotel'), fn ($q, $c) => $q->where('code', $c))->get() as $hotel) {
            Tenant::set($hotel);
            [$sent, $reminded, $expired] = FeedbackService::runDue();
            $escalated = CaseService::escalateOverdue();
            if ($sent + $reminded + $expired + $escalated) {
                $this->line("[{$hotel->code}] {$sent} survey(s) sent, {$reminded} reminded, {$expired} expired · {$escalated} case(s) escalated");
            }
        }

        return self::SUCCESS;
    }
}
