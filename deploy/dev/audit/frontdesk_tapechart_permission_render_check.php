<?php

// Render the actual Tape Chart for each active user who can open Front Office.
// Run with: deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/frontdesk_tapechart_permission_render_check.php';"

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
        $canMove = $user->hasPermission('frontdesk.roommove');
        $canEdit = $user->hasPermission('reservations.edit');
        $canViewHousekeeping = collect(['housekeeping.view', 'housekeeping.manage', 'housekeeping.attendant'])
            ->contains(fn (string $permission) => $user->hasPermission($permission));
        $request = Request::create('/front-desk/tape-chart', 'GET');
        $response = $kernel->handle($request);
        $html = (string) $response->getContent();
        $start = strpos($html, '<div class="tape-chart-page">');
        $page = $start === false ? '' : substr($html, $start);
        $checks = [
            'Tape Chart returns HTTP 200' => $response->getStatusCode() === 200,
            'actual room availability table renders' => str_contains($page, 'class="tape-chart-table"'),
            'date window controls render' => str_contains($page, 'id="tape-from"') && str_contains($page, 'id="tape-days"'),
            'room move/assignment controls follow editor or move permission' => str_contains($page, 'Move / assign:') === ($canMove || $canEdit),
            'housekeeping detail follows housekeeping role permissions' => str_contains($page, 'title="Housekeeping: ') === $canViewHousekeeping,
            'reservation links are omitted when user lacks reservation view' => $user->hasPermission('reservations.view') || ! preg_match('~href="/reservations/[0-9]+"~', $page),
            'in-house bars are draggable only with front-desk move permission' => $canMove || ! preg_match('/data-inhouse="1"/', $page),
        ];

        foreach ($checks as $label => $passed) {
            ++$checksRun;
            echo ($passed ? 'PASS ' : 'FAIL ').$user->role->name.": {$label}".PHP_EOL;
            if (! $passed) {
                throw new RuntimeException("Tape Chart permission render check failed for {$user->role->name}: {$label}");
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
