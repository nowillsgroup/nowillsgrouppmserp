<?php

// Render the actual walk-in screen as every active demo role and verify the
// existing frontdesk.checkin and reservations.view visibility behavior.
// GET requests are read-only; run with:
// TINKER='require "/app/deploy/dev/audit/frontdesk_walkin_permission_render_check.php";' deploy/dev/bin/php -f deploy/dev/tinker.php

use App\Models\Hotel;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

Tenant::set(Hotel::findOrFail(1));
$users = User::query()->where('hotel_id', 1)->where('is_active', true)->get();
if ($users->isEmpty()) {
    throw new RuntimeException('No active demo users are available for the walk-in permission render check.');
}
$guard = Auth::guard();
$originalUser = $guard->user();
$kernel = app(Illuminate\Contracts\Http\Kernel::class);
$checksRun = 0;

try {
    foreach ($users as $user) {
        $guard->setUser($user);
        $request = Request::create('/front-desk/walk-in', 'GET');
        $response = $kernel->handle($request);
        $html = (string) $response->getContent();
        $allowed = $user->hasPermission('frontdesk.checkin');
        $checks = [
            'walk-in route preserves frontdesk.checkin permission' => $allowed
                ? $response->getStatusCode() === 200
                : in_array($response->getStatusCode(), [302, 403], true),
            'City Ledger creation is limited to check-in and credit-manage roles' => (str_contains($html, 'id="walkinCreateCityLedger"') === ($allowed && $user->hasPermission('credit.manage')))
                && (str_contains($html, 'id="walkinCompanyCreateForm"') === ($allowed && $user->hasPermission('credit.manage'))),
        ];

        if ($allowed) {
            $headEnd = strpos($html, '</head>');
            $stylePosition = strpos($html, '.walkin-page');
            $shouldSearchGuests = $user->hasPermission('reservations.view');
            $checks += [
                'actual walk-in workspace and guest form render' => str_contains($html, 'class="walkin-page"')
                    && str_contains($html, 'id="walkinForm"')
                    && str_contains($html, 'Guest registration'),
                'room, stay, estimate and deposit controls render' => str_contains($html, 'id="room_id"')
                    && str_contains($html, 'id="f_nights"')
                    && str_contains($html, 'id="walkinSummaryRoom"')
                    && str_contains($html, 'id="walkinAdvanceSplit"'),
                'room readiness status and dirty/touch-up confirmation render' => str_contains($html, 'data-hk=')
                    && str_contains($html, 'Confirm room readiness with Housekeeping before arrival.'),
                'walk-in Other title reveals a custom title input and save control' => str_contains($html, 'value="__other__"')
                    && str_contains($html, 'id="guestCustomTitle"')
                    && str_contains($html, 'id="guestCustomTitleSave"'),
                'company direct-billing guidance renders from company settings' => str_contains($html, 'id="walkinBillingNote"')
                    && str_contains($html, 'const companyBilling = ')
                    && str_contains($html, 'Direct billing is enabled for this company.'),
                'selected guest link can be reviewed and safely cleared' => str_contains($html, 'id="walkinGuestLink"')
                    && str_contains($html, 'id="walkinGuestUnlink"')
                    && str_contains($html, 'const clearLinkedGuest = ()')
                    && str_contains($html, 'Editing name, mobile, email or ID details clears this profile link.'),
                'guest-history lookup follows reservations.view' => str_contains($html, 'id="guestLookup"') === $shouldSearchGuests,
                'guest-search results distinguish profiles with only an ID suffix' => str_contains($html, "String(guest.id_number).slice(-4)")
                    && ! str_contains($html, 'details.textContent = [guest.phone, guest.email, guest.id_number]'),
                'contract-rate API requests follow reservations.view' => str_contains($html, 'const canQuoteRates = '.($shouldSearchGuests ? 'true' : 'false').';'),
                'suggested-rate refresh control follows reservations.view' => str_contains($html, 'id="walkinRateRefresh"') === $shouldSearchGuests,
                'terms and special-request fields render' => str_contains($html, 'id="f_special_request"')
                    && str_contains($html, 'id="walkinSummaryTitle"'),
                'final check-in requires an estimate-and-deposit confirmation' => str_contains($html, 'window.confirm(message)')
                    && str_contains($html, 'checks the guest in and records any deposit.'),
                'walk-in stylesheet is emitted into document head' => $headEnd !== false
                    && $stylePosition !== false && $stylePosition < $headEnd,
                'room-plan and pricing script render without HTML-string injection' => str_contains($html, 'planOption.textContent')
                    && ! str_contains($html, 'plan.innerHTML')
                    && str_contains($html, 'new URLSearchParams'),
            ];
        }

        foreach ($checks as $label => $passed) {
            ++$checksRun;
            echo ($passed ? 'PASS ' : 'FAIL ').$user->role->name.": {$label}".PHP_EOL;
            if (! $passed) {
                throw new RuntimeException("Walk-in permission render check failed for {$user->role->name}: {$label}");
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

echo 'Checked '.$users->count().' active demo roles with '.$checksRun.' assertions; no guest, reservation, stay or folio data was created.'.PHP_EOL;
