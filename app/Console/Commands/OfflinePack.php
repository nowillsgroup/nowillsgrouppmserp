<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\DesktopSyncService;
use Illuminate\Console\Command;

/**
 * Build a desktop offline pack on the server (scripted desktop pulls, cron).
 * pms:offline:pack {--days=7} {--device=} {--user=admin} {--out=}
 */
class OfflinePack extends Command
{
    protected $signature = 'pms:offline:pack {--days=7} {--device=} {--user=admin} {--out=}';

    protected $description = 'Build a multi-day desktop offline pack (reservations, stays, rooms, rates) as JSON';

    public function handle(): int
    {
        $user = User::where('username', $this->option('user'))->orWhere('email', $this->option('user'))->first()
            ?: User::orderBy('id')->first();
        $pack = DesktopSyncService::pack($user, (int) $this->option('days'), $this->option('device') ?: null);
        $json = json_encode($pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $out = $this->option('out');
        if ($out) {
            file_put_contents($out, $json);
            $this->info("Pack written to {$out} (".strlen($json)." bytes)");
        } else {
            $this->line($json);
        }
        $this->info('Covering '.$pack['meta']['covers']['from'].' → '.$pack['meta']['covers']['until']
            .'; '.collect($pack['meta']['counts'] ?? $pack['dataset'])->map(fn ($v) => is_array($v) ? count($v) : $v)->sum().' rows.');

        return self::SUCCESS;
    }
}
