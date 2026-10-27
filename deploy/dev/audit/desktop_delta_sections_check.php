<?php

// Verify delta carries changes to each reference section present in a desktop pack.
// TINKER='require "/app/deploy/dev/audit/desktop_delta_sections_check.php";' deploy/dev/bin/php -f deploy/dev/tinker.php

use App\Models\Folio;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\PaymentMode;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\User;
use App\Services\DesktopSyncService;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;

Tenant::set(Hotel::find(1));
$user = User::where('hotel_id', 1)->firstOrFail();
$models = [
    'room_types' => RoomType::where('hotel_id', 1)->first(),
    'rate_plans' => RatePlan::where('hotel_id', 1)->first(),
    'payment_modes' => PaymentMode::where('hotel_id', 1)->first(),
    'guests' => Guest::where('hotel_id', 1)->first(),
    'folios' => Folio::where('hotel_id', 1)->first(),
];
foreach ($models as $section => $model) {
    if (! $model) {
        throw new RuntimeException("No demo {$section} row available for the delta check.");
    }
}

DB::beginTransaction();
try {
    $stamp = now();
    foreach ($models as $model) {
        $model->newQuery()->whereKey($model->getKey())->update(['updated_at' => $stamp]);
    }
    $delta = DesktopSyncService::delta($user, $stamp->copy()->subMinute()->toIso8601String());
    $failed = [];
    foreach ($models as $section => $model) {
        $found = collect($delta[$section] ?? [])->contains(fn ($row) => (int) $row['id'] === (int) $model->id);
        echo ($found ? 'PASS ' : 'FAIL ')."desktop delta includes changed {$section}".PHP_EOL;
        if (! $found) {
            $failed[] = $section;
        }
    }
    if ($failed) {
        throw new RuntimeException('Desktop delta omitted: '.implode(', ', $failed));
    }
} finally {
    DB::rollBack();
}
