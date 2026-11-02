<?php

// Rollback-safe audit of multi-person, individually attributable F&B service shifts.
// Run with: deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/department_shift_session_check.php';"

use App\Http\Controllers\DepartmentShiftController;
use App\Http\Controllers\PosController;
use App\Models\DepartmentShiftSession;
use App\Models\Hotel;
use App\Models\Outlet;
use App\Models\Permission;
use App\Models\PosOrder;
use App\Models\PosOrderItem;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\DepartmentShiftService;
use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

Tenant::set(Hotel::findOrFail(1));
$staff = User::query()->where('hotel_id', 1)->where('is_active', true)->get();
$first = $staff->first(fn (User $user) => $user->hasPermission('pos.order'));
$second = $staff->first(fn (User $user) => $user->id !== $first?->id && $user->hasPermission('pos.order'));
$manager = $staff->first(fn (User $user) => $user->hasPermission('pos.shift_view_all'));
$waiterRole = Role::query()->where('hotel_id', 1)->where('name', 'Restaurant Waiter')->first();
$outlet = Outlet::where('is_active', true)->first();
if (! $first || ! $second || ! $manager || ! $outlet) {
    throw new RuntimeException('The audit needs two POS-order staff, a shift-report manager and one active outlet.');
}
$guard = Auth::guard();
$originalUser = $guard->user();
$checks = 0;
$assert = function (bool $condition, string $label) use (&$checks) {
    ++$checks;
    echo ($condition ? 'PASS ' : 'FAIL ').$label.PHP_EOL;
    if (! $condition) {
        throw new RuntimeException('Department shift audit failed: '.$label);
    }
};
$waiterPermissions = $waiterRole?->permissions()->pluck('key')->all() ?? [];
$assert(in_array('pos.order', $waiterPermissions, true) && in_array('pos.view', $waiterPermissions, true)
    && ! in_array('pos.shift_view_all', $waiterPermissions, true), 'default waiter role can operate POS shifts without seeing every server’s shift report');

DB::beginTransaction();
try {
    $guard->setUser($first);
    $one = DepartmentShiftService::startFoodService($first, 'Audit station A');
    $assert($one->status === 'open' && (int) $one->user_id === (int) $first->id, 'a waiter opens an individual authenticated service shift');
    try {
        DepartmentShiftService::startFoodService($first);
        $assert(false, 'duplicate personal service shifts are rejected');
    } catch (RuntimeException $e) {
        $assert(str_contains($e->getMessage(), 'already have an open'), 'duplicate personal service shifts are rejected');
    }
    $posRequest = Request::create('/pos/orders', 'POST', ['outlet_id' => $outlet->id, 'order_type' => 'takeaway', 'covers' => 1]);
    app(PosController::class)->open($posRequest);
    $autoLinkedOrder = PosOrder::where('user_id', $first->id)->where('department_shift_session_id', $one->id)->latest('id')->first();
    $assert($autoLinkedOrder !== null && $autoLinkedOrder->order_type === 'takeaway', 'a POS order opened by the signed-in waiter is automatically linked to their open service shift');
    $autoLinkedOrder?->delete();

    DB::beginTransaction();
    try {
        // In ask mode KOT jobs wait for an explicit print choice, so this audit cannot send a real printer job.
        Setting::updateOrCreate(['key' => 'pos_kot_print_mode'], ['value' => 'ask']);
        $guestOrder = PosOrder::create(['order_no' => 'SHIFT-AUDIT-QR', 'outlet_id' => $outlet->id, 'order_type' => 'dine_in',
            'business_date' => bdate()->toDateString(), 'status' => 'open', 'covers' => 2, 'total' => 20]);
        PosOrderItem::create(['pos_order_id' => $guestOrder->id, 'item_name' => 'QR audit meal', 'qty' => 1, 'rate' => 20,
            'amount' => 20, 'is_pending' => true]);
        $guard->setUser($first);
        app(PosController::class)->acceptGuest($guestOrder);
        $acceptedQrOrder = $guestOrder->fresh();
        $assert((int) $acceptedQrOrder->department_shift_session_id === (int) $one->id
            && (int) $acceptedQrOrder->user_id === (int) $first->id
            && ! $acceptedQrOrder->items()->first()->is_pending,
            'a waiter who accepts a guest QR order attributes it to their active service shift');
    } finally {
        DB::rollBack();
    }

    $guard->setUser($second);
    $two = DepartmentShiftService::startFoodService($second, 'Audit station B');
    $assert($two->status === 'open' && $two->shift === $one->shift && $two->business_date->toDateString() === $one->business_date->toDateString(),
        'multiple staff can independently open the same named department shift');

    $order = fn (DepartmentShiftSession $session, string $no, string $status, string $settlement, int $covers, float $total) => PosOrder::create([
        'order_no' => $no, 'outlet_id' => $outlet->id, 'order_type' => 'dine_in', 'business_date' => bdate()->toDateString(),
        'status' => $status, 'settlement' => $settlement, 'user_id' => $session->user_id, 'department_shift_session_id' => $session->id,
        'covers' => $covers, 'total' => $total,
    ]);
    $firstSettled = $order($one, 'SHIFT-AUDIT-1', 'settled', 'payment', 2, 125);
    $firstOpen = $order($one, 'SHIFT-AUDIT-2', 'open', '', 3, 80);
    $order($two, 'SHIFT-AUDIT-3', 'settled', 'room_post', 1, 60);
    $live = DepartmentShiftService::summary($one);
    $assert($live['orders'] === 2 && $live['covers'] === 5 && $live['order_value'] === 205.0
        && $live['settled_sales'] === 125.0 && $live['open_orders'] === 1, 'individual service summary separates order value, payment sales and open bills');

    try {
        DepartmentShiftService::close($one, $second, 'wrong user');
        $assert(false, 'only the session owner can close their personal report');
    } catch (RuntimeException $e) {
        $assert(str_contains($e->getMessage(), 'Only the staff member'), 'only the session owner can close their personal report');
    }

    $guard->setUser($first);
    $closed = DepartmentShiftService::close($one, $first, 'Section handed to the next server.');
    $assert($closed->status === 'closed' && $closed->close_snapshot['orders'] === 2, 'closing saves an immutable individual shift snapshot');
    $firstSettled->forceFill(['total' => 999])->save();
    $assert(abs((float) DepartmentShiftService::summary($closed)['order_value'] - 205.0) < 0.01, 'a closed report retains its snapshot when an order changes later');

    $team = DepartmentShiftService::workspace($manager, $one->business_date->toDateString());
    $group = $team['serviceDaySummary'][$one->shift] ?? [];
    $assert($team['teamServiceSessions']->count() === 2 && ($group['people'] ?? 0) === 2
        && ($group['orders'] ?? 0) === 3 && ($group['covers'] ?? 0) === 6 && ($group['order_value'] ?? 0) === 265.0,
        'a manager sees both people together in the department/day roll-up');

    $readerRole = Role::create(['hotel_id' => 1, 'name' => 'Department shift audit reader']);
    $readerRole->permissions()->attach(Permission::where('key', 'pos.order')->value('id'));
    $restricted = (new User)->forceFill(['id' => $first->id, 'hotel_id' => 1, 'role_id' => $readerRole->id, 'is_active' => true]);
    $personal = DepartmentShiftService::workspace($restricted, $one->business_date->toDateString());
    $assert($personal['teamServiceSessions']->count() === 1 && (int) $personal['teamServiceSessions']->first()->user_id === (int) $first->id,
        'a waiter without the all-staff report permission sees only their own shift reports');
    $foreignRequest = Request::create('/service-shifts/'.$two->id, 'GET');
    $foreignRequest->setUserResolver(fn () => $restricted);
    $foreignReportDenied = false;
    try {
        app(DepartmentShiftController::class)->show($foreignRequest, $two);
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
        $foreignReportDenied = $e->getStatusCode() === 403;
    }
    $assert($foreignReportDenied, 'a waiter cannot open another staff member’s report by guessing its URL');

    $waiterViewer = (new User)->forceFill(['id' => $first->id, 'hotel_id' => 1, 'role_id' => $waiterRole->id,
        'name' => 'Waiter route audit', 'is_active' => true]);
    $guard->setUser($waiterViewer);
    $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
    $request = Request::create('/service-shifts', 'GET');
    $response = $kernel->handle($request);
    $serviceHtml = (string) $response->getContent();
    $assert($response->getStatusCode() === 200 && str_contains($serviceHtml, 'My shift sessions')
        && ! str_contains($serviceHtml, e($second->name)), 'default waiter role can open the service workspace and sees only personal session history');
    $kernel->terminate($request, $response);
    $request = Request::create('/my-shift', 'GET');
    $response = $kernel->handle($request);
    $hubHtml = (string) $response->getContent();
    $assert($response->getStatusCode() === 200 && str_contains($hubHtml, 'Food &amp; Beverage Service')
        && ! str_contains($hubHtml, 'Cashiering') && ! str_contains($hubHtml, 'Front Office'),
        'default waiter role sees the My Shift hub with only its authorized department workflow');
    $kernel->terminate($request, $response);

    $reportRole = Role::create(['hotel_id' => 1, 'name' => 'Department shift report-only audit']);
    $reportRole->permissions()->attach(Permission::where('key', 'pos.shift_view_all')->value('id'));
    $reportViewer = (new User)->forceFill(['id' => $second->id, 'hotel_id' => 1, 'role_id' => $reportRole->id, 'name' => 'Report-only audit viewer', 'is_active' => true]);
    $guard->setUser($reportViewer);
    $request = Request::create('/service-shifts/'.$closed->id, 'GET');
    $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle($request);
    $html = (string) $response->getContent();
    $assert($response->getStatusCode() === 200 && str_contains($html, 'SHIFT-AUDIT-1')
        && ! str_contains($html, '/pos/orders/'.$firstSettled->id), 'report-only managers can read shift reports without receiving broken POS-terminal links');
    $kernel->terminate($request, $response);

    $assert(DepartmentShiftSession::whereKey($one->id)->exists() && DepartmentShiftSession::whereKey($two->id)->exists(), 'individual sessions persist until the surrounding transaction rolls back');
} finally {
    DB::rollBack();
    if ($originalUser) {
        $guard->setUser($originalUser);
    } else {
        $guard->forgetUser();
    }
}

echo 'Completed '.$checks.' department-shift assertions; test data was rolled back.'.PHP_EOL;
