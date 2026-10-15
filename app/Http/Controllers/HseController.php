<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Employee;
use App\Models\HseAccident;
use App\Models\HseCheck;
use App\Models\HseDrill;
use App\Models\HseEquipment;
use App\Models\HsePermit;
use App\Models\HseRisk;
use App\Models\HseWarden;
use App\Models\MaintenanceRequest;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\SecurityIncident;
use App\Models\User;
use App\Services\HseService as HS;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** v6.7 Health, safety & fire: dashboard, fire equipment & round, permits to work, accidents, risk assessments, drills & wardens. */
class HseController extends Controller
{
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

    // ------------------------------------------------------------------ dashboard

    public function index()
    {
        return view('hse.index', ['k' => HS::kpis(), 'eq' => HS::equipmentStats(), 'alerts' => HS::alerts(), 'due' => HS::dueList(8), 'drills' => HS::drillStatus(),
            'cover' => HS::coverage(), 'heat' => HS::heatmap(),
            'live' => HsePermit::with(['issuer'])->whereIn('status', ['issued', 'requested'])->orderByRaw("FIELD(status, 'issued', 'requested')")->orderBy('ends_at')->get(),
            'recent' => HseAccident::with(['employee', 'guest'])->orderByDesc('occurred_at')->orderByDesc('id')->limit(6)->get(),
            'reviews' => HseRisk::where('status', '!=', 'archived')->where(fn ($q) => $q->whereDate('review_on', '<', bdate()->toDateString())->orWhere('status', 'pending'))->orderBy('review_on')->limit(6)->get(),
            'trend' => $this->monthly(), 'lk' => $this->accidentLookups()]);
    }

    /** Accidents and near misses per month, last 12 months. */
    protected function monthly(): array
    {
        $out = [];
        $start = bdate()->copy()->startOfMonth()->subMonths(11);
        $rows = HseAccident::where('occurred_at', '>=', $start)->get(['kind', 'occurred_at']);
        for ($i = 0; $i < 12; $i++) {
            $m = $start->copy()->addMonths($i);
            $in = $rows->filter(fn ($a) => $a->occurred_at->format('Y-m') === $m->format('Y-m'));
            $out[] = ['label' => $m->format('M'), 'injury' => $in->where('kind', 'injury')->count(), 'near' => $in->where('kind', 'near_miss')->count(), 'dangerous' => $in->where('kind', 'dangerous')->count()];
        }

        return $out;
    }

    // ------------------------------------------------------------------ fire equipment

    public function equipment(Request $r)
    {
        $items = HseEquipment::with('ticket')->when($r->filled('type'), fn ($q) => $q->where('type', $r->query('type')))
            ->when($r->filled('floor'), fn ($q) => $q->where('floor', $r->query('floor')))
            ->when($r->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('code', 'like', '%'.$r->query('q').'%')->orWhere('location', 'like', '%'.$r->query('q').'%')->orWhere('gnfs_tag', 'like', '%'.$r->query('q').'%')))
            ->when(! $r->boolean('retired'), fn ($q) => $q->where('status', '!=', 'retired'))
            ->orderBy('code')->get()->each(fn ($e) => $e->setAttribute('st', $e->state()));
        if ($r->filled('state')) {
            $items = $items->where('st', $r->query('state'))->values();
        }

        return view('hse.equipment', ['items' => $items, 'eq' => HS::equipmentStats(), 'floors' => HseEquipment::whereNotNull('floor')->distinct()->orderBy('floor')->pluck('floor'),
            'rooms' => Room::orderBy('room_no')->get(['id', 'room_no'])]);
    }

    public function equipmentShow(HseEquipment $hseequipment)
    {
        return view('hse.equipment_show', ['e' => $hseequipment->load(['checks.user', 'checks.ticket', 'ticket', 'room']), 'rooms' => Room::orderBy('room_no')->get(['id', 'room_no'])]);
    }

    public function equipmentSave(Request $r, ?HseEquipment $hseequipment = null)
    {
        return $this->attempt(function () use ($r, $hseequipment) {
            $e = HS::saveEquipment($r->all(), $r->user(), $hseequipment?->exists ? $hseequipment : null);

            return $hseequipment?->exists ? "{$e->code} saved." : redirect()->route('hse.equipment.show', $e)->with('success', "{$e->code} added – first check due {$e->next_check_on->format('d M')}.");
        }, 'Saved.');
    }

    public function check(Request $r, HseEquipment $hseequipment)
    {
        return $this->attempt(function () use ($r, $hseequipment) {
            $c = HS::recordCheck($hseequipment, $r->all(), $r->user());

            return $c->result === 'pass' ? "{$hseequipment->code} passed – next check ".$hseequipment->fresh()->next_check_on->format('d M').'.'
                : "{$hseequipment->code} FAILED and is marked defective".($c->ticket ? " – engineering ticket {$c->ticket->ticket_no} raised" : '').'.';
        }, 'Recorded.');
    }

    public function round(Request $r)
    {
        $today = bdate()->toDateString();
        $scope = $r->query('scope', 'due');
        $items = HseEquipment::where('status', '!=', 'retired')
            ->when($r->filled('floor'), fn ($q) => $q->where('floor', $r->query('floor')))
            ->when($scope === 'due', fn ($q) => $q->where(fn ($w) => $w->whereNull('next_check_on')->orWhereDate('next_check_on', '<=', bdate()->copy()->addDays(7)->toDateString())->orWhereIn('status', ['defective', 'out_of_service'])))
            ->orderBy('floor')->orderBy('code')->get()->each(fn ($e) => $e->setAttribute('st', $e->state($today)));

        return view('hse.round', ['items' => $items, 'scope' => $scope, 'floors' => HseEquipment::whereNotNull('floor')->distinct()->orderBy('floor')->pluck('floor')]);
    }

    public function roundSave(Request $r)
    {
        return $this->attempt(function () use ($r) {
            $n = HS::round((string) ($r->input('checked_on') ?: bdate()->toDateString()), (array) $r->input('rows', []), $r->user());

            return "Round saved: {$n['pass']} passed, {$n['fail']} failed".($n['fail'] ? ' – defects sent to engineering' : '').'.';
        }, 'Saved.');
    }

    // ------------------------------------------------------------------ permits to work

    public function permits(Request $r)
    {
        $permits = HsePermit::with(['requester', 'issuer'])->when($r->filled('status'), fn ($q) => $q->where('status', $r->query('status')))
            ->when($r->filled('type'), fn ($q) => $q->where('type', $r->query('type')))
            ->orderByRaw("FIELD(status, 'issued', 'requested', 'closed', 'cancelled')")->orderByDesc('starts_at')->orderByDesc('id')->paginate(30)->withQueryString();

        return view('hse.permits', ['permits' => $permits, 'tickets' => MaintenanceRequest::whereIn('status', ['open', 'in_progress'])->orderByDesc('id')->limit(60)->get(['id', 'ticket_no', 'title'])]);
    }

    public function permit(HsePermit $hsepermit)
    {
        return view('hse.permit', ['p' => $hsepermit->load(['requester', 'issuer', 'closer', 'ticket'])]);
    }

    public function permitPrint(HsePermit $hsepermit)
    {
        return view('hse.permit_print', ['p' => $hsepermit->load(['requester', 'issuer', 'closer', 'ticket'])]);
    }

    public function permitStore(Request $r)
    {
        return $this->attempt(fn () => redirect()->route('hse.permit', $p = HS::requestPermit($r->all(), $r->user()))->with('success', "{$p->number} requested – an authorised issuer must check the precautions and issue it."), 'Requested.');
    }

    public function permitIssue(Request $r, HsePermit $hsepermit)
    {
        return $this->attempt(fn () => "{$hsepermit->number} issued – work may proceed until ".HS::issuePermit($hsepermit, $r->all(), $r->user())->ends_at->format('d M H:i').'.', 'Issued.');
    }

    public function permitClose(Request $r, HsePermit $hsepermit)
    {
        return $this->attempt(fn () => HS::closePermit($hsepermit, $r->all(), $r->user()) ? "{$hsepermit->number} closed out." : '', 'Closed.');
    }

    public function permitCancel(Request $r, HsePermit $hsepermit)
    {
        return $this->attempt(fn () => HS::cancelPermit($hsepermit, (string) $r->input('reason'), $r->user()) ? "{$hsepermit->number} cancelled." : '', 'Cancelled.');
    }

    // ------------------------------------------------------------------ accidents

    protected function accidentLookups(): array
    {
        return [
            'employees' => Employee::where('is_active', true)->orderBy('name')->get(['id', 'name', 'designation']),
            'stays' => ReservationRoom::with(['guest', 'room'])->where('status', 'checked_in')->get()->sortBy(fn ($s) => $s->room?->room_no ?? 'zzz')->values(),
            'rooms' => Room::orderBy('room_no')->get(['id', 'room_no']),
            'incidents' => SecurityIncident::whereIn('type', ['accident', 'medical', 'fire_alarm'])->where('occurred_at', '>=', now()->subDays(30))->orderByDesc('occurred_at')->get(['id', 'incident_no', 'title', 'occurred_at']),
        ];
    }

    public function accidents(Request $r)
    {
        [$from, $to] = [$r->query('from', bdate()->copy()->subMonths(12)->toDateString()), $r->query('to', bdate()->toDateString())];
        $rows = HseAccident::with(['employee', 'guest'])->whereBetween('occurred_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($r->filled('kind'), fn ($q) => $q->where('kind', $r->query('kind')))
            ->when($r->filled('status'), fn ($q) => $q->where('status', $r->query('status')))
            ->when($r->boolean('reportable'), fn ($q) => $q->where('reportable', true))
            ->orderByDesc('occurred_at')->orderByDesc('id')->paginate(30)->withQueryString();

        return view('hse.accidents', ['rows' => $rows, 'from' => $from, 'to' => $to, 'k' => HS::kpis(), 'alerts' => HS::alerts(), 'lk' => $this->accidentLookups()]);
    }

    public function accident(HseAccident $hseaccident)
    {
        return view('hse.accident', ['a' => $hseaccident->load(['employee.department', 'guest', 'room', 'incident', 'ticket', 'reporter']), 'cfg' => HS::cfg()]);
    }

    public function notice(HseAccident $hseaccident)
    {
        abort_unless($hseaccident->reportable, 404);

        return view('hse.notice', ['a' => $hseaccident->load(['employee.department', 'guest', 'reporter']), 'cfg' => HS::cfg()]);
    }

    public function accidentStore(Request $r)
    {
        return $this->attempt(function () use ($r) {
            $d = $r->all();
            if (($d['person_type'] ?? '') === 'guest' && filled($d['stay_id'] ?? null) && ($s = ReservationRoom::find((int) $d['stay_id']))) {
                $d['guest_id'] = $s->guest_id;
                $d['room_id'] = $d['room_id'] ?? $s->room_id;
            }
            $a = HS::reportAccident($d, $r->user());

            return redirect()->route('hse.accident', $a)->with($a->reportable ? 'error' : 'success', $a->reportable
                ? "{$a->number} recorded. This is notifiable under the Factories, Offices and Shops Act – send the written notice to the Factories Inspectorate forthwith."
                : "{$a->number} recorded.");
        }, 'Recorded.');
    }

    public function accidentUpdate(Request $r, HseAccident $hseaccident)
    {
        return $this->attempt(function () use ($r, $hseaccident) {
            $a = HS::updateAccident($hseaccident, $r->all(), $r->user());

            return $a->reportable && ! $a->notified_on ? "{$a->number} updated – it is now notifiable: send the notice to the Factories Inspectorate." : "{$a->number} updated.";
        }, 'Updated.');
    }

    public function accidentNotify(Request $r, HseAccident $hseaccident)
    {
        return $this->attempt(fn () => HS::notifyAccident($hseaccident, $r->all(), $r->user()) ? "Notice to the Factories Inspectorate recorded for {$hseaccident->number}." : '', 'Recorded.');
    }

    public function accidentInvestigate(Request $r, HseAccident $hseaccident)
    {
        return $this->attempt(function () use ($r, $hseaccident) {
            $a = HS::investigateAccident($hseaccident, $r->all(), $r->user());

            return "Investigation saved".($a->ticket ? " – ticket {$a->ticket->ticket_no} raised" : '').'.';
        }, 'Saved.');
    }

    public function accidentClose(Request $r, HseAccident $hseaccident)
    {
        return $this->attempt(fn () => HS::closeAccident($hseaccident, $r->user()) ? "{$hseaccident->number} closed." : '', 'Closed.');
    }

    // ------------------------------------------------------------------ risk assessments

    public function risks(Request $r)
    {
        $risks = HseRisk::with(['owner', 'department'])->when($r->filled('status'), fn ($q) => $q->where('status', $r->query('status')), fn ($q) => $q->where('status', '!=', 'archived'))
            ->when($r->filled('area'), fn ($q) => $q->where('area', $r->query('area')))->orderBy('number')->get();
        if ($r->filled('band')) {
            $risks = $risks->filter(fn ($x) => HseRisk::band($x->residual())[0] === $r->query('band'))->values();
        }

        return view('hse.risks', ['risks' => $risks, 'heat' => HS::heatmap(), 'areas' => HseRisk::distinct()->orderBy('area')->pluck('area'), 'users' => $this->users(),
            'departments' => Department::orderBy('name')->get(['id', 'name'])]);
    }

    public function risk(HseRisk $hserisk)
    {
        return view('hse.risk', ['x' => $hserisk->load(['owner', 'assessor', 'acceptor', 'department']), 'users' => $this->users(), 'departments' => Department::orderBy('name')->get(['id', 'name'])]);
    }

    public function riskSave(Request $r, ?HseRisk $hserisk = null)
    {
        return $this->attempt(function () use ($r, $hserisk) {
            $x = HS::saveRisk($r->all(), $r->user(), $hserisk?->exists ? $hserisk : null);
            $msg = $x->status === 'pending' ? "{$x->number} saved – residual risk {$x->residual()} is high and awaits acceptance by another manager." : "{$x->number} saved.";

            return $hserisk?->exists ? $msg : redirect()->route('hse.risk', $x)->with('success', $msg);
        }, 'Saved.');
    }

    public function riskAccept(Request $r, HseRisk $hserisk)
    {
        return $this->attempt(fn () => HS::acceptRisk($hserisk, $r->user()) ? "{$hserisk->number} accepted." : '', 'Accepted.');
    }

    public function riskReview(Request $r, HseRisk $hserisk)
    {
        return $this->attempt(fn () => "{$hserisk->number} reviewed – next review ".HS::reviewRisk($hserisk, $r->all(), $r->user())->review_on->format('d M Y').'.', 'Reviewed.');
    }

    public function riskArchive(Request $r, HseRisk $hserisk)
    {
        return $this->attempt(fn () => HS::archiveRisk($hserisk, $r->boolean('archive'), $r->user()) ? "{$hserisk->number} ".($r->boolean('archive') ? 'archived' : 'restored').'.' : '', 'Saved.');
    }

    // ------------------------------------------------------------------ drills & wardens

    public function drills()
    {
        return view('hse.drills', ['drills' => HseDrill::with('leader')->orderByRaw("FIELD(status, 'planned', 'done', 'cancelled')")->orderByDesc('scheduled_at')->limit(60)->get(),
            'status' => HS::drillStatus(), 'cover' => HS::coverage(), 'cfg' => HS::cfg(), 'users' => $this->users(),
            'wardens' => HseWarden::with('employee.department')->orderByRaw("FIELD(shift, 'morning', 'evening', 'night')")->orderByRaw("FIELD(role, 'chief', 'floor', 'first_aider', 'fire_team')")->get(),
            'employees' => Employee::where('is_active', true)->orderBy('name')->get(['id', 'name', 'designation'])]);
    }

    public function drill(HseDrill $hsedrill)
    {
        return view('hse.drill', ['x' => $hsedrill->load(['staff', 'leader']), 'employees' => Employee::where('is_active', true)->orderBy('name')->get(['id', 'name', 'designation'])]);
    }

    public function drillStore(Request $r)
    {
        return $this->attempt(fn () => redirect()->route('hse.drill', $x = HS::scheduleDrill($r->all(), $r->user()))->with('success', "{$x->number} scheduled."), 'Scheduled.');
    }

    public function drillRecord(Request $r, HseDrill $hsedrill)
    {
        return $this->attempt(fn () => "{$hsedrill->number} recorded – ".(HS::recordDrill($hsedrill, $r->all(), $r->user())->result === 'pass' ? 'PASS.' : 'FAIL: see the improvements.'), 'Recorded.');
    }

    public function drillCancel(Request $r, HseDrill $hsedrill)
    {
        return $this->attempt(function () use ($r, $hsedrill) {
            HS::cancelDrill($hsedrill, (string) $r->input('reason'), $r->user());

            return "{$hsedrill->number} cancelled.";
        }, 'Cancelled.');
    }

    public function wardenSave(Request $r, ?HseWarden $hsewarden = null)
    {
        return $this->attempt(fn () => HS::saveWarden($r->all(), $r->user(), $hsewarden?->exists ? $hsewarden : null)->employee->name.' saved.', 'Saved.');
    }

    // ------------------------------------------------------------------ settings

    public function settings()
    {
        return view('hse.settings', ['cfg' => HS::cfg()]);
    }

    public function saveSettings(Request $r)
    {
        return $this->attempt(function () use ($r) {
            HS::saveSettings($r->all());

            return 'Health & safety settings saved.';
        }, 'Saved.');
    }
}
