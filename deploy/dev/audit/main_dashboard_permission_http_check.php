<?php

// Exercise the real dashboard route as representative roles that have
// dashboard.view but no reservation or finance-view permission.
// Run with: deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/main_dashboard_permission_http_check.php';"

use App\Models\Hotel;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Tenant::set(Hotel::findOrFail(1));
$guard = Auth::guard();
$originalUser = $guard->user();
$kernel = app(Illuminate\Contracts\Http\Kernel::class);
$roles = ['Housekeeping Supervisor', 'Restaurant Cashier', 'Security Officer'];
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

try {
    foreach ($roles as $roleName) {
        $user = User::query()->where('hotel_id', 1)->whereHas('role', fn ($query) => $query->where('name', $roleName))->firstOrFail();
        $canSeePos = $user->hasPermission('pos.view');
        $canSeeReservationOps = $user->hasPermission('frontdesk.view') || $user->hasPermission('reservations.view');
        if (! $user->hasPermission('dashboard.view') || $user->hasPermission('folio.view') || $user->hasPermission('reports.view') || $user->hasPermission('revenue.view') || $user->hasPermission('income_audit.view') || $user->hasPermission('reservations.view') || $user->hasPermission('frontdesk.view')) {
            throw new RuntimeException("{$roleName} no longer represents the restricted-dashboard permission set.");
        }

        $guard->setUser($user);
        $financialQueryCountBefore = count($financialQueries);
        $reservationQueryCountBefore = count($reservationQueries);
        $request = Request::create('/Dashboard', 'GET');
        $response = $kernel->handle($request);
        $html = $response->getContent();
        $checks = [
            'dashboard route returns HTTP 200' => $response->getStatusCode() === 200,
            'finance snapshot is absent' => ! str_contains($html, 'Revenue snapshot'),
            'historical revenue chart is absent' => ! str_contains($html, 'dash-revenue-bar'),
            'revenue mix chart is absent' => ! str_contains($html, 'dashRevenueMixTitle'),
            'reservation mix chart is absent' => ! str_contains($html, 'dashSourceMixTitle'),
            'POS status chart matches POS permission' => str_contains($html, 'dashPosMixTitle') === $canSeePos,
            'occupancy trend remains visible' => str_contains($html, 'dash-occupancy-bar'),
            'new-bookings aggregate is absent' => ! str_contains($html, 'New bookings today'),
            'folio and settled-POS finance queries are skipped' => count($financialQueries) === $financialQueryCountBefore,
            'guest-flow queries are skipped without front-desk or reservation access' => $canSeeReservationOps || count($reservationQueries) === $reservationQueryCountBefore,
        ];

        foreach ($checks as $label => $passed) {
            echo ($passed ? 'PASS ' : 'FAIL ')."{$roleName}: {$label}".PHP_EOL;
            if (! $passed) {
                throw new RuntimeException("Restricted dashboard permission check failed for {$roleName}: {$label}");
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
