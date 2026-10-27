<?php

namespace App\Http\Controllers;

use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\SecurityIncident;
use App\Models\User;
use App\Services\IncidentService;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/** v4.2 – Security › Incident register. */
class IncidentController extends Controller
{
    protected function guarded(callable $fn)
    {
        try {
            return $fn();
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    protected function formData(): array
    {
        return ['rooms' => Room::orderBy('room_no')->pluck('room_no', 'id'),
            'stays' => ReservationRoom::with('room', 'guest', 'reservation.guest')->where(fn ($q) => $q->where('status', 'checked_in')
                ->orWhere(fn ($w) => $w->where('status', 'checked_out')->where('checked_out_at', '>=', now()->subDays(3))))->get()->sortBy(fn ($s) => $s->room?->room_no)
                ->mapWithKeys(fn ($s) => [$s->id => ($s->room?->room_no ?? '—').' · '.($s->guest ?? $s->reservation?->guest)?->full_name.($s->status === 'checked_out' ? ' (departed)' : '')]),
            'users' => User::where('hotel_id', Tenant::id())->where('is_active', true)->orderBy('name')->pluck('name', 'id')];
    }

    public function index(Request $request)
    {
        $status = in_array($request->query('status'), ['open', 'investigating', 'closed', 'all'], true) ? $request->query('status') : 'active';
        $type = array_key_exists((string) $request->query('type'), SecurityIncident::TYPES) ? $request->query('type') : null;
        $sev = array_key_exists((string) $request->query('severity'), SecurityIncident::RANK) ? $request->query('severity') : null;
        $list = SecurityIncident::with('room', 'assignee', 'guest')
            ->when($status === 'active', fn ($q) => $q->where('status', '!=', 'closed'))
            ->when(in_array($status, ['open', 'investigating', 'closed'], true), fn ($q) => $q->where('status', $status))
            ->when($type, fn ($q) => $q->where('type', $type))->when($sev, fn ($q) => $q->where('severity', $sev))
            ->orderByRaw("FIELD(severity, 'critical', 'high', 'medium', 'low')")->latest('occurred_at')->paginate(40)->withQueryString();
        $from = bdate()->copy()->startOfMonth();
        $stats = ['open' => SecurityIncident::where('status', 'open')->count(), 'investigating' => SecurityIncident::where('status', 'investigating')->count(),
            'serious' => SecurityIncident::where('status', '!=', 'closed')->whereIn('severity', ['high', 'critical'])->count(),
            'month' => SecurityIncident::where('occurred_at', '>=', $from)->count(),
            'loss_month' => (float) SecurityIncident::where('occurred_at', '>=', $from)->sum('loss_amount'),
            'by_type' => SecurityIncident::where('occurred_at', '>=', now()->subDays(90))->selectRaw('type, count(*) n')->groupBy('type')->orderByDesc('n')->pluck('n', 'type')];

        return view('security.incidents', ['list' => $list, 'status' => $status, 'type' => $type, 'severity' => $sev, 'stats' => $stats] + (can_do('security.incidents') ? $this->formData() : []));
    }

    protected function rules(): array
    {
        return ['type' => ['required', Rule::in(array_keys(SecurityIncident::TYPES))], 'severity' => ['required', Rule::in(array_keys(SecurityIncident::RANK))],
            'occurred_at' => 'required|date', 'location' => 'required|string|max:150', 'room_id' => 'nullable|integer|owned:rooms',
            'reservation_room_id' => 'nullable|integer|owned:reservation_rooms', 'title' => 'required|string|max:200', 'description' => 'required|string|max:5000',
            'persons_involved' => 'nullable|string|max:2000', 'action_taken' => 'nullable|string|max:2000', 'injuries' => 'nullable|boolean',
            'loss_amount' => 'nullable|numeric|min:0|max:99999999', 'police_informed' => 'nullable|boolean', 'police_station' => 'nullable|string|max:120',
            'police_ref' => 'nullable|string|max:60', 'assigned_to' => 'nullable|integer'];
    }

    public function store(Request $request)
    {
        $d = $request->validate($this->rules());

        return $this->guarded(function () use ($d) {
            $i = IncidentService::report($d);

            return redirect()->route('security.incidents.show', $i)->with('success', "Incident {$i->incident_no} recorded".($i->log_entry_id ? ' – the duty manager has been alerted in the log book.' : '.'));
        });
    }

    public function show(SecurityIncident $incident)
    {
        return view('security.incident', ['i' => $incident->load('updates.user', 'room', 'stay.reservation', 'guest', 'assignee', 'creator', 'closer')] + (can_do('security.incidents') ? $this->formData() : []));
    }

    public function update(Request $request, SecurityIncident $incident)
    {
        $d = $request->validate($this->rules());

        return $this->guarded(function () use ($incident, $d) {
            $incident = IncidentService::update($incident, $d);

            return back()->with('success', "Incident {$incident->incident_no} updated.");
        });
    }

    public function note(Request $request, SecurityIncident $incident)
    {
        $d = $request->validate(['note' => 'required|string|max:4000', 'status' => 'nullable|in:open,investigating']);

        return $this->guarded(function () use ($incident, $d) {
            IncidentService::note($incident, $d['note'], $d['status'] ?? null);

            return back()->with('success', 'Follow-up added.');
        });
    }

    public function close(Request $request, SecurityIncident $incident)
    {
        $d = $request->validate(['resolution' => 'required|string|max:4000']);

        return $this->guarded(function () use ($incident, $d) {
            $incident = IncidentService::close($incident, $d['resolution']);

            return back()->with('success', "Incident {$incident->incident_no} closed.");
        });
    }

    public function reopen(Request $request, SecurityIncident $incident)
    {
        $d = $request->validate(['reason' => 'required|string|max:255']);

        return $this->guarded(function () use ($incident, $d) {
            $incident = IncidentService::reopen($incident, $d['reason']);

            return back()->with('success', "Incident {$incident->incident_no} reopened.");
        });
    }
}
