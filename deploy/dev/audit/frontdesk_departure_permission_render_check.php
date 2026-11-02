<?php

// Render the actual Front Desk Departures and In-house routes for every
// active front-desk demo user. Folio values and folio links must follow
// folio.view; check-out controls must follow frontdesk.checkout.
// Run with: deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/frontdesk_departure_permission_render_check.php';"

use App\Models\Hotel;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

Tenant::set(Hotel::findOrFail(1));
$guard = Auth::guard();
$originalUser = $guard->user();
$kernel = app(Illuminate\Contracts\Http\Kernel::class);
$users = User::query()->where('hotel_id', 1)->where('is_active', true)->get()
    ->filter(fn (User $user) => $user->hasPermission('frontdesk.view'))->values();
if ($users->isEmpty()) {
    throw new RuntimeException('No active demo users have frontdesk.view.');
}

$checksRun = 0;
try {
    foreach ($users as $user) {
        $guard->setUser($user);
        $canViewFolio = $user->hasPermission('folio.view');
        $canCheckout = $user->hasPermission('frontdesk.checkout');
        foreach ([
            '/front-desk/departures' => 'departure-manifest-title',
            '/front-desk/in-house' => 'stayTable',
        ] as $path => $marker) {
            $request = Request::create($path, 'GET');
            $response = $kernel->handle($request);
            $html = (string) $response->getContent();
            $checks = ['route returns HTTP 200' => $response->getStatusCode() === 200];

            if ($path === '/front-desk/departures') {
                $start = strpos($html, 'id="departure-manifest-title"');
                $end = strpos($html, 'departure-footnote', $start === false ? 0 : $start);
                $manifest = $start === false ? '' : substr($html, $start, $end === false ? null : $end - $start);
                $checks['arrival/departure page is the actual due-out manifest'] = str_contains($html, $marker);
                $checks['folio column follows folio.view'] = str_contains($manifest, '<th scope="col">Folio</th>') === $canViewFolio;
                $checks['folio links follow folio.view'] = (str_contains($manifest, 'class="departure-folio"') || str_contains($manifest, 'No folio linked')) === $canViewFolio;
                $checks['check-out control follows frontdesk.checkout'] = str_contains($manifest, 'aria-label="Check out ') === $canCheckout;
                $checks['balance metric follows folio.view'] = str_contains($html, 'departure-stat-label">Outstanding folio balance</span>') === $canViewFolio;
            } else {
                $start = strpos($html, '<table class="inhouse-table" id="stayTable">');
                $end = strpos($html, '</table>', $start === false ? 0 : $start);
                $table = $start === false ? '' : substr($html, $start, $end === false ? null : $end + 8 - $start);
                $checks['in-house roster is rendered'] = str_contains($html, 'id="inhouse-manifest-title"') && str_contains($html, $marker);
                $checks['in-house folio column follows folio.view'] = str_contains($table, '<th scope="col">Folio</th>') === $canViewFolio;
                $checks['in-house folio links follow folio.view'] = $canViewFolio || (! str_contains($table, 'inhouse-folio') && ! str_contains($table, 'folios.show'));
                $checks['in-house check-out control follows frontdesk.checkout'] = str_contains($table, 'aria-label="Check out ') === $canCheckout;
                $checks['in-house room-move control follows frontdesk.roommove'] = str_contains($table, 'aria-label="Move ') === $user->hasPermission('frontdesk.roommove');
            }

            foreach ($checks as $label => $passed) {
                ++$checksRun;
                echo ($passed ? 'PASS ' : 'FAIL ').$user->role->name." ({$path}): {$label}".PHP_EOL;
                if (! $passed) {
                    throw new RuntimeException("Front Desk role-matrix check failed for {$user->role->name} ({$path}): {$label}");
                }
            }
            $kernel->terminate($request, $response);
        }
    }
} finally {
    if ($originalUser) {
        $guard->setUser($originalUser);
    } else {
        $guard->forgetUser();
    }
}

echo 'Checked '.$users->count().' active Front Desk roles with '.$checksRun.' assertions.'.PHP_EOL;
