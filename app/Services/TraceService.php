<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Trace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** v3.5 – traces: dated to-dos for a department, optionally tied to a reservation / stay; rolled forward by the night audit. */
class TraceService
{
    public const DEPARTMENTS = ['front_office' => 'Front Office', 'reservations' => 'Reservations', 'housekeeping' => 'Housekeeping', 'fnb' => 'Food & Beverage',
        'engineering' => 'Engineering', 'concierge' => 'Concierge', 'accounts' => 'Accounts', 'sales' => 'Sales'];

    public static function create(array $d): Trace
    {
        $text = trim((string) ($d['text'] ?? ''));
        if ($text === '') {
            throw new RuntimeException('What needs to be done?');
        }
        $date = Carbon::parse($d['trace_date'] ?? bdate());
        if ($date->lt(bdate()->copy()->startOfDay())) {
            throw new RuntimeException('A trace cannot be dated in the past.');
        }
        $stayId = ! empty($d['reservation_room_id']) ? (int) $d['reservation_room_id'] : null;
        $reservationId = ! empty($d['reservation_id']) ? (int) $d['reservation_id'] : null;
        $time = trim((string) ($d['due_time'] ?? ''));

        return DB::transaction(function () use ($d, $text, $date, $stayId, $reservationId, $time) {
            $stay = null;
            $res = null;
            if ($stayId) {
                $snapshot = ReservationRoom::whereKey($stayId)->firstOrFail();
                $res = Reservation::whereKey($snapshot->reservation_id)->lockForUpdate()->firstOrFail();
                $stay = ReservationRoom::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
                $stay->setRelation('reservation', $res);
            } elseif ($reservationId) {
                $res = Reservation::whereKey($reservationId)->lockForUpdate()->firstOrFail();
            }
            if ($res && in_array($res->status, ['cancelled', 'no_show', 'checked_out'], true) && ! $stay) {
                throw new RuntimeException('That reservation is '.str_replace('_', ' ', $res->status).'.');
            }
            if ($date->lt(bdate()->copy()->startOfDay())) {
                throw new RuntimeException('A trace cannot be dated in the past.');
            }
            $t = Trace::create([
                'trace_date' => $date->toDateString(), 'due_time' => $time !== '' ? substr($time, 0, 5).':00' : null,
                'department' => array_key_exists($d['department'] ?? '', self::DEPARTMENTS) ? $d['department'] : 'front_office',
                'reservation_id' => $res?->id, 'reservation_room_id' => $stay?->id, 'guest_id' => $stay?->guest_id ?? $res?->guest_id,
                'text' => mb_substr($text, 0, 255), 'status' => 'open', 'created_by' => auth()->id(),
            ]);
            ActivityLogger::log('traces', 'create', 'Trace '.$t->trace_date->format('d M').' ('.self::DEPARTMENTS[$t->department].'): '.$t->text, $t);

            return $t;
        });
    }

    public static function complete(Trace $t, ?string $note = null): Trace
    {
        return DB::transaction(function () use ($t, $note) {
            $locked = Trace::whereKey($t->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isOpen()) {
                throw new RuntimeException('This trace is already '.$locked->status.'.');
            }
            $locked->forceFill(['status' => 'done', 'done_at' => now(), 'done_by' => auth()->id(), 'done_note' => trim((string) $note) ? mb_substr(trim($note), 0, 190) : null])->save();
            ActivityLogger::log('traces', 'done', 'Trace done: '.$locked->text, $locked);

            return $locked;
        });
    }

    public static function cancel(Trace $t, string $reason): Trace
    {
        if (trim($reason) === '') {
            throw new RuntimeException('Give a reason.');
        }

        return DB::transaction(function () use ($t, $reason) {
            $locked = Trace::whereKey($t->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isOpen()) {
                throw new RuntimeException('This trace is already '.$locked->status.'.');
            }
            $locked->forceFill(['status' => 'cancelled', 'done_at' => now(), 'done_by' => auth()->id(), 'done_note' => mb_substr(trim($reason), 0, 190)])->save();
            ActivityLogger::log('traces', 'cancel', 'Trace cancelled: '.$locked->text.' – '.trim($reason), $locked);

            return $locked;
        });
    }

    /** night audit: open traces up to the closing date move to the next business day */
    public static function carryForward(Carbon $closing): int
    {
        $closingDate = $closing->toDateString();
        $next = $closing->copy()->addDay()->toDateString();
        $ids = Trace::where('status', 'open')->whereDate('trace_date', '<=', $closingDate)->orderBy('id')->pluck('id');
        $n = 0;
        foreach ($ids as $id) {
            $carried = DB::transaction(function () use ($id, $closingDate, $next) {
                $trace = Trace::whereKey($id)->lockForUpdate()->first();
                if (! $trace || $trace->status !== 'open' || $trace->trace_date->toDateString() > $closingDate) {
                    return false;
                }
                $trace->forceFill(['carried_from' => $trace->carried_from ?? $trace->trace_date, 'trace_date' => $next])->save();

                return true;
            });
            $n += $carried ? 1 : 0;
        }

        return $n;
    }

    /** traces of a reservation – open first */
    public static function forReservation(Reservation $r)
    {
        return Trace::with('creator', 'doer', 'stay.room')->where('reservation_id', $r->id)->orderByRaw("status = 'open' desc")->orderBy('trace_date')->get();
    }
}
