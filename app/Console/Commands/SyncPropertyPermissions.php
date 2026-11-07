<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Services\HotelProvisioner;
use App\Support\Tenant;
use Illuminate\Console\Command;

/**
 * v10.1 register the permissions a release added and grant them to the built-in roles that match,
 * property by property.
 *
 * HotelProvisioner::upgrade() has always been the upgrade path, but nothing ran it: a property
 * provisioned before a release kept a permission catalogue that had never heard of the new keys, so
 * the only account that could reach a new screen was a super admin. This command runs the upgrade
 * over every property (or one, with --hotel) and reports what it actually added. Safe to re-run: it
 * never removes a permission an administrator granted by hand.
 */
class SyncPropertyPermissions extends Command
{
    protected $signature = 'pms:sync-permissions {--hotel= : Hotel code (default: every property)} {--live-only : Skip suspended and retired properties}';

    protected $description = 'Register newly added permissions and grant them to the built-in roles of every property (safe to re-run)';

    public function handle(): int
    {
        $query = Hotel::query()->orderBy('name');
        if ($code = $this->option('hotel')) {
            $query->where('code', strtoupper($code));
        }
        if ($this->option('live-only')) {
            $query->where('status', 'active')->where('is_active', true);
        }

        $hotels = $query->get();
        if ($hotels->isEmpty()) {
            $this->error('No property matched.');

            return self::FAILURE;
        }

        foreach ($hotels as $hotel) {
            $added = HotelProvisioner::upgrade($hotel);
            $this->line(sprintf('[%s] %s — %d permission(s) granted', $hotel->code, $hotel->statusLabel(), $added));
        }

        Tenant::set(null);

        return self::SUCCESS;
    }
}
