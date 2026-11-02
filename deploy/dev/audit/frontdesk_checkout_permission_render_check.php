<?php

// Render the real check-out form for every active demo role with
// frontdesk.checkout. The render is rolled back as a safety measure.
// Run with: deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/frontdesk_checkout_permission_render_check.php';"

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
    throw new RuntimeException('No checked-in demo stay is available for the check-out render check.');
}
$users = User::query()->where('hotel_id', 1)->where('is_active', true)->get()
    ->filter(fn (User $user) => $user->hasPermission('frontdesk.checkout'))->values();
if ($users->isEmpty()) {
    throw new RuntimeException('No active demo users have frontdesk.checkout.');
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
            $request = Request::create('/front-desk/stay/'.$stay->id.'/check-out', 'GET');
            $response = $kernel->handle($request);
            $html = (string) $response->getContent();
            $pageStart = strpos($html, '<div class="checkout-page">');
            $page = $pageStart === false ? '' : substr($html, $pageStart);
            $folio = $stay->folio;
            $canViewFolio = $user->hasPermission('folio.view');
            $canViewReservation = $user->hasPermission('reservations.view');
            $redirectMethod = new ReflectionMethod(\App\Http\Controllers\FrontDeskController::class, 'checkOutSuccessRedirect');
            $successRedirect = $redirectMethod->invoke(app(\App\Http\Controllers\FrontDeskController::class), $stay, 'Audit redirect');
            $expectedRedirect = $canViewFolio
                ? route('folios.invoice', $folio)
                : ($canViewReservation
                    ? route('reservations.show', $stay->reservation_id)
                    : ($user->hasPermission('frontdesk.view')
                        ? route('frontdesk.departures')
                        : \App\Http\Controllers\Auth\LoginController::home($user)));
            $checks = [
                'actual check-out form returns HTTP 200' => $response->getStatusCode() === 200,
                'overview, dates and projected settlement render' => str_contains($page, 'aria-label="Check-out overview"')
                    && str_contains($page, 'aria-label="Stay dates"') && str_contains($page, 'Projected balance due'),
                'settlement form and confirmation control render' => str_contains($page, 'id="checkoutSettleForm"')
                    && str_contains($page, 'Settle & Check-out'),
                'folio ledger and itemized totals follow folio.view' => str_contains($page, 'checkout-ledger-table') === $canViewFolio
                    && str_contains($page, 'Total charges (incl. tax)') === $canViewFolio,
                'full-folio action follows folio.view' => str_contains($html, 'href="'.route('folios.show', $folio).'"') === $canViewFolio,
                'reservation action follows reservations.view' => str_contains($html, 'href="'.route('reservations.show', $stay->reservation_id).'"') === $canViewReservation,
                'restricted folio breakdown note appears only without folio.view' => str_contains($page, 'does not have permission to view the itemized folio') === ! $canViewFolio,
                'post-checkout redirect targets a screen this role may open' => $successRedirect->getTargetUrl() === $expectedRedirect,
            ];

            foreach ($checks as $label => $passed) {
                ++$checksRun;
                echo ($passed ? 'PASS ' : 'FAIL ').$user->role->name.": {$label}".PHP_EOL;
                if (! $passed) {
                    throw new RuntimeException("Check-out permission render check failed for {$user->role->name}: {$label}");
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

echo 'Checked '.$users->count().' active check-out roles with '.$checksRun.' assertions; database changes from renders were rolled back.'.PHP_EOL;
