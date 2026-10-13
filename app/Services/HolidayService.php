<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * v7.43 Public holidays — the gazetted Ghana calendar a hotel must run its roster, attendance and payroll against.
 *
 *  Public Holidays Act, 2001 (Act 601) as amended by the Public Holidays and Commemorative Days (Amendment) Act, 2025
 *  (Act 1142): a public holiday is a paid non-working day (Labour Act, 2003 – Act 651, s. 72), it does not eat into a
 *  worker's annual leave (s. 22) and it is not part of the rest periods (s. 43). A temporary or casual worker must be
 *  paid the full remuneration of a normal working day for every public holiday and, if he works on it, the proportion
 *  of a day's pay for the hours worked as well (s. 77) — which is the "double time" the industry applies.
 *  Commemorative days (Act 1142: Africa Unity Day, Republic Day) are kept on the calendar but are working days.
 *
 *  Dates that follow the moon (Eid al-Fitr, Eid al-Adha) and the substitute day given when a holiday falls on a
 *  weekend move with the official gazette, so every year is editable per property and the shipped table is only the
 *  default. Stored as one setting per year: hr_holidays_2026 = [[date, name, commemorative], …].
 */
class HolidayService
{
    /** Default Ghana calendar. Islamic dates and substitutes follow the gazette – edit the year before relying on it. */
    public const STATUTORY = [
        2026 => [
            ['2026-01-01', "New Year's Day"], ['2026-01-07', 'Constitution Day'], ['2026-03-06', 'Independence Day'],
            ['2026-03-20', 'Eid al-Fitr (End of Ramadan)'], ['2026-04-03', 'Good Friday'], ['2026-04-06', 'Easter Monday'],
            ['2026-05-01', "Workers' Day (May Day)"], ['2026-05-27', 'Eid al-Adha (Feast of the Sacrifice)'],
            ['2026-08-04', "Founders' Day"], ['2026-09-21', 'Kwame Nkrumah Memorial Day'], ['2026-12-04', "Farmers' Day"],
            ['2026-12-25', 'Christmas Day'], ['2026-12-26', 'Boxing Day'], ['2026-12-28', 'Boxing Day (substitute)'],
        ],
        2027 => [
            ['2027-01-01', "New Year's Day"], ['2027-01-07', 'Constitution Day'], ['2027-03-06', 'Independence Day'],
            ['2027-03-08', 'Independence Day (substitute)'], ['2027-03-09', 'Eid al-Fitr (End of Ramadan)'],
            ['2027-03-26', 'Good Friday'], ['2027-03-29', 'Easter Monday'], ['2027-05-01', "Workers' Day (May Day)"],
            ['2027-05-03', "Workers' Day (substitute)"], ['2027-05-16', 'Eid al-Adha (Feast of the Sacrifice)'],
            ['2027-08-04', "Founders' Day"], ['2027-09-21', 'Kwame Nkrumah Memorial Day'], ['2027-12-03', "Farmers' Day"],
            ['2027-12-25', 'Christmas Day'], ['2027-12-27', 'Christmas Day (substitute)'], ['2027-12-26', 'Boxing Day'],
            ['2027-12-28', 'Boxing Day (substitute)'],
        ],
    ];

    /** Act 1142 commemorative days: observed, but working days unless the property adds them as holidays. */
    public const COMMEMORATIVE = [
        2026 => [['2026-05-25', 'Africa Unity Day'], ['2026-07-01', 'Republic Day']],
        2027 => [['2027-05-25', 'Africa Unity Day'], ['2027-07-01', 'Republic Day']],
    ];

    public const DEFAULTS = [
        'hr_holiday_notify_days' => '7',   // how far ahead the payroll / roster reminder looks
        'payroll_holiday_multiplier' => '2', // s. 77: the day is already paid, so the hours worked earn (multiplier − 1) more
    ];

    public static function cfg(string $k): string
    {
        return (string) setting($k, self::DEFAULTS[$k]);
    }

    protected static function key(int $year): string
    {
        return 'hr_holidays_'.$year;
    }

    /** The stored calendar for a year, or the gazetted default when the property has not edited it. */
    public static function rows(int $year): array
    {
        $json = json_decode((string) setting(self::key($year), ''), true);
        if (is_array($json)) {
            return $json;
        }
        $out = array_map(fn ($r) => [$r[0], $r[1], false], self::STATUTORY[$year] ?? []);
        foreach (self::COMMEMORATIVE[$year] ?? [] as $r) {
            $out[] = [$r[0], $r[1], true];
        }
        usort($out, fn ($a, $b) => strcmp($a[0], $b[0]));

        return $out;
    }

    public static function isCustom(int $year): bool
    {
        return setting(self::key($year), '') !== '';
    }

    /** ['date' => Carbon, 'name' => string, 'commemorative' => bool, 'holiday' => bool] for a year, sorted. */
    public static function list(int $year): Collection
    {
        return collect(self::rows($year))
            ->map(fn ($r) => ['date' => Carbon::parse($r[0])->startOfDay(), 'name' => (string) $r[1], 'commemorative' => (bool) ($r[2] ?? false)])
            ->map(fn ($r) => $r + ['holiday' => ! $r['commemorative'], 'weekday' => $r['date']->format('l')])
            ->sortBy(fn ($r) => $r['date']->toDateString())->values();
    }

    /** Paid public holidays of a year: Y-m-d => name (commemorative days excluded). */
    public static function dates(int $year): array
    {
        return self::list($year)->where('holiday', true)->mapWithKeys(fn ($r) => [$r['date']->toDateString() => $r['name']])->all();
    }

    /** The public holiday on a date, or null (a commemorative day is not a holiday). */
    public static function on(Carbon|string|null $date): ?array
    {
        if (! $date) {
            return null;
        }
        $d = $date instanceof Carbon ? $date->copy() : Carbon::parse($date);

        return self::list((int) $d->format('Y'))->first(fn ($r) => $r['date']->toDateString() === $d->toDateString() && $r['holiday']);
    }

    public static function isHoliday(Carbon|string|null $date): bool
    {
        return self::on($date) !== null;
    }

    /** Public holidays inside a date range (both ends included): Y-m-d => name. */
    public static function inRange(Carbon $from, Carbon $to): array
    {
        $out = [];
        for ($year = (int) $from->format('Y'); $year <= (int) $to->format('Y'); $year++) {
            foreach (self::dates($year) as $d => $name) {
                if ($d >= $from->toDateString() && $d <= $to->toDateString()) {
                    $out[$d] = $name;
                }
            }
        }
        ksort($out);

        return $out;
    }

    public static function upcoming(int $days = 30): Collection
    {
        $today = bdate()->copy()->startOfDay();
        $to = $today->copy()->addDays(max(1, $days));

        return collect(self::inRange($today, $to))->map(fn ($name, $d) => ['date' => Carbon::parse($d), 'name' => $name,
            'days' => (int) $today->diffInDays(Carbon::parse($d))])->values();
    }

    /** Save a whole year's calendar: [['date' => Y-m-d, 'name' => string, 'commemorative' => bool], …]. */
    public static function save(int $year, array $rows): int
    {
        if ($year < 2000 || $year > 2100) {
            throw new RuntimeException('Choose a year between 2000 and 2100.');
        }
        $clean = [];
        foreach ($rows as $r) {
            $date = trim((string) ($r['date'] ?? ''));
            $name = trim((string) ($r['name'] ?? ''));
            if ($date === '' && $name === '') {
                continue;
            }
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || Carbon::parse($date)->format('Y') !== (string) $year) {
                throw new RuntimeException("{$name}: the date must be a day of {$year} (YYYY-MM-DD).");
            }
            if ($name === '') {
                throw new RuntimeException(dmy(Carbon::parse($date)).': every holiday needs a name.');
            }
            $clean[$date] = [$date, mb_substr($name, 0, 90), ! empty($r['commemorative'])];
        }
        ksort($clean);
        Setting::updateOrCreate(['key' => self::key($year)], ['value' => json_encode(array_values($clean))]);
        ActivityLogger::log('hr', 'holidays', count($clean)." public holiday(s) saved for {$year}");

        return count($clean);
    }

    /** Back to the gazetted default for the year. */
    public static function reset(int $year): void
    {
        Setting::where('key', self::key($year))->delete();
        ActivityLogger::log('hr', 'holidays', "Public holidays for {$year} reset to the gazetted Ghana calendar");
    }

    /** Years the calendar can be edited in: last year, this year and next. */
    public static function years(): array
    {
        $y = (int) bdate()->format('Y');

        return [$y - 1, $y, $y + 1];
    }

    /** Holiday facts for one payroll period: days in it and the employees who worked on them. */
    public static function payrollNote(Carbon $periodStart, Carbon $periodEnd): array
    {
        $in = self::inRange($periodStart, $periodEnd);

        return ['count' => count($in), 'days' => $in,
            'text' => $in ? count($in).' public holiday(s) fall in this period ('.implode('; ', array_map(fn ($n, $d) => dmy(Carbon::parse($d)).' '.$n, $in, array_keys($in))).') – each is a paid day (Act 651 s. 72) and does not use annual leave (s. 22).' : 'No public holiday falls in this period.',
        ];
    }
}
