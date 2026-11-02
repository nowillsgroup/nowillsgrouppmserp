<?php

// Render the empty in-house roster state in memory; this does not write stay
// or reservation records. The live route is checked by the role-matrix audit.
// Run with: deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/frontdesk_inhouse_render_check.php';"

use App\Models\Hotel;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ViewErrorBag;

$hotel = Hotel::findOrFail(1);
Tenant::set($hotel);
view()->share(['currentHotel' => $hotel, 'errors' => new ViewErrorBag]);
$guard = Auth::guard();
$originalUser = $guard->user();
$user = User::query()->where('hotel_id', $hotel->id)->where('is_active', true)->firstOrFail();
$guard->setUser($user);

try {
    $html = view('frontdesk.list', [
        'stays' => new Collection,
        'mode' => 'inhouse',
        'title' => 'In-House Guests',
        'canViewFolio' => $user->hasPermission('folio.view'),
        'inHouseDate' => bdate(),
        'inHouseSummary' => ['stays' => 0, 'guests' => 0, 'dueToday' => 0, 'pastDue' => 0, 'rooms' => 0],
    ])->render();

    $checks = [
        'empty roster message appears' => str_contains($html, 'No guests currently in-house'),
        'empty roster offers arrivals and room view' => str_contains($html, 'Open arrivals') && str_contains($html, 'Open room view'),
        'empty roster keeps accessible section heading' => str_contains($html, 'id="inhouse-manifest-title"'),
        'zero live-stay summary remains visible' => str_contains($html, '<b class="inhouse-stat-value">0</b>'),
        'no stay actions render without rows' => ! str_contains($html, 'aria-label="Check out ') && ! str_contains($html, 'aria-label="Move '),
    ];

    foreach ($checks as $label => $passed) {
        echo ($passed ? 'PASS ' : 'FAIL ').$label.PHP_EOL;
        if (! $passed) {
            throw new RuntimeException("In-house empty-state render failed: {$label}");
        }
    }
} finally {
    if ($originalUser) {
        $guard->setUser($originalUser);
    } else {
        $guard->forgetUser();
    }
}

echo 'Checked five empty in-house render assertions without database writes.'.PHP_EOL;
