<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Services\HotelProvisioner;
use Illuminate\Console\Command;

class PmsUpgrade extends Command
{
    protected $signature = 'pms:upgrade';

    protected $description = 'After "php artisan migrate": register new permissions/roles for every property (keeps customisations)';

    public function handle(): int
    {
        foreach (Hotel::all() as $hotel) {
            $n = HotelProvisioner::upgrade($hotel);
            $this->line("[{$hotel->code}] {$n} permission grant(s) added");
        }

        return self::SUCCESS;
    }
}
