<?php

// Exercise the Walk-in company-creation action and verify Finance sees the
// new direct-billing account. All database writes are rolled back.
// Run with:
// TINKER='require "/app/deploy/dev/audit/frontdesk_walkin_company_create_check.php";' deploy/dev/bin/php -f deploy/dev/tinker.php

use App\Http\Controllers\AccountsController;
use App\Http\Controllers\FrontDeskController;
use App\Models\Company;
use App\Models\Hotel;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\ValidationException;

Tenant::set(Hotel::findOrFail(1));
$user = User::query()->where('hotel_id', 1)->where('is_active', true)
    ->whereHas('role', fn ($query) => $query->where('name', 'Administrator'))->firstOrFail();
if (! $user->hasPermission('frontdesk.checkin') || ! $user->hasPermission('credit.manage') || ! $user->hasPermission('accounts.view')) {
    throw new RuntimeException('The demo administrator must have check-in, credit-management and accounts-view permissions.');
}

$guard = Auth::guard();
$originalUser = $guard->user();
$name = 'Walk-in City Ledger Audit '.Str::upper(Str::random(8));
$checksRun = 0;
$assert = function (bool $passed, string $label) use (&$checksRun): void {
    ++$checksRun;
    echo ($passed ? 'PASS ' : 'FAIL ').$label.PHP_EOL;
    if (! $passed) {
        throw new RuntimeException('Walk-in city-ledger creation check failed: '.$label);
    }
};

$frontOfficeManager = User::query()->where('hotel_id', 1)->where('is_active', true)
    ->whereHas('role', fn ($query) => $query->where('name', 'Front Office Manager'))->firstOrFail();
if (! $frontOfficeManager->hasPermission('frontdesk.checkin') || $frontOfficeManager->hasPermission('credit.manage')) {
    throw new RuntimeException('The demo Front Office Manager should have check-in access without credit management.');
}
$guard->setUser($frontOfficeManager);
$unauthorizedDenied = false;
try {
    app(FrontDeskController::class)->storeWalkInCompany(Request::create('/front-desk/walk-in/company', 'POST'));
} catch (HttpException $exception) {
    $unauthorizedDenied = $exception->getStatusCode() === 403;
} finally {
    $guard->setUser($user);
}
$assert($unauthorizedDenied, 'Check-in-only Front Office Manager is denied account creation without credit.manage');

DB::beginTransaction();
try {
    $guard->setUser($user);
    $request = Request::create('/front-desk/walk-in/company', 'POST', [
        'name' => $name, 'type' => 'corporate', 'contact_person' => 'Audit Contact', 'phone' => '+233 20 000 0000',
        'email' => 'walkin-ledger-audit@example.com', 'city' => 'Accra', 'address' => 'Audit address',
        'credit_limit' => '5000.00', 'credit_days' => '30',
    ]);
    $response = app(FrontDeskController::class)->storeWalkInCompany($request);
    $payload = $response->getData(true);
    $assert($response->getStatusCode() === 201 && isset($payload['company']['id']), 'Walk-in saves a company and returns its selectable account data');

    $company = Company::findOrFail($payload['company']['id']);
    $assert($company->is_active && $company->direct_billing && $company->credit_status === 'open', 'New account is active and approved for City Ledger billing');
    $assert((float) $company->credit_limit === 5000.0 && (int) $company->credit_days === 30, 'Credit limit and terms are retained');

    $duplicateRejected = false;
    try {
        app(FrontDeskController::class)->storeWalkInCompany(Request::create('/front-desk/walk-in/company', 'POST', $request->all()));
    } catch (ValidationException $exception) {
        $duplicateRejected = isset($exception->errors()['name']);
    }
    $assert($duplicateRejected, 'Duplicate company name is rejected with a field-level validation error');

    $ledgerView = app(AccountsController::class)->cityLedger();
    $ledgerCompanies = $ledgerView->getData()['companies'];
    $assert($ledgerCompanies->contains('id', $company->id), 'Saved company appears in Finance → City Ledger account data');
    View::share('currentHotel', Hotel::findOrFail(1));
    View::share('errors', new \Illuminate\Support\ViewErrorBag());
    $financeHtml = $ledgerView->render();
    $assert(str_contains($financeHtml, e($name)), 'Saved company appears in the rendered Finance → City Ledger page');
} finally {
    DB::rollBack();
    if ($originalUser) {
        $guard->setUser($originalUser);
    } else {
        $guard->forgetUser();
    }
}
$assert(! Company::where('name', $name)->exists(), 'Temporary account is absent after rollback');

echo 'Checked '.$checksRun.' assertions; temporary company and activity rows were rolled back.'.PHP_EOL;
