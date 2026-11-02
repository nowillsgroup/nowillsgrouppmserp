<?php

// Render the actual e-GRC page for stay 57 as every active demo role and
// verify the existing frontdesk.checkin gate plus reservation-link visibility.
// GET requests are read-only; run with:
// deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/frontdesk_grc_sign_permission_render_check.php';"

use App\Models\Hotel;
use App\Models\ReservationRoom;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

Tenant::set(Hotel::findOrFail(1));
$stay = ReservationRoom::query()->findOrFail(57);
$stay->load('reservation');
$users = User::query()->where('hotel_id', 1)->where('is_active', true)->get();
if ($users->isEmpty()) {
    throw new RuntimeException('No active demo users are available for the e-GRC render check.');
}
$guard = Auth::guard();
$originalUser = $guard->user();
$kernel = app(Illuminate\Contracts\Http\Kernel::class);
$checksRun = 0;

try {
    foreach ($users as $user) {
        $guard->setUser($user);
        $request = Request::create('/front-desk/stay/'.$stay->id.'/grc/sign', 'GET');
        $response = $kernel->handle($request);
        $html = (string) $response->getContent();
        $allowed = $user->hasPermission('frontdesk.checkin');
        $checks = [
            'e-GRC route preserves frontdesk.checkin permission' => $allowed
                ? $response->getStatusCode() === 200
                : in_array($response->getStatusCode(), [302, 403], true),
        ];

        if ($allowed) {
            $headEnd = strpos($html, '</head>');
            $stylePosition = strpos($html, '.egrc-page');
            $reservationLink = 'href="'.route('reservations.show', $stay->reservation).'" class="btn btn-ghost">Back to reservation</a>';
            $shouldShowReservation = $user->hasPermission('reservations.view');
            $signatureAvailable = $stay->grc_signature_path
                && Storage::disk('local')->exists($stay->grc_signature_path)
                && $user->hasPermission('frontdesk.view');
            $checks += [
                'actual guest and stay workspace renders' => str_contains($html, 'class="egrc-page"')
                    && str_contains($html, 'Guest identity &amp; contact')
                    && str_contains($html, 'Reservation &amp; stay details'),
                'signature and ID-document forms render' => str_contains($html, 'id="sigForm"')
                    && str_contains($html, 'id="sigPad"')
                    && str_contains($html, 'egrc-upload'),
                'registration terms and accessible signature instructions render' => str_contains($html, 'id="egrcTerms"')
                    && str_contains($html, 'id="sigInstructions"')
                    && str_contains($html, 'name="accept" value="1" required'),
                'stylesheet is emitted into document head' => $headEnd !== false
                    && $stylePosition !== false && $stylePosition < $headEnd,
                'reservation shortcut follows reservations.view' => str_contains($html, $reservationLink) === $shouldShowReservation,
                'saved signature preview follows frontdesk.view' => str_contains($html, 'alt="Saved guest signature"') === (bool) $signatureAvailable,
                'GRC print action remains available' => str_contains($html, 'Print GRC')
                    && str_contains($html, route('frontdesk.grc', $stay)),
            ];
        }

        foreach ($checks as $label => $passed) {
            ++$checksRun;
            echo ($passed ? 'PASS ' : 'FAIL ').$user->role->name.": {$label}".PHP_EOL;
            if (! $passed) {
                throw new RuntimeException("e-GRC permission render check failed for {$user->role->name}: {$label}");
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

echo 'Checked '.$users->count().' active demo roles with '.$checksRun.' assertions; no signature or ID-document data was changed.'.PHP_EOL;
