<?php

namespace App\Services;

use App\Models\ClubBooking;
use App\Models\ClubClass;
use App\Models\ClubMember;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v5.1 Group classes: weekly timetable, bookings with capacity and an ordered waitlist (promoted automatically when a
 * place frees up), late cancellations counted as no-shows, attendance, and a booking block for repeat no-shows.
 */
class ClubClassService
{
    public const BOOK_AHEAD_DAYS = 14;

    public static function sessionStart(ClubClass $c, string $date): Carbon
    {
        return Carbon::parse($date.' '.$c->start_time);
    }

    /** The week's sessions (Monday-based) with booking counts. */
    public static function week(string $anyDate): array
    {
        $mon = Carbon::parse($anyDate)->startOfWeek(Carbon::MONDAY);
        $classes = ClubClass::with('employee')->where('is_active', true)->orderBy('start_time')->get();
        $counts = ClubBooking::whereBetween('date', [$mon->toDateString(), $mon->copy()->addDays(6)->toDateString()])
            ->selectRaw('club_class_id, date, status, COUNT(*) n')->groupBy('club_class_id', 'date', 'status')->get()
            ->groupBy(fn ($r) => $r->club_class_id.'|'.Carbon::parse($r->date)->toDateString());
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $mon->copy()->addDays($i);
            $days[$i + 1] = ['date' => $d, 'sessions' => $classes->where('weekday', $i + 1)->values()->map(function ($c) use ($d, $counts) {
                $k = $counts[$c->id.'|'.$d->toDateString()] ?? collect();
                $n = fn ($s) => (int) ($k->firstWhere('status', $s)?->n ?? 0);

                return ['class' => $c, 'booked' => $n('booked') + $n('attended') + $n('no_show'), 'attended' => $n('attended'), 'waitlist' => $n('waitlist')];
            })];
        }

        return ['monday' => $mon, 'days' => $days];
    }

    public static function noShowCount(ClubMember $p): int
    {
        return ClubBooking::where('club_member_id', $p->id)->where('status', 'no_show')->whereDate('date', '>=', bdate()->copy()->subDays(30)->toDateString())->count();
    }

    /** Book a place (member by card, or an in-house guest by room number). Full sessions go to the waitlist. */
    public static function book(ClubClass $c, string $date, ?ClubMember $p, ?string $roomNo = null, ?User $u = null, bool $force = false): ClubBooking
    {
        $d = Carbon::parse($date);
        if ((int) $d->isoWeekday() !== (int) $c->weekday) {
            throw new RuntimeException("{$c->name} runs on ".ClubClass::DAYS[$c->weekday].'s.');
        }
        if (! $c->is_active) {
            throw new RuntimeException('This class is no longer on the timetable.');
        }
        if (! $force && self::sessionStart($c, $d->toDateString())->lt(now())) {
            throw new RuntimeException('This session has already started.');
        }
        if (! $force && $d->toDateString() > bdate()->copy()->addDays(self::BOOK_AHEAD_DAYS)->toDateString()) {
            throw new RuntimeException('Bookings open '.self::BOOK_AHEAD_DAYS.' days ahead.');
        }
        $stay = null;
        if ($p) {
            $chk = ClubService::access($p, 'classes', self::sessionStart($c, $d->toDateString()));
            $reasons = array_values(array_filter($chk['reasons'], fn ($r) => ! str_starts_with($r, 'Already checked in') && ! str_contains($r, ' is full (')));
            if ($reasons && ! $force) {
                throw new RuntimeException('Cannot book: '.implode(' ', $reasons));
            }
            $limit = (int) ClubService::cfg('club_noshow_limit');
            if (! $force && $limit > 0 && self::noShowCount($p) >= $limit) {
                throw new RuntimeException("Class bookings are on hold – {$p->name} missed {$limit} or more classes in the last 30 days.");
            }
            if (ClubBooking::where('club_class_id', $c->id)->whereDate('date', $d->toDateString())->where('club_member_id', $p->id)->whereIn('status', ['booked', 'waitlist', 'attended'])->exists()) {
                throw new RuntimeException("{$p->name} is already booked on this session.");
            }
            $name = $p->name;
        } else {
            if (! $c->residents) {
                throw new RuntimeException('This class is for members only.');
            }
            $stay = ClubService::residentStay((string) $roomNo) ?? throw new RuntimeException("Room {$roomNo} has no in-house guest.");
            $name = ($stay->guest?->full_name ?? $stay->reservation?->guest?->full_name ?? 'Guest')." (Rm {$roomNo})";
        }

        return DB::transaction(function () use ($c, $d, $p, $stay, $name, $u) {
            ClubClass::whereKey($c->id)->lockForUpdate()->first();
            $taken = ClubBooking::where('club_class_id', $c->id)->whereDate('date', $d->toDateString())->whereIn('status', ['booked', 'attended'])->count();
            $wait = $taken >= $c->capacity;
            $pos = $wait ? (int) ClubBooking::where('club_class_id', $c->id)->whereDate('date', $d->toDateString())->where('status', 'waitlist')->max('position') + 1 : null;

            return ClubBooking::create(['club_class_id' => $c->id, 'date' => $d->toDateString(), 'club_member_id' => $p?->id, 'reservation_room_id' => $stay?->id,
                'name' => mb_substr($name, 0, 120), 'status' => $wait ? 'waitlist' : 'booked', 'position' => $pos, 'user_id' => $u?->id]);
        });
    }

    /** Cancel a booking. Inside the cut-off it counts as a no-show. A freed place goes to the first on the waitlist. */
    public static function cancel(ClubBooking $b, ?User $u = null): array
    {
        if (! in_array($b->status, ['booked', 'waitlist'], true)) {
            throw new RuntimeException('Only a live booking can be cancelled.');
        }
        $start = self::sessionStart($b->klass, $b->date->toDateString());
        if ($start->lt(now())) {
            throw new RuntimeException('The session has started – mark attendance instead.');
        }
        $late = $b->status === 'booked' && $b->club_member_id && now()->diffInMinutes($start) < (int) ClubService::cfg('club_class_cancel_hours') * 60;

        return DB::transaction(function () use ($b, $late) {
            $was = $b->status;
            $b->update(['status' => $late ? 'no_show' : 'cancelled', 'position' => null]);
            $promoted = $was === 'booked' ? self::promote($b->klass, $b->date->toDateString()) : null;

            return ['late' => $late, 'promoted' => $promoted];
        });
    }

    public static function promote(ClubClass $c, string $date): ?ClubBooking
    {
        $taken = ClubBooking::where('club_class_id', $c->id)->whereDate('date', $date)->whereIn('status', ['booked', 'attended'])->count();
        if ($taken >= $c->capacity) {
            return null;
        }
        $next = ClubBooking::where('club_class_id', $c->id)->whereDate('date', $date)->where('status', 'waitlist')->orderBy('position')->first();
        $next?->update(['status' => 'booked', 'position' => null]);

        return $next;
    }

    /** Register: [booking_id => attended|no_show]. From the session start (or any past session). */
    public static function attendance(ClubClass $c, string $date, array $marks, bool $force = false): int
    {
        if (! $force && self::sessionStart($c, $date)->subMinutes(15)->gt(now())) {
            throw new RuntimeException('Attendance can be taken from 15 minutes before the session.');
        }
        $n = 0;
        foreach ($marks as $id => $status) {
            if (! in_array($status, ['attended', 'no_show'], true)) {
                continue;
            }
            $b = ClubBooking::where('club_class_id', $c->id)->whereDate('date', $date)->whereIn('status', ['booked', 'attended', 'no_show'])->find($id);
            if ($b && $b->status !== $status) {
                $b->update(['status' => $status]);
                $n++;
            }
        }

        return $n;
    }
}
