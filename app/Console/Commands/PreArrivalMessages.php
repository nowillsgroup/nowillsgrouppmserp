<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Models\MessageLog;
use App\Models\Reservation;
use App\Services\MessageService;
use App\Support\Tenant;
use Illuminate\Console\Command;

class PreArrivalMessages extends Command
{
    protected $signature = 'pms:pre-arrival {--hotel= : Hotel code (default: all hotels)}';

    protected $description = 'SMS / WhatsApp the pre-arrival message (with online check-in link) N days before arrival — once per reservation';

    public function handle(): int
    {
        $hotels = Hotel::where('is_active', true)->when($this->option('hotel'), fn ($q, $c) => $q->where('code', $c))->get();
        foreach ($hotels as $hotel) {
            Tenant::set($hotel);
            $days = (int) setting('sms_pre_arrival_days', 0);
            if (setting('sms_enabled', '0') !== '1' || $days < 1) {
                continue;
            }
            $sent = 0;
            $date = bdate()->copy()->addDays($days)->toDateString();
            $done = MessageLog::where('template', 'pre_arrival')->where('related_type', 'Reservation')->where('status', 'sent')->pluck('related_id')->all();
            Reservation::with('guest')->whereIn('status', ['confirmed', 'tentative'])->where('arrival_date', $date)->whereNotIn('id', $done)
                ->whereHas('guest', fn ($q) => $q->whereNotNull('phone')->where('phone', '!=', ''))
                ->each(function ($r) use (&$sent) {
                    $sent += MessageService::sendTemplate('pre_arrival', $r, MessageService::defaultChannel())->status === 'sent' ? 1 : 0;
                });
            $this->line("[{$hotel->code}] pre-arrival for {$date}: {$sent} sent");
        }

        return self::SUCCESS;
    }
}
