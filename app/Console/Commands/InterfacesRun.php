<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Models\InterfaceCommand;
use App\Services\CallAccountingService;
use App\Services\InterfaceService;
use App\Services\KeyCardService;
use App\Services\RobitaService;
use App\Support\Tenant;
use Illuminate\Console\Command;

class InterfacesRun extends Command
{
    protected $signature = 'pms:interfaces {--hotel= : Hotel code (default: all hotels)}';

    protected $description = 'Hotel interfaces: expire key cards past their validity, re-send failed PABX commands, re-run the call review queue';

    public function handle(): int
    {
        $prev = Tenant::hotel();
        foreach (Hotel::where('is_active', true)->when($this->option('hotel'), fn ($q, $c) => $q->where('code', $c))->get() as $hotel) {
            Tenant::set($hotel);
            $out = self::runForCurrentHotel();
            if (array_sum($out)) {
                $this->line("[{$hotel->code}] {$out['expired']} card(s) expired · {$out['resent']} PABX command(s) re-sent · {$out['posted']} queued call(s) posted · {$out['overdue']} master key(s) overdue · {$out['locks']} door credential(s) expired · {$out['lock_retry']} lock push(es) retried");
            }
        }
        Tenant::set($prev);

        return self::SUCCESS;
    }

    /** one sweep for the selected property (also used by the tests) */
    public static function runForCurrentHotel(): array
    {
        $cfg = InterfaceService::cfg();
        $out = ['expired' => KeyCardService::expireDue(), 'resent' => 0, 'posted' => 0, 'overdue' => 0, 'locks' => 0, 'lock_retry' => 0];
        // PABX commands that failed in the last 6 hours are re-sent (at most 5 attempts); card encodes are never retried
        if ($cfg['pbx_enabled'] === '1' && $cfg['pbx_driver'] !== 'offline') {
            foreach (InterfaceCommand::where('system', 'pbx')->where('status', 'failed')->where('attempts', '<', 5)->where('created_at', '>=', now()->subHours(6))->orderBy('id')->get() as $c) {
                $out['resent'] += InterfaceService::retry($c)->status === 'done' ? 1 : 0;
            }
        }
        // calls parked because the guest was not yet checked in / the extension was unknown get another chance
        if ($cfg['pbx_auto_post'] === '1') {
            $out['posted'] = CallAccountingService::reprocess()['posted'];
        }
        $out['overdue'] = InterfaceService::counts()['keys_overdue'];
        // v7.57: door credentials that outlived their validity, and cloud pushes that never landed
        $out['locks'] = RobitaService::expireDue();
        $out['lock_retry'] = RobitaService::retryPending();

        return $out;
    }
}
