<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Services\ApiService;
use App\Services\WebhookService;
use App\Support\Tenant;
use Illuminate\Console\Command;

class WebhooksRun extends Command
{
    protected $signature = 'pms:webhooks {--limit=200 : Most deliveries attempted per run} {--prune : Also delete old request logs, idempotency keys and finished deliveries}';

    protected $description = 'API platform: deliver due webhook events (with retry back-off) and, with --prune, apply the log retention';

    public function handle(): int
    {
        [$n, $ok, $bad] = WebhookService::deliverDue(null, max(1, (int) $this->option('limit')));
        if ($n) {
            $this->line("{$n} webhook deliver(ies) attempted: {$ok} delivered, {$bad} failed");
        }
        if ($this->option('prune')) {
            $prev = Tenant::hotel();
            foreach (Hotel::where('is_active', true)->get() as $hotel) {
                Tenant::set($hotel);
                [$logs, $keys] = ApiService::prune();
                $del = WebhookService::prune();
                if ($logs || $keys || $del) {
                    $this->line("[{$hotel->code}] pruned {$logs} request log(s), {$keys} idempotency key(s), {$del} delivery record(s)");
                }
            }
            Tenant::set($prev);
            // refused calls (no valid key) are not tied to a hotel
            \App\Models\ApiRequestLog::withoutGlobalScope('hotel')->whereNull('hotel_id')->where('created_at', '<', now()->subDays(30))->delete();
        }

        return self::SUCCESS;
    }
}
