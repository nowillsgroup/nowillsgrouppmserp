<?php

namespace App\Services;

use App\Models\EinvoiceLog;
use App\Models\GuestRequest;
use App\Models\HousekeepingTask;
use App\Models\MaintenanceRequest;
use App\Models\ReservationRoom;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * v7.55 – the notification centre behind the bell in the top bar.
 *
 * Everything here is *derived* from data the modules already keep: there is no
 * notifications table, nothing to mark read, nothing to prune, and no way for
 * the bell to disagree with the screen it points at. Each group is a question
 * the system already knows how to answer ("who is arriving and still not
 * checked in?") with a link straight to the page where it is answered.
 *
 * Three rules keep it safe to put on every page:
 *
 *  1. A group is only built when the signed-in user holds the permission that
 *     lets them act on it — a housekeeper never sees the accountant's queue.
 *  2. Tenant scoping is whatever the models already do (BelongsToHotel), so a
 *     group admin switched into a property sees that property.
 *  3. Each group is built inside its own try/catch. A module whose tables are
 *     not migrated yet — a fresh install, a half-applied release — must never
 *     take the header, and therefore every page, down with it.
 */
class NotificationCenter
{
    /** How many example rows a group carries (the dropdown shows fewer). */
    public const MAX_ITEMS = 4;

    /** One build per user per request, however many times the header asks. */
    private static array $cache = [];

    /**
     * @return array<int, array{key:string,label:string,icon:string,tone:string,count:int,url:?string,hint:string,items:array}>
     */
    public static function forUser(?User $user = null): array
    {
        $user ??= auth()->user();

        if (! $user) {
            return [];
        }

        if (isset(self::$cache[$user->id])) {
            return self::$cache[$user->id];
        }

        $groups = [];

        foreach ([
            'arrivals', 'departures', 'housekeeping', 'requests', 'maintenance', 'einvoices',
        ] as $builder) {
            try {
                $group = self::{$builder}($user);
            } catch (\Throwable $e) {
                // Never let an unmigrated / renamed table break every page.
                report($e);
                $group = null;
            }

            if ($group) {
                $groups[] = $group;
            }
        }

        // Most urgent first: red, then amber, then everything else; then by size.
        $rank = ['red' => 0, 'amber' => 1, 'blue' => 2, 'purple' => 2, 'teal' => 3, 'green' => 3];
        usort($groups, fn ($a, $b) => [$rank[$a['tone']] ?? 9, -$a['count']] <=> [$rank[$b['tone']] ?? 9, -$b['count']]);

        return self::$cache[$user->id] = $groups;
    }

    /** The number on the badge: distinct things waiting, not warnings × rows. */
    public static function total(array $groups): int
    {
        return array_sum(array_column($groups, 'count'));
    }

    /** True when the user has nothing anywhere — the bell still opens, to say so. */
    public static function isEmpty(array $groups): bool
    {
        return self::total($groups) === 0;
    }

    /**
     * Rows for the dropdown. Groups are taken in rounds — one row from every
     * queue, then a second — so a glance shows the shape of the day (one
     * arrival, one request, one ticket …) instead of the five oldest tickets
     * from whichever group happens to be first.
     */
    public static function preview(array $groups, int $limit = 6): array
    {
        $rows = [];
        $perGroup = 2;   // no queue may dominate the dropdown

        for ($round = 0; $round < $perGroup; $round++) {
            foreach ($groups as $group) {
                if (count($rows) >= $limit) {
                    return $rows;
                }

                if (empty($group['items'])) {
                    if ($round > 0) {
                        continue;
                    }

                    $rows[] = [
                        'group' => $group['key'],
                        'text' => $group['label'].' — '.$group['count'],
                        'meta' => $group['hint'],
                        'url' => $group['url'],
                        'tone' => $group['tone'],
                        'icon' => $group['icon'],
                    ];

                    continue;
                }

                if (! isset($group['items'][$round])) {
                    continue;
                }

                $item = $group['items'][$round];
                $rows[] = [
                    'group' => $group['key'],
                    'text' => $item['text'],
                    'meta' => $item['meta'],
                    'url' => $item['url'] ?? $group['url'],
                    'tone' => $group['tone'],
                    'icon' => $group['icon'],
                ];
            }
        }

        return $rows;
    }

    // ── Groups ───────────────────────────────────────────────────────────────

    /** Arrivals that were expected today (or earlier) and are still not checked in. */
    private static function arrivals(User $user): ?array
    {
        if (! $user->hasPermission('frontdesk.view')) {
            return null;
        }

        $today = bdate()->toDateString();
        $query = ReservationRoom::with('reservation.guest', 'reservation', 'roomType')
            ->where('status', 'reserved')
            ->whereDate('arrival_date', '<=', $today);

        $count = (clone $query)->count();

        if ($count === 0) {
            return null;
        }

        $late = (clone $query)->whereDate('arrival_date', '<', $today)->count();
        $rows = $query->orderBy('arrival_date')->orderBy('id')->limit(self::MAX_ITEMS)->get();

        return [
            'key' => 'arrivals',
            'label' => 'Arrivals to check in',
            'icon' => 'globe',
            'tone' => $late ? 'red' : 'blue',
            'count' => $count,
            'url' => self::url('arrivals'),
            'hint' => $late ? $late.' past their arrival date' : 'due today',
            'items' => $rows->map(fn (ReservationRoom $r) => [
                'text' => self::guestName($r)
                    .' · '.($r->roomType->name ?? 'room type ?')
                    .($r->room ? ' · room '.$r->room->room_no : ''),
                'meta' => 'Res '.($r->reservation->reservation_no ?? '—').' · '.dmy($r->arrival_date)
                    .($r->arrival_date->toDateString() < bdate()->toDateString() ? ' · overdue' : ''),
                'url' => $r->reservation ? self::url('reservations.show', $r->reservation) : self::url('arrivals'),
            ])->all(),
        ];
    }

    /** Guests still in house after their departure date, and departures due today. */
    private static function departures(User $user): ?array
    {
        if (! $user->hasPermission('frontdesk.view')) {
            return null;
        }

        $today = bdate()->toDateString();
        $query = ReservationRoom::with('reservation.guest', 'reservation', 'room')
            ->where('status', 'checked_in')
            ->whereDate('departure_date', '<=', $today);

        $count = (clone $query)->count();

        if ($count === 0) {
            return null;
        }

        $overstay = (clone $query)->whereDate('departure_date', '<', $today)->count();
        $rows = $query->orderBy('departure_date')->limit(self::MAX_ITEMS)->get();

        return [
            'key' => 'departures',
            'label' => 'Departures to settle',
            'icon' => 'logout',
            'tone' => $overstay ? 'red' : 'amber',
            'count' => $count,
            'url' => self::url('departures'),
            'hint' => $overstay ? $overstay.' past departure date (overstay)' : 'due today',
            'items' => $rows->map(fn (ReservationRoom $r) => [
                'text' => self::guestName($r).($r->room ? ' · room '.$r->room->room_no : ''),
                'meta' => 'Due '.dmy($r->departure_date)
                    .($r->departure_date->toDateString() < bdate()->toDateString() ? ' · overstay' : ''),
                'url' => $r->reservation ? self::url('reservations.show', $r->reservation) : self::url('departures'),
            ])->all(),
        ];
    }

    /** Cleaning work still open for today (including yesterday's carry-over). */
    private static function housekeeping(User $user): ?array
    {
        if (! $user->hasPermission('housekeeping.view')) {
            return null;
        }

        $today = bdate()->toDateString();
        $query = HousekeepingTask::with('room')
            ->whereIn('status', ['pending', 'in_progress'])
            ->whereDate('task_date', '<=', $today);

        $count = (clone $query)->count();

        if ($count === 0) {
            return null;
        }

        $behind = (clone $query)->whereDate('task_date', '<', $today)->count();
        $rows = $query->orderBy('task_date')->orderBy('id')->limit(self::MAX_ITEMS)->get();

        return [
            'key' => 'housekeeping',
            'label' => 'Housekeeping tasks open',
            'icon' => 'broom',
            'tone' => $behind ? 'amber' : 'teal',
            'count' => $count,
            'url' => self::url('housekeeping.tasks'),
            'hint' => $behind ? $behind.' carried over from an earlier day' : 'for today',
            'items' => $rows->map(fn (HousekeepingTask $t) => [
                'text' => ($t->room->room_no ?? 'Room ?').' · '
                    .(HousekeepingTask::TYPES[$t->task_type] ?? ucfirst((string) $t->task_type)),
                'meta' => ($t->status === 'in_progress' ? 'In progress' : 'Not started')
                    .' · '.dmy($t->task_date),
                'url' => self::url('housekeeping.tasks'),
            ])->all(),
        ];
    }

    /** Guest requests nobody has closed yet — the guests are waiting. */
    private static function requests(User $user): ?array
    {
        if (! $user->hasPermission('requests.view')) {
            return null;
        }

        $query = GuestRequest::with('room')->whereIn('status', GuestRequest::OPEN);

        $count = (clone $query)->count();

        if ($count === 0) {
            return null;
        }

        $stale = (clone $query)->where('created_at', '<', now()->subHours(2))->count();
        $rows = $query->oldest('created_at')->limit(self::MAX_ITEMS)->get();

        return [
            'key' => 'requests',
            'label' => 'Guest requests open',
            'icon' => 'bell',
            'tone' => $stale ? 'amber' : 'blue',
            'count' => $count,
            'url' => self::url('requests.index'),
            'hint' => $stale ? $stale.' waiting over 2 hours' : 'waiting for an answer',
            'items' => $rows->map(fn (GuestRequest $r) => [
                'text' => ($r->room->room_no ? 'Room '.$r->room->room_no.' · ' : '')
                    .($r->category_label ?? (GuestRequest::CATEGORIES[$r->category][0] ?? ucfirst((string) $r->category))),
                'meta' => strtoupper((string) $r->status).' · '
                    .($r->created_at ? $r->created_at->diffForHumans(null, true) : ''),
                'url' => self::url('requests.index'),
            ])->all(),
        ];
    }

    /** Engineering tickets, urgent first. */
    private static function maintenance(User $user): ?array
    {
        if (! $user->hasPermission('maintenance.manage')) {
            return null;
        }

        $query = MaintenanceRequest::with('room')->whereIn('status', ['open', 'in_progress']);

        $count = (clone $query)->count();

        if ($count === 0) {
            return null;
        }

        $urgent = (clone $query)->whereIn('priority', ['urgent', 'high'])->count();
        // Portable urgency ordering (field() is MySQL-only; local dev runs SQLite).
        $rows = $query->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")
            ->oldest('id')->limit(self::MAX_ITEMS)->get();

        return [
            'key' => 'maintenance',
            'label' => 'Engineering tickets open',
            'icon' => 'tool',
            'tone' => $urgent ? 'red' : 'amber',
            'count' => $count,
            'url' => self::url('maintenance.index'),
            'hint' => $urgent ? $urgent.' high or urgent' : 'no urgent tickets',
            'items' => $rows->map(fn (MaintenanceRequest $t) => [
                'text' => ($t->ticket_no ?? '#'.$t->id).' · '.($t->title ?? 'Maintenance'),
                'meta' => ucfirst((string) $t->priority)
                    .($t->room ? ' · room '.$t->room->room_no : '')
                    .($t->due_date ? ' · due '.dmy($t->due_date) : ''),
                'url' => self::url('maintenance.index'),
            ])->all(),
        ];
    }

    /** GRA E-VAT submissions the SDC refused in the last week. */
    private static function einvoices(User $user): ?array
    {
        if (! $user->hasPermission('gst.view')) {
            return null;
        }

        $query = EinvoiceLog::where('ok', false)->where('created_at', '>=', now()->subDays(7));

        $count = (clone $query)->count();

        if ($count === 0) {
            return null;
        }

        $rows = $query->latest('created_at')->limit(self::MAX_ITEMS)->get();

        return [
            'key' => 'einvoices',
            'label' => 'E-VAT submissions refused',
            'icon' => 'alert',
            'tone' => 'red',
            'count' => $count,
            'url' => self::url('einvoices'),
            'hint' => 'last 7 days',
            'items' => $rows->map(fn (EinvoiceLog $l) => [
                'text' => ($l->doc_no ? 'Document '.$l->doc_no : ($l->action === 'cancel' ? 'A cancellation' : 'A submission'))
                    .' was refused',
                'meta' => trim(($l->error_code ? $l->error_code.': ' : '').\Illuminate\Support\Str::limit((string) $l->message, 60)),
                'url' => self::url('einvoices'),
            ])->all(),
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Who this stay belongs to. Demo and imported data can carry a stay with a
     * reservation but no guest record, so fall back to the booking number
     * rather than printing "Guest" three times in a row.
     */
    private static function guestName(ReservationRoom $stay): string
    {
        $name = $stay->reservation->guest->full_name ?? null;

        if ($name) {
            return $name;
        }

        $free = $stay->reservation->guest_name ?? null;

        return $free ?: ('Res '.($stay->reservation->reservation_no ?? '—'));
    }

    /**
     * A link, or null when this build has no such route. Route names move
     * between releases; a missing one must degrade to "no link", not a 500.
     */
    private static function url(string $name, mixed $params = []): ?string
    {
        try {
            return Route::has($name) ? route($name, $params) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
