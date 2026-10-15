<?php
/**
 * Dump the live config('pms.menu') registry as the module inventory docs/MODULES.md has to match.
 *
 * The shape is: 12 top-level groups, each with a `section` key (5 distinct values), each holding
 * `children` modules; a module is either a direct link (it carries `route`) or a page list (it carries
 * `children` of its own). A page is any node below module level that carries a route, and a module that
 * is itself a link counts as a module, not a page — the rule the document states, applied literally.
 *
 * Two outputs, one source of truth:
 *   TINKER='require "/app/deploy/dev/audit/menu_inventory.php";' deploy/dev/bin/php -f deploy/dev/tinker.php
 *   MENU_JSON=1 …the same command… > /tmp/menu_live.json      (what modules_doc_check.py reads)
 */

$menu = config('pms.menu');
$tree = [];
$noperm = [];

foreach ($menu as $group) {
    $g = ['label' => $group['label'] ?? '(unlabelled)', 'section' => $group['section'] ?? '?', 'modules' => []];
    foreach ($group['children'] ?? [] as $mod) {
        $label = $mod['label'] ?? '(unlabelled)';
        if (! empty($mod['route'])) {
            $g['modules'][] = ['label' => $label, 'link' => $mod['route'], 'perm' => $mod['perm'] ?? null, 'pages' => []];
            if (empty($mod['perm'])) {
                $noperm[] = $label;
            }

            continue;
        }
        $m = ['label' => $label, 'link' => null, 'perm' => $mod['perm'] ?? null, 'pages' => []];
        foreach ($mod['children'] ?? [] as $kid) {
            if (empty($kid['route'])) {
                continue;                       // a divider or a label with nothing behind it
            }
            $m['pages'][] = ['label' => $kid['label'] ?? $kid['route'], 'route' => $kid['route'], 'perm' => $kid['perm'] ?? null];
            if (empty($kid['perm'])) {
                $noperm[] = $label.' › '.($kid['label'] ?? $kid['route']);
            }
        }
        $g['modules'][] = $m;
    }
    $tree[] = $g;
}

$out = [
    'version' => config('pms.version'),
    'sections' => count(array_unique(array_column($tree, 'section'))),
    'groups' => count($tree),
    'modules' => count(array_merge(...array_map(fn ($g) => $g['modules'], $tree))),
    'pages' => array_sum(array_map(fn ($g) => array_sum(array_map(fn ($m) => count($m['pages']), $g['modules'])), $tree)),
    'no_perm' => $noperm,
    'tree' => $tree,
];

if (getenv('MENU_JSON')) {
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;

    return;
}

foreach ($tree as $g) {
    printf("GROUP %s  [%s]%s", $g['label'], $g['section'], PHP_EOL);
    foreach ($g['modules'] as $m) {
        if ($m['link']) {
            printf("  MODULE %-34s direct link   route=%-32s perm=%s%s", $m['label'], $m['link'], $m['perm'] ?? '(none)', PHP_EOL);

            continue;
        }
        printf("  MODULE %-34s %2d page(s)%s", $m['label'], count($m['pages']), PHP_EOL);
        printf("      %s%s", implode(' · ', array_column($m['pages'], 'label')), PHP_EOL);
    }
    printf("%s", PHP_EOL);
}

printf("TOTALS sections=%d groups=%d modules=%d pages=%d · config('pms.version') = %s%s",
    $out['sections'], $out['groups'], $out['modules'], $out['pages'], $out['version'], PHP_EOL);
if ($noperm) {
    printf("nodes with no perm key (%d): %s%s", count($noperm), implode(' | ', $noperm), PHP_EOL);
}
