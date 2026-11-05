<?php

// Render the actual Dashboard route for every active demo user granted
// dashboard.view, checking finance/reservation gates against each role.
// Run with: deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/main_dashboard_role_matrix_check.php';"

use App\Models\Hotel;
use App\Models\NightAudit;
use App\Models\ReservationRoom;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Tenant::set(Hotel::findOrFail(1));
$guard = Auth::guard();
$originalUser = $guard->user();
$kernel = app(Illuminate\Contracts\Http\Kernel::class);
$users = User::query()->where('hotel_id', 1)->where('is_active', true)->get()
    ->filter(fn (User $user) => $user->hasPermission('dashboard.view'))->values();
$auditBarsExpected = min(14, NightAudit::count());
$arrivalCounts = ReservationRoom::whereDate('arrival_date', bdate()->toDateString())
    ->whereIn('status', ['reserved', 'checked_in', 'checked_out', 'no_show'])
    ->select('status', DB::raw('COUNT(*) as total'))->groupBy('status')->pluck('total', 'status');
$arrivalsCheckedIn = (int) ($arrivalCounts['checked_in'] ?? 0) + (int) ($arrivalCounts['checked_out'] ?? 0);
$arrivalProgressHtml = $arrivalsCheckedIn.' checked in · '.(int) ($arrivalCounts['reserved'] ?? 0).' remaining';
$financialQueries = $reservationQueries = [];
DB::listen(function (QueryExecuted $query) use (&$financialQueries, &$reservationQueries): void {
    $sql = strtolower($query->sql);
    $bindings = array_map(fn ($binding) => strtolower((string) $binding), $query->bindings);
    if (str_contains($sql, 'folio_transactions') || (str_contains($sql, 'pos_orders') && (str_contains($sql, 'sum(') || in_array('payment', $bindings, true)))) {
        $financialQueries[] = $query->sql;
    }
    if (str_contains($sql, 'reservation_rooms')) {
        $reservationQueries[] = $query->sql;
    }
});

if ($users->isEmpty()) {
    throw new RuntimeException('No active demo users have dashboard.view.');
}

$checksRun = 0;
try {
    foreach ($users as $user) {
        $guard->setUser($user);
        $canSeeFinance = $user->hasPermission('folio.view') || $user->hasPermission('reports.view')
            || $user->hasPermission('revenue.view') || $user->hasPermission('income_audit.view');
        $canSeeReservations = $user->hasPermission('reservations.view');
        $canSeeFrontDesk = $user->hasPermission('frontdesk.view');
        $canSeePos = $user->hasPermission('pos.view');
        $canSeeDepartmentSnapshots = collect(['budget.view', 'calls.view', 'keys.view', 'vouchers.view', 'security.view', 'utilities.view', 'compliance.view', 'banquet.view'])
            ->contains(fn (string $permission) => $user->hasPermission($permission));
        $financeQueryCountBefore = count($financialQueries);
        $reservationQueryCountBefore = count($reservationQueries);

        $request = Request::create('/Dashboard', 'GET');
        $response = $kernel->handle($request);
        $html = (string) $response->getContent();
        $revenueBars = substr_count($html, 'class="b dash-revenue-bar"');
        $occupancyBars = substr_count($html, 'class="b dash-occupancy-bar"');
        $checks = [
            'dashboard returns HTTP 200' => $response->getStatusCode() === 200,
            'revenue KPI matches finance permission' => str_contains($html, 'Revenue today') === $canSeeFinance,
            'revenue samples match finance permission' => $revenueBars === ($canSeeFinance ? $auditBarsExpected : 0),
            'revenue mix chart matches finance permission' => str_contains($html, 'dashRevenueMixTitle') === $canSeeFinance,
            'reservation mix chart matches reservation permission' => str_contains($html, 'dashSourceMixTitle') === $canSeeReservations,
            'POS status chart matches POS permission' => str_contains($html, 'dashPosMixTitle') === $canSeePos,
            'occupancy samples remain visible' => $occupancyBars === $auditBarsExpected,
            'new-bookings aggregate matches reservation permission' => str_contains($html, 'New bookings today') === $canSeeReservations,
            'arrival progress matches front-desk permission' => str_contains($html, $arrivalProgressHtml) === $canSeeFrontDesk,
            'room status explains unavailable rooms and overlapping dirty counts' => str_contains($html, 'unavailable because of room restrictions, outages or leases') && str_contains($html, 'Dirty is a housekeeping count; these rooms may also be occupied or vacant.'),
            'action centre explains permission-filtered empty state' => str_contains($html, 'Only actions from areas you can access are shown. A blank list means none are currently flagged.'),
            'department snapshot section matches module permissions' => str_contains($html, 'id="dashModulesHeading"') === $canSeeDepartmentSnapshots,
            'restricted finance queries are skipped' => $canSeeFinance || count($financialQueries) === $financeQueryCountBefore,
            'guest-flow aggregates are skipped without front-desk or reservation access' => $canSeeFrontDesk || $canSeeReservations || count($reservationQueries) === $reservationQueryCountBefore,
        ];

        foreach ($checks as $label => $passed) {
            ++$checksRun;
            echo ($passed ? 'PASS ' : 'FAIL ').$user->role->name.": {$label}".PHP_EOL;
            if (! $passed) {
                throw new RuntimeException("Dashboard role-matrix check failed for {$user->role->name}: {$label}");
            }
        }
        $kernel->terminate($request, $response);
    }
} finally {
    if ($originalUser) {
        $guard->setUser($originalUser);
    } else {
        $guard->forgetUser();
    }
}

echo 'Checked '.$users->count().' active roles with '.$checksRun.' assertions.'.PHP_EOL;
