<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pure-PHP SQL dump (no mysqldump binary required — works on shared hosting).
 *  - full():  schema + data of the whole database (group admin / CLI only).
 *  - hotel(): INSERT-only export of one property's rows (tenant-safe).
 */
class BackupService
{
    public const SKIP = ['cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'sessions', 'password_reset_tokens'];

    /** Child tables without hotel_id: [parent table, foreign key]. */
    public const CHILDREN = ['pos_order_items' => ['pos_orders', 'pos_order_id'], 'purchase_order_items' => ['purchase_orders', 'purchase_order_id'], 'permission_role' => ['roles', 'role_id']];

    public static function dir(): string
    {
        $dir = storage_path('app/backups');
        if (! is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        return $dir;
    }

    public static function tables(): array
    {
        return collect(Schema::getTableListing())->map(fn ($t) => str_contains($t, '.') ? explode('.', $t, 2)[1] : $t)
            ->reject(fn ($t) => in_array($t, self::SKIP))->values()->all();
    }

    public static function full(?string $file = null): string
    {
        $file ??= self::dir().'/full_'.now()->format('Ymd_His').'.sql';
        $fh = fopen($file, 'w');
        $mysql = DB::getDriverName() !== 'sqlite';
        fwrite($fh, "-- Nowills PMS full backup\n-- Generated ".now()->toDateTimeString()."\nSET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n\n");
        foreach (self::tables() as $t) {
            if ($mysql) {
                $create = (array) DB::selectOne('SHOW CREATE TABLE `'.$t.'`');
                fwrite($fh, "DROP TABLE IF EXISTS `$t`;\n".($create['Create Table'] ?? array_values($create)[1]).";\n\n");
            }
            self::rows($fh, $t, DB::table($t));
        }
        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fh);

        return $file;
    }

    public static function hotel(int $hotelId, ?string $file = null): string
    {
        $file ??= self::dir().'/hotel'.$hotelId.'_'.now()->format('Ymd_His').'.sql';
        $fh = fopen($file, 'w');
        fwrite($fh, "-- Nowills PMS property data export (hotel #$hotelId)\n-- Generated ".now()->toDateTimeString()."\n-- Import into an existing Nowills PMS schema.\nSET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n\n");
        foreach (self::tables() as $t) {
            if ($t === 'migrations' || $t === 'hotel_groups') {
                continue;
            }
            if ($t === 'hotels') {
                $q = DB::table($t)->where('id', $hotelId);
            } elseif ($t === 'permissions') {
                $q = DB::table($t);
            } elseif (isset(self::CHILDREN[$t])) {
                [$parent, $fk] = self::CHILDREN[$t];
                $q = DB::table($t)->whereIn($fk, DB::table($parent)->where('hotel_id', $hotelId)->select('id'));
            } elseif (Schema::hasColumn($t, 'hotel_id')) {
                $q = DB::table($t)->where('hotel_id', $hotelId);
            } else {
                continue;
            }
            self::rows($fh, $t, $q, $t === 'users' ? ['remember_token'] : []);
        }
        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fh);

        return $file;
    }

    protected static function rows($fh, string $table, $query, array $blank = []): void
    {
        $pdo = DB::connection()->getPdo();
        $cols = Schema::getColumnListing($table);
        $order = in_array('id', $cols) ? 'id' : $cols[0];
        $query->orderBy($order)->chunk(500, function ($rows) use ($fh, $table, $pdo, $blank) {
            $vals = [];
            foreach ($rows as $r) {
                $r = (array) $r;
                foreach ($blank as $b) {
                    if (array_key_exists($b, $r)) {
                        $r[$b] = null;
                    }
                }
                $vals[] = '('.implode(',', array_map(fn ($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? $v : $pdo->quote((string) $v)), $r)).')';
                $cols = array_keys($r);
            }
            if ($vals) {
                fwrite($fh, 'INSERT INTO `'.$table.'` (`'.implode('`,`', $cols).'`) VALUES'."\n".implode(",\n", $vals).";\n");
            }
        });
        fwrite($fh, "\n");
    }

    /** Remove backups older than N days. */
    public static function prune(int $days = 14): int
    {
        $n = 0;
        foreach (glob(self::dir().'/*.sql') as $f) {
            if (filemtime($f) < now()->subDays($days)->getTimestamp()) {
                @unlink($f);
                $n++;
            }
        }

        return $n;
    }
}
