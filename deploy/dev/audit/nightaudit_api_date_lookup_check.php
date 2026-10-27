<?php

// Regression: parameterized daily-report API lookup must find DATE rows stored with a time in SQLite.
// deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/nightaudit_api_date_lookup_check.php';"

use App\Http\Controllers\Api\V1Controller;
use App\Models\Hotel;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

if (DB::getDriverName() !== 'sqlite') {
    echo 'SKIP SQLite DATE-shape regression (driver: '.DB::getDriverName().')'.PHP_EOL;

    return;
}

Tenant::set(Hotel::firstOrFail());
$row = DB::table('night_audits')->where('hotel_id', Tenant::id())
    ->whereRaw('length(business_date) = 19')->orderByDesc('business_date')->first();
if (! $row) {
    throw new RuntimeException('Need a timestamp-shaped NightAudit.business_date fixture.');
}
$date = substr((string) $row->business_date, 0, 10);
$request = Request::create('/api/v1/reports/daily', 'GET', ['date' => $date]);
$response = app(V1Controller::class)->daily($request);
$body = json_decode($response->getContent(), true);
$ok = $response->getStatusCode() === 200 && ($body['data']['date'] ?? null) === $date;
echo ($ok ? 'PASS ' : 'FAIL ')."daily report API finds timestamp-shaped DATE row for {$date} (HTTP {$response->getStatusCode()})".PHP_EOL;
if (! $ok) {
    throw new RuntimeException('Daily report date lookup failed for a persisted audit row.');
}
