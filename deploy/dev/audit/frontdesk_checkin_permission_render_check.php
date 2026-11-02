<?php

// Render the actual check-in form for each active demo role. Each render is
// rolled back because loading desk upsell offers records that they were shown.
// Run with: deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/frontdesk_checkin_permission_render_check.php';"

use App\Models\Hotel;
use App\Models\ReservationRoom;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Tenant::set(Hotel::findOrFail(1));
$stay = ReservationRoom::query()->where('status', 'reserved')->orderBy('arrival_date')->orderBy('id')->first();
if (! $stay) {
    throw new RuntimeException('No reserved demo stay is available for the check-in render check.');
}
$users = User::query()->where('hotel_id', 1)->where('is_active', true)->get()
    ->filter(fn (User $user) => $user->hasPermission('frontdesk.checkin'))->values();
if ($users->isEmpty()) {
    throw new RuntimeException('No active demo users have frontdesk.checkin.');
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
            $request = Request::create('/front-desk/stay/'.$stay->id.'/check-in', 'GET');
            $response = $kernel->handle($request);
            $html = (string) $response->getContent();
            $start = strpos($html, '<div class="checkin-page">');
            $page = $start === false ? '' : substr($html, $start);
            $canViewReservation = $user->hasPermission('reservations.view');
            $checks = [
                'actual check-in form returns HTTP 200' => $response->getStatusCode() === 200,
                'stay context and date fields render' => str_contains($page, 'Guest arrival') && str_contains($page, 'datetime="'.$stay->arrival_date->toDateString().'"'),
                'required identity fields and the room selector render' => str_contains($page, 'name="guest[id_type]"')
                    && str_contains($page, 'name="guest[id_number]"') && str_contains($page, 'id="checkinRoom"'),
                'reservation lookup follows reservations.view' => str_contains($page, 'id="guestLookup"') === $canViewReservation,
                'walk-in-only custom title editor stays off reservation check-in' => ! str_contains($page, 'id="guestCustomTitle"'),
                'check-in completion control renders' => str_contains($page, 'Complete check-in'),
                'authorized check-in role can open the registration and ID workflow' => str_contains($html, 'Registration &amp; ID'),
            ];

            foreach ($checks as $label => $passed) {
                ++$checksRun;
                echo ($passed ? 'PASS ' : 'FAIL ').$user->role->name.": {$label}".PHP_EOL;
                if (! $passed) {
                    throw new RuntimeException("Check-in permission render check failed for {$user->role->name}: {$label}");
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

echo 'Checked '.$users->count().' active check-in roles with '.$checksRun.' assertions; database changes from renders were rolled back.'.PHP_EOL;
