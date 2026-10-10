<?php

namespace App\Console\Commands;

use App\Models\ChannelConnection;
use App\Models\Hotel;
use App\Services\ChannelService;
use App\Support\Tenant;
use Illuminate\Console\Command;

class ChannelPush extends Command
{
    protected $signature = 'pms:channel-push {--hotel= : Hotel code (default: all hotels)} {--full : Full ARI refresh (the whole push horizon) instead of pending changes}';

    protected $description = 'Channel manager: push pending availability / rate / restriction changes (ARI) to connected OTAs, with retry back-off';

    public function handle(): int
    {
        foreach (Hotel::where('is_active', true)->when($this->option('hotel'), fn ($q, $c) => $q->where('code', $c))->get() as $hotel) {
            Tenant::set($hotel);
            if (! ChannelConnection::where('is_active', true)->exists()) {
                continue;
            }
            if ($this->option('full')) {
                ChannelConnection::where('is_active', true)->update(['full_sync_due' => true, 'next_retry_at' => null]);
            }
            foreach (ChannelService::pushDue() as $p) {
                $this->line(sprintf('[%s] %s %s push: %s, %d room-night(s)%s', $hotel->code, $p->connection->name, $p->kind, strtoupper($p->status), $p->room_days,
                    $p->error ? ' – '.$p->error : ''));
            }
        }

        return self::SUCCESS;
    }
}
