<?php

// Render the real in-house room-move page for every active demo role with
// frontdesk.roommove. The GET is wrapped in a transaction and rolled back.
// Run with: deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/frontdesk_move_permission_render_check.php';"

use App\Models\Hotel;
use App\Models\ReservationRoom;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Tenant::set(Hotel::findOrFail(1));
$stay = ReservationRoom::query()->where('status', 'checked_in')->orderBy('id')->first();
if (! $stay) {
    throw new RuntimeException('No checked-in demo stay is available for the room-move render check.');
}
$users = User::query()->where('hotel_id', 1)->where('is_active', true)->get()
    ->filter(fn (User $user) => $user->hasPermission('frontdesk.roommove'))->values();
if ($users->isEmpty()) {
    throw new RuntimeException('No active demo users have frontdesk.roommove.');
}
$guard = Auth::guard();
$originalUser = $guard->user();
$kernel = app(Illuminate\Contracts\Http\Kernel::class);
$checksRun = 0;

try {
    foreach ($users as $user) {
        $guard->setUser($user);
        DB::beginTransaction();
        try {
            $request = Request::create('/front-desk/stay/'.$stay->id.'/move', 'GET');
            $response = $kernel->handle($request);
            $html = (string) $response->getContent();
            $pageStart = strpos($html, '<div class="roommove-page">');
            $page = $pageStart === false ? '' : substr($html, $pageStart);
            $folio = $stay->folio;
            $canViewReservation = $user->hasPermission('reservations.view');
            $redirectMethod = new ReflectionMethod(\App\Http\Controllers\FrontDeskController::class, 'roomMoveSuccessRedirect');
            $successRedirect = $redirectMethod->invoke(app(\App\Http\Controllers\FrontDeskController::class), $stay, 'Audit redirect');
            $expectedRedirect = $canViewReservation
                ? route('reservations.show', $stay->reservation_id)
                : ($user->hasPermission('frontdesk.view')
                    ? route('frontdesk.inhouse')
                    : \App\Http\Controllers\Auth\LoginController::home($user));
            $checks = [
                'actual room-move form returns HTTP 200' => $response->getStatusCode() === 200,
                'stay context, current dates and eligible-room summary render' => str_contains($page, 'roommove-hero')
                    && str_contains($page, 'Current stay dates') && str_contains($page, 'Eligible vacant rooms through'),
                'move and stay-amendment forms render' => str_contains($page, 'id="roomMoveForm"')
                    && str_contains($page, 'id="amendStayForm"'),
                'room selector or empty-availability notice renders' => str_contains($page, 'id="moveToRoom"')
                    || str_contains($page, 'No vacant rooms are currently eligible'),
                'move history renders' => str_contains($page, 'id="moveHistoryTitle"') && str_contains($page, 'roommove-history-table'),
                'reservation link follows reservations.view' => str_contains($html, 'href="'.route('reservations.show', $stay->reservation_id).'"') === $canViewReservation,
                'room-move success redirect respects accessible destinations' => $successRedirect->getTargetUrl() === $expectedRedirect,
            ];

            foreach ($checks as $label => $passed) {
                ++$checksRun;
                echo ($passed ? 'PASS ' : 'FAIL ').$user->role->name.": {$label}".PHP_EOL;
                if (! $passed) {
                    throw new RuntimeException("Room-move permission render check failed for {$user->role->name}: {$label}");
                }
            }
            $kernel->terminate($request, $response);
        } finally {
            DB::rollBack();
        }
    }
} finally {
    if ($originalUser) {
        $guard->setUser($originalUser);
    } else {
        $guard->forgetUser();
    }
}

echo 'Checked '.$users->count().' active room-move roles with '.$checksRun.' assertions; database changes from renders were rolled back.'.PHP_EOL;
