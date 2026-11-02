<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\FrontDeskShiftSession;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FrontDeskShiftSessionService
{
    public static function activeForUser(?int $userId = null): ?FrontDeskShiftSession
    {
        $userId ??= (int) auth()->id();

        return FrontDeskShiftSession::where('started_by', $userId)->where('status', 'open')->latest('started_at')->first();
    }

    public static function pendingCount(?int $userId = null): int
    {
        $userId ??= (int) auth()->id();

        return $userId ? FrontDeskShiftSession::where('incoming_user_id', $userId)->where('status', 'handover_pending')->count() : 0;
    }

    /** Staff who can receive an operational hand-over at this property. */
    public static function recipientOptions(?int $exceptUserId = null): array
    {
        $hotelId = Tenant::id();
        if (! $hotelId) {
            return [];
        }

        return User::query()->where('hotel_id', $hotelId)->where('is_active', true)
            ->when($exceptUserId, fn ($q) => $q->where('id', '!=', $exceptUserId))
            ->orderBy('name')->get()->filter(fn (User $user) => $user->hasPermission('logbook.view') && $user->hasPermission('logbook.write'))
            ->mapWithKeys(fn (User $user) => [$user->id => $user->name])->all();
    }

    public static function workspace(?int $userId = null, ?string $date = null): array
    {
        $userId ??= (int) auth()->id();
        $day = \Illuminate\Support\Carbon::parse($date ?? bdate()->toDateString())->toDateString();
        $with = ['starter:id,name', 'closer:id,name', 'incomingUser:id,name', 'receiver:id,name'];
        $activeSession = self::activeForUser($userId);
        $pendingHandovers = FrontDeskShiftSession::with($with)->withCount('logEntries')
            ->where('status', 'handover_pending')->where('incoming_user_id', $userId)->orderBy('closed_at')->get();
        $activityModules = [];
        $actor = auth()->user();
        foreach (['logbook' => 'logbook.view', 'wakeup' => 'desk.wakeup', 'messages' => 'desk.messages', 'traces' => 'desk.traces', 'lostfound' => 'lostfound.manage', 'frontdesk' => 'frontdesk.view'] as $module => $permission) {
            if ($actor?->hasPermission($permission)) {
                $activityModules[] = $module;
            }
        }
        $sessionActivities = [];
        if ($activityModules) {
            $activitySessions = collect([$activeSession])->filter()->merge($pendingHandovers)->unique('id');
            foreach ($activitySessions as $session) {
                $sessionActivities[$session->id] = ActivityLog::query()->select(['id', 'module', 'action', 'description', 'created_at'])
                    ->where('user_id', $session->started_by)->where('created_at', '>=', $session->started_at)
                    ->where('created_at', '<=', $session->closed_at ?? now())
                    ->whereIn('module', $activityModules)->orderByDesc('created_at')->limit(10)->get();
            }
        }
        $daySessions = FrontDeskShiftSession::with($with)->withCount('logEntries')->whereDate('business_date', $day)
            ->orderBy('shift')->orderBy('started_at')->get();
        $daySummary = $daySessions->groupBy('shift')->map(fn ($sessions) => [
            'people' => $sessions->unique('started_by')->count(),
            'sessions' => $sessions->count(),
            'open' => $sessions->where('status', 'open')->count(),
            'pending' => $sessions->where('status', 'handover_pending')->count(),
            'closed' => $sessions->where('status', 'closed')->count(),
            'log_entries' => $sessions->sum('log_entries_count'),
        ]);

        return [
            'activeSession' => $activeSession,
            'daySessions' => $daySessions,
            'daySummary' => $daySummary,
            'day' => $day,
            'pendingHandovers' => $pendingHandovers,
            'teamActiveSessions' => FrontDeskShiftSession::with('starter:id,name')->withCount('logEntries')
                ->where('status', 'open')->orderBy('started_at')->get(),
            'recentSessions' => FrontDeskShiftSession::with($with)->withCount('logEntries')
                ->orderByDesc('started_at')->limit(30)->get(),
            'recipients' => self::recipientOptions($userId),
            'sessionActivities' => $sessionActivities,
        ];
    }

    public static function start(User $actor, ?string $note = null): FrontDeskShiftSession
    {
        self::requirePermission($actor, 'logbook.write');
        $hotelId = Tenant::id();
        if (! $hotelId) {
            throw new RuntimeException('Select a hotel before starting a front-office shift.');
        }

        return DB::transaction(function () use ($actor, $note, $hotelId) {
            // Serialize starts by this account so two quick submissions cannot open duplicate sessions.
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            if (FrontDeskShiftSession::where('started_by', $actor->id)->where('status', 'open')->lockForUpdate()->exists()) {
                throw new RuntimeException('You already have an active front-office shift session.');
            }
            if (FrontDeskShiftSession::where('incoming_user_id', $actor->id)->where('status', 'handover_pending')->lockForUpdate()->exists()) {
                throw new RuntimeException('Acknowledge the hand-over assigned to you before starting your next shift session.');
            }

            $session = FrontDeskShiftSession::create([
                'hotel_id' => $hotelId,
                'business_date' => bdate()->toDateString(),
                'shift' => FrontDeskOpsService::currentShift(),
                'status' => 'open',
                'started_by' => $actor->id,
                'started_at' => now(),
                'start_note' => trim((string) $note) ?: null,
            ]);
            ActivityLogger::log('frontdesk', 'shift_start', 'Front-office shift session started ('.($session->shift).')', $session);

            return $session;
        });
    }

    public static function closeAndHandOver(FrontDeskShiftSession $session, User $actor, int $incomingUserId, string $note): FrontDeskShiftSession
    {
        self::requirePermission($actor, 'logbook.write');
        $note = trim($note);
        if ($note === '') {
            throw new RuntimeException('Add a hand-over summary before closing your shift.');
        }
        if ($incomingUserId === (int) $actor->id) {
            throw new RuntimeException('Choose a different staff member to receive the hand-over.');
        }

        return DB::transaction(function () use ($session, $actor, $incomingUserId, $note) {
            $locked = FrontDeskShiftSession::whereKey($session->getKey())->lockForUpdate()->firstOrFail();
            if ((int) $locked->started_by !== (int) $actor->id) {
                throw new RuntimeException('Only the staff member who started this session can close it.');
            }
            if ($locked->status !== 'open') {
                throw new RuntimeException('This shift session is no longer open.');
            }

            $recipient = User::query()->where('hotel_id', Tenant::id())->where('is_active', true)->find($incomingUserId);
            if (! $recipient || ! $recipient->hasPermission('logbook.view') || ! $recipient->hasPermission('logbook.write')) {
                throw new RuntimeException('Choose an active front-office staff member with shift-session access at this property.');
            }

            $closedAt = now();
            $snapshot = FrontDeskOpsService::snapshot();
            $locked->forceFill([
                'status' => 'handover_pending',
                'closed_by' => $actor->id,
                'closed_at' => $closedAt,
                'handover_snapshot' => $snapshot,
                'incoming_user_id' => $recipient->id,
                'handover_note' => mb_substr($note, 0, 4000),
            ])->save();
            ActivityLogger::log('frontdesk', 'shift_handover', 'Front-office shift handed over to '.$recipient->name, $locked);

            return $locked->load(['starter', 'incomingUser']);
        });
    }

    public static function acceptHandover(FrontDeskShiftSession $session, User $actor): FrontDeskShiftSession
    {
        self::requirePermission($actor, 'logbook.view');

        return DB::transaction(function () use ($session, $actor) {
            $locked = FrontDeskShiftSession::whereKey($session->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'handover_pending') {
                throw new RuntimeException('This hand-over has already been accepted or is no longer pending.');
            }
            if ((int) $locked->incoming_user_id !== (int) $actor->id) {
                throw new RuntimeException('Only the assigned incoming staff member can acknowledge this hand-over.');
            }

            LogBookService::markRead($locked->logEntries()->get(), (int) $actor->id);
            $locked->forceFill(['status' => 'closed', 'received_by' => $actor->id, 'received_at' => now()])->save();
            ActivityLogger::log('frontdesk', 'shift_handover_ack', 'Front-office hand-over acknowledged from '.$locked->starter?->name, $locked);

            return $locked;
        });
    }

    protected static function requirePermission(User $actor, string $permission): void
    {
        if (! $actor->hasPermission($permission)) {
            throw new RuntimeException('You do not have permission to manage front-office shift sessions.');
        }
    }
}
