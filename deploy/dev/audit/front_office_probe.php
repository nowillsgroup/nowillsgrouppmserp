<?php
// Front Office & Guest Services completeness probe — data layer, permissions,
// automation. Run: deploy/dev/bin/php -f deploy/dev/audit/front_office_probe.php
// Writes deploy/dev/audit/fo_data.json (consumed by build_report.py).
require '/app/build/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\App\Support\Tenant::set(\App\Models\Hotel::find(1));

$map = json_decode(file_get_contents('/app/deploy/dev/audit/fo_models.json'), true);

// ---- 1. menu registry: perms + badges used by the section ----
$menu = config('pms.menu');
$pages = [];
foreach ($menu as $sec) {
    if (($sec['label'] ?? '') !== 'Front Office & Guest Services') continue;
    foreach ($sec['children'] as $mod) {
        foreach (($mod['children'] ?? []) as $pg) {
            $pages[] = ['module' => $mod['label'], 'label' => $pg['label'],
                        'route' => $pg['route'] ?? null, 'perm' => $pg['perm'] ?? null,
                        'badge' => $pg['badge'] ?? null, 'params' => $pg['params'] ?? null];
        }
    }
}

$registry = config('pms.permissions');
$flat = [];
array_walk_recursive($registry, function ($label, $key) use (&$flat) {
    if (is_string($key) && is_string($label)) $flat[$key] = true;
});

$permMissing = [];
$foPerms = [];
foreach ($pages as $p) {
    foreach (array_filter(array_map('trim', explode(',', (string) ($p['perm'] ?? '')))) as $key) {
        $foPerms[$key] = true;
        if (!isset($flat[$key])) $permMissing[] = $key.' ('.$p['label'].')';
    }
}
$foPerms = array_keys($foPerms);

// ---- 2. badge partial coverage ----
$badgeSrc = file_get_contents('/app/resources/views/partials/sidebar-badge.blade.php');
$badgeMissing = [];
foreach ($pages as $p) {
    if (!$p['badge']) continue;
    $hit = str_contains($badgeSrc, "'".$p['badge']."'") || str_contains($badgeSrc, '"'.$p['badge'].'"')
        || ($p['badge'] === str_starts_with($p['badge'], 'desk_') ? str_contains($badgeSrc, "'desk_'") : false);
    if (!str_contains($badgeSrc, $p['badge'])) $badgeMissing[] = $p['badge'].' ('.$p['label'].')';
}

// ---- 3. row counts per model (data layer + demo data) ----
$counts = [];
foreach ($map as $mod => $d) {
    foreach ($d['models'] as $m) {
        $cls = '\\App\\Models\\'.$m;
        if (!class_exists($cls)) { $counts[$m] = ['module' => $mod, 'rows' => null, 'note' => 'MODEL CLASS ABSENT']; continue; }
        if (isset($counts[$m])) { continue; }
        try {
            $q = $cls::query();
            // hotel-scoped tables: count for hotel 1 (the seeded demo property)
            if (in_array('hotel_id', \Illuminate\Support\Facades\Schema::getColumnListing((new $cls)->getTable()), true)) {
                $all = (clone $q)->count();
                $h1 = (clone $q)->where('hotel_id', 1)->count();
                $counts[$m] = ['module' => $mod, 'table' => (new $cls)->getTable(), 'rows' => $all, 'rows_hotel_1' => $h1];
            } else {
                $counts[$m] = ['module' => $mod, 'table' => (new $cls)->getTable(), 'rows' => $q->count()];
            }
        } catch (\Throwable $e) {
            $counts[$m] = ['module' => $mod, 'rows' => null, 'note' => substr($e->getMessage(), 0, 120)];
        }
    }
}

// ---- 4. role coverage: can the seeded roles actually reach these pages? ----
$roles = [];
if (class_exists('\\App\\Models\\Role')) {
    foreach (\App\Models\Role::all() as $r) {
        $have = [];
        foreach (['permissions'] as $rel) {
            if (method_exists($r, $rel)) {
                try { $have = array_merge($have, collect($r->$rel)->pluck('key')->all()); } catch (\Throwable $e) {}
            }
        }
        if (!$have && isset($r->permissions) && is_string($r->permissions)) $have = json_decode($r->permissions, true) ?: [];
        $roles[$r->name ?? ('role#'.$r->id)] = ['perms' => count($have),
            'fo_perms' => count(array_intersect($have, $foPerms)), 'fo_total' => count($foPerms)];
    }
} else {
    $roles['_no_role_model'] = true;
}
$users = \App\Models\User::where('hotel_id', 1)->get(['id', 'username', 'role_id', 'is_super_admin'])
    ->map(fn($u) => ['username' => $u->username, 'role_id' => $u->role_id, 'super' => (bool) ($u->is_super_admin ?? false)])
    ->values()->all();

// ---- 5. automation: console commands + schedule touching these modules ----
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$all = array_keys($kernel->all());
$want = ['wakeup', 'trace', 'message', 'formc', 'forex', 'call', 'key', 'iptv', 'tv', 'kiosk',
         'inbox', 'concierge', 'activity', 'cro', 'lost', 'request', 'desk', 'channel', 'ical',
         'reservation', 'arrival', 'departure', 'night', 'interface'];
$cmds = array_values(array_filter($all, function ($c) use ($want) {
    foreach ($want as $w) if (str_contains(strtolower($c), $w)) return true;
    return false;
}));
sort($cmds);

file_put_contents('/app/deploy/dev/audit/fo_data.json', json_encode([
    'pages' => $pages,
    'perm_registry_size' => count($flat),
    'perm_missing' => array_values(array_unique($permMissing)),
    'badge_missing' => array_values(array_unique($badgeMissing)),
    'model_rows' => $counts,
    'roles' => $roles,
    'commands' => $cmds,
    'business_date' => function_exists('bdate') ? bdate()->toDateString() : null,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "pages: ", count($pages), PHP_EOL;
echo "perm registry entries: ", count($flat), " | missing perms: ", count(array_unique($permMissing)), PHP_EOL;
foreach (array_unique($permMissing) as $x) echo "   !! perm ", $x, PHP_EOL;
echo "badge keys missing from partial: ", count(array_unique($badgeMissing)), PHP_EOL;
foreach (array_unique($badgeMissing) as $x) echo "   !! badge ", $x, PHP_EOL;
echo "models probed: ", count($counts), PHP_EOL;
$empty = array_filter($counts, fn($c) => ($c['rows'] ?? 0) === 0);
echo "models with ZERO rows: ", count($empty), PHP_EOL;
foreach ($empty as $m => $c) echo "   -- ", $m, " (", $c['module'], ", table ", ($c['table'] ?? '?'), ")", PHP_EOL;
$absent = array_filter($counts, fn($c) => ($c['rows'] ?? 0) === null);
foreach ($absent as $m => $c) echo "   !! ", $m, ": ", ($c['note'] ?? 'n/a'), PHP_EOL;
echo "commands matching module keywords: ", count($cmds), PHP_EOL;
echo "  ", implode(', ', $cmds), PHP_EOL;
echo "roles: ", json_encode($roles), PHP_EOL;
echo "PROBE_DONE", PHP_EOL;
