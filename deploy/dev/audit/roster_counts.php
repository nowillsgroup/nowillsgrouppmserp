<?php
// v7.44 — roster row/duplicate counts for one department week. DEPT + WEEK come from the environment.
$dept = (int) (getenv('DEPT') ?: 9);
$week = getenv('WEEK') ?: '2026-10-12';
$rows = (int) DB::selectOne('SELECT count(*) n FROM roster_shifts s JOIN employees e ON e.id = s.employee_id
    WHERE e.department_id = ? AND date(s.date) >= ? AND date(s.date) <= date(?, "+6 days")', [$dept, $week, $week])->n;
$dups = (int) DB::selectOne('SELECT count(*) n FROM (SELECT s.employee_id, date(s.date) d FROM roster_shifts s
    JOIN employees e ON e.id = s.employee_id WHERE e.department_id = ? GROUP BY 1, 2 HAVING count(*) > 1) x', [$dept])->n;
$tot = (int) DB::table('roster_shifts')->count();
echo json_encode(['week_rows' => $rows, 'dup_groups' => $dups, 'roster_shifts_total' => $tot]).PHP_EOL;
