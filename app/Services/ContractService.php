<?php

namespace App\Services;

use App\Models\ContractAllotment;
use App\Models\RateContract;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\RoomType;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * v2.7 negotiated rate contracts: which contract applies to a booking, the contract price of a stay (net grid by room
 * type / season / occupancy, or a percentage off the public rate; blackout nights fall back to the public rate),
 * allotments held out of general inventory until their release date, the approval lifecycle, renewal and production.
 */
class ContractService
{
    public const DEFAULTS = [
        'credit_auto_hold' => '1',            // put companies on hold automatically when bills are overdue
        'credit_hold_grace_days' => '15',     // … by more than their credit days + this grace
        'contract_expiry_alert_days' => '60', // "expiring soon" window
    ];

    public static function ensureDefaults(): void
    {
        foreach (self::DEFAULTS as $k => $v) {
            Setting::firstOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::$version++;
    }

    // ------------------------------------------------------------------ which contract

    /** The active contract of a company that covers an arrival date (latest start wins). */
    public static function forCompany(?int $companyId, $arrival): ?RateContract
    {
        if (! $companyId) {
            return null;
        }
        $d = Carbon::parse($arrival)->toDateString();

        return RateContract::where('company_id', $companyId)->where('status', 'active')
            ->whereDate('valid_from', '<=', $d)->whereDate('valid_to', '>=', $d)->orderByDesc('valid_from')->orderByDesc('id')->first();
    }

    /** Booking-engine corporate code → the active contract covering the arrival. */
    public static function byAccessCode(?string $code, $arrival): ?RateContract
    {
        $code = strtoupper(trim((string) $code));
        if ($code === '') {
            return null;
        }
        $c = RateContract::with('company')->where('access_code', $code)->first();
        $d = Carbon::parse($arrival)->toDateString();
        if (! $c || $c->status !== 'active' || ! $c->company?->is_active || $c->valid_from->toDateString() > $d || $c->valid_to->toDateString() < $d) {
            return null;
        }

        return $c;
    }

    // ------------------------------------------------------------------ pricing

    /**
     * Contract price of a stay. Same shape as RateService::quote() plus per-night 'basis' (net / discount / public)
     * and 'why' (blackout / outside), 'public_total', 'saving', 'contract' (code) and 'lra'.
     */
    public static function quote(RateContract $c, RoomType $type, $from, $to, int $adults = 1): array
    {
        $c->loadMissing('rates', 'blackouts');
        $bar = RateService::quote($type, $from, $to);
        $from = $c->valid_from->toDateString();
        $to = $c->valid_to->toDateString();
        $lines = $c->rates->where('room_type_id', $type->id);
        $extra = null;
        $nights = [];
        foreach ($bar['nights'] as $n) {
            $d = $n['date'];
            $row = ['date' => $d, 'rate' => (float) $n['rate'], 'public_rate' => (float) $n['rate'], 'season' => $n['season'], 'basis' => 'public', 'why' => null];
            if ($d < $from || $d > $to) {
                $row['why'] = 'outside';
            } elseif ($b = $c->blackouts->first(fn ($b) => $b->covers($d))) {
                $row['why'] = 'blackout'.($b->reason ? ': '.$b->reason : '');
            } else {
                $line = $c->pricing === 'net' ? ($lines->first(fn ($l) => $l->season_from && $l->covers($d)) ?? $lines->first(fn ($l) => ! $l->season_from)) : null;
                if ($line) {
                    $row['rate'] = (float) ($adults >= 2 && $line->double_rate !== null ? $line->double_rate : $line->rate);
                    $row['basis'] = 'net';
                    $row['season'] = $line->season_name;
                    $extra ??= $line->extra_adult_rate !== null ? (float) $line->extra_adult_rate : null;
                } elseif ((float) $c->discount_pct > 0) {
                    $row['rate'] = round($row['public_rate'] * (1 - (float) $c->discount_pct / 100), 2);
                    $row['basis'] = 'discount';
                }
            }
            $nights[] = $row;
        }
        $total = round(array_sum(array_column($nights, 'rate')), 2);
        $public = round(array_sum(array_column($nights, 'public_rate')), 2);
        $contractNights = count(array_filter($nights, fn ($n) => $n['basis'] !== 'public'));

        return ['nights' => $nights, 'count' => count($nights), 'total' => $total, 'average' => round($total / max(1, count($nights)), 2),
            'public_total' => $public, 'saving' => round($public - $total, 2), 'contract' => $c->code, 'contract_id' => $c->id, 'lra' => $c->lra,
            'contract_nights' => $contractNights, 'extra_adult_rate' => $extra,
            'min_stay' => $c->lra && $contractNights ? 1 : $bar['min_stay'], 'stop_sell' => $c->lra && $contractNights ? false : $bar['stop_sell'],
            'min_stay_ok' => $c->lra && $contractNights ? true : $bar['min_stay_ok']];
    }

    // ------------------------------------------------------------------ allotments

    /**
     * Unsold allotment rooms still held out of general inventory: [type][Y-m-d] => rooms. A night is released
     * (back on general sale) once fewer than release_days remain before it.
     */
    public static function heldRooms($from, $to, ?int $exceptContractId = null): array
    {
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->startOfDay();
        $today = bdate()->copy()->startOfDay();
        $allots = ContractAllotment::whereHas('contract', fn ($q) => $q->where('status', 'active'))
            ->when($exceptContractId, fn ($q) => $q->where('rate_contract_id', '!=', $exceptContractId))
            ->whereDate('date_from', '<', $to->toDateString())->whereDate('date_to', '>=', $from->toDateString())->get();
        if ($allots->isEmpty()) {
            return [];
        }
        $picked = self::pickedStays($allots->pluck('rate_contract_id')->unique()->all(), $from, $to);
        $held = [];
        foreach ($allots as $a) {
            $start = $a->date_from->copy()->max($from);
            $end = $a->date_to->copy()->addDay()->min($to);
            for ($d = $start->copy(); $d->lt($end); $d->addDay()) {
                if ($today->diffInDays($d, false) < $a->release_days) {
                    continue; // released
                }
                $key = $d->toDateString();
                $p = $picked->filter(fn ($s) => $s->contract_id === $a->rate_contract_id && $s->room_type_id === $a->room_type_id
                    && $s->arrival_date->lte($d) && $s->departure_date->gt($d))->count();
                $held[$a->room_type_id][$key] = ($held[$a->room_type_id][$key] ?? 0) + max(0, $a->rooms - $p);
            }
        }

        return $held;
    }

    /** Active stays booked under the given contracts that touch the range (each with ->contract_id). */
    public static function pickedStays(array $contractIds, Carbon $from, Carbon $to): Collection
    {
        $resToContract = Reservation::whereIn('rate_contract_id', $contractIds)->where('arrival_date', '<', $to->toDateString())
            ->where('departure_date', '>', $from->toDateString())->pluck('rate_contract_id', 'id');

        return ReservationRoom::whereIn('reservation_id', $resToContract->keys())->whereIn('status', ['reserved', 'checked_in'])
            ->get(['id', 'reservation_id', 'room_type_id', 'arrival_date', 'departure_date'])
            ->each(fn ($s) => $s->contract_id = (int) $resToContract[$s->reservation_id]);
    }

    /** Allotment grid for the contract page: [allotmentId => [date => [allotted, picked, left, released]]]. */
    public static function allotmentGrid(RateContract $c, Carbon $from, int $days): array
    {
        $to = $from->copy()->addDays($days);
        $today = bdate()->copy()->startOfDay();
        $picked = self::pickedStays([$c->id], $from, $to);
        $out = [];
        foreach ($c->allotments as $a) {
            for ($d = $from->copy(); $d->lt($to); $d->addDay()) {
                if ($d->lt($a->date_from) || $d->gt($a->date_to)) {
                    $out[$a->id][$d->toDateString()] = null;

                    continue;
                }
                $p = $picked->filter(fn ($s) => $s->room_type_id === $a->room_type_id && $s->arrival_date->lte($d) && $s->departure_date->gt($d))->count();
                $out[$a->id][$d->toDateString()] = ['allotted' => $a->rooms, 'picked' => $p, 'left' => max(0, $a->rooms - $p),
                    'released' => $today->diffInDays($d, false) < $a->release_days];
            }
        }

        return $out;
    }

    /** Utilisation of an allotment over its whole period (room nights allotted vs picked up). */
    public static function allotmentUsage(ContractAllotment $a): array
    {
        $from = $a->date_from->copy();
        $to = $a->date_to->copy()->addDay();
        $picked = self::pickedStays([$a->rate_contract_id], $from, $to)->where('room_type_id', $a->room_type_id);
        $allotted = 0;
        $used = 0;
        $released = 0;
        $today = bdate()->copy()->startOfDay();
        for ($d = $from->copy(); $d->lt($to); $d->addDay()) {
            $p = min($a->rooms, $picked->filter(fn ($s) => $s->arrival_date->lte($d) && $s->departure_date->gt($d))->count());
            $allotted += $a->rooms;
            $used += $p;
            if ($today->diffInDays($d, false) < $a->release_days) {
                $released += $a->rooms - $p;
            }
        }

        return ['allotted' => $allotted, 'picked' => $used, 'released' => $released, 'pct' => $allotted ? round($used / $allotted * 100, 1) : 0.0];
    }

    // ------------------------------------------------------------------ lifecycle

    public static function activate(RateContract $c): void
    {
        $c->loadMissing('rates', 'company');
        if (! in_array($c->status, ['draft', 'suspended'])) {
            throw ValidationException::withMessages(['contract' => "Contract {$c->code} is already {$c->status}."]);
        }
        if ($c->valid_to->toDateString() < bdate()->toDateString()) {
            throw ValidationException::withMessages(['contract' => "Contract {$c->code} ended on {$c->valid_to->format('d M Y')} – renew it instead."]);
        }
        if ($c->pricing === 'net' && $c->rates->isEmpty()) {
            throw ValidationException::withMessages(['contract' => 'Add at least one net rate before activating.']);
        }
        if ($c->pricing === 'discount' && (float) $c->discount_pct <= 0) {
            throw ValidationException::withMessages(['contract' => 'Set the discount before activating.']);
        }
        if (! $c->company->is_active) {
            throw ValidationException::withMessages(['contract' => "{$c->company->name} is inactive."]);
        }
        $user = auth()->user();
        if ($user && $c->created_by === $user->id && ! $user->isAdministrator()) {
            throw ValidationException::withMessages(['contract' => 'A contract must be approved by someone other than the person who drafted it.']);
        }
        $clash = RateContract::where('company_id', $c->company_id)->where('status', 'active')->where('id', '!=', $c->id)
            ->whereDate('valid_from', '<=', $c->valid_to)->whereDate('valid_to', '>=', $c->valid_from)->first();
        if ($clash) {
            throw ValidationException::withMessages(['contract' => "{$clash->code} already covers {$clash->valid_from->format('d M Y')} – {$clash->valid_to->format('d M Y')} for {$c->company->name}. Suspend it or change the dates."]);
        }
        $c->forceFill(['status' => 'active', 'approved_by' => auth()->id(), 'approved_at' => now()])->save();
        ActivityLogger::log('contracts', 'activate', "Activated contract {$c->code} for {$c->company->name}", $c);
    }

    public static function suspend(RateContract $c, string $reason): void
    {
        if ($c->status !== 'active') {
            throw ValidationException::withMessages(['contract' => 'Only an active contract can be suspended.']);
        }
        $c->forceFill(['status' => 'suspended', 'notes' => trim(($c->notes ? $c->notes."\n" : '').'Suspended '.bdate()->format('d M Y').': '.$reason)])->save();
        ActivityLogger::log('contracts', 'suspend', "Suspended contract {$c->code}: {$reason}", $c);
    }

    /** Draft a renewal for the next period with rates raised by $uplift % (rounded to whole units). */
    public static function renew(RateContract $c, string $code, float $uplift): RateContract
    {
        $c->loadMissing('rates', 'blackouts', 'allotments');
        $years = max(1, (int) ceil($c->valid_from->diffInDays($c->valid_to) / 366));
        $shift = fn (?Carbon $d) => $d?->copy()->addYears($years);
        $f = 1 + $uplift / 100;

        return DB::transaction(function () use ($c, $code, $f, $shift, $uplift) {
            $new = RateContract::create(collect($c->getAttributes())->except(['id', 'hotel_id', 'code', 'status', 'approved_by', 'approved_at', 'created_at', 'updated_at', 'renewed_from_id', 'created_by', 'valid_from', 'valid_to', 'access_code', 'notes'])->all()
                + ['code' => $code, 'status' => 'draft', 'valid_from' => $c->valid_to->copy()->addDay(), 'valid_to' => $shift($c->valid_to),
                    'renewed_from_id' => $c->id, 'created_by' => auth()->id(), 'access_code' => null, 'notes' => "Renewal of {$c->code}".($uplift ? " (+{$uplift}%)" : '')]);
            foreach ($c->rates as $r) {
                $new->rates()->create(['room_type_id' => $r->room_type_id, 'season_name' => $r->season_name, 'season_from' => $shift($r->season_from), 'season_to' => $shift($r->season_to),
                    'rate' => round($r->rate * $f), 'double_rate' => $r->double_rate !== null ? round($r->double_rate * $f) : null,
                    'extra_adult_rate' => $r->extra_adult_rate !== null ? round($r->extra_adult_rate * $f) : null]);
            }
            foreach ($c->blackouts as $b) {
                $new->blackouts()->create(['date_from' => $shift($b->date_from), 'date_to' => $shift($b->date_to), 'reason' => $b->reason]);
            }
            foreach ($c->allotments as $a) {
                $new->allotments()->create(['room_type_id' => $a->room_type_id, 'date_from' => $shift($a->date_from), 'date_to' => $shift($a->date_to), 'rooms' => $a->rooms, 'release_days' => $a->release_days]);
            }
            ActivityLogger::log('contracts', 'renew', "Drafted renewal {$new->code} of {$c->code}", $new);

            return $new;
        });
    }

    // ------------------------------------------------------------------ production

    /** Bookings, room nights, revenue, ADR and progress against the room-night target. */
    public static function production(RateContract $c): array
    {
        $ids = Reservation::where('rate_contract_id', $c->id)->where('status', '!=', 'cancelled')->pluck('id');
        $v = ReservationRoom::whereIn('reservation_id', $ids)->where('status', '!=', 'cancelled')
            ->selectRaw('COALESCE(SUM(DATEDIFF(departure_date, arrival_date)),0) n, COALESCE(SUM(rate * DATEDIFF(departure_date, arrival_date)),0) v')->first();
        $actual = ReservationRoom::whereIn('reservation_id', $ids)->whereIn('status', ['checked_in', 'checked_out'])
            ->selectRaw('COALESCE(SUM(DATEDIFF(LEAST(departure_date, ?), arrival_date)),0) n', [bdate()->toDateString()])->value('n');
        $nights = (int) $v->n;
        $period = max(1, $c->valid_from->diffInDays($c->valid_to) + 1);
        $elapsed = min($period, max(0, $c->valid_from->diffInDays(bdate(), false) + 1));
        $target = (int) $c->room_night_target;

        return ['bookings' => $ids->count(), 'nights' => $nights, 'stayed' => max(0, (int) $actual), 'revenue' => round((float) $v->v, 2),
            'adr' => $nights ? round((float) $v->v / $nights, 2) : 0.0, 'target' => $target,
            'target_pct' => $target ? round($nights / $target * 100, 1) : null,
            'pace_pct' => $target ? round($elapsed / $period * 100, 1) : null, // where production "should" be by today
            'cancelled' => Reservation::where('rate_contract_id', $c->id)->where('status', 'cancelled')->count()];
    }

    /** Counts for the sidebar badge / dashboard: active contracts ending within the alert window, drafts awaiting approval. */
    public static function alerts(): array
    {
        $days = (int) setting('contract_expiry_alert_days', '60');
        $today = bdate()->toDateString();

        return ['expiring' => RateContract::where('status', 'active')->whereDate('valid_to', '>=', $today)
            ->whereDate('valid_to', '<=', bdate()->copy()->addDays($days)->toDateString())->count(),
            'drafts' => RateContract::where('status', 'draft')->count()];
    }
}
