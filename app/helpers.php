<?php

use App\Models\Setting;
use App\Support\Tenant;
use Illuminate\Support\Carbon;

if (! function_exists('hotel')) {
    function hotel(): ?App\Models\Hotel
    {
        return Tenant::hotel();
    }
}

if (! function_exists('bdate')) {
    /** Current PMS business date for the active hotel. */
    function bdate(): Carbon
    {
        return Tenant::hotel()?->business_date?->copy() ?? Carbon::today();
    }
}

if (! function_exists('money')) {
    function money($amount, bool $symbol = true): string
    {
        $s = number_format((float) $amount, 2);

        return $symbol ? (Tenant::hotel()?->currency_symbol ?? 'GH₵').' '.$s : $s;
    }
}

if (! function_exists('money_short')) {
    /** Compact money for stat cards: GH₵ 6.98M / GH₵ 845.2K; below 100K the full amount. */
    function money_short($amount): string
    {
        $a = (float) $amount;
        $sym = Tenant::hotel()?->currency_symbol ?? 'GH₵';
        $abs = abs($a);
        if ($abs >= 1_000_000) {
            return $sym.' '.rtrim(rtrim(number_format($a / 1_000_000, 2), '0'), '.').'M';
        }
        if ($abs >= 100_000) {
            return $sym.' '.rtrim(rtrim(number_format($a / 1_000, 1), '0'), '.').'K';
        }

        return money($a);
    }
}

if (! function_exists('setting')) {
    function setting(string $key, $default = null)
    {
        static $cache = [];
        $hid = Tenant::id();
        if (! $hid) {
            return $default;
        }
        $hid .= ':'.Setting::$version;
        if (! isset($cache[$hid])) {
            $cache[$hid] = Setting::pluck('value', 'key')->all();
        }

        return $cache[$hid][$key] ?? $default;
    }
}

if (! function_exists('can_do')) {
    function can_do(string $perm): bool
    {
        return (bool) auth()->user()?->hasPermission($perm);
    }
}

if (! function_exists('dmy')) {
    function dmy($date, string $fmt = 'd M Y'): string
    {
        return $date ? Carbon::parse($date)->format($fmt) : '—';
    }
}

if (! function_exists('status_badge')) {
    function status_badge(?string $status): string
    {
        $map = [
            'confirmed' => 'blue', 'tentative' => 'amber', 'checked_in' => 'green', 'checked_out' => 'slate',
            'cancelled' => 'red', 'no_show' => 'red', 'reserved' => 'blue', 'open' => 'amber', 'closed' => 'slate',
            'vacant' => 'green', 'occupied' => 'red', 'out_of_order' => 'slate', 'out_of_service' => 'slate',
            'clean' => 'green', 'dirty' => 'red', 'inspected' => 'blue', 'touch_up' => 'amber',
            'pending' => 'amber', 'in_progress' => 'blue', 'completed' => 'green', 'resolved' => 'green',
            'billed' => 'blue', 'settled' => 'green', 'void' => 'red', 'draft' => 'amber', 'approved' => 'blue', 'received' => 'green',
            'enquiry' => 'amber', 'stored' => 'amber', 'returned' => 'green', 'disposed' => 'slate', 'active' => 'green', 'inactive' => 'slate', 'definite' => 'green', 'lost' => 'slate', 'merged' => 'slate', 'preparing' => 'amber', 'ready' => 'green', 'served' => 'slate', 'new' => 'blue', 'due' => 'amber', 'paid' => 'green', 'posted' => 'green', 'released' => 'slate', 'stopped' => 'slate', 'acknowledged' => 'purple', 'done' => 'green', 'declined' => 'red', 'on_hold' => 'red', 'partial' => 'amber', 'rejected' => 'red', 'recovered' => 'slate', 'recovering' => 'amber', 'submitted' => 'purple', 'issued' => 'green', 'scheduled' => 'purple', 'sending' => 'blue', 'sent' => 'green', 'failed' => 'red', 'skipped' => 'slate', 'suspended' => 'red', 'expired' => 'slate', 'upcoming' => 'blue', 'hold' => 'red', 'unverified' => 'slate', 'contained' => 'amber', 'notified' => 'blue', 'restricted' => 'amber', 'erased' => 'slate', 'opened' => 'blue', 'ignored' => 'slate', 'reconciled' => 'green', 'matched' => 'green', 'unmatched' => 'amber', 'agreed' => 'green',
            'awarded' => 'green', 'quoted' => 'green', 'viewed' => 'purple', 'invited' => 'blue', 'future' => 'purple', // v4.8
            'queued' => 'amber', 'printing' => 'blue', 'printed' => 'green', // v7.6 KOT printers
        ];
        $c = $map[$status] ?? 'slate';

        return '<span class="badge badge-'.$c.'">'.e(ucwords(str_replace('_', ' ', (string) $status))).'</span>';
    }
}

if (! function_exists('amount_words')) {
    /** 700,893.99 → "Seven hundred thousand eight hundred ninety-three and 99/100" (international grouping). */
    function amount_words(float $amount): string
    {
        $ones = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
        $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
        $three = function (int $n) use ($ones, $tens): string {
            $w = [];
            if ($n >= 100) {
                $w[] = $ones[intdiv($n, 100)].' hundred';
                $n %= 100;
            }
            if ($n >= 20) {
                $w[] = $tens[intdiv($n, 10)].($n % 10 ? '-'.$ones[$n % 10] : '');
            } elseif ($n > 0) {
                $w[] = $ones[$n];
            }

            return implode(' ', $w);
        };
        $amount = round(abs($amount), 2);
        $int = (int) floor($amount);
        $cents = (int) round(($amount - $int) * 100);
        if ($int === 0) {
            $words = 'zero';
        } else {
            $parts = [];
            foreach ([1000000000 => 'billion', 1000000 => 'million', 1000 => 'thousand', 1 => ''] as $div => $label) {
                if ($int >= $div) {
                    $parts[] = trim($three(intdiv($int, $div)).' '.$label);
                    $int %= $div;
                }
            }
            $words = implode(' ', $parts);
        }

        return ucfirst($words).($cents ? ' and '.str_pad((string) $cents, 2, '0', STR_PAD_LEFT).'/100' : '').' only';
    }
}

if (! function_exists('sentiment_badge')) {
    /** v3.1 sentiment chip. */
    function sentiment_badge(?string $s): string
    {
        if (! $s) {
            return '<span class="muted">–</span>';
        }
        $c = ['positive' => 'green', 'neutral' => 'slate', 'negative' => 'red', 'mixed' => 'amber'][$s] ?? 'slate';

        return '<span class="badge badge-'.$c.'">'.e(ucfirst($s)).'</span>';
    }
}

if (! function_exists('nps_badge')) {
    /** v3.1 recommend score chip (0-6 detractor, 7-8 passive, 9-10 promoter). */
    function nps_badge(?int $n): string
    {
        if ($n === null) {
            return '<span class="muted">–</span>';
        }
        $c = $n >= 9 ? 'green' : ($n >= 7 ? 'amber' : 'red');
        $t = $n >= 9 ? 'Promoter' : ($n >= 7 ? 'Passive' : 'Detractor');

        return '<span class="badge badge-'.$c.'" title="'.$t.'">'.$n.'</span>';
    }
}

if (! function_exists('stars')) {
    function stars($r, int $of = 5): string
    {
        if ($r === null || $r === '') {
            return '<span class="muted">–</span>';
        }
        $full = (int) round((float) $r);

        return '<span class="text-amber" title="'.e(rtrim(rtrim(number_format((float) $r, 1), '0'), '.')).' / '.$of.'">'.str_repeat('★', max(0, min($of, $full))).'<span style="color:#cbd5e1">'.str_repeat('★', max(0, $of - $full)).'</span></span>';
    }
}

if (! function_exists('tb_badge')) {
    /** v3.2 table reservation status badge. */
    function tb_badge(?string $status): string
    {
        [$c, $l] = [
            'booked' => ['amber', 'Unconfirmed'], 'confirmed' => ['blue', 'Confirmed'], 'seated' => ['green', 'Seated'], 'completed' => ['slate', 'Completed'],
            'cancelled' => ['red', 'Cancelled'], 'no_show' => ['red', 'No-show'], 'waiting' => ['amber', 'Waiting'], 'notified' => ['purple', 'Called'], 'left' => ['slate', 'Left'],
        ][$status] ?? ['slate', ucfirst((string) $status)];

        return '<span class="badge badge-'.$c.'">'.e($l).'</span>';
    }
}

if (! function_exists('tr_badge')) {
    /** v3.3 transport trip status badge. */
    function tr_badge(?string $status): string
    {
        $tone = \App\Models\TransportTrip::TONES[$status] ?? 'slate';

        return '<span class="badge badge-'.$tone.'">'.e(\App\Models\TransportTrip::STATUSES[$status] ?? ucfirst((string) $status)).'</span>';
    }
}

if (! function_exists('paper_chip')) {
    /** v3.3 compliance date chip: red expired, amber within $days, green valid, grey not recorded. */
    function paper_chip(string $label, $date, int $days = 30): string
    {
        if (! $date) {
            return '<span class="paper none" title="Not recorded">'.e($label).' –</span>';
        }
        $d = \Illuminate\Support\Carbon::parse($date)->startOfDay();
        $cls = $d->lt(now()->startOfDay()) ? 'exp' : ($d->lte(now()->startOfDay()->addDays($days)) ? 'due' : 'ok');

        return '<span class="paper '.$cls.'" title="'.e($label).' valid until '.$d->format('d M Y').'">'.e($label).' '.$d->format('d M y').'</span>';
    }
}

if (! function_exists('formc_badge')) {
    /** v3.4 Form C state chip: overdue | due | submitted | departure | done | exempt */
    function formc_badge(string $state): string
    {
        [$tone, $label] = match ($state) {
            'overdue' => ['red', 'Overdue'], 'due' => ['amber', 'To submit'], 'departure' => ['blue', 'Departure to report'],
            'done' => ['green', 'Closed'], 'exempt' => ['slate', 'Exempt'], default => ['green', 'Submitted'],
        };

        return '<span class="badge badge-'.$tone.'" data-state="'.$state.'">'.$label.'</span>';
    }
}

if (! function_exists('ordinal_day')) {
    /** 1 → 1st, 2 → 2nd, 11 → 11th, 23 → 23rd (v5.4). */
    function ordinal_day(int $n): string
    {
        $s = in_array($n % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');

        return $n.$s;
    }
}
