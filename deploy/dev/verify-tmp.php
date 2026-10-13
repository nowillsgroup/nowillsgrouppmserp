<?php
$pdo = \Illuminate\Support\Facades\DB::connection()->getPdo();
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
$mixed = [];
foreach ($tables as $tb) {
    $cols = $pdo->query("PRAGMA table_info('$tb')")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        $col = $c['name'];
        if (!str_ends_with($col, '_date') && $col !== 'business_date') { continue; }
        $tot = (int) $pdo->query("SELECT count(*) FROM \"$tb\" WHERE \"$col\" IS NOT NULL")->fetchColumn();
        if ($tot === 0) { continue; }
        $dt = (int) $pdo->query("SELECT count(*) FROM \"$tb\" WHERE \"$col\" LIKE '% %'")->fetchColumn();
        if ($dt > 0 && $dt < $tot) { $mixed[] = "MIXED $tb.$col  ($dt/$tot datetime-form, ".($tot - $dt)." date-only)"; }
    }
}
echo implode(PHP_EOL, $mixed), PHP_EOL, 'total flagged: ', count($mixed), PHP_EOL;
