<?php

/**
 * Read-only render check for the CRO desk's view/book/manage permission boundaries.
 * Run with: TINKER='require "/app/deploy/dev/audit/cro_desk_permission_render_check.php";' deploy/dev/bin/php -f deploy/dev/tinker.php
 */

$hotel = \App\Models\Hotel::where('code', 'NOWILLS')->firstOrFail();
$groupAgent = \App\Models\User::where('hotel_id', $hotel->id)->where('username', 'cro')->firstOrFail();
$groupManager = \App\Models\User::where('username', 'groupadmin')->firstOrFail();
$viewOnly = \App\Models\User::where('hotel_id', $hotel->id)->where('username', 'marketing')->firstOrFail();

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$assert($groupAgent->hasPermission('cro.view') && $groupAgent->hasPermission('cro.book') && ! $groupAgent->hasPermission('cro.manage'),
    'The CRO fixture must retain view + book without manage.');
$assert($groupAgent->hotel_group_id && $groupManager->hotel_group_id === $groupAgent->hotel_group_id,
    'The CRO and group-manager fixtures must share the hotel group.');
$assert($viewOnly->hasPermission('cro.view') && ! $viewOnly->hasPermission('cro.book') && ! $viewOnly->hasPermission('cro.manage'),
    'The Sales & Marketing fixture must be CRO view-only.');

$renderDesk = static function (\App\Models\User $user) use ($hotel): string {
    \App\Support\Tenant::set($hotel);
    \App\Services\CroService::flush();
    \Illuminate\Support\Facades\Auth::login($user);
    $request = \Illuminate\Http\Request::create('/cro?arrival=2026-11-06&departure=2026-11-08&rooms=1&adults=2&children=0&asked=1', 'GET');
    $request->setUserResolver(static fn () => $user);
    app()->instance('request', $request);
    \Illuminate\Support\Facades\View::share('currentHotel', $hotel);
    \Illuminate\Support\Facades\View::share('errors', new \Illuminate\Support\ViewErrorBag);

    $view = (new \App\Http\Controllers\CroController)->index($request);
    if (! $view instanceof \Illuminate\View\View) {
        throw new RuntimeException('CRO index did not return the expected view.');
    }

    return $view->with('currentHotel', $hotel)->with('errors', new \Illuminate\Support\ViewErrorBag)->render();
};

$agentHtml = $renderDesk($groupAgent);
$assert(str_contains($agentHtml, 'Nowills Lake Resort') && str_contains($agentHtml, 'name="asked"'),
    'The group CRO must see sister-property availability and the requested-property selector.');
$assert(str_contains($agentHtml, 'Refer &amp; book') || str_contains($agentHtml, 'Refer & book'),
    'The group CRO must be offered the existing referral-booking action.');
$assert(! str_contains($agentHtml, '/cro/settings') && ! str_contains($agentHtml, 'name="rate"'),
    'The non-manager CRO must not see CRO settings or rate overrides.');
$assert(str_contains($agentHtml, 'data-modal="croBookModal"') && str_contains($agentHtml, 'croLogModal'),
    'The book-enabled CRO must retain booking and enquiry actions.');

$viewOnlyHtml = $renderDesk($viewOnly);
$assert(! str_contains($viewOnlyHtml, 'data-modal="croBookModal"') && ! str_contains($viewOnlyHtml, 'id="croBookModal"'),
    'A view-only user must not see booking actions or the booking dialog.');
$assert(! str_contains($viewOnlyHtml, 'id="logEnquiryBtn"') && ! str_contains($viewOnlyHtml, 'id="croLogModal"'),
    'A view-only user must not see enquiry-logging actions or the log dialog.');
$assert(! str_contains($viewOnlyHtml, '/cro/settings'), 'A view-only user must not see the settings link.');

$managerHtml = $renderDesk($groupManager);
$assert(str_contains($managerHtml, '/cro/settings') && str_contains($managerHtml, 'name="rate"'),
    'A group manager must retain settings and rate-override controls.');

// Route middleware remains the actual authorization boundary; this harness only renders read-only views.
$routes = file_get_contents(base_path('routes/web.php'));
$assert(str_contains($routes, "Route::middleware('perm:cro.view,cro.book,cro.manage')->group(function ()"),
    'CRO read routes must retain the existing permission middleware.');
$assert(str_contains($routes, "Route::middleware('perm:cro.book,cro.manage')->group(function ()"),
    'CRO write routes must retain the existing permission middleware.');
$assert(str_contains($routes, "Route::middleware('perm:cro.manage')->group(function ()"),
    'CRO settings routes must remain manage-only.');

echo 'PASS: group CRO can book/refer without manager controls; view-only sees no write dialogs; group manager retains settings and rate overrides. No writes performed.'.PHP_EOL;
