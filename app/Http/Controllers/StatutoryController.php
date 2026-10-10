<?php

namespace App\Http\Controllers;

use App\Models\PaymentMode;
use App\Models\Setting;
use App\Models\StatutoryRemittance;
use App\Services\ActivityLogger;
use App\Services\StatutoryService as Stat;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

/** v4.5 Payroll › Statutory returns: SSNIT Tier 1, Tier 2 and PAYE per payroll month – schedules, exports, remittances. */
class StatutoryController extends Controller
{
    protected function period(Request $request): string
    {
        $p = (string) $request->input('period', '');
        if (Stat::validPeriod($p)) {
            return $p;
        }
        // default: the latest approved payroll month that still has something to remit, else the last month
        $prev = bdate()->copy()->startOfMonth()->subMonthNoOverflow()->format('Y-m');
        foreach ([3, 2, 1] as $back) {
            $m = bdate()->copy()->startOfMonth()->subMonthsNoOverflow($back)->format('Y-m');
            if (collect(Stat::overview($m))->contains(fn ($r) => in_array($r['status'], ['due', 'overdue'], true))) {
                return $m;
            }
        }

        return $prev;
    }

    public function index(Request $request)
    {
        $period = $this->period($request);
        $tab = array_key_exists($request->tab, Stat::KINDS) ? $request->tab : 'ssnit';
        $periods = collect(range(0, 12))->map(fn ($i) => bdate()->copy()->startOfMonth()->subMonthsNoOverflow($i)->format('Y-m'))->all();

        return view('payroll.statutory', [
            'period' => $period, 'periods' => $periods, 'tab' => $tab, 'o' => Stat::overview($period), 'alerts' => Stat::alerts(),
            'belowMin' => Stat::belowMinimumWage(), 'minWage' => Stat::minWageMonthly(),
            'history' => StatutoryRemittance::with('paymentMode', 'creator')->latest('paid_on')->latest('id')->limit(30)->get(),
            'modes' => PaymentMode::where('is_active', true)->whereNotIn('type', ['city_ledger', 'gift_voucher', 'forex', 'online'])->orderBy('name')->pluck('name', 'id'),
            'cfg' => collect(Stat::DEFAULTS)->map(fn ($v, $k) => Stat::cfg($k))->all(),
        ]);
    }

    public function export(Request $request, string $kind)
    {
        abort_unless(isset(Stat::KINDS[$kind]), 404);
        $period = $this->period($request);
        [$name, $head, $rows] = Stat::csv($kind, $period);
        $safe = fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) && ! is_numeric($v) ? "'".$v : $v;
        ActivityLogger::log('payroll', 'stat_export', Stat::label($kind).' schedule downloaded for '.Carbon::parse($period.'-01')->format('M Y'));

        return response()->streamDownload(function () use ($head, $rows, $safe) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $head);
            foreach ($rows as $r) {
                fputcsv($out, array_map($safe, $r));
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
    }

    public function remit(Request $request)
    {
        $d = $request->validate([
            'kind' => 'required|in:'.implode(',', array_keys(Stat::KINDS)), 'period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'paid_on' => 'required|date', 'payment_mode_id' => 'required|owned:payment_modes', 'reference' => 'required|string|max:60', 'notes' => 'nullable|string|max:255',
        ]);
        try {
            $r = Stat::remit($d['kind'], $d['period'], $d);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('payroll.statutory', ['period' => $d['period'], 'tab' => $d['kind']])
            ->with('success', Stat::label($d['kind']).' '.Carbon::parse($d['period'].'-01')->format('M Y').' recorded as paid: '.money($r->amount)." ({$r->reference}).");
    }

    public function cancel(Request $request, StatutoryRemittance $srem)
    {
        $d = $request->validate(['reason' => 'required|string|max:255']);
        try {
            Stat::cancel($srem, $d['reason']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Remittance {$srem->remit_no} cancelled – the ledger entry is reversed.");
    }

    public function settings(Request $request)
    {
        $d = $request->validate([
            'stat_ssnit_employer_no' => 'nullable|string|max:30', 'stat_tier2_trustee' => 'nullable|string|max:120', 'stat_tier2_scheme' => 'nullable|string|max:40',
            'payroll_min_wage_daily' => 'required|numeric|min:0|max:10000', 'payroll_min_wage_days' => 'required|integer|min:1|max:31',
            'stat_insurable_cap' => 'required|numeric|min:0|max:10000000',
        ]);
        foreach ($d as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => (string) ($v ?? '')]);
        }
        Setting::$version++;
        ActivityLogger::log('payroll', 'stat_settings', 'Statutory return settings updated');

        return back()->with('success', 'Statutory return settings saved.');
    }
}
