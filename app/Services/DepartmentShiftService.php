<?php

namespace App\Services;

use App\Models\DepartmentShiftSession;
use App\Models\PaymentMode;
use App\Models\PosOrder;
use App\Models\PosPayment;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Department-specific staff shifts that complement the cashier drawer and front-desk hand-over workflows. */
class DepartmentShiftService
{
    public const FOOD_BEVERAGE = 'food_beverage';

    /** Only the waiter-level service role is locked to a personal shift; cashier/manager and admin roles keep their existing access. */
    public static function requiresPersonalShift(?User $actor): bool
    {
        return $actor !== null && ! $actor->is_super_admin && ! $actor->is_group_admin
            && $actor->hasPermission('service_shift.waiter_required') && $actor->hasPermission('pos.order')
            && ! $actor->hasPermission('pos.settle') && ! $actor->hasPermission('pos.shift_view_all');
    }

    public static function activeForUser(?int $userId = null, string $department = self::FOOD_BEVERAGE): ?DepartmentShiftSession
    {
        $userId ??= (int) auth()->id();

        return $userId ? DepartmentShiftSession::where('user_id', $userId)->where('department', $department)->where('status', 'open')->latest('opened_at')->first() : null;
    }

    public static function startFoodService(User $actor, ?string $note = null): DepartmentShiftSession
    {
        self::requirePermission($actor, 'pos.order');
        $hotelId = Tenant::id();
        if (! $hotelId) {
            throw new RuntimeException('Select a hotel before starting a service shift.');
        }

        return DB::transaction(function () use ($actor, $note, $hotelId) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            if (DepartmentShiftSession::where('user_id', $actor->id)->where('department', self::FOOD_BEVERAGE)->where('status', 'open')->lockForUpdate()->exists()) {
                throw new RuntimeException('You already have an open Food & Beverage service shift.');
            }
            $session = DepartmentShiftSession::create([
                'hotel_id' => $hotelId,
                'user_id' => $actor->id,
                'department' => self::FOOD_BEVERAGE,
                'shift' => FrontDeskOpsService::currentShift(),
                'business_date' => bdate()->toDateString(),
                'opened_at' => now(),
                'status' => 'open',
                'opening_note' => trim((string) $note) ? mb_substr(trim($note), 0, 500) : null,
            ]);
            ActivityLogger::log('department_shift', 'open', 'Opened Food & Beverage service shift ('.$session->shift.')', $session);

            return $session;
        });
    }

    public static function close(DepartmentShiftSession $session, User $actor, ?string $note = null): DepartmentShiftSession
    {
        self::requirePermission($actor, 'pos.order');

        return DB::transaction(function () use ($session, $actor, $note) {
            $locked = DepartmentShiftSession::whereKey($session->getKey())->lockForUpdate()->firstOrFail();
            if ((int) $locked->user_id !== (int) $actor->id) {
                throw new RuntimeException('Only the staff member who opened this service shift can close it.');
            }
            if ($locked->status !== 'open') {
                throw new RuntimeException('This service shift is already closed.');
            }
            $locked->closed_at = now();
            $locked->forceFill([
                'status' => 'closed',
                'closing_note' => trim((string) $note) ? mb_substr(trim($note), 0, 1000) : null,
                'close_snapshot' => self::summary($locked),
            ])->save();
            ActivityLogger::log('department_shift', 'close', 'Closed Food & Beverage service shift: '.($locked->close_snapshot['orders'] ?? 0).' order(s)', $locked);

            return $locked;
        });
    }

    /** Snapshot metrics are derived from orders linked while this employee's own service shift was open. */
    public static function summary(DepartmentShiftSession $session): array
    {
        if ($session->status === 'closed' && is_array($session->close_snapshot)) {
            $snapshot = $session->close_snapshot;
            // Close-outs saved before the payment breakdown existed still print their method totals:
            // a closed shift's payments are settled history, so reading them once is safe.
            return array_key_exists('payments', $snapshot) ? $snapshot : array_merge($snapshot, self::paymentsByMode($session));
        }

        $stats = $session->posOrders()->selectRaw('COUNT(*) as orders_count')
            ->selectRaw("COALESCE(SUM(CASE WHEN status != 'void' THEN covers ELSE 0 END), 0) as covers_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN status != 'void' THEN total ELSE 0 END), 0) as order_value")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'settled' AND settlement = 'payment' THEN total ELSE 0 END), 0) as settled_sales")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'settled' AND settlement = 'room_post' THEN total ELSE 0 END), 0) as room_posts")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'void' THEN 1 ELSE 0 END), 0) as void_orders")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'settled' THEN 1 ELSE 0 END), 0) as settled_orders")
            ->selectRaw("COALESCE(SUM(CASE WHEN status IN ('open', 'billed') THEN 1 ELSE 0 END), 0) as open_orders")->first();

        return array_merge([
            'orders' => (int) $stats->orders_count,
            'covers' => (int) $stats->covers_count,
            'order_value' => round((float) $stats->order_value, 2),
            'settled_sales' => round((float) $stats->settled_sales, 2),
            'room_posts' => round((float) $stats->room_posts, 2),
            'void_orders' => (int) $stats->void_orders,
            'settled_orders' => (int) $stats->settled_orders,
            'open_orders' => (int) $stats->open_orders,
        ], self::paymentsByMode($session));
    }

    /**
     * What was taken on this shift, per payment method: payments in, refunds out, net.
     * The same orders' cashier-shift reconciliation stays with the cashier drawer — this is the
     * service shift's own collection sheet, so a waiter can hand it over with the bill folder.
     *
     * @return array{payments: array<int, array<string, mixed>>, payments_in: float, payments_out: float, payments_total: float}
     */
    protected static function paymentsByMode(DepartmentShiftSession $session): array
    {
        $modes = PaymentMode::withoutGlobalScopes()->where('hotel_id', $session->hotel_id)->get()->keyBy('id');
        $rows = [];
        $add = function (?int $modeId, float $in, float $out) use (&$rows, $modes) {
            $mode = $modeId !== null ? ($modes[$modeId] ?? null) : null;
            $key = $mode?->name ?? ($modeId === null ? 'Unspecified method' : 'Other');
            $rows[$key] ??= ['name' => $key, 'is_cash' => $mode?->type === 'cash', 'collected' => 0.0, 'refunded' => 0.0, 'net' => 0.0, 'count' => 0];
            $rows[$key]['collected'] += $in;
            $rows[$key]['refunded'] += $out;
            $rows[$key]['net'] += $in - $out;
            $rows[$key]['count']++;
        };

        $payments = PosPayment::whereIn('pos_order_id', $session->posOrders()->select('id'))
            ->get(['pos_order_id', 'payment_mode_id', 'kind', 'amount']);
        $payments->each(fn ($p) => $add($p->payment_mode_id === null ? null : (int) $p->payment_mode_id,
            $p->kind === 'payment' ? (float) $p->amount : 0,
            $p->kind === 'refund' ? (float) $p->amount : 0));

        // Orders settled before the payment ledger existed have no rows; count them once through the legacy columns.
        $ledgerOrderIds = $payments->pluck('pos_order_id')->unique();
        PosOrder::where('department_shift_session_id', $session->id)->where('status', 'settled')->where('settlement', 'payment')
            ->when($ledgerOrderIds->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $ledgerOrderIds))
            ->get(['id', 'total', 'payment_mode_id'])
            ->each(fn ($o) => $add($o->payment_mode_id === null ? null : (int) $o->payment_mode_id, (float) $o->total, 0));

        ksort($rows);
        $rows = array_values(array_map(fn ($row) => [
            'name' => $row['name'], 'is_cash' => $row['is_cash'], 'count' => $row['count'],
            'collected' => round($row['collected'], 2), 'refunded' => round($row['refunded'], 2), 'net' => round($row['net'], 2),
        ], $rows));

        return [
            'payments' => $rows,
            'payments_in' => round(collect($rows)->sum('collected'), 2),
            'payments_out' => round(collect($rows)->sum('refunded'), 2),
            'payments_total' => round(collect($rows)->sum('net'), 2),
        ];
    }

    /** Current user's sessions plus a permission-filtered department roster/day close view. */
    public static function workspace(User $viewer, ?string $date = null): array
    {
        $date = Carbon::parse($date ?? bdate()->toDateString())->toDateString();
        $canViewTeam = $viewer->hasPermission('pos.shift_view_all');
        $ownSessions = DepartmentShiftSession::with('user')->where('department', self::FOOD_BEVERAGE)
            ->where('user_id', $viewer->id)->latest('opened_at')->limit(30)->get();
        $teamSessions = DepartmentShiftSession::with('user')->where('department', self::FOOD_BEVERAGE)
            ->whereDate('business_date', $date)->when(! $canViewTeam, fn ($q) => $q->where('user_id', $viewer->id))
            ->orderBy('shift')->orderBy('opened_at')->get();
        $teamSessions->each(fn (DepartmentShiftSession $row) => $row->setAttribute('report_summary', self::summary($row)));
        $daySummary = $teamSessions->groupBy('shift')->map(fn ($sessions) => [
            'people' => $sessions->count(),
            'active' => $sessions->where('status', 'open')->count(),
            'orders' => $sessions->sum(fn ($s) => $s->report_summary['orders']),
            'covers' => $sessions->sum(fn ($s) => $s->report_summary['covers']),
            'order_value' => round($sessions->sum(fn ($s) => $s->report_summary['order_value']), 2),
            'settled_sales' => round($sessions->sum(fn ($s) => $s->report_summary['settled_sales']), 2),
            'payments_total' => round($sessions->sum(fn ($s) => (float) ($s->report_summary['payments_total'] ?? 0)), 2),
            'open_orders' => $sessions->sum(fn ($s) => $s->report_summary['open_orders']),
        ]);

        return [
            'currentServiceShift' => self::activeForUser((int) $viewer->id),
            'ownServiceSessions' => $ownSessions,
            'teamServiceSessions' => $teamSessions,
            'serviceDaySummary' => $daySummary,
            'serviceShiftDate' => $date,
            'canViewServiceTeam' => $canViewTeam,
        ];
    }

    protected static function requirePermission(User $actor, string $permission): void
    {
        if (! $actor->hasPermission($permission)) {
            throw new RuntimeException('Your role does not have permission to manage a Food & Beverage service shift.');
        }
    }
}
