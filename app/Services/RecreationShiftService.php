<?php

namespace App\Services;

use App\Models\ClubDayPass;
use App\Models\ClubTicketPayment;
use App\Models\ClubTicketSale;
use App\Models\ClubTicketSaleItem;
use App\Models\DepartmentShiftSession;
use App\Models\PaymentMode;
use App\Models\User;
use App\Support\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Recreation outlet shifts and the counter's day close.
 *
 * The outlet is a revenue point of sale: a cashier opens a shift with a float, every sale
 * rung up on that shift is linked to it, and at the end of the shift the drawer is counted
 * and the variance recorded. The same department-shift table the restaurant waiters use is
 * reused under department "recreation", so "My Shift" and the department roll-up keep
 * working — only the money it summarises is different.
 *
 * Cash is the only tender that sits in the drawer; card, bank and mobile money are reported
 * separately as collections so the cash count is never inflated by them.
 */
class RecreationShiftService
{
    public const DEPARTMENT = 'recreation';

    /** Ghana cedi denominations the close-out counter accepts. */
    public const DENOMINATIONS = [200, 100, 50, 20, 10, 5, 2, 1, 0.5, 0.2, 0.1];

    /** Tender modes the outlet accepts (gift vouchers and city ledger are handled elsewhere). */
    public const MODE_TYPES = ['cash', 'card', 'bank', 'wallet'];

    public static function active(?int $userId = null): ?DepartmentShiftSession
    {
        $userId ??= (int) auth()->id();

        return $userId
            ? DepartmentShiftSession::where('user_id', $userId)->where('department', self::DEPARTMENT)->where('status', 'open')->latest('opened_at')->first()
            : null;
    }

    public static function requireOpenForSelling(): void
    {
        if (setting('recreation_shift_required', '0') === '1' && auth()->check() && ! self::active()) {
            throw new RuntimeException('Open your recreation outlet shift before taking money at the counter.');
        }
    }

    public static function open(User $actor, float $float = 0, ?string $note = null): DepartmentShiftSession
    {
        $hotelId = Tenant::id();
        if (! $hotelId) {
            throw new RuntimeException('Select a hotel before opening the outlet shift.');
        }
        if (! is_finite($float) || $float < 0) {
            throw new RuntimeException('Enter the cash float you are starting with.');
        }

        return DB::transaction(function () use ($actor, $float, $note, $hotelId) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            if (DepartmentShiftSession::where('user_id', $actor->id)->where('department', self::DEPARTMENT)->where('status', 'open')->lockForUpdate()->exists()) {
                throw new RuntimeException('You already have an open recreation outlet shift.');
            }
            $session = DepartmentShiftSession::create([
                'hotel_id' => $hotelId,
                'user_id' => $actor->id,
                'department' => self::DEPARTMENT,
                'shift' => FrontDeskOpsService::currentShift(),
                'business_date' => bdate()->toDateString(),
                'opened_at' => now(),
                'status' => 'open',
                'opening_float' => round($float, 2),
                'opening_note' => trim((string) $note) ? mb_substr(trim($note), 0, 500) : null,
            ]);
            ActivityLogger::log('recreation', 'shift_open', 'Opened recreation outlet shift with float '.money($float), $session);

            return $session;
        });
    }

    /** Close the shift: count the drawer, snapshot the takings, record the variance. */
    public static function close(DepartmentShiftSession $session, User $actor, float $counted, ?string $note = null, array $denominations = []): DepartmentShiftSession
    {
        if (! is_finite($counted) || $counted < 0) {
            throw new RuntimeException('Enter the cash counted in the drawer.');
        }
        $allowed = array_map('floatval', self::DENOMINATIONS);
        $denominations = collect($denominations)
            ->filter(fn ($qty, $value) => in_array(round((float) $value, 2), $allowed, true) && (float) $qty > 0)
            ->mapWithKeys(fn ($qty, $value) => [(string) $value => (int) $qty])->all();
        if ($denominations) {
            $denomTotal = collect($denominations)->sum(fn ($qty, $value) => (float) $qty * (float) $value);
            if (abs($denomTotal - $counted) > 0.01) {
                throw new RuntimeException('The denomination count totals '.money($denomTotal).' but counted cash is '.money($counted).'. Check the notes and coins.');
            }
        }

        return DB::transaction(function () use ($session, $actor, $counted, $note, $denominations) {
            $locked = DepartmentShiftSession::whereKey($session->getKey())->lockForUpdate()->firstOrFail();
            if ((int) $locked->user_id !== (int) $actor->id && ! $actor->hasPermission('club.manage')) {
                throw new RuntimeException('Only the cashier who opened this shift, or a recreation manager, can close it.');
            }
            if ($locked->status !== 'open') {
                throw new RuntimeException('This outlet shift is already closed.');
            }
            $summary = self::summary($locked);
            $locked->forceFill([
                'status' => 'closed',
                'closed_at' => now(),
                'closing_note' => trim((string) $note) ? mb_substr(trim($note), 0, 1000) : null,
                'close_snapshot' => $summary,
                'expected_cash' => $summary['expected_cash'],
                'counted_cash' => round($counted, 2),
                'variance' => round($counted - $summary['expected_cash'], 2),
                'counted_denominations' => $denominations ?: null,
            ])->save();
            ActivityLogger::log('recreation', 'shift_close', 'Closed recreation outlet shift: expected '.money($summary['expected_cash'])
                .', counted '.money($counted).', variance '.money($locked->variance), $locked);

            return $locked;
        });
    }

    /** Every sale rung up on a shift (or, without a shift, on a business date). */
    protected static function saleQuery(?DepartmentShiftSession $shift = null, ?string $date = null)
    {
        $q = ClubTicketSale::with(['items', 'payments.paymentMode', 'user', 'passes']);

        if ($shift) {
            return $q->where('department_shift_session_id', $shift->id);
        }

        return $q->whereDate('business_date', $date ?? bdate()->toDateString());
    }

    /**
     * What the shift took, and what should be in the drawer.
     *
     * @return array{sales:int, tickets:int, items:int, void_sales:int, void_total:float, net:float, tax:float, total:float,
     *               room_total:float, change:float, opening_float:float, expected_cash:float, cash_in:float, tenders:array, by_category:array}
     */
    public static function summary(DepartmentShiftSession $shift): array
    {
        if ($shift->status === 'closed' && is_array($shift->close_snapshot)) {
            return $shift->close_snapshot;
        }

        $sales = self::saleQuery($shift)->get();
        $paid = $sales->where('status', 'paid');

        $tenders = [];
        $cashIn = 0.0;
        foreach ($paid as $sale) {
            foreach ($sale->payments as $payment) {
                $name = $payment->paymentMode?->name ?? 'Other';
                $isCash = ($payment->paymentMode?->type ?? '') === 'cash';
                $tenders[$name] ??= ['amount' => 0.0, 'count' => 0, 'is_cash' => $isCash];
                $tenders[$name]['amount'] += (float) $payment->amount;
                $tenders[$name]['count'] += 1;
                if ($isCash) {
                    $cashIn += (float) $payment->amount;
                }
            }
        }
        ksort($tenders);
        foreach ($tenders as $name => $row) {
            $tenders[$name]['amount'] = round($row['amount'], 2);
        }

        $change = round((float) $paid->sum('change_due'), 2);
        $openingFloat = round((float) ($shift->opening_float ?? 0), 2);
        $byCategory = [];
        foreach ($paid as $sale) {
            foreach ($sale->items as $item) {
                $category = $item->type?->category ?? 'other';
                $byCategory[$category] ??= ['qty' => 0, 'net' => 0.0, 'tax' => 0.0];
                $byCategory[$category]['qty'] += (int) $item->qty;
                $byCategory[$category]['net'] += (float) $item->line_total;
                $byCategory[$category]['tax'] += (float) $item->tax;
            }
        }
        foreach ($byCategory as $category => $row) {
            $byCategory[$category] = array_merge($row, ['net' => round($row['net'], 2), 'tax' => round($row['tax'], 2)]);
        }
        ksort($byCategory);

        return [
            'sales' => $paid->count(),
            'tickets' => (int) $paid->sum(fn ($sale) => $sale->passes->sum(fn ($p) => (int) $p->adults + (int) $p->children)),
            'items' => (int) $paid->sum(fn ($sale) => $sale->items->sum('qty')),
            'void_sales' => $sales->where('status', 'void')->count(),
            'void_total' => round((float) $sales->where('status', 'void')->sum('total'), 2),
            'net' => round((float) $paid->sum('subtotal'), 2),
            'tax' => round((float) $paid->sum('tax'), 2),
            'total' => round((float) $paid->sum('total'), 2),
            'room_total' => round((float) $paid->whereNotNull('reservation_room_id')->sum('total'), 2),
            'change' => $change,
            'opening_float' => $openingFloat,
            'cash_in' => round($cashIn, 2),
            'expected_cash' => round($openingFloat + $cashIn - $change, 2),
            'tenders' => $tenders,
            'by_category' => $byCategory,
        ];
    }

    /** The day report: everything the outlet did on a business date, across every shift. */
    public static function dayReport(?string $date = null): array
    {
        $date ??= bdate()->toDateString();
        $sales = ClubTicketSale::with(['items.type', 'payments.paymentMode', 'user', 'stay.room', 'passes'])
            ->whereDate('business_date', $date)->orderBy('id')->get();
        $paid = $sales->where('status', 'paid');

        $tenders = [];
        $cash = 0.0;
        foreach ($paid as $sale) {
            foreach ($sale->payments as $payment) {
                $name = $payment->paymentMode?->name ?? 'Other';
                $isCash = ($payment->paymentMode?->type ?? '') === 'cash';
                $tenders[$name] ??= ['amount' => 0.0, 'count' => 0, 'is_cash' => $isCash];
                $tenders[$name]['amount'] += (float) $payment->amount;
                $tenders[$name]['count'] += 1;
                if ($isCash) {
                    $cash += (float) $payment->amount;
                }
            }
        }
        ksort($tenders);
        foreach ($tenders as $name => $row) {
            $tenders[$name]['amount'] = round($row['amount'], 2);
        }

        $byType = [];
        $byCategory = [];
        foreach ($paid as $sale) {
            foreach ($sale->items as $item) {
                $typeKey = $item->description;
                $byType[$typeKey] ??= ['qty' => 0, 'net' => 0.0, 'tax' => 0.0];
                $byType[$typeKey]['qty'] += (int) $item->qty;
                $byType[$typeKey]['net'] += (float) $item->line_total;
                $byType[$typeKey]['tax'] += (float) $item->tax;

                $category = $item->type?->category ?? 'other';
                $byCategory[$category] ??= ['qty' => 0, 'net' => 0.0, 'tax' => 0.0];
                $byCategory[$category]['qty'] += (int) $item->qty;
                $byCategory[$category]['net'] += (float) $item->line_total;
                $byCategory[$category]['tax'] += (float) $item->tax;
            }
        }
        foreach ([&$byType, &$byCategory] as &$group) {
            foreach ($group as $key => $row) {
                $group[$key] = array_merge($row, ['net' => round($row['net'], 2), 'tax' => round($row['tax'], 2), 'total' => round($row['net'] + $row['tax'], 2)]);
            }
            ksort($group);
        }
        unset($group);

        $cashier = $paid->groupBy(fn ($sale) => $sale->user?->name ?? 'Counter')
            ->map(fn ($rows, $name) => ['name' => $name, 'sales' => $rows->count(), 'total' => round((float) $rows->sum('total'), 2),
                'change' => round((float) $rows->sum('change_due'), 2)])
            ->values()->all();

        $shifts = DepartmentShiftSession::with('user')->where('department', self::DEPARTMENT)
            ->whereDate('business_date', $date)->orderBy('opened_at')->get()
            ->map(fn ($s) => ['session' => $s, 'summary' => $s->status === 'open' ? null : self::summary($s)]);

        $tax = round((float) $paid->sum('tax'), 2);

        // Admissions: what the tickets the counter sold actually let through the gate.
        $passes = ClubDayPass::whereDate('date', $date)->get();
        $livePasses = $passes->whereNull('cancelled_at');
        $admittedPasses = $livePasses->whereNotNull('redeemed_at');

        return [
            'date' => $date,
            'sales' => $paid->count(),
            'void_sales' => $sales->where('status', 'void')->count(),
            'void_total' => round((float) $sales->where('status', 'void')->sum('total'), 2),
            'tickets' => (int) $paid->sum(fn ($sale) => $sale->passes->sum(fn ($p) => (int) $p->adults + (int) $p->children)),
            'items' => (int) $paid->sum(fn ($sale) => $sale->items->sum('qty')),
            'net' => round((float) $paid->sum('subtotal'), 2),
            'tax' => $tax,
            'total' => round((float) $paid->sum('total'), 2),
            'room_total' => round((float) $paid->whereNotNull('reservation_room_id')->sum('total'), 2),
            'change' => round((float) $paid->sum('change_due'), 2),
            'cash_in' => round($cash, 2),
            'tenders' => $tenders,
            'by_type' => $byType,
            'by_category' => $byCategory,
            'cashiers' => $cashier,
            'shifts' => $shifts,
            'admissions' => [
                'issued' => $livePasses->count(),
                'admitted' => $admittedPasses->count(),
                'waiting' => $livePasses->whereNull('redeemed_at')->count(),
                'cancelled' => $passes->whereNotNull('cancelled_at')->count(),
                'pax_issued' => (int) $livePasses->sum(fn ($p) => (int) $p->adults + (int) $p->children),
                'pax_admitted' => (int) $admittedPasses->sum(fn ($p) => (int) ($p->redeemed_pax ?? ((int) $p->adults + (int) $p->children))),
            ],
            'components' => TaxService::componentRates('club'),
            'rate' => (float) TaxService::rateFor('club', 0),
            'sales_list' => $sales,
        ];
    }

    /** Cash taken at the outlet for a business date, split by mode (included in the cashier drawer). */
    public static function cashierTakings(int $userId, Carbon $from, Carbon $to): array
    {
        return ClubTicketPayment::query()
            ->where('received_by', $userId)
            ->whereBetween('received_at', [$from, $to])
            ->whereHas('sale', fn ($q) => $q->where('status', 'paid'))
            ->get(['payment_mode_id', 'amount'])
            ->groupBy('payment_mode_id')
            ->map(fn ($rows) => round((float) $rows->sum('amount'), 2))->all();
    }

    /** Open cash, card, bank and mobile-money modes the outlet may take. */
    public static function modes()
    {
        return PaymentMode::where('is_active', true)->whereIn('type', self::MODE_TYPES)->orderBy('id')->get();
    }
}
