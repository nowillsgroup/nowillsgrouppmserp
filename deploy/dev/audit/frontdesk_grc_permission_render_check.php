<?php

// Render the real printable GRC for stay 57 as every active demo role and
// verify the existing frontdesk.view gate plus document/print layout output.
// GET requests are read-only; run with:
// deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/frontdesk_grc_permission_render_check.php';"

use App\Models\Hotel;
use App\Models\ReservationRoom;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

Tenant::set(Hotel::findOrFail(1));
$stay = ReservationRoom::query()->findOrFail(57);
$users = User::query()->where('hotel_id', 1)->where('is_active', true)->get();
if ($users->isEmpty()) {
    throw new RuntimeException('No active demo users are available for the GRC permission render check.');
}
$guard = Auth::guard();
$originalUser = $guard->user();
$kernel = app(Illuminate\Contracts\Http\Kernel::class);
$checksRun = 0;

try {
    foreach ($users as $user) {
        $guard->setUser($user);
        $request = Request::create('/front-desk/stay/'.$stay->id.'/grc', 'GET');
        $response = $kernel->handle($request);
        $html = (string) $response->getContent();
        $allowed = $user->hasPermission('frontdesk.view');
        $headEnd = strpos($html, '</head>');
        $stylePosition = strpos($html, '.grc-sheet');
        $checks = [
            'GRC route preserves frontdesk.view permission' => $allowed
                ? $response->getStatusCode() === 200
                : in_array($response->getStatusCode(), [302, 403], true),
        ];

        if ($allowed) {
            $checks += [
                'actual registration card and reservation render' => str_contains($html, 'id="guestRegistrationCard"')
                    && str_contains($html, e($stay->reservation->reservation_no)),
                'guest identity, ID and stay sections render' => str_contains($html, 'Guest identity &amp; contact')
                    && str_contains($html, 'Identity documents') && str_contains($html, 'Reservation &amp; stay'),
                'declaration and signature lines render' => str_contains($html, 'Guest declaration &amp; terms')
                    && str_contains($html, 'Guest signature') && str_contains($html, 'Front-office representative'),
                'GRC stylesheet is emitted into document head' => $headEnd !== false && $stylePosition !== false && $stylePosition < $headEnd,
                'print page includes the browser print action' => str_contains($html, 'window.print()'),
            ];
        }

        foreach ($checks as $label => $passed) {
            ++$checksRun;
            echo ($passed ? 'PASS ' : 'FAIL ').$user->role->name.": {$label}".PHP_EOL;
            if (! $passed) {
                throw new RuntimeException("GRC permission render check failed for {$user->role->name}: {$label}");
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

echo 'Checked '.$users->count().' active demo roles with '.$checksRun.' assertions; no GRC data was changed.'.PHP_EOL;
