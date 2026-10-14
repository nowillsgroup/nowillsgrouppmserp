<?php
// v7.44 — what shape does each DATE column actually store?
// SQLite is dynamically typed: a `date` column holds exactly what the writer passed.
// Eloquent with a `date` cast writes "Y-m-d"; a service/seeder assigning a Carbon
// (or DB::table()->insert) writes "Y-m-d 00:00:00". Both are "valid" to SQLite, and
// a string comparison against a date-only bound then silently misses rows.
$map = [];
foreach (DB::select("SELECT m.name AS tbl, p.name AS col, p.type AS ty FROM sqlite_master m, pragma_table_info(m.name) p WHERE m.type='table' AND p.type IN ('date') ORDER BY m.name, p.cid") as $r) {
    $n = (int) DB::table($r->tbl)->whereNotNull($r->col)->where($r->col, '!=', '')->count();
    if (! $n) { continue; }
    $shapes = [];
    foreach (DB::select('SELECT length('.$r->col.') AS l, count(*) AS c FROM '.$r->tbl.' WHERE '.$r->col." IS NOT NULL AND ".$r->col." != '' GROUP BY 1 ORDER BY 1") as $s) {
        $shapes[(string) $s->l] = (int) $s->c;
    }
    // PHP turns the numeric string key "10" into int 10, so compare loosely.
    $kind = count($shapes) > 1 ? 'MIXED' : (((int) array_key_first($shapes)) === 10 ? 'date-only' : 'has-time');
    $map[$r->tbl.'.'.$r->col] = ['rows' => $n, 'kind' => $kind, 'shapes' => $shapes];
}
file_put_contents('/app/storage/logs/date-shapes.json', json_encode($map, JSON_PRETTY_PRINT));
$mixed = array_filter($map, fn ($v) => $v['kind'] === 'MIXED');
$only  = array_filter($map, fn ($v) => $v['kind'] === 'date-only');
$has   = array_filter($map, fn ($v) => $v['kind'] === 'has-time');
printf("DATE columns in non-empty tables: %d   date-only: %d   has-time: %d   MIXED: %d%s", count($map), count($only), count($has), count($mixed), PHP_EOL);
foreach ($mixed as $k => $v) { printf("  MIXED %-46s %s%s", $k, json_encode($v['shapes']), PHP_EOL); }
