<?php

namespace App\Http\Controllers;

use App\Models\AuditDay;
use App\Models\AuditFinding;
use App\Models\Room;
use App\Models\RoomCount;
use App\Models\Setting;
use App\Services\IncomeAuditService as IA;
use Carbon\Carbon;
use Illuminate\Http\Request;
use RuntimeException;

/** v5.3 Income audit: audit days, exceptions, sign-off / close, the housekeeping room count and discrepancies. */
class IncomeAuditController extends Controller
{
    protected function attempt(callable $fn, string|callable $ok, ?string $route = null)
    {
        try {
            $r = $fn();
            $msg = is_callable($ok) ? $ok($r) : $ok;

            return ($route ? redirect($route) : back())->with('success', $msg);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function index(Request $request)
    {
        $days = AuditDay::with('signer', 'closer')->where('business_date', '>=', bdate()->copy()->subDays(45)->toDateString())->get()->keyBy(fn ($d) => $d->business_date->toDateString());
        $rows = [];
        for ($d = bdate()->copy()->subDay(); $d->gte(bdate()->copy()->subDays(30)); $d->subDay()) {
            $rows[] = ['date' => $d->toDateString(), 'day' => $days[$d->toDateString()] ?? null];
        }
        if ($request->input('status')) {
            $rows = array_values(array_filter($rows, fn ($r) => $request->status === 'none' ? ! $r['day'] : $r['day']?->status === $request->status));
        }

        return view('income-audit.index', ['rows' => $rows, 'stats' => IA::stats(), 'settings' => IA::SETTINGS, 'checks' => IA::CHECKS]);
    }

    public function start(Request $request)
    {
        $d = $request->validate(['date' => 'required|date']);
        try {
            $day = IA::day($d['date']);
            if ($day->status === 'open') {
                $r = IA::run($day, $request->user());
                $msg = "Checks run for ".dmy($day->business_date).": {$r['found']} exception(s), {$r['open']} open.";
            }
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('audit.day', $day)->with('success', $msg ?? 'Opened.');
    }

    public function settings(Request $request)
    {
        $d = $request->validate(collect(IA::SETTINGS)->mapWithKeys(fn ($s, $k) => [$k => 'required|numeric|min:0|max:10000000'])->all());
        foreach ($d as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => (string) $v]);
        }
        Setting::$version++;

        return back()->with('success', 'Income audit settings saved.');
    }

    public function show(Request $request, AuditDay $auditday)
    {
        $auditday->load('signer', 'closer', 'reopener');
        $all = AuditFinding::with('resolver', 'authorisation')->where('audit_day_id', $auditday->id)->get();
        $q = $all->when($request->input('check'), fn ($c) => $c->where('check', $request->check));
        $q = match ($request->input('show', 'attention')) {
            'attention' => $q->whereIn('status', AuditFinding::UNRESOLVED),
            'all' => $q,
            default => $q->where('status', $request->show),
        };
        $sev = ['high' => 0, 'medium' => 1, 'low' => 2];
        $findings = $q->sortBy(fn ($f) => [array_search($f->check, array_keys(IA::CHECKS)), $sev[$f->severity] ?? 3, $f->room_no])->values();
        $date = $auditday->business_date->toDateString();

        return view('income-audit.day', ['day' => $auditday, 'findings' => $findings, 'all' => $all, 'checks' => IA::CHECKS,
            'figures' => $auditday->figures ?: IA::figures($date), 'counts' => RoomCount::where('business_date', $date)->get(),
            'prev' => AuditDay::where('business_date', '<', $date)->orderByDesc('business_date')->first(),
            'next' => AuditDay::where('business_date', '>', $date)->orderBy('business_date')->first()]);
    }

    public function print(AuditDay $auditday)
    {
        $auditday->load('signer', 'closer');
        $findings = AuditFinding::with('resolver', 'authorisation')->where('audit_day_id', $auditday->id)->where('status', '!=', 'cleared')->get()
            ->sortBy(fn ($f) => [array_search($f->check, array_keys(IA::CHECKS)), $f->room_no])->values();

        return view('income-audit.print-day', ['day' => $auditday, 'findings' => $findings, 'checks' => IA::CHECKS, 'figures' => $auditday->figures ?: IA::figures($auditday->business_date->toDateString())]);
    }

    public function run(Request $request, AuditDay $auditday)
    {
        return $this->attempt(fn () => IA::run($auditday, $request->user()),
            fn ($r) => "Checks re-run: {$r['found']} exception(s) found, {$r['new']} new, {$r['cleared']} cleared (put right), {$r['open']} still open.");
    }

    public function sign(Request $request, AuditDay $auditday)
    {
        $d = $request->validate(['note' => 'nullable|string|max:500']);

        return $this->attempt(fn () => IA::sign($auditday, $d['note'] ?? null, $request->user()), 'Day signed – waiting for the financial controller to close it.');
    }

    public function close(Request $request, AuditDay $auditday)
    {
        $d = $request->validate(['note' => 'nullable|string|max:500']);

        return $this->attempt(fn () => IA::close($auditday, $d['note'] ?? null, $request->user()), dmy($auditday->business_date).' closed – postings dated that day are now locked.');
    }

    public function reopen(Request $request, AuditDay $auditday)
    {
        $d = $request->validate(['reason' => 'required|string|max:255']);

        return $this->attempt(fn () => IA::reopen($auditday, $d['reason'], $request->user()), 'Day reopened for audit.');
    }

    public function resolve(Request $request, AuditFinding $auditfinding)
    {
        $d = $request->validate(['status' => 'required|string', 'resolution' => 'nullable|string|max:500']);

        return $this->attempt(fn () => IA::resolve($auditfinding, $d['status'], (string) ($d['resolution'] ?? ''), $request->user()),
            'Exception marked '.strtolower(AuditFinding::STATUSES[$d['status']] ?? $d['status']).'.');
    }

    // ───────────── room count ─────────────

    public function count()
    {
        $date = bdate()->toDateString();
        $rooms = Room::with('roomType')->where('is_active', true)->orderBy('floor_id')->orderBy('room_no')->get();
        $done = RoomCount::where('business_date', $date)->get()->keyBy('room_id');

        return view('income-audit.room-count', ['rooms' => $rooms, 'done' => $done, 'date' => $date, 'blind' => ! can_do('income_audit.authorise')]);
    }

    public function saveCount(Request $request)
    {
        $d = $request->validate(['rooms' => 'required|array', 'rooms.*.hk' => 'nullable|in:occupied,vacant,ooo', 'rooms.*.luggage' => 'nullable']);

        return $this->attempt(fn () => IA::saveCount($d['rooms'], $request->user()),
            fn ($r) => "Room count saved: {$r['rooms']} of {$r['total']} rooms".($r['issues'] ? " – {$r['issues']} discrepancy(ies) sent to front office." : ' – all agree with front office.'));
    }

    public function discrepancies(Request $request)
    {
        $from = Carbon::parse($request->input('from', bdate()->copy()->subDays(7)->toDateString()))->toDateString();
        $rows = RoomCount::with('room.roomType', 'counter', 'resolver')->where('business_date', '>=', $from)->where('result', '!=', 'ok')
            ->when($request->input('show', 'open') === 'open', fn ($q) => $q->whereNull('resolution'))
            ->orderByDesc('business_date')->orderBy('room_id')->get();
        $summary = RoomCount::where('business_date', bdate()->toDateString())->selectRaw('result, count(*) n')->groupBy('result')->pluck('n', 'result');

        return view('income-audit.discrepancies', ['rows' => $rows, 'from' => $from, 'summary' => $summary, 'total' => Room::where('is_active', true)->count()]);
    }

    public function resolveCount(Request $request, RoomCount $roomcount)
    {
        $d = $request->validate(['resolution' => 'required|string', 'note' => 'nullable|string|max:255']);

        return $this->attempt(fn () => IA::resolveCount($roomcount, $d['resolution'], $d['note'] ?? null, $request->user()), 'Room '.$roomcount->room?->room_no.' discrepancy resolved.');
    }
}
