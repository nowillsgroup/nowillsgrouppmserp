<?php

namespace App\Services;

use App\Models\CashierMovement;
use App\Models\CashierShift;
use App\Models\FolioTransaction;
use App\Models\PaymentMode;
use App\Models\PosOrder;
use App\Models\PosPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Cashiering: a cashier opens a shift with a cash float, every payment / refund / POS
 * settlement they take is attributed to them, and the drawer supports auditable cash-in /
 * cash-out movements. At close, denominations, expected cash and per-mode totals are
 * snapshotted so later edits cannot change a closed report.
 */
class ShiftService
{
    /** Common Ghana cedi notes and coins used by the close-out counter. */
    public const DENOMINATIONS = [200, 100, 50, 20, 10, 5, 2, 1, 0.5, 0.2, 0.1];

    public static function required(): bool
    {
        return setting('require_cashier_shift', '0') === '1';
    }

    public static function current(?User $user = null): ?CashierShift
    {
        $user ??= auth()->user();

        return $user ? CashierShift::where('user_id', $user->id)->where('status', 'open')->latest('id')->first() : null;
    }

    /** Called before a user takes money when shifts are mandatory. */
    public static function assertOpen(): void
    {
        if (self::required() && auth()->check() && ! self::current()) {
            throw new RuntimeException('Open your cashier shift (Finance › Cashiering) before receiving payments.');
        }
    }

    public static function open(float $float, ?string $notes = null): CashierShift
    {
        return DB::transaction(function () use ($float, $notes) {
            if (CashierShift::where('user_id', auth()->id())->where('status', 'open')->lockForUpdate()->exists()) {
                throw new RuntimeException('You already have an open shift.');
            }
            $s = CashierShift::create(['user_id' => auth()->id(), 'business_date' => bdate(), 'opened_at' => now(), 'opening_float' => round($float, 2), 'notes' => $notes]);
            ActivityLogger::log('shift', 'open', 'Opened cashier shift with float '.money($float), $s);

            return $s;
        });
    }

    public static function cashIn(float $amount, string $reason, ?string $reference = null): CashierMovement
    {
        return self::movement('cash_in', $amount, $reason, $reference);
    }

    public static function cashOut(float $amount, string $reason, ?string $reference = null): CashierMovement
    {
        return self::movement('cash_out', $amount, $reason, $reference);
    }

    protected static function movement(string $kind, float $amount, string $reason, ?string $reference = null): CashierMovement
    {
        if (! in_array($kind, ['cash_in', 'cash_out'], true)) {
            throw new RuntimeException('Unknown cash drawer movement.');
        }
        if (! is_finite($amount) || $amount <= 0) {
            throw new RuntimeException('Enter an amount greater than zero.');
        }
        $user = auth()->user();
        if (! $user) {
            throw new RuntimeException('Sign in as a cashier before recording a drawer movement.');
        }
        $shift = self::current($user);
        if (! $shift) {
            throw new RuntimeException('Open your cashier shift before recording a drawer movement.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('Give the reason for this drawer movement.');
        }

        return DB::transaction(function () use ($kind, $amount, $reason, $reference, $user, $shift) {
            $locked = CashierShift::lockForUpdate()->findOrFail($shift->id);
            if ($locked->status !== 'open') {
                throw new RuntimeException('This cashier shift is already closed.');
            }
            if ($kind === 'cash_out' && $amount > self::summary($locked)['expected_cash'] + 0.005) {
                throw new RuntimeException('Cash out exceeds the expected drawer balance. Record the collection or correct the amount first.');
            }
            $movement = CashierMovement::create([
                'cashier_shift_id' => $locked->id, 'user_id' => $user->id, 'business_date' => bdate()->toDateString(),
                'kind' => $kind, 'amount' => round($amount, 2), 'reason' => mb_substr($reason, 0, 190),
                'reference' => filled($reference) ? mb_substr(trim($reference), 0, 80) : null,
            ]);
            ActivityLogger::log('shift', $kind, ucfirst(str_replace('_', ' ', $kind)).' '.money($amount).': '.$reason, $movement);

            return $movement;
        });
    }

    /**
     * Live (open) or snapshot (closed) totals for a shift.
     * Returns modes[name => [in, out, net, is_cash]], cash_in, cash_out, expected_cash, total_in, count.
     */
    public static function summary(CashierShift $shift): array
    {
        if ($shift->status === 'closed' && $shift->summary) {
            return $shift->summary;
        }
        $from = $shift->opened_at;
        $to = $shift->closed_at ?? now();
        $modes = PaymentMode::withoutGlobalScopes()->where('hotel_id', $shift->hotel_id)->get()->keyBy('id');
        $rows = [];
        $add = function ($modeId, float $in, float $out) use (&$rows, $modes) {
            $m = $modes[$modeId] ?? null;
            $k = $m?->name ?? ($modeId === null ? 'Cash drawer' : 'Other');
            $rows[$k] ??= ['in' => 0.0, 'out' => 0.0, 'net' => 0.0, 'is_cash' => $m?->type === 'cash' || $modeId === null, 'count' => 0];
            $rows[$k]['in'] += $in;
            $rows[$k]['out'] += $out;
            $rows[$k]['net'] += $in - $out;
            $rows[$k]['count']++;
        };
        FolioTransaction::where('posted_by', $shift->user_id)->whereBetween('created_at', [$from, $to])->where('is_void', false)
            ->whereIn('type', ['payment', 'refund'])->where('category', '!=', 'city_ledger')->whereNotNull('payment_mode_id')
            ->get(['type', 'amount', 'payment_mode_id'])
            ->each(fn ($t) => $add($t->payment_mode_id, $t->type === 'payment' ? (float) $t->amount : 0, $t->type === 'refund' ? (float) $t->amount : 0));
        $posPayments = PosPayment::where('received_by', $shift->user_id)->whereBetween('received_at', [$from, $to])->get(['pos_order_id', 'payment_mode_id', 'kind', 'amount']);
        $posPayments->each(fn ($p) => $add($p->payment_mode_id, $p->kind === 'payment' ? (float) $p->amount : 0, $p->kind === 'refund' ? (float) $p->amount : 0));
        // Seeded / historic orders may pre-date the ledger. Count them once through the legacy settlement columns.
        $ledgerOrderIds = $posPayments->pluck('pos_order_id')->unique();
        PosOrder::where('settled_by', $shift->user_id)->whereBetween('settled_at', [$from, $to])->where('status', 'settled')->where('settlement', 'payment')
            ->when($ledgerOrderIds->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $ledgerOrderIds))
            ->get(['id', 'total', 'payment_mode_id'])->each(fn ($o) => $add($o->payment_mode_id, (float) $o->total, 0));
        CashierMovement::where('cashier_shift_id', $shift->id)->whereBetween('created_at', [$from, $to])
            ->get(['kind', 'amount'])->each(fn ($m) => $add(null, $m->kind === 'cash_in' ? (float) $m->amount : 0, $m->kind === 'cash_out' ? (float) $m->amount : 0));
        // v3.4 forex desk: cedis paid out for foreign currency leave the drawer; the currency taken in is handed over with the shift
        $fx = \App\Models\ForexExchange::withoutGlobalScopes()->with('currency')->where('hotel_id', $shift->hotel_id)->where('created_by', $shift->user_id)
            ->whereBetween('created_at', [$from, $to])->whereNull('voided_at')->get();
        $cashMode = $modes->firstWhere('type', 'cash');
        foreach ($fx->where('payout', 'cash') as $x) {
            $add($cashMode?->id, 0, (float) $x->net_amount);
        }
        $forex = $fx->groupBy(fn ($x) => $x->currency?->code)->map(fn ($g) => round((float) $g->sum('fc_amount'), 2))->sortKeys()->all();
        ksort($rows);
        $cashIn = collect($rows)->where('is_cash', true)->sum('in');
        $cashOut = collect($rows)->where('is_cash', true)->sum('out');

        return ['modes' => $rows, 'cash_in' => round($cashIn, 2), 'cash_out' => round($cashOut, 2),
            'expected_cash' => round((float) $shift->opening_float + $cashIn - $cashOut, 2),
            'total_in' => round(collect($rows)->sum('in'), 2), 'total_out' => round(collect($rows)->sum('out'), 2), 'count' => collect($rows)->sum('count'), 'forex' => $forex];
    }

    public static function close(CashierShift $shift, float $counted, ?string $notes = null, array $denominations = []): CashierShift
    {
        if (! is_finite($counted) || $counted < 0) {
            throw new RuntimeException('Enter the cash counted in the drawer.');
        }
        $denominations = collect($denominations)->filter(fn ($qty, $value) => in_array((float) $value, self::DENOMINATIONS) && (float) $qty > 0)
            ->mapWithKeys(fn ($qty, $value) => [(string) $value => (int) $qty])->all();
        if ($denominations) {
            $denomTotal = collect($denominations)->sum(fn ($qty, $value) => (float) $qty * (float) $value);
            if (abs($denomTotal - $counted) > 0.01) {
                throw new RuntimeException('The denomination count totals '.money($denomTotal).' but counted cash is '.money($counted).'. Check the notes and coins.');
            }
        }

        return DB::transaction(function () use ($shift, $counted, $notes, $denominations) {
            $shift = CashierShift::lockForUpdate()->findOrFail($shift->id);
            if ($shift->status !== 'open') {
                throw new RuntimeException('This shift is already closed.');
            }
            $shift->closed_at = now();
            $sum = self::summary($shift);
            $shift->forceFill([
                'status' => 'closed', 'summary' => $sum, 'expected_cash' => $sum['expected_cash'], 'counted_cash' => round($counted, 2),
                'counted_denominations' => $denominations ?: null, 'closed_by' => auth()->id(),
                'variance' => round($counted - $sum['expected_cash'], 2), 'notes' => trim(($shift->notes ? $shift->notes."\n" : '').($notes ?? '')) ?: null,
            ])->save();
            ActivityLogger::log('shift', 'close', 'Closed cashier shift: expected '.money($shift->expected_cash).', counted '.money($counted).', variance '.money($shift->variance), $shift);

            return $shift;
        });
    }
}
