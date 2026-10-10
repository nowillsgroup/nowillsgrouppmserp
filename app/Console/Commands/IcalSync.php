<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Models\OtaChannel;
use App\Services\IcalService;
use App\Support\Tenant;
use Illuminate\Console\Command;

class IcalSync extends Command
{
    protected $signature = 'pms:ical-sync {--hotel= : Hotel code (default: all hotels)}';

    protected $description = 'Import OTA iCal calendars for every active channel';

    public function handle(): int
    {
        $hotels = Hotel::where('is_active', true)->when($this->option('hotel'), fn ($q, $c) => $q->where('code', $c))->get();
        foreach ($hotels as $hotel) {
            Tenant::set($hotel);
            foreach (OtaChannel::where('is_active', true)->whereNotNull('import_url')->get() as $ch) {
                IcalService::sync($ch);
                $this->line("[{$hotel->code}] {$ch->name}: {$ch->last_sync_status}");
            }
        }

        return self::SUCCESS;
    }
}
