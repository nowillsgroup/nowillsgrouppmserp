<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Which offline screens this login may prepare on this device.
 *
 * Preparing is the step the runbook asks for before the line gets shaky: the page
 * itself and its snapshot are pulled while the connection is good, so the screen
 * opens from the device afterwards. The server decides the list — a member of staff
 * is never sent a snapshot their permissions do not carry — and the device only
 * fetches what it is given.
 *
 * `snapshot_key` names the key `/js/offline-core.js` uses for screens that read
 * through the shared cache. The older housekeeping and guest-request screens keep
 * their own store; for those the snapshot is still fetched, so the service worker
 * has the JSON and the screen fills its store the first time it opens offline.
 */
class OfflinePreparationService
{
    public const VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public static function plan(User $user): array
    {
        $slices = array_values(array_filter([
            self::slice('pos', 'Restaurant orders', 'offline.pos', 'offline.pos.snapshot',
                $user->hasPermission('pos.order') || $user->hasPermission('pos.view'), 'pos'),
            self::slice('frontdesk', 'Front desk', 'offline.frontdesk', 'offline.frontdesk.snapshot',
                $user->hasPermission('frontdesk.view'), 'frontdesk'),
            self::slice('housekeeping', 'Housekeeping', 'offline.housekeeping', 'offline.housekeeping.snapshot',
                $user->hasPermission('housekeeping.view') || $user->hasPermission('housekeeping.attendant') || $user->hasPermission('housekeeping.manage')),
            self::slice('requests', 'Guest requests', 'offline.guest_requests', 'offline.guest_requests.snapshot',
                $user->hasPermission('requests.view') || $user->hasPermission('requests.manage')),
        ]));

        return [
            'version' => self::VERSION,
            'generated_at' => now()->toIso8601String(),
            'business_date' => bdate()->toDateString(),
            'staff' => $user->name,
            'slices' => $slices,
            'count' => count($slices),
        ];
    }

    /**
     * One screen, if this login may work it and the routes still exist.
     *
     * @return array<string, mixed>|null
     */
    protected static function slice(string $key, string $label, string $page, string $snapshot, bool $allowed, ?string $snapshotKey = null): ?array
    {
        if (! $allowed || ! Route::has($page) || ! Route::has($snapshot)) {
            return null;
        }

        return [
            'key' => $key,
            'label' => $label,
            'page' => route($page, [], false),
            'url' => route($snapshot, [], false),
            'snapshot_key' => $snapshotKey,
        ];
    }
}
