<?php

namespace App\Http\Controllers;

use App\Models\KpiAlert;
use App\Models\KpiAlertEvent;
use App\Models\ReportDelivery;
use App\Models\ReportSchedule;
use App\Models\ReportView;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\KpiService;
use App\Services\ReportScheduleService as RS;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

/** v5.4 Saved report views, e-mailed report schedules and KPI threshold alerts. */
class ReportAutomationController extends Controller
{
    /** My reports: saved views (own + shared) and the schedules I own. */
    public function library(Request $request)
    {
        $u = $request->user();
        $views = RS::viewsFor($u);
        $mySchedules = ReportSchedule::with('view')->where('user_id', $u->id)->orderBy('name')->get();
        $reports = collect(ReportController::REPORTS)->filter(fn ($r) => $u->hasPermission($r[4] ?? 'reports.view'))->map(fn ($r) => $r[1])->sort();

        return view('reports.library', compact('views', 'mySchedules', 'reports') + ['stats' => RS::stats()]);
    }

    public function storeView(Request $request)
    {
        return $this->attempt(function () use ($request) {
            $v = RS::saveView($request->all(), $request->user());

            return redirect()->to($v->url())->with('success', "View “{$v->name}” saved.");
        });
    }

    public function updateView(Request $request, ReportView $rview)
    {
        return $this->attempt(function () use ($request, $rview) {
            RS::saveView($request->all() + ['columns' => $rview->columns], $request->user(), $rview);

            return back()->with('success', "View “{$rview->name}” updated.");
        });
    }

    public function pinView(Request $request, ReportView $rview)
    {
        abort_unless($rview->user_id === $request->user()->id, 403);
        $rview->update(['is_pinned' => ! $rview->is_pinned]);

        return back()->with('success', $rview->is_pinned ? "“{$rview->name}” pinned to your dashboard." : "“{$rview->name}” unpinned.");
    }

    public function destroyView(Request $request, ReportView $rview)
    {
        abort_unless($rview->user_id === $request->user()->id || $request->user()->hasPermission('settings.manage'), 403);
        if ($rview->schedules()->exists()) {
            return back()->with('error', "“{$rview->name}” is used by ".$rview->schedules()->count().' schedule(s) – delete those first.');
        }
        ActivityLogger::log('reports', 'view_delete', 'Deleted report view '.$rview->name, $rview);
        $rview->delete();

        return back()->with('success', 'View deleted.');
    }

    // ───────────────────────── schedules ─────────────────────────

    public function schedules(Request $request)
    {
        $u = $request->user();
        $schedules = ReportSchedule::with('user', 'view')->orderByDesc('is_active')->orderBy('name')->get();
        $deliveries = ReportDelivery::with('schedule', 'user')
            ->when($request->integer('schedule'), fn ($q, $id) => $q->where('report_schedule_id', $id))
            ->when($request->input('status'), fn ($q, $st) => $q->where('status', $st))
            ->latest('id')->paginate(25)->withQueryString();
        $reports = collect(ReportController::REPORTS)->filter(fn ($r) => $u->hasPermission($r[4] ?? 'reports.view'))->map(fn ($r) => $r[1])->sort();

        return view('reports.schedules', compact('schedules', 'deliveries', 'reports') + [
            'stats' => RS::stats(), 'users' => User::visibleToHotel()->where('is_active', true)->whereNotNull('email')->orderBy('name')->get(['id', 'name', 'email']),
            'views' => RS::viewsFor($u), 'editing' => $request->integer('edit') ? ReportSchedule::find($request->integer('edit')) : null,
        ]);
    }

    public function storeSchedule(Request $request)
    {
        return $this->attempt(function () use ($request) {
            $s = RS::save($request->all(), $request->user());

            return redirect()->route('reports.schedules')->with('success', "Schedule “{$s->name}” saved – ".$s->when().($s->next_run_at ? ', next '.$s->next_run_at->format('D d M H:i') : '').'.');
        });
    }

    public function updateSchedule(Request $request, ReportSchedule $rschedule)
    {
        $this->ownerOrAdmin($request, $rschedule);

        return $this->attempt(function () use ($request, $rschedule) {
            RS::save($request->all(), $request->user(), $rschedule);

            return redirect()->route('reports.schedules')->with('success', "Schedule “{$rschedule->name}” updated.");
        });
    }

    public function runSchedule(Request $request, ReportSchedule $rschedule)
    {
        $this->ownerOrAdmin($request, $rschedule);
        $d = RS::run($rschedule->load('user', 'view'), 'manual', $request->user());

        return back()->with($d->status === 'failed' ? 'error' : 'success', match ($d->status) {
            'sent' => "“{$rschedule->name}” sent to {$d->delivered} recipient(s) ({$d->rows} rows).",
            'partial' => "“{$rschedule->name}” sent to {$d->delivered} of {$d->recipients} recipients. {$d->error}",
            'skipped' => "Nothing sent: {$d->error}",
            default => "Sending failed: {$d->error}",
        });
    }

    public function toggleSchedule(Request $request, ReportSchedule $rschedule)
    {
        $this->ownerOrAdmin($request, $rschedule);
        RS::setActive($rschedule, ! $rschedule->is_active);

        return back()->with('success', "“{$rschedule->name}” ".($rschedule->is_active ? 'resumed – next run '.($rschedule->next_run_at?->format('D d M H:i') ?? 'after the next night audit') : 'paused').'.');
    }

    public function destroySchedule(Request $request, ReportSchedule $rschedule)
    {
        $this->ownerOrAdmin($request, $rschedule);
        ActivityLogger::log('reports', 'schedule_delete', 'Deleted report schedule '.$rschedule->name, $rschedule);
        $rschedule->delete();

        return back()->with('success', 'Schedule deleted – its delivery history is kept.');
    }

    public function deliveryCsv(Request $request, ReportDelivery $rdelivery)
    {
        try {
            $data = RS::regenerate($rdelivery, $request->user());
        } catch (RuntimeException $e) {
            abort(403, $e->getMessage());
        }
        $name = $rdelivery->report_key.'_'.($rdelivery->period_from?->format('Ymd') ?? 'asof').'_'.($rdelivery->period_to?->format('Ymd') ?? '').'.csv';

        return response()->streamDownload(fn () => print (RS::csv($data)), $name, ['Content-Type' => 'text/csv']);
    }

    public function settings(Request $request)
    {
        abort_unless($request->user()->hasPermission('settings.manage'), 403);
        RS::saveSettings($request->only(array_keys(RS::SETTINGS)));
        ActivityLogger::log('reports', 'settings', 'Updated report scheduling settings');

        return back()->with('success', 'Report scheduling settings saved.');
    }

    private function ownerOrAdmin(Request $request, ReportSchedule $s): void
    {
        abort_unless($s->user_id === $request->user()->id || $request->user()->hasPermission('settings.manage'), 403, 'Only the schedule owner or an administrator can change it.');
    }

    // ───────────────────────── KPI alerts ─────────────────────────

    public function alerts(Request $request)
    {
        $alerts = KpiAlert::with('user')->orderByDesc('is_active')->orderBy('name')->get();
        $events = KpiAlertEvent::with('alert', 'acknowledger')
            ->when($request->input('show') !== 'all', fn ($q) => $q->whereNull('acknowledged_at'))
            ->orderByDesc('business_date')->orderByRaw("FIELD(severity,'critical','warning','info')")->paginate(30)->withQueryString();

        return view('reports.alerts', compact('alerts', 'events') + [
            'open' => KpiService::openCount(), 'date' => KpiService::defaultDate(),
            'users' => User::visibleToHotel()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email', 'phone']),
            'editing' => $request->integer('edit') ? KpiAlert::find($request->integer('edit')) : null,
        ]);
    }

    public function storeAlert(Request $request)
    {
        return $this->attempt(function () use ($request) {
            $a = KpiService::save($request->all(), $request->user());

            return redirect()->route('reports.alerts')->with('success', "Alert “{$a->name}” saved – ".$a->rule().'.');
        });
    }

    public function updateAlert(Request $request, KpiAlert $kpialert)
    {
        return $this->attempt(function () use ($request, $kpialert) {
            KpiService::save($request->all() + ['is_active' => $kpialert->is_active], $request->user(), $kpialert);

            return redirect()->route('reports.alerts')->with('success', "Alert “{$kpialert->name}” updated.");
        });
    }

    public function toggleAlert(KpiAlert $kpialert)
    {
        $kpialert->update(['is_active' => ! $kpialert->is_active]);

        return back()->with('success', "“{$kpialert->name}” ".($kpialert->is_active ? 'enabled' : 'disabled').'.');
    }

    public function destroyAlert(KpiAlert $kpialert)
    {
        ActivityLogger::log('reports', 'kpi_delete', 'Deleted KPI alert '.$kpialert->name, $kpialert);
        $kpialert->delete();

        return back()->with('success', 'Alert deleted.');
    }

    public function evaluate(Request $request)
    {
        $date = $request->filled('date') ? Carbon::parse($request->input('date'))->startOfDay() : KpiService::defaultDate();
        if ($date->gte(bdate()->copy()->startOfDay())) {
            return back()->with('error', 'Alerts can only be evaluated for audited business dates (before '.bdate()->format('d M Y').').');
        }
        $r = KpiService::evaluate($date, $request->boolean('notify', true));

        return back()->with('success', "Checked {$r['evaluated']} alert(s) for ".$date->format('d M Y').": {$r['triggered']} new breach(es).");
    }

    public function acknowledge(Request $request, KpiAlertEvent $kpievent)
    {
        return $this->attempt(function () use ($request, $kpievent) {
            KpiService::acknowledge($kpievent, $request->user(), $request->input('note'));

            return back()->with('success', 'Alert acknowledged.');
        });
    }

    private function attempt(callable $fn)
    {
        try {
            return $fn();
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }
}
