<?php

// Render Room View for each active front-desk demo role and confirm folio,
// reservation and housekeeping details/actions follow the user's permissions.
// Run with: deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/frontdesk_rooms_permission_render_check.php';"

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
        $canViewHousekeeping = collect(['housekeeping.view', 'housekeeping.manage', 'housekeeping.attendant'])
            ->contains(fn (string $permission) => $user->hasPermission($permission));
        $request = Request::create('/front-desk/rooms', 'GET');
        $response = $kernel->handle($request);
        $html = (string) $response->getContent();
        $pageStart = strpos($html, '<div class="room-view-page">');
        $page = $pageStart === false ? '' : substr($html, $pageStart);
        $checks = [
            'room inventory returns HTTP 200' => $response->getStatusCode() === 200,
            'room tiles render in the real Front Office page' => str_contains($page, 'data-room-card'),
            'folio actions follow folio.view' => $canViewFolio || ! str_contains($page, 'Folio / Post charge'),
            'housekeeping summary follows housekeeping view/manage/attendant' => str_contains($page, 'aria-label="Housekeeping summary"') === $canViewHousekeeping,
            'housekeeping filter follows housekeeping view/manage/attendant' => str_contains($page, 'id="room-hk-filter"') === $canViewHousekeeping,
            'housekeeping actions follow housekeeping.manage' => $user->hasPermission('housekeeping.manage') || (! str_contains($page, 'Mark clean') && ! str_contains($page, 'Mark dirty') && ! str_contains($page, 'Set out of order')),
            'reservation links follow reservations.view' => $user->hasPermission('reservations.view') || ! str_contains($page, 'View reservation'),
            'check-in links follow frontdesk.checkin' => $user->hasPermission('frontdesk.checkin') || (! str_contains($page, 'Walk-in check-in') && ! str_contains($page, '>Check-in ')),
            'room-move links follow frontdesk.roommove' => $user->hasPermission('frontdesk.roommove') || ! str_contains($page, 'Room move / Amend'),
            'check-out links follow frontdesk.checkout' => $user->hasPermission('frontdesk.checkout') || ! str_contains($page, '>Check-out</a>'),
        ];

        foreach ($checks as $label => $passed) {
            ++$checksRun;
            echo ($passed ? 'PASS ' : 'FAIL ').$user->role->name.": {$label}".PHP_EOL;
            if (! $passed) {
                throw new RuntimeException("Room View role-matrix check failed for {$user->role->name}: {$label}");
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

echo 'Checked '.$users->count().' active Front Desk roles with '.$checksRun.' assertions.'.PHP_EOL;
