<?php
/**
 * v7.51 — the capability facts that docs/FEATURE-GAP-AUDIT.md makes claims about, dumped as JSON.
 *
 * A gap audit rots in one direction only: a capability ships and the document keeps saying it is missing. The
 * checklist half of that document had already been updated for social posting, review feeds, bank statements, mobile
 * money and the PABX while the priority-ranked summary at the top still listed them as open gaps, so the document
 * contradicted itself and told the business four shipped things were missing.
 *
 * gap_audit_check.py reads this and diffs every claim in the document against it — in both directions, so a claim that
 * something is closed fails when the evidence is absent, and a claim that something is still open fails the moment
 * somebody ships it. Facts that need the application (registered commands, the driver registry, the live menu, hotel
 * columns) are read from the running code rather than guessed from filenames.
 *
 * TINKER='require "/app/deploy/dev/audit/gap_facts.php";' deploy/dev/bin/php -f deploy/dev/tinker.php
 */

use App\Services\Interfaces\ChannelDriverRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;

$drivers = (new ReflectionClass(ChannelDriverRegistry::class))->getStaticPropertyValue('drivers');

$commands = array_keys(Artisan::all());
sort($commands);

// The registry is a flat list of groups, each carrying a `section` key, each holding `children` modules. Counted
// exactly as menu_inventory.php counts it, so the two harnesses can never disagree about what the menu holds: a module
// that is a direct link carries no page list, and a child with no route is a divider rather than a page.
$menu = config('pms.menu');
$modules = 0;
$pages = 0;
foreach ($menu as $group) {
    foreach ($group['children'] ?? [] as $mod) {
        $modules++;
        if (empty($mod['route'])) {
            $pages += count(array_filter($mod['children'] ?? [], fn ($k) => ! empty($k['route'])));
        }
    }
}

echo json_encode([
    'generated_at' => now()->toDateTimeString(),
    'business_date' => bdate()->toDateString(),
    'laravel' => Application::VERSION,
    'php' => PHP_VERSION,
    'pms_version' => config('pms.version'),
    'menu' => ['sections' => count(array_unique(array_column($menu, 'section'))), 'groups' => count($menu),
        'modules' => $modules, 'pages' => $pages],
    'route_count' => count(Route::getRoutes()->getRoutes()),
    'commands' => $commands,
    'channel_drivers' => array_values(array_keys($drivers)),
    'hotel_columns' => Schema::getColumnListing('hotels'),
    'reservation_columns' => Schema::getColumnListing('reservations'),
    'settings' => App\Models\Setting::orderBy('key')->pluck('key')->values()->all(),
    'services' => array_values(array_map(
        fn ($f) => basename($f, '.php'),
        glob(base_path('app/Services/*.php')) ?: []
    )),
    'gateway_services' => array_values(array_map(
        fn ($f) => basename($f, '.php'),
        glob(base_path('app/Services/Payments/*.php')) ?: []
    )),
    'docs' => array_values(array_map(
        fn ($f) => basename($f),
        glob(base_path('docs/*.md')) ?: []
    )),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
