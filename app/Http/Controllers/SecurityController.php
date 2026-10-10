<?php

namespace App\Http\Controllers;

use App\Models\ReservationRoom;
use App\Models\SecurityIncident;
use App\Models\SecurityWatch;
use App\Models\User;
use App\Models\Visitor;
use App\Services\ActivityLogger;
use App\Services\EInvoiceService;
use App\Services\SecurityService;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/** v4.2 – Security › Visitors: on-site board, expected (pre-registered) visitors, visitor log, watch list, settings. */
class SecurityController extends Controller
{
    public const TABS = ['onsite' => 'On site now', 'expected' => 'Expected', 'log' => 'Visitor log', 'watchlist' => 'Watch list', 'settings' => 'Settings'];

    protected function guarded(callable $fn)
    {
        try {
            return $fn();
        } catch (RuntimeException $e) {
            return back()->withInput(request()->except('id_number'))->with('error', $e->getMessage()); // never flash a raw ID number into the session
        }
    }

    public function index(Request $request)
    {
        $tab = array_key_exists($request->query('tab'), self::TABS) ? $request->query('tab') : 'onsite';
        if (in_array($tab, ['watchlist', 'settings'], true) && ! can_do('security.manage')) {
            $tab = 'onsite';
        }
        $v = ['tab' => $tab, 'cfg' => SecurityService::cfg(), 'snap' => SecurityService::snapshot()];
        if (can_do('security.visitors')) {   // the "Register visitor" dialog is on every tab
            $v['stays'] = ReservationRoom::with('room', 'guest', 'reservation.guest')->where('status', 'checked_in')->get()
                ->sortBy(fn ($s) => $s->room?->room_no)->values();
            $v['staff'] = User::where('hotel_id', Tenant::id())->where('is_active', true)->orderBy('name')->pluck('name', 'id');
            $v['events'] = SecurityService::eventsNow()->get();
        }
        switch ($tab) {
            case 'onsite':
                $v['list'] = Visitor::with('room', 'watch', 'stay')->whereIn('status', ['awaiting', 'in'])
                    ->orderByRaw("status = 'awaiting' desc")->orderBy('valid_until')->get();
                $v['private'] = ReservationRoom::with('room', 'guest', 'reservation.guest')->where('status', 'checked_in')->where('visitor_policy', '!=', 'allow')->get();
                $v['arriving'] = Visitor::with('room')->where('status', 'expected')->whereBetween('expected_at', [now()->subHours(3), now()->addHours(2)])->orderBy('expected_at')->get();
                break;
            case 'expected':
                $v['list'] = Visitor::with('room', 'stay')->where('status', 'expected')->orderBy('expected_at')->get();
                break;
            case 'log':
                $from = $request->date('from') ?? now()->subDays(6)->startOfDay();
                $to = $request->date('to') ?? now();
                $q = trim((string) $request->query('q'));
                $v += ['from' => $from, 'to' => $to, 'q' => $q, 'status' => $request->query('status')];
                $v['list'] = Visitor::with('room', 'inBy', 'outBy', 'watch')
                    ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('pass_no', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%")
                        ->orWhere('phone', 'like', "%{$q}%")->orWhere('vehicle_no', 'like', "%{$q}%")->orWhere('host_name', 'like', "%{$q}%")))
                    ->when($q === '', fn ($w) => $w->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]))
                    ->when(array_key_exists((string) $request->query('status'), Visitor::STATUSES), fn ($w) => $w->where('status', $request->query('status')))
                    ->latest('id')->paginate(50)->withQueryString();
                break;
            case 'watchlist':
                $v['list'] = SecurityWatch::with('creator')->orderByDesc('is_active')->orderBy('name')->get();
                $v['hits'] = Visitor::whereNotNull('watchlist_id')->where('created_at', '>=', now()->subDays(90))->selectRaw('watchlist_id, count(*) n')->groupBy('watchlist_id')->pluck('n', 'watchlist_id');
                break;
        }

        return view('security.visitors', $v);
    }

    protected function visitorRules(): array
    {
        return ['name' => 'required|string|max:120', 'phone' => 'nullable|string|max:30', 'company' => 'nullable|string|max:120',
            'id_type' => ['nullable', Rule::in(array_keys(Visitor::ID_TYPES))], 'id_number' => 'nullable|string|max:40',
            'purpose' => ['required', Rule::in(array_keys(Visitor::PURPOSES))], 'host_type' => ['required', Rule::in(array_keys(Visitor::HOSTS))],
            'reservation_room_id' => 'nullable|required_if:host_type,guest|integer|owned:reservation_rooms', 'host_user_id' => 'nullable|required_if:host_type,staff|integer',
            'banquet_event_id' => 'nullable|required_if:host_type,event|integer|owned:banquet_events', 'persons' => 'required|integer|min:1|max:50',
            'vehicle_no' => 'nullable|string|max:20', 'items_declared' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:500',
            'guest_confirmed' => 'nullable|boolean', 'override_reason' => 'nullable|string|max:255'];
    }

    public function store(Request $request)
    {
        $d = $request->validate($this->visitorRules());

        return $this->guarded(function () use ($d) {
            $v = SecurityService::register($d);
            $msg = $v->status === 'awaiting'
                ? "Visitor {$v->pass_no} registered – call {$v->hostLabel()} and approve once the guest says yes."
                : "Visitor {$v->pass_no} {$v->name} checked in – pass valid until ".$v->valid_until->format('H:i').'.';
            $r = redirect()->route('security.visitors')->with('success', $msg)->with('badge_for', $v->status === 'in' ? $v->id : null);

            return $v->watchlist_id ? $r->with('warning', 'Watch-list match: '.$v->watch->reason.' – security has been alerted in the log book.') : $r;
        });
    }

    public function approve(Request $request, Visitor $visitor)
    {
        $d = $request->validate(['via' => ['nullable', Rule::in(['phone', 'host'])]]);

        return $this->guarded(function () use ($visitor, $d) {
            SecurityService::approve($visitor, $d['via'] ?? 'phone');

            return back()->with('success', "Visitor {$visitor->pass_no} {$visitor->name} may go up – pass valid until ".$visitor->valid_until->format('H:i').'.')->with('badge_for', $visitor->id);
        });
    }

    public function deny(Request $request, Visitor $visitor)
    {
        $d = $request->validate(['reason' => 'required|string|max:255']);

        return $this->guarded(function () use ($visitor, $d) {
            SecurityService::deny($visitor, $d['reason']);

            return back()->with('success', "Visitor {$visitor->pass_no} turned away.");
        });
    }

    public function arrive(Request $request, Visitor $visitor)
    {
        $d = $request->validate(['id_type' => ['nullable', Rule::in(array_keys(Visitor::ID_TYPES))], 'id_number' => 'nullable|string|max:40',
            'phone' => 'nullable|string|max:30', 'vehicle_no' => 'nullable|string|max:20', 'override_reason' => 'nullable|string|max:255']);

        return $this->guarded(function () use ($visitor, $d) {
            SecurityService::arrive($visitor, $d);
            $r = back()->with('success', "Expected visitor {$visitor->pass_no} {$visitor->name} checked in.")->with('badge_for', $visitor->id);

            return $visitor->watchlist_id ? $r->with('warning', 'Watch-list match: '.$visitor->watch->reason.' – security has been alerted.') : $r;
        });
    }

    public function checkOut(Visitor $visitor)
    {
        return $this->guarded(function () use ($visitor) {
            SecurityService::checkOut($visitor);

            return back()->with('success', "Visitor {$visitor->pass_no} {$visitor->name} checked out.");
        });
    }

    public function cancel(Visitor $visitor)
    {
        return $this->guarded(function () use ($visitor) {
            SecurityService::cancel($visitor, 'Cancelled by the desk');

            return back()->with('success', "Expected visitor {$visitor->pass_no} cancelled.");
        });
    }

    public function extend(Request $request, Visitor $visitor)
    {
        $d = $request->validate(['hours' => 'required|integer|min:1|max:12']);

        return $this->guarded(function () use ($visitor, $d) {
            SecurityService::extend($visitor, (int) $d['hours']);

            return back()->with('success', "Pass {$visitor->pass_no} extended to ".$visitor->valid_until->format('H:i').'.');
        });
    }

    public function badge(Visitor $visitor)
    {
        abort_unless(in_array($visitor->status, ['in', 'expected', 'awaiting'], true), 404);

        return view('security.badge', ['v' => $visitor->load('room'), 'qr' => EInvoiceService::qrSvg($visitor->pass_no), 'hotel' => hotel()]);
    }

    public function policy(Request $request, ReservationRoom $stay)
    {
        $d = $request->validate(['policy' => ['required', Rule::in(array_keys(SecurityService::POLICIES))]]);

        return $this->guarded(function () use ($stay, $d) {
            SecurityService::setPolicy($stay, $d['policy']);

            return back()->with('success', 'Visitor preference for room '.($stay->room?->room_no ?? '?').': '.SecurityService::POLICIES[$d['policy']].'.');
        });
    }

    // ───────── watch list ─────────

    protected function watchRules(): array
    {
        return ['name' => 'required|string|max:120', 'phone' => 'nullable|string|max:30', 'id_type' => ['nullable', Rule::in(array_keys(Visitor::ID_TYPES))],
            'id_number' => 'nullable|string|max:40', 'level' => 'required|in:block,alert', 'reason' => 'required|string|max:255',
            'valid_until' => 'nullable|date|after_or_equal:today'];
    }

    public function storeWatch(Request $request)
    {
        $d = $request->validate($this->watchRules());

        return $this->guarded(function () use ($d) {
            $w = SecurityService::saveWatch($d);

            return redirect()->route('security.visitors', ['tab' => 'watchlist'])->with('success', "{$w->name} added to the watch list.");
        });
    }

    public function updateWatch(Request $request, SecurityWatch $watch)
    {
        $d = $request->validate($this->watchRules());

        return $this->guarded(function () use ($d, $watch) {
            SecurityService::saveWatch($d, $watch);

            return redirect()->route('security.visitors', ['tab' => 'watchlist'])->with('success', "Watch-list entry for {$watch->name} updated.");
        });
    }

    public function toggleWatch(SecurityWatch $watch)
    {
        $watch->update(['is_active' => ! $watch->is_active]);
        ActivityLogger::log('security', 'watchlist', ($watch->is_active ? 'Re-activated' : 'Lifted').' watch-list entry: '.$watch->name, $watch);

        return back()->with('success', $watch->is_active ? "{$watch->name} is back on the watch list." : "Watch-list entry for {$watch->name} lifted.");
    }

    public function settings(Request $request)
    {
        $d = $request->validate(['visiting_until' => 'required|date_format:H:i', 'approval' => 'required|in:always,policy', 'pass_hours' => 'required|integer|min:1|max:24',
            'require_id' => 'nullable|boolean', 'retention_days' => 'required|integer|min:30|max:3650', 'gp_approval' => 'nullable|boolean',
            'incident_alert' => ['required', Rule::in(array_keys(SecurityIncident::RANK))], 'alert_phone' => 'nullable|string|max:30']);
        SecurityService::saveSettings($d);

        return redirect()->route('security.visitors', ['tab' => 'settings'])->with('success', 'Security settings saved.');
    }

    public function run()
    {
        $r = SecurityService::run();

        return back()->with('success', "Security check done: {$r['overstays']} overstay alert(s), {$r['no_shows']} no-show(s), {$r['overdue_passes']} overdue gate pass(es), {$r['anonymised']} old record(s) anonymised.");
    }
}
