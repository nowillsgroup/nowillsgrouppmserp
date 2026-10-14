<?php

namespace App\Services;

use App\Models\LogEntry;
use App\Models\LogEntryComment;
use App\Models\LogEntryRead;
use App\Models\ReservationRoom;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** v3.5 – shift log book & hand-over. */
class LogBookService
{
    public static function create(array $d, string $source = 'manual'): LogEntry
    {
        $title = trim((string) ($d['title'] ?? ''));
        if ($title === '') {
            throw new RuntimeException('Give the entry a short title.');
        }
        $cat = array_key_exists($d['category'] ?? '', LogEntry::CATEGORIES) ? $d['category'] : 'info';
        $dept = array_key_exists($d['department'] ?? '', LogEntry::DEPARTMENTS) ? $d['department'] : 'front_office';
        $prio = array_key_exists($d['priority'] ?? '', LogEntry::PRIORITIES) ? $d['priority'] : 'normal';
        $needsAction = ! empty($d['needs_action']) || ! empty($d['follow_up_at']) || in_array($cat, ['complaint', 'incident', 'maintenance']) || $prio === 'urgent';
        $stay = ! empty($d['reservation_room_id']) ? ReservationRoom::find($d['reservation_room_id']) : null;
        $follow = ! empty($d['follow_up_at']) ? Carbon::parse($d['follow_up_at']) : null;
        if ($follow && $follow->lt(now()->subMinutes(5)) && $source === 'manual') {
            throw new RuntimeException('The follow-up time must be in the future.');
        }

        $e = LogEntry::create([
            'business_date' => bdate()->toDateString(), 'shift' => FrontDeskOpsService::currentShift(), 'department' => $dept, 'category' => $cat, 'priority' => $prio,
            'title' => mb_substr($title, 0, 150), 'body' => trim((string) ($d['body'] ?? '')) ?: null,
            'reservation_room_id' => $stay?->id, 'room_id' => $d['room_id'] ?? $stay?->room_id, 'guest_id' => $d['guest_id'] ?? $stay?->guest_id,
            'status' => $needsAction ? 'open' : 'note', 'follow_up_at' => $follow,
            'pinned' => ! empty($d['pinned']), 'pinned_until' => ! empty($d['pinned']) ? ($d['pinned_until'] ?? null) : null,
            'source' => $source, 'created_by' => auth()->id(),
        ]);
        ActivityLogger::log('logbook', 'create', 'Log book: '.$e->title.($source === 'system' ? ' (automatic)' : ''), $e);

        return $e;
    }

    /** automatic entry written by the system (wake-up escalation, night audit, …) */
    public static function system(string $title, ?string $body = null, string $category = 'info', string $priority = 'normal', array $links = [], bool $needsAction = false): LogEntry
    {
        return self::create(['title' => $title, 'body' => $body, 'category' => $category, 'priority' => $priority, 'needs_action' => $needsAction] + $links, 'system');
    }

    public static function comment(LogEntry $e, string $body): LogEntryComment
    {
        $body = trim($body);
        if ($body === '') {
            throw new RuntimeException('Write a comment first.');
        }
        $c = LogEntryComment::create(['log_entry_id' => $e->id, 'user_id' => auth()->id(), 'body' => mb_substr($body, 0, 2000)]);
        self::markRead(collect([$e]), (int) auth()->id());
        $e->touch();

        return $c;
    }

    public static function resolve(LogEntry $e, string $resolution): LogEntry
    {
        if ($e->status === 'resolved') {
            throw new RuntimeException('This entry is already resolved.');
        }
        if (trim($resolution) === '') {
            throw new RuntimeException('Say how it was resolved.');
        }
        $e->forceFill(['status' => 'resolved', 'resolution' => mb_substr(trim($resolution), 0, 2000), 'resolved_at' => now(), 'resolved_by' => auth()->id()])->save();
        ActivityLogger::log('logbook', 'resolve', "Log book resolved: {$e->title}", $e);

        return $e;
    }

    public static function reopen(LogEntry $e): LogEntry
    {
        if ($e->status !== 'resolved') {
            throw new RuntimeException('Only resolved entries can be re-opened.');
        }
        $e->forceFill(['status' => 'open', 'resolved_at' => null, 'resolved_by' => null])->save();
        ActivityLogger::log('logbook', 'reopen', "Log book re-opened: {$e->title}", $e);

        return $e;
    }

    public static function pin(LogEntry $e, bool $on, ?string $until = null): LogEntry
    {
        $e->forceFill(['pinned' => $on, 'pinned_until' => $on ? $until : null])->save();

        return $e;
    }

    /** acknowledge ("I have read the hand-over") */
    public static function markRead(Collection $entries, int $userId): int
    {
        if (! $userId || $entries->isEmpty()) {
            return 0;
        }
        $now = now();
        $rows = $entries->filter(fn ($e) => $e->created_by !== $userId)
            ->map(fn ($e) => ['log_entry_id' => $e->id, 'user_id' => $userId, 'read_at' => $now])->values()->all();

        return $rows ? DB::table('log_entry_reads')->insertOrIgnore($rows) : 0;
    }

    public static function unreadQuery(int $userId)
    {
        return LogEntry::where('created_at', '>=', now()->subDays(max(1, FrontDeskOpsService::cfg()['log_unread_days'])))
            ->where(fn ($q) => $q->whereNull('created_by')->orWhere('created_by', '!=', $userId))
            ->whereDoesntHave('reads', fn ($q) => $q->where('user_id', $userId));
    }

    public static function unreadCount(int $userId): int
    {
        return self::unreadQuery($userId)->count();
    }

    /** pinned entries still in force */
    public static function pinnedQuery()
    {
        return LogEntry::where('pinned', true)->where(fn ($q) => $q->whereNull('pinned_until')->orWhereDate('pinned_until', '>=', bdate()->toDateString()));
    }

    /** the hand-over: what happened since the previous shift started + everything still open + pinned notes */
    public static function handover(): array
    {
        $since = FrontDeskOpsService::previousShiftStart();
        $with = ['author', 'reads.user', 'stay.room', 'room', 'guest', 'comments'];

        return [
            'since' => $since,
            'recent' => LogEntry::with($with)->where('created_at', '>=', $since)->orderByDesc('id')->get(),
            'open' => LogEntry::with($with)->where('status', 'open')->where('created_at', '<', $since)
                ->orderByRaw("FIELD(priority,'urgent','high','normal')")->orderBy('follow_up_at')->orderByDesc('id')->get(),
            'pinned' => self::pinnedQuery()->with($with)->orderByDesc('id')->get(),
        ];
    }
}
