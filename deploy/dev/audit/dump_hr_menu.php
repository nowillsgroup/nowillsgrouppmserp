<?php

/**
 * Regenerate the HR menu fixtures used by the HR completeness audit from the live registry.
 *
 *   TINKER='require "/app/deploy/dev/audit/dump_hr_menu.php";' deploy/dev/bin/php -f deploy/dev/tinker.php
 */

$section = collect(config('pms.menu'))->firstWhere('label', 'HR & Workforce');
if (! $section) {
    throw new RuntimeException('The HR & Workforce menu group is missing.');
}

$menu = [];
$pages = [];
$routes = app('router')->getRoutes();

foreach ($section['children'] ?? [] as $module) {
    $row = ['module' => $module['label'], 'pages' => []];

    foreach ($module['children'] ?? [] as $page) {
        $name = $page['route'] ?? null;
        if (! $name) {
            continue;
        }
        $route = $routes->getByName($name);
        if (! $route) {
            throw new RuntimeException('HR menu route is not registered: '.$name);
        }

        $params = $page['params'] ?? null;
        $path = route($name, $params ?? [], false);
        $path = parse_url($path, PHP_URL_PATH) ?: '/';
        $action = $route->getActionName();
        $action = str_contains($action, '@') ? substr($action, strrpos($action, '@') + 1) : $action;

        $row['pages'][] = [
            'label' => $page['label'] ?? $name,
            'route' => $name,
            'params' => $params,
            'perm' => $page['perm'] ?? null,
            'badge' => $page['badge'] ?? null,
            'employee' => (bool) ($page['employee'] ?? false),
        ];
        $pages[] = [
            'module' => $module['label'],
            'label' => $page['label'] ?? $name,
            'uri' => $path,
            'action' => $action,
            'perm' => $page['perm'] ?? null,
            'badge' => $page['badge'] ?? null,
        ];
    }

    $menu[] = $row;
}

$base = '/app/deploy/dev/audit/';
file_put_contents($base.'menu-hr.json', json_encode($menu, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
file_put_contents($base.'hr_pages.json', json_encode($pages, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

echo 'Wrote '.count($pages).' HR sidebar pages across '.count($menu).' modules to menu-hr.json and hr_pages.json.'.PHP_EOL;
