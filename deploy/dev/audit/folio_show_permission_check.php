<?php

// Render real Folio detail routes for active demo roles with folio.view and
// verify action modals against permissions plus actionable stay state.
// Run with: deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/folio_show_permission_check.php';"

use App\Models\Folio;
use App\Models\Hotel;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

Tenant::set(Hotel::findOrFail(1));
$guard = Auth::guard();
$originalUser = $guard->user();
$kernel = app(Illuminate\Contracts\Http\Kernel::class);
$folioCases = collect([
    'actionable stay' => Folio::query()->where('folios.status', 'open')
        ->whereHas('stay', fn ($stay) => $stay->whereIn('status', ['reserved', 'checked_in']))
        ->with('stay')->orderByDesc('id')->first(),
    'no actionable stay' => Folio::query()->where('folios.status', 'open')
        ->where(fn ($query) => $query->whereDoesntHave('stay')
            ->orWhereHas('stay', fn ($stay) => $stay->whereNotIn('status', ['reserved', 'checked_in'])))
        ->with('stay')->orderByDesc('id')->first(),
])->filter()->unique('id');
if ($folioCases->isEmpty()) {
    throw new RuntimeException('No open folios are available for the permission audit.');
}
$users = User::query()->where('hotel_id', 1)->where('is_active', true)->get()
    ->filter(fn (User $user) => $user->hasPermission('folio.view'))
    ->unique('role_id')->values();
$checksRun = 0;

try {
    foreach ($users as $user) {
        $guard->setUser($user);
        foreach ($folioCases as $scenario => $folio) {
            $isOpen = $folio->status === 'open';
            $hasActionableStay = $folio->stay && in_array($folio->stay->status, ['reserved', 'checked_in'], true);
            $balance = $folio->balance();
            $request = Request::create('/folios/'.$folio->id, 'GET');
            $response = $kernel->handle($request);
            $html = (string) $response->getContent();
            $checks = [
                'folio returns HTTP 200' => $response->getStatusCode() === 200,
                'charge modal matches folio.post' => str_contains($html, 'id="chargeModal"') === ($isOpen && $user->hasPermission('folio.post')),
                'payment modal matches folio.payment' => str_contains($html, 'id="payModal"') === ($isOpen && $user->hasPermission('folio.payment')),
                'MoMo modal matches request permission and positive balance' => str_contains($html, 'id="momoModal"') === ($isOpen && $user->hasPermission('payments.request') && $balance > 0),
                'discount modal matches folio.discount' => str_contains($html, 'id="discModal"') === ($isOpen && $user->hasPermission('folio.discount')),
                'city-ledger modal matches payment permission and positive balance' => str_contains($html, 'id="transferModal"') === ($isOpen && $user->hasPermission('folio.payment') && $balance > 0),
                'void modal matches folio.void' => str_contains($html, 'id="voidModal"') === ($isOpen && $user->hasPermission('folio.void')),
                'split modal matches folio.split' => str_contains($html, 'id="moveModal"') === ($isOpen && $user->hasPermission('folio.split')),
                'fixed-charge modal matches folio.post and actionable stay state' => str_contains($html, 'id="fixedModal"') === ($isOpen && $hasActionableStay && $user->hasPermission('folio.post')),
                'package modal matches package permission and actionable stay state' => str_contains($html, 'id="pkgModal"') === ($isOpen && $hasActionableStay && $user->hasPermission('packages.sell')),
            ];

            foreach ($checks as $label => $passed) {
                ++$checksRun;
                echo ($passed ? 'PASS ' : 'FAIL ').$user->role->name." ({$scenario}): {$label}".PHP_EOL;
                if (! $passed) {
                    throw new RuntimeException("Folio permission check failed for {$user->role->name} ({$scenario}): {$label}");
                }
            }
            $kernel->terminate($request, $response);
        }
    }
} finally {
    if ($originalUser) {
        $guard->setUser($originalUser);
    } else {
        $guard->forgetUser();
    }
}

echo 'Checked '.$users->count().' folio roles across '.$folioCases->count().' stay-state cases with '.$checksRun.' assertions.'.PHP_EOL;
