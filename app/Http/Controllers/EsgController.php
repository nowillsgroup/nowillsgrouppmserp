<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\BanquetEvent;
use App\Models\Company;
use App\Models\Department;
use App\Models\EsgChecklistItem;
use App\Models\EsgCommunity;
use App\Models\EsgGreenNight;
use App\Models\EsgGreenStay;
use App\Models\EsgInitiative;
use App\Models\EsgLaundryMonth;
use App\Models\EsgPolicy;
use App\Models\EsgRefrigerantLog;
use App\Models\EsgTarget;
use App\Models\EsgWasteLog;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\EsgService as ES;
use App\Support\Tenant;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** v7.0 Sustainability & ESG. */
class EsgController extends Controller
{
    protected const FILE_RULE = 'nullable|file|max:5120|mimes:pdf,jpg,jpeg,png,webp,doc,docx|mimetypes:application/pdf,image/jpeg,image/png,image/webp,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    protected function attempt(callable $fn, string $ok)
    {
        try {
            $r = $fn();
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first())->withInput();
        }

        return $r instanceof \Symfony\Component\HttpFoundation\Response ? $r : back()->with('success', is_string($r) ? $r : $ok);
    }

    protected function users()
    {
        return User::where('hotel_id', Tenant::id())->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    /** from / to query with a default window; to never later than yesterday's business date */
    protected function range(Request $r, string $defFrom, ?string $defTo = null): array
    {
        $y = bdate()->copy()->subDay()->toDateString();
        try {
            $from = $r->filled('from') ? Carbon::parse($r->query('from'))->toDateString() : $defFrom;
            $to = $r->filled('to') ? Carbon::parse($r->query('to'))->toDateString() : ($defTo ?? $y);
        } catch (\Throwable) {
            [$from, $to] = [$defFrom, $defTo ?? $y];
        }
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    // ------------------------------------------------------------------ dashboard

    public function index()
    {
        [$wf, $wt] = ES::window();
        $ytdFrom = bdate()->copy()->startOfYear()->toDateString();
        $y = bdate()->copy()->subDay()->toDateString();
        $mStart = bdate()->copy()->startOfMonth()->toDateString();

        return view('esg.index', [
            'b' => ES::benchmark(), 'wf' => $wf, 'wt' => $wt, 'cb' => ES::carbon($wf, $wt), 'waste' => ES::waste(bdate()->copy()->subDays(90)->toDateString(), $y),
            'green' => ES::greenStats($mStart, bdate()->toDateString()), 'cert' => ES::certScore(), 'gov' => ES::governance(), 'alerts' => ES::alerts(),
            'targets' => ES::targets(), 'trend' => ES::carbonTrend(), 'community' => ES::community($ytdFrom, $y), 'renewable' => ES::metric('renewable'),
            'initiatives' => EsgInitiative::with('owner')->whereIn('status', ['idea', 'approved', 'in_progress'])->orderByRaw("FIELD(status, 'idea', 'in_progress', 'approved')")->orderBy('due_on')->limit(6)->get(),
            'leaks' => collect(ES::leakRates())->where('flag', true)->values(),
        ]);
    }

    // ------------------------------------------------------------------ carbon & HCMI

    public function carbon(Request $r)
    {
        [$wf, $wt] = ES::window();
        [$from, $to] = $this->range($r, $wf, $wt);

        return view('esg.carbon', ['from' => $from, 'to' => $to, 'cb' => ES::carbon($from, $to), 'h' => ES::hcmi($from, $to), 'use' => ES::meetingUse($from, $to),
            'laundry' => EsgLaundryMonth::with('supplier')->orderByDesc('month')->limit(12)->get(), 'cfg' => ES::cfg(), 'ef' => \App\Services\UtilityService::cfg()['ef'],
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name'])]);
    }

    public function laundryStore(Request $r)
    {
        return $this->attempt(function () use ($r) {
            $m = ES::saveLaundry($r->validate(['month' => 'required|string|max:10', 'kg' => 'required|numeric|min:0', 'supplier_id' => 'nullable|integer|owned:suppliers',
                'kwh' => 'nullable|numeric|min:0', 'notes' => 'nullable|string|max:255']), $r->user());

            return 'Laundry tonnage for '.$m->month->format('M Y').' saved.';
        }, 'Saved.');
    }

    // ------------------------------------------------------------------ waste

    public function waste(Request $r)
    {
        [$from, $to] = $this->range($r, bdate()->copy()->subDays(89)->toDateString(), bdate()->toDateString());
        $list = EsgWasteLog::with(['department', 'supplier', 'user'])->whereBetween('date', [$from, $to])
            ->when($r->filled('stream'), fn ($q) => $q->where('stream', $r->query('stream')))
            ->when($r->filled('disposal'), fn ($q) => $q->where('disposal', $r->query('disposal')))
            ->when(! $r->boolean('cancelled'), fn ($q) => $q->whereNull('cancelled_at'))
            ->orderByDesc('date')->orderByDesc('id')->paginate(40)->withQueryString();

        return view('esg.waste', ['from' => $from, 'to' => $to, 'list' => $list, 's' => ES::waste($from, $to), 'trend' => ES::wasteTrend(),
            'departments' => Department::orderBy('name')->get(['id', 'name']), 'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name'])]);
    }

    public function wasteStore(Request $r)
    {
        return $this->attempt(function () use ($r) {
            $w = ES::saveWaste($r->validate(['date' => 'required|date', 'stream' => 'required|string|max:16', 'disposal' => 'required|string|max:14', 'weight_kg' => 'required|numeric',
                'department_id' => 'nullable|integer|owned:departments', 'supplier_id' => 'nullable|integer|owned:suppliers', 'contractor' => 'nullable|string|max:120',
                'manifest_no' => 'nullable|string|max:60', 'cost' => 'nullable|numeric|min:0', 'income' => 'nullable|numeric|min:0', 'notes' => 'nullable|string|max:255']), $r->user());

            return "Waste {$w->number} recorded: ".number_format($w->weight_kg, 1).' kg.';
        }, 'Saved.');
    }

    public function wasteCancel(Request $r, EsgWasteLog $esgwaste)
    {
        return $this->attempt(fn () => ES::cancelWaste($esgwaste, (string) $r->input('reason'), $r->user()), "Waste {$esgwaste->number} cancelled.");
    }

    // ------------------------------------------------------------------ refrigerants

    public function refrigerants(Request $r)
    {
        [$from, $to] = $this->range($r, bdate()->copy()->subYear()->addDay()->toDateString(), bdate()->toDateString());
        $list = EsgRefrigerantLog::with(['asset', 'ticket', 'user'])->whereBetween('date', [$from, $to])->orderByDesc('date')->orderByDesc('id')->get();

        return view('esg.refrigerants', ['from' => $from, 'to' => $to, 'list' => $list, 'leaks' => ES::leakRates(), 'cfg' => ES::cfg(),
            'assets' => Asset::orderBy('name')->get(['id', 'name', 'asset_no']),
            'tickets' => \App\Models\MaintenanceRequest::orderByDesc('id')->limit(80)->get(['id', 'ticket_no', 'title'])]);
    }

    public function refrigerantStore(Request $r)
    {
        return $this->attempt(function () use ($r) {
            $x = ES::saveRefrigerant($r->validate(['date' => 'required|date', 'equipment' => 'required|string|max:120', 'gas' => 'required|string|max:10', 'reason' => 'required|string|max:14',
                'asset_id' => 'nullable|integer|owned:assets', 'system_charge_kg' => 'nullable|numeric', 'kg_added' => 'nullable|numeric|min:0', 'kg_recovered' => 'nullable|numeric|min:0',
                'technician' => 'nullable|string|max:120', 'certificate_no' => 'nullable|string|max:60', 'maintenance_request_id' => 'nullable|integer|owned:maintenance_requests',
                'notes' => 'nullable|string|max:255']), $r->user());

            return "F-gas entry {$x->number} recorded (".number_format($x->co2e(), 0).' kg CO₂e).';
        }, 'Saved.');
    }

    // ------------------------------------------------------------------ green stay

    public function green(Request $r)
    {
        [$from, $to] = $this->range($r, bdate()->copy()->startOfMonth()->toDateString(), bdate()->toDateString());
        $active = EsgGreenStay::with(['stay.room', 'stay.guest', 'stay.reservation.guest'])->where('status', 'active')->orderBy('from_date')->get();
        $inhouse = ReservationRoom::with(['room', 'guest', 'reservation.guest'])->where('status', 'checked_in')->where('departure_date', '>', bdate()->copy()->addDay()->toDateString())
            ->whereNotIn('id', $active->pluck('reservation_room_id'))->get()->sortBy(fn ($s) => $s->room?->room_no)->values();
        $nights = EsgGreenNight::with(['room', 'guest'])->whereBetween('business_date', [$from, $to])->orderByDesc('business_date')->orderBy('room_id')->limit(200)->get();
        $today = bdate()->toDateString();

        return view('esg.green', ['from' => $from, 'to' => $to, 'active' => $active, 'inhouse' => $inhouse, 'nights' => $nights, 's' => ES::greenStats($from, $to), 'cfg' => ES::cfg(),
            'skipToday' => $active->mapWithKeys(fn ($g) => [$g->id => ES::skipsService($g->stay, $today)])]);
    }

    public function greenIn(Request $r)
    {
        return $this->attempt(function () use ($r) {
            $s = ReservationRoom::with('room')->findOrFail((int) $r->input('reservation_room_id'));
            $g = ES::optIn($s, 'desk', $r->user());

            return 'Room '.($s->room?->room_no ?? '').' is on the green stay from '.$g->from_date->format('d M').'.';
        }, 'Saved.');
    }

    public function greenOut(Request $r, EsgGreenStay $esggreen)
    {
        return $this->attempt(fn () => ES::optOut($esggreen, 'desk', $r->user()), 'Green stay ended – daily service resumes.');
    }

    // ------------------------------------------------------------------ targets

    public function targets()
    {
        return view('esg.targets', ['rows' => ES::targets(), 'retired' => EsgTarget::where('is_active', false)->orderByDesc('updated_at')->get(),
            'current' => collect(ES::METRICS)->map(fn ($m, $k) => ES::metric($k))->all(), 'window' => ES::window()]);
    }

    protected function targetData(Request $r): array
    {
        return $r->validate(['metric' => 'required|string|max:24', 'baseline_year' => 'required|integer', 'baseline_value' => 'required|numeric', 'target_year' => 'required|integer',
            'target_value' => 'required|numeric', 'notes' => 'nullable|string|max:255']) + ['is_active' => $r->boolean('is_active', true)];
    }

    public function targetStore(Request $r)
    {
        return $this->attempt(fn () => ES::saveTarget($this->targetData($r), $r->user()), 'Target saved.');
    }

    public function targetUpdate(Request $r, EsgTarget $esgtarget)
    {
        return $this->attempt(fn () => ES::saveTarget($this->targetData($r), $r->user(), $esgtarget), 'Target updated.');
    }

    // ------------------------------------------------------------------ initiatives

    public function initiatives(Request $r)
    {
        $list = EsgInitiative::with(['owner', 'raiser'])->when($r->filled('status'), fn ($q) => $q->where('status', $r->query('status')))
            ->when($r->filled('category'), fn ($q) => $q->where('category', $r->query('category')))
            ->orderByRaw("FIELD(status, 'idea', 'in_progress', 'approved', 'done', 'rejected', 'cancelled')")->orderByDesc('id')->get();
        $live = EsgInitiative::whereIn('status', ['approved', 'in_progress', 'done'])->get();

        return view('esg.initiatives', ['list' => $list, 'users' => $this->users(),
            'tot' => ['capex' => $live->sum('capex'), 'ghs' => $live->sum('saving_ghs'), 'co2' => $live->sum('saving_co2_kg'), 'kwh' => $live->sum('saving_kwh'), 'm3' => $live->sum('saving_m3'),
                'done_co2' => $live->where('status', 'done')->sum('saving_co2_kg'), 'done_ghs' => $live->where('status', 'done')->sum('saving_ghs')]]);
    }

    public function initiative(EsgInitiative $esginitiative)
    {
        return view('esg.initiative', ['i' => $esginitiative->load(['owner', 'raiser', 'approver']), 'users' => $this->users()]);
    }

    protected function initiativeData(Request $r): array
    {
        return $r->validate(['title' => 'required|string|max:150', 'category' => 'required|string|max:12', 'description' => 'nullable|string|max:5000', 'owner_user_id' => 'nullable|integer|owned:users',
            'capex' => 'nullable|numeric|min:0', 'saving_ghs' => 'nullable|numeric|min:0', 'saving_kwh' => 'nullable|numeric|min:0', 'saving_m3' => 'nullable|numeric|min:0',
            'saving_waste_kg' => 'nullable|numeric|min:0', 'saving_co2_kg' => 'nullable|numeric|min:0', 'start_on' => 'nullable|date', 'due_on' => 'nullable|date']);
    }

    public function initiativeStore(Request $r)
    {
        return $this->attempt(function () use ($r) {
            $i = ES::saveInitiative($this->initiativeData($r), $r->user());

            return redirect()->route('esg.initiative', $i)->with('success', "Initiative {$i->number} proposed.");
        }, 'Saved.');
    }

    public function initiativeUpdate(Request $r, EsgInitiative $esginitiative)
    {
        return $this->attempt(fn () => ES::saveInitiative($this->initiativeData($r), $r->user(), $esginitiative), 'Initiative updated.');
    }

    public function initiativeMove(Request $r, EsgInitiative $esginitiative)
    {
        return $this->attempt(function () use ($r, $esginitiative) {
            $i = ES::moveInitiative($esginitiative, (string) $r->input('action'), $r->user(), $r->only(['note', 'outcome', 'completed_on']));

            return "Initiative {$i->number}: {$i->statusLabel()}.";
        }, 'Saved.');
    }

    // ------------------------------------------------------------------ people & community

    public function social(Request $r)
    {
        [$from, $to] = $this->range($r, bdate()->copy()->startOfYear()->toDateString());

        return view('esg.social', ['from' => $from, 'to' => $to, 's' => ES::social($from, $to), 'src' => ES::localSpend($from, $to),
            'list' => EsgCommunity::with('user')->whereBetween('date', [$from, $to])->orderByDesc('date')->get()]);
    }

    public function communityStore(Request $r)
    {
        return $this->attempt(function () use ($r) {
            $data = $r->validate(['date' => 'required|date', 'kind' => 'required|string|max:12', 'beneficiary' => 'required|string|max:150', 'description' => 'nullable|string|max:500',
                'amount' => 'nullable|numeric|min:0', 'hours' => 'nullable|numeric|min:0', 'volunteers' => 'nullable|integer|min:0', 'people_reached' => 'nullable|integer|min:0', 'evidence' => self::FILE_RULE]);
            $c = ES::saveCommunity($data, $r->user(), $r->file('evidence'));

            return "Community contribution {$c->number} recorded.";
        }, 'Saved.');
    }

    public function communityFile(EsgCommunity $esgcommunity)
    {
        abort_unless($esgcommunity->evidence_path && Storage::disk('local')->exists($esgcommunity->evidence_path), 404);

        return Storage::disk('local')->download($esgcommunity->evidence_path, $esgcommunity->evidence_name ?? basename($esgcommunity->evidence_path));
    }

    // ------------------------------------------------------------------ certification

    public function certification(Request $r)
    {
        $items = EsgChecklistItem::with(['owner', 'reviewer'])->when($r->filled('status'), fn ($q) => $q->where('status', $r->query('status')))->orderBy('sort')->get();

        return view('esg.certification', ['items' => $items->groupBy('section'), 'score' => ES::certScore(), 'users' => $this->users()]);
    }

    public function assess(Request $r, EsgChecklistItem $esgitem)
    {
        return $this->attempt(fn () => ES::assess($esgitem, $r->validate(['status' => 'required|string|max:8', 'evidence' => 'nullable|string|max:500', 'owner_user_id' => 'nullable|integer|owned:users']), $r->user()),
            "{$esgitem->code} updated.");
    }

    // ------------------------------------------------------------------ policies

    public function policies()
    {
        return view('esg.policies', ['list' => EsgPolicy::with('owner')->orderByRaw("FIELD(pillar, 'environment', 'social', 'governance')")->orderBy('code')->get(), 'gov' => ES::governance(), 'users' => $this->users()]);
    }

    protected function policyData(Request $r): array
    {
        return $r->validate(['code' => 'required|string|max:20', 'title' => 'required|string|max:150', 'pillar' => 'required|string|max:12', 'owner_user_id' => 'nullable|integer|owned:users',
            'version' => 'nullable|string|max:10', 'approved_on' => 'nullable|date', 'approved_by_name' => 'nullable|string|max:120', 'review_months' => 'required|integer|min:1|max:60',
            'status' => 'required|string|max:10', 'summary' => 'nullable|string|max:500', 'file' => self::FILE_RULE]);
    }

    public function policyStore(Request $r)
    {
        return $this->attempt(fn () => ES::savePolicy($this->policyData($r), $r->user(), null, $r->file('file')), 'Policy added.');
    }

    public function policyUpdate(Request $r, EsgPolicy $esgpolicy)
    {
        return $this->attempt(fn () => ES::savePolicy($this->policyData($r), $r->user(), $esgpolicy, $r->file('file')), "Policy {$esgpolicy->code} updated.");
    }

    public function policyFile(EsgPolicy $esgpolicy)
    {
        abort_unless($esgpolicy->file_path && Storage::disk('local')->exists($esgpolicy->file_path), 404);

        return Storage::disk('local')->download($esgpolicy->file_path, $esgpolicy->file_name ?? basename($esgpolicy->file_path));
    }

    // ------------------------------------------------------------------ carbon statements

    public function statements(Request $r)
    {
        $b = ES::benchmark();
        $st = null;
        $err = null;
        if ($r->filled('type') && $r->filled('id')) {
            try {
                $st = ES::statement((string) $r->query('type'), (int) $r->query('id'), $r->filled('year') ? (int) $r->query('year') : null);
            } catch (RuntimeException $e) {
                $err = $e->getMessage();
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                $err = 'That record was not found.';
            }
        }

        return view('esg.statements', ['b' => $b, 'st' => $st, 'err' => $err,
            'bookings' => Reservation::with('guest', 'company')->whereIn('status', ['confirmed', 'checked_in', 'checked_out', 'tentative'])->orderByDesc('arrival_date')->limit(150)->get(['id', 'reservation_no', 'guest_id', 'company_id', 'arrival_date', 'departure_date']),
            'events' => BanquetEvent::whereIn('status', ['definite', 'completed'])->orderByDesc('event_date')->limit(100)->get(['id', 'event_no', 'title', 'event_date']),
            'companies' => Company::orderBy('name')->get(['id', 'name'])]);
    }

    public function statementPrint(Request $r)
    {
        try {
            $st = ES::statement((string) $r->query('type'), (int) $r->query('id'), $r->filled('year') ? (int) $r->query('year') : null);
        } catch (RuntimeException $e) {
            return redirect()->route('esg.statements')->with('error', $e->getMessage());
        }
        ActivityLogger::log('esg', 'statement', "Carbon statement printed: {$st['title']}");

        return view('esg.statement_print', ['st' => $st]);
    }

    // ------------------------------------------------------------------ annual report

    public function report(Request $r)
    {
        $year = (int) ($r->query('year') ?: bdate()->year);
        try {
            $a = ES::annual($year);
        } catch (RuntimeException $e) {
            return redirect()->route('esg.report')->with('error', $e->getMessage());
        }

        return view('esg.report', ['a' => $a, 'years' => range((int) bdate()->year, (int) bdate()->year - 4), 'print' => false]);
    }

    public function reportPrint(Request $r)
    {
        $year = (int) ($r->query('year') ?: bdate()->year);
        try {
            $a = ES::annual($year);
        } catch (RuntimeException $e) {
            return redirect()->route('esg.report')->with('error', $e->getMessage());
        }

        return view('esg.report_print', ['a' => $a, 'print' => true]);
    }

    // ------------------------------------------------------------------ settings

    public function settings()
    {
        return view('esg.settings', ['cfg' => ES::cfg(), 'raw' => collect(ES::DEFAULTS)->map(fn ($v, $k) => setting($k, $v))->all()]);
    }

    public function saveSettings(Request $r)
    {
        return $this->attempt(fn () => ES::saveSettings($r->all()), 'ESG settings saved.');
    }
}
