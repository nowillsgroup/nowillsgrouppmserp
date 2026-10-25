<?php

/**
 * Regression harness for the 20 Oct 2026 navigation/RBAC remediation.
 *
 * Verify the newly exposed pages are real named routes, appear in the sidebar with
 * their intended employee/permission gates, and use permissions declared, seeded,
 * and granted to the built-in front-office roles.
 *
 * Run after `php artisan pms:upgrade`:
 *   TINKER='require "/app/deploy/dev/audit/navigation_permissions.php";' deploy/dev/bin/php -f deploy/dev/tinker.php
 */

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\Route;

$checks = 0;
$fails = [];
$check = function (string $label, bool $ok) use (&$checks, &$fails): void {
    $checks++;
    if (! $ok) {
        $fails[] = $label;
    }
};

$declared = [];
foreach (config('pms.permissions', []) as $permissions) {
    foreach (array_keys($permissions) as $key) {
        $declared[] = $key;
    }
}
$declared = array_values(array_unique($declared));
$seeded = Permission::query()->pluck('key')->all();

$expected = [
    'offline.desktop.workspace' => ['offline.desktop.view', false],
    'rentals.index' => ['rentals.view', false],
    'rentals.fleet' => ['rentals.view', false],
    'hr.ess' => ['hr.manage', false],
    'me.profile' => ['hr.self', true],
    'me.documents' => ['hr.self', true],
    'me.announcements' => ['hr.self', true],
    'billingrec.index' => ['billingrec.view', false],
];

$menuRoutes = [];
$collect = function (array $items) use (&$collect, &$menuRoutes): void {
    foreach ($items as $item) {
        if (! empty($item['route'])) {
            $menuRoutes[$item['route']] = $item;
        }
        if (! empty($item['children'])) {
            $collect($item['children']);
        }
    }
};
$collect(config('pms.menu', []));

foreach ($expected as $routeName => [$permission, $employeeOnly]) {
    $item = $menuRoutes[$routeName] ?? null;
    $check($routeName.' exists as a named route', Route::has($routeName));
    $check($routeName.' appears in the sidebar', is_array($item));
    $check($routeName.' sidebar permission is '.$permission, is_array($item) && ($item['perm'] ?? null) === $permission);
    $check($routeName.' employee visibility flag is correct', is_array($item) && (bool) ($item['employee'] ?? false) === $employeeOnly);

    $check($permission.' is declared in config/pms.php', in_array($permission, $declared, true));
    $check($permission.' is seeded in the permissions table', in_array($permission, $seeded, true));

    $route = Route::getRoutes()->getByName($routeName);
    $middleware = $route?->getAction('middleware') ?? [];
    $check($routeName.' route middleware requires '.$permission, in_array('perm:'.$permission, $middleware, true));
}

$moreRoutes = [
    'offline.desktop.pack' => 'offline.desktop.view',
    'offline.desktop.delta' => 'offline.desktop.view',
    'offline.desktop.sync' => 'offline.desktop.sync',
    'rentals.store' => 'rentals.manage',
    'rentals.vehicles.store' => 'rentals.manage',
    'rentals.pickup' => 'rentals.manage',
    'rentals.return' => 'rentals.manage',
    'rentals.bill' => 'rentals.manage',
    'hr.ess.decide' => 'hr.manage',
    'hr.ess.doc' => 'hr.manage',
    'hr.ess.announce' => 'hr.manage',
    'me.documents.upload' => 'hr.self',
    'billingrec.store' => 'billingrec.manage',
    'billingrec.refresh' => 'billingrec.manage',
    'billingrec.items.update' => 'billingrec.manage',
    'billingrec.items.evidence' => 'billingrec.view',
    'billingrec.finalise' => 'billingrec.approve',
    'billingrec.reopen' => 'billingrec.approve',
];
foreach ($moreRoutes as $routeName => $permission) {
    $route = Route::getRoutes()->getByName($routeName);
    $middleware = $route?->getAction('middleware') ?? [];
    $check($routeName.' exists and requires '.$permission, $route !== null && in_array('perm:'.$permission, $middleware, true));
}

$rolePermissions = ['rentals.view', 'rentals.manage', 'offline.desktop.view', 'offline.desktop.sync'];
foreach (['Administrator', 'Front Office Manager', 'Receptionist'] as $roleName) {
    $role = Role::query()->with('permissions')->where('name', $roleName)->first();
    $have = $role?->permissions->pluck('key')->all() ?? [];
    foreach ($rolePermissions as $permission) {
        $check($roleName.' receives '.$permission.' after pms:upgrade', in_array($permission, $have, true));
    }
}
$accountant = Role::query()->with('permissions')->where('name', 'Accountant')->first();
$accountantHave = $accountant?->permissions->pluck('key')->all() ?? [];
foreach (['billingrec.view', 'billingrec.manage', 'billingrec.approve'] as $permission) {
    $check('Accountant receives '.$permission.' after pms:upgrade', in_array($permission, $accountantHave, true));
}

echo 'Navigation/RBAC regression checks: '.$checks.'; passed '.($checks - count($fails)).'; failed '.count($fails)."\n";
foreach ($fails as $failure) {
    echo 'FAIL: '.$failure."\n";
}

if ($fails) {
    throw new RuntimeException('Navigation/RBAC regression checks failed.');
}
