<?php

/**
 * HR & Workforce audit — permission evidence.
 *
 * Every permission key the HR sidebar asks for must exist in config/pms.php AND be seeded in the
 * permissions table, and the HR roles must actually hold them, or the menu item is dead for the
 * people who need it.
 *
 *   TINKER='require "/app/deploy/dev/audit/hr_perms.php";' deploy/dev/bin/php -f deploy/dev/tinker.php
 */

$keys = ['claims.submit', 'hr.leave', 'hr.manage', 'hr.self', 'learning.manage', 'learning.view',
    'payroll.manage', 'payroll.view', 'roster.view', 'servicecharge.approve', 'servicecharge.manage',
    'talent.appraise', 'talent.discipline', 'talent.manage', 'talent.recruit', 'talent.view', 'timeclock.kiosk'];

$cfg = config('pms.permissions');
$flat = [];
// config('pms.permissions') is nested: group => [key => label], so the permission key is the array key.
array_walk_recursive($cfg, function ($v, $k) use (&$flat) {
    if (is_string($v) && is_string($k)) {
        $flat[] = $k;
    }
});
$flat = array_values(array_unique($flat));
$seeded = App\Models\Permission::pluck('key')->all();

$missing = [];
foreach ($keys as $k) {
    if (! in_array($k, $flat, true) || ! in_array($k, $seeded, true)) {
        $missing[] = $k.'('.(in_array($k, $flat, true) ? '' : 'not declared ').(in_array($k, $seeded, true) ? '' : 'not seeded').')';
    }
}
echo 'HR menu permission keys: '.count($keys)."  declared in config: ".count(array_intersect($keys, $flat))
    .'  seeded in the database: '.count(array_intersect($keys, $seeded))."\n";
echo 'missing: '.($missing ? implode(', ', $missing) : 'none')."\n";
echo 'app-wide permissions declared: '.count($flat).'  seeded: '.count($seeded)."\n\n";

foreach (App\Models\Role::with('permissions')->orderBy('name')->get() as $r) {
    $have = $r->permissions->pluck('key')->all();
    $n = count(array_intersect($keys, $have));
    if ($n) {
        printf("%-34s holds %2d/%d HR permissions%s", $r->name, $n, count($keys), PHP_EOL);
    }
}
