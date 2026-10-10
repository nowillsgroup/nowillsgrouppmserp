<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Services\LedgerService;
use App\Support\Tenant;
use Illuminate\Console\Command;

class GlSync extends Command
{
    protected $signature = 'pms:gl-sync {--hotel= : Hotel code (default: all hotels)} {--full : Re-check every source after the lock date}';

    protected $description = 'Post closed business days to the double-entry general ledger (reverses & re-posts amended sources)';

    public function handle(): int
    {
        $hotels = Hotel::where('is_active', true)->when($this->option('hotel'), fn ($q, $c) => $q->where('code', $c))->get();
        $failed = false;
        foreach ($hotels as $hotel) {
            Tenant::set($hotel);
            $t = microtime(true);
            $s = LedgerService::sync((bool) $this->option('full'));
            $this->line(sprintf('[%s] up to %s: %d checked, %d posted, %d re-posted, %d reversed, %d errors (%.1fs)',
                $hotel->code, LedgerService::cutoff(), $s['checked'], $s['posted'], $s['reposted'], $s['reversed'], count($s['errors']), microtime(true) - $t));
            foreach ($s['errors'] as $e) {
                $this->warn('  '.$e);
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
