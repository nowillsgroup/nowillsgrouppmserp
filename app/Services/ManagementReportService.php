<?php

namespace App\Services;

use App\Models\Company;
use App\Models\FolioTransaction;
use App\Models\PosOrder;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\Voucher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Manager's flash report (Day / MTD / YTD) and accounts-receivable ageing.
 * Always computed from source transactions, so the numbers are correct even for days without an audit record.
 */
class ManagementReportService
{
    public const ROOM_CATS = ['room', 'extra_bed'];

    /** @return array{0: array, 1: Collection} [periods, rows(metric, day, mtd, ytd)] */
    public static function flash(Carbon $date): array
    {
        $date = $date->copy()->startOfDay();
        $periods = ['day' => [$date->copy(), $date->copy()], 'mtd' => [$date->copy()->startOfMonth(), $date->copy()], 'ytd' => [$date->copy()->startOfYear(), $date->copy()]];
        $yStart = $periods['ytd'][0];
        $rooms = Room::where('is_active', true)->count();

        // Load the whole YTD window once, then slice per period in memory.
        $stays = ReservationRoom::whereIn('status', ['checked_in', 'checked_out'])->whereDate('arrival_date', '<=', $date)->whereDate('departure_date', '>', $yStart)
            ->get(['arrival_date', 'departure_date', 'adults', 'children', 'status', 'checked_out_at']);
        $tx = FolioTransaction::where('is_void', false)->whereDate('business_date', '>=', $yStart)->whereDate('business_date', '<=', $date)
            ->get(['business_date', 'type', 'category', 'amount', 'tax_amount']);
        $pos = PosOrder::where('status', 'settled')->whereDate('business_date', '>=', $yStart)->whereDate('business_date', '<=', $date)
            ->get(['business_date', 'settlement', 'subtotal', 'discount', 'service_charge', 'tax', 'total']);
        $res = Reservation::where(fn ($q) => $q->whereDate('arrival_date', '>=', $yStart)->whereDate('arrival_date', '<=', $date)->orWhereBetween('cancelled_at', [$yStart, $date->copy()->endOfDay()]))
            ->get(['arrival_date', 'status', 'cancelled_at']);

        $m = [];
        foreach ($periods as $k => [$a, $b]) {
            $in = fn ($d) => $d && $d->betweenIncluded($a, $b->copy()->endOfDay());
            $days = $a->diffInDays($b) + 1;
            $end = $b->copy()->addDay();
            $nights = $stays->sum(fn ($s) => max(0, (int) $s->arrival_date->max($a)->diffInDays($s->departure_date->min($end), false)));
            $t = $tx->filter(fn ($x) => $in($x->business_date));
            $charges = $t->where('type', 'charge');
            $p = $pos->filter(fn ($o) => $in($o->business_date));
            $direct = $p->where('settlement', 'payment');
            $roomRev = (float) $charges->whereIn('category', self::ROOM_CATS)->sum('amount');
            $fnb = (float) $charges->where('category', 'fnb')->sum('amount') + (float) $direct->sum(fn ($o) => $o->subtotal - $o->discount + $o->service_charge);
            $other = (float) $charges->whereNotIn('category', [...self::ROOM_CATS, 'fnb'])->sum('amount');
            $disc = (float) $t->whereIn('type', ['discount', 'allowance'])->sum('amount');
            $paid = $t->where('type', 'payment')->whereNotIn('category', ['city_ledger', 'loyalty'])->sum('amount') - $t->where('type', 'refund')->sum('amount') + $direct->sum('total');
            $available = $rooms * $days;
            $m[$k] = [
                'available' => $available, 'sold' => $nights,
                'pax' => $stays->sum(fn ($s) => $s->arrival_date->lte($b) && $s->departure_date->gt($b) ? $s->adults + $s->children : 0),
                'occ' => $available ? $nights * 100 / $available : 0,
                'room' => $roomRev, 'adr' => $nights ? $roomRev / $nights : 0, 'revpar' => $available ? $roomRev / $available : 0,
                'fnb' => $fnb, 'other' => $other, 'disc' => $disc, 'net' => $roomRev + $fnb + $other - $disc,
                'tax' => (float) $charges->sum('tax_amount') + (float) $direct->sum('tax'), 'collect' => (float) $paid,
                'arrivals' => $stays->filter(fn ($s) => $s->arrival_date->betweenIncluded($a, $b))->count(),
                'departures' => $stays->where('status', 'checked_out')->filter(fn ($s) => $s->departure_date->betweenIncluded($a, $b))->count(),
                'noshow' => $res->where('status', 'no_show')->filter(fn ($r) => $r->arrival_date->betweenIncluded($a, $b))->count(),
                'cancel' => $res->where('status', 'cancelled')->filter(fn ($r) => $in($r->cancelled_at))->count(),
                'covers' => $p->count(),
            ];
        }
        $defs = [
            ['Rooms available', 'available', 'int'], ['Room nights sold', 'sold', 'int'], ['Occupancy %', 'occ', 'pct'],
            ['Guests in house (end of period)', 'pax', 'int'],
            ['Room revenue', 'room', 'money'], ['ADR (avg. daily rate)', 'adr', 'money'], ['RevPAR', 'revpar', 'money'],
            ['F&B revenue (room-posted + outlet)', 'fnb', 'money'], ['Other revenue', 'other', 'money'], ['Discounts & allowances', 'disc', 'money'],
            ['Net revenue (excl. tax)', 'net', 'money'], ['Taxes collected', 'tax', 'money'], ['Collections (cash, card, UPI…)', 'collect', 'money'],
            ['Arrivals', 'arrivals', 'int'], ['Departures', 'departures', 'int'], ['No-shows', 'noshow', 'int'], ['Cancellations', 'cancel', 'int'], ['POS bills', 'covers', 'int'],
        ];
        $fmt = fn ($v, $f) => match ($f) { 'money' => money($v, false), 'pct' => number_format($v, 1).'%', default => number_format($v) };
        $rows = collect($defs)->map(fn ($d) => ['metric' => $d[0], 'day' => $fmt($m['day'][$d[1]], $d[2]), 'mtd' => $fmt($m['mtd'][$d[1]], $d[2]), 'ytd' => $fmt($m['ytd'][$d[1]], $d[2]), 'key' => $d[1]]);

        return [$m, $rows];
    }

    /**
     * City-ledger ageing as on a date. Receipts are applied FIFO against the oldest bills,
     * so the buckets show which invoices are still unpaid and for how long.
     */
    public static function arAgeing(Carbon $asOn): Collection
    {
        $asOn = $asOn->copy()->startOfDay();
        $bills = FolioTransaction::where('category', 'city_ledger')->where('is_void', false)->whereNotNull('company_id')->whereDate('business_date', '<=', $asOn)
            ->orderBy('business_date')->orderBy('id')->get(['company_id', 'business_date', 'amount'])->groupBy('company_id');
        $paid = Voucher::where('type', 'receipt')->whereNotNull('company_id')->whereDate('date', '<=', $asOn)->get(['company_id', 'amount'])->groupBy('company_id')->map->sum('amount');
        $companies = Company::whereIn('id', $bills->keys()->merge($paid->keys())->unique())->orderBy('name')->get();

        return $companies->map(function ($c) use ($bills, $paid, $asOn) {
            $credit = (float) ($paid[$c->id] ?? 0);
            $b = ['b0' => 0.0, 'b30' => 0.0, 'b60' => 0.0, 'b90' => 0.0];
            $billed = 0.0;
            $oldest = null;
            foreach ($bills[$c->id] ?? [] as $bill) {
                $amt = (float) $bill->amount;
                $billed += $amt;
                $applied = min($amt, $credit);
                $credit -= $applied;
                $open = round($amt - $applied, 2);
                if ($open <= 0) {
                    continue;
                }
                $age = (int) $bill->business_date->diffInDays($asOn);
                $oldest ??= $age;
                $b[$age <= 30 ? 'b0' : ($age <= 60 ? 'b30' : ($age <= 90 ? 'b60' : 'b90'))] += $open;
            }
            $outstanding = array_sum($b) - $credit; // leftover credit = advance / on-account

            return ['company' => $c->name, 'type' => ucwords(str_replace('_', ' ', $c->type)), 'billed' => round($billed, 2), 'received' => (float) ($paid[$c->id] ?? 0),
                'b0' => round($b['b0'], 2), 'b30' => round($b['b30'], 2), 'b60' => round($b['b60'], 2), 'b90' => round($b['b90'], 2),
                'unapplied' => round($credit, 2), 'outstanding' => round($outstanding, 2), 'limit' => (float) $c->credit_limit,
                'oldest' => $oldest !== null ? $oldest.' d' : '—',
                'flag' => $c->credit_limit > 0 && $outstanding > $c->credit_limit ? 'OVER LIMIT' : ($b['b90'] > 0 ? '90+ overdue' : ''), 'id' => $c->id];
        })->filter(fn ($r) => abs($r['outstanding']) > 0.004 || $r['billed'] > 0)->sortByDesc('outstanding')->values();
    }
}
