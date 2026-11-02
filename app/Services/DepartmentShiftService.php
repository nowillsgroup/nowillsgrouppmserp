<?php

namespace App\Services;

use App\Models\DepartmentShiftSession;
use App\Models\PosOrder;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Department-specific staff shifts that complement the cashier drawer and front-desk hand-over workflows. */
class DepartmentShiftService
{
    public const FOOD_BEVERAGE = 'food_beverage';

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
            return $session->close_snapshot;
        }

        $stats = $session->posOrders()->selectRaw('COUNT(*) as orders_count')
            ->selectRaw("COALESCE(SUM(CASE WHEN status != 'void' THEN covers ELSE 0 END), 0) as covers_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN status != 'void' THEN total ELSE 0 END), 0) as order_value")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'settled' AND settlement = 'payment' THEN total ELSE 0 END), 0) as settled_sales")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'settled' AND settlement = 'room_post' THEN total ELSE 0 END), 0) as room_posts")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'void' THEN 1 ELSE 0 END), 0) as void_orders")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'settled' THEN 1 ELSE 0 END), 0) as settled_orders")
            ->selectRaw("COALESCE(SUM(CASE WHEN status IN ('open', 'billed') THEN 1 ELSE 0 END), 0) as open_orders")->first();

        return [
            'orders' => (int) $stats->orders_count,
            'covers' => (int) $stats->covers_count,
            'order_value' => round((float) $stats->order_value, 2),
            'settled_sales' => round((float) $stats->settled_sales, 2),
            'room_posts' => round((float) $stats->room_posts, 2),
            'void_orders' => (int) $stats->void_orders,
            'settled_orders' => (int) $stats->settled_orders,
            'open_orders' => (int) $stats->open_orders,
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
