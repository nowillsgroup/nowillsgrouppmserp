<?php

// Rollback-safe check for cashier close-out reports against the current CashierShift schema.
// Run with: deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/cashier_shift_report_check.php';"

use App\Http\Controllers\ReportController;
use App\Models\CashierMovement;
use App\Models\CashierShift;
use App\Models\Hotel;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ShiftService;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Tenant::set(Hotel::findOrFail(1));
$user = User::query()->where('hotel_id', 1)->where('is_active', true)->firstOrFail();
$otherUser = User::query()->where('hotel_id', 1)->where('is_active', true)->where('id', '!=', $user->id)->first();
if (! $otherUser) {
    throw new RuntimeException('The cashier roll-up audit needs at least two active staff accounts.');
}
$checks = 0;
$assert = function (bool $condition, string $label) use (&$checks) {
    ++$checks;
    echo ($condition ? 'PASS ' : 'FAIL ').$label.PHP_EOL;
    if (! $condition) {
        throw new RuntimeException('Cashier shift report audit failed: '.$label);
    }
};

DB::beginTransaction();
try {
    $controller = app(ReportController::class);
    $invoke = function (string $method, ...$args) use ($controller) {
        $reflection = new ReflectionMethod($controller, $method);
        return $reflection->invoke($controller, ...$args);
    };
    $from = bdate()->copy()->startOfDay();
    $to = bdate()->copy()->startOfDay();
    $registerBefore = $invoke('r_cashier_shifts', $from, $to)['rows'];
    $baselineCashierIds = $registerBefore->filter(fn ($row) => Carbon::parse($row['business_date'])->toDateString() === bdate()->toDateString())
        ->pluck('_cashier_id')->unique()->values()->all();
    $dayBefore = $invoke('r_cashier_day_summary', $from, $to)['rows']->first(fn ($row) => $row['business_date']->toDateString() === bdate()->toDateString());

    $openedAt = now()->subHours(2);
    $shift = CashierShift::create(['user_id' => $user->id, 'business_date' => bdate()->toDateString(), 'opened_at' => $openedAt,
        'opening_float' => 100, 'status' => 'open', 'notes' => 'Report audit fixture']);
    CashierMovement::create(['cashier_shift_id' => $shift->id, 'user_id' => $user->id, 'business_date' => bdate()->toDateString(),
        'kind' => 'cash_in', 'amount' => 50, 'reason' => 'Report audit cash in', 'reference' => 'AUDIT-IN']);
    CashierMovement::create(['cashier_shift_id' => $shift->id, 'user_id' => $user->id, 'business_date' => bdate()->toDateString(),
        'kind' => 'cash_out', 'amount' => 10, 'reason' => 'Report audit cash out', 'reference' => 'AUDIT-OUT']);
    $otherShift = CashierShift::create(['user_id' => $otherUser->id, 'business_date' => bdate()->toDateString(), 'opened_at' => $openedAt,
        'opening_float' => 50, 'status' => 'open', 'notes' => 'Second cashier roll-up audit fixture']);
    CashierMovement::create(['cashier_shift_id' => $otherShift->id, 'user_id' => $otherUser->id, 'business_date' => bdate()->toDateString(),
        'kind' => 'cash_in', 'amount' => 20, 'reason' => 'Second cashier audit cash in', 'reference' => 'AUDIT-IN-2']);
    $guard = Auth::guard();
    $originalGuardUser = $guard->user();
    $duplicateRejected = false;
    try {
        $guard->setUser($otherUser);
        ShiftService::open(25, 'Must not create a second drawer for one cashier.');
    } catch (RuntimeException $e) {
        $duplicateRejected = str_contains($e->getMessage(), 'already have an open shift');
    } finally {
        $originalGuardUser ? $guard->setUser($originalGuardUser) : $guard->forgetUser();
    }
    $assert($duplicateRejected && CashierShift::where('user_id', $otherUser->id)->where('status', 'open')->count() === 1,
        'cashier cannot open a second drawer shift while one is already open');

    $shiftReport = $invoke('r_cashier_shifts', $from, $to);
    $row = collect($shiftReport['rows'])->first(fn ($candidate) => $candidate['cashier'] === $user->name
        && $candidate['business_date']->toDateString() === bdate()->toDateString() && $candidate['opening_float'] === 100.0
        && $candidate['expected_cash'] === 140.0 && $candidate['status'] === 'open');
    $assert($row && $row['opening_float'] === 100.0 && $row['expected_cash'] === 140.0
        && $row['cash_received'] === 50.0 && $row['cash_out'] === 10.0 && $row['status'] === 'open',
        'cashier close-out report uses actual opening float, dates and live expected-cash summary');
    $assert(($shiftReport['cols']['business_date'] ?? null) === 'Business date'
        && in_array('variance', $shiftReport['money'], true) && in_array('surplus', $shiftReport['money'], true)
        && in_array('shortage', $shiftReport['money'], true), 'cashier shift report exposes business-date and currency-formatted reconciliation columns');

    $dayReport = $invoke('r_cashier_day_summary', $from, $to);
    $dayRow = collect($dayReport['rows'])->first(fn ($daily) => $daily['business_date']->toDateString() === bdate()->toDateString());
    $expectedCashierCount = count(array_unique([...$baselineCashierIds, (int) $user->id, (int) $otherUser->id]));
    $assert($dayRow && $dayRow['cashiers'] === $expectedCashierCount
        && $dayRow['shifts'] === (int) ($dayBefore['shifts'] ?? 0) + 2
        && $dayRow['open_shifts'] === (int) ($dayBefore['open_shifts'] ?? 0) + 2
        && abs($dayRow['expected_cash'] - ((float) ($dayBefore['expected_cash'] ?? 0) + 210)) < 0.01
        && abs($dayRow['total_collections'] - ((float) ($dayBefore['total_collections'] ?? 0) + 70)) < 0.01,
        'cashier day roll-up combines multiple staff shifts while counting each cashier once');
    $assert(($dayReport['cols']['business_date'] ?? null) === 'Business date' && in_array('variance', $dayReport['money'], true)
        && in_array('surplus', $dayReport['money'], true) && in_array('shortage', $dayReport['money'], true),
        'cashier daily report groups currency-formatted reconciliation fields at department/day level');

    $movementReport = $invoke('r_cashier_movements', $from, $to);
    $movementRows = collect($movementReport['rows'])->where('shift', '#'.$shift->id);
    $assert($movementRows->count() === 2 && $movementRows->contains(fn ($r) => $r['type'] === 'Cash in' && $r['reference'] === 'AUDIT-IN')
        && $movementRows->contains(fn ($r) => $r['type'] === 'Cash out' && $r['entered_by'] === $user->name),
        'cashier movement report uses the real movement, cashier, shift and timestamp fields');
    $reportRouteMiddleware = Route::getRoutes()->getByName('reports.show')?->getAction('middleware') ?? [];
    $assert((ReportController::REPORTS['cashier_shifts'][4] ?? null) === 'folio.shift_all'
        && (ReportController::REPORTS['cashier_movements'][4] ?? null) === 'folio.shift_all'
        && (ReportController::REPORTS['cashier_day_summary'][4] ?? null) === 'folio.shift_all'
        && in_array('perm:reports.view,folio.shift_all', $reportRouteMiddleware, true),
        'cashier managers can reach reports by their report-specific permission without being granted unrelated report access');

    $reportRole = Role::create(['hotel_id' => 1, 'name' => 'Cashier report-only audit']);
    $reportRole->permissions()->attach(Permission::where('key', 'folio.shift_all')->value('id'));
    $reportViewer = (new User)->forceFill(['id' => $otherUser->id, 'hotel_id' => 1, 'role_id' => $reportRole->id,
        'name' => 'Cashier report-only viewer', 'is_active' => true]);
    $guard = Auth::guard();
    $originalGuardUser = $guard->user();
    try {
        $guard->setUser($reportViewer);
        $request = Request::create('/reports/cashier_day_summary?from='.$from->toDateString().'&to='.$to->toDateString(), 'GET');
        $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
        $response = $kernel->handle($request);
        $html = (string) $response->getContent();
        $assert($response->getStatusCode() === 200 && str_contains($html, 'Daily Cashier Shift Roll-up')
            && ! $reportViewer->hasPermission('reports.view'), 'cashier-only report permission opens its daily report without granting the general report library');
        $kernel->terminate($request, $response);
    } finally {
        $originalGuardUser ? $guard->setUser($originalGuardUser) : $guard->forgetUser();
    }

    $guard->setUser($user);
    $closedShift = ShiftService::close($shift, 142, 'Counted above expected.');
    $assert($closedShift->status === 'closed' && $closedShift->expected_cash === 140.0
        && $closedShift->counted_cash === 142.0 && $closedShift->variance === 2.0,
        'cashier close stores expected cash, counted cash and variance on the individual shift');
    CashierMovement::where('cashier_shift_id', $shift->id)->where('kind', 'cash_in')->update(['amount' => 999]);
    $savedSummary = ShiftService::summary($closedShift);
    $closedRegister = $invoke('r_cashier_shifts', $from, $to)['rows'];
    $closedRow = $closedRegister->first(fn ($candidate) => $candidate['_cashier_id'] === (int) $user->id
        && $candidate['opening_float'] === 100.0 && $candidate['expected_cash'] === 140.0
        && $candidate['variance'] === 2.0 && $candidate['status'] === 'closed');
    $assert(abs((float) $savedSummary['expected_cash'] - 140) < 0.01 && abs((float) $savedSummary['total_in'] - 50) < 0.01
        && $closedRow && abs((float) $closedRow['expected_cash'] - 140) < 0.01 && abs((float) $closedRow['variance'] - 2) < 0.01,
        'closed cashier reports retain their saved collection and reconciliation snapshot after later ledger edits');
} finally {
    DB::rollBack();
}

echo 'Completed '.$checks.' cashier-report assertions; test data was rolled back.'.PHP_EOL;
