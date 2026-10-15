<?php

namespace App\Http\Controllers;

use App\Models\ComplianceEvent;
use App\Models\ComplianceItem;
use App\Models\User;
use App\Services\ComplianceService as Cs;
use Carbon\Carbon;
use Illuminate\Http\Request;
use RuntimeException;

/** v3.9 – licences, permits, certificates, insurance, statutory inspections & AMCs: register, renewals, documents, alerts. */
class ComplianceController extends Controller
{
    public const TABS = ['register' => 'Register', 'calendar' => 'Renewal calendar', 'settings' => 'Settings'];

    protected const FILE_RULE = 'nullable|file|max:10240|mimes:pdf,jpg,jpeg,png,webp|mimetypes:application/pdf,image/jpeg,image/png,image/webp';

    protected function fail(RuntimeException $e)
    {
        return back()->withInput()->with('error', $e->getMessage());
    }

    protected function owners()
    {
        return User::where('hotel_id', \App\Support\Tenant::id())->where('is_active', true)->orderBy('name')->pluck('name', 'id');
    }

    public function index(Request $request)
    {
        $tab = array_key_exists($request->query('tab'), self::TABS) ? $request->query('tab') : 'register';
        if ($tab === 'settings' && ! can_do('compliance.manage')) {
            $tab = 'register';
        }
        $v = ['tab' => $tab, 'snap' => Cs::snapshot(), 'cfg' => Cs::cfg(), 'owners' => $this->owners()];
        if ($tab === 'register') {
            $st = $request->query('state');
            $items = ComplianceItem::with('owner')
                ->when($st === 'archived', fn ($q) => $q->where('status', 'archived'), fn ($q) => $q->where('status', '!=', 'archived'))
                ->when($request->query('category'), fn ($q, $c) => $q->where('category', $c))
                ->when($request->query('department'), fn ($q, $d) => $q->where('department', $d))
                ->when(trim((string) $request->query('q')), fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'like', "%{$t}%")->orWhere('authority', 'like', "%{$t}%")->orWhere('reference_no', 'like', "%{$t}%")))
                ->get()
                ->when($st && $st !== 'archived', fn ($c) => $c->filter(fn ($i) => $i->state() === $st))
                // most urgent first: expired, due, renewing, valid, no expiry
                ->sortBy(fn ($i) => [array_search($i->state(), ['expired', 'due', 'renewing', 'valid', 'perpetual', 'archived']), $i->expires_on?->timestamp ?? PHP_INT_MAX, $i->name])->values();
            $v += ['items' => $items];
        } elseif ($tab === 'calendar') {
            $start = bdate()->copy()->startOfMonth();
            $items = Cs::active()->whereNotNull('expires_on')->whereDate('expires_on', '<', $start->copy()->addMonths(12)->toDateString())->orderBy('expires_on')->get();
            $months = [];
            for ($i = 0; $i < 12; $i++) {
                $m = $start->copy()->addMonths($i);
                $months[$m->format('Y-m')] = ['label' => $m->format('M Y'), 'items' => collect()];
            }
            $overdue = collect();
            foreach ($items as $it) {
                $k = $it->expires_on->format('Y-m');
                isset($months[$k]) && $it->expires_on->gte($start) ? $months[$k]['items']->push($it) : $overdue->push($it);
            }
            $v += ['months' => $months, 'overdue' => $overdue, 'yearFees' => $items->where('expires_on', '>=', $start)->sum('fee')]; // Collection of models: Carbon-to-Carbon, already exact
        }

        return view('compliance.index', $v);
    }

    public function show(ComplianceItem $citem)
    {
        return view('compliance.show', ['i' => $citem->load('owner', 'events.user'), 'owners' => $this->owners()]);
    }

    protected function rules(): array
    {
        return [
            'name' => 'required|string|max:120', 'category' => 'required|in:'.implode(',', array_keys(ComplianceItem::CATEGORIES)), 'authority' => 'nullable|string|max:120',
            'reference_no' => 'nullable|string|max:80', 'department' => 'nullable|string|max:30', 'owner_id' => 'nullable|integer|owned:users',
            'issued_on' => 'nullable|date', 'expires_on' => 'nullable|date', 'frequency_months' => 'nullable|integer|min:1|max:120', 'lead_days' => 'nullable|integer|min:1|max:365',
            'fee' => 'nullable|numeric|min:0', 'is_critical' => 'nullable|boolean', 'notes' => 'nullable|string|max:1000',
        ];
    }

    public function store(Request $request)
    {
        $d = $request->validate($this->rules() + ['file' => self::FILE_RULE]);
        try {
            $i = Cs::save($d);
            if ($request->hasFile('file')) {
                Cs::upload($i, $request->file('file'), 'Initial document');
            }
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return redirect()->route('compliance.show', $i)->with('success', "\"{$i->name}\" added to the compliance register.");
    }

    public function update(Request $request, ComplianceItem $citem)
    {
        $d = $request->validate($this->rules());
        try {
            Cs::save($d, $citem);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return redirect()->route('compliance.show', $citem)->with('success', 'Saved.');
    }

    public function apply(Request $request, ComplianceItem $citem)
    {
        $d = $request->validate(['applied_on' => 'required|date', 'reference' => 'nullable|string|max:80', 'fee' => 'nullable|numeric|min:0', 'note' => 'nullable|string|max:500']);
        try {
            Cs::applyRenewal($citem, $d);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with('success', 'Renewal application recorded – reminders pause until the renewal is recorded or the licence lapses.');
    }

    public function renew(Request $request, ComplianceItem $citem)
    {
        $d = $request->validate(['renewed_on' => 'required|date', 'new_expiry' => 'nullable|date', 'reference_no' => 'nullable|string|max:80', 'fee' => 'nullable|numeric|min:0',
            'note' => 'nullable|string|max:500', 'file' => self::FILE_RULE]);
        try {
            $i = Cs::renew($citem, $d, $request->file('file'));
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with('success', "\"{$i->name}\" renewed".($i->expires_on ? ' – valid until '.$i->expires_on->format('d M Y') : '').'.');
    }

    public function inspect(Request $request, ComplianceItem $citem)
    {
        $d = $request->validate(['done_on' => 'required|date', 'result' => 'required|in:pass,fail', 'next_due' => 'nullable|date', 'reference' => 'nullable|string|max:80',
            'fee' => 'nullable|numeric|min:0', 'note' => 'nullable|string|max:500', 'file' => self::FILE_RULE]);
        try {
            $i = Cs::inspect($citem, $d, $request->file('file'));
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with($d['result'] === 'pass' ? 'success' : 'warning', $d['result'] === 'pass'
            ? 'Inspection recorded'.($i->expires_on ? ' – next due '.$i->expires_on->format('d M Y') : '').'.'
            : 'Failed inspection recorded and flagged in the log book – rectify and re-inspect.');
    }

    public function upload(Request $request, ComplianceItem $citem)
    {
        $d = $request->validate(['file' => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png,webp|mimetypes:application/pdf,image/jpeg,image/png,image/webp', 'note' => 'nullable|string|max:500']);
        try {
            Cs::upload($citem, $request->file('file'), $d['note'] ?? null);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with('success', 'Document uploaded.');
    }

    public function archive(Request $request, ComplianceItem $citem)
    {
        $d = $request->validate(['note' => 'nullable|string|max:500']);
        Cs::archive($citem, $citem->status !== 'archived', $d['note'] ?? null);

        return back()->with('success', $citem->status === 'archived' ? 'Archived – no longer tracked.' : 'Restored to the register.');
    }

    public function document(ComplianceItem $citem)
    {
        return Cs::documentResponse($citem->document_path, $citem->document_name ?: 'document');
    }

    public function eventDocument(ComplianceEvent $cevent)
    {
        return Cs::documentResponse($cevent->document_path, $cevent->document_name ?: 'document');
    }

    public function template()
    {
        $n = Cs::loadTemplate();

        return redirect()->route('compliance.index')->with('success', $n ? "{$n} standard item(s) added – fill in their reference numbers and expiry dates." : 'All standard items are already in the register.');
    }

    public function runAlerts()
    {
        $r = Cs::alerts();

        return back()->with('success', "{$r['due']} newly due, {$r['expired']} newly expired · {$r['mails']} e-mail(s) sent.");
    }

    public function saveSettings(Request $request)
    {
        $d = $request->validate(['lead_days' => 'required|integer|min:1|max:365', 'digest_to' => 'nullable|string|max:500']);
        try {
            Cs::saveSettings($d);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return redirect()->route('compliance.index', ['tab' => 'settings'])->with('success', 'Compliance settings saved.');
    }

    public function printRegister(Request $request)
    {
        $asOf = $request->query('as_of') && strtotime($request->query('as_of')) ? Carbon::parse($request->query('as_of'))->toDateString() : bdate()->toDateString();

        return view('compliance.print', ['rows' => Cs::register($asOf), 'asOf' => Carbon::parse($asOf)]);
    }
}
