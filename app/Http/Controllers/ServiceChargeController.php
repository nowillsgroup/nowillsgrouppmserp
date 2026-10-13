<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\PaymentMode;
use App\Models\PayrollRun;
use App\Models\ScDistribution;
use App\Models\ScDistributionLine;
use App\Models\Setting;
use App\Models\TipCollection;
use App\Services\ActivityLogger;
use App\Services\LedgerService;
use App\Services\ServiceChargeService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use RuntimeException;

/** v4.9 Accounts/HR › Service charge & tips: the tronc pool, tips register, distributions and staff points. */
class ServiceChargeController extends Controller
{
    protected function attempt(callable $fn, string|callable $ok)
    {
        try {
            $r = $fn();
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', is_callable($ok) ? $ok($r) : $ok);
    }

    public function index(Request $request)
    {
        $tab = in_array($request->input('tab'), ['distributions', 'tips', 'staff'], true) ? $request->input('tab') : 'distributions';
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->input('month')) ? $request->input('month') : bdate()->format('Y-m');
        $mFrom = Carbon::parse($month.'-01')->startOfMonth()->toDateString();
        $mTo = Carbon::parse($month.'-01')->endOfMonth()->toDateString();
        $cutoff = LedgerService::cutoff();
        $last = ScDistribution::where('status', '!=', 'cancelled')->orderByDesc('period_end')->first();
        $nextFrom = $last ? $last->period_end->copy()->addDay() : Carbon::parse($cutoff)->startOfMonth();
        $nextTo = $nextFrom->copy()->endOfMonth();
        if ($nextTo->toDateString() > $cutoff) {
            $nextTo = Carbon::parse($cutoff);
        }

        return view('servicecharge.index', ['tab' => $tab, 'month' => $month, 'cutoff' => $cutoff,
            'available' => ServiceChargeService::available($cutoff), 'retention' => ServiceChargeService::retentionPct(),
            'bySource' => ServiceChargeService::collectedBySource($mFrom, min($mTo, $cutoff)),
            'dists' => ScDistribution::with('creator', 'approver', 'run')->withCount(['lines as paid_count' => fn ($q) => $q->where('amount', '>', 0)])->latest('period_end')->latest('id')->limit(36)->get(),
            'tips' => TipCollection::with('paymentMode', 'creator')->whereBetween('date', [$mFrom, $mTo])->latest('date')->latest('id')->get(),
            'modes' => PaymentMode::where('is_active', true)->orderBy('id')->pluck('name', 'id'),
            'staff' => Employee::with('department')->where('is_active', true)->orderBy('department_id')->orderBy('name')->get(),
            'suggest' => ['from' => $nextFrom->toDateString(), 'to' => $nextTo->toDateString(), 'ok' => $nextFrom->toDateString() <= $cutoff]]);
    }

    public function storeTip(Request $request)
    {
        $d = $request->validate(['date' => 'required|date', 'source' => 'required|string', 'payment_mode_id' => 'required|integer|exists:payment_modes,id',
            'amount' => 'required|numeric|min:0.01|max:1000000', 'reference' => 'nullable|string|max:60', 'notes' => 'nullable|string|max:255']);

        return $this->attempt(fn () => ServiceChargeService::addTip($d, auth()->user()), fn ($t) => "Tips {$t->tip_no} of ".money($t->amount).' recorded into the pool.');
    }

    public function cancelTip(Request $request, TipCollection $tip)
    {
        $d = $request->validate(['reason' => 'required|string|max:255']);

        return $this->attempt(fn () => ServiceChargeService::cancelTip($tip, $d['reason']), "Tips {$tip->tip_no} cancelled.");
    }

    public function store(Request $request)
    {
        $d = $request->validate(['period_start' => 'required|date', 'period_end' => 'required|date', 'basis' => 'required|string', 'notes' => 'nullable|string|max:255']);
        try {
            $dist = ServiceChargeService::create($d, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('sc.show', $dist)->with('success', "Distribution {$dist->dist_no} prepared – review the shares, then ask a second person to approve.");
    }

    public function show(ScDistribution $scdist)
    {
        $scdist->load('lines.employee', 'lines.department', 'creator', 'approver', 'run');
        $from = $scdist->period_start->toDateString();
        $to = $scdist->period_end->toDateString();

        return view('servicecharge.show', ['d' => $scdist, 'bySource' => ServiceChargeService::collectedBySource($from, $to),
            'runs' => PayrollRun::where('status', 'draft')->whereDate('period_end', '>=', $to)->orderBy('period_start')->get(),
            'byDept' => $scdist->lines->groupBy(fn ($l) => $l->department?->name ?? '—')->map(fn ($g) => ['n' => $g->where('amount', '>', 0)->count(), 'amt' => $g->sum('amount')]),
            'canApprove' => can_do('servicecharge.approve'), 'canManage' => can_do('servicecharge.manage')]);
    }

    public function recompute(ScDistribution $scdist)
    {
        return $this->attempt(fn () => ServiceChargeService::compute($scdist), 'Recalculated from the ledger and attendance.');
    }

    public function exclude(Request $request, ScDistribution $scdist, ScDistributionLine $scline)
    {
        abort_unless($scline->sc_distribution_id === $scdist->id, 404);
        $d = $request->validate(['excluded' => 'required|boolean', 'reason' => 'nullable|string|max:120']);

        return $this->attempt(fn () => ServiceChargeService::exclude($scline, (bool) $d['excluded'], $d['reason'] ?? null),
            $d['excluded'] ? "{$scline->employee?->name} excluded – shares recalculated." : "{$scline->employee?->name} re-included.");
    }

    public function approve(ScDistribution $scdist)
    {
        return $this->attempt(fn () => ServiceChargeService::approve($scdist, auth()->user()), fn ($d) => "{$d->dist_no} approved – house share ".money($d->retained).' posted to income; add the staff shares to a payroll run.');
    }

    public function post(Request $request, ScDistribution $scdist)
    {
        $d = $request->validate(['payroll_run_id' => 'required|integer']);
        $run = PayrollRun::findOrFail($d['payroll_run_id']);

        return $this->attempt(fn () => ServiceChargeService::postToPayroll($scdist, $run, auth()->user()), "Shares added to payroll {$run->run_no} as a taxable 'Service charge' earning.");
    }

    public function cancel(Request $request, ScDistribution $scdist)
    {
        abort_unless(can_do('servicecharge.approve') || $scdist->status === 'draft', 403);
        $d = $request->validate(['reason' => 'required|string|max:255']);

        return $this->attempt(fn () => ServiceChargeService::cancel($scdist, $d['reason'], auth()->user()), "{$scdist->dist_no} cancelled.");
    }

    public function print(ScDistribution $scdist)
    {
        return view('servicecharge.print', ['d' => $scdist->load('lines.employee', 'lines.department', 'creator', 'approver', 'run'),
            'bySource' => ServiceChargeService::collectedBySource($scdist->period_start->toDateString(), $scdist->period_end->toDateString())]);
    }

    public function points(Request $request)
    {
        $d = $request->validate(['points' => 'required|array', 'points.*' => 'numeric|min:0|max:20', 'eligible' => 'nullable|array']);
        $n = 0;
        foreach (Employee::whereIn('id', array_keys($d['points']))->get() as $e) {
            $e->sc_points = round((float) $d['points'][$e->id], 2);
            $e->sc_eligible = isset($d['eligible'][$e->id]);
            if ($e->isDirty()) {
                $e->save();
                $n++;
            }
        }
        ActivityLogger::log('servicecharge', 'points', "Service charge points updated for {$n} employee(s)");

        return back()->with('success', "Points saved ({$n} change(s)). Draft distributions use them when recalculated.");
    }

    public function settings(Request $request)
    {
        $d = $request->validate(['sc_retention_pct' => 'required|numeric|min:0|max:100']);
        Setting::updateOrCreate(['key' => 'sc_retention_pct'], ['value' => (string) $d['sc_retention_pct']]);
        Setting::$version++;
        ActivityLogger::log('servicecharge', 'settings', "House retention set to {$d['sc_retention_pct']}%");

        return back()->with('success', 'House retention saved – applies to new distributions.');
    }
}
