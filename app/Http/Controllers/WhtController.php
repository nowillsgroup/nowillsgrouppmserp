<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\WhtPeriod;
use App\Services\ActivityLogger;
use App\Services\GstService;
use App\Services\WhtService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

/** v4.4 Ghana withholding tax on supplier payments: live quote, monthly return, filing, settings and credit certificates. */
class WhtController extends Controller
{
    protected function period(Request $request): string
    {
        $p = (string) $request->input('period', '');

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $p)) {
            return $p;
        }
        $prev = bdate()->copy()->startOfMonth()->subMonth()->format('Y-m'); // the return to file next, else the running month

        return WhtService::isFiled($prev) ? bdate()->format('Y-m') : $prev;
    }

    /** JSON preview for the payment form (the server recalculates on save). */
    public function quote(Request $request)
    {
        $d = $request->validate(['supplier_id' => 'required|owned:suppliers', 'amount' => 'nullable|numeric|min:0|max:99999999', 'date' => 'nullable|date',
            'alloc' => 'nullable|array', 'alloc.*' => 'nullable|numeric|min:0']);
        $alloc = collect($d['alloc'] ?? [])->map(fn ($v) => round((float) $v, 2))->filter(fn ($v) => $v > 0)->all();
        $q = WhtService::quote(Supplier::findOrFail($d['supplier_id']), (float) ($d['amount'] ?? 0), $alloc, $d['date'] ?? bdate()->toDateString());

        return response()->json(collect($q)->only(['category', 'label', 'rate', 'base', 'wht', 'wvat', 'net', 'ytd', 'threshold', 'note', 'overridable'])->all()
            + ['wht_f' => money($q['wht']), 'wvat_f' => money($q['wvat']), 'net_f' => money($q['net']), 'base_f' => money($q['base'])]);
    }

    public function index(Request $request)
    {
        $period = $this->period($request);
        $periods = collect(range(0, 12))->map(fn ($i) => bdate()->copy()->startOfMonth()->subMonths($i)->format('Y-m'))->all();

        return view('ap.wht', ['period' => $period, 'periods' => $periods, 'r' => WhtService::monthReturn($period), 'alert' => WhtService::alert(),
            'filings' => WhtPeriod::with('filer')->orderByDesc('period')->limit(12)->get(), 'enabled' => WhtService::enabled(),
            'cfg' => collect(WhtService::DEFAULTS)->map(fn ($v, $k) => WhtService::cfg($k)), 'closed' => $period < bdate()->format('Y-m')]);
    }

    public function export(Request $request)
    {
        $period = $this->period($request);
        $rows = WhtService::csv($period);
        $safe = fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) && ! is_numeric($v) ? "'".$v : $v;
        ActivityLogger::log('ap', 'wht_export', 'WHT schedule downloaded for '.Carbon::parse($period.'-01')->format('M Y'));

        return response()->streamDownload(function () use ($rows, $safe) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $rows->isNotEmpty() ? array_keys($rows->first()) : ['Payment date', 'Payment no', 'Certificate no', 'Supplier', 'Supplier TIN', 'Nature of payment', 'Gross amount', 'Taxable (VAT-exclusive)', 'WHT rate %', 'WHT', 'Withholding VAT', 'Net paid']);
            foreach ($rows as $r) {
                fputcsv($out, array_map($safe, array_values($r)));
            }
            fclose($out);
        }, 'WHT_'.(GstService::hotelTin() ?: 'NA').'_'.str_replace('-', '', $period).'.csv', ['Content-Type' => 'text/csv']);
    }

    public function file(Request $request)
    {
        $d = $request->validate(['period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'], 'reference' => 'required|string|max:40', 'filed_on' => 'required|date|before_or_equal:today']);
        try {
            WhtService::file($d['period'], $d['reference'], $d['filed_on']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('ap.wht', ['period' => $d['period']])->with('success', 'WHT return for '.Carbon::parse($d['period'].'-01')->format('M Y').' marked as filed – its withheld payments are locked.');
    }

    public function settings(Request $request)
    {
        $d = $request->validate(['wht_enabled' => 'boolean', 'wht_threshold' => 'required|numeric|min:0|max:10000000', 'wvat_agent' => 'boolean', 'wvat_rate' => 'required|numeric|min:0|max:20']);
        $vals = ['wht_enabled' => ! empty($d['wht_enabled']) ? '1' : '0', 'wht_threshold' => (string) round((float) $d['wht_threshold'], 2),
            'wvat_agent' => ! empty($d['wvat_agent']) ? '1' : '0', 'wvat_rate' => (string) round((float) $d['wvat_rate'], 2)];
        foreach ($vals as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::$version++;
        ActivityLogger::log('ap', 'wht_settings', 'Withholding tax settings: '.($vals['wht_enabled'] === '1' ? 'on, threshold '.money($vals['wht_threshold']) : 'off').($vals['wvat_agent'] === '1' ? ", withholding VAT agent ({$vals['wvat_rate']}%)" : ''));

        return back()->with('success', 'Withholding tax settings saved.');
    }

    /** WHT credit certificate for the supplier (their evidence of tax paid on account). */
    public function certificate(SupplierPayment $spay)
    {
        abort_unless($spay->wht_cert_no, 404);
        $spay->load('supplier', 'paymentMode', 'allocations.invoice', 'creator');

        return view('ap.wht-certificate', ['p' => $spay, 'tin' => GstService::hotelTin()]);
    }
}
